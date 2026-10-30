<?php
/**
 * MeditScanConnector
 *
 * Medit Link implementation of ScannerConnectorInterface. Turns the
 * documented case/file endpoints into the normalized scan payloads the
 * existing inbox -> storage -> attach pipeline consumes. Uses the
 * established 'medit' vendor identity - no new vendor key.
 *
 * ENDPOINT COVERAGE (all read-only; nothing here mutates Medit):
 *   fetchScans   -> GET /v1/cases/search (dateCreated window, ms epoch,
 *                   page/size pagination) + GET /v1/cases/{uuid} per case
 *                   for its files[] list (the search response carries no
 *                   file details).
 *   downloadFile -> GET /v1/files/{fileUuid}?type=stl (documented convert
 *                   types: stl/obj/ply) -> presigned S3 URL -> bytes.
 *
 * NORMALIZATION:
 *   external_scan_id     <- case uuid (stable across reconnects -
 *                           dedup_key is practice+vendor+connection+
 *                           account+scan id, so re-connections re-match)
 *   external_patient_ref <- patient.code (opaque vendor code - never a
 *                           name; labs may receive it empty)
 *   patient_*            <- patient.name is a SINGLE field in the Medit
 *                           schema (display-only normalization: split on
 *                           the last space). NEVER used for matching.
 *   scan_at              <- dateScanned, falling back to dateCreated
 *   instructions         <- not supplied by the API (never fabricated)
 *   files                <- one descriptor per files[] entry with
 *                           fileType SCAN_DATA. meditMesh files are
 *                           fetched via the documented ?type=stl
 *                           conversion (descriptor file_name reflects
 *                           the .stl the practice actually receives).
 *   revision             <- signature over file uuid + size +
 *                           dateUpdated - the only revision signal the
 *                           API exposes. An updated file (new uuid or
 *                           changed size/dateUpdated) produces a new
 *                           file row; a same-uuid/same-size re-scan is
 *                           undetectable and documented as such.
 *
 * PACKAGING (verified against the documented response shape):
 *   Get File returns a presigned S3 url whose downloadFileName is a 7z
 *   archive (e.g. {uuid}.stl.7z) plus items[] - the archive's entry
 *   name/size/MD5. The connector extracts 7z with strict limits
 *   (entry count, expanded size, relative-path-only entries, no
 *   symlinks) via the p7zip binary. Environments without p7zip store
 *   the verified archive bytes opaquely - the user-downloadable file
 *   is still intact, but content-level hash verification is skipped
 *   and logged. That degradation is never silent.
 *
 * INTEGRITY: items[].hashValue/hashAlgorithm (MD5, base64 per Appendix
 *   B) is verified against the extracted entry before bytes reach
 *   storage. A mismatch throws a retryable ScannerDownloadException -
 *   the import is never marked complete on corrupt bytes.
 */

require_once __DIR__ . '/../../feature-flags.php';
require_once __DIR__ . '/../scanners/ScannerConnectorInterface.php';
require_once __DIR__ . '/../scanners/ScannerDownloadException.php';
require_once __DIR__ . '/../scanners/ScanFileStore.php';
require_once __DIR__ . '/../MeditConfig.php';
require_once __DIR__ . '/MeditClient.php';
require_once __DIR__ . '/MeditAuthService.php';

class MeditScanConnector implements ScannerConnectorInterface {

    public const VENDOR = 'medit';

    /** 7z signature: 37 7A BC AF 27 1C. */
    private const SEVENZ_MAGIC = "\x37\x7A\xBC\xAF\x27\x1C";

    /** Archive safety limits (independent of the per-file ceiling). */
    private const MAX_ARCHIVE_ENTRIES = 50;
    private const MAX_ARCHIVE_EXPANDED_BYTES = 512 * 1024 * 1024;

    /** Convert type used for meditMesh scan geometry (documented). */
    private const SCAN_CONVERT_TYPE = 'stl';

    /** @var PDO */
    private $pdo;
    /** @var MeditAuthService */
    private $auth;
    /** @var callable|null transport seam (same shape as MeditClient). */
    private $transport;
    /** @var callable|null client factory seam for tests. */
    private $clientFactory;

