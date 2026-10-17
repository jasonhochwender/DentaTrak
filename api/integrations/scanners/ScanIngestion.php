<?php
/**
 * ScanIngestion
 *
 * Vendor-agnostic incoming-scan pipeline: dedup -> file rows -> download ->
 * store -> status transitions. Nothing here knows about iTero/TRIOS/
 * Primescan/Medit - connectors produce normalized payloads (see
 * ScannerConnectorInterface) and this class owns all persistence.
 *
 * IDEMPOTENCY / CONCURRENCY:
 *  - Scan dedup is the UNIQUE dedup_key (sha256 of
 *    practice|vendor|connection|account|external_scan_id) - INSERT IGNORE
 *    makes a repeated vendor delivery a no-op and two concurrent ingestions
 *    converge on the same row.
 *  - File dedup is UNIQUE(scan_id, external_file_id, revision): a vendor
 *    re-send of the same file revision is ignored; a NEW revision inserts
 *    a NEW row and supersedes only unattached earlier revisions, so a
 *    previously attached file is never silently overwritten.
 *  - Download claim is a conditional UPDATE (pending/failed -> downloading);
 *    rowCount=0 means another worker owns the file - the loser skips it.
 *  - A 'downloading' file claimed longer than CLAIM_STALE_MINUTES ago is
 *    reclaimable (crashed worker), same pattern as integration events.
 *
 * PHI CONTRACT: patient names/DOB/instructions are stored encrypted via
 * PIIEncryption and must never appear in logs, events, or error fields.
 */

require_once __DIR__ . '/ScannerConnectorInterface.php';
require_once __DIR__ . '/ScannerDownloadException.php';
require_once __DIR__ . '/ScanFileStore.php';
require_once __DIR__ . '/../../encryption.php';

class ScanIngestion {

    // Scan statuses.
    public const SCAN_RECEIVED   = 'received';
    public const SCAN_PROCESSING = 'processing';
    public const SCAN_READY      = 'ready';
    public const SCAN_FAILED     = 'failed';
    public const SCAN_DISMISSED  = 'dismissed';
    public const SCAN_LINKED     = 'linked';

    // File statuses.
    public const FILE_PENDING     = 'pending';
    public const FILE_DOWNLOADING = 'downloading';
    public const FILE_READY       = 'ready';
    public const FILE_FAILED      = 'failed';
    public const FILE_SUPERSEDED  = 'superseded';

    /** A 'downloading' claim older than this is treated as crashed. */
    private const CLAIM_STALE_MINUTES = 10;

    /** Bounded retries per file download. */
    public const MAX_DOWNLOAD_ATTEMPTS = 5;

    /** Known vendor keys -> display label. Real vendors are reserved for
        their future adapters; only 'simulated' produces rows today. */
    public const VENDORS = [
        'itero'     => 'iTero',
        'trios'     => '3Shape TRIOS',
        'primescan' => 'Primescan / DS Core',
        'medit'     => 'Medit Link',
        'simulated' => 'Simulated connector',
    ];

    // ----------------------------------------------------------------------
    // Dedup
    // ----------------------------------------------------------------------

    public static function dedupKey(int $practiceId, string $vendor, ?int $connectionId, ?string $sourceAccount, string $externalScanId): string {
        return hash('sha256', implode('|', [
            'scan',
            (string)$practiceId,
            strtolower($vendor),
            (string)($connectionId ?? 0),
            (string)($sourceAccount ?? ''),
            $externalScanId,
        ]));
    }

