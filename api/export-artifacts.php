<?php
/**
 * Export artifact storage.
 *
 * Finished export payloads are PHI. In production they are written to a
 * private prefix of the application GCS bucket (practice-exports/...) and
 * downloaded via short-lived V4 signed URLs. In local development (no GCS
 * bucket configured) artifacts are written under exports-v2/ inside the
 * app root and served through the authenticated download endpoint.
 *
 * Callers must treat every artifact as PHI: never log contents, never
 * expose object names publicly.
 */

require_once __DIR__ . '/appConfig.php';

if (!function_exists('exportArtifactDriver')) {
    /**
     * 'gcs' when a bucket is configured, otherwise 'local' (dev only).
     */
    function exportArtifactDriver(): string {
        global $appConfig;
        $bucket = $appConfig['gcs']['bucket_name'] ?? '';
        return ($bucket !== '') ? 'gcs' : 'local';
    }
}

if (!function_exists('exportArtifactPrefix')) {
    function exportArtifactPrefix(int $practiceId, int $exportId): string {
        return "practice-exports/{$practiceId}/{$exportId}/";
    }
}

if (!function_exists('exportArtifactPut')) {
    /**
     * Store artifact bytes. Returns ['driver','ref','size','sha256'] where
     * ref is the GCS object name or local relative filename.
     */
    function exportArtifactPut(int $practiceId, int $exportId, string $filename, $contents): array {
        $bytes = is_resource($contents) ? stream_get_contents($contents) : $contents;
        if ($bytes === false) {
            throw new RuntimeException('artifact read failed');
        }
        $size = strlen($bytes);
        $sha = hash('sha256', $bytes);

        if (exportArtifactDriver() === 'gcs') {
            require_once __DIR__ . '/gcs-storage.php';
            $objectPath = exportArtifactPrefix($practiceId, $exportId) . $filename;
            $bucket = getGcsBucket();
            $bucket->upload($bytes, ['name' => $objectPath]);
            return ['driver' => 'gcs', 'bucket' => $GLOBALS['appConfig']['gcs']['bucket_name'],
                    'ref' => $objectPath, 'size' => $size, 'sha256' => $sha];
        }

        $dir = __DIR__ . '/../exports-v2';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $rel = "{$practiceId}_{$exportId}_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        file_put_contents($dir . '/' . $rel, $bytes);
        chmod($dir . '/' . $rel, 0600);
        return ['driver' => 'local', 'bucket' => null, 'ref' => $rel, 'size' => $size, 'sha256' => $sha];
    }
}

if (!function_exists('exportArtifactDelete')) {
    function exportArtifactDelete(?string $driver, ?string $bucket, ?string $ref): void {
        if (!$ref) return;
        try {
            if ($driver === 'gcs' && $bucket) {
                require_once __DIR__ . '/gcs-storage.php';
                deleteGcsObject($ref);
            } elseif ($driver === 'local') {
                $path = __DIR__ . '/../exports-v2/' . basename($ref);
                if (is_file($path)) unlink($path);
            }
        } catch (Throwable $e) {
            error_log('[export-artifacts] delete failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('exportArtifactSignedUrl')) {
    /**
     * Short-lived download URL. GCS: V4 signed URL (15 min). Local dev:
     * app-mediated URL handled by data-export-v2.php?action=download_file.
     */
    function exportArtifactSignedUrl(array $export, int $ttlSeconds = 900): ?string {
        if (($export['driver'] ?? '') === 'gcs') {
            require_once __DIR__ . '/gcs-storage.php';
            $name = $export['ref'] ?? '';
            if ($name === '') return null;
            $object = getGcsBucket()->object($name);
            $url = $object->signedUrl(
                new DateTime('+' . $ttlSeconds . ' seconds'),
                ['method' => 'GET', 'version' => 'v4',
                 'responseDisposition' => 'attachment; filename="' .
                     preg_replace('/[^A-Za-z0-9._-]/', '_', $export['filename'] ?? 'export') . '"']
            );
            return (string)$url;
        }
        // Local dev fallback: authenticated app endpoint.
        $base = rtrim($GLOBALS['appConfig']['baseUrl'] ?? '', '/');
        return $base . '/api/data-export-v2.php?action=download_file&export_id=' . (int)$export['id'];
    }
}
