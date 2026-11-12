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

if (!function_exists('exportArtifactPutFile')) {
    /**
     * Store an artifact from an on-disk file WITHOUT loading it into
     * memory (archives can be large). Local driver moves the file into
     * exports-v2/; GCS driver streams the upload.
     */
    function exportArtifactPutFile(int $practiceId, int $exportId, string $filename, string $absPath): array {
        $size = filesize($absPath);
        $sha = hash_file('sha256', $absPath);
        if ($size === false || $sha === false) {
            throw new RuntimeException('artifact stat failed');
        }

        if (exportArtifactDriver() === 'gcs') {
            require_once __DIR__ . '/gcs-storage.php';
            $objectPath = exportArtifactPrefix($practiceId, $exportId) . $filename;
            $bucket = getGcsBucket();
            $stream = fopen($absPath, 'rb');
            if ($stream === false) throw new RuntimeException('artifact open failed');
            try {
                $bucket->upload($stream, ['name' => $objectPath]);
            } finally {
                fclose($stream);
            }
            return ['driver' => 'gcs', 'bucket' => $GLOBALS['appConfig']['gcs']['bucket_name'],
                    'ref' => $objectPath, 'size' => $size, 'sha256' => $sha];
        }

        $dir = __DIR__ . '/../exports-v2';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $rel = "{$practiceId}_{$exportId}_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        if (!@rename($absPath, $dir . '/' . $rel)) {
            if (!@copy($absPath, $dir . '/' . $rel)) throw new RuntimeException('artifact store failed');
            @unlink($absPath);
        }
        chmod($dir . '/' . $rel, 0600);
        return ['driver' => 'local', 'bucket' => null, 'ref' => $rel, 'size' => $size, 'sha256' => $sha];
    }
}

