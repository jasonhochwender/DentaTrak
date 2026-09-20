<?php
/**
 * OpenDentalAdapter
 *
 * Open Dental implementation of PmsAdapterInterface. All Open Dental-specific
 * knowledge (base URL, ODFHIR auth scheme, endpoint paths, record shape,
 * sentinel dates, zero-FK convention) lives in this class - nothing
 * provider-specific leaks into IntegrationManager or SyncEngine.
 *
 * Verified against the current official docs (opendental.com/site/api*.html):
 *   - Base URL:   https://api.opendental.com/api/v1
 *   - Auth:       Authorization: ODFHIR {DeveloperKey}/{CustomerKey}
 *   - List pages: Limit/Offset params, hard 100-item cap per request
 *   - Throttle:   ApiReadAll-only keys ~1 request/5s per CustomerKey;
 *                 429 responses carry a Retry-After header
 *   - Statuses:   200/201/400/401/404/410/429 (+504 gateway timeout)
 *   - Sentinels:  "0001-01-01 ..." and "2000-01-01 ..." mean "not set";
 *                 0 means "no FK" on ID columns (AptNum, PlannedAptNum, ...)
 *
 * SECURITY:
 *   - Credentials arrive already-decrypted and are used ONLY to build the
 *     Authorization header in memory. They are never logged, never placed
 *     in exceptions, never returned, and never added to URLs.
 *   - The Developer Key is DentaTrak's central server secret
 *     (OpenDentalConfig/env), NOT a per-practice credential; only the
 *     practice's Customer Key is decrypted from integration_credentials.
 *   - Response bodies may contain PHI and are NEVER logged or included in
 *     exception messages. Errors carry only an HTTP code, a safe category,
 *     and the resource path.
 *   - Patient retrieval is data-minimized: only the fields a future case
 *     import needs are extracted (names, birthdate, gender) - SSN, address,
 *     balances, insurance, phones, and email are discarded in memory.
 *
 * PHASE C/D SCOPE: reads plus subscription lifecycle only. POST/PUT exist
 * exclusively for /subscriptions (Events POC); NO LabCase or other record
 * writes are implemented.
 */

require_once __DIR__ . '/../PmsAdapterInterface.php';
require_once __DIR__ . '/../CanonicalCase.php';
require_once __DIR__ . '/../OpenDentalConfig.php';

/**
 * Safe transport exception. Message/category contain no credentials and no
 * response body - safe to surface to admins and to store in last_error.
 */
class OpenDentalApiException extends RuntimeException {
    /** @var string Safe category: network|auth|rate_limited|not_found|bad_request|gone|timeout|server_error|unexpected_response|credentials_missing|econnector_offline */
    public string $category;
    public int $httpCode;
    public string $endpoint;
    /** @var int|null Documented Retry-After seconds on 429 responses, when present. */
    public ?int $retryAfterSeconds = null;

    public function __construct(string $category, int $httpCode, string $endpoint, string $safeDetail = '') {
        $this->category = $category;
        $this->httpCode = $httpCode;
        $this->endpoint = $endpoint;
        $message = "Open Dental request failed ({$category}) on {$endpoint}"
            . ($httpCode > 0 ? " HTTP {$httpCode}" : '')
            . ($safeDetail !== '' ? ": {$safeDetail}" : '');
        parent::__construct($message);
    }
}

class OpenDentalAdapter implements PmsAdapterInterface {

    const BASE_URL        = 'https://api.opendental.com/api/v1';
    const CONNECT_TIMEOUT = 10;
    // Open Dental's own gateway limit is 60s; stay comfortably under it.
    const REQUEST_TIMEOUT = 45;
    // Documented hard cap for list endpoints.
    const PAGE_LIMIT      = 100;

    // Open Dental "not set" DateTime sentinels (per official examples).
    const SENTINEL_DATES = ['0001-01-01', '2000-01-01'];

    /**
     * @var callable|null Transport override for tests:
     *   fn(string $method, string $url, array $headers): array{code:int, body:string, headers:array}
     * When null, real cURL is used.
     */
    private $transport;

    public function __construct(?callable $transport = null) {
        $this->transport = $transport;
    }

