<?php
/**
 * MeditClient
 *
 * Read-only HTTP client for the Medit Link OpenAPI resource APIs
 * ({resource-base}/v1/* - the RESOURCE host, never the auth host).
 * Only the endpoints documented in the stage guide are implemented:
 *
 *   GET /v1/cases/search?schema=latest&size&page&start&end&name&status&patientCode
 *   GET /v1/cases/{caseUuid}?schema=latest
 *   GET /v1/files/{fileUuid}?type={convert}        (presigned download URL)
 *   GET {presigned S3 url}                          (file bytes, NO auth)
 *
 * Deliberately NOT implemented (read-only rollout scope): patient/case/
 * order creation or mutation, file register/upload/delete, contracts,
 * webhook endpoint CRUD (the webhook is configured in Medit's portal,
 * not via API - WEBHOOK:MANAGE is not among the requested scopes).
 *
 * DOCUMENTED THROTTLING (stage guide): cases/search 5 req/s,
 * files/{uuid} 10 req/s - both "by authenticated information". The client
 * enforces a minimum spacing between resource calls so a run cannot
 * burst past the documented budget regardless of caller pacing.
 *
 * RESPONSE SHAPES ARE VERIFIED, not assumed: a list response without the
 * documented page/content structure throws 'unexpected_response' rather
 * than silently becoming "zero scans".
 *
 * AUTHENTICATION: every call goes through
 * MeditAuthService::withAutoRefresh() - one reactive 401 -> one forced
 * serialized refresh -> one retry, never a loop. Resource calls also
 * carry x-meditlink-client-id and x-meditlink-group-uuid (the group's
 * tenant binding, resolved at connect time from /v1/me).
 *
 * TRANSPORT SEAM: fn($method, $url, $headers, $body) =>
 *   ['status' => int, 'headers' => [...], 'body' => string]
 * plus OPTIONAL 'body_file' => path for streamed downloads.
 *
 * SECURITY:
 *  - Resource URLs are built from the configured base host only; no
 *    endpoint path is ever taken from response data.
 *  - Presigned download URLs are fetched with NO Authorization header at
 *    all: credentials can never be forwarded to a download host. The URL
 *    itself is additionally validated - https only, host restricted to
 *    amazonaws.com / meditlink.com domains. Anything else is rejected.
 *  - Tokens and response bodies are never logged.
 */

require_once __DIR__ . '/../MeditConfig.php';
require_once __DIR__ . '/../IntegrationManager.php';
require_once __DIR__ . '/MeditAuthService.php';

class MeditApiException extends Exception {
    /** @var string PHI-free machine category. */
    public $category;
    /** @var bool */
    public $retryable;
    /** @var int|null parsed Retry-After hint (seconds) when present. */
    public $retryAfter;

    public function __construct(string $category, string $message, bool $retryable = false, ?int $retryAfter = null) {
        parent::__construct($message);
        $this->category = $category;
        $this->retryable = $retryable;
        $this->retryAfter = $retryAfter;
    }
}

class MeditClient {

    /** Documented page ceiling: size 1..100, page 0..1000. */
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 50;

    /** Documented convert types for meditMesh downloads. */
    public const CONVERT_TYPES = ['stl', 'obj', 'ply'];

    /** Hard byte ceiling for a single download (mirrors ScanFileStore). */
    private const MAX_DOWNLOAD_BYTES = 250 * 1024 * 1024;

    /** Minimum spacing between resource calls (well under 5 req/s). */
    private const MIN_CALL_SPACING_US = 250000;

    private const CONNECT_TIMEOUT = 10;
    private const JSON_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 300;

    /** @var PDO */
    private $pdo;
    /** @var int */
    private $connectionId;
    /** @var MeditAuthService */
    private $auth;
    /** @var callable|null */
    private $transport;
    /** @var string|null group uuid bound at connect time */
    private $groupUuid;
    /** @var int last resource-call microtime for throttle pacing */
    private $lastCallUs = 0;

    public function __construct(PDO $pdo, int $connectionId, ?MeditAuthService $auth = null, ?callable $transport = null) {
        $this->pdo = $pdo;
        $this->connectionId = $connectionId;
        $this->auth = $auth ?? new MeditAuthService();
        $this->transport = $transport;
    }

    /**
     * The bound Medit group uuid, lazily read from the connection's
     * config_json. A connection authorized before binding existed (or a
     * corrupt config) cannot make scoped calls - fail closed.
     */
    private function groupUuid(): string {
        if ($this->groupUuid !== null) {
            return $this->groupUuid;
        }
        $connection = IntegrationManager::findConnection($this->pdo, $this->connectionId);
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        $uuid = is_array($config) ? trim((string)($config['group_uuid'] ?? '')) : '';
        if ($uuid === '') {
            throw new MeditApiException('group_unbound', 'Medit connection has no bound group.', false);
        }
        return $this->groupUuid = $uuid;
    }