if (!function_exists('dataExportV2PracticeAllowed')) {
    /**
     * Server-enforced rollout gate for the v2 export pipeline.
     *
     * DATA_EXPORT_V2 is the master switch. DATA_EXPORT_V2_PRACTICES is a
     * comma-separated list of practice ids allowed to use the feature;
     * it is checked against the practice that owns the export row (and
     * the session practice context), never a client-supplied value, so
     * switching practices cannot bypass it.
     *
     * An empty/unset allowlist permits every practice ONLY in
     * development/test environments. In production (or whenever the
     * environment cannot be determined) an empty allowlist permits
     * NOBODY - enabling DATA_EXPORT_V2 alone can never expose the
     * feature to customers.
     */
    function dataExportV2PracticeAllowed(int $practiceId): bool {
        $enabled = function_exists('isFeatureEnabled')
            ? isFeatureEnabled('DATA_EXPORT_V2')
            : filter_var(getEnvVar('DATA_EXPORT_V2'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return false;

        $list = trim((string)(getEnvVar('DATA_EXPORT_V2_PRACTICES') ?? ''));
        if ($list === '') {
            $env = $GLOBALS['appConfig']['current_environment'] ?? 'production';
            return in_array($env, ['development', 'test', 'local'], true);
        }
        foreach (explode(',', $list) as $p) {
            $p = trim($p);
            if ($p !== '' && (int)$p === $practiceId) return true;
        }
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* Streaming GCS upload                                               */
/*                                                                    */
/* Large practice archives must not be spooled to the container       */
/* filesystem (memory-backed on Cloud Run). This stream wrapper       */
/* pushes ZIP bytes straight into a GCS resumable-upload session in   */
/* bounded chunks while computing SHA-256 and byte count inline, so   */
/* peak memory stays ~2 chunks regardless of archive size. The        */
/* object only materializes when the final chunk (with the true       */
/* total size) is committed; aborting the stream cancels the session  */
/* and leaves no partial object behind.                               */
/* ------------------------------------------------------------------ */

if (!defined('EXPORT_GCS_UPLOAD_CHUNK')) {
    // 8 MiB - a multiple of the 256 KiB resumable-upload alignment.
    define('EXPORT_GCS_UPLOAD_CHUNK', 8 * 1024 * 1024);
}

if (!class_exists('DtkExportGcsUploadStream')) {
    class DtkExportGcsUploadStream {
        public $context;

        private string $pathKey = '';
        private string $bucketName = '';
        private string $objectName = '';
        private $sessionUri = null;
        private string $buffer = '';
        private int $sent = 0;
        private int $size = 0;
        private $hash = null;
        private ?string $error = null;
        private bool $closed = false;
        private bool $aborted = false;
        private ?string $token = null;

        /** @var array<string,self> open streams, for abort-on-failure */
        private static array $open = [];
        /** @var array<string,array> finalized upload results by path */
        private static array $results = [];

        public static function register(): void {
            if (!in_array('dtkexpupload', stream_get_wrappers(), true)) {
                stream_wrapper_register('dtkexpupload', self::class);
            }
        }

        /** Cancel every in-flight upload (e.g. when archive assembly fails). */
        public static function abortAll(): void {
            foreach (self::$open as $s) { $s->aborted = true; $s->cancelSession(); }
            self::$open = [];
        }

        /** Fetch and remove the result recorded by stream_close(). */
        public static function takeResult(string $path): ?array {
            if (!isset(self::$results[$path])) return null;
            $r = self::$results[$path];
            unset(self::$results[$path]);
            return $r;
        }

        public function stream_open($path, $mode, $options, &$opened_path): bool {
            if (!preg_match('#^dtkexpupload://(\d+)/(\d+)/(.+)$#', $path, $m)) return false;
            if (strpos($mode, 'r') !== false && strpos($mode, '+') === false
                && strpos($mode, 'w') === false && strpos($mode, 'a') === false
                && strpos($mode, 'c') === false && strpos($mode, 'x') === false) {
                return false; // write modes only
            }
            $this->pathKey = $path;
            $this->bucketName = (string)($GLOBALS['appConfig']['gcs']['bucket_name'] ?? '');
            if ($this->bucketName === '') return false;
            $this->objectName = exportArtifactPrefix((int)$m[1], (int)$m[2]) . rawurldecode($m[3]);
            $this->hash = hash_init('sha256');
            self::$open[$path] = $this;
            return true;
        }

        public function stream_write($data): int {
            if ($this->closed || $this->aborted) return 0;
            if ($this->error !== null) {
                throw new RuntimeException('gcs upload stream failed: ' . $this->error);
            }
            $len = strlen((string)$data);
            // Feed the buffer in chunk-sized pieces so peak memory stays
            // bounded even when a caller hands us one huge string.
            $off = 0;
            while ($off < $len) {
                $piece = substr((string)$data, $off, EXPORT_GCS_UPLOAD_CHUNK);
                $off += strlen($piece);
                $this->buffer .= $piece;
                hash_update($this->hash, $piece);
                $this->size += strlen($piece);
                // Strictly-greater keeps at least one byte buffered for
                // the final commit chunk.
                while (strlen($this->buffer) > EXPORT_GCS_UPLOAD_CHUNK) {
                    $this->putChunk(substr($this->buffer, 0, EXPORT_GCS_UPLOAD_CHUNK), false);
                    $this->buffer = substr($this->buffer, EXPORT_GCS_UPLOAD_CHUNK);
                }
            }
            return $len;
        }

        public function stream_flush(): bool {
            // Buffered data is already pushed in bounded chunks by
            // stream_write; nothing extra to do.
            return $this->error === null;
        }

        public function stream_close(): void {
            if ($this->closed) return;
            $this->closed = true;
            unset(self::$open[$this->pathKey]);
            if ($this->aborted || $this->error !== null) return;
            try {
                if ($this->size === 0) {
                    throw new RuntimeException('refusing to commit empty export upload');
                }
                // The final chunk carries the true total size, which is
                // what commits the object.
                $this->putChunk($this->buffer, true);
                $this->buffer = '';
                self::$results[$this->pathKey] = [
                    'size' => $this->size,
                    'sha256' => hash_final($this->hash),
                    'ref' => $this->objectName,
                ];
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                $this->cancelSession();
            }
        }

        public function stream_eof(): bool {
            return $this->closed;
        }

        public function stream_stat(): array|false {
            return ['size' => $this->size];
        }

        public function stream_tell(): int|false {
            return $this->sent + strlen($this->buffer);
        }

        private function accessToken(): string {
            if ($this->token !== null) return $this->token;
            try {
                $keyFile = $GLOBALS['appConfig']['gcs']['key_file'] ?? null;
                if ($keyFile && file_exists($keyFile)) {
                    $creds = \Google\Auth\CredentialsLoader::makeCredentials(
                        ['https://www.googleapis.com/auth/devstorage.read_write'],
                        json_decode((string)file_get_contents($keyFile), true));
                } else {
                    $creds = \Google\Auth\ApplicationDefaultCredentials::getCredentials(
                        ['https://www.googleapis.com/auth/devstorage.read_write']);
                }
                $t = $creds->fetchAuthToken();
                if (!empty($t['access_token'])) {
                    $this->token = (string)$t['access_token'];
                    return $this->token;
                }
            } catch (Throwable $e) { /* fall back to the metadata server */ }
            $this->token = exportWorkerMetadataToken();
            return $this->token;
        }

        private function ensureSession(): void {
            if ($this->sessionUri !== null) return;
            $token = $this->accessToken();
            if ($token === '') throw new RuntimeException('gcs credentials unavailable');
            $meta = json_encode(['name' => $this->objectName, 'contentType' => 'application/zip']);
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$token}\r\n"
                    . "Content-Type: application/json; charset=UTF-8\r\n"
                    . "X-Upload-Content-Type: application/zip\r\n"
                    . "X-Upload-Content-Length: *\r\n",
                'content' => $meta,
                'timeout' => 30,
                'ignore_errors' => true,
            ]]);
            @file_get_contents(
                'https://storage.googleapis.com/upload/storage/v1/b/'
                    . rawurlencode($this->bucketName) . '/o?uploadType=resumable',
                false, $ctx);
            $status = 0; $location = null;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
            }
            foreach (($http_response_header ?? []) as $h) {
                if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
            }
            if ((!in_array($status, [200, 201], true)) || !$location
                || !preg_match('#^https://[a-z0-9.\-]*googleapis\.com/#i', $location)) {
                throw new RuntimeException('gcs resumable session failed HTTP ' . $status);
            }
            $this->sessionUri = $location;
        }

        private function putChunk(string $data, bool $final): void {
            $this->ensureSession();
            $len = strlen($data);
            if ($final && $len === 0) {
                // Defensive: the write-path invariant keeps >=1 byte
                // buffered, so an empty final chunk means a bug.
                throw new RuntimeException('gcs upload stream underflow');
            }
            $end = $this->sent + $len - 1;
            $total = $final ? (string)$this->size : '*';
            $range = 'bytes ' . $this->sent . '-' . $end . '/' . $total;
            $ctx = stream_context_create(['http' => [
                'method' => 'PUT',
                'header' => 'Content-Length: ' . $len . "\r\n"
                    . "Content-Type: application/zip\r\n"
                    . 'Content-Range: ' . $range . "\r\n",
                'content' => $data,
                'timeout' => 300,
                'ignore_errors' => true,
            ]]);
            @file_get_contents($this->sessionUri, false, $ctx);
            $status = 0;
            foreach (($http_response_header ?? []) as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
            }
            // 308 = intermediate chunk accepted; 200/201 = object committed.
            if (!in_array($status, [200, 201, 308], true)) {
                throw new RuntimeException('gcs upload chunk failed HTTP ' . $status);
            }
            $this->sent += $len;
        }

        private function cancelSession(): void {
            if ($this->sessionUri === null) return;
            $uri = $this->sessionUri;
            $this->sessionUri = null;
            $ctx = stream_context_create(['http' => [
                'method' => 'DELETE', 'timeout' => 15, 'ignore_errors' => true,
            ]]);
            @file_get_contents($uri, false, $ctx);
        }
    }
}