    public function getProvider(): string {
        return 'open_dental';
    }

    // ----------------------------------------------------------------------
    // Authentication
    // ----------------------------------------------------------------------

    /**
     * Build the ODFHIR Authorization header value. The returned string is a
     * secret - callers must never log or return it.
     *
     * The Developer Key is DentaTrak's central server secret
     * (OPEN_DENTAL_DEVELOPER_KEY via OpenDentalConfig) - it is never stored
     * per practice. Only the practice-specific Customer Key comes from the
     * encrypted credential store. A stored legacy developer_key, if any
     * remains, is ignored by design.
     *
     * @throws OpenDentalApiException
     *   category=server_config_missing - central developer key absent
     *   category=credentials_missing   - practice customer key absent
     */
    private function buildAuthHeader(array $credentials): string {
        $dev = OpenDentalConfig::developerKey();
        if ($dev === null) {
            throw new OpenDentalApiException('server_config_missing', 0, 'auth',
                'The Open Dental integration is not configured on this server.');
        }
        $cust = $credentials['customer_key'] ?? '';
        if (!is_string($cust) || $cust === '') {
            throw new OpenDentalApiException('credentials_missing', 0, 'auth',
                'Required integration credential is not configured.');
        }
        return 'ODFHIR ' . $dev . '/' . $cust;
    }

    // ----------------------------------------------------------------------
    // HTTP transport
    // ----------------------------------------------------------------------

    /**
     * Perform a GET against the Open Dental API and return the decoded JSON.
     * @throws OpenDentalApiException on any failure (always PHI-free)
     */
    private function get(string $path, array $credentials, array $params = []) {
        return $this->request('GET', $path, $credentials, $params);
    }

    /**
     * Perform a JSON request against the Open Dental API and return the
     * decoded body. POST/PUT exist ONLY for subscription lifecycle
     * management (Events POC) - no LabCase writes are implemented.
     * @throws OpenDentalApiException on any failure (always PHI-free)
     */
    private function request(string $method, string $path, array $credentials, array $params = [], ?array $jsonBody = null) {
        $url = $this->baseUrl() . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $authHeader = $this->buildAuthHeader($credentials);
        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $authHeader,
        ];
        $bodyOut = $jsonBody !== null ? json_encode($jsonBody) : null;

        if ($this->transport !== null) {
            $result = call_user_func($this->transport, $method, $url, $headers, $bodyOut);
        } else {
            $result = $this->curlRequest($method, $url, $headers, $bodyOut);
        }

        $code = (int)($result['code'] ?? 0);
        $body = (string)($result['body'] ?? '');

        if ($code === 0) {
            throw new OpenDentalApiException('network', 0, $path, 'Could not reach the Open Dental API.');
        }
        if ($code === 401) {
            throw new OpenDentalApiException('auth', 401, $path,
                'Open Dental rejected the configured credentials or API permission.');
        }
        if ($code === 404) {
            throw new OpenDentalApiException('not_found', 404, $path);
        }
        if ($code === 429) {
            $retryAfter = $result['headers']['retry-after'] ?? $result['headers']['Retry-After'] ?? null;
            $hasRetry = is_scalar($retryAfter) && ctype_digit((string)$retryAfter);
            $e = new OpenDentalApiException('rate_limited', 429, $path,
                'Open Dental rate limit reached.' . ($hasRetry ? ' Retry after ' . (int)$retryAfter . 's.' : ''));
            if ($hasRetry) {
                $e->retryAfterSeconds = (int)$retryAfter;
            }
            throw $e;
        }
        if ($code === 400) {
            // Open Dental returns a bare JSON *string* for request-level
            // failures. Two documented operational states are worth distinct
            // categories so the UI can give targeted guidance:
            //   "The office's eConnector is not running..."  -> office offline
            //   "API key has not been assigned."             -> key not installed
            $odMessage = json_decode($body, true);
            if (is_string($odMessage)) {
                if (stripos($odMessage, 'eConnector') !== false) {
                    throw new OpenDentalApiException('econnector_offline', 400, $path,
                        'Open Dental reports the office eConnector is not running.');
                }
                if (stripos($odMessage, 'not been assigned') !== false) {
                    throw new OpenDentalApiException('credentials_missing', 400, $path,
                        'Open Dental reports the customer key is not assigned in the office.');
                }
            }
            throw new OpenDentalApiException('bad_request', 400, $path);
        }
        if ($code === 410) {
            throw new OpenDentalApiException('gone', 410, $path);
        }
        if ($code === 504 || $code === 502 || $code === 503) {
            throw new OpenDentalApiException('timeout', $code, $path);
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

    /**
     * Base URL for requests. OPEN_DENTAL_BASE_URL env overrides the default
     * (used by automated tests to target a local stub and for future
     * sandbox/staging routing); it is never taken from request input.
     */
    private function baseUrl(): string {
        $env = getenv('OPEN_DENTAL_BASE_URL');
        return (is_string($env) && $env !== '') ? rtrim($env, '/') : self::BASE_URL;
    }

    /**
     * Real cURL transport. Returns the same shape the test transport does.
     */
    private function curlRequest(string $method, string $url, array $headers, ?string $body = null): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HEADER         => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false || $errNo !== 0) {
            // Deliberately no curl_error() text - it can embed the request
            // URL, and we keep transport errors uniformly generic.
            return ['code' => 0, 'body' => '', 'headers' => []];
        }