    // ------------------------------------------------------------------
    // Cases
    // ------------------------------------------------------------------

    /**
     * GET /v1/cases/search - dateCreated window in epoch MILLISECONDS
     * (documented). Returns ['content'=>[...], 'last'=>bool, 'page'=>int,
     * 'totalPage'=>int] verified against the documented shape. HTTP 204
     * ("list could not be found") maps to an empty first page - that is
     * a documented empty result, not silence-as-empty.
     */
    public function listCases(int $size, int $page, int $startMs, int $endMs): array {
        $size = max(1, min(self::MAX_PAGE_SIZE, $size));
        $page = max(0, min(1000, $page));
        $data = $this->apiGetJson('/v1/cases/search', [
            'schema' => 'latest',
            'size'   => $size,
            'page'   => $page,
            'start'  => $startMs,
            'end'    => $endMs,
        ], true);
        if ($data === null) {
            return ['content' => [], 'last' => true, 'page' => $page, 'totalPage' => $page + 1];
        }
        if (!array_key_exists('content', $data) || !is_array($data['content'])) {
            throw new MeditApiException('unexpected_response', 'Medit cases/search response lacked the documented content array.', true);
        }
        return [
            'content'   => $data['content'],
            'last'      => (bool)($data['last'] ?? true),
            'page'      => (int)($data['page'] ?? $page),
            'totalPage' => (int)($data['totalPage'] ?? ($page + 1)),
        ];
    }

    /**
     * GET /v1/cases/{caseUuid} - returns the decoded case including its
     * files[] array. Malformed payloads throw rather than degrade.
     */
    public function getCase(string $caseUuid): array {
        $data = $this->apiGetJson('/v1/cases/' . self::encodeUuid($caseUuid), ['schema' => 'latest'], false);
        if (!isset($data['uuid']) || !is_string($data['uuid'])) {
            throw new MeditApiException('unexpected_response', 'Medit case detail response lacked the case uuid.', true);
        }
        if (array_key_exists('files', $data) && !is_array($data['files'])) {
            throw new MeditApiException('unexpected_response', 'Medit case files field was not an array.', true);
        }
        return $data;
    }

    /**
     * GET /v1/files/{fileUuid}?type={convert} - returns the documented
     * presigned-download descriptor: url, downloadFileName, items[]
     * (name/size/hashValue/hashAlgorithm), dateExpired. $type is one of
     * the documented convert types (stl/obj/ply) or null for original.
     */
    public function getFileDownload(string $fileUuid, ?string $type = 'stl'): array {
        $query = [];
        if ($type !== null) {
            $type = strtolower($type);
            if (!in_array($type, self::CONVERT_TYPES, true)) {
                throw new MeditApiException('invalid_convert_type', 'Unsupported Medit convert type.', false);
            }
            $query['type'] = $type;
        }
        $data = $this->apiGetJson('/v1/files/' . self::encodeUuid($fileUuid), $query, false);
        $url = isset($data['url']) ? trim((string)$data['url']) : '';
        if ($url === '') {
            throw new MeditApiException('unexpected_response', 'Medit file response lacked a download url.', true);
        }
        if (array_key_exists('items', $data) && !is_array($data['items'])) {
            throw new MeditApiException('unexpected_response', 'Medit file items field was not an array.', true);
        }
        return [
            'url'               => $url,
            'downloadFileName'  => isset($data['downloadFileName']) ? substr((string)$data['downloadFileName'], 0, 255) : null,
            'items'             => $data['items'] ?? [],
            'dateExpired'       => $data['dateExpired'] ?? null,
        ];
    }

