<?php
/**
 * Data export worker.
 *
 * Drains queued data_exports rows (export_type='case_data'). Runs as a
 * Cloud Run Job (same image, `php api/export-worker.php`) or locally via
 * CLI: `php api/export-worker.php [--id=N] [--limit=N]`. Also reachable
 * over HTTP POST with the shared X-Queue-Worker-Token for manual drains.
 *
 * Snapshot discipline: all database reads for one export happen inside a
 * single REPEATABLE READ consistent-snapshot transaction whose results
 * are materialized to temp files BEFORE the transaction commits. File
 * (GCS) copying happens after commit, so the DB transaction is never
 * held open during artifact assembly - per the export consistency
 * requirement. GCS object generations are recorded in the manifest and
 * missing/changed objects are reported explicitly, never silently.
 */

// Bootstrap (auth/opts) only runs when this file is the directly-invoked
// script. When included as a library (tests, the dev inline path in
// data-export-v2.php) we skip straight to the shared requires and function
// definitions; the driver at the bottom is guarded the same way.
$__ewDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);

if (!$__ewDirect) {
    $opts = ['id' => 0, 'limit' => 5, 'sweep_only' => false];
} elseif (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/appConfig.php';
    require_once __DIR__ . '/feature-flags.php';
    require_once __DIR__ . '/queue-worker-auth.php';
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false]);
        exit;
    }
    requireQueueWorkerToken();
    if (!isFeatureEnabled('DATA_EXPORT_V2')) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'disabled']);
        exit;
    }
    $opts = ['id' => (int)($_GET['id'] ?? $_POST['id'] ?? 0), 'limit' => 1, 'sweep_only' => !empty($_POST['sweep_only'])];
} else {
    $opts = ['id' => 0, 'limit' => 5, 'sweep_only' => false];
    foreach ($argv ?? [] as $a) {
        if (preg_match('/^--id=(\d+)$/', $a, $m)) $opts['id'] = (int)$m[1];
        if (preg_match('/^--limit=(\d+)$/', $a, $m)) $opts['limit'] = max(1, min(20, (int)$m[1]));
        if ($a === '--sweep-only') $opts['sweep_only'] = true;
    }
    // Cloud Run Job dispatch passes the target export via EXPORT_ID env.
    if ($opts['id'] === 0) {
        $envId = (int)getenv('EXPORT_ID');
        if ($envId > 0) $opts['id'] = $envId;
    }
}

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/attachment-display.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/export-artifacts.php';
require_once __DIR__ . '/export-archive.php';
require_once __DIR__ . '/../migrations/2026_11_13_data_exports_v2.php';
require_once __DIR__ . '/../migrations/2026_11_20_data_exports_v2_attempts.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (php_sapi_name() !== 'cli') { http_response_code(500); echo json_encode(['success' => false]); exit; }
    fwrite(STDERR, "no db\n"); exit(1);
}

if (!defined('EW_MAX_ATTEMPTS')) define('EW_MAX_ATTEMPTS', 3);
// Stale threshold MUST exceed the Cloud Run Job task timeout (1800s) plus
// margin - a live worker refreshes claimed_at via heartbeat, so only a
// dead worker's claim ever reaches this age.
if (!defined('EW_STALE_MINUTES')) define('EW_STALE_MINUTES', 45);
if (!defined('EW_ABANDON_HOURS')) define('EW_ABANDON_HOURS', 24);
if (!defined('EW_HEARTBEAT_SECONDS')) define('EW_HEARTBEAT_SECONDS', 20);

$workerId = gethostname() . '-' . getmypid();
$processed = 0;
$results = [];

// Peek at the next claimable export id without claiming it.
function ewNextId(PDO $pdo, int $exportId): ?int {
    if ($exportId > 0) {
        $q = $pdo->prepare("SELECT id FROM data_exports WHERE id=:id AND status='queued' AND export_type <> 'legacy_json'");
        $q->execute(['id' => $exportId]);
        $v = $q->fetchColumn();
        return $v ? (int)$v : null;
    }
    $v = $pdo->query("SELECT id FROM data_exports WHERE status='queued' AND export_type <> 'legacy_json'
        ORDER BY created_at LIMIT 1")->fetchColumn();
    return $v ? (int)$v : null;
}

function ewCsvRow(array $fields): string {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, $fields);
    rewind($out);
    $s = stream_get_contents($out);
    fclose($out);
    return $s === false ? '' : $s;
}

