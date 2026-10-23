<?php
/**
 * DsCoreClient
 *
 * Read-only HTTP client for the DS Core PMS business APIs
 * ({dscore-base-host} - the REGIONAL host, never the global auth host).
 * Only the endpoints documented in the PMS OpenAPI spec are implemented:
 *
 *   GET /v1beta/digitalImpressions                                    (list)
 *   GET /v1beta/digitalImpressions/{digitalImpression}                (get)
 *   GET /v1beta/digitalImpressions/{digitalImpression}/content        (STL)
 *   GET /v1beta/digitalImpressions/{digitalImpression}/content/{content}/checksum
 *   GET /v1beta/fhir/Patient/{id}                                     (patient)
 *
 * Deliberately NOT implemented (read-only rollout scope): patient create/
 * update, order placement, document upload, webhook registration, blobs.
 * No digital-impression-created webhook exists in the spec - discovery is
 * polling-based (see DsCoreScanSync).
 *
 * AUTHENTICATION: every call goes through
 * DsCoreAuthService::withAutoRefresh() - one reactive 401 -> one forced
 * serialized refresh -> one retry, never a loop. Scheduled refresh stays
 * the worker's job.
 *
 * TRANSPORT SEAM: fn($method, $url, $headers, $body) =>
 *   ['status' => int, 'headers' => ['name' => value, ...], 'body' => string]
 * plus OPTIONAL 'body_file' => path for streamed downloads. Mocks return
 * 'body'; the real transport streams large downloads to a temp file so a
 * 250MB archive never lives in PHP memory.
 *
 * SECURITY:
 *  - URLs are built from the configured base host only; no endpoint URL is
 *    ever taken from API response data.
 *  - Redirects are followed manually, never automatically: same-host
 *    redirects keep the bearer token, cross-host redirects (e.g. a signed
 *    storage URL) are fetched WITHOUT the Authorization header and only
 *    over https. http redirects and >MAX_REDIRECTS hops are rejected.
 *  - Tokens and response bodies are never logged. Error categories are
 *    PHI-free machine strings.
 */

require_once __DIR__ . '/../DsCoreConfig.php';
require_once __DIR__ . '/DsCoreAuthService.php';

class DsCoreApiException extends Exception {
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

class DsCoreClient {

    /** Documented list-page ceiling. */
    public const MAX_PAGE_SIZE = 100;
    public const DEFAULT_PAGE_SIZE = 20;

    /** Redirect hops the manual-follow loop tolerates. */
    private const MAX_REDIRECTS = 2;

    /** Hard byte ceiling for a single download (mirrors ScanFileStore). */
    private const MAX_DOWNLOAD_BYTES = 250 * 1024 * 1024;

    private const CONNECT_TIMEOUT = 10;
    private const JSON_TIMEOUT = 30;
    private const DOWNLOAD_TIMEOUT = 300;

    /** @var PDO */
    private $pdo;
    /** @var int */
    private $connectionId;
    /** @var DsCoreAuthService */
    private $auth;
    /** @var callable|null */
    private $transport;

    public function __construct(PDO $pdo, int $connectionId, ?DsCoreAuthService $auth = null, ?callable $transport = null) {
        $this->pdo = $pdo;
        $this->connectionId = $connectionId;
        $this->auth = $auth ?? new DsCoreAuthService();
        $this->transport = $transport;
    }

    // ------------------------------------------------------------------
    // Digital impressions
    // ------------------------------------------------------------------

    /**
     * GET /v1beta/digitalImpressions?pageSize=&pageToken=&filter=
     * $filter is the documented filter expression (patient.id / create_time
     * comparisons) built by the caller - this method never invents fields.
     */
    public function listDigitalImpressions(int $pageSize, ?string $pageToken, ?string $filter): array {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $query = ['pageSize' => $pageSize];
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        if ($filter !== null && $filter !== '') {
            $query['filter'] = $filter;
        }
        return $this->apiGetJson('/v1beta/digitalImpressions', $query);
    }

    /** GET /v1beta/digitalImpressions/{digitalImpression} */
    public function getDigitalImpression(string $name): array {
        return $this->apiGetJson('/v1beta/' . self::encodeResourceName($name), []);
    }

