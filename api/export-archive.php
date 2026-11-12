<?php
/**
 * Complete Practice Archive builder (export_type='practice_archive').
 *
 * Produces a ZIP containing every approved practice-owned data category:
 * case data (CSV + full-fidelity JSON), relational case history, practice
 * settings, members, PHI access log, safe subscription metadata, scanner
 * records, integration status/operational history, and the actual binary
 * files (attachments, comment images, scanner files) with SHA-256
 * verification. Sensitive material (credentials, tokens, password
 * hashes, raw integration payloads, billing transactions, notification
 * inboxes, user activity logs) is never read into the archive.
 *
 * Consistency: all DB reads happen inside ONE REPEATABLE READ snapshot
 * transaction materialized to temp JSONL files; the transaction commits
 * before any file/ZIP work begins.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/export-artifacts.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/attachment-display.php';
require_once __DIR__ . '/integrations/scanners/ScanFileStore.php';
require_once __DIR__ . '/../vendor/autoload.php';

use ZipStream\ZipStream;
use ZipStream\CompressionMethod;
use GuzzleHttp\Psr7\Utils;

if (!function_exists('eaDec')) {
    function eaDec($v) {
        if (!is_string($v) || $v === '') return $v;
        try { return PIIEncryption::decrypt($v); }
        catch (Throwable $e) { return $v; }
    }
}

/** Read a materialized JSONL temp file row-by-row. */
function eaJsonlRows(string $path): Generator {
    $fp = fopen($path, 'rb');
    if (!$fp) return;
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row)) yield $row;
    }
    fclose($fp);
}

/** CSV-escape a single field (matches fputcsv quoting rules). */
function eaCsvField($v): string {
    $v = (string)$v;
    if ($v === '' || !preg_match('/[",\n\r\\\\]/', $v)) return $v;
    return '"' . str_replace('"', '""', $v) . '"';
}

/** Render one CSV record line (fields already in column order). */
function eaCsvLine(array $fields): string {
    return implode(',', array_map('eaCsvField', array_values($fields))) . "\n";
}

/**
 * Add byte chunks to the zip as a STORE entry while computing the
 * entry's SHA-256 inline. ZipStream consumes the stream eagerly, so
 * the hash is final once addFileFromCallback returns.
 */
function eaZipChunks(ZipStream $zip, string $name, iterable $chunks, array &$sums): void {
    $hash = hash_init('sha256');
    $size = 0;
    $gen = (function () use ($chunks, $hash, &$size) {
        foreach ($chunks as $c) {
            if ($c === '' || $c === null) continue;
            hash_update($hash, $c);
            $size += strlen($c);
            yield $c;
        }
    })();
    $zip->addFileFromCallback($name,
        callback: fn() => Utils::streamFor($gen),
        compressionMethod: CompressionMethod::STORE,
        enableZeroHeader: true);
    $sums[] = [hash_final($hash), $name];
}

/** Add a UTF-8-BOM CSV entry built from a header + row iterator. */
function eaZipCsv(ZipStream $zip, string $name, array $header, iterable $rows, array &$sums): void {
    $chunks = (function () use ($header, $rows) {
        yield "\xEF\xBB\xBF" . eaCsvLine($header);
        foreach ($rows as $r) yield eaCsvLine($r);
    })();
    eaZipChunks($zip, $name, $chunks, $sums);
}

/** Add a pretty-printed JSON array entry built from a row iterator. */
function eaZipJson(ZipStream $zip, string $name, iterable $rows, ?callable $map, array &$sums): void {
    $chunks = (function () use ($rows, $map) {
        yield "[\n";
        $first = true;
        foreach ($rows as $r) {
            if ($map) $r = $map($r);
            if (!$first) yield ",\n";
            yield json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
        }
        yield "\n]\n";
    })();
    eaZipChunks($zip, $name, $chunks, $sums);
}

/** Add a string as a zip entry; returns its sha256. */
function eaZipString(ZipStream $zip, string $name, string $content, array &$sums): void {
    $zip->addFile($name, $content, compressionMethod: CompressionMethod::DEFLATE);
    $sums[] = [hash('sha256', $content), $name];
}

/** Sanitize a filename for use inside the zip. */
function eaSafeName(string $name): string {
    $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1F]#', '_', $name);
    $name = trim($name, ' ._');
    return $name === '' ? 'file' : mb_substr($name, 0, 180);
}

/**
 * Snapshot phase: read every approved section inside one consistent
 * transaction and materialize rows to JSONL temp files.
 * Returns [sectionName => tmpPath] plus manifest row counts.
 */
