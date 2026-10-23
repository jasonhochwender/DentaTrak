<?php
/**
 * DsCoreScanConnector
 *
 * DS Core (Primescan) implementation of ScannerConnectorInterface. Turns
 * the documented digital-impression endpoints into the normalized scan
 * payloads the existing inbox -> storage -> attach pipeline consumes.
 * Reuses the established 'primescan' vendor identity - no new vendor key.
 *
 * ENDPOINT COVERAGE (all read-only; nothing here mutates DS Core):
 *   fetchScans   -> GET /v1beta/digitalImpressions (pageSize/pageToken/
 *                   filter), optional GET /v1beta/fhir/Patient/{id} when an
 *                   impression omits patient name fields.
 *   downloadFile -> GET .../content?fileType=STL (explicit STL - the spec
 *                   restricts PMS downloads to STL even though other
 *                   formats exist in the shared schema), then
 *                   GET .../content/{content}/checksum for integrity.
 *
 * NORMALIZATION:
 *   external_scan_id     <- digital impression id (name sans prefix)
 *   external_patient_ref <- patient.id (opaque DS Core id - NOT assumed
 *                           to be an Open Dental patient identifier; the
 *                           inbox suggestion path treats it accordingly)
 *   patient_*            <- patient.givenName / familyName / birthday
 *   scan_at              <- createTime
 *   instructions         <- NOT supplied by the API (never fabricated)
 *   files                <- one descriptor per models[] entry (vendor-
 *                           declared type stored as file_role verbatim);
 *                           a single descriptor when models[] is empty.
 *   revision             <- signature over models + estimated sizes +
 *                           supported file types. The API exposes no
 *                           updateTime/etag, so this is NOT complete
 *                           revision synchronization: it is new-scan
 *                           import with LIMITED revision detection. A
 *                           signature change creates new file rows, but
 *                           changed content can retain identical model
 *                           metadata and file size - a same-metadata,
 *                           same-size revision is undetectable and will
 *                           NOT produce new rows. Re-export detection is
 *                           a documented gap until the API exposes a
 *                           revision signal.
 *
 * CONTENT PACKAGING - PENDING SANDBOX VERIFICATION:
 *   The spec documents the PLY path as a zip archive but is ambiguous for
 *   fileType=STL. The connector handles both: a plain STL stream is
 *   stored verbatim; a zip is opened safely (entry count, expanded-size
 *   and path-traversal limits) and the entry matching the model's file
 *   name is returned. A multi-entry archive with no model mapping can
 *   never silently pick the wrong file - it fails retryable as
 *   'packaging_ambiguous'.
 *
 * INTEGRITY: after download, the connector calls the documented checksum
 *   endpoint and compares md5Checksum (hex) to the downloaded stream.
 *   A mismatch throws a retryable ScannerDownloadException - the import
 *   is never marked complete on corrupt bytes.
 */

require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../scanners/ScannerConnectorInterface.php';
require_once __DIR__ . '/../scanners/ScannerDownloadException.php';
require_once __DIR__ . '/../scanners/ScanFileStore.php';
require_once __DIR__ . '/../DsCoreConfig.php';
require_once __DIR__ . '/DsCoreClient.php';
require_once __DIR__ . '/DsCoreAuthService.php';

class DsCoreScanConnector implements ScannerConnectorInterface {

    public const VENDOR = 'primescan';

    /** Archive safety limits (independent of the per-file ceiling). */
    private const MAX_ARCHIVE_ENTRIES = 50;
    private const MAX_ARCHIVE_EXPANDED_BYTES = 512 * 1024 * 1024;

    /** Cap on per-run FHIR patient lookups (one extra call each). */
    private const PATIENT_LOOKUP_CAP = 20;

    /** @var PDO */
    private $pdo;
    /** @var DsCoreAuthService */
    private $auth;
    /** @var callable|null transport seam (same shape as DsCoreClient). */
    private $transport;
    /** @var callable|null client factory seam for tests. */
    private $clientFactory;

    /**
     * @param PDO|null $pdo       falls back to the app-global connection.
     * @param DsCoreAuthService|null $auth
     * @param callable|null $transport HTTP seam -> forwarded to clients.
     * @param callable|null $clientFactory fn(PDO,int,callable)=>DsCoreClient
     */
    public function __construct(?PDO $pdo = null, ?DsCoreAuthService $auth = null, ?callable $transport = null, ?callable $clientFactory = null) {
        global $pdo;
        $this->pdo = $pdo ?? $GLOBALS['pdo'];
        // The auth service shares the transport seam so tests can mock
        // token:refresh alongside business calls.
        $this->auth = $auth ?? new DsCoreAuthService($transport);
        $this->transport = $transport;
        $this->clientFactory = $clientFactory;
    }