    /**
     * Stream a presigned download URL to a temp file. NO Authorization
     * header is ever sent - the presigned query string is the only
     * credential and must only reach the vendor's storage host.
     *
     * @return array{tmp_file:string, size:int, content_type:string}
     *         Caller owns the temp file (must unlink it).
     */
    public function downloadPresigned(string $url): array {
        $this->assertDownloadUrl($url);
        $response = $this->dispatch('GET', $url, [], null, true);
        $status = (int)($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new MeditApiException(
                $status === 403 || $status === 404 ? 'content_gone' : 'download_failed',
                'Medit file download failed (HTTP ' . $status . ').',
                $status >= 500 || $status === 429
            );
        }

        $tmpFile = isset($response['body_file']) ? (string)$response['body_file'] : null;
        $size = 0;
        if ($tmpFile !== null && is_file($tmpFile)) {
            $size = (int)filesize($tmpFile);
        } else {
            $body = (string)($response['body'] ?? '');
            $tmpFile = self::writeTempFile($body);
            $size = strlen($body);
        }
        if ($size <= 0) {
            @unlink($tmpFile);
            throw new MeditApiException('download_empty', 'Medit download produced no bytes.', true);
        }
        if ($size > self::MAX_DOWNLOAD_BYTES) {
            @unlink($tmpFile);
            throw new MeditApiException('file_too_large', 'Medit download exceeded the size limit.', false);
        }

        $headers = self::lowerHeaders($response['headers'] ?? []);
        return [
            'tmp_file'     => $tmpFile,
            'size'         => $size,
            'content_type' => isset($headers['content-type']) ? trim((string)$headers['content-type']) : 'application/octet-stream',
        ];
    }