function eaSnapshot(PDO $pdo, int $pid, string $tmpDir): array {
    $files = [];
    $counts = [];
    $notes = [];

    $sections = [
        'cases' => "SELECT * FROM cases_cache WHERE practice_id = :p ORDER BY creation_date DESC, id",
        'comments' => "SELECT * FROM case_comments WHERE practice_id = :p ORDER BY created_at, id",
        'activity' => "SELECT ca.* FROM case_activity_log ca INNER JOIN cases_cache cc ON ca.case_id = cc.case_id WHERE cc.practice_id = :p ORDER BY ca.created_at DESC",
        'assignments' => "SELECT ca.* FROM case_assignments ca INNER JOIN cases_cache cc ON ca.case_id = cc.case_id COLLATE utf8mb4_unicode_ci WHERE cc.practice_id = :p",
        'lab_periods' => "SELECT * FROM case_lab_assignment_periods WHERE practice_id = :p ORDER BY started_at",
        'lab_acks' => "SELECT * FROM case_lab_acknowledgments WHERE practice_id = :p ORDER BY created_at",
        'remakes' => "SELECT * FROM case_remake_events WHERE practice_id = :p ORDER BY created_at",
        'labels' => "SELECT * FROM case_labels WHERE practice_id = :p",
        'label_assignments' => "SELECT la.* FROM case_label_assignments la INNER JOIN case_labels l ON l.id = la.label_id WHERE l.practice_id = :p",
        'scanner_incoming' => "SELECT * FROM scanner_incoming_scans WHERE practice_id = :p ORDER BY created_at DESC",
        'scanner_files' => "SELECT sf.* FROM scanner_scan_files sf INNER JOIN scanner_incoming_scans s ON s.id = sf.scan_id WHERE s.practice_id = :p ORDER BY sf.created_at",
        'scanner_events' => "SELECT * FROM scanner_scan_events WHERE practice_id = :p ORDER BY created_at",
        'phi_access_log' => "SELECT * FROM phi_access_log WHERE practice_id = :p ORDER BY accessed_at DESC",
        'integration_connections' => "SELECT * FROM integration_connections WHERE practice_id = :p",
        'integration_sync_runs' => "SELECT r.* FROM integration_sync_runs r INNER JOIN integration_connections ic ON ic.id = r.connection_id WHERE ic.practice_id = :p ORDER BY r.started_at DESC",
        'integration_entity_mappings' => "SELECT m.* FROM integration_entity_mappings m INNER JOIN integration_connections ic ON ic.id = m.connection_id WHERE ic.practice_id = :p",
        'members' => "SELECT pu.*, u.email AS member_email, u.first_name AS member_first_name, u.last_name AS member_last_name, u.created_at AS user_created_at
                      FROM practice_users pu LEFT JOIN users u ON u.id = pu.user_id WHERE pu.practice_id = :p",
        'practice' => "SELECT * FROM practices WHERE id = :p",
        'user_prefs' => "SELECT * FROM user_notification_preferences WHERE practice_id = :p",
    ];

    $pdo->beginTransaction();
    $snapshotAt = $pdo->query('SELECT NOW()')->fetchColumn();
    try {
        foreach ($sections as $name => $sql) {
            $path = $tmpDir . '/sec_' . $name . '.jsonl';
            $fp = fopen($path, 'wb');
            if (!$fp) throw new RuntimeException("cannot write section {$name}");
            $n = 0;
            try {
                $q = $pdo->prepare($sql);
                $q->execute(['p' => $pid]);
                while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    $n++;
                }
            } catch (Throwable $e) {
                fclose($fp);
                @unlink($path);
                $notes[$name] = 'unavailable: ' . $e->getMessage();
                continue;
            }
            fclose($fp);
            $files[$name] = $path;
            $counts[$name] = $n;
        }

        // Owner-scoped subscription metadata (non-sensitive plan/status).
        try {
            $ownerId = (int)$pdo->query("SELECT user_id FROM practice_users WHERE practice_id = " . (int)$pid . " AND is_owner = 1 LIMIT 1")->fetchColumn();
            if ($ownerId > 0) {
                $q = $pdo->prepare("SELECT * FROM subscriptions WHERE owner_user_id = :u LIMIT 1");
                $q->execute(['u' => $ownerId]);
                $sub = $q->fetch(PDO::FETCH_ASSOC);
                if ($sub) {
                    foreach (['stripe_secret','webhook_secret','api_key'] as $k) unset($sub[$k]);
                    $path = $tmpDir . '/sec_subscription.json';
                    file_put_contents($path, json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $files['subscription'] = $path;
                    $counts['subscription'] = 1;
                }
            }
        } catch (Throwable $e) { $notes['subscription'] = 'unavailable'; }
    } finally {
        if ($pdo->inTransaction()) $pdo->commit();
    }

    return ['files' => $files, 'counts' => $counts, 'notes' => $notes, 'snapshot_at' => $snapshotAt];
}

/** Strip sensitive keys from a practice settings row. */
function eaSanitizePractice(array $row): array {
    foreach (array_keys($row) as $k) {
        if (preg_match('/secret|token|password|credential|private_key/i', (string)$k)) unset($row[$k]);
    }
    return $row;
}

