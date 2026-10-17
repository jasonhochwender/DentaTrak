<?php
/**
 * ScanFileStore
 *
 * Object storage boundary for incoming scan files. Two drivers share ONE
 * object-key convention so attachment records are identical in every
 * environment:
 *
 *   cases/{practiceId}/pending_scan_{fileId}/intraoralScans/{hex}-{name}
 *       - staged at import; the `pending_` segment exempts it from per-case
 *         ACL (like pending manual uploads) while the practice prefix still
 *         scopes it to the owning practice.
 *   cases/{practiceId}/{caseId}/intraoralScans/{hex}-{name}
 *       - after attach: moveToCase() relocates the object so the existing
 *         attachment endpoints' per-case access checks apply verbatim.
 *
 * Drivers:
 *   'gcs'   - the configured Google Cloud Storage bucket (production path;
 *             the same bucket and key space as manually uploaded case files).
 *   'local' - DEV/TEST ONLY disk mirror under uploads/scan-objects/ for
 *             environments without a configured bucket. Never available in
 *             production: driver() throws if the bucket is missing AND the
 *             environment is production-like, so no deployment can silently
 *             spill scan bytes to webroot disk.
 *
 * The local mirror directory is created at first write with a deny-all
 * .htaccess and index.html - bytes are only ever served through
 * api/scan-file.php after authorization, never directly by the web server.
 */

require_once __DIR__ . '/../../gcs-storage.php';

class ScanFileStore {

    public const DRIVER_GCS   = 'gcs';
    public const DRIVER_LOCAL = 'local';

    /** Max bytes accepted into store() - mirrors the GCS per-file ceiling. */
    public static function maxFileBytes(): int {
        global $appConfig;
        return (int)($appConfig['gcs']['max_file_size'] ?? (250 * 1024 * 1024));
    }

    /**
     * Resolve the storage driver. Fails closed in production when no bucket
     * is configured.
     */
    public static function driver(): string {
        global $appConfig;
        if (!empty($appConfig['gcs']['bucket_name'])) {
            return self::DRIVER_GCS;
        }
        $env = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
        if (!in_array($env, ['development', 'local', 'test', 'uat'], true)
            && getEnvVar('DENTATRAK_TEST_MODE', 'false') !== 'true') {
            throw new RuntimeException('Scan file storage is unavailable: no bucket configured.');
        }
        return self::DRIVER_LOCAL;
    }

    /**
     * Allowed file extensions mirror the GCS upload allowlist (scan-relevant
     * subset; a zip is stored opaque - its contents are never extracted).
     */
    private static function sanitizeName(string $fileName): string {
        $name = sanitizeGcsFilename($fileName);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'scan.bin';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        global $appConfig;
        $allowed = $appConfig['gcs']['allowed_extensions'] ?? [];
        if ($allowed && !in_array($ext, $allowed, true)) {
            // Keep the name readable but force an inert extension.
            $name = preg_replace('/\.[A-Za-z0-9]{1,10}$/', '', $name) . '.bin';
        }
        return $name;
    }

    /**
     * Store scan file bytes under the pending key.
     *
     * @return array ['storage_driver','storage_path','size','sha256']
     * @throws RuntimeException when the driver is unavailable or the write fails.
     */
    public static function store(int $practiceId, int $scanFileId, string $fileName, string $content, ?string $contentType): array {
        $driver = self::driver();
        $safeName = self::sanitizeName($fileName);
        $storagePath = sprintf(
            'cases/%d/pending_scan_%d/intraoralScans/%s-%s',
            $practiceId,
            $scanFileId,
            bin2hex(random_bytes(8)),
            $safeName
        );

        if ($driver === self::DRIVER_GCS) {
            $bucket = getGcsBucket();
            $bucket->upload($content, [
                'name' => $storagePath,
                'metadata' => ['contentType' => $contentType ?: 'application/octet-stream'],
            ]);
        } else {
            self::localWrite($storagePath, $content);
        }

        return [
            'storage_driver' => $driver,
            'storage_path'   => $storagePath,
            'size'           => strlen($content),
            'sha256'         => hash('sha256', $content),
        ];
    }