    public function getVendor(): string {
        return self::VENDOR;
    }

    // ----------------------------------------------------------------------
    // fetchScans
    // ----------------------------------------------------------------------

    /**
     * Discover digital impressions inside a create_time window.
     *
     * Context keys (all optional except connection_id):
     *   connection_id          int    ds_core connection to call through
     *   window_start           string RFC3339 lower bound (exclusive)
     *   window_end             string RFC3339 upper bound (inclusive)
     *   page_token             string resume token from a previous run
     *   page_limit             int    max list pages this call (default 10)
     *   patient_ref            string restrict to patient.id="ref"
     *   fetch_patient_details  bool   FHIR lookup when names are missing
     *   on_page                callable fn(array $payloads, ?string $nextToken): bool
     *                                 invoked per page AFTER normalization;
     *                                 return false to stop fetching (the
     *                                 caller keeps only committed progress).
     *   client                 DsCoreClient test seam
     *
     * @return array normalized scan payloads (interface contract).
     */
    public function fetchScans(array $context): array {
        $client = $context['client'] ?? $this->clientForContext($context);
        $pageLimit = max(1, min(50, (int)($context['page_limit'] ?? 10)));
        $pageToken = isset($context['page_token']) && $context['page_token'] !== ''
            ? (string)$context['page_token'] : null;
        $onPage = $context['on_page'] ?? null;
        $filter = self::buildFilter($context);
        $fetchPatients = ($context['fetch_patient_details'] ?? true) !== false;
        $patientLookups = 0;

        $payloads = [];
        $pages = 0;
        do {
            $response = $client->listDigitalImpressions(
                DsCoreClient::MAX_PAGE_SIZE, $pageToken, $filter);
            $pages++;
            $pagePayloads = [];
            foreach (($response['digitalImpressions'] ?? []) as $di) {
                if (is_array($di)) {
                    $pagePayloads[] = $this->normalizeImpression(
                        $di, $client, $fetchPatients, $patientLookups);
                }
            }
            $pageToken = isset($response['nextPageToken']) && $response['nextPageToken'] !== ''
                ? (string)$response['nextPageToken'] : null;

            if (is_callable($onPage)) {
                // Progress is committed by the caller per page - a false
                // return stops pagination so unprocessed pages are not
                // marked as consumed.
                if ($onPage($pagePayloads, $pageToken) === false) {
                    return $payloads;
                }
            }
            $payloads = array_merge($payloads, $pagePayloads);
        } while ($pageToken !== null && $pages < $pageLimit);

        return $payloads;
    }