if (!function_exists('exportArtifactOpenStream')) {
    /**
     * Open a bounded-memory GCS upload stream for an export artifact.
     * Returns [resource $fp, string $path]; pass $path to
     * exportArtifactStreamResult() after fclose(). GCS driver only.
     */
    function exportArtifactOpenStream(int $practiceId, int $exportId, string $filename): array {
        if (exportArtifactDriver() !== 'gcs') {
            throw new RuntimeException('stream upload requires the gcs artifact driver');
        }
        DtkExportGcsUploadStream::register();
        $path = 'dtkexpupload://' . $practiceId . '/' . $exportId . '/' . rawurlencode($filename);
        $fp = fopen($path, 'wb');
        if ($fp === false) throw new RuntimeException('cannot open export upload stream');
        return [$fp, $path];
    }
}

if (!function_exists('exportArtifactStreamResult')) {
    /**
     * Collect the size/sha256/ref recorded when a stream opened by
     * exportArtifactOpenStream() was closed. Throws if the upload did
     * not finalize (the session is cancelled and no object exists).
     */
    function exportArtifactStreamResult(string $path): array {
        $r = DtkExportGcsUploadStream::takeResult($path);
        if ($r === null) {
            throw new RuntimeException('export upload stream did not finalize');
        }
        return ['driver' => 'gcs',
                'bucket' => $GLOBALS['appConfig']['gcs']['bucket_name'],
                'ref' => $r['ref'], 'size' => $r['size'], 'sha256' => $r['sha256']];
    }
}

if (!function_exists('exportArtifactAbortStreams')) {
    /** Cancel all in-flight GCS upload streams (failed builds only). */
    function exportArtifactAbortStreams(): void {
        DtkExportGcsUploadStream::abortAll();
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
        if ($project === '') {
            // Cloud Run does not set GOOGLE_CLOUD_PROJECT by default; read
            // it from the metadata server (same channel as the token).
            $pj = @file_get_contents(
                'http://metadata.google.internal/computeMetadata/v1/project/project-id',
                false,
                stream_context_create(['http' => ['header' => "Metadata-Flavor: Google\r\n", 'timeout' => 3]])
            );
            $project = $pj ? trim($pj) : '';
        }
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
