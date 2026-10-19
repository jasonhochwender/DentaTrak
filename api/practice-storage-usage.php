<?php
/**
 * Practice Storage Usage (DevTools reporting)
 *
 * Computes and caches how much storage a single practice's files occupy.
 * Used only by DevTools -> Practice Administration (api/admin-practices.php).
 *
 * Measurement model:
 * - Case attachments and comment images carry a byte size recorded at upload
 *   time (verified against the storage object during ingest), so they are
 *   summed from stored metadata without touching the objects.
 * - Scanner inbox files only carry a vendor-declared size, and a file can be
 *   deleted from storage while its row remains; each scanner row is therefore
 *   stat'ed against the real object (bounded count - scanner rows per
 *   practice are small). Missing objects are excluded.
 * - The practice logo has no recorded size; it is stat'ed as well.
 * - The same object referenced by both scanner_scan_files and a case's
 *   attachments_json is counted once, deduplicated by storage path.
 *
 * Excluded by design:
 * - Objects with no database reference (orphaned pending_* uploads) - they
 *   are transient and swept by cleanup-orphan-uploads within ~24h. Summing
 *   them would require enumerating the whole bucket prefix per practice.
 * - Objects removed from storage outside application flows cannot be
 *   detected for attachments/comments without a per-object stat; scanner
 *   rows and logos ARE stat'ed and therefore excluded when missing.
 *
 * Nothing here returns file names, patient data, object paths, or signed
 * URLs - only aggregate byte totals and file counts.
 */

require_once __DIR__ . '/gcs-storage.php';

const PRACTICE_STORAGE_REFRESH_STALE_SECONDS = 120;

/**
 * Lazily create the cached-usage table (same pattern as
 * practice-display-settings.php). Safe to call on every request.
 */