    /**
     * Relocate a stored object into a case-owned key after attach so the
     * existing per-case ACL (attachment-content.php / download-signed-url.php)
     * applies verbatim. Returns the new storage path.
     *
     * @throws RuntimeException on failure - the caller must NOT mark the
     *         file attached when this throws (retry-safe: a retry copies
     *         from whichever key still exists).
     */
    public static function moveToCase(string $storagePath, string $driver, int $practiceId, string $caseId): string {
        // cases/{pid}/pending_scan_{fileId}/{uploadType}/{file}
        //   -> cases/{pid}/{caseId}/{uploadType}/{file}
        // Segment-based rewrite (no regex replacement pitfalls); case IDs are
        // validated to be simple identifiers before being embedded in a path.
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $caseId)) {
            throw new RuntimeException('Unrecognized case identifier.');
        }
        $segments = explode('/', $storagePath);
        if (count($segments) !== 5
            || $segments[0] !== 'cases'
            || (int)$segments[1] !== $practiceId
            || strpos($segments[2], 'pending_scan_') !== 0) {
            throw new RuntimeException('Unrecognized scan storage path layout.');
        }
        $segments[2] = $caseId;
        $newPath = implode('/', $segments);

        if ($driver === self::DRIVER_GCS) {
            $bucket = getGcsBucket();
            $source = $bucket->object($storagePath);
            if (!$source->exists()) {
                // Idempotent: already moved (e.g. retry after partial link).
                if ($bucket->object($newPath)->exists()) {
                    return $newPath;
                }
                throw new RuntimeException('Scan file object is missing in storage.');
            }
            if (!$bucket->object($newPath)->exists()) {
                $source->copy($bucket, ['name' => $newPath]);
            }
            $source->delete();
            return $newPath;
        }

        // Local driver.
        $srcAbs = self::localPath($storagePath);
        $dstAbs = self::localPath($newPath);
        if (!is_file($srcAbs)) {
            if (is_file($dstAbs)) {
                return $newPath; // already moved - idempotent
            }
            throw new RuntimeException('Scan file object is missing in storage.');
        }
        $dstDir = dirname($dstAbs);
        if (!is_dir($dstDir) && !mkdir($dstDir, 0750, true) && !is_dir($dstDir)) {
            throw new RuntimeException('Could not create case storage directory.');
        }
        if (!is_file($dstAbs) && !rename($srcAbs, $dstAbs)) {
            throw new RuntimeException('Could not move scan file into case storage.');
        }
        return $newPath;
    }

    /**
     * Whether the stored object exists. Used by the attach flow to verify a
     * claimed-but-unmerged file's bytes are really at the recorded key
     * before its attachment metadata is rebuilt.
     */
    public static function exists(string $driver, string $storagePath): bool {
        try {
            if ($driver === self::DRIVER_GCS) {
                return getGcsBucket()->object($storagePath)->exists();
            }
            return is_file(self::localPath($storagePath));
        } catch (Throwable $e) {
            error_log('[ScanFileStore] exists check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Open a read stream for a stored object.
     * @return array{0: resource, 1: int, 2: string}|null [stream, size, content_type]
     */
    public static function openStream(string $driver, string $storagePath): ?array {
        if ($driver === self::DRIVER_GCS) {
            try {
                $object = getGcsBucket()->object($storagePath);
                if (!$object->exists()) {
                    return null;
                }
                $info = $object->info();
                return [
                    $object->downloadAsStream(),
                    (int)($info['size'] ?? 0),
                    (string)($info['contentType'] ?? 'application/octet-stream'),
                ];
            } catch (Throwable $e) {
                error_log('[ScanFileStore] stream open failed: ' . $e->getMessage());
                return null;
            }
        }

        $abs = self::localPath($storagePath);
        if (!is_file($abs)) {
            return null;
        }
        $stream = fopen($abs, 'rb');
        if ($stream === false) {
            return null;
        }
        return [$stream, filesize($abs), 'application/octet-stream'];
    }

    /** Best-effort delete; never throws. */
    public static function delete(string $driver, string $storagePath): void {
        try {
            if ($driver === self::DRIVER_GCS) {
                deleteGcsObject($storagePath);
                return;
            }
            $abs = self::localPath($storagePath);
            if (is_file($abs)) {
                unlink($abs);
            }
        } catch (Throwable $e) {
            error_log('[ScanFileStore] delete failed for staged object');
        }
    }

    // ----------------------------------------------------------------------
    // Local driver internals (dev/test only)
    // ----------------------------------------------------------------------

    private static function localRoot(): string {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'scan-objects';
    }

    /**
     * Map a `cases/...` object key onto the local mirror. Any key that does
     * not match the strict layout, or resolves outside the mirror root,
     * is rejected (path-traversal guard - the same check the GCS endpoints
     * apply to the `..` substring).
     */
    private static function localPath(string $storagePath): string {
        if (strpos($storagePath, '..') !== false
            || !preg_match('#^cases/\d+/[A-Za-z0-9_.\-/]+$#', $storagePath)) {
            throw new RuntimeException('Invalid scan storage path.');
        }
        $root = self::localRoot();
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
    }

    private static function localWrite(string $storagePath, string $content): void {
        $abs = self::localPath($storagePath);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create scan storage directory.');
        }
        self::ensureLocalRootProtection();
        if (file_put_contents($abs, $content, LOCK_EX) === false) {
            throw new RuntimeException('Could not write scan file to storage.');
        }
    }

    /**
     * Ensure the mirror root is never directly servable: deny-all .htaccess
     * for Apache and an empty index.html for directory listings. Recreated
     * on every write (cheap) so a missing file can never silently expose it.
     */
    private static function ensureLocalRootProtection(): void {
        $root = self::localRoot();
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('Could not create scan storage root.');
        }
        $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "# Scan object mirror - served only via api/scan-file.php after authorization.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $root . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }
}