    /**
     * @param PDO|null $pdo       falls back to the app-global connection.
     * @param MeditAuthService|null $auth
     * @param callable|null $transport HTTP seam -> forwarded to clients.
     * @param callable|null $clientFactory fn(PDO,int,callable)=>MeditClient
     */
    public function __construct(?PDO $pdo = null, ?MeditAuthService $auth = null, ?callable $transport = null, ?callable $clientFactory = null) {
        global $pdo;
        $this->pdo = $pdo ?? $GLOBALS['pdo'];
        $this->auth = $auth ?? new MeditAuthService($transport);
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
     * Discover cases inside a dateCreated window.
     *
     * Context keys:
     *   connection_id   int    REQUIRED - medit connection to call through
     *   window_start    string RFC3339 lower bound
     *   window_end      string RFC3339 upper bound
     *   page_token      string resume position (0-based page index)
     *   page_limit      int    max list pages this call (default 10)
     *   on_page         callable fn(array $payloads, ?string $nextToken): bool
     *   case_uuids      array  explicit case uuids to fetch instead of a
     *                          windowed search (webhook-driven updates to
     *                          cases outside the creation window)
     *   client          MeditClient test seam
     *
     * @return array normalized scan payloads (interface contract).
     */
    public function fetchScans(array $context): array {
        $client = $context['client'] ?? $this->clientForContext($context);
        $onPage = $context['on_page'] ?? null;

        // Explicit case refresh (webhook-driven): fetch each case by id -
        // covers updates to cases created before the polling window.
        if (!empty($context['case_uuids']) && is_array($context['case_uuids'])) {
            $payloads = [];
            foreach (array_slice($context['case_uuids'], 0, 50) as $uuid) {
                $uuid = trim((string)$uuid);
                if ($uuid === '' || strlen($uuid) > 64) {
                    continue;
                }
                $payloads[] = $this->normalizeCase($client->getCase($uuid));
            }
            return $payloads;
        }

        $startMs = self::toEpochMs($context['window_start'] ?? null);
        $endMs   = self::toEpochMs($context['window_end'] ?? null);
        if ($startMs === null || $endMs === null) {
            // The documented search requires start/end - an unbounded
            // listing is never issued on behalf of a sync run.
            throw new MeditApiException('invalid_resource', 'Medit scan polling requires a bounded window.', false);
        }

        $pageLimit = max(1, min(50, (int)($context['page_limit'] ?? 10)));
        $page = isset($context['page_token']) && $context['page_token'] !== ''
            ? max(0, (int)$context['page_token']) : 0;

        $payloads = [];
        $pages = 0;
        do {
            $result = $client->listCases(MeditClient::DEFAULT_PAGE_SIZE, $page, $startMs, $endMs);
            $pages++;
            $pagePayloads = [];
            foreach ($result['content'] as $caseSummary) {
                if (!is_array($caseSummary)) {
                    continue;
                }
                $uuid = trim((string)($caseSummary['uuid'] ?? ''));
                if ($uuid === '') {
                    throw new MeditApiException('unexpected_response', 'Medit case summary lacked its uuid.', true);
                }
                // files[] only exists on the detail response - one
                // documented call per case inside the window.
                $detail = $client->getCase($uuid);
                $pagePayloads[] = $this->normalizeCase($detail, $caseSummary);
            }

            $nextToken = (!$result['last'] && count($result['content']) > 0)
                ? (string)($page + 1) : null;

            if (is_callable($onPage)) {
                // Progress is committed by the caller per page - a false
                // return stops pagination so unprocessed pages are not
                // marked as consumed.
                if ($onPage($pagePayloads, $nextToken) === false) {
                    return $payloads;
                }
            }
            $payloads = array_merge($payloads, $pagePayloads);
            $page++;
        } while ($nextToken !== null && $pages < $pageLimit);

        return $payloads;
    }

    // ----------------------------------------------------------------------
    // downloadFile
    // ----------------------------------------------------------------------

    /**
     * remote_ref format:
     *   medit://file/{fileUuid}/stl/{urlencoded-basename}   (converted scan)
     *   medit://file/{fileUuid}/orig/{urlencoded-basename}  (original file)
     */
    public function downloadFile(array $context, string $externalFileId): array {
        $ref = (string)($context['remote_ref'] ?? '');
        if (!preg_match('#^medit://file/([^/]+)/(stl|obj|ply|orig)(?:/(.+))?$#', $ref, $m)) {
            throw new ScannerDownloadException('unknown remote reference', false, 'not_found');
        }
        $fileUuid = rawurldecode($m[1]);
        $mode = $m[2];
        $expectedName = isset($m[3]) && $m[3] !== '' ? rawurldecode($m[3]) : null;

        $client = $context['client'] ?? $this->clientForContext($context);
        $tmpFile = null;
        try {
            $descriptor = $client->getFileDownload($fileUuid, $mode === 'orig' ? null : $mode);
            $download = $client->downloadPresigned($descriptor['url']);
            $tmpFile = $download['tmp_file'];

            $bytes = file_get_contents($tmpFile);
            if ($bytes === false || $bytes === '') {
                throw new ScannerDownloadException('empty download', true, 'download_empty');
            }

            return $this->extractPayload(
                $bytes,
                $download['content_type'],
                $descriptor,
                $expectedName,
                $mode
            );
        } catch (MeditAuthException $e) {
            $retryable = $e->retryable && $e->category !== 'invalid_credentials' && $e->category !== 'not_found';
            throw new ScannerDownloadException('source authorization failed', $retryable, 'auth_' . $e->category);
        } catch (MeditApiException $e) {
            $code = 'download_failed';
            if (in_array($e->category, ['forbidden', 'not_found', 'gone', 'content_gone', 'file_too_large', 'invalid_download_url', 'invalid_resource', 'group_unbound'], true)) {
                $code = in_array($e->category, ['not_found', 'gone', 'content_gone'], true) ? 'content_gone' : $e->category;
            } elseif ($e->category === 'rate_limited') {
                $code = 'rate_limited';
            }
            throw new ScannerDownloadException('download failed: ' . $e->category, $e->retryable, $code);
        } catch (ScannerDownloadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ScannerDownloadException('download failed', true, 'download_failed');
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }
    }

    // ----------------------------------------------------------------------
    // Content packaging
    // ----------------------------------------------------------------------

    /**
     * Inspect the downloaded bytes: a 7z archive (the documented
     * packaging) is extracted safely and its entry verified against the
     * items[] MD5; anything else is stored verbatim.
     */
    private function extractPayload(string $bytes, string $contentType, array $descriptor, ?string $expectedName, string $mode): array {
        if (substr($bytes, 0, 6) !== self::SEVENZ_MAGIC) {
            return ['content' => $bytes, 'content_type' => $contentType];
        }

        $items = $this->expectedItems($descriptor);
        $binary = self::sevenZipBinary();
        if ($binary === null) {
            // No extractor on this host: store the verified-archive bytes
            // opaquely (documented degradation - never silent). The file
            // remains user-downloadable; per-entry integrity could not
            // be checked.
            error_log('[medit] p7zip unavailable - storing 7z archive opaquely');
            return ['content' => $bytes, 'content_type' => 'application/x-7z-compressed'];
        }

        $entries = $this->extract7z($bytes, $binary);
        $match = $this->matchEntry($entries, $items, $expectedName);
        if ($match === null) {
            throw new ScannerDownloadException('archive entry could not be matched to its descriptor', true, 'packaging_ambiguous');
        }
        if ($match['size'] > ScanFileStore::maxFileBytes()) {
            throw new ScannerDownloadException('file too large', false, 'file_too_large');
        }
        $content = file_get_contents($match['path']);
        if ($content === false || $content === '') {
            throw new ScannerDownloadException('archive read failed', true, 'download_failed');
        }

        // Integrity: the documented items[] carries a base64-encoded MD5
        // per archive entry. Verification is required whenever the hash
        // is supplied - a mismatch means corrupt bytes must never be
        // marked ready.
        if (isset($items[$match['name']])) {
            $expected = $items[$match['name']];
            if ($expected['hash'] !== null && strtolower(md5($content)) !== strtolower($expected['hash'])) {
                throw new ScannerDownloadException('download integrity check failed', true, 'checksum_mismatch');
            }
            if ($expected['size'] !== null && $expected['size'] !== strlen($content)) {
                throw new ScannerDownloadException('download size check failed', true, 'checksum_mismatch');
            }
        }

        return ['content' => $content, 'content_type' => $contentType];
    }

    /**
     * items[] from the Get File response, keyed by entry name. Hash is
     * base64 MD5 per Appendix B - decoded to hex for comparison.
     */
    private function expectedItems(array $descriptor): array {
        $out = [];
        foreach (($descriptor['items'] ?? []) as $item) {
            if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
                continue;
            }
            $hash = null;
            if (isset($item['hashValue']) && strtoupper((string)($item['hashAlgorithm'] ?? '')) === 'MD5') {
                $raw = base64_decode((string)$item['hashValue'], true);
                if ($raw !== false) {
                    $hash = bin2hex($raw);
                } elseif (preg_match('/^[0-9a-f]{32}$/i', (string)$item['hashValue'])) {
                    $hash = (string)$item['hashValue'];
                }
            }
            $out[$item['name']] = [
                'hash' => $hash,
                'size' => isset($item['size']) ? (int)$item['size'] : null,
            ];
        }
        return $out;
    }

