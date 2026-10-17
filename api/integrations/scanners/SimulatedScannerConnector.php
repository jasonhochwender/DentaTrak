<?php
/**
 * SimulatedScannerConnector
 *
 * DEVELOPMENT/TEST-ONLY connector that feeds synthetic scans through the
 * real ingestion -> storage -> inbox -> attach pipeline. It is NOT a stand-in
 * for any vendor contract - it exists so the pipeline is exercised end to
 * end before iTero / TRIOS / Primescan / Medit adapters exist.
 *
 * AVAILABILITY (fail closed):
 *   isAvailable() requires BOTH:
 *     - a non-production appConfig environment (development/local/test/uat
 *       or DENTATRAK_TEST_MODE), AND
 *     - the SCANNER_SIMULATOR feature flag.
 *   Every entry point (api/scanner-simulate.php, inbox retry resolution,
 *   CLI worker) calls isAvailable() first - in production it always returns
 *   false, so the connector can never run there regardless of flags.
 *
 * ISOLATION: the connector performs no network I/O, sends no email or
 * notifications, and never writes to Open Dental or any external system.
 * All fixture patients are clearly synthetic ("Simulation ..." names).
 *
 * REMOTE REF CONVENTION: remote_ref encodes the scenario
 *   sim://{scenario}/{scanKey}/{fileId}
 * so downloadFile() can reproduce deterministic failure behavior (e.g. the
 * 'partial' scenario truncates bytes on attempt 1, completes on attempt 2)
 * purely from the opaque handle - the pipeline never interprets it.
 *
 * SCENARIOS (fetchScans($context['scenario'])):
 *   success          One scan, one patient (with external_patient_ref),
 *                    instructions, and two ready-able files (STL + PDF).
 *   repeat           Identical payload to 'success' - re-delivery exercises
 *                    dedup (same dedup_key -> same scan row, no dup files).
 *   missing_patient  A scan with no external_patient_ref and no names -
 *                    inbox must show it without match suggestions.
 *   partial          File declares a larger size than attempt 1 returns;
 *                    attempt 2+ returns the complete body. Proves a
 *                    truncated download is never marked ready.
 *   retryable        downloadFile throws a retryable ScannerDownloadException
 *                    on attempts < 3 - exercises the bounded-retry path.
 *   revised          Same external_scan_id as 'success' but the STL file
 *                    carries revision 'rev2' and different bytes - must
 *                    produce a NEW file row, never overwrite the attached one.
 *   empty            No scans (source has nothing to deliver).
 */

require_once __DIR__ . '/ScannerConnectorInterface.php';
require_once __DIR__ . '/ScannerDownloadException.php';
require_once __DIR__ . '/../../appConfig.php';
require_once __DIR__ . '/../../feature-flags.php';

class SimulatedScannerConnector implements ScannerConnectorInterface {

    public const VENDOR = 'simulated';

    public const SCENARIOS = [
        'success', 'repeat', 'missing_patient', 'partial', 'retryable', 'revised', 'empty',
    ];

    private const SCAN_KEY_BASE = 'sim-scan-1001';

    // ----------------------------------------------------------------------
    // Availability
    // ----------------------------------------------------------------------

    /**
     * True only outside production AND when the SCANNER_SIMULATOR flag is on.
     * This is the single gate every entry point must check first.
     */
    public static function isAvailable(): bool {
        global $appConfig;
        $env = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
        $nonProduction = in_array($env, ['development', 'local', 'test', 'uat'], true)
            || getEnvVar('DENTATRAK_TEST_MODE', 'false') === 'true'
            || ($appConfig['test_mode'] ?? false) === true;
        if (!$nonProduction) {
            return false;
        }
        return isFeatureEnabled('SCANNER_SIMULATOR');
    }

    /** Throws when unavailable - belt under the callers' isAvailable() check. */
    public function __construct() {
        if (!self::isAvailable()) {
            throw new RuntimeException('Simulated scanner connector is not available in this environment.');
        }
    }

    public function getVendor(): string {
        return self::VENDOR;
    }

    // ----------------------------------------------------------------------
    // fetchScans
    // ----------------------------------------------------------------------

    public function fetchScans(array $context): array {
        $scenario = (string)($context['scenario'] ?? 'success');
        if (!in_array($scenario, self::SCENARIOS, true)) {
            $scenario = 'success';
        }

        switch ($scenario) {
            case 'empty':
                return [];
            case 'missing_patient':
                return [$this->scanPayload('sim-scan-2002', 'missing_patient', null, null, null, null)];
            case 'partial':
                return [$this->scanPayload('sim-scan-3003', 'partial', 'sim-pat-77', 'Simulation', 'PatientThree', '1988-06-15')];
            case 'retryable':
                return [$this->scanPayload('sim-scan-4004', 'retryable', 'sim-pat-88', 'Simulation', 'PatientFour', '1975-01-30')];
            case 'revised':
                // Same external_scan_id as 'success' -> same scan row; the
                // file carries revision rev2 -> a new, separate file row.
                return [$this->scanPayload(self::SCAN_KEY_BASE, 'revised', 'sim-pat-42', 'Simulation', 'PatientOne', '1990-03-12', 'rev2')];
            case 'success':
            case 'repeat':
            default:
                return [$this->scanPayload(self::SCAN_KEY_BASE, 'success', 'sim-pat-42', 'Simulation', 'PatientOne', '1990-03-12', 'rev1')];
        }
    }