/** Strip secret-bearing fields from integration connection rows. */
function eaSanitizeIntegration(array $row): array {
    unset($row['config_json'], $row['access_token'], $row['refresh_token'],
          $row['client_secret'], $row['credentials_json']);
    foreach (array_keys($row) as $k) {
        if (preg_match('/secret|token|password|credential/i', (string)$k)) unset($row[$k]);
    }
    return $row;
}

/** Members allowlist - never include password hashes, TOTP, sessions, google ids. */
function eaMemberRow(array $r): array {
    $name = trim(eaDec($r['member_first_name'] ?? '') . ' ' . eaDec($r['member_last_name'] ?? ''));
    return [
        'user_id' => $r['user_id'] ?? null,
        'email' => $r['member_email'] ?? null,
        'display_name' => $name !== '' ? $name : null,
        'role' => $r['role'] ?? null,
        'is_owner' => $r['is_owner'] ?? null,
        'limited_visibility' => $r['limited_visibility'] ?? null,
        'can_view_analytics' => $r['can_view_analytics'] ?? null,
        'can_edit_cases' => $r['can_edit_cases'] ?? null,
        'can_add_users' => $r['can_add_users'] ?? null,
        'can_add_labels' => $r['can_add_labels'] ?? null,
        'is_lab' => $r['is_lab'] ?? null,
        'can_view_incoming_scans' => $r['can_view_incoming_scans'] ?? null,
        'created_at' => $r['user_created_at'] ?? ($r['created_at'] ?? null),
    ];
}

/**
 * Enumerate every binary file referenced by the snapshot and add it to
 * the zip with integrity checks. Missing/inaccessible objects are
 * recorded explicitly in the manifest - never silently skipped.
 */
