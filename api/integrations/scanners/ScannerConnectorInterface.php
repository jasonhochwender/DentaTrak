<?php
/**
 * ScannerConnectorInterface
 *
 * Boundary between DentaTrak's incoming-scan pipeline and a scanner vendor's
 * delivery surface (iTero, 3Shape TRIOS, Primescan / DS Core, Medit Link).
 *
 * Vendor integrations are NOT approved or connected yet - this contract
 * exists so the real pipeline (dedup, storage, inbox, case linking) is built
 * and exercised against the development-only SimulatedScannerConnector, and
 * a real vendor adapter can slot in later without reshaping the pipeline.
 *
 * NORMALIZED SCAN PAYLOAD (array shape returned by fetchScans()):
 *   remote_ref             string  Opaque connector handle for the scan
 *                                  (re-fetch/retry); never logged with PHI.
 *   external_scan_id       string  REQUIRED. Vendor scan/case identifier.
 *   external_patient_ref   ?string Vendor patient identifier (opaque id,
 *                                  never a name).
 *   patient_first_name     ?string PHI - handled only by the import path.
 *   patient_last_name      ?string PHI - handled only by the import path.
 *   patient_dob            ?string 'Y-m-d' when known.
 *   scan_at                ?string ISO-8601 or 'Y-m-d H:i:s' capture time.
 *   instructions           ?string Plain-text prescription/instructions.
 *                                  Connectors must strip markup - the inbox
 *                                  renders this as text, never raw HTML.
 *   files                  array   List of normalized file descriptors:
 *     external_file_id     string  REQUIRED. Vendor file identifier.
 *     remote_ref           string  REQUIRED. Opaque handle passed back to
 *                                  downloadFile().
 *     file_name            string  REQUIRED. Display/download filename.
 *     content_type         ?string Declared MIME type (validated, not trusted).
 *     declared_size        ?int    Vendor-declared byte size. When present the
 *                                  pipeline refuses to mark a file ready
 *                                  unless the stored byte count matches, so
 *                                  truncated downloads can never look ready.
 *     revision             ?string Vendor revision tag; a changed revision
 *                                  produces a NEW file row so previously
 *                                  attached bytes are never overwritten.
 *
 * All fields except external_scan_id and files[].external_file_id /
 * remote_ref / file_name may be absent - vendors do not supply uniform data.
 *
 * downloadFile() returns:
 *   ['content' => string bytes, 'content_type' => ?string]
 * or throws ScannerDownloadException ($retryable distinguishes transient
 * network/availability failures from permanent ones).
 *
 * SECURITY: a connector must only ever be constructed by server-side code
 * for a connection/account DentaTrak already verified belongs to the
 * practice. Patient data flowing through this interface must never reach
 * logs, sync-event detail, or error messages.
 */

interface ScannerConnectorInterface {

    /** Vendor key, e.g. 'itero', 'trios', 'primescan', 'medit', 'simulated'. */
    public function getVendor(): string;

    /**
     * Fetch the normalized scans currently available for the source account.
     *
     * @param array $context Connector-supplied context (connection row +
     *                       options); the simulated connector uses it to
     *                       select a scenario. Never contains credentials.
     * @return array List of normalized scan payloads (shape above).
     */
    public function fetchScans(array $context): array;

    /**
     * Download one vendor file's bytes.
     *
     * @param array  $context      Scan/file context assembled by the pipeline:
     *                             practice_id, scan_id, remote_ref (file),
     *                             attempt (1-based download attempt number).
     * @param string $externalFileId Vendor file identifier (for logging).
     * @return array ['content' => string, 'content_type' => ?string]
     * @throws ScannerDownloadException
     */
    public function downloadFile(array $context, string $externalFileId): array;
}