    private function scanPayload(string $scanKey, string $scenario, ?string $patientRef, ?string $first, ?string $last, ?string $dob, string $revision = 'rev1'): array {
        $stlFile = [
            'external_file_id' => $scanKey . '-crown.stl',
            'remote_ref'       => 'sim://' . $scenario . '/' . $scanKey . '/crown.stl',
            'file_name'        => 'SYNTHETIC-' . $scanKey . '-crown.stl',
            'content_type'     => 'model/stl',
            'revision'         => $revision,
        ];
        // For 'partial', the declared size is larger than what attempt 1
        // returns; downloadFile() serves the full body from attempt 2.
        $stlFile['declared_size'] = strlen(self::stlBody($scanKey, $revision));

        $pdfFile = [
            'external_file_id' => $scanKey . '-rx.pdf',
            'remote_ref'       => 'sim://' . $scenario . '/' . $scanKey . '/rx.pdf',
            'file_name'        => 'SYNTHETIC-' . $scanKey . '-rx.pdf',
            'content_type'     => 'application/pdf',
            'revision'         => 'rev1',
            'declared_size'    => strlen(self::pdfBody($scanKey)),
        ];

        return [
            'remote_ref'           => 'sim://' . $scenario . '/' . $scanKey,
            'external_scan_id'     => $scanKey,
            'external_patient_ref' => $patientRef,
            'patient_first_name'   => $first,
            'patient_last_name'    => $last,
            'patient_dob'          => $dob,
            'scan_at'              => gmdate('Y-m-d H:i:s', strtotime('-2 hours')),
            'instructions'         => 'SYNTHETIC SCAN - Shade A2. Please verify margins on tooth #14. (Simulated connector output for development/testing only.)',
            'files'                => [$stlFile, $pdfFile],
        ];
    }

    // ----------------------------------------------------------------------
    // downloadFile
    // ----------------------------------------------------------------------

    public function downloadFile(array $context, string $externalFileId): array {
        $remoteRef = (string)($context['remote_ref'] ?? '');
        $attempt   = (int)($context['attempt'] ?? 1);

        // Parse sim://{scenario}/{scanKey}/{fileName}
        if (!preg_match('#^sim://([a-z_]+)/([A-Za-z0-9\-]+)/([A-Za-z0-9.\-_]+)$#', $remoteRef, $m)) {
            throw new ScannerDownloadException('unknown remote reference', false, 'not_found');
        }
        [, $scenario, $scanKey, $fileName] = $m;

        $isStl = substr($fileName, -4) === '.stl';
        $revision = $isStl ? ($scenario === 'revised' ? 'rev2' : 'rev1') : 'rev1';
        $body = $isStl ? self::stlBody($scanKey, $revision) : self::pdfBody($scanKey);

        switch ($scenario) {
            case 'partial':
                if ($attempt < 2 && $isStl) {
                    // Truncated body - the pipeline must NOT mark it ready
                    // because declared_size won't match the stored size.
                    // The sibling file still imports: a partial download
                    // affects one file, not the whole scan.
                    $body = substr($body, 0, (int)floor(strlen($body) / 2));
                }
                break;
            case 'retryable':
                if ($attempt < 3) {
                    throw new ScannerDownloadException('vendor temporarily unavailable', true, 'download_failed');
                }
                break;
        }

        return [
            'content'      => $body,
            'content_type' => $isStl ? 'model/stl' : 'application/pdf',
        ];
    }

    // ----------------------------------------------------------------------
    // Synthetic file bodies (nonclinical fixtures)
    // ----------------------------------------------------------------------

    /** Minimal syntactically-valid ASCII STL - clearly labeled synthetic. */
    private static function stlBody(string $scanKey, string $revision): string {
        $label = 'SYNTHETIC ' . $scanKey . ' ' . $revision;
        // 1 triangle = valid trivial STL solid, padded to a realistic size.
        $stl = "solid {$label}\n"
            . "  facet normal 0 0 1\n"
            . "    outer loop\n"
            . "      vertex 0 0 0\n      vertex 10 0 0\n      vertex 0 10 0\n"
            . "    endloop\n  endfacet\n"
            . "endsolid {$label}\n";
        return str_pad($stl, 4096, '# synthetic padding - not clinical data ');
    }

    /** Small synthetic PDF body. */
    private static function pdfBody(string $scanKey): string {
        $text = 'SYNTHETIC PRESCRIPTION - ' . $scanKey . ' - development fixture only, no patient data';
        return "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            . "4 0 obj << /Length " . (strlen($text) + 40) . " >> stream\nBT /F1 10 Tf 50 700 Td ({$text}) Tj ET\nendstream endobj\n"
            . "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n";
    }
}