function eaAddBinaryFiles(ZipStream $zip, PDO $pdo, int $pid, array $snapFiles, array &$sums, array &$manifestFiles, callable $heartbeat): void {
    require_once __DIR__ . '/gcs-storage.php';
    $bucket = exportArtifactDriver() === 'gcs' ? getGcsBucket() : null;
    $usedNames = [];

    $addOne = function (string $zipPath, string $driver, string $storagePath, ?int $declaredSize, ?string $declaredSha, array $source) use (&$manifestFiles, &$sums, &$usedNames, $zip, $bucket, $heartbeat) {
        // Deduplicate zip paths: append _2, _3... on collision.
        $base = $zipPath; $i = 2;
        while (isset($usedNames[$zipPath])) { $zipPath = preg_replace('/(\.[^\/]*)?$/', '_' . $i . '$1', $base); $i++; }
        $usedNames[$zipPath] = true;
        $entry = ['zipPath' => $zipPath, 'source' => $source, 'status' => 'pending'];
        $object = null; $abs = null;
        try {
            // Pin object metadata BEFORE reading.
            $gen = null; $md5 = null;
            if ($driver === 'gcs') {
                $object = $bucket->object($storagePath);
                $info = $object->exists() ? $object->info() : null;
                if (!$info) { $entry['status'] = 'missing'; $manifestFiles[] = $entry; return; }
                $gen = isset($info['generation']) ? (string)$info['generation'] : null;
                $size = isset($info['size']) ? (int)$info['size'] : null;
                $md5 = $info['md5Hash'] ?? null;
            } else {
                $abs = ScanFileStore::exportLocalPath($storagePath);
                if ($abs === null || !is_file($abs)) { $entry['status'] = 'missing'; $manifestFiles[] = $entry; return; }
                $size = filesize($abs);
            }

            // Stream the bytes straight into the zip while hashing, so
            // SHA256SUMS describes the exact bytes written and no
            // per-file spool is needed. A failure while streaming is
            // FATAL: a partially-written zip entry would corrupt the
            // whole archive, so the exception propagates, the upload
            // session is aborted, and the export retries/fails cleanly
            // instead of producing a damaged archive.
            $chunks = eaBinaryChunks($driver, $storagePath, $object, $abs);
            if ($chunks === null) { $entry['status'] = 'missing'; $manifestFiles[] = $entry; return; }

            $hash = hash_init('sha256');
            $seen = 0;
            $tee = (function () use ($chunks, $hash, &$seen) {
                foreach ($chunks as $ch) {
                    if ($ch === '' || $ch === null) continue;
                    hash_update($hash, $ch);
                    $seen += strlen($ch);
                    yield $ch;
                }
            })();
            $zip->addFileFromCallback($zipPath,
                callback: fn() => Utils::streamFor($tee),
                compressionMethod: CompressionMethod::STORE,
                exactSize: $size !== null ? (int)$size : null,
                enableZeroHeader: true);

            // Post-read generation check: if the GCS object was replaced
            // while we were reading it, flag explicitly.
            if ($driver === 'gcs') {
                try {
                    $info2 = $bucket->object($storagePath)->info();
                    $gen2 = isset($info2['generation']) ? (string)$info2['generation'] : null;
                    if ($gen !== null && $gen2 !== null && $gen !== $gen2) {
                        $entry['generation_changed'] = true;
                    }
                } catch (Throwable $e) { /* object may be gone now - already recorded */ }
            }

            $sha = hash_final($hash);
            $size = $seen;
            if ($declaredSha && hash_equals(strtolower($declaredSha), (string)$sha)) {
                $entry['checksum'] = 'match';
            } elseif ($declaredSha) {
                $entry['checksum'] = 'mismatch';
                $entry['status'] = 'checksum_mismatch';
            } else {
                $entry['checksum'] = 'computed';
            }
            if ($declaredSize !== null && (int)$declaredSize !== (int)$size) {
                $entry['size_mismatch'] = true;
            }

            $sums[] = [$sha, $zipPath];
            $entry['status'] = 'included';
            $entry['size'] = $size;
            $entry['sha256'] = $sha;
            if ($gen !== null) $entry['generation'] = $gen;
            if ($md5 !== null) $entry['gcs_md5'] = $md5;
            $manifestFiles[] = $entry;
            $heartbeat();
        } catch (Throwable $e) {
            // Preflight 'missing' cases returned above; anything thrown
            // here means the object could not be read/streamed. That is
            // fatal to archive integrity - rethrow so the whole export
            // fails (and is retried) rather than silently skipping.
            throw $e;
        }
    };

    // --- Case attachments + revision files + comment images ---
    $casesPath = $snapFiles['cases'] ?? null;
    $commentsPath = $snapFiles['comments'] ?? null;
    if ($casesPath) {
        foreach (eaJsonlRows($casesPath) as $c) {
            $cid = (string)($c['case_id'] ?? '');
            if ($cid === '') continue;
            $atts = json_decode($c['attachments_json'] ?? '[]', true) ?: [];
            foreach ((array)$atts as $a) {
                $sp = $a['storagePath'] ?? $a['storage_path'] ?? '';
                $st = $a['storageType'] ?? $a['storage_type'] ?? 'gcs';
                if ($sp === '') continue;
                $name = eaSafeName($a['name'] ?? $a['originalName'] ?? $a['filename'] ?? basename($sp));
                $type = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($a['type'] ?? $a['uploadType'] ?? 'attachments'));
                $addOne("files/cases/{$cid}/{$type}/{$name}", $st === 'local' ? 'local' : 'gcs', $sp,
                    isset($a['size']) ? (int)$a['size'] : null, $a['checksum'] ?? $a['checksum_sha256'] ?? null,
                    ['kind' => 'case_attachment', 'case_id' => $cid]);
            }
            $revs = json_decode($c['revisions_json'] ?? '[]', true) ?: [];
            foreach ((array)$revs as $rv) {
                foreach ((array)($rv['attachments'] ?? $rv['files'] ?? []) as $a) {
                    $sp = $a['storagePath'] ?? $a['storage_path'] ?? '';
                    if ($sp === '') continue;
                    $name = eaSafeName($a['name'] ?? $a['originalName'] ?? basename($sp));
                    $addOne("files/cases/{$cid}/revisions/{$name}", 'gcs', $sp,
                        isset($a['size']) ? (int)$a['size'] : null, $a['checksum'] ?? $a['checksum_sha256'] ?? null,
                        ['kind' => 'revision_file', 'case_id' => $cid]);
                }
            }
        }
    }
    if ($commentsPath) {
        foreach (eaJsonlRows($commentsPath) as $cm) {
            $cid = (string)($cm['case_id'] ?? '');
            $atts = json_decode($cm['attachments_json'] ?? '[]', true) ?: [];
            foreach ((array)$atts as $a) {
                $sp = $a['storagePath'] ?? $a['storage_path'] ?? '';
                if ($sp === '') continue;
                $name = eaSafeName($a['name'] ?? $a['originalName'] ?? basename($sp));
                $addOne("files/cases/{$cid}/comments/{$name}", 'gcs', $sp,
                    isset($a['size']) ? (int)$a['size'] : null, $a['checksum'] ?? null,
                    ['kind' => 'comment_image', 'case_id' => $cid]);
            }
        }
    }

    // --- Scanner files (stored objects incl. pending + attached) ---
    $sfPath = $snapFiles['scanner_files'] ?? null;
    if ($sfPath) {
        foreach (eaJsonlRows($sfPath) as $sf) {
            $fid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($sf['id'] ?? 'unknown'));
            $driver = ($sf['storage_driver'] ?? 'gcs') === 'local' ? 'local' : 'gcs';
            $sp = (string)($sf['storage_path'] ?? '');
            if ($sp === '') continue;
            $name = eaSafeName($sf['file_name'] ?? $sf['original_name'] ?? basename($sp));
            $addOne("files/scanner/{$fid}/{$name}", $driver, $sp,
                isset($sf['declared_size']) ? (int)$sf['declared_size'] : (isset($sf['size']) ? (int)$sf['size'] : null),
                $sf['checksum_sha256'] ?? null,
                ['kind' => 'scanner_file', 'scan_file_id' => $fid,
                 'attached_case_id' => $sf['attached_case_id'] ?? null,
                 'status' => $sf['status'] ?? null]);
        }
    }
}