    /**
     * GET /v1beta/digitalImpressions/{id}/content?fileType=STL
     * Streams the response body to a temp file (bounded at
     * MAX_DOWNLOAD_BYTES) and returns the file path plus the X-Content-Id
     * response header the checksum endpoint requires.
     *
     * @return array{content_id:string, tmp_file:string, size:int,
     *               content_type:string}
     *         Caller owns the temp file (must unlink it).
     */
    public function downloadContent(string $name, string $fileType = 'STL', ?string $orderId = null): array {
        $query = ['fileType' => $fileType];
        if ($orderId !== null && $orderId !== '') {
            $query['orderId'] = $orderId;
        }
        $path = '/v1beta/' . self::encodeResourceName($name) . '/content';

        $response = $this->withAuth(function (string $token) use ($path, $query) {
            $r = $this->rawRequest('GET', $path, $query, $token, true);
            $this->throwForStatus((int)$r['status'], $r);
            return $r;
        });

        $headers = self::lowerHeaders($response['headers'] ?? []);
        $contentId = isset($headers['x-content-id']) ? trim((string)$headers['x-content-id']) : '';
        if ($contentId === '') {
            // Documented contract: content responses carry X-Content-Id -
            // without it the integrity check cannot run.
            throw new DsCoreApiException('missing_content_id', 'DS Core content response lacked X-Content-Id.', true);
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
            throw new DsCoreApiException('empty_content', 'DS Core returned an empty content stream.', true);
        }
        if ($size > self::MAX_DOWNLOAD_BYTES) {
            @unlink($tmpFile);
            throw new DsCoreApiException('file_too_large', 'DS Core content exceeded the size limit.', false);
        }

        return [
            'content_id'   => $contentId,
            'tmp_file'     => $tmpFile,
            'size'         => $size,
            'content_type' => isset($headers['content-type']) ? trim((string)$headers['content-type']) : 'application/octet-stream',
        ];
    }

    /**
     * GET /v1beta/digitalImpressions/{id}/content/{content}/checksum
     * Returns the documented md5Checksum as a lowercase hex string.
     */
    public function getContentChecksum(string $name, string $contentId): string {
        $path = '/v1beta/' . self::encodeResourceName($name)
            . '/content/' . rawurlencode($contentId) . '/checksum';
        $data = $this->apiGetJson($path, []);
        $md5 = isset($data['md5Checksum']) ? strtolower(trim((string)$data['md5Checksum'])) : '';
        if ($md5 === '' || !preg_match('/^[0-9a-f]{32}$/', $md5)) {
            throw new DsCoreApiException('unexpected_response', 'DS Core checksum response was malformed.', true);
        }
        return $md5;
    }

    /**
     * GET /v1beta/fhir/Patient/{id}
     * Used ONLY when a digital impression references a patient but omits
     * name fields. Returns the decoded resource (caller tolerates both the
     * documented flat schema and FHIR-style name/birthDate shapes).
     */
    public function getPatient(string $id): array {
        if ($id === '' || !preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $id)) {
            throw new DsCoreApiException('invalid_patient_ref', 'Invalid patient identifier.', false);
        }
        return $this->apiGetJson('/v1beta/fhir/Patient/' . rawurlencode($id), []);
    }

    // ------------------------------------------------------------------
    // Request plumbing
    // ------------------------------------------------------------------