        $headerBlock = substr($raw, 0, $headerSize);
        $headersOut = [];
        foreach (explode("\n", $headerBlock) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headersOut[strtolower(trim($k))] = trim($v);
            }
        }
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => $headersOut];
    }

    // ----------------------------------------------------------------------
    // PmsAdapterInterface
    // ----------------------------------------------------------------------

    /**
     * Least-invasive verification: GET /clinics returns a tiny payload (and
     * an empty array when the office does not use clinics), which proves the
     * credentials authenticate and the customer database responds. When a
     * clinic exists, its Description doubles as a human-meaningful
     * external_account_id hint for the connected office.
     */
    public function testConnection(array $credentials, array $config): array {
        try {
            $clinics = $this->get('/clinics', $credentials);
        } catch (OpenDentalApiException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                // Safe machine-readable category so the UI can differentiate
                // guidance (key not installed vs rejected vs eConnector down
                // vs DentaTrak server config) without parsing message text.
                'error_code' => $e->category,
                'external_account_id' => null,
            ];
        }

        $externalAccountId = null;
        if (is_array($clinics) && isset($clinics[0]) && is_array($clinics[0])) {
            $first = $clinics[0];
            $externalAccountId = $first['Description'] ?? ($first['Abbr'] ?? null);
        }

        return [
            'success' => true,
            'message' => 'Open Dental connection verified.',
            'external_account_id' => is_string($externalAccountId) && $externalAccountId !== '' ? $externalAccountId : null,
        ];
    }

    /**
     * Fetch lab cases via documented Offset pagination.
     *
     * INCREMENTAL-SYNC LIMITATION (verified against current docs): the
     * LabCases multiple-GET accepts only PatNum, LaboratoryNum, AptNum,
     * PlannedAptNum and ProvNum - there is NO DateTStamp query parameter.
     * This method therefore pages through the full list and applies the
     * $cursor watermark as a CLIENT-SIDE DateTStamp filter. The documented
     * alternatives for Phase D are the API Events/Subscriptions webhook
     * (WatchTable: LabCase) or full enumeration + local comparison; neither
     * is implemented here.
     */
    public function fetchCases(array $credentials, array $config, ?string $cursor, int $limit): array {
        $cases = [];
        $offset = 0;
        $hasMore = false;
        $maxTStamp = $cursor;

        while (count($cases) < $limit) {
            $page = $this->get('/labcases', $credentials, [
                'Offset' => $offset,
                'Limit'  => self::PAGE_LIMIT,
            ]);
            if (!is_array($page)) {
                throw new OpenDentalApiException('unexpected_response', 200, '/labcases',
                    'Open Dental returned a malformed response.');
            }
            $pageCount = count($page);
            if ($pageCount === 0) {
                break;
            }

            foreach ($page as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $tstamp = $raw['DateTStamp'] ?? null;
                if ($cursor !== null && is_string($tstamp) && $tstamp !== ''
                    && strcmp($tstamp, $cursor) <= 0) {
                    continue; // already synced at or before the watermark
                }
                if (is_string($tstamp) && $tstamp !== ''
                    && ($maxTStamp === null || strcmp($tstamp, $maxTStamp) > 0)) {
                    $maxTStamp = $tstamp;
                }
                $cases[] = $this->normalizeCase($raw);
                if (count($cases) >= $limit) {
                    break;
                }
            }

            $offset += $pageCount;
            if ($pageCount < self::PAGE_LIMIT) {
                $hasMore = false;
                break;
            }
            $hasMore = true; // full page - there may be more rows
            if (count($cases) >= $limit) {
                break;
            }
        }

        return [
            'cases'       => $cases,
            'next_cursor' => $maxTStamp,
            'has_more'    => $hasMore,
        ];
    }

    /**
     * Normalize an Open Dental LabCase record into a CanonicalCase.
     *
     * Accepts either a bare labcase row, or a composite array:
     *   ['labcase' => row, 'patient' => ?row, 'provider' => ?row,
     *    'laboratory' => ?row, 'appointment' => ?row]
     * Supporting rows are optional; absent ones simply leave canonical
     * fields null. Never throws on missing optional data.
     *
     * NOTE: LabCases exposes no case-type field, and we deliberately do NOT
     * parse Instructions to guess one - sourceCaseType stays null.
     */
    public function normalizeCase(array $rawRecord): CanonicalCase {
        $labcase = $rawRecord;
        $patient = $provider = $laboratory = $appointment = null;
        if (isset($rawRecord['labcase']) && is_array($rawRecord['labcase'])) {
            $labcase     = $rawRecord['labcase'];
            $patient     = $rawRecord['patient']     ?? null;
            $provider    = $rawRecord['provider']    ?? null;
            $laboratory  = $rawRecord['laboratory']  ?? null;
            $appointment = $rawRecord['appointment'] ?? null;
        }

        $aptNum        = self::nonZeroId($labcase['AptNum'] ?? null);
        $plannedAptNum = self::nonZeroId($labcase['PlannedAptNum'] ?? null);

        $providerName = null;
        if (is_array($provider)) {
            $providerName = trim(
                trim((string)($provider['FName'] ?? '') . ' ' . (string)($provider['LName'] ?? ''))
            );
            if ($providerName === '') {
                $providerName = $provider['Abbr'] ?? null;
            }
        }

        $patientFirst = is_array($patient) ? self::blankToNull($patient['FName'] ?? null) : null;
        $patientLast  = is_array($patient) ? self::blankToNull($patient['LName'] ?? null) : null;

        return new CanonicalCase([
            'externalCaseId'        => self::nonZeroId($labcase['LabCaseNum'] ?? null),
            'externalPatientId'     => self::nonZeroId($labcase['PatNum'] ?? null),
            'externalProviderId'    => self::nonZeroId($labcase['ProvNum'] ?? null),
            'externalLaboratoryId'  => self::nonZeroId($labcase['LaboratoryNum'] ?? null),
            // AptNum (scheduled) takes precedence over PlannedAptNum (planned).
            'externalAppointmentId' => $aptNum ?? $plannedAptNum,
            'patientFirstName'      => $patientFirst,
            'patientLastName'       => $patientLast,
            'patientDob'            => is_array($patient) ? self::odDate($patient['Birthdate'] ?? null) : null,
            'patientGender'         => is_array($patient) ? self::blankToNull($patient['Gender'] ?? null) : null,
            'providerName'          => $providerName !== '' ? $providerName : null,
            'laboratoryName'        => is_array($laboratory) ? self::blankToNull($laboratory['Description'] ?? null) : null,
            'dueDate'               => self::odDate($labcase['DateTimeDue'] ?? null),
            'appointmentDate'       => is_array($appointment) ? self::odDate($appointment['AptDateTime'] ?? null) : null,
            'instructions'          => self::blankToNull($labcase['Instructions'] ?? null),
            'sourceCaseType'        => null, // LabCases exposes no case type
            'metadata'              => [
                'date_tstamp'      => self::odDate($labcase['DateTStamp'] ?? null),
                'date_time_created'=> self::odDate($labcase['DateTimeCreated'] ?? null),
                'date_time_sent'   => self::odDate($labcase['DateTimeSent'] ?? null),
                'date_time_recd'   => self::odDate($labcase['DateTimeRecd'] ?? null),
                'date_time_checked'=> self::odDate($labcase['DateTimeChecked'] ?? null),
                // Both appointment references are preserved - AptNum as the
                // scheduled link, PlannedAptNum when the lab case was raised
                // from the planned-appointment queue instead.
                'apt_num'          => $aptNum,
                'planned_apt_num'  => $plannedAptNum,
                'invoice_num'      => self::blankToNull($labcase['InvoiceNum'] ?? null),
            ],
        ]);
    }

    // ----------------------------------------------------------------------
    // Read-only resource helpers (used by the diagnostic tool and Phase D)
    // ----------------------------------------------------------------------

    /** GET /labcases/{LabCaseNum} - null when the record does not exist. */
    public function getLabCase(array $credentials, int $labCaseNum): ?array {
        return $this->getOrNull("/labcases/{$labCaseNum}", $credentials);
    }

    /** GET /labcases (single page, documented filters only). */
    public function listLabCases(array $credentials, array $filters = [], int $offset = 0): array {
        $allowed = ['PatNum', 'LaboratoryNum', 'AptNum', 'PlannedAptNum', 'ProvNum'];
        $params = array_intersect_key($filters, array_flip($allowed));
        $params['Offset'] = $offset;
        $page = $this->get('/labcases', $credentials, $params);
        return is_array($page) ? $page : [];
    }

    /**
     * GET /patients/{PatNum} - DATA-MINIMIZED. Returns only the fields a
     * case import plausibly needs; the rest of the (PHI-heavy) record is
     * discarded in memory and never stored or logged.
     */
    public function getPatient(array $credentials, int $patNum): ?array {
        $raw = $this->getOrNull("/patients/{$patNum}", $credentials);
        if (!is_array($raw)) {
            return null;
        }
        return [
            'PatNum'    => $raw['PatNum'] ?? null,
            'FName'     => $raw['FName'] ?? null,
            'LName'     => $raw['LName'] ?? null,
            'Birthdate' => $raw['Birthdate'] ?? null,
            'Gender'    => $raw['Gender'] ?? null,
        ];
    }

    /** GET /providers/{ProvNum} */
    public function getProviderByNum(array $credentials, int $provNum): ?array {
        return $this->getOrNull("/providers/{$provNum}", $credentials);
    }

    /** GET /laboratories/{LaboratoryNum} */
    public function getLaboratory(array $credentials, int $laboratoryNum): ?array {
        return $this->getOrNull("/laboratories/{$laboratoryNum}", $credentials);
    }

    /** GET /appointments/{AptNum} - only fields needed for case context. */
    public function getAppointment(array $credentials, int $aptNum): ?array {
        $raw = $this->getOrNull("/appointments/{$aptNum}", $credentials);
        if (!is_array($raw)) {
            return null;
        }
        return [
            'AptNum'      => $raw['AptNum'] ?? null,
            'AptDateTime' => $raw['AptDateTime'] ?? null,
            'AptStatus'   => $raw['AptStatus'] ?? null,
        ];
    }

    /** GET /clinics - used by testConnection; empty when clinics are unused. */
    public function getClinics(array $credentials): array {
        $r = $this->get('/clinics', $credentials);
        return is_array($r) ? $r : [];
    }

    // ----------------------------------------------------------------------
    // API Events / Subscriptions (WatchTable: LabCase webhook lifecycle)
    //
    // Verified against the current official docs (apisubscriptions.html,
    // apievents.html, apiguideevents.html):
    //   - Database Events are PUSH webhooks: an OpenDental.exe workstation or
    //     OpenDentalAPIService.exe at the practice POSTs batches of FULL rows
    //     (up to 1000 per call) to Subscription.EndPointUrl every
    //     PollingSeconds, whenever rows changed since DateTimeStart.
    //   - The webhook Authorization header carries the CUSTOMER API KEY
    //     (shared-secret validation - Open Dental provides no HMAC).
    //   - Event-Type header is "WatchTable: LabCase" (or LabCaseDeleted).
    //   - Successful delivery advances DateTimeStart; failed delivery leaves
    //     it unchanged and the next successful poll resends up to 3 days of
    //     changes - so deliveries are at-least-once and MUST be deduplicated.
    //   - Events carry no event id/sequence. Dedup derives from the row's
    //     identity + DateTStamp watermark (see IntegrationEvents).
    //   - Subscriptions GET/POST/PUT are under the FREE ApiReadAll tier.
    //     Subscriptions DELETE is NOT in that tier (falls under paid
    //     "All Others"), so disableSubscription() expires via PUT
    //     DateTimeStop instead of deleting.
    //   - LabCase WatchTable requires OD v25.4.14+; LabCaseDeleted v26.1.6+.
    // ----------------------------------------------------------------------

    const WATCH_TABLE_LABCASE         = 'LabCase';
    const WATCH_TABLE_LABCASE_DELETED = 'LabCaseDeleted';
    const DEFAULT_POLLING_SECONDS     = 60;
    // v26.2.1+ sentinel for "every machine fires". Earlier versions treat a
    // blank Workstation the same way. Duplicates are expected in that mode
    // and are absorbed by event dedup; production should name the machine
    // running OpenDentalAPIService.exe instead.
    const ALL_WORKSTATIONS = 'All Workstations';

    /** GET /subscriptions - all subscriptions for this customer key. */
    public function listSubscriptions(array $credentials): array {
        $r = $this->get('/subscriptions', $credentials);
        return is_array($r) ? $r : [];
    }

    /**
     * POST /subscriptions for WatchTable: LabCase.
     * @param array $params endpoint_url, workstation, polling_seconds, note
     * @return array Decoded subscription (SubscriptionNum, DateTimeStart,
     *               failure fields) - contains no PHI.
     */
    public function createLabCaseSubscription(array $credentials, array $params): array {
        $body = [
            'EndPointUrl'    => (string)($params['endpoint_url'] ?? ''),
            'Workstation'    => (string)($params['workstation'] ?? self::ALL_WORKSTATIONS),
            'WatchTable'     => self::WATCH_TABLE_LABCASE,
            'PollingSeconds' => (int)($params['polling_seconds'] ?? self::DEFAULT_POLLING_SECONDS),
        ];
        if (!empty($params['note'])) {
            $body['Note'] = substr((string)$params['note'], 0, 255);
        }
        $r = $this->request('POST', '/subscriptions', $credentials, [], $body);
        if (!is_array($r) || empty($r['SubscriptionNum'])) {
            throw new OpenDentalApiException('unexpected_response', 200, '/subscriptions',
                'Open Dental returned a malformed response.');
        }
        return $r;
    }

    /**
     * PUT /subscriptions/{num} - whitelisted mutable fields only
     * (PollingSeconds, DateTimeStart, DateTimeStop, EndPointUrl,
     * Workstation, Note). WatchTable is immutable once created.
     */
    public function updateSubscription(array $credentials, int $subscriptionNum, array $fields): array {
        $allowed = ['PollingSeconds', 'DateTimeStart', 'DateTimeStop', 'EndPointUrl', 'Workstation', 'Note'];
        $body = array_intersect_key($fields, array_flip($allowed));
        $r = $this->request('PUT', "/subscriptions/{$subscriptionNum}", $credentials, [], $body);
        return is_array($r) ? $r : [];
    }

    /**
     * Disable a subscription WITHOUT the paid-tier DELETE method: set
     * DateTimeStop to the current time so Open Dental stops firing. The
     * subscription row remains inspectable via GET /subscriptions.
     */
    public function disableSubscription(array $credentials, int $subscriptionNum): array {
        return $this->updateSubscription($credentials, $subscriptionNum, [
            'DateTimeStop' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Find our LabCase subscription by number, or null when Open Dental no
     * longer has it (deleted remotely / never created).
     */
    public function findSubscription(array $credentials, int $subscriptionNum): ?array {
        foreach ($this->listSubscriptions($credentials) as $sub) {
            if (is_array($sub) && (int)($sub['SubscriptionNum'] ?? 0) === $subscriptionNum) {
                return $sub;
            }
        }
        return null;
    }

    /**
     * Parse an inbound WatchTable event POST body.
     *
     * @param string $rawBody         Raw request body (JSON array of rows)
     * @param string $eventTypeHeader Event-Type header value
     * @return array {entity_type, deleted, rows: [{external_id, watermark, row}]}
     * @throws OpenDentalApiException category=bad_request on wrong type or
     *         malformed payload; category=unexpected_response when a row
     *         lacks LabCaseNum.
     */
    public function parseWatchTableEvent(string $rawBody, string $eventTypeHeader): array {
        $type = trim($eventTypeHeader);
        $deleted = false;
        if (strcasecmp($type, 'WatchTable: ' . self::WATCH_TABLE_LABCASE_DELETED) === 0) {
            $deleted = true;
        } elseif (strcasecmp($type, 'WatchTable: ' . self::WATCH_TABLE_LABCASE) !== 0) {
            throw new OpenDentalApiException('bad_request', 400, 'event',
                'Unsupported event type.');
        }

        $rows = json_decode($rawBody, true);
        if (!is_array($rows)) {
            throw new OpenDentalApiException('bad_request', 400, 'event',
                'Malformed event payload.');
        }

        $parsed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new OpenDentalApiException('bad_request', 400, 'event',
                    'Malformed event payload.');
            }
            $labCaseNum = self::nonZeroId($row['LabCaseNum'] ?? null);
            if ($labCaseNum === null) {
                throw new OpenDentalApiException('bad_request', 400, 'event',
                    'Event row is missing its identifier.');
            }
            $parsed[] = [
                'external_id' => $labCaseNum,
                'watermark'   => self::odDate($row['DateTStamp'] ?? null),
                'row'         => $row,
            ];
        }

        return [
            'entity_type' => 'labcase',
            'deleted'     => $deleted,
            'rows'        => $parsed,
        ];
    }

    /**
     * Deterministic dedup key for one parsed event row. Open Dental events
     * carry no event id, so identity = entity id + its DateTStamp watermark
     * (a new modification produces a new watermark; a redelivery repeats
     * it). When the watermark is absent the row content itself is hashed so
     * identical replays still collapse.
     */
    public static function eventDedupKey(string $externalId, ?string $watermark, array $row): string {
        if ($watermark !== null && $watermark !== '') {
            return hash('sha256', $externalId . '|' . $watermark);
        }
        ksort($row);
        return hash('sha256', $externalId . '|' . json_encode($row));
    }

    private function getOrNull(string $path, array $credentials): ?array {
        try {
            $r = $this->get($path, $credentials);
        } catch (OpenDentalApiException $e) {
            if ($e->category === 'not_found') {
                return null;
            }
            throw $e;
        }
        return is_array($r) ? $r : null;
    }

    // ----------------------------------------------------------------------
    // Field normalization helpers
    // ----------------------------------------------------------------------

    /**
     * Open Dental uses 0 for "no foreign key". Convert 0/absent to null and
     * real IDs to strings.
     */
    private static function nonZeroId($value): ?string {
        if ($value === null || $value === '' ) {
            return null;
        }
        $n = (int)$value;
        return $n > 0 ? (string)$n : null;
    }

    private static function blankToNull($value): ?string {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    /**
     * Open Dental DateTime "unset" sentinels are 0001-01-01 and 2000-01-01
     * (both appear in official examples for labcase fields). Returns the
     * date/time string unchanged for real values, null for sentinels/blanks.
     */
    private static function odDate($value): ?string {
        $v = self::blankToNull($value);
        if ($v === null) {
            return null;
        }
        foreach (self::SENTINEL_DATES as $sentinel) {
            if (strncmp($v, $sentinel, strlen($sentinel)) === 0) {
                return null;
            }
        }
        return $v;
    }
}