/**
 * Byte-chunk iterator for a stored object (bounded memory).
 * Returns a Generator, or null when the object cannot be opened.
 * Read errors surface while the generator is consumed - callers must
 * treat those as fatal to archive integrity.
 */
function eaBinaryChunks(string $driver, string $storagePath, $object = null, ?string $abs = null): ?iterable {
    if ($driver === 'gcs') {
        try {
            $object = $object ?: getGcsBucket()->object($storagePath);
            $psr = $object->downloadAsStream();
        } catch (Throwable $e) { return null; }
        return (function () use ($psr) {
            try {
                while (!$psr->eof()) {
                    $d = $psr->read(1 << 20);
                    if ($d === '' || $d === null) break;
                    yield $d;
                }
            } finally {
                try { $psr->close(); } catch (Throwable $e) { /* best effort */ }
            }
        })();
    }
    $abs = $abs ?: ScanFileStore::exportLocalPath($storagePath);
    if ($abs === null || !is_file($abs)) return null;
    $st = @fopen($abs, 'rb');
    if (!$st) return null;
    return (function () use ($st) {
        try {
            while (!feof($st)) {
                $d = fread($st, 1 << 20);
                if ($d === false || $d === '') break;
                yield $d;
            }
        } finally {
            fclose($st);
        }
    })();
}

/**
 * Build the complete practice archive ZIP.
 * Returns ['put'=>artifactPutResult, 'manifest'=>array, 'sectionsDone'=>[]].
 * Throws on fatal errors; per-file problems are recorded in the manifest.
 */