// Claim one queued export atomically and return its row.
function ewClaim(PDO $pdo, int $exportId, string $workerId): ?array {
    $stmt = $pdo->prepare("UPDATE data_exports SET status='processing', claimed_at=NOW(), claimed_by=:w,
        attempts=attempts+1
        WHERE id=:id AND status='queued' AND export_type <> 'legacy_json'");
    $stmt->execute(['w' => $workerId, 'id' => $exportId]);
    if ($stmt->rowCount() === 0) return null;
    $q = $pdo->prepare("SELECT * FROM data_exports WHERE id=:id");
    $q->execute(['id' => $exportId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Claim heartbeat / ownership check. Refreshes claimed_at while the
 * worker still owns the export; returns false when another worker has
 * taken over (claim stolen or row no longer processing under us), in
 * which case the caller must abort without overwriting shared state.
 */
function ewHeartbeat(PDO $pdo, int $exportId, string $workerId): bool {
    // Verify ownership first: UPDATE rowCount is unreliable here because
    // MySQL reports CHANGED rows - a same-second heartbeat changes nothing
    // and would look like a lost claim.
    $own = $pdo->prepare("SELECT claimed_by FROM data_exports
        WHERE id=:id AND status='processing' AND claimed_by=:w");
    $own->execute(['id' => $exportId, 'w' => $workerId]);
    if (!$own->fetchColumn()) return false;
    $pdo->prepare("UPDATE data_exports SET claimed_at=NOW() WHERE id=:id")->execute(['id' => $exportId]);
    return true;
}

/**
 * Process a single claimed export. Returns a result row for logging.
 */
function exportWorkerProcessOne(PDO $pdo, int $exportId, string $workerId): array {
    $export = ewClaim($pdo, $exportId, $workerId);
    if (!$export) {
        return ['id' => $exportId, 'status' => 'not_claimed'];
    }
    if (($export['export_type'] ?? 'case_data') === 'practice_archive') {
        $aTmp = sys_get_temp_dir() . '/export-' . $exportId . '-' . uniqid();
        @mkdir($aTmp, 0700, true);
        try {
            return exportWorkerProcessArchive($pdo, $export, $workerId, $aTmp);
        } finally {
            foreach (glob($aTmp . '/*') ?: [] as $f) @unlink($f);
            foreach (glob($aTmp . '/stage/*') ?: [] as $f) @unlink($f);
            @rmdir($aTmp . '/stage');
            @rmdir($aTmp);
        }
    }
    $pid = (int)$export['practice_id'];
    $uid = (int)$export['user_id'];
    $format = $export['format'] === 'json' ? 'json' : 'csv';
    $tmpDir = sys_get_temp_dir() . '/export-' . $exportId . '-' . uniqid();
    @mkdir($tmpDir, 0700, true);

    try {
        runDataExportsV2Migration($pdo);
        runDataExportsV2AttemptsMigration($pdo);

        // ---------- Phase A: consistent-snapshot DB read ----------
        // Everything the artifact needs is read inside ONE transaction and
        // materialized to temp files; the txn commits before any GCS/file
        // work so it is never held open during assembly.
        $manifest = ['export_id' => $exportId, 'practice_id' => $pid,
                     'requested_by_user_id' => $uid, 'format' => $format,
                     'snapshot_at' => gmdate('c'), 'sections' => [], 'files' => []];
        $sectionsDone = [];

        $pdo->beginTransaction();
        try {
            // Cases (canonical record; PII decrypted at write time).
            $cases = $pdo->prepare("SELECT * FROM cases_cache WHERE practice_id = :p ORDER BY creation_date DESC, id");
            $cases->execute(['p' => $pid]);
            $caseRows = $cases->fetchAll(PDO::FETCH_ASSOC);

            $comments = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_comments WHERE practice_id = :p ORDER BY created_at, id");
                $q->execute(['p' => $pid]);
                $comments = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['comments'] = 'unavailable: ' . $e->getMessage(); }

            $activity = [];
            try {
                $q = $pdo->prepare("SELECT ca.* FROM case_activity_log ca
                    INNER JOIN cases_cache cc ON ca.case_id = cc.case_id
                    WHERE cc.practice_id = :p ORDER BY ca.created_at DESC");
                $q->execute(['p' => $pid]);
                $activity = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['activity'] = 'unavailable'; }

            $assignments = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_assignments WHERE case_id IN
                    (SELECT case_id FROM cases_cache WHERE practice_id = :p)");
                $q->execute(['p' => $pid]);
                $assignments = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['assignments'] = 'unavailable'; }

            $labPeriods = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_lab_assignment_periods WHERE practice_id = :p ORDER BY started_at");
                $q->execute(['p' => $pid]);
                $labPeriods = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['lab_periods'] = 'unavailable'; }

            $labAcks = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_lab_acknowledgments WHERE practice_id = :p ORDER BY created_at");
                $q->execute(['p' => $pid]);
                $labAcks = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['lab_acks'] = 'unavailable'; }

            $remakes = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_remake_events WHERE practice_id = :p ORDER BY created_at");
                $q->execute(['p' => $pid]);
                $remakes = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['remakes'] = 'unavailable'; }

            $labels = [];
            $labelAssignments = [];
            try {
                $q = $pdo->prepare("SELECT * FROM case_labels WHERE practice_id = :p");
                $q->execute(['p' => $pid]);
                $labels = $q->fetchAll(PDO::FETCH_ASSOC);
                $q = $pdo->prepare("SELECT la.* FROM case_label_assignments la
                    INNER JOIN case_labels l ON l.id = la.label_id WHERE l.practice_id = :p");
                $q->execute(['p' => $pid]);
                $labelAssignments = $q->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $manifest['sections']['labels'] = 'unavailable'; }

            $manifest['sections'] = array_merge($manifest['sections'], [
                'cases' => count($caseRows), 'comments' => count($comments),
                'activity' => count($activity), 'assignments' => count($assignments),
                'lab_periods' => count($labPeriods), 'lab_acks' => count($labAcks),
                'remakes' => count($remakes), 'labels' => count($labels),
            ]);
        } finally {
            if ($pdo->inTransaction()) $pdo->commit();
        }

        // ---------- Phase B: artifact assembly (no DB txn held) ----------
        $stageLabel = getWorkflowStageLabelOverridesForPractice($pid);
        $dec = function ($v) {
            if (!is_string($v) || $v === '') return $v;
            try { return PIIEncryption::decrypt($v); }
            catch (Throwable $e) { return $v; } // plaintext or differently-encoded field
        };

        if ($format === 'csv') {
            // Flat Excel-friendly cases sheet. UTF-8 BOM for Excel.
            $fp = fopen($tmpDir . '/cases.csv', 'w');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, [
                'case_id','patient_first_name','patient_last_name','patient_dob','patient_gender',
                'dentist_name','case_type','case_type_label','tooth_shade','material',
                'due_date','patient_appointment_date','creation_date','last_update_date',
                'delivered_at','status','status_label','notes','assigned_to',
                'archived','archived_date','carrier','tracking_number','custom_carrier',
                'reviewed_at','reviewed_by_user_id','lab_ack_status','lab_promised_date',
                'revision_count','created_by_user_id','attachment_count','clinical_details_json'
            ]);
            foreach ($caseRows as $c) {
                $atts = json_decode($c['attachments_json'] ?? '[]', true);
                fputcsv($fp, [
                    $c['case_id'], $dec($c['patient_first_name']), $dec($c['patient_last_name']),
                    $dec($c['patient_dob']), $c['patient_gender'], $dec($c['dentist_name']),
                    $c['case_type'], getCaseTypeDisplayLabel($c['case_type'] ?? ''),
                    $c['tooth_shade'], $c['material'], $c['due_date'], $c['patient_appointment_date'],
                    $c['creation_date'], $c['last_update_date'], $c['delivered_at'],
                    $c['status'], resolveWorkflowStageLabel($c['status'], $stageLabel),
                    $dec($c['notes']), $c['assigned_to'],
                    (int)$c['archived'], $c['archived_date'], $c['carrier'],
                    $c['tracking_number'], $c['custom_carrier'], $c['reviewed_at'],
                    $c['reviewed_by_user_id'], $c['lab_ack_status'], $c['lab_promised_date'],
                    (int)($c['revision_count'] ?? 0), $c['created_by_user_id'],
                    is_array($atts) ? count($atts) : 0, $c['clinical_details_json'] ?? '',
                ]);
            }
            fclose($fp);
            $artifactName = "dentatrak-case-export-{$exportId}.csv";
        } else {
            // Structured JSON: every section, decrypted PII, decoded JSON cols.
            $out = ['exportInfo' => [
                    'exportId' => $exportId, 'practiceId' => $pid, 'format' => 'json',
                    'exportedAt' => $manifest['snapshot_at'], 'snapshotAt' => $manifest['snapshot_at'],
                    'rowCounts' => $manifest['sections'],
                ],
                'cases' => [], 'comments' => [], 'activity' => [],
                'assignments' => $assignments, 'labAssignmentPeriods' => $labPeriods,
                'labAcknowledgments' => $labAcks, 'remakeEvents' => $remakes,
                'labels' => $labels, 'labelAssignments' => $labelAssignments,
            ];
            foreach ($caseRows as $c) {
                $row = $c;
                foreach (['patient_first_name','patient_last_name','patient_dob','dentist_name','notes'] as $f) {
                    $row[$f] = $dec($c[$f] ?? '');
                }
                $row['attachments'] = json_decode($c['attachments_json'] ?? '[]', true) ?: [];
                $row['revisions'] = json_decode($c['revisions_json'] ?? '[]', true) ?: [];
                $row['clinicalDetails'] = json_decode($c['clinical_details_json'] ?? '{}', true) ?: [];
                $row['statusLabel'] = resolveWorkflowStageLabel($c['status'], $stageLabel);
                unset($row['attachments_json'], $row['revisions_json'], $row['clinical_details_json']);
                $out['cases'][] = $row;
            }
            foreach ($comments as &$cm) {
                $cm['comment_text'] = $dec($cm['comment_text'] ?? '');
                $cm['attachments'] = json_decode($cm['attachments_json'] ?? '[]', true) ?: [];
                unset($cm['attachments_json']);
            }
            unset($cm);
            $out['activity'] = $activity;
            file_put_contents($tmpDir . '/export.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $artifactName = "dentatrak-case-export-{$exportId}.json";
        }

        $src = $tmpDir . '/' . ($format === 'csv' ? 'cases.csv' : 'export.json');
        $put = exportArtifactPut($pid, $exportId, $artifactName, file_get_contents($src));
        $sectionsDone = array_keys($manifest['sections']);

        // ---------- Phase C: finalize ----------
        // Expiry is computed on the DB clock so it can't drift from the
        // server's NOW() used by the sweeper and download checks.
        $expiresAt = $pdo->query("SELECT DATE_FORMAT(NOW() + INTERVAL 7 DAY, '%Y-%m-%d %H:%i:%s')")->fetchColumn();
        $manifest['artifact'] = ['name' => $artifactName, 'size' => $put['size'], 'sha256' => $put['sha256'],
                                  'driver' => $put['driver']];
        // Ownership-verified finalize: only the worker that still holds
        // the claim may write the terminal state. If the claim was lost
        // (stale-recovery requeue + another worker), do not overwrite.
        if (!ewHeartbeat($pdo, $exportId, $workerId)) {
            exportArtifactDelete($put['driver'], $put['bucket'] ?? null, $put['ref']);
            return ['id' => $exportId, 'status' => 'claim_lost'];
        }
        $fin = $pdo->prepare("UPDATE data_exports SET status='completed', progress=100,
            sections_done=:sd, manifest_json=:mj,
            file_size=:fs, file_path=:fp, gcs_bucket=:gb, gcs_object=:go, sha256=:sh,
            expires_at=:ex, completed_at=NOW()
            WHERE id=:id AND claimed_by=:w AND status='processing'");
        $fin->execute([
            'sd' => json_encode($sectionsDone), 'mj' => json_encode($manifest),
            'fs' => $put['size'],
            'fp' => $put['driver'] === 'local' ? $put['ref'] : null,
            'gb' => $put['bucket'], 'go' => $put['driver'] === 'gcs' ? $put['ref'] : null,
            'sh' => $put['sha256'], 'ex' => $expiresAt, 'id' => $exportId, 'w' => $workerId,
        ]);
        if ($fin->rowCount() === 0) {
            exportArtifactDelete($put['driver'], $put['bucket'] ?? null, $put['ref']);
            return ['id' => $exportId, 'status' => 'claim_lost'];
        }

        logPHIAccess(PHI_ACTION_DATA_EXPORT, null,
            ['export_id' => $exportId, 'format' => $format, 'file_size' => $put['size']],
            'practice_export', (string)$exportId, $pid);

        // Email the requester.
        try {
            $email = $pdo->query("SELECT email FROM users WHERE id = " . (int)$uid)->fetchColumn();
            if ($email) ewSendReadyEmail((string)$email, $exportId, $put['size'], $expiresAt, $format, $uid);
        } catch (Throwable $e) { error_log('[export-worker] email failed: ' . $e->getMessage()); }

        if (function_exists('logUserActivity')) {
            logUserActivity($uid, 'data_export_v2_completed', "Case data export completed (ID: {$exportId})");
        }
        return ['id' => $exportId, 'status' => 'completed', 'size' => $put['size']];
    } catch (Throwable $e) {
        error_log('[export-worker] export ' . $exportId . ' failed: ' . $e->getMessage());
        // Bounded retry: transient failures go back to 'queued' until the
        // attempt budget is exhausted, then the export is marked failed.
        // All writes are claim-scoped so a worker that lost its claim
        // cannot clobber the new owner's state.
        $attempts = (int)($export['attempts'] ?? 0);
        if ($attempts < EW_MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE data_exports SET status='queued', claimed_at=NULL, claimed_by=NULL,
                error_message=:em, progress=0 WHERE id=:id AND claimed_by=:w")
                ->execute(['em' => 'Export processing failed (will retry)', 'id' => $exportId, 'w' => $workerId]);
            return ['id' => $exportId, 'status' => 'queued', 'error' => $e->getMessage(), 'retry' => true];
        }
        $pdo->prepare("UPDATE data_exports SET status='failed', error_message=:em, progress=0
            WHERE id=:id AND claimed_by=:w")
            ->execute(['em' => 'Export processing failed', 'id' => $exportId, 'w' => $workerId]);
        return ['id' => $exportId, 'status' => 'failed', 'error' => $e->getMessage()];
    } finally {
        foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmpDir);
    }
}

/**
 * Maintenance sweep run at the start of every worker invocation:
 *  1. Expired v2 artifacts - delete the stored object/file and mark the
 *     row 'expired' so it can never be downloaded again, even if object
 *     deletion is delayed or fails (status gate blocks downloads).
 *  2. Stale 'processing' claims (worker died mid-export) - requeue while
 *     attempts remain, otherwise fail permanently.
 *  3. Abandoned 'queued' rows older than EW_ABANDON_HOURS - fail them so
 *     they cannot sit forever if dispatch never happened.
 *
 * Legacy export rows (export_type='legacy_json') are never touched.
 */
function exportWorkerSweep(PDO $pdo): array {
    runDataExportsV2Migration($pdo);
    runDataExportsV2AttemptsMigration($pdo);
    $out = ['expired' => 0, 'requeued' => 0, 'failed_stale' => 0, 'abandoned' => 0];

    // 1. Expired artifacts (v2 only).
    $exp = $pdo->query("SELECT id, gcs_object, gcs_bucket, file_path FROM data_exports
        WHERE export_type <> 'legacy_json' AND status IN ('completed','failed','processing','queued','pending')
          AND expires_at IS NOT NULL AND expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exp as $row) {
        $id = (int)$row['id'];
        if (!empty($row['gcs_object'])) {
            exportArtifactDelete('gcs', $row['gcs_bucket'], $row['gcs_object']);
        } elseif (!empty($row['file_path'])) {
            exportArtifactDelete('local', null, $row['file_path']);
        }
        $pdo->prepare("UPDATE data_exports SET status='expired' WHERE id=:id")->execute(['id' => $id]);
        $out['expired']++;
    }

    // 2. Stale processing claims.
    $stale = $pdo->query("SELECT id, attempts FROM data_exports
        WHERE export_type <> 'legacy_json' AND status='processing'
          AND claimed_at IS NOT NULL AND claimed_at < NOW() - INTERVAL " . (int)EW_STALE_MINUTES . " MINUTE")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stale as $row) {
        $id = (int)$row['id'];
        $attempts = (int)$row['attempts'];
        if ($attempts >= EW_MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE data_exports SET status='failed', claimed_at=NULL, claimed_by=NULL,
                error_message='Worker claim expired' WHERE id=:id")->execute(['id' => $id]);
            $out['failed_stale']++;
        } else {
            $pdo->prepare("UPDATE data_exports SET status='queued', claimed_at=NULL, claimed_by=NULL WHERE id=:id")
                ->execute(['id' => $id]);
            $out['requeued']++;
        }
    }

    // 3. Abandoned queued rows (never claimed before expiry window).
    $ab = $pdo->prepare("UPDATE data_exports SET status='failed', error_message='Abandoned before processing'
        WHERE export_type <> 'legacy_json' AND status='queued'
          AND created_at < NOW() - INTERVAL " . (int)EW_ABANDON_HOURS . " HOUR");
    $ab->execute();
    $out['abandoned'] = $ab->rowCount();

    return $out;
}

function ewSendReadyEmail(string $email, int $exportId, int $size, string $expiresAt, string $format, ?int $userId): void {
    global $appConfig;
    $base = rtrim($appConfig['baseUrl'] ?? '', '/');
    $url = $base . '/main.php#settings';
    $locale = function_exists('resolveEmailLocale') ? resolveEmailLocale($userId, null, null) : 'en-US';
    $appName = $appConfig['appName'] ?? 'DentaTrak';
    $subject = "Your DentaTrak data export is ready";
    $body = "Your case data export (ID {$exportId}, format {$format}, " . number_format($size / 1024, 1) . " KB) is ready.\n\nDownload it from Settings > Data & Privacy. The link expires {$expiresAt}.\n\n- {$appName}";
    sendAppEmail($email, $subject, nl2br(htmlspecialchars($body)), $body);
}

// ---------- Driver ----------
// Only run when invoked directly (CLI or HTTP); included contexts (tests)
// call exportWorkerProcessOne() explicitly.
$__ewSelf = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
if ($__ewSelf !== realpath(__FILE__)) {
    return; // included as a library
}
unset($__ewSelf);

// Maintenance first: expire artifacts, recover stale claims, fail
// abandoned rows - on every run, including EXPORT_ID-targeted kicks.
$sweep = exportWorkerSweep($pdo);

$limit = $opts['sweep_only'] ? 0 : $opts['limit'];
$processed = 0;
$results = [];
while ($processed < $limit) {
    $nextId = ewNextId($pdo, $opts['id']);
    if ($nextId === null) break;
    $res = exportWorkerProcessOne($pdo, $nextId, $workerId);
    $results[] = $res;
    $processed++;
    if ($opts['id'] > 0) break; // single explicit id
}

$out = ['success' => true, 'processed' => $processed, 'sweep' => $sweep, 'results' => $results];
if (php_sapi_name() === 'cli') {
    echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($out);
}
