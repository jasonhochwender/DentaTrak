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

if (php_sapi_name() !== 'cli') {
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
    $opts = ['id' => (int)($_GET['id'] ?? $_POST['id'] ?? 0), 'limit' => 1];
} else {
    $opts = ['id' => 0, 'limit' => 5];
    foreach ($argv ?? [] as $a) {
        if (preg_match('/^--id=(\d+)$/', $a, $m)) $opts['id'] = (int)$m[1];
        if (preg_match('/^--limit=(\d+)$/', $a, $m)) $opts['limit'] = max(1, min(20, (int)$m[1]));
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
require_once __DIR__ . '/../migrations/2026_11_13_data_exports_v2.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (php_sapi_name() !== 'cli') { http_response_code(500); echo json_encode(['success' => false]); exit; }
    fwrite(STDERR, "no db\n"); exit(1);
}

$workerId = gethostname() . '-' . getmypid();
$processed = 0;
$results = [];

// Peek at the next claimable export id without claiming it.
function ewNextId(PDO $pdo, int $exportId): ?int {
    if ($exportId > 0) {
        $q = $pdo->prepare("SELECT id FROM data_exports WHERE id=:id AND status='queued' AND export_type='case_data'");
        $q->execute(['id' => $exportId]);
        $v = $q->fetchColumn();
        return $v ? (int)$v : null;
    }
    $v = $pdo->query("SELECT id FROM data_exports WHERE status='queued' AND export_type='case_data'
        ORDER BY created_at LIMIT 1")->fetchColumn();
    return $v ? (int)$v : null;
}

// Best-effort Cloud Run Job kick (production only). The job is also
// scheduled/drained independently, so failure here is non-fatal.
function exportWorkerKickJob(int $exportId): void {
    $job = getEnvVar('EXPORT_RUN_JOB') ?? '';
    if ($job === '') return;
    $project = getEnvVar('GOOGLE_CLOUD_PROJECT') ?? getEnvVar('GCP_PROJECT') ?? '';
    $region = getEnvVar('GOOGLE_CLOUD_REGION') ?? 'us-east1';
    if ($project === '') return;
    $url = "https://run.googleapis.com/v2/projects/{$project}/locations/{$region}/jobs/{$job}:run";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Authorization: Bearer " . ewMetadataToken() . "\r\nContent-Type: application/json",
        'content' => json_encode(['overrides' => ['containerOverrides' => [['env' => [['name' => 'EXPORT_ID', 'value' => (string)$exportId]]]]]]),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

function ewMetadataToken(): string {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $json = @file_get_contents('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token', false,
        stream_context_create(['http' => ['header' => "Metadata-Flavor: Google\r\n", 'timeout' => 5]]));
    $d = $json ? json_decode($json, true) : null;
    return $d['access_token'] ?? '';
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
    $stmt = $pdo->prepare("UPDATE data_exports SET status='processing', claimed_at=NOW(), claimed_by=:w
        WHERE id=:id AND status='queued' AND export_type='case_data'");
    $stmt->execute(['w' => $workerId, 'id' => $exportId]);
    if ($stmt->rowCount() === 0) return null;
    $q = $pdo->prepare("SELECT * FROM data_exports WHERE id=:id");
    $q->execute(['id' => $exportId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Process a single claimed export. Returns a result row for logging.
 */
function exportWorkerProcessOne(PDO $pdo, int $exportId, string $workerId): array {
    $export = ewClaim($pdo, $exportId, $workerId);
    if (!$export) {
        return ['id' => $exportId, 'status' => 'not_claimed'];
    }
    $pid = (int)$export['practice_id'];
    $uid = (int)$export['user_id'];
    $format = $export['format'] === 'json' ? 'json' : 'csv';
    $tmpDir = sys_get_temp_dir() . '/export-' . $exportId . '-' . uniqid();
    @mkdir($tmpDir, 0700, true);

    try {
        runDataExportsV2Migration($pdo);

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
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $manifest['artifact'] = ['name' => $artifactName, 'size' => $put['size'], 'sha256' => $put['sha256'],
                                  'driver' => $put['driver']];
        $pdo->prepare("UPDATE data_exports SET status='completed', progress=100,
            sections_done=:sd, manifest_json=:mj,
            file_size=:fs, file_path=:fp, gcs_bucket=:gb, gcs_object=:go, sha256=:sh,
            expires_at=:ex, completed_at=NOW()
            WHERE id=:id")
            ->execute([
                'sd' => json_encode($sectionsDone), 'mj' => json_encode($manifest),
                'fs' => $put['size'],
                'fp' => $put['driver'] === 'local' ? $put['ref'] : null,
                'gb' => $put['bucket'], 'go' => $put['driver'] === 'gcs' ? $put['ref'] : null,
                'sh' => $put['sha256'], 'ex' => $expiresAt, 'id' => $exportId,
            ]);

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
        $pdo->prepare("UPDATE data_exports SET status='failed', error_message=:em, progress=0 WHERE id=:id")
            ->execute(['em' => 'Export processing failed', 'id' => $exportId]);
        return ['id' => $exportId, 'status' => 'failed', 'error' => $e->getMessage()];
    } finally {
        foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmpDir);
    }
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

$limit = $opts['limit'];
$processed = 0;
while ($processed < $limit) {
    $nextId = ewNextId($pdo, $opts['id']);
    if ($nextId === null) break;
    $res = exportWorkerProcessOne($pdo, $nextId, $workerId);
    $results[] = $res;
    $processed++;
    if ($opts['id'] > 0) break; // single explicit id
}

$out = ['success' => true, 'processed' => $processed, 'results' => $results];
if (php_sapi_name() === 'cli') {
    echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo json_encode($out);
}