function exportArchiveBuild(PDO $pdo, array $export, string $tmpDir, string $workerId): array {
    $exportId = (int)$export['id'];
    $pid = (int)$export['practice_id'];
    $uid = (int)$export['user_id'];
    $snapshotAt = null;

    $lastBeat = 0.0;
    $heartbeat = function () use ($pdo, $exportId, $workerId, &$lastBeat) {
        $now = microtime(true);
        if ($now - $lastBeat >= EW_HEARTBEAT_SECONDS) {
            $lastBeat = $now;
            return ewHeartbeat($pdo, $exportId, $workerId);
        }
        return true;
    };

    $manifestFiles = [];
    $sums = [];
    $warnings = [];

    // ---------- Phase A: consistent snapshot -> JSONL temp files ----------
    // eaSnapshot wraps all reads in one REPEATABLE READ transaction and
    // commits before any file/ZIP work begins.
    $snap = eaSnapshot($pdo, $pid, $tmpDir);
    $snapshotAt = $snap['snapshot_at'] ?? null;
    $counts = $snap['counts'];
    foreach (($snap['notes'] ?? []) as $sec => $note) {
        $warnings[] = "section {$sec}: {$note}";
    }

    // Bounded-memory guard: the JSONL snapshot lives on the container
    // filesystem (memory-backed on Cloud Run). Refuse oversized
    // snapshots with a clear error instead of letting the instance OOM.
    // Binary objects are NOT included - they stream to the zip.
    $snapBytes = 0;
    foreach ($snap['files'] as $f) {
        $s = @filesize($f);
        if ($s !== false) $snapBytes += $s;
    }
    $maxSnapBytes = max(1, (int)(getEnvVar('EXPORT_ARCHIVE_SNAPSHOT_MAX_MB') ?? '512')) * 1024 * 1024;
    if ($snapBytes > $maxSnapBytes) {
        throw new RuntimeException("practice archive snapshot exceeds configured limit ({$snapBytes} > {$maxSnapBytes} bytes)");
    }

    // ---------- Phase B: ZIP assembly ----------
    // Sections stream JSONL -> CSV/JSON -> zip entry -> (GCS resumable
    // upload | local temp file). No per-section staging files and no
    // whole-archive temp file: peak memory is bounded by chunk buffers.
    $artifactName = "dentatrak-practice-archive-{$exportId}.zip";
    $gcsUploadPath = null;
    if (exportArtifactDriver() === 'gcs') {
        [$zipOut, $gcsUploadPath] = exportArtifactOpenStream($pid, $exportId, $artifactName);
    } else {
        $zipPath = $tmpDir . '/archive.zip';
        $zipOut = fopen($zipPath, 'wb');
        if ($zipOut === false) throw new RuntimeException('cannot open archive temp file');
    }
    $zip = new ZipStream(
        outputStream: $zipOut,
        outputName: "dentatrak-practice-archive-{$exportId}.zip",
        sendHttpHeaders: false,
        defaultCompressionMethod: CompressionMethod::DEFLATE,
        enableZip64: true,
        defaultEnableZeroHeader: true,
        flushOutput: true,
    );

    $F = $snap['files'];

    // ---- cases/cases.csv (Excel-ready) ----
    if (isset($F['cases'])) {
        $stageLabel = getWorkflowStageLabelOverridesForPractice($pid);
        $dec = 'eaDec';
        eaZipCsv($zip, 'cases/cases.csv', [
            'case_id','patient_first_name','patient_last_name','patient_dob','patient_gender',
            'dentist_name','case_type','case_type_label','tooth_shade','material',
            'due_date','patient_appointment_date','creation_date','last_update_date',
            'delivered_at','status','status_label','notes','assigned_to',
            'archived','archived_date','carrier','tracking_number','custom_carrier',
            'reviewed_at','reviewed_by_user_id','lab_ack_status','lab_promised_date',
            'revision_count','created_by_user_id','attachment_count','clinical_details_json'
        ], (function () use ($F, $stageLabel, $dec) {
            foreach (eaJsonlRows($F['cases']) as $c) {
                $atts = json_decode($c['attachments_json'] ?? '[]', true);
                yield [
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
                ];
            }
        })(), $sums);
        $heartbeat();
    }

    // ---- cases/cases.json (full fidelity) ----
    if (isset($F['cases'])) {
        $stageLabel = getWorkflowStageLabelOverridesForPractice($pid);
        eaZipJson($zip, 'cases/cases.json', eaJsonlRows($F['cases']), function ($c) use ($stageLabel) {
            $row = $c;
            foreach (['patient_first_name','patient_last_name','patient_dob','dentist_name','notes'] as $f) {
                $row[$f] = eaDec($c[$f] ?? '');
            }
            $row['attachments'] = json_decode($c['attachments_json'] ?? '[]', true) ?: [];
            $row['revisions'] = json_decode($c['revisions_json'] ?? '[]', true) ?: [];
            $row['clinicalDetails'] = json_decode($c['clinical_details_json'] ?? '{}', true) ?: [];
            $row['statusLabel'] = resolveWorkflowStageLabel($c['status'], $stageLabel);
            unset($row['attachments_json'], $row['revisions_json'], $row['clinical_details_json']);
            return $row;
        }, $sums);
        $heartbeat();
    }

    // ---- Relational history as CSV (streamed from JSONL, not held in memory) ----
    $csvSections = [
        'comments' => ['id','case_id','user_id','user_email','comment_text','mentions','is_deleted','created_at','updated_at'],
        'activity' => null, // dynamic header below
        'assignments' => null,
        'lab_periods' => null,
        'lab_acks' => null,
        'remakes' => null,
        'labels' => null,
        'label_assignments' => null,
        'scanner_incoming' => null,
        'scanner_files' => null,
        'scanner_events' => null,
        'phi_access_log' => null,
        'integration_sync_runs' => null,
        'integration_entity_mappings' => null,
        'user_prefs' => null,
    ];
    // Columns that may carry raw payloads/metadata are stripped per section.
    $columnDenylist = [
        'integration_entity_mappings' => ['metadata_json'],
        'integration_sync_runs' => [],
    ];
    foreach ($csvSections as $sec => $hdr) {
        if (!isset($F[$sec])) continue;
        $deny = $columnDenylist[$sec] ?? [];
        // Pass 1: header from the first row's keys (dynamic sections).
        if ($hdr === null) {
            $first = null;
            foreach (eaJsonlRows($F[$sec]) as $r) {
                foreach ($deny as $d) unset($r[$d]);
                $first = $r; break;
            }
            $hdr = $first ? array_keys($first) : ['empty'];
        }
        // Pass 2: stream rows to the zip entry as CSV.
        $rowsGen = (function () use ($F, $sec, $hdr, $deny) {
            foreach (eaJsonlRows($F[$sec]) as $r) {
                foreach ($deny as $d) unset($r[$d]);
                if ($sec === 'comments') {
                    $r['comment_text'] = eaDec($r['comment_text'] ?? '');
                    $r['attachments'] = json_decode($r['attachments_json'] ?? '[]', true) ?: [];
                    unset($r['attachments_json']);
                }
                yield array_map(function ($k) use ($r) {
                    $v = $r[$k] ?? '';
                    return (is_array($v) || is_object($v)) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
                }, $hdr);
            }
        })();
        eaZipCsv($zip, "data/{$sec}.csv", $hdr, $rowsGen, $sums);
        $heartbeat();
    }

    // ---- practice/settings.json ----
    if (isset($F['practice'])) {
        $p = null;
        foreach (eaJsonlRows($F['practice']) as $row) { $p = $row; break; }
        if ($p) {
            $out = ['practice' => eaSanitizePractice($p), 'snapshotAt' => $snapshotAt ?? gmdate('c')];
            eaZipString($zip, 'practice/settings.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $sums);
        }
    }

    // ---- practice/members.csv ----
    if (isset($F['members'])) {
        eaZipCsv($zip, 'practice/members.csv',
            ['user_id','email','display_name','role','is_owner','limited_visibility','can_view_analytics','can_edit_cases','can_add_users','can_add_labels','is_lab','can_view_incoming_scans','created_at'],
            (function () use ($F) {
                foreach (eaJsonlRows($F['members']) as $r) yield eaMemberRow($r);
            })(), $sums);
    }

    // ---- subscription.json (safe metadata) ----
    if (isset($F['subscription'])) {
        $sub = json_decode(file_get_contents($F['subscription']), true);
        if (is_array($sub)) {
            foreach (['stripe_secret','webhook_secret','api_key','client_secret'] as $k) unset($sub[$k]);
            eaZipString($zip, 'practice/subscription.json', json_encode($sub, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $sums);
        }
    }

    // ---- integrations/connections.json (sanitized) ----
    if (isset($F['integration_connections'])) {
        eaZipJson($zip, 'integrations/connections.json', eaJsonlRows($F['integration_connections']), 'eaSanitizeIntegration', $sums);
    }

    // ---- Binary files with integrity checks ----
    eaAddBinaryFiles($zip, $pdo, $pid, $F, $sums, $manifestFiles, $heartbeat);

    $bad = array_filter($manifestFiles, function ($f) {
        return in_array($f['status'] ?? '', ['missing','error','checksum_mismatch'], true)
            || !empty($f['generation_changed']) || !empty($f['size_mismatch']);
    });
    $missing = count(array_filter($manifestFiles, function ($f) {
        return in_array($f['status'] ?? '', ['missing','error','checksum_mismatch'], true);
    }));
    foreach ($bad as $b) {
        $why = $b['status'];
        if (!empty($b['generation_changed'])) $why .= '+generation_changed';
        if (!empty($b['size_mismatch'])) $why .= '+size_mismatch';
        $warnings[] = "file {$b['zipPath']}: {$why}";
    }

    // ---- manifest.json + SHA256SUMS + README ----
    $manifest = [
        'exportInfo' => [
            'exportId' => $exportId, 'practiceId' => $pid, 'type' => 'practice_archive',
            'format' => 'zip', 'snapshotAt' => $snapshotAt ?? gmdate('c'),
            'generatedAt' => gmdate('c'), 'rowCounts' => $counts,
        ],
        'sections' => array_keys($F),
        'sectionNotes' => $snap['notes'] ?? [],
        'files' => $manifestFiles,
        'fileCount' => count($manifestFiles),
        'includedCount' => count(array_filter($manifestFiles, function ($f) { return ($f['status'] ?? '') === 'included'; })),
        'missingOrFailedCount' => $missing,
        'complete' => $missing === 0,
        'warnings' => $warnings,
    ];
    eaZipString($zip, 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $sums);

    $sumsTxt = '';
    foreach ($sums as [$sha, $name]) $sumsTxt .= $sha . '  ' . $name . "\n";
    eaZipString($zip, 'SHA256SUMS', $sumsTxt, $sums);

    $readme = "DentaTrak Complete Practice Archive\n"
        . "=====================================\n\n"
        . "This archive contains your practice's data as of the snapshot time\n"
        . "recorded in manifest.json. It expires 7 days after generation.\n\n"
        . "Contents:\n"
        . "  cases/cases.csv        - all cases (active + archived), Excel-ready\n"
        . "  cases/cases.json       - full case records incl. revisions + clinical details\n"
        . "  data/*.csv             - comments, activity, assignments, lab history,\n"
        . "                          remakes, labels, scanner records, PHI access log,\n"
        . "                          integration sync history\n"
        . "  practice/              - settings, members, subscription metadata\n"
        . "  integrations/          - connection status + sync history (no credentials)\n"
        . "  files/                 - actual attachments, comment images, scanner files\n\n"
        . "Verify integrity: SHA256SUMS lists the SHA-256 of every file.\n"
        . "manifest.json records per-file status; 'missing'/'error' entries mean\n"
        . "the source object could not be read at export time.\n";
    eaZipString($zip, 'README.txt', $readme, $sums);

    $zip->finish();
    fclose($zipOut);

    // ---------- Phase C: store artifact ----------
    if ($gcsUploadPath !== null) {
        // Streamed straight into GCS; the object committed when the
        // stream closed. Size/sha256 were computed inline during writes.
        $put = exportArtifactStreamResult($gcsUploadPath);
    } else {
        $put = exportArtifactPutFile($pid, $exportId, $artifactName, $zipPath);
        // zipPath was moved into exports-v2 (local) or left for cleanup (gcs).
        if ($put['driver'] === 'gcs') @unlink($zipPath);
    }

    $manifest['artifact'] = ['name' => "dentatrak-practice-archive-{$exportId}.zip",
        'size' => $put['size'], 'sha256' => $put['sha256'], 'driver' => $put['driver']];

    // PHI audit for generation.
    logPHIAccess(PHI_ACTION_DATA_EXPORT, null,
        ['export_id' => $exportId, 'type' => 'practice_archive', 'file_size' => $put['size'],
         'file_count' => $manifest['fileCount'], 'missing' => $missing],
        'practice_export', (string)$exportId, $pid);

    return ['put' => $put, 'manifest' => $manifest,
            'sectionsDone' => array_keys($F), 'warnings' => $warnings];
}

/**
 * Worker entry point for practice_archive exports. Mirrors
 * exportWorkerProcessOne's claim/finalize/retry discipline.
 */
function exportWorkerProcessArchive(PDO $pdo, array $export, string $workerId, string $tmpDir): array {
    $exportId = (int)$export['id'];
    $pid = (int)$export['practice_id'];
    $uid = (int)$export['user_id'];

    try {
        $res = exportArchiveBuild($pdo, $export, $tmpDir, $workerId);
        $put = $res['put'];
        $manifest = $res['manifest'];

        // Ownership-verified finalize (same rules as case_data exports).
        if (!ewHeartbeat($pdo, $exportId, $workerId)) {
            exportArtifactDelete($put['driver'], $put['bucket'] ?? null, $put['ref']);
            return ['id' => $exportId, 'status' => 'claim_lost'];
        }
        $expiresAt = $pdo->query("SELECT DATE_FORMAT(NOW() + INTERVAL 7 DAY, '%Y-%m-%d %H:%i:%s')")->fetchColumn();
        $fin = $pdo->prepare("UPDATE data_exports SET status='completed', progress=100,
            sections_done=:sd, manifest_json=:mj,
            file_size=:fs, file_path=:fp, gcs_bucket=:gb, gcs_object=:go, sha256=:sh,
            expires_at=:ex, completed_at=NOW()
            WHERE id=:id AND claimed_by=:w AND status='processing'");
        $fin->execute([
            'sd' => json_encode($res['sectionsDone']), 'mj' => json_encode($manifest),
            'fs' => $put['size'],
            'fp' => $put['driver'] === 'local' ? $put['ref'] : null,
            'gb' => $put['bucket'], 'go' => $put['driver'] === 'gcs' ? $put['ref'] : null,
            'sh' => $put['sha256'], 'ex' => $expiresAt, 'id' => $exportId, 'w' => $workerId,
        ]);
        if ($fin->rowCount() === 0) {
            exportArtifactDelete($put['driver'], $put['bucket'] ?? null, $put['ref']);
            return ['id' => $exportId, 'status' => 'claim_lost'];
        }

        try {
            $email = $pdo->query("SELECT email FROM users WHERE id = " . (int)$uid)->fetchColumn();
            if ($email) ewSendReadyEmail((string)$email, $exportId, $put['size'], $expiresAt, 'archive', $uid);
        } catch (Throwable $e) { error_log('[export-worker] archive email failed: ' . $e->getMessage()); }

        if (function_exists('logUserActivity')) {
            logUserActivity($uid, 'data_export_v2_completed', "Practice archive completed (ID: {$exportId})");
        }
        return ['id' => $exportId, 'status' => 'completed', 'size' => $put['size'],
                'warnings' => count($res['warnings'])];
    } catch (Throwable $e) {
        error_log('[export-worker] archive ' . $exportId . ' failed: ' . $e->getMessage());
        // Cancel any in-flight GCS upload session so a partially-built
        // archive can never commit as an object.
        try { exportArtifactAbortStreams(); } catch (Throwable $ignored) {}
        $attempts = (int)($export['attempts'] ?? 0);
        if ($attempts < EW_MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE data_exports SET status='queued', claimed_at=NULL, claimed_by=NULL,
                error_message=:em, progress=0 WHERE id=:id AND claimed_by=:w")
                ->execute(['em' => 'Archive processing failed (will retry)', 'id' => $exportId, 'w' => $workerId]);
            return ['id' => $exportId, 'status' => 'queued', 'error' => $e->getMessage(), 'retry' => true];
        }
        $pdo->prepare("UPDATE data_exports SET status='failed', error_message=:em, progress=0
            WHERE id=:id AND claimed_by=:w")
            ->execute(['em' => 'Archive processing failed', 'id' => $exportId, 'w' => $workerId]);
        exportArtifactCleanupFailedExport($exportId);
        ewNotifyExportFailed($pdo, $exportId, (int)($export['user_id'] ?? 0) ?: null, 'archive');
        return ['id' => $exportId, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}