    /**
     * Locate the p7zip CLI. Checks the common binary names in PATH;
     * environments without it fall back to opaque archive storage.
     */
    private static function sevenZipBinary(): ?string {
        static $cached = null;
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }
        foreach (['7zr', '7z', '7za'] as $name) {
            $probe = stripos(PHP_OS, 'WIN') === 0 ? "where {$name} 2>NUL" : "command -v {$name} 2>/dev/null";
            $out = @shell_exec($probe);
            if (is_string($out) && trim($out) !== '') {
                return $cached = trim(explode("\n", trim($out))[0]);
            }
        }
        $cached = '';
        return null;
    }

    /**
     * Extract a 7z archive into a private temp dir with strict limits,
     * returning validated entry descriptors. Safety contract:
     *   - entry count <= MAX_ARCHIVE_ENTRIES
     *   - expanded total <= MAX_ARCHIVE_EXPANDED_BYTES
     *   - every entry a regular file (no symlinks/hardlinks)
     *   - every entry resolves inside the temp dir (no traversal,
     *     absolute paths, or drive prefixes)
     */
    private function extract7z(string $bytes, string $binary): array {
        $base = sys_get_temp_dir() . '/mdz_' . bin2hex(random_bytes(8));
        if (!@mkdir($base, 0700, true)) {
            throw new ScannerDownloadException('archive workspace failed', true, 'download_failed');
        }
        $archive = $base . '/payload.7z';
        $entries = [];
        try {
            if (file_put_contents($archive, $bytes) === false) {
                throw new ScannerDownloadException('archive buffer failed', true, 'download_failed');
            }
            $outDir = $base . '/out';
            mkdir($outDir, 0700);
            $cmd = escapeshellarg($binary) . ' x -y -o' . escapeshellarg($outDir) . ' ' . escapeshellarg($archive) . ' 2>&1';
            exec($cmd, $output, $rc);
            if ($rc !== 0) {
                throw new ScannerDownloadException('unreadable archive', false, 'packaging_corrupt');
            }

            $expanded = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $realBase = realpath($outDir);
            foreach ($iterator as $fileInfo) {
                $path = $fileInfo->getPathname();
                // Symlinks/hardlinks are never honored - extraction output
                // must be regular files only.
                if ($fileInfo->isLink() || !$fileInfo->isFile()) {
                    if ($fileInfo->isLink()) {
                        throw new ScannerDownloadException('unsafe archive entry', false, 'packaging_unsafe');
                    }
                    continue;
                }
                $real = realpath($path);
                if ($real === false || strpos($real, $realBase) !== 0) {
                    throw new ScannerDownloadException('unsafe archive entry path', false, 'packaging_unsafe');
                }
                if (count($entries) >= self::MAX_ARCHIVE_ENTRIES) {
                    throw new ScannerDownloadException('archive entry limit exceeded', false, 'packaging_unsafe');
                }
                $size = $fileInfo->getSize();
                $expanded += $size;
                if ($expanded > self::MAX_ARCHIVE_EXPANDED_BYTES) {
                    throw new ScannerDownloadException('archive size limit exceeded', false, 'packaging_unsafe');
                }
                $entries[] = [
                    'name' => substr($real, strlen($realBase) + 1),
                    'base' => $fileInfo->getBasename(),
                    'path' => $real,
                    'size' => $size,
                ];
            }
            return $entries;
        } finally {
            self::removeTree($base);
        }
    }

    /**
     * Pick the archive entry matching the descriptor: prefer the exact
     * items[] name, then the expected download basename, then - only
     * when unambiguous - a single-entry archive. Never guesses.
     */
    private function matchEntry(array $entries, array $items, ?string $expectedName): ?array {
        if (count($entries) === 0) {
            return null;
        }
        if (count($items) === 1) {
            $itemName = array_key_first($items);
            foreach ($entries as $entry) {
                if ($entry['name'] === $itemName || $entry['base'] === basename($itemName)) {
                    return $entry;
                }
            }
        }
        if ($expectedName !== null) {
            foreach ($entries as $entry) {
                if (strcasecmp($entry['base'], $expectedName) === 0) {
                    return $entry;
                }
            }
        }
        return count($entries) === 1 ? $entries[0] : null;
    }

    private static function removeTree(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            $fileInfo->isDir() && !$fileInfo->isLink() ? @rmdir($fileInfo->getPathname()) : @unlink($fileInfo->getPathname());
        }
        @rmdir($dir);
    }

    // ----------------------------------------------------------------------
    // Normalization
    // ------------------------------------------------------------------

    /**
     * Case detail -> normalized payload. $summary (the search row) is an
     * optional fallback - the detail response normally carries the same
     * fields.
     */
    private function normalizeCase(array $detail, ?array $summary = null): array {
        $uuid = trim((string)($detail['uuid'] ?? $summary['uuid'] ?? ''));
        $patient = isset($detail['patient']) && is_array($detail['patient'])
            ? $detail['patient']
            : (isset($summary['patient']) && is_array($summary['patient']) ? $summary['patient'] : []);

        $patientName = self::bounded($patient['name'] ?? null, 255);
        [$first, $last] = self::splitDisplayName($patientName);
        $patientCode = self::bounded($patient['code'] ?? null, 128);

        $scanAt = self::bounded($detail['dateScanned'] ?? $summary['dateScanned'] ?? null, 40);
        if ($scanAt === null) {
            $scanAt = self::bounded($detail['dateCreated'] ?? $summary['dateCreated'] ?? null, 40);
        }

        $files = [];
        foreach (($detail['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            // Only scan geometry is ingestible - attached documents and
            // other file types stay out of the scan inbox by design.
            if (strtoupper((string)($file['fileType'] ?? '')) !== 'SCAN_DATA') {
                continue;
            }
            $fileUuid = trim((string)($file['uuid'] ?? ''));
            $fileName = self::bounded($file['name'] ?? null, 255);
            if ($fileUuid === '' || $fileName === null) {
                continue;
            }
            $isMesh = (bool)preg_match('/\.meditMesh$/i', $fileName);
            $displayName = $isMesh ? preg_replace('/\.meditMesh$/i', '.stl', $fileName) : $fileName;
            $refMode = $isMesh ? self::SCAN_CONVERT_TYPE : 'orig';
            $files[] = [
                'external_file_id' => $fileUuid,
                'remote_ref'       => 'medit://file/' . rawurlencode($fileUuid) . '/' . $refMode . '/' . rawurlencode($displayName),
                'file_name'        => $displayName,
                'content_type'     => $isMesh ? 'model/stl' : null,
                'declared_size'    => isset($file['size']) ? (int)$file['size'] : null,
                'revision'         => substr(md5($fileUuid . '|' . (int)($file['size'] ?? 0) . '|' . (string)($file['dateUpdated'] ?? '')), 0, 32),
                'file_role'        => null, // never inferred from names
            ];
        }

        return [
            'remote_ref'           => 'medit://case/' . rawurlencode($uuid),
            'external_scan_id'     => $uuid,
            'external_patient_ref' => $patientCode,
            'patient_first_name'   => $first,
            'patient_last_name'    => $last,
            'patient_dob'          => null,
            'scan_at'              => $scanAt,
            'instructions'         => null,
            'files'                => $files,
        ];
    }

    /**
     * Medit's patient.name is one free-text field. For display the last
     * whitespace-separated token is treated as the surname - this is a
     * display normalization ONLY; it is never used to match patients.
     */
    private static function splitDisplayName(?string $name): array {
        if ($name === null) {
            return [null, null];
        }
        $name = trim($name);
        if ($name === '') {
            return [null, null];
        }
        $pos = strrpos($name, ' ');
        if ($pos === false) {
            return [null, substr($name, 0, 128)];
        }
        return [substr($name, 0, min($pos, 128)), substr($name, $pos + 1, 128)];
    }

    private static function toEpochMs($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return $ts * 1000;
    }

    private static function bounded($value, int $max): ?string {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        return $v === '' ? null : substr($v, 0, $max);
    }

    // ----------------------------------------------------------------------
    // Client resolution
    // ------------------------------------------------------------------

    private function clientForContext(array $context): MeditClient {
        $connectionId = (int)($context['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            throw new MeditApiException('invalid_resource', 'A Medit connection is required.', false);
        }
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $this->pdo, $connectionId, $this->transport);
        }
        return new MeditClient($this->pdo, $connectionId, $this->auth, $this->transport);
    }
}