    /**
     * A download URL may only ever be an https URL on vendor-controlled
     * storage (AWS S3 presigned, or a meditlink.com host). This is the
     * "credentials must not be forwarded to arbitrary hosts" control:
     * we send nothing at all, and only to hosts the vendor owns.
     */
    private function assertDownloadUrl(string $url): void {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new MeditApiException('invalid_download_url', 'Medit download URL failed validation.', false);
        }
        $host = strtolower($parts['host']);
        $ok = $host === 'amazonaws.com' || substr($host, -14) === '.amazonaws.com'
            || $host === 'meditlink.com' || substr($host, -13) === '.meditlink.com';
        if (!$ok) {
            throw new MeditApiException('invalid_download_url', 'Medit download URL pointed at an unrecognized host.', false);
        }
    }

    // ------------------------------------------------------------------
    // Request plumbing
    // ------------------------------------------------------------------

    /**
     * GET expecting a JSON object body. $allowNoContent maps the
     * documented 204 ("list could not be found") to null; anything else
     * treats 204 as an unexpected empty body.
     */
    private function apiGetJson(string $path, array $query, bool $allowNoContent): ?array {
        $response = $this->withAuth(function (string $token) use ($path, $query) {
            $r = $this->rawRequest('GET', $path, $query, $token);
            // Status must be evaluated INSIDE the wrapper so a 401 feeds
            // withAutoRefresh (one refresh + one retry, never a loop).
            $this->throwForStatus((int)$r['status'], $r);
            return $r;
        });
        if ($allowNoContent && (int)$response['status'] === 204) {
            return null;
        }
        $data = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($data)) {
            throw new MeditApiException('unexpected_response', 'Medit returned a non-JSON response.', true);
        }
        return $data;
    }

    /** Wrap a transport call in the serialized auto-refresh contract. */
    private function withAuth(callable $fn) {
        return $this->auth->withAutoRefresh($this->pdo, $this->connectionId, $fn);
    }

    /**
     * One GET against the configured resource host carrying the
     * documented headers: Bearer token + x-meditlink-client-id +
     * x-meditlink-group-uuid (the group's tenant binding).
     */
    private function rawRequest(string $method, string $path, array $query, string $accessToken): array {
        $base = MeditConfig::resourceBaseUrl();
        $baseParts = parse_url($base);
        if (empty($baseParts['host']) || (($baseParts['scheme'] ?? '') !== 'https')) {
            throw new MeditApiException('server_config_missing', 'Medit resource host is not configured.', false);
        }

        $this->pace();
        $url = $base . $path . '?' . http_build_query($query);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'x-meditlink-client-id: ' . MeditConfig::clientId(),
            'x-meditlink-group-uuid: ' . $this->groupUuid(),
        ];
        return $this->dispatch($method, $url, $headers, null, false);
    }

    /**
     * Minimum spacing between resource calls - the documented throttles
     * are 5-10 req/s; 4 req/s here stays comfortably inside the budget
     * without needing a shared rate limiter.
     */
    private function pace(): void {
        $now = (int)(microtime(true) * 1000000);
        $wait = self::MIN_CALL_SPACING_US - ($now - $this->lastCallUs);
        if ($this->lastCallUs > 0 && $wait > 0) {
            usleep($wait);
        }
        $this->lastCallUs = (int)(microtime(true) * 1000000);
    }

    private function dispatch(string $method, string $url, array $headers, ?string $body, bool $isDownload): array {
        if ($this->transport !== null) {
            return call_user_func($this->transport, $method, $url, $headers, $body);
        }
        return self::curlGet($url, $headers, $isDownload);
    }

    private static function curlGet(string $url, array $headers, bool $isDownload): array {
        $ch = curl_init($url);
        $responseHeaders = [];
        $tmpFile = null;
        $tmpHandle = null;
        $overflow = false;

        $opts = [
            CURLOPT_CUSTOMREQUEST    => 'GET',
            CURLOPT_RETURNTRANSFER   => !$isDownload,
            CURLOPT_HTTPHEADER       => $headers,
            CURLOPT_CONNECTTIMEOUT   => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT          => $isDownload ? self::DOWNLOAD_TIMEOUT : self::JSON_TIMEOUT,
            CURLOPT_NOSIGNAL         => true,
            CURLOPT_FOLLOWLOCATION   => false, // no redirects on API or S3
            CURLOPT_HEADERFUNCTION   => function ($ch, $line) use (&$responseHeaders) {
                $len = strlen($line);
                $line = trim($line);
                if ($line !== '' && strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $len;
            },
        ];
        if ($isDownload) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'mdl_');
            $tmpHandle = fopen($tmpFile, 'wb');
            if ($tmpHandle === false) {
                throw new MeditApiException('io_error', 'Could not allocate a download buffer file.', true);
            }
            $opts[CURLOPT_FILE] = $tmpHandle;
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use ($tmpHandle, &$overflow) {
                $written = fwrite($tmpHandle, $chunk);
                if (ftell($tmpHandle) > self::MAX_DOWNLOAD_BYTES) {
                    $overflow = true;
                    return 0; // abort the transfer
                }
                return $written === false ? 0 : $written;
            };
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);

        if ($tmpHandle !== null) {
            fclose($tmpHandle);
        }
        if ($overflow) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
            throw new MeditApiException('file_too_large', 'Medit download exceeded the size limit.', false);
        }
        if ($errno !== 0 || $raw === false) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
            throw new MeditApiException('network', 'Medit request failed to complete.', true);
        }

        $out = ['status' => $code, 'headers' => $responseHeaders, 'body' => $isDownload ? '' : (string)$raw];
        if ($isDownload && $code >= 200 && $code < 300 && $tmpFile !== null) {
            $out['body_file'] = $tmpFile;
        } elseif ($tmpFile !== null) {
            @unlink($tmpFile);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Status -> exception mapping (PHI-free categories only)
    // ------------------------------------------------------------------

    private function throwForStatus(int $status, array $response): void {
        switch (true) {
            case $status === 204:
                return; // documented "list could not be found" - caller maps
            case $status >= 200 && $status < 300:
                return;
            case $status === 401:
                // Feeds MeditAuthService::withAutoRefresh.
                throw new MeditAuthException('unauthorized', 'Medit rejected the access token.', true);
            case $status === 403:
                // Permission, not authentication - refreshing cannot help.
                throw new MeditApiException('forbidden', 'Medit denied access to the resource.', false);
            case $status === 404:
                throw new MeditApiException('not_found', 'Medit resource not found.', false);
            case $status === 410:
                throw new MeditApiException('gone', 'Medit resource is gone.', false);
            case $status === 429:
                throw new MeditApiException('rate_limited', 'Medit rate limit reached.', true, self::retryAfter($response));
            case $status >= 500 && $status < 600:
                throw new MeditApiException('http_' . $status, 'Medit is temporarily unavailable.', true);
            default:
                throw new MeditApiException('http_' . $status, 'Medit request failed (HTTP ' . $status . ').', false);
        }
    }

    private static function retryAfter(array $response): ?int {
        $h = self::lowerHeaders($response['headers'] ?? []);
        $raw = isset($h['retry-after']) ? trim((string)$h['retry-after']) : '';
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return max(1, min(3600, (int)$raw));
        }
        $ts = strtotime($raw);
        return $ts === false ? null : max(1, min(3600, $ts - time()));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Uuids arrive as 36-char identifiers from the API. For path use they
     * must still be constrained - a malformed value can never become a
     * path fragment.
     */
    private static function encodeUuid(string $uuid): string {
        $uuid = trim($uuid);
        if ($uuid === '' || strlen($uuid) > 64 || !preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $uuid)) {
            throw new MeditApiException('invalid_resource', 'Invalid Medit resource identifier.', false);
        }
        return rawurlencode($uuid);
    }

    private static function lowerHeaders(array $headers): array {
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                if (strpos((string)$value, ':') !== false) {
                    [$n, $v] = explode(':', (string)$value, 2);
                    $out[strtolower(trim($n))] = trim($v);
                }
            } else {
                $out[strtolower($name)] = is_array($value) ? end($value) : $value;
            }
        }
        return $out;
    }

    private static function writeTempFile(string $bytes): string {
        $tmp = tempnam(sys_get_temp_dir(), 'mdl_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new MeditApiException('io_error', 'Could not buffer the download.', true);
        }
        return $tmp;
    }
}
