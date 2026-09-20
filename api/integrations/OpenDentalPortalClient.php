<?php
/**
 * OpenDentalPortalClient
 *
 * Client for the Open Dental DEVELOPER PORTAL API - a small, documented
 * surface that is deliberately separate from the per-practice Open Dental
 * API (OpenDentalAdapter). Verified against the current official docs
 * (opendental.com/site/apideveloperportal.html):
 *
 *   Auth:  Authorization: ODFHIR {DeveloperKey}/{DeveloperPortalKey}
 *   GET    /apikeys   -> list all CustomerKeys on the developer account
 *   POST   /apikeys   -> create + return a new CustomerKey (201)
 *   PUT    /apikeys   -> update {CustomerKey, KeyStatus?, DevRefId?}
 *                        KeyStatus: "Enabled" | "DisabledByDeveloper"
 *
 * Used for exactly one purpose: managing the lifecycle of the customer
 * keys DentaTrak hands to practices during onboarding (generate, re-key,
 * disable/re-enable on disconnect/reconnect). No other portal functions.
 *
 * SECURITY:
 *  - Both secrets are DentaTrak-central (OpenDentalConfig/env) - never
 *    stored per practice, never in integration_credentials, never logged,
 *    never returned, never in URLs.
 *  - Responses are echoed back to callers verbatim (they contain only key
 *    metadata); callers must NOT log them since CustomerKey is a secret.
 *  - Exceptions reuse OpenDentalApiException: safe categories, HTTP code,
 *    endpoint path - never response bodies or secret material.
 */

require_once __DIR__ . '/OpenDentalConfig.php';
require_once __DIR__ . '/adapters/OpenDentalAdapter.php'; // OpenDentalApiException

class OpenDentalPortalClient {

    const BASE_URL        = 'https://api.opendental.com/api/v1';
    const CONNECT_TIMEOUT = 10;
    const REQUEST_TIMEOUT = 30;

    const STATUS_ENABLED               = 'Enabled';
    const STATUS_DISABLED_BY_DEVELOPER = 'DisabledByDeveloper';

