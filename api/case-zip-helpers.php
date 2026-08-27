<?php
/**
 * Case-level bulk attachment ZIP helpers.
 *
 * These functions are used by api/download-case-attachments-zip.php and are
 * designed to be independently testable. They perform no GCS I/O themselves.
 */

/**
 * Return the validated maximum synchronous ZIP size in bytes.
 * Controlled by the DENTATRAK_BULK_ZIP_MAX_BYTES environment variable.
 *
 * - Default: 5,368,709,120 bytes (5 GiB)
 * - Hard cap: 5,368,709,120 bytes (5 GiB)
 *
 * With ZipStream-PHP streaming directly to php://output, source attachments
 * and the finished ZIP are not stored in /tmp, so the only per-byte memory
 * cost is the stream buffers used by the library and the GCS client.
 *
 * @return int
 */
function getBulkZipMaxSize(): int {
    $default = 5 * 1024 * 1024 * 1024; // 5,368,709,120 bytes (5 GiB)
    $maxAllowed = $default;

    $env = getEnvVar('DENTATRAK_BULK_ZIP_MAX_BYTES', (string)$default);
    if ($env === null || $env === '') {
        return $default;
    }

    // Reject non-integers, decimals, hex, octal, overflows, and strings
    // larger than PHP_INT_MAX before any cast.
    $value = filter_var($env, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    if ($value <= 0) {
        return $default;
    }
    if ($value > $maxAllowed) {
        return $maxAllowed;
    }
    return $value;
}

/**
 * Check that the ZipStream-PHP library is available.
 *
 * Returns a safe, localized error string if the dependency is missing,
 * or null if it is ready. The message intentionally omits paths,
 * class names, or stack traces.
 *
 * @return string|null
 */
function getZipStreamDependencyError(): ?string {
    if (!class_exists('ZipStream\ZipStream')) {
        return t('attachments.download_all_failed', ['message' => 'ZIP streaming library unavailable']);
    }
    return null;
}

/**
 * Build the eligible attachment list for a case-level ZIP.
 *
 * Shared logic used by both the preflight and the actual download endpoint.
 * It performs the exact-path checks, GCS existence verification, size
 * validation, and filename sanitization. No ZIP or attachment content is
 * downloaded; only metadata is read from GCS.
 *
 * @param array  $case       The cases_cache row from requireCaseAccess()
 * @param string|int $practiceId Current practice
 * @param \Google\Cloud\Storage\Bucket $bucket GCS bucket
 * @return array ['eligible' => [...], 'totalActualSize' => int]
 */
function getEligibleZipAttachments(array $case, $practiceId, $bucket): array {
    $attachments = [];
    if (!empty($case['attachments_json'])) {
        $decoded = json_decode($case['attachments_json'], true);
        if (is_array($decoded)) {
            $attachments = $decoded;
        }
    }

    $eligible = [];
    $totalActualSize = 0;
    $usedNames = [];

    foreach ($attachments as $att) {
        $storagePath = $att['storagePath'] ?? '';
        $fileName = $att['fileName'] ?? ($att['name'] ?? '');
        $storageType = $att['storageType'] ?? '';

        if ($storageType !== 'gcs' || empty($storagePath) || empty($fileName)) {
            continue;
        }

        if (!isValidAttachmentPath($storagePath, $practiceId, $case['case_id'])) {
            continue;
        }

        $object = $bucket->object($storagePath);
        if (!$object->exists()) {
            continue;
        }

        $info = $object->info();
        $actualSize = (int)($info['size'] ?? 0);

        $zipName = sanitizeZipFilename($fileName, $usedNames);
        $usedNames[] = strtolower($zipName);

        $eligible[] = [
            'storagePath' => $storagePath,
            'fileName' => $fileName,
            'zipName' => $zipName,
            'size' => $actualSize,
        ];
        $totalActualSize += $actualSize;
    }

    return ['eligible' => $eligible, 'totalActualSize' => $totalActualSize];
}

/**
 * Validate that a stored attachment path belongs to a specific case.
 *
 * @param string $storagePath The path stored in attachments_json
 * @param string|int $practiceId Current practice
 * @param string $caseId Expected case id
 * @return bool
 */
function isValidAttachmentPath($storagePath, $practiceId, $caseId): bool {
    if (empty($storagePath) || empty($practiceId) || empty($caseId)) {
        return false;
    }

    // Decode any URL encoding before prefix checks.
    $storagePath = rawurldecode($storagePath);

    // Explicitly reject traversal sequences.
    if (strpos($storagePath, '..') !== false || strpos($storagePath, '\\') !== false) {
        return false;
    }

    // Require the exact normalized prefix.
    $expected = 'cases/' . $practiceId . '/' . $caseId . '/';
    return strpos($storagePath, $expected) === 0;
}

/**
 * Sanitize a filename for use inside a ZIP.
 *
 * @param string $filename Original filename
 * @param array  $used      Lowercase list of already-used names
 * @return string
 */
function sanitizeZipFilename($filename, array &$used) {
    // Strip any directory/path components.
    $name = basename($filename);

    // Remove null bytes and control characters.
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);

    // Remove obvious path separators and unsafe filesystem characters.
    $name = preg_replace('/[\\\\\/:*?"<>|]/', '_', $name);

    // Collapse multiple dots and leading/trailing dots.
    $name = preg_replace('/\.{2,}/', '.', $name);
    $name = trim($name, '. ');

    // Limit total length.
    $maxLen = 200;
    if (strlen($name) > $maxLen) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = substr($base, 0, $maxLen - (strlen($ext) ? strlen($ext) + 1 : 0));
        $name = $base . ($ext ? '.' . $ext : '');
    }

    if ($name === '') {
        $name = 'file';
    }

    // Deduplicate: filename.ext, filename (2).ext, filename (3).ext.
    if (!in_array(strtolower($name), $used, true)) {
        $used[] = strtolower($name);
        return $name;
    }

    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $counter = 2;
    while (true) {
        $candidate = $base . ' (' . $counter . ')' . ($ext ? '.' . $ext : '');
        if (!in_array(strtolower($candidate), $used, true)) {
            $used[] = strtolower($candidate);
            return $candidate;
        }
        $counter++;
        if ($counter > 1000) {
            $fallback = uniqid('file_', true) . ($ext ? '.' . $ext : '');
            $used[] = strtolower($fallback);
            return $fallback;
        }
    }
}
