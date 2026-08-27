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
 * - Default: 33,554,432 bytes (32 MiB)
 * - Hard cap: 67,108,864 bytes (64 MiB) because the Cloud Run container's
 *   temporary filesystem is memory-backed. 64 MiB of source files plus 64 MiB
 *   of ZIP output, plus PHP and request overhead, still fits a 256 MiB
 *   Cloud Run instance with a safety margin.
 *
 * @return int
 */
function getBulkZipMaxSize(): int {
    $default = 32 * 1024 * 1024; // 33,554,432 bytes
    $maxAllowed = 64 * 1024 * 1024; // 67,108,864 bytes

    $env = getEnvVar('DENTATRAK_BULK_ZIP_MAX_BYTES', (string)$default);
    if ($env === null || $env === '' || !is_numeric($env)) {
        return $default;
    }

    $value = (int)$env;
    if ($value <= 0) {
        return $default;
    }
    if ($value > $maxAllowed) {
        return $maxAllowed;
    }
    return $value;
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