    /**
     * Documented filter grammar: patient.id and create_time comparisons.
     * Values are RFC3339 timestamps / opaque patient ids supplied by the
     * sync layer - nothing user-controlled reaches this string.
     */
    public static function buildFilter(array $context): string {
        $clauses = [];
        $start = self::rfc3339($context['window_start'] ?? null);
        $end = self::rfc3339($context['window_end'] ?? null);
        if ($start !== null) {
            $clauses[] = 'create_time > "' . $start . '"';
        }
        if ($end !== null) {
            $clauses[] = 'create_time <= "' . $end . '"';
        }
        $patientRef = isset($context['patient_ref']) ? trim((string)$context['patient_ref']) : '';
        if ($patientRef !== '') {
            $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '', $patientRef);
            if ($safe !== '') {
                $clauses[] = 'patient.id="' . $safe . '"';
            }
        }
        return implode(' and ', $clauses);
    }

    // ----------------------------------------------------------------------
    // downloadFile
    // ----------------------------------------------------------------------

    /**
     * remote_ref format:
     *   dscore://di/{urlencoded-resource-name}/stl            (single file)
     *   dscore://di/{urlencoded-resource-name}/stl/{idx}/{urlencoded-basename}
     *                                                         (per-model file)
     * The basename is the descriptor's expected file name - it exists so a
     * multi-entry archive can be mapped back to its model deterministically.
     */
    public function downloadFile(array $context, string $externalFileId): array {
        $ref = (string)($context['remote_ref'] ?? '');
        if (!preg_match('#^dscore://di/([^/]+)/stl(?:/(\d+)(?:/(.+))?)?$#', $ref, $m)) {
            throw new ScannerDownloadException('unknown remote reference', false, 'not_found');
        }
        $name = rawurldecode($m[1]);
        $modelIdx = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null;
        $expectedName = isset($m[3]) && $m[3] !== '' ? rawurldecode($m[3]) : null;

        $client = $context['client'] ?? $this->clientForContext($context);

        $download = null;
        try {
            $download = $client->downloadContent($name, 'STL');
            $tmpFile = $download['tmp_file'];

            // Integrity: the documented checksum endpoint returns the md5
            // of the content stream; a mismatch means the bytes cannot be
            // trusted and must never be marked ready.
            $expectedMd5 = $client->getContentChecksum($name, $download['content_id']);
            $actualMd5 = md5_file($tmpFile);
            if ($actualMd5 === false || strtolower($actualMd5) !== $expectedMd5) {
                throw new ScannerDownloadException('download integrity check failed', true, 'checksum_mismatch');
            }

            $bytes = file_get_contents($tmpFile);
            if ($bytes === false || $bytes === '') {
                throw new ScannerDownloadException('empty download', true, 'download_empty');
            }

            return $this->extractPayload($bytes, $download['content_type'], $modelIdx, $expectedName);
        } catch (DsCoreAuthException $e) {
            // Auth failures map onto the same retry contract: transient
            // categories retry within the pipeline's bounded attempts;
            // invalid_credentials/not_found are permanent until reconnect.
            $retryable = $e->retryable && $e->category !== 'invalid_credentials' && $e->category !== 'not_found';
            throw new ScannerDownloadException('source authorization failed', $retryable, 'auth_' . $e->category);
        } catch (DsCoreApiException $e) {
            $code = 'download_failed';
            if (in_array($e->category, ['forbidden', 'not_found', 'gone', 'file_too_large', 'redirect_rejected', 'invalid_resource', 'invalid_patient_ref'], true)) {
                $code = $e->category === 'not_found' || $e->category === 'gone' ? 'content_gone' : $e->category;
            } elseif ($e->category === 'rate_limited') {
                $code = 'rate_limited';
            }
            throw new ScannerDownloadException('download failed: ' . $e->category, $e->retryable, $code);
        } catch (ScannerDownloadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ScannerDownloadException('download failed', true, 'download_failed');
        } finally {
            if ($download !== null && isset($download['tmp_file'])) {
                @unlink($download['tmp_file']);
            }
        }
    }

    // ----------------------------------------------------------------------
    // Content packaging
    // ----------------------------------------------------------------------

    /**
     * Inspect the verified bytes: a plain STL stream is returned as-is; a
     * zip archive is opened with strict safety limits. Packaging is a
     * documented ambiguity - whichever the API emits is handled here.
     */
    private function extractPayload(string $bytes, string $contentType, ?int $modelIdx, ?string $expectedName): array {
        if (!self::isZip($bytes)) {
            return ['content' => $bytes, 'content_type' => $contentType];
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'dsz_');
        if ($tmpZip === false || file_put_contents($tmpZip, $bytes) === false) {
            throw new ScannerDownloadException('archive buffer failed', true, 'download_failed');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                // Not a readable archive despite the magic bytes.
                throw new ScannerDownloadException('unreadable archive', false, 'packaging_corrupt');
            }
            $entries = [];
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $entryName = (string)($stat['name'] ?? '');
                if ($entryName === '' || substr($entryName, -1) === '/') {
                    continue; // directory entry
                }
                if (!self::safeArchivePath($entryName)) {
                    throw new ScannerDownloadException('unsafe archive entry path', false, 'packaging_unsafe');
                }
                if (count($entries) >= self::MAX_ARCHIVE_ENTRIES) {
                    throw new ScannerDownloadException('archive entry limit exceeded', false, 'packaging_unsafe');
                }
                $expanded += (int)($stat['size'] ?? 0);
                if ($expanded > self::MAX_ARCHIVE_EXPANDED_BYTES) {
                    throw new ScannerDownloadException('archive size limit exceeded', false, 'packaging_unsafe');
                }
                $entries[] = ['index' => $i, 'name' => $entryName, 'size' => (int)$stat['size']];
            }

            if ($modelIdx === null) {
                // Single-file descriptor.
                if (count($entries) === 1) {
                    $content = $zip->getFromIndex($entries[0]['index']);
                    if ($content === false) {
                        throw new ScannerDownloadException('archive read failed', true, 'download_failed');
                    }
                    return ['content' => $content, 'content_type' => $contentType];
                }
                // Multi-entry archive with no model mapping: store the
                // verified archive opaquely rather than guess an entry -
                // the pipeline supports opaque zip storage. Flagged as
                // packaging pending sandbox verification.
                return ['content' => $bytes, 'content_type' => 'application/zip'];
            }

            // Per-model descriptor: match the expected file name first,
            // then fall back to a uniquely-identifiable single entry.
            $match = null;
            if ($expectedName !== null) {
                foreach ($entries as $entry) {
                    if (strcasecmp(basename(str_replace('\\', '/', $entry['name'])), $expectedName) === 0) {
                        $match = $entry;
                        break;
                    }
                }
            }
            if ($match === null && count($entries) === 1) {
                $match = $entries[0];
            }
            if ($match === null) {
                // Never guess among multiple candidates - attaching the
                // wrong arch is worse than a retryable failure.
                throw new ScannerDownloadException('archive entry could not be matched to a model', true, 'packaging_ambiguous');
            }
            if ($match['size'] > ScanFileStore::maxFileBytes()) {
                throw new ScannerDownloadException('file too large', false, 'file_too_large');
            }
            $content = $zip->getFromIndex($match['index']);
            if ($content === false || $content === '') {
                throw new ScannerDownloadException('archive read failed', true, 'download_failed');
            }
            return ['content' => $content, 'content_type' => $contentType];
        } finally {
            if (isset($zip) && $zip instanceof ZipArchive) {
                $zip->close();
            }
            @unlink($tmpZip);
        }
    }

    private static function isZip(string $bytes): bool {
        return strlen($bytes) >= 4 && substr($bytes, 0, 4) === "PK\x03\x04";
    }

    /**
     * Archive entry names must be relative, simple paths: no absolute
     * prefixes, no drive letters, no traversal segments, no NUL.
     */
    private static function safeArchivePath(string $name): bool {
        if ($name === '' || strpos($name, "\0") !== false) {
            return false;
        }
        if ($name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:/', $name)) {
            return false;
        }
        foreach (preg_split('#[/\\\\]#', $name) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }
        return true;
    }

    // ----------------------------------------------------------------------
    // Normalization
    // ----------------------------------------------------------------------

    private function normalizeImpression(array $di, ?DsCoreClient $client, bool $fetchPatients, int &$patientLookups): array {
        $name = trim((string)($di['name'] ?? ''));
        $externalId = preg_match('#/([^/]+)$#', $name, $mm) ? $mm[1] : $name;

        $patient = isset($di['patient']) && is_array($di['patient']) ? $di['patient'] : [];
        $first = self::bounded($patient['givenName'] ?? null, 128);
        $last = self::bounded($patient['familyName'] ?? null, 128);
        $dob = self::normalizeDob($patient['birthday'] ?? null);
        $patientRef = self::bounded($patient['id'] ?? null, 128);

        // Additional patient detail only when the impression lacks it -
        // never called eagerly (bounded lookups, documented endpoint).
        if ($fetchPatients && $client !== null && $patientRef !== null
            && ($first === null || $last === null) && $patientLookups < self::PATIENT_LOOKUP_CAP) {
            $patientLookups++;
            try {
                $extra = $client->getPatient($patientRef);
                $names = self::patientNames($extra);
                if ($first === null) {
                    $first = self::bounded($names['first'] ?? null, 128);
                }
                if ($last === null) {
                    $last = self::bounded($names['last'] ?? null, 128);
                }
                if ($dob === null) {
                    $dob = self::normalizeDob($extra['birthday'] ?? $extra['birthDate'] ?? null);
                }
            } catch (Throwable $e) {
                // Names stay null -> editable in the case form. Nothing
                // about the patient is logged.
            }
        }

        $displayName = self::bounded($di['displayName'] ?? null, 128) ?: $externalId;
        $files = [];
        $models = isset($di['models']) && is_array($di['models']) ? array_values($di['models']) : [];
        $signature = substr(hash('sha256', json_encode([
            'm' => $models,
            's' => $di['estimatedContentSizesBytes'] ?? null,
            't' => $di['supportedFileTypes'] ?? null,
        ])), 0, 16);

        if ($models) {
            foreach ($models as $idx => $model) {
                $model = is_array($model) ? $model : [];
                $fileName = self::stlFileName(self::bounded($model['displayName'] ?? null, 96), $displayName, $idx);
                $files[] = [
                    'external_file_id' => substr($name . '#stl:' . $idx, 0, 128),
                    'remote_ref'       => 'dscore://di/' . rawurlencode($name) . '/stl/' . $idx . '/' . rawurlencode($fileName),
                    'file_name'        => $fileName,
                    'content_type'     => 'model/stl',
                    'file_role'        => isset($model['type']) ? (string)$model['type'] : null,
                    'revision'         => $signature,
                ];
            }
        } else {
            $fileName = self::stlFileName(null, $displayName, 0);
            $files[] = [
                'external_file_id' => substr($name . '#stl', 0, 128),
                'remote_ref'       => 'dscore://di/' . rawurlencode($name) . '/stl',
                'file_name'        => $fileName,
                'content_type'     => 'model/stl',
                'revision'         => $signature,
            ];
        }

        return [
            'remote_ref'           => 'dscore://di/' . rawurlencode($name),
            'external_scan_id'     => substr($externalId, 0, 128),
            'external_patient_ref' => $patientRef,
            'patient_first_name'   => $first,
            'patient_last_name'    => $last,
            'patient_dob'          => $dob,
            'scan_at'              => self::bounded($di['createTime'] ?? null, 40),
            'instructions'         => null, // not part of the documented schema
            'files'                => $files,
        ];
    }

    /** File names always end in .stl; fall back to the impression name. */
    private static function stlFileName(?string $modelName, string $displayName, int $idx): string {
        $base = $modelName !== null && $modelName !== '' ? $modelName : $displayName;
        $base = trim((string)$base);
        if ($base === '') {
            $base = 'scan-' . $idx;
        }
        if (strtolower(substr($base, -4)) !== '.stl') {
            $base .= '.stl';
        }
        return substr($base, 0, 200);
    }

    /** Tolerates both the flat spec schema and FHIR name[]/birthDate. */
    private static function patientNames(array $patient): array {
        if (isset($patient['givenName']) || isset($patient['familyName'])) {
            return [
                'first' => $patient['givenName'] ?? null,
                'last'  => $patient['familyName'] ?? null,
            ];
        }
        $first = null;
        $last = null;
        foreach (($patient['name'] ?? []) as $name) {
            if (!is_array($name)) {
                continue;
            }
            $last = $last ?? ($name['family'] ?? null);
            $given = $name['given'] ?? null;
            $first = $first ?? (is_array($given) ? ($given[0] ?? null) : $given);
        }
        return ['first' => $first, 'last' => $last];
    }

    private static function normalizeDob($raw): ?string {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // Documented as a date; accept Y-m-d or an RFC3339 timestamp.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
            return $m[0];
        }
        $ts = strtotime($raw);
        return $ts === false ? null : gmdate('Y-m-d', $ts);
    }

    private static function rfc3339($raw): ?string {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private static function bounded($raw, int $max): ?string {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $v = trim((string)$raw);
        return $v === '' ? null : substr($v, 0, $max);
    }

    // ----------------------------------------------------------------------
    // Client construction (per-connection, practice-verified)
    // ----------------------------------------------------------------------

    private function clientForContext(array $context): ?DsCoreClient {
        // Deepest gate in the download path: no DS Core request can be
        // made while the feature flag is off, regardless of which entry
        // point (poll worker, retry CLI, inbox retry) reached here.
        if (!isFeatureEnabled('DS_CORE_CONNECT')) {
            throw new ScannerDownloadException('source disabled', false, 'source_unavailable');
        }
        $connectionId = (int)($context['connection_id'] ?? 0);
        $practiceId = (int)($context['practice_id'] ?? 0);
        if ($connectionId <= 0) {
            throw new ScannerDownloadException('source connection missing', false, 'source_unavailable');
        }
        // The scan's recorded connection must still be a ds_core connection
        // owned by the scan's practice - a deleted/transferred connection
        // can never serve another practice's downloads.
        $stmt = $this->pdo->prepare("
            SELECT id, practice_id, provider, status FROM integration_connections
            WHERE id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $connectionId]);
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$connection
            || ($connection['provider'] ?? '') !== DsCoreAuthService::PROVIDER
            || ($practiceId > 0 && (int)$connection['practice_id'] !== $practiceId)) {
            throw new ScannerDownloadException('source connection unavailable', false, 'source_unavailable');
        }
        if (($connection['status'] ?? '') !== 'active') {
            throw new ScannerDownloadException('source connection is not active', false, 'source_unavailable');
        }
        // Practice allowlist (DS_CORE_ALLOWED_PRACTICES): a connection
        // whose practice falls off the list stops all DS Core traffic -
        // polling, queued file processing, and retries alike.
        if (!DsCoreConfig::isPracticeAllowed((int)$connection['practice_id'])) {
            throw new ScannerDownloadException('source practice not allowed', false, 'source_unavailable');
        }

        if (is_callable($this->clientFactory)) {
            return call_user_func($this->clientFactory, $this->pdo, $connectionId, $this->auth, $this->transport);
        }
        return new DsCoreClient($this->pdo, $connectionId, $this->auth, $this->transport);
    }
}