    /** @var callable|null Transport override for tests (same shape as adapter). */
    private $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport;
    }

    /**
     * POST /apikeys - create a new CustomerKey on the DentaTrak developer
     * account. Returns the decoded key record (CustomerKey, KeyStatus,
     * DateCreated, ...). The CustomerKey value is a secret.
     * @throws OpenDentalApiException
     */
    public function createCustomerKey(): array {
        $result = $this->request('POST', '/apikeys');
        if (!is_array($result) || empty($result['CustomerKey']) || !is_string($result['CustomerKey'])) {
            throw new OpenDentalApiException('unexpected_response', 0, '/apikeys',
                'Portal did not return a customer key.');
        }
        return $result;
    }

    /**
     * GET /apikeys - list all CustomerKeys on the developer account.
     * @throws OpenDentalApiException
     */
    public function listCustomerKeys(): array {
        $result = $this->request('GET', '/apikeys');
        return is_array($result) ? $result : [];
    }

    /**
     * PUT /apikeys - update an existing CustomerKey's status and/or the
     * developer-side reference label (portal display only).
     *
     * @param string      $customerKey The key to update.
     * @param string|null $keyStatus   STATUS_ENABLED | STATUS_DISABLED_BY_DEVELOPER
     * @param string|null $devRefId    Developer reference, shown only in our portal.
     * @throws OpenDentalApiException
     */
    public function updateCustomerKey(string $customerKey, ?string $keyStatus = null, ?string $devRefId = null): array {
        $body = ['CustomerKey' => $customerKey];
        if ($keyStatus !== null) {
            $body['KeyStatus'] = $keyStatus;
        }
        if ($devRefId !== null) {
            $body['DevRefId'] = $devRefId;
        }
        $result = $this->request('PUT', '/apikeys', $body);
        return is_array($result) ? $result : [];
    }

    /** PUT /apikeys {KeyStatus: DisabledByDeveloper} - retire a key. */
    public function disableCustomerKey(string $customerKey): array {
        return $this->updateCustomerKey($customerKey, self::STATUS_DISABLED_BY_DEVELOPER);
    }

    /** PUT /apikeys {KeyStatus: Enabled} - re-activate a key. */
    public function enableCustomerKey(string $customerKey): array {
        return $this->updateCustomerKey($customerKey, self::STATUS_ENABLED);
    }

    // ----------------------------------------------------------------------
    // Transport
    // ----------------------------------------------------------------------

    /**
     * Base URL for portal requests. OPEN_DENTAL_PORTAL_BASE_URL env overrides
     * the default (automated tests point it at a local stub); it is never
     * taken from request input.
     */
    private function baseUrl(): string {
        $env = getenv('OPEN_DENTAL_PORTAL_BASE_URL');
        return (is_string($env) && $env !== '') ? rtrim($env, '/') : self::BASE_URL;
    }

    /**
     * Authorization header: ODFHIR {DeveloperKey}/{DeveloperPortalKey}.
     * The returned string is a secret - never log it.
     * @throws OpenDentalApiException category=server_config_missing
     */
    private function buildAuthHeader(): string {
        $dev = OpenDentalConfig::developerKey();
        $portal = OpenDentalConfig::developerPortalKey();
        if ($dev === null || $portal === null) {
            throw new OpenDentalApiException('server_config_missing', 0, 'auth',
                'The Open Dental integration is not configured on this server.');
        }
        return 'ODFHIR ' . $dev . '/' . $portal;
    }

    /**
     * @throws OpenDentalApiException on any failure (always secret-free)
     */
    private function request(string $method, string $path, ?array $jsonBody = null) {
        $url = $this->baseUrl() . $path;
        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->buildAuthHeader(),
        ];
        // Open Dental's API rejects write methods with no Content-Length
        // (HTTP 411). The documented POST /apikeys takes no parameters, so
        // send an empty JSON object rather than a null body.
        $bodyOut = $jsonBody !== null ? json_encode($jsonBody)
            : (in_array($method, ['POST', 'PUT'], true) ? '{}' : null);

        if ($this->transport !== null) {
            $result = call_user_func($this->transport, $method, $url, $headers, $bodyOut);
        } else {
            $result = $this->curlRequest($method, $url, $headers, $bodyOut);
        }

        $code = (int)($result['code'] ?? 0);
        $body = (string)($result['body'] ?? '');

        if ($code === 0) {
            throw new OpenDentalApiException('network', 0, $path, 'Could not reach the Open Dental service.');
        }
        if ($code === 401) {
            throw new OpenDentalApiException('auth', 401, $path,
                'Open Dental rejected the configured developer credentials.');
        }
        if ($code === 404) {
            throw new OpenDentalApiException('not_found', 404, $path);
        }
        if ($code === 400) {
            throw new OpenDentalApiException('bad_request', 400, $path);
        }
        if ($code === 429) {
            throw new OpenDentalApiException('rate_limited', 429, $path, 'Open Dental rate limit reached.');
        }
        if ($code >= 500) {
            throw new OpenDentalApiException('server_error', $code, $path);
        }
        if ($code < 200 || $code >= 300) {
            throw new OpenDentalApiException('unexpected_response', $code, $path);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && trim($body) !== '' && trim($body) !== 'null') {
            throw new OpenDentalApiException('unexpected_response', $code, $path,
                'Open Dental returned a malformed response.');
        }
        return $decoded;
    }

    /** Real cURL transport - same result shape as the adapter's. */
    private function curlRequest(string $method, string $url, array $headers, ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errNo !== 0) {
            // Deliberately no curl_error() text - uniform generic failure.
            return ['code' => 0, 'body' => '', 'headers' => []];
        }
        return ['code' => $code, 'body' => $raw, 'headers' => []];
    }
}