    public static function findScanForPractice(PDO $pdo, int $practiceId, int $scanId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM scanner_incoming_scans WHERE id = :id AND practice_id = :pid LIMIT 1");
        $stmt->execute([':id' => $scanId, ':pid' => $practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function listFiles(PDO $pdo, int $scanId): array {
        $stmt = $pdo->prepare("SELECT * FROM scanner_scan_files WHERE scan_id = :sid ORDER BY id ASC");
        $stmt->execute([':sid' => $scanId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * PHI-free audit event. $detail carries ids/counts/statuses only.
     */
    public static function recordEvent(PDO $pdo, int $scanId, int $practiceId, ?int $userId, string $event, ?string $detail = null): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO scanner_scan_events (scan_id, practice_id, user_id, event, detail)
                VALUES (:scan_id, :practice_id, :user_id, :event, :detail)
            ");
            $stmt->execute([
                ':scan_id'     => $scanId,
                ':practice_id' => $practiceId,
                ':user_id'     => $userId,
                ':event'       => substr($event, 0, 48),
                ':detail'      => $detail !== null ? substr($detail, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[ScanIngestion] audit write failed: ' . $e->getMessage());
        }
    }

    // ----------------------------------------------------------------------
    // Ingest
    // ----------------------------------------------------------------------

    /**
     * Ingest one normalized scan payload for a practice.
     *
     * @param array $context practice_id (required), connection_id,
     *                       source_account, is_synthetic
     * @return array ['scan_id' => int, 'inserted' => bool, 'status' => string,
     *                'files_ready' => int, 'files_failed' => int]
     */
    public static function ingestScan(PDO $pdo, array $context, ScannerConnectorInterface $connector, array $payload): array {
        $practiceId    = (int)($context['practice_id'] ?? 0);
        $connectionId  = isset($context['connection_id']) ? (int)$context['connection_id'] : null;
        $sourceAccount = isset($context['source_account']) ? substr((string)$context['source_account'], 0, 128) : null;
        $vendor        = strtolower($connector->getVendor());
        $externalScanId = substr(trim((string)($payload['external_scan_id'] ?? '')), 0, 128);

        if ($practiceId <= 0 || $externalScanId === '' || !isset(self::VENDORS[$vendor])) {
            return ['scan_id' => 0, 'inserted' => false, 'status' => 'rejected'];
        }

        $dedupKey = self::dedupKey($practiceId, $vendor, $connectionId, $sourceAccount, $externalScanId);
        $now = gmdate('Y-m-d H:i:s');

        // Insert-first dedup: the unique key arbitrates concurrent deliveries.
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO scanner_incoming_scans
                (practice_id, connection_id, vendor, source_account, remote_ref,
                 external_scan_id, external_patient_ref,
                 patient_first_name, patient_last_name, patient_dob,
                 scan_at, instructions, status, is_synthetic, dedup_key)
            VALUES
                (:practice_id, :connection_id, :vendor, :source_account, :remote_ref,
                 :external_scan_id, :external_patient_ref,
                 :patient_first_name, :patient_last_name, :patient_dob,
                 :scan_at, :instructions, :status, :is_synthetic, :dedup_key)
        ");
        $stmt->execute([
            ':practice_id'          => $practiceId,
            ':connection_id'        => $connectionId,
            ':vendor'               => $vendor,
            ':source_account'       => $sourceAccount,
            ':remote_ref'           => self::boundedStr($payload['remote_ref'] ?? null, 255),
            ':external_scan_id'     => $externalScanId,
            ':external_patient_ref' => self::boundedStr($payload['external_patient_ref'] ?? null, 128),
            ':patient_first_name'   => self::encryptField($payload['patient_first_name'] ?? null),
            ':patient_last_name'    => self::encryptField($payload['patient_last_name'] ?? null),
            ':patient_dob'          => self::encryptField(self::normalizeDate($payload['patient_dob'] ?? null)),
            ':scan_at'              => self::normalizeDateTime($payload['scan_at'] ?? null),
            ':instructions'         => self::encryptField(self::sanitizeInstructions($payload['instructions'] ?? null)),
            ':status'               => self::SCAN_RECEIVED,
            ':is_synthetic'         => !empty($context['is_synthetic']) ? 1 : 0,
            ':dedup_key'            => $dedupKey,
        ]);
        $inserted = $stmt->rowCount() > 0;

        $scanStmt = $pdo->prepare("SELECT * FROM scanner_incoming_scans WHERE dedup_key = :k LIMIT 1");
        $scanStmt->execute([':k' => $dedupKey]);
        $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);
        $scanId = (int)$scan['id'];

        if ($inserted) {
            self::recordEvent($pdo, $scanId, $practiceId, null, 'imported',
                'vendor=' . $vendor . ' synthetic=' . (!empty($context['is_synthetic']) ? '1' : '0'));
        }

        // Upsert file rows. A new revision for a previously seen file is a
        // new row; unattached older revisions are superseded. Attached files
        // are never touched.
        foreach (($payload['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $externalFileId = substr(trim((string)($file['external_file_id'] ?? '')), 0, 128);
            $fileName = trim((string)($file['file_name'] ?? ''));
            $remoteRef = self::boundedStr($file['remote_ref'] ?? null, 255);
            if ($externalFileId === '' || $fileName === '' || $remoteRef === null) {
                continue;
            }
            $revision = substr((string)($file['revision'] ?? ''), 0, 64);
            $declaredSize = isset($file['declared_size']) ? max(0, (int)$file['declared_size']) : null;

            // Vendor-declared file role ('upper', 'lower', 'bite', ...) is
            // stored verbatim (bounded, plain text). When absent it stays
            // NULL - nothing infers clinical meaning from filenames.
            $fileRole = self::boundedStr(
                isset($file['file_role']) ? preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)$file['file_role']) : null,
                64
            );

            $ins = $pdo->prepare("
                INSERT IGNORE INTO scanner_scan_files
                    (scan_id, external_file_id, revision, remote_ref, file_name,
                     content_type, file_role, declared_size, status)
                VALUES
                    (:scan_id, :external_file_id, :revision, :remote_ref, :file_name,
                     :content_type, :file_role, :declared_size, :status)
            ");
            $ins->execute([
                ':scan_id'          => $scanId,
                ':external_file_id' => $externalFileId,
                ':revision'         => $revision,
                ':remote_ref'       => $remoteRef,
                ':file_name'        => substr($fileName, 0, 255),
                ':content_type'     => self::boundedStr($file['content_type'] ?? null, 128),
                ':file_role'        => $fileRole,
                ':declared_size'    => $declaredSize,
                ':status'           => self::FILE_PENDING,
            ]);

            if ($ins->rowCount() > 0) {
                $newFileId = (int)$pdo->lastInsertId();
                // Supersede same-file earlier revisions that were never
                // attached. Attached rows keep pointing at their stored bytes.
                $pdo->prepare("
                    UPDATE scanner_scan_files
                    SET status = :superseded
                    WHERE scan_id = :scan_id
                      AND external_file_id = :external_file_id
                      AND id <> :new_id
                      AND attached_case_id IS NULL
                      AND status IN (:pending, :ready_state, :failed)
                ")->execute([
                    ':superseded'       => self::FILE_SUPERSEDED,
                    ':scan_id'          => $scanId,
                    ':external_file_id' => $externalFileId,
                    ':new_id'           => $newFileId,
                    ':pending'          => self::FILE_PENDING,
                    ':ready_state'      => self::FILE_READY,
                    ':failed'           => self::FILE_FAILED,
                ]);
                self::recordEvent($pdo, $scanId, $practiceId, null, 'revision_received',
                    'file_id=' . $newFileId . ' ext=' . $externalFileId);
            }
        }

        self::processPendingFiles($pdo, $scan, $connector, $context);
        self::refreshScanStatus($pdo, $scanId);

        $final = self::findScanForPractice($pdo, $practiceId, $scanId);
        return [
            'scan_id'       => $scanId,
            'inserted'      => $inserted,
            'status'        => $final['status'] ?? 'unknown',
            'files_ready'   => self::countFiles($pdo, $scanId, self::FILE_READY),
            'files_failed'  => self::countFiles($pdo, $scanId, self::FILE_FAILED),
        ];
    }

    /**
     * Download every downloadable file row for a scan (pending, retryable
     * failed under the attempt cap, or stale claimed). Called by ingest and
     * by the user-facing Retry action - it is idempotent in both directions
     * (row claim + unique storage keys).
     *
     * @return array ['downloaded' => int, 'failed' => int]
     */
    public static function processPendingFiles(PDO $pdo, array $scan, ScannerConnectorInterface $connector, array $context = []): array {
        $scanId = (int)$scan['id'];
        $practiceId = (int)$scan['practice_id'];
        $downloaded = 0;
        $failed = 0;

        $stmt = $pdo->prepare("
            SELECT * FROM scanner_scan_files
            WHERE scan_id = :scan_id
              AND (
                  status = :pending
                  OR (status = :failed AND download_attempts < :max_attempts)
                  OR (status = :downloading AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL " . self::CLAIM_STALE_MINUTES . " MINUTE)))
              )
            ORDER BY id ASC
        ");
        $stmt->execute([
            ':scan_id'       => $scanId,
            ':pending'       => self::FILE_PENDING,
            ':failed'        => self::FILE_FAILED,
            ':max_attempts'  => self::MAX_DOWNLOAD_ATTEMPTS,
            ':downloading'   => self::FILE_DOWNLOADING,
        ]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $file) {
            $fileId = (int)$file['id'];

            // Atomic claim. rowCount=0 means another worker owns it or the
            // attempt cap was just reached - skip without touching state.
            $claim = $pdo->prepare("
                UPDATE scanner_scan_files
                SET status = :downloading, claimed_at = NOW(),
                    download_attempts = download_attempts + 1
                WHERE id = :id
                  AND (
                      status = :pending
                      OR (status = :failed AND download_attempts < :max_attempts)
                      OR (status = :downloading_stale AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL " . self::CLAIM_STALE_MINUTES . " MINUTE)))
                  )
            ");
            $claim->execute([
                ':downloading'       => self::FILE_DOWNLOADING,
                ':downloading_stale' => self::FILE_DOWNLOADING,
                ':id'                => $fileId,
                ':pending'           => self::FILE_PENDING,
                ':failed'            => self::FILE_FAILED,
                ':max_attempts'      => self::MAX_DOWNLOAD_ATTEMPTS,
            ]);
            if ($claim->rowCount() === 0) {
                continue;
            }

            $attempt = (int)$file['download_attempts'] + 1;

            try {
                $result = $connector->downloadFile(
                    $context + [
                        'practice_id' => $practiceId,
                        'scan_id'     => $scanId,
                        'remote_ref'  => $file['remote_ref'],
                        'attempt'     => $attempt,
                    ],
                    (string)$file['external_file_id']
                );
                $content = (string)($result['content'] ?? '');
                $size = strlen($content);

                if ($size === 0) {
                    throw new ScannerDownloadException('empty download', true, 'download_empty');
                }
                if ($size > ScanFileStore::maxFileBytes()) {
                    throw new ScannerDownloadException('file too large', false, 'file_too_large');
                }
                // An incomplete download is never ready: the vendor-declared
                // size is the contract. Mismatch = truncated read.
                if ($file['declared_size'] !== null && (int)$file['declared_size'] !== $size) {
                    throw new ScannerDownloadException('incomplete download', true, 'incomplete_download');
                }

                $stored = ScanFileStore::store(
                    $practiceId,
                    $fileId,
                    (string)$file['file_name'],
                    $content,
                    $result['content_type'] ?? $file['content_type']
                );

                $pdo->prepare("
                    UPDATE scanner_scan_files
                    SET status = :ready, storage_driver = :driver, storage_path = :path,
                        checksum_sha256 = :sha, claimed_at = NULL, error_code = NULL
                    WHERE id = :id
                ")->execute([
                    ':ready'  => self::FILE_READY,
                    ':driver' => $stored['storage_driver'],
                    ':path'   => $stored['storage_path'],
                    ':sha'    => $stored['sha256'],
                    ':id'     => $fileId,
                ]);
                $downloaded++;
                self::recordEvent($pdo, $scanId, $practiceId, null, 'file_ready', 'file_id=' . $fileId);
            } catch (ScannerDownloadException $e) {
                // Non-retryable failures skip the attempt cap and park now.
                $hardFail = !$e->isRetryable() || $attempt >= self::MAX_DOWNLOAD_ATTEMPTS;
                $pdo->prepare("
                    UPDATE scanner_scan_files
                    SET status = :failed, error_code = :code
                    WHERE id = :id
                ")->execute([
                    ':failed' => self::FILE_FAILED,
                    ':code'   => $hardFail ? $e->getErrorCode() : $e->getErrorCode(),
                    ':id'     => $fileId,
                ]);
                $failed++;
                self::recordEvent($pdo, $scanId, $practiceId, null, 'file_failed',
                    'file_id=' . $fileId . ' code=' . $e->getErrorCode() . ' attempt=' . $attempt);
            } catch (Throwable $e) {
                $pdo->prepare("
                    UPDATE scanner_scan_files
                    SET status = :failed, error_code = :code
                    WHERE id = :id
                ")->execute([
                    ':failed' => self::FILE_FAILED,
                    ':code'   => 'storage_error',
                    ':id'     => $fileId,
                ]);
                $failed++;
                error_log('[ScanIngestion] download/store failed for scan ' . $scanId . ' file ' . $fileId . ': ' . $e->getMessage());
                self::recordEvent($pdo, $scanId, $practiceId, null, 'file_failed',
                    'file_id=' . $fileId . ' code=storage_error attempt=' . $attempt);
            }
        }

        return ['downloaded' => $downloaded, 'failed' => $failed];
    }

    /**
     * Recompute the workflow status of a scan from its file rows.
     * User-terminal states (linked/dismissed) are preserved - a new file
     * revision arriving after link/dismiss must not reopen the workflow.
     */
    public static function refreshScanStatus(PDO $pdo, int $scanId): void {
        $scan = $pdo->prepare("SELECT status FROM scanner_incoming_scans WHERE id = :id");
        $scan->execute([':id' => $scanId]);
        $current = (string)$scan->fetchColumn();
        if (in_array($current, [self::SCAN_LINKED, self::SCAN_DISMISSED], true)) {
            return;
        }

        $counts = $pdo->prepare("
            SELECT status, COUNT(*) AS n FROM scanner_scan_files
            WHERE scan_id = :scan_id AND status <> :superseded
            GROUP BY status
        ");
        $counts->execute([':scan_id' => $scanId, ':superseded' => self::FILE_SUPERSEDED]);
        $byStatus = [];
        foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byStatus[$row['status']] = (int)$row['n'];
        }

        $failed = $byStatus[self::FILE_FAILED] ?? 0;
        $inFlight = ($byStatus[self::FILE_PENDING] ?? 0) + ($byStatus[self::FILE_DOWNLOADING] ?? 0);

        if ($failed > 0) {
            $newStatus = self::SCAN_FAILED;
            $errCode = $pdo->prepare("SELECT error_code FROM scanner_scan_files WHERE scan_id = :s AND status = :f ORDER BY id DESC LIMIT 1");
            $errCode->execute([':s' => $scanId, ':f' => self::FILE_FAILED]);
            $pdo->prepare("UPDATE scanner_incoming_scans SET status = :st, error_code = :ec, error_detail = NULL WHERE id = :id")
                ->execute([':st' => $newStatus, ':ec' => $errCode->fetchColumn() ?: 'download_failed', ':id' => $scanId]);
        } else {
            $newStatus = $inFlight > 0 ? self::SCAN_PROCESSING : self::SCAN_READY;
            $pdo->prepare("UPDATE scanner_incoming_scans SET status = :st, error_code = NULL, error_detail = NULL WHERE id = :id")
                ->execute([':st' => $newStatus, ':id' => $scanId]);
        }
    }

    // ----------------------------------------------------------------------
    // Field normalization
    // ----------------------------------------------------------------------

    private static function encryptField(?string $value): ?string {
        $value = $value !== null ? trim($value) : null;
        if ($value === null || $value === '') {
            return null;
        }
        return PIIEncryption::encrypt($value);
    }

    /** Decrypt a stored field; empty on failure so ciphertext never leaks. */
    public static function decryptField($value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $plain = PIIEncryption::decrypt((string)$value);
            return $plain === '' ? null : $plain;
        } catch (Throwable $e) {
            error_log('[ScanIngestion] field decrypt failed');
            return null;
        }
    }

    private static function normalizeDate($value): ?string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d', $ts);
    }

    private static function normalizeDateTime($value): ?string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Instructions are rendered as plain text downstream - strip markup at
     * the boundary so no vendor HTML can ever reach the DOM.
     */
    private static function sanitizeInstructions($value): ?string {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $text = strip_tags($value);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return mb_substr(trim($text), 0, 4000);
    }

    private static function boundedStr($value, int $max): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : substr($value, 0, $max);
    }

    private static function countFiles(PDO $pdo, int $scanId, string $status): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM scanner_scan_files WHERE scan_id = :s AND status = :st");
        $stmt->execute([':s' => $scanId, ':st' => $status]);
        return (int)$stmt->fetchColumn();
    }
}