    /**
     * GET expecting a JSON object body. Runs inside withAutoRefresh.
     */
    private function apiGetJson(string $path, array $query): array {
        $response = $this->withAuth(function (string $token) use ($path, $query) {
            $r = $this->rawRequest('GET', $path, $query, $token, false);
            // Status must be evaluated INSIDE the wrapper so a 401 feeds
            // withAutoRefresh (one refresh + one retry, never a loop).
            $this->throwForStatus((int)$r['status'], $r);
            return $r;
        });
        $data = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($data)) {
            throw new DsCoreApiException('unexpected_response', 'DS Core returned a non-JSON response.', true);
        }
        return $data;
    }

    /** Wrap a transport call in the serialized auto-refresh contract. */
    private function withAuth(callable $fn) {
        try {
            return $this->auth->withAutoRefresh($this->pdo, $this->connectionId, $fn);
        } catch (DsCoreAuthException $e) {
            throw $e;
        } catch (DsCoreApiException $e) {
            throw $e;
        }
    }

    /**
     * One GET against the configured business host, with the manual
     * redirect policy. $isDownload streams the body to a temp file in the
     * real transport. Never leaks the bearer token to a foreign host.
     */
    private function rawRequest(string $method, string $path, array $query, string $accessToken, bool $isDownload): array {
        $base = DsCoreConfig::businessBaseUrl();
        $baseParts = parse_url($base);
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));
        if ($baseHost === '' || (($baseParts['scheme'] ?? '') !== 'https')) {
            throw new DsCoreApiException('server_config_missing', 'DS Core business host is not configured.', false);
        }

        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
        $sendAuth = true;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $headers = ['Accept: application/json'];
            if ($sendAuth) {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }
            $response = $this->dispatch($method, $url, $headers, null, $isDownload);
            $status = (int)($response['status'] ?? 0);

            if ($status >= 300 && $status < 400) {
                $h = self::lowerHeaders($response['headers'] ?? []);
                $location = isset($h['location']) ? trim((string)$h['location']) : '';
                if ($location === '' || $hop === self::MAX_REDIRECTS) {
                    throw new DsCoreApiException('redirect_rejected', 'DS Core returned an unusable redirect.', true);
                }
                $next = $this->resolveRedirect($base, $url, $location);
                if ($next === null) {
                    throw new DsCoreApiException('redirect_rejected', 'DS Core redirect target failed validation.', false);
                }
                // Cross-host hop (e.g. a signed storage URL): never forward
                // the bearer token to a non-DS-Core host.
                $nextHost = strtolower((string)(parse_url($next, PHP_URL_HOST) ?? ''));
                $sendAuth = ($nextHost === $baseHost);
                $url = $next;
                continue;
            }
            return $response;
        }
        // Unreachable (loop throws on last hop) - kept for clarity.
        throw new DsCoreApiException('redirect_rejected', 'DS Core redirect chain exceeded the limit.', true);
    }

    /**
     * Validate a redirect Location. Returns an absolute https URL or null.
     * Rules: relative references resolve against the request URL; absolute
     * targets must be https and must be either the configured base host or
     * a subdomain of dscore.com / dscore.com.cn (vendor-controlled CDN).
     */
    private function resolveRedirect(string $base, string $currentUrl, string $location): ?string {
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $location)) {
            $absolute = $location;
        } else {
            $baseParts = parse_url($base);
            if ($location === '' || $location[0] === '#') {
                return null;
            }
            $absolute = ($baseParts['scheme'] ?? 'https') . '://' . ($baseParts['host'] ?? '')
                . ($location[0] === '/' ? $location : '/' . $location);
        }
        $parts = parse_url($absolute);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $baseHost = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
        if ($host === $baseHost) {
            return $absolute;
        }
        // Vendor-owned hosts only - the token is stripped for these, but a
        // redirect to an arbitrary host is still rejected outright.
        if ($host === 'dscore.com' || substr($host, -12) === '.dscore.com'
            || $host === 'dscore.com.cn' || substr($host, -15) === '.dscore.com.cn') {
            return $absolute;
        }
        return null;
    }

    /**
     * Transport dispatch. The injectable seam mirrors DsCoreAuthService;
     * the real transport streams download bodies to a temp file and
     * captures response headers (the auth transport drops them).
     */
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
            CURLOPT_FOLLOWLOCATION   => false, // manual redirect policy
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
            $tmpFile = tempnam(sys_get_temp_dir(), 'dsc_');
            $tmpHandle = fopen($tmpFile, 'wb');
            if ($tmpHandle === false) {
                throw new DsCoreApiException('io_error', 'Could not allocate a download buffer file.', true);
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
            throw new DsCoreApiException('file_too_large', 'DS Core content exceeded the size limit.', false);
        }
        if ($errno !== 0 || $raw === false) {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
            throw new DsCoreApiException('network', 'DS Core request failed to complete.', true);
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
            case $status >= 200 && $status < 300:
                return;
            case $status === 401:
                // Feeds DsCoreAuthService::withAutoRefresh (one refresh +
                // one retry). A second 401 propagates from there.
                throw new DsCoreAuthException('unauthorized', 'DS Core rejected the access token.', true);
            case $status === 403:
                // Permission, not authentication - refreshing cannot help.
                throw new DsCoreApiException('forbidden', 'DS Core denied access to the resource.', false);
            case $status === 404:
                throw new DsCoreApiException('not_found', 'DS Core resource not found.', false);
            case $status === 410:
                throw new DsCoreApiException('gone', 'DS Core resource is gone.', false);
            case $status === 429:
                throw new DsCoreApiException('rate_limited', 'DS Core rate limit reached.', true, self::retryAfter($response));
            case $status >= 500 && $status < 600:
                throw new DsCoreApiException('http_' . $status, 'DS Core is temporarily unavailable.', true);
            default:
                throw new DsCoreApiException('http_' . $status, 'DS Core request failed (HTTP ' . $status . ').', false);
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
     * Resource names arrive as "digitalImpressions/dii-abc123". The URL
     * path embeds the full resource name; each segment is encoded.
     */
    private static function encodeResourceName(string $name): string {
        $name = trim($name);
        if ($name === '' || strlen($name) > 256) {
            throw new DsCoreApiException('invalid_resource', 'Invalid digital impression identifier.', false);
        }
        $segments = explode('/', $name);
        return implode('/', array_map('rawurlencode', $segments));
    }

    private static function lowerHeaders(array $headers): array {
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                // Header lines (defensive tolerance for simple mocks).
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
        $tmp = tempnam(sys_get_temp_dir(), 'dsc_');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) {
            throw new DsCoreApiException('io_error', 'Could not buffer the download.', true);
        }
        return $tmp;
    }
}
