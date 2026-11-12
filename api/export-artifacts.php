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

if (!function_exists('exportWorkerMetadataToken')) {
    /**
     * Access token from the Cloud Run metadata server. Returns '' outside
     * GCP (local dev), which makes the job kick a no-op.
     */
    function exportWorkerMetadataToken(): string {
        $json = @file_get_contents(
            'http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token',
            false,
            stream_context_create(['http' => ['header' => "Metadata-Flavor: Google\r\n", 'timeout' => 5]])
        );
        $d = $json ? json_decode($json, true) : null;
        return is_array($d) ? (string)($d['access_token'] ?? '') : '';
    }
}

if (!function_exists('exportWorkerKickJob')) {
    /**
     * Best-effort wake of the dedicated Cloud Run Job for one export.
     * Returns true when the Jobs API accepted the run request. Failure is
     * non-fatal: the export row stays 'queued' and the scheduled worker
     * sweep will pick it up on its next run.
     *
     * Requires env EXPORT_RUN_JOB (e.g. projects/.../jobs/dtk-export-worker
     * or the short job id) plus GOOGLE_CLOUD_PROJECT/REGION. The container
     * override sets EXPORT_ID, which the worker honors when --id is absent.
     */
    function exportWorkerKickJob(int $exportId): bool {
        $job = getEnvVar('EXPORT_RUN_JOB') ?? '';
        $project = getEnvVar('GOOGLE_CLOUD_PROJECT') ?? getEnvVar('GCP_PROJECT') ?? '';
        $region = getEnvVar('GOOGLE_CLOUD_REGION') ?? 'us-east1';
        if ($job === '' || $project === '') return false;

        // Accept either a full resource name or a bare job id.
        if (strpos($job, 'jobs/') === false) {
            $job = "projects/{$project}/locations/{$region}/jobs/" . ltrim($job, '/');
        }
        if (strpos($job, 'projects/') !== 0) {
            $job = 'projects/' . $project . '/' . ltrim($job, '/');
        }
        $url = "https://run.googleapis.com/v2/{$job}:run";

        $token = exportWorkerMetadataToken();
        if ($token === '') return false;

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json",
            'content' => json_encode(['overrides' => ['containerOverrides' => [[
                'env' => [['name' => 'EXPORT_ID', 'value' => (string)$exportId]],
            ]]]]),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
        }
        if ($status < 200 || $status >= 300) {
            error_log('[export-dispatch] jobs.run returned HTTP ' . $status . ' for export ' . $exportId);
            return false;
        }
        return true;
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