function ensurePracticeStorageUsageTable(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS practice_storage_usage (
            practice_id INT UNSIGNED NOT NULL PRIMARY KEY,
            status VARCHAR(16) NOT NULL DEFAULT 'idle',
            claimed_at DATETIME NULL,
            total_bytes BIGINT UNSIGNED NULL,
            image_bytes BIGINT UNSIGNED NULL,
            image_files INT UNSIGNED NULL,
            scan_bytes BIGINT UNSIGNED NULL,
            scan_files INT UNSIGNED NULL,
            other_bytes BIGINT UNSIGNED NULL,
            other_files INT UNSIGNED NULL,
            branding_bytes BIGINT UNSIGNED NULL,
            branding_files INT UNSIGNED NULL,
            unmeasured_files INT UNSIGNED NULL,
            measurement_notes TEXT NULL,
            calculated_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ensured = true;
}

/**
 * Normalize a cached row (or an empty result) into the API payload shape.
 * Contains only aggregate numbers and timestamps - never identifiers.
 */
function practiceStorageUsagePayload(?array $row): array {
    if (!$row) {
        return [
            'calculated' => false,
            'calculated_at' => null,
            'total_bytes' => null,
            'categories' => [
                'image' => ['bytes' => null, 'files' => null],
                'scan' => ['bytes' => null, 'files' => null],
                'other' => ['bytes' => null, 'files' => null],
                'branding' => ['bytes' => null, 'files' => null],
            ],
            'unmeasured_files' => 0,
            'partial' => false,
            'notes' => [],
            'refresh_in_progress' => false,
            'last_error' => null,
        ];
    }

    $notes = [];
    if (!empty($row['measurement_notes'])) {
        $decoded = json_decode($row['measurement_notes'], true);
        if (is_array($decoded)) {
            $notes = $decoded;
        }
    }

    return [
        'calculated' => $row['calculated_at'] !== null,
        'calculated_at' => $row['calculated_at'],
        'total_bytes' => $row['total_bytes'] !== null ? (int)$row['total_bytes'] : null,
        'categories' => [
            'image' => ['bytes' => $row['image_bytes'] !== null ? (int)$row['image_bytes'] : null,
                        'files' => $row['image_files'] !== null ? (int)$row['image_files'] : null],
            'scan' => ['bytes' => $row['scan_bytes'] !== null ? (int)$row['scan_bytes'] : null,
                       'files' => $row['scan_files'] !== null ? (int)$row['scan_files'] : null],
            'other' => ['bytes' => $row['other_bytes'] !== null ? (int)$row['other_bytes'] : null,
                        'files' => $row['other_files'] !== null ? (int)$row['other_files'] : null],
            'branding' => ['bytes' => $row['branding_bytes'] !== null ? (int)$row['branding_bytes'] : null,
                           'files' => $row['branding_files'] !== null ? (int)$row['branding_files'] : null],
        ],
        'unmeasured_files' => (int)($row['unmeasured_files'] ?? 0),
        'partial' => !empty($notes) || (int)($row['unmeasured_files'] ?? 0) > 0,
        'notes' => $notes,
        'refresh_in_progress' => ($row['status'] ?? 'idle') === 'running',
        'last_error' => $row['last_error'] ?? null,
    ];
}

/**
 * Read the cached usage row for a practice. Returns null when the practice
 * has never been calculated (or does not exist).
 */
function getPracticeStorageUsage(PDO $pdo, int $practiceId): ?array {
    ensurePracticeStorageUsageTable($pdo);
    $stmt = $pdo->prepare("SELECT * FROM practice_storage_usage WHERE practice_id = ?");
    $stmt->execute([$practiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Atomically claim a refresh so duplicate jobs cannot run. A stale claim
 * (older than PRACTICE_STORAGE_REFRESH_STALE_SECONDS) is reclaimable.
 */
function claimPracticeStorageRefresh(PDO $pdo, int $practiceId): bool {
    ensurePracticeStorageUsageTable($pdo);
    $pdo->prepare("INSERT IGNORE INTO practice_storage_usage (practice_id) VALUES (?)")
        ->execute([$practiceId]);
    $stmt = $pdo->prepare("
        UPDATE practice_storage_usage
        SET status = 'running', claimed_at = NOW(), last_attempt_at = NOW()
        WHERE practice_id = :pid
          AND (status <> 'running'
               OR claimed_at IS NULL
               OR claimed_at < NOW() - INTERVAL " . PRACTICE_STORAGE_REFRESH_STALE_SECONDS . " SECOND)
    ");
    $stmt->execute(['pid' => $practiceId]);
    return $stmt->rowCount() > 0;
}

/**
 * Recompute and persist the usage totals for a practice. On failure the
 * previous successful totals and calculated_at are preserved and
 * last_error is set instead.
 */
function refreshPracticeStorageUsage(PDO $pdo, int $practiceId): array {
    if (!claimPracticeStorageRefresh($pdo, $practiceId)) {
        return [
            'refreshed' => false,
            'in_progress' => true,
            'usage' => practiceStorageUsagePayload(getPracticeStorageUsage($pdo, $practiceId)),
        ];
    }

    try {
        $totals = computePracticeStorageTotals($pdo, $practiceId);
        $stmt = $pdo->prepare("
            UPDATE practice_storage_usage SET
                status = 'idle',
                total_bytes = :total_bytes,
                image_bytes = :image_bytes, image_files = :image_files,
                scan_bytes = :scan_bytes, scan_files = :scan_files,
                other_bytes = :other_bytes, other_files = :other_files,
                branding_bytes = :branding_bytes, branding_files = :branding_files,
                unmeasured_files = :unmeasured_files,
                measurement_notes = :notes,
                calculated_at = NOW(),
                last_error = NULL
            WHERE practice_id = :pid
        ");
        $stmt->execute([
            'total_bytes' => $totals['total_bytes'],
            'image_bytes' => $totals['image_bytes'], 'image_files' => $totals['image_files'],
            'scan_bytes' => $totals['scan_bytes'], 'scan_files' => $totals['scan_files'],
            'other_bytes' => $totals['other_bytes'], 'other_files' => $totals['other_files'],
            'branding_bytes' => $totals['branding_bytes'], 'branding_files' => $totals['branding_files'],
            'unmeasured_files' => $totals['unmeasured_files'],
            'notes' => $totals['notes'] ? json_encode($totals['notes']) : null,
            'pid' => $practiceId,
        ]);
        return [
            'refreshed' => true,
            'in_progress' => false,
            'usage' => practiceStorageUsagePayload(getPracticeStorageUsage($pdo, $practiceId)),
        ];
    } catch (Throwable $e) {
        error_log('[storage-usage] refresh failed for practice ' . $practiceId . ': ' . $e->getMessage());
        try {
            $pdo->prepare("
                UPDATE practice_storage_usage
                SET status = 'idle', last_error = :err
                WHERE practice_id = :pid
            ")->execute(['err' => substr($e->getMessage(), 0, 500), 'pid' => $practiceId]);
        } catch (Throwable $ignored) {
            error_log('[storage-usage] could not record failure state for practice ' . $practiceId);
        }
        return [
            'refreshed' => false,
            'in_progress' => false,
            'error' => 'calculation_failed',
            'usage' => practiceStorageUsagePayload(getPracticeStorageUsage($pdo, $practiceId)),
        ];
    }
}

/**
 * Stat a stored object and return its real size in bytes, or null when the
 * object is missing or cannot be stat'ed. Supports the two storage drivers
 * used by scanner files and attachments: 'gcs' and the dev-only 'local'
 * mirror under uploads/scan-objects.
 */
function statStoredObjectSize(string $driver, string $storagePath): ?int {
    if ($storagePath === '') {
        return null;
    }
    if ($driver === 'local') {
        // Same strict mapping as ScanFileStore::localPath(): only
        // cases/... keys, no traversal.
        if (strpos($storagePath, '..') !== false || strpos($storagePath, 'cases/') !== 0) {
            return null;
        }
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
             . 'scan-objects' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        return is_file($abs) ? (int)filesize($abs) : null;
    }
    try {
        $object = getGcsBucket()->object($storagePath);
        if (!$object->exists()) {
            return null;
        }
        $info = $object->info();
        return isset($info['size']) ? (int)$info['size'] : null;
    } catch (Throwable $e) {
        error_log('[storage-usage] stat failed for object: ' . $e->getMessage());
        return null;
    }
}

/**
 * Classify an attachment record into 'image', 'scan', or 'other'. Prefers
 * the structured upload type over MIME/extension inference.
 */
function categorizePracticeAttachment(array $entry): string {
    $type = (string)($entry['type'] ?? '');
    if ($type === 'photos') {
        return 'image';
    }
    if (in_array($type, ['intraoralScans', 'facialScans', 'photogrammetry'], true)) {
        return 'scan';
    }
    if (in_array($type, ['completedDesigns', 'otherDocuments'], true)) {
        return 'other';
    }

    $mime = strtolower((string)($entry['mimeType'] ?? $entry['fileType'] ?? ''));
    if (strpos($mime, 'image/') === 0) {
        return 'image';
    }
    if (strpos($mime, 'model/') === 0) {
        return 'scan';
    }
    if ($mime === 'application/pdf' || strpos($mime, 'text/') === 0 || strpos($mime, 'application/') === 0) {
        // Vendor scans are frequently delivered as PDF/octet-stream; a
        // scanner-sourced entry is a scan, anything else is other.
        if (($entry['source'] ?? '') === 'scanner') {
            return 'scan';
        }
        return 'other';
    }

    // Last resort: well-known 3D/scan extensions.
    $name = strtolower((string)($entry['fileName'] ?? $entry['name'] ?? ''));
    if (preg_match('/\.(stl|ply|obj|dcm|dicom|xyz|exocad|3oxz|3ox|cdt|dentproject)$/i', $name)) {
        return 'scan';
    }
    if (preg_match('/\.(png|jpe?g|gif|webp|bmp|tiff?|heic|heif)$/i', $name)) {
        return 'image';
    }
    return 'other';
}

/**
 * Walk a JSON attachment array, deduplicating by storage path, and fold
 * each entry into $totals. Entries without a recorded size are stat'ed;
 * ones that still cannot be measured increment unmeasured_files (never
 * silently counted as zero).
 */
function accumulateAttachments(?string $json, array &$totals, array &$seen): void {
    if (!$json || trim($json) === '' || trim($json) === '[]') {
        return;
    }
    $entries = json_decode($json, true);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = (string)($entry['storagePath'] ?? $entry['path'] ?? '');
        if ($path !== '' && isset($seen[$path])) {
            continue; // same underlying object referenced elsewhere
        }

        $size = null;
        if (isset($entry['size']) && is_numeric($entry['size']) && (int)$entry['size'] >= 0) {
            $size = (int)$entry['size'];
        } elseif ($path !== '') {
            $driver = (($entry['storageType'] ?? 'gcs') === 'local') ? 'local' : 'gcs';
            $size = statStoredObjectSize($driver, $path);
        }

        if ($size === null) {
            $totals['unmeasured_files']++;
            if (!in_array('unmeasured_attachments', $totals['notes'], true)) {
                $totals['notes'][] = 'unmeasured_attachments';
            }
            continue;
        }

        if ($path !== '') {
            $seen[$path] = true;
        }
        $cat = categorizePracticeAttachment($entry);
        $totals[$cat . '_bytes'] += $size;
        $totals[$cat . '_files']++;
    }
}

/**
 * Compute current storage totals for a practice from stored metadata plus
 * bounded object stats. Returns category totals, counts, an unmeasured
 * counter, and measurement notes. Does not enumerate storage buckets.
 */
function computePracticeStorageTotals(PDO $pdo, int $practiceId): array {
    $totals = [
        'image_bytes' => 0, 'image_files' => 0,
        'scan_bytes' => 0, 'scan_files' => 0,
        'other_bytes' => 0, 'other_files' => 0,
        'branding_bytes' => 0, 'branding_files' => 0,
        'unmeasured_files' => 0,
        'notes' => [],
    ];
    $seen = [];

    // 1) Scanner files first: their declared_size is vendor-supplied, so each
    //    row is stat'ed for the true size and to detect objects deleted while
    //    the DB row survives. Retained revisions (status='superseded') still
    //    occupy storage and are counted. Rows with no storage_path have not
    //    been ingested yet - nothing occupies storage, so they are skipped.
    try {
        $scanStmt = $pdo->prepare("
            SELECT sf.storage_driver, sf.storage_path
            FROM scanner_scan_files sf
            JOIN scanner_incoming_scans s ON s.id = sf.scan_id
            WHERE s.practice_id = :pid AND sf.storage_path IS NOT NULL AND sf.storage_path <> ''
        ");
        $scanStmt->execute(['pid' => $practiceId]);
        $missing = 0;
        foreach ($scanStmt->fetchAll(PDO::FETCH_ASSOC) as $sf) {
            $path = (string)$sf['storage_path'];
            if (isset($seen[$path])) {
                continue;
            }
            $size = statStoredObjectSize((string)($sf['storage_driver'] ?: 'gcs'), $path);
            if ($size === null) {
                $missing++;
                continue; // object deleted or unreachable - not counted
            }
            $seen[$path] = true;
            $totals['scan_bytes'] += $size;
            $totals['scan_files']++;
        }
        if ($missing > 0) {
            $totals['notes'][] = 'scanner_objects_missing';
        }
    } catch (PDOException $e) {
        // Scanner tables may not exist on pre-migration schemas - treat as
        // "no scanner files", not as a failure.
        if (strpos($e->getMessage(), '42S02') === false) {
            throw $e;
        }
    }

    // 2) Case attachments (includes archived cases - archived files remain
    //    stored and billable, so they are counted).
    $caseStmt = $pdo->prepare("
        SELECT attachments_json FROM cases_cache
        WHERE practice_id = :pid
          AND attachments_json IS NOT NULL
          AND attachments_json NOT IN ('', '[]')
    ");
    $caseStmt->execute(['pid' => $practiceId]);
    foreach ($caseStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        accumulateAttachments($json, $totals, $seen);
    }

    // 3) Comment attachments. Soft-deleted comments keep their objects, so
    //    no is_deleted filter is applied.
    try {
        $commentStmt = $pdo->prepare("
            SELECT attachments_json FROM case_comments
            WHERE practice_id = :pid
              AND attachments_json IS NOT NULL
              AND attachments_json NOT IN ('', '[]')
        ");
        $commentStmt->execute(['pid' => $practiceId]);
        foreach ($commentStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            accumulateAttachments($json, $totals, $seen);
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), '42S02') === false) {
            throw $e;
        }
    }

    // 4) Practice logo (branding). logo_path is either
    //    'api/logo.php?p=logos/{pid}/{file}' (GCS) or a legacy web-relative
    //    local path under uploads/logos/.
    $logoStmt = $pdo->prepare("SELECT logo_path FROM practices WHERE id = ?");
    $logoStmt->execute([$practiceId]);
    $logoPath = (string)($logoStmt->fetchColumn() ?: '');
    if ($logoPath !== '') {
        $logoSize = null;
        if (preg_match('/[?&]p=(logos\/[^&\s]+)/', $logoPath, $m)) {
            $logoSize = statStoredObjectSize('gcs', $m[1]);
        } elseif (strpos($logoPath, 'uploads/') === 0 && strpos($logoPath, '..') === false) {
            $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);
            $logoSize = is_file($abs) ? (int)filesize($abs) : null;
        }
        if ($logoSize !== null) {
            $totals['branding_bytes'] += $logoSize;
            $totals['branding_files']++;
        } else {
            $totals['notes'][] = 'branding_object_missing';
        }
    }

    $totals['total_bytes'] = $totals['image_bytes'] + $totals['scan_bytes']
        + $totals['other_bytes'] + $totals['branding_bytes'];

    return $totals;
}
