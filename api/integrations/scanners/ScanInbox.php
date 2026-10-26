<?php
/**
 * ScanInbox
 *
 * Practice-scoped read/author side of the incoming-scans pipeline:
 * listing, detail, dismiss/restore, and the atomic attach-to-case action.
 *
 * Every method takes the server-derived $practiceId (from
 * requireValidPracticeContext()) and scopes every query by it - a scan id
 * from another practice is indistinguishable from a missing row.
 *
 * MATCHING POLICY: suggestedCases() only surfaces DentaTrak cases whose
 * PMS entity mapping carries the SAME external patient reference the
 * scanner source supplied (reliable cross-system identity). Patient names
 * are NEVER used for matching or suggestions.
 *
 * ATTACH SEMANTICS:
 *  - Only file rows that are 'ready', not superseded, and not already
 *    attached are moved. Latest revision per external_file_id wins.
 *  - Bytes move to the case-owned object key via ScanFileStore::moveToCase
 *    BEFORE the case row is updated; the attachment metadata is then
 *    appended under the case's optimistic version lock. A retry re-reads
 *    state, skips files already marked attached, and produces identical
 *    attachment entries - repeated clicks/concurrent submits converge.
 *  - Case fields are never clobbered: only attachments_json grows, and
 *    notes is appended to ONLY when the caller passed the explicit
 *    append_instructions confirmation. Everything else is preserved.
 */

require_once __DIR__ . '/ScanIngestion.php';
require_once __DIR__ . '/ScanFileStore.php';
require_once __DIR__ . '/ScannerConnectorInterface.php';
require_once __DIR__ . '/../../cases-cache.php';
require_once __DIR__ . '/../../practice-security.php';

class ScanInbox {

    // ----------------------------------------------------------------------
    // Read side
    // ----------------------------------------------------------------------

    /**
     * List inbox scans for a practice, newest first.
     * @return array serialized scan rows
     */
    public static function listForPractice(PDO $pdo, int $practiceId, ?int $userId = null, bool $includeDismissed = false): array {
        $sql = "SELECT * FROM scanner_incoming_scans WHERE practice_id = :pid";
        $params = [':pid' => $practiceId];
        if (!$includeDismissed) {
            $sql .= " AND status <> :dismissed";
            $params[':dismissed'] = ScanIngestion::SCAN_DISMISSED;
        }
        $sql .= " ORDER BY created_at DESC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::serializeScan($pdo, $row, $userId, true);
        }
        return $out;
    }

    /**
     * Filtered, paginated inbox listing.
     *
     * Status views:
     *   attention - unlinked work (received/processing/ready/failed) plus
     *               linked scans that still carry unlinked ready files
     *               (partially linked revisions awaiting attach)
     *   linked / dismissed / all - literal status views
     *
     * Search: patient names live encrypted, so SQL cannot match them.
     * SQL narrows by status/vendor/date (indexed, cheap); names are
     * decrypted server-side and substring-matched in PHP, then the page
     * is sliced - search covers every matching record, not just the page.
     * Scan IDs are plaintext and searchable. Search terms are never
     * logged and no plaintext is persisted.
     *
     * @param array $opts view|q|vendor|date_from|date_to|page|per_page
     * @return array {scans,total,page,per_page,counts,vendors,truncated}
     */
    public static function listFiltered(PDO $pdo, int $practiceId, ?int $userId, array $opts): array {
        $view    = (string)($opts['view'] ?? 'attention');
        if (!in_array($view, ['attention', 'linked', 'dismissed', 'all'], true)) {
            $view = 'attention';
        }
        $q        = mb_strtolower(trim((string)($opts['q'] ?? '')));
        $vendor   = strtolower(trim((string)($opts['vendor'] ?? '')));
        $dateFrom = self::boundDate($opts['date_from'] ?? null, false);
        $dateTo   = self::boundDate($opts['date_to'] ?? null, true);
        $page     = max(1, (int)($opts['page'] ?? 1));
        $perPage  = max(1, min(100, (int)($opts['per_page'] ?? 25)));

        // Unlinked-ready files flag: a linked scan with files still waiting
        // is partially linked and stays visible under Needs attention.
        $unlinkedReady = "EXISTS(SELECT 1 FROM scanner_scan_files f WHERE f.scan_id = s.id AND f.status = 'ready' AND f.attached_case_id IS NULL)";

        $where = "s.practice_id = :pid";
        $params = [':pid' => $practiceId];
        if ($view === 'attention') {
            $where .= " AND (s.status IN ('received','processing','ready','failed') OR (s.status = 'linked' AND $unlinkedReady))";
        } elseif ($view === 'linked') {
            $where .= " AND s.status = 'linked'";
        } elseif ($view === 'dismissed') {
            $where .= " AND s.status = 'dismissed'";
        }
        if ($vendor !== '') {
            $where .= " AND s.vendor = :vendor";
            $params[':vendor'] = $vendor;
        }
        if ($dateFrom !== null) {
            $where .= " AND COALESCE(s.scan_at, s.created_at) >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $where .= " AND COALESCE(s.scan_at, s.created_at) <= :date_to";
            $params[':date_to'] = $dateTo;
        }

        // Bounded pre-search set; names need decryption before matching so
        // pagination happens after the full filtered set is known.
        $cap = 5000;
        $stmt = $pdo->prepare("
            SELECT s.*, $unlinkedReady AS unlinked_ready
            FROM scanner_incoming_scans s
            WHERE $where
            ORDER BY COALESCE(s.scan_at, s.created_at) DESC, s.id DESC
            LIMIT $cap
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) >= $cap;

        if ($q !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($q) {
                $haystacks = [
                    (string)($row['external_scan_id'] ?? ''),
                    (string)$row['id'],
                    (string)(ScanIngestion::decryptField($row['patient_first_name']) ?? ''),
                    (string)(ScanIngestion::decryptField($row['patient_last_name']) ?? ''),
                ];
                foreach ($haystacks as $h) {
                    if ($h !== '' && mb_stripos($h, $q) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $total = count($rows);
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        $scans = [];
        foreach ($pageRows as $row) {
            $scans[] = self::serializeScan($pdo, $row, $userId, true);
        }

        return [
            'scans'     => $scans,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'counts'    => self::viewCounts($pdo, $practiceId),
            'vendors'   => self::practiceVendors($pdo, $practiceId),
            'truncated' => $truncated,
        ];
    }

    private static function boundDate($raw, bool $endOfDay): ?string {
        $raw = trim((string)$raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return $raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    /** Per-view totals for tab badges. */
    private static function viewCounts(PDO $pdo, int $practiceId): array {
        $unlinkedReady = "EXISTS(SELECT 1 FROM scanner_scan_files f WHERE f.scan_id = s.id AND f.status = 'ready' AND f.attached_case_id IS NULL)";
        $stmt = $pdo->prepare("
            SELECT s.status, COUNT(*) AS n, SUM($unlinkedReady) AS partial
            FROM scanner_incoming_scans s
            WHERE s.practice_id = :pid
            GROUP BY s.status
        ");
        $stmt->execute([':pid' => $practiceId]);
        $counts = ['attention' => 0, 'linked' => 0, 'dismissed' => 0, 'all' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = (int)$row['n'];
            $status = (string)$row['status'];
            $counts['all'] += $n;
            if ($status === 'linked') {
                $counts['linked'] += $n;
                $counts['attention'] += (int)$row['partial'];
            } elseif ($status === 'dismissed') {
                $counts['dismissed'] += $n;
            } else {
                $counts['attention'] += $n;
            }
        }
        return $counts;
    }

    /** Vendor keys actually present for this practice, with display labels. */
    private static function practiceVendors(PDO $pdo, int $practiceId): array {
        $stmt = $pdo->prepare("SELECT DISTINCT vendor FROM scanner_incoming_scans WHERE practice_id = :pid ORDER BY vendor");
        $stmt->execute([':pid' => $practiceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower((string)$row['vendor']);
            $out[] = ['key' => $key, 'label' => ScanIngestion::VENDORS[$key] ?? $key];
        }
        return $out;
    }

    /**
     * Full detail for one scan owned by the practice (null when absent).
     */
    public static function getDetail(PDO $pdo, int $practiceId, int $scanId, ?int $userId = null): ?array {
        $row = ScanIngestion::findScanForPractice($pdo, $practiceId, $scanId);
        return $row ? self::serializeScan($pdo, $row, $userId, true) : null;
    }

    /**
     * Serialize a scan row for API output. Patient fields are decrypted here
     * (authorized practice scope); nothing PHI reaches events/logs.
     */
    private static function serializeScan(PDO $pdo, array $row, ?int $userId, bool $withFiles): array {
        $scanId = (int)$row['id'];
        $files = [];
        $attachableCount = 0;
        if ($withFiles) {
            foreach (ScanIngestion::listFiles($pdo, $scanId) as $file) {
                $files[] = self::serializeFile($file);
                if ($file['status'] === ScanIngestion::FILE_READY && $file['attached_case_id'] === null) {
                    $attachableCount++;
                }
            }
        }

        $dismissed = $row['status'] === ScanIngestion::SCAN_DISMISSED;
        $vendor = strtolower((string)$row['vendor']);

        // Linked-case display: resolve a readable reference (patient name +
        // case id). A deleted/inaccessible case reports a missing flag so
        // the UI can degrade without exposing anything.
        $linkedCase = null;
        $isLinked = $row['status'] === ScanIngestion::SCAN_LINKED;
        if ($isLinked && !empty($row['case_id'])) {
            $stmt = $pdo->prepare("SELECT case_id, patient_first_name, patient_last_name FROM cases_cache WHERE case_id = :cid AND practice_id = :pid LIMIT 1");
            $stmt->execute([':cid' => (string)$row['case_id'], ':pid' => (int)$row['practice_id']]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($case) {
                $cFirst = ScanIngestion::decryptField($case['patient_first_name']);
                $cLast = ScanIngestion::decryptField($case['patient_last_name']);
                $patientLabel = trim(($cFirst ?? '') . ' ' . ($cLast ?? ''));
                $linkedCase = [
                    'id'           => $case['case_id'],
                    'patientName'  => $patientLabel !== '' ? $patientLabel : null,
                    'exists'       => true,
                ];
            } else {
                $linkedCase = ['id' => null, 'patientName' => null, 'exists' => false];
            }
        }

        return [
            'id'                 => $scanId,
            'vendor'             => $vendor,
            'vendorLabel'        => ScanIngestion::VENDORS[$vendor] ?? $vendor,
            'externalScanId'     => $row['external_scan_id'],
            'externalPatientRef' => $row['external_patient_ref'],
            'patientFirstName'   => ScanIngestion::decryptField($row['patient_first_name']),
            'patientLastName'    => ScanIngestion::decryptField($row['patient_last_name']),
            'patientDob'         => ScanIngestion::decryptField($row['patient_dob']),
            'scanAt'             => $row['scan_at'],
            'instructions'       => ScanIngestion::decryptField($row['instructions']),
            'status'             => $row['status'],
            'errorCode'          => $row['error_code'],
            'caseId'             => $row['case_id'],
            'isSynthetic'        => !empty($row['is_synthetic']),
            'isDismissed'        => $dismissed,
            'isLinked'           => $isLinked,
            'isPartiallyLinked'  => $isLinked && $attachableCount > 0,
            'linkedCase'         => $linkedCase,
            'attachableFiles'    => $attachableCount,
            'createdAt'          => $row['created_at'],
            'linkedAt'           => $row['linked_at'],
            'dismissedAt'        => $row['dismissed_at'],
            'files'              => $files,
            'suggestedCases'     => self::suggestCases($pdo, (int)$row['practice_id'], $row),
        ];
    }

    private static function serializeFile(array $row): array {
        return [
            'id'             => (int)$row['id'],
            'externalFileId' => $row['external_file_id'],
            'revision'       => $row['revision'],
            'fileName'       => $row['file_name'],
            'contentType'    => $row['content_type'],
            'fileRole'       => $row['file_role'] ?? null,
            'declaredSize'   => $row['declared_size'] !== null ? (int)$row['declared_size'] : null,
            'status'         => $row['status'],
            'errorCode'      => $row['error_code'],
            'downloadAttempts' => (int)$row['download_attempts'],
            'attachedCaseId' => $row['attached_case_id'],
            'attachedAt'     => $row['attached_at'],
        ];
    }

    /**
     * Reliable-match suggestions: cases imported from a PMS connection of
     * THIS practice whose mapping external_parent_id equals the scan's
     * external patient reference. Never name-based.
     *
     * @return array [['caseId'=>..., 'patientName'=>...], ...]
     */
    private static function suggestCases(PDO $pdo, int $practiceId, array $scan): array {
        $patientRef = trim((string)($scan['external_patient_ref'] ?? ''));
        if ($patientRef === '') {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT m.internal_id AS case_id
                FROM integration_entity_mappings m
                JOIN integration_connections c ON c.id = m.connection_id
                WHERE c.practice_id = :practice_id
                  AND m.entity_type = 'case'
                  AND m.external_parent_id = :patient_ref
                LIMIT 10
            ");
            $stmt->execute([
                ':practice_id' => $practiceId,
                ':patient_ref' => $patientRef,
            ]);
            $caseIds = array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'case_id'));
            if (!$caseIds) {
                return [];
            }
            // Confirm the mapped cases still exist in this practice and the
            // caller could see them; decrypt names for display.
            $suggestions = [];
            foreach ($caseIds as $caseId) {
                $stmt = $pdo->prepare("SELECT case_id, patient_first_name, patient_last_name FROM cases_cache WHERE case_id = :id AND practice_id = :pid LIMIT 1");
                $stmt->execute([':id' => $caseId, ':pid' => $practiceId]);
                $case = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$case) {
                    continue;
                }
                $first = ScanIngestion::decryptField($case['patient_first_name']);
                $last  = ScanIngestion::decryptField($case['patient_last_name']);
                $suggestions[] = [
                    'caseId'      => $case['case_id'],
                    'patientName' => trim(($first ?? '') . ' ' . ($last ?? '')),
                ];
            }
            return $suggestions;
        } catch (Throwable $e) {
            error_log('[ScanInbox] suggestion lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    // ----------------------------------------------------------------------
    // Dismiss / restore
    // ----------------------------------------------------------------------

    /**
     * Park a scan out of the inbox. Nothing is deleted or sent anywhere.
     * Idempotent: dismissing an already-dismissed scan is a success no-op.
     */
    public static function dismiss(PDO $pdo, int $practiceId, int $userId, int $scanId): array {
        $scan = ScanIngestion::findScanForPractice($pdo, $practiceId, $scanId);
        if (!$scan) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($scan['status'] === ScanIngestion::SCAN_LINKED) {
            return ['success' => false, 'error' => 'already_linked'];
        }
        if ($scan['status'] === ScanIngestion::SCAN_DISMISSED) {
            return ['success' => true, 'status' => ScanIngestion::SCAN_DISMISSED];
        }
        $stmt = $pdo->prepare("
            UPDATE scanner_incoming_scans
            SET status = :dismissed, dismissed_by_user_id = :uid, dismissed_at = NOW()
            WHERE id = :id AND practice_id = :pid AND status <> :linked
        ");
        $stmt->execute([
            ':dismissed' => ScanIngestion::SCAN_DISMISSED,
            ':uid'       => $userId,
            ':id'        => $scanId,
            ':pid'       => $practiceId,
            ':linked'    => ScanIngestion::SCAN_LINKED,
        ]);
        ScanIngestion::recordEvent($pdo, $scanId, $practiceId, $userId, 'dismissed');
        return ['success' => true, 'status' => ScanIngestion::SCAN_DISMISSED];
    }

    /**
     * Return a dismissed scan to the workflow (status recomputed from files).
     */
    public static function restore(PDO $pdo, int $practiceId, int $userId, int $scanId): array {
        $scan = ScanIngestion::findScanForPractice($pdo, $practiceId, $scanId);
        if (!$scan) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($scan['status'] !== ScanIngestion::SCAN_DISMISSED) {
            return ['success' => true, 'status' => $scan['status']];
        }
        $pdo->prepare("
            UPDATE scanner_incoming_scans
            SET status = :received, dismissed_by_user_id = NULL, dismissed_at = NULL
            WHERE id = :id AND practice_id = :pid AND status = :dismissed
        ")->execute([
            ':received'  => ScanIngestion::SCAN_RECEIVED,
            ':id'        => $scanId,
            ':pid'       => $practiceId,
            ':dismissed' => ScanIngestion::SCAN_DISMISSED,
        ]);
        ScanIngestion::refreshScanStatus($pdo, $scanId);
        ScanIngestion::recordEvent($pdo, $scanId, $practiceId, $userId, 'restored');
        $fresh = ScanIngestion::findScanForPractice($pdo, $practiceId, $scanId);
        return ['success' => true, 'status' => $fresh['status'] ?? ScanIngestion::SCAN_RECEIVED];
    }

    // ----------------------------------------------------------------------
    // Attach to case
    // ----------------------------------------------------------------------

    /**
     * Attach a scan's ready files to an existing case in the same practice.
     *
     * Idempotent contract:
     *  - scan already linked to $caseId            -> success (no dup work)
     *  - scan linked to a different case           -> 409 conflict
     *  - per-file conditional claim + attachment metadata dedupe by
     *    scanFileId make concurrent submits converge to one outcome
     *
     * @return array success | {success:false, error:code} | {success:false,
     *               conflict:true}  (caller maps codes to HTTP status)
     */
    public static function attachToCase(PDO $pdo, int $practiceId, int $userId, int $scanId, string $caseId, bool $appendInstructions = false): array {
        // --- Validate scan ownership/state under a row lock.
        $pdo->beginTransaction();
        try {
            $scanStmt = $pdo->prepare("SELECT * FROM scanner_incoming_scans WHERE id = :id AND practice_id = :pid FOR UPDATE");
            $scanStmt->execute([':id' => $scanId, ':pid' => $practiceId]);
            $scan = $scanStmt->fetch(PDO::FETCH_ASSOC);

            if (!$scan) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'not_found'];
            }
            $alreadyLinkedHere = false;
            if ($scan['status'] === ScanIngestion::SCAN_LINKED) {
                $linkedCase = (string)$scan['case_id'];
                if ($linkedCase !== $caseId) {
                    $pdo->commit();
                    return ['success' => false, 'conflict' => true, 'error' => 'already_linked', 'caseId' => $linkedCase];
                }
                // Linked to THIS case: keep going - a revised/rescan file
                // may have arrived after linking and still needs delivery.
                $alreadyLinkedHere = true;
            }
            if ($scan['status'] === ScanIngestion::SCAN_DISMISSED) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'dismissed'];
            }

            // --- Validate the target case inside the same practice.
            $caseStmt = $pdo->prepare("SELECT * FROM cases_cache WHERE case_id = :cid AND practice_id = :pid LIMIT 1");
            $caseStmt->execute([':cid' => $caseId, ':pid' => $practiceId]);
            $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC);
            if (!$caseRow) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'case_not_found'];
            }
            // Per-case access (assigned-only users may only touch assigned cases).
            if (!canUserAccessCase($caseRow, $practiceId, $userId)) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'case_access_denied'];
            }

            // --- Select attachable files: ready, not superseded, and either
            //     unattached or already claimed by THIS case (a previous
            //     attach that failed mid-merge left bytes at the case path
            //     but no metadata row - including it again lets the retry
            //     converge instead of deadlocking on no_ready_files).
            //     Latest revision per external_file_id wins.
            $fileStmt = $pdo->prepare("
                SELECT * FROM scanner_scan_files
                WHERE scan_id = :sid AND status = :ready
                  AND (attached_case_id IS NULL OR attached_case_id = :cid)
                ORDER BY id DESC
            ");
            $fileStmt->execute([':sid' => $scanId, ':ready' => ScanIngestion::FILE_READY, ':cid' => $caseId]);
            $candidates = [];
            foreach ($fileStmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $key = (string)$file['external_file_id'];
                if (!isset($candidates[$key])) { // first row per file = newest revision
                    $candidates[$key] = $file;
                }
            }

            if (!$candidates) {
                $pdo->commit();
                if ($alreadyLinkedHere) {
                    return ['success' => true, 'caseId' => $caseId, 'alreadyLinked' => true];
                }
                return ['success' => false, 'error' => 'no_ready_files'];
            }

            $pdo->commit(); // state validated; file moves happen next
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ScanInbox] attach validation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'internal'];
        }

        // --- Move bytes into the case-owned key and claim each file row.
        //     Claim is conditional so a concurrent attach converges instead
        //     of double-appending metadata.
        $attachments = [];
        $missingBytes = 0;
        foreach ($candidates as $file) {
            $fileId = (int)$file['id'];
            if ($file['attached_case_id'] === $caseId) {
                // Claimed by an earlier attach attempt to this same case but
                // the metadata merge never completed. Verify what actually
                // happened to the bytes before rebuilding the record:
                //  - still on the pending key  -> the move never ran;
                //    complete it now (moveToCase is idempotent).
                //  - on the case key and the object exists -> merge was the
                //    only missed step; rebuild the attachment record.
                //  - object missing -> do NOT record a phantom attachment:
                //    release the claim and reset the row to pending so the
                //    Retry action can re-download it from the connector.
                $storedPath = (string)$file['storage_path'];
                $segments = explode('/', $storedPath);
                $stillPending = count($segments) === 5
                    && isset($segments[2])
                    && strpos($segments[2], 'pending_scan_') === 0;
                if ($stillPending) {
                    try {
                        $newPath = ScanFileStore::moveToCase(
                            $storedPath,
                            (string)$file['storage_driver'],
                            $practiceId,
                            $caseId
                        );
                    } catch (Throwable $e) {
                        $pdo->prepare("UPDATE scanner_scan_files SET attached_case_id = NULL, attached_at = NULL WHERE id = :id AND attached_case_id = :cid")
                            ->execute([':id' => $fileId, ':cid' => $caseId]);
                        error_log('[ScanInbox] finishing interrupted move failed for file ' . $fileId . ': ' . $e->getMessage());
                        return ['success' => false, 'error' => 'storage_error'];
                    }
                    $pdo->prepare("UPDATE scanner_scan_files SET storage_path = :p WHERE id = :id")
                        ->execute([':p' => $newPath, ':id' => $fileId]);
                } else {
                    // Trust the stored path only when it both belongs to this
                    // case's key space and the object actually exists. A path
                    // that points at another case, or at nothing, is treated
                    // as lost bytes rather than recorded as an attachment.
                    $destOk = count($segments) === 5
                        && $segments[0] === 'cases'
                        && $segments[1] === (string)$practiceId
                        && $segments[2] === (string)$caseId
                        && ScanFileStore::exists((string)$file['storage_driver'], $storedPath);
                    if ($destOk) {
                        $newPath = $storedPath;
                    } else {
                        // Destination bytes are gone or the pointer is
                        // untrustworthy. Reset to pending + unclaimed so Retry
                        // re-downloads the file; the attach reports failure
                        // instead of recording an attachment that points at
                        // nothing.
                        $pdo->prepare("
                            UPDATE scanner_scan_files
                            SET attached_case_id = NULL, attached_at = NULL,
                                status = :pending, storage_driver = NULL,
                                storage_path = NULL, checksum_sha256 = NULL
                            WHERE id = :id AND attached_case_id = :cid
                        ")->execute([':id' => $fileId, ':cid' => $caseId, ':pending' => ScanIngestion::FILE_PENDING]);
                        $missingBytes++;
                        error_log('[ScanInbox] claimed file ' . $fileId . ' has no stored object at the case key; reset to pending for re-download');
                        continue;
                    }
                }
            } else {
                $claimed = $pdo->prepare("
                    UPDATE scanner_scan_files
                    SET attached_case_id = :case_id, attached_at = NOW()
                    WHERE id = :id AND attached_case_id IS NULL AND status = :ready
                ");
                $claimed->execute([':case_id' => $caseId, ':id' => $fileId, ':ready' => ScanIngestion::FILE_READY]);
                if ($claimed->rowCount() === 0) {
                    continue; // another attach took it, or it was superseded
                }
                try {
                    $newPath = ScanFileStore::moveToCase(
                        (string)$file['storage_path'],
                        (string)$file['storage_driver'],
                        $practiceId,
                        $caseId
                    );
                } catch (Throwable $e) {
                    // Roll the claim back so the file stays attachable on retry.
                    $pdo->prepare("UPDATE scanner_scan_files SET attached_case_id = NULL, attached_at = NULL WHERE id = :id AND attached_case_id = :cid")
                        ->execute([':id' => $fileId, ':cid' => $caseId]);
                    error_log('[ScanInbox] file move failed for file ' . $fileId . ': ' . $e->getMessage());
                    return ['success' => false, 'error' => 'storage_error'];
                }
                $pdo->prepare("UPDATE scanner_scan_files SET storage_path = :p WHERE id = :id")
                    ->execute([':p' => $newPath, ':id' => $fileId]);
            }

            $attachments[] = [
                'id'            => 'scanfile_' . $fileId,
                'scanFileId'    => $fileId,
                'source'        => 'scanner',
                // Attachment records store the ucfirst'ed category form the
                // case modal's typeMapping expects ('photos' -> 'Photos').
                'type'          => 'IntraoralScans',
                'fileName'      => (string)$file['file_name'],
                'name'          => (string)$file['file_name'],
                'mimeType'      => $file['content_type'] ?: 'application/octet-stream',
                'fileType'      => $file['content_type'] ?: 'application/octet-stream',
                'size'          => $file['declared_size'] !== null ? (int)$file['declared_size'] : null,
                'storagePath'   => $newPath,
                'storageType'   => (string)$file['storage_driver'],
                'path'          => $newPath,
                'uploadedAt'    => gmdate('c'),
                // Provenance (all optional, all already known-safe values):
                // vendor + external ids let the case view say where the
                // file came from; scan_at is the vendor's scan timestamp,
                // importedAt is when it arrived in the inbox - distinct.
                'vendor'         => (string)$scan['vendor'],
                'vendorLabel'    => ScanIngestion::VENDORS[strtolower((string)$scan['vendor'])] ?? (string)$scan['vendor'],
                'externalScanId' => $scan['external_scan_id'],
                'scanAt'         => $scan['scan_at'],
                'importedAt'     => $scan['created_at'],
                'fileRole'       => $file['file_role'] ?? null,
                'revision'       => $file['revision'],
            ];
        }

        if (!$attachments) {
            if ($missingBytes > 0) {
                // Every candidate lost its stored object and was reset to
                // pending - report a recoverable failure, not a link that
                // delivered zero files.
                return ['success' => false, 'error' => 'storage_error'];
            }
            // Everything was claimed by a concurrent attach - converge: mark
            // the scan linked to whichever case now owns the files.
            return self::finalizeAttach($pdo, $practiceId, $userId, $scanId, $caseId, false);
        }

        // --- Append attachment metadata under the case's optimistic lock.
        $merged = self::mergeAttachmentsIntoCase($pdo, $practiceId, $caseId, $attachments, $appendInstructions ? $scan['instructions'] ?? null : null);
        if (!$merged['success']) {
            return $merged;
        }

        return self::finalizeAttach($pdo, $practiceId, $userId, $scanId, $caseId, $appendInstructions);
    }

    /**
     * Merge scan attachment metadata into cases_cache.attachments_json with
     * optimistic concurrency (retries a bounded number of times on version
     * conflict, re-reading and re-merging so attachments from concurrent
     * attaches are preserved rather than lost).
     */
    private static function mergeAttachmentsIntoCase(PDO $pdo, int $practiceId, string $caseId, array $newAttachments, $encryptedInstructions): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stmt = $pdo->prepare("SELECT attachments_json, notes, version FROM cases_cache WHERE case_id = :cid AND practice_id = :pid LIMIT 1");
            $stmt->execute([':cid' => $caseId, ':pid' => $practiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'case_not_found'];
            }
            $existing = json_decode($row['attachments_json'] ?? '[]', true);
            if (!is_array($existing)) {
                $existing = [];
            }

            // Dedupe by scanFileId - a retried attach adds nothing new.
            $present = [];
            foreach ($existing as $att) {
                if (isset($att['scanFileId'])) {
                    $present[(int)$att['scanFileId']] = true;
                }
            }
            $toAdd = [];
            foreach ($newAttachments as $att) {
                if (!isset($present[(int)$att['scanFileId']])) {
                    $toAdd[] = $att;
                }
            }
            if (!$toAdd && !$encryptedInstructions) {
                return ['success' => true, 'alreadyPresent' => true];
            }

            $mergedAttachments = array_merge($existing, $toAdd);

            // Notes: only ever APPEND imported instructions on explicit opt-in,
            // after decrypting the stored field. Existing text is preserved.
            $notes = $row['notes'];
            if ($encryptedInstructions) {
                $imported = ScanIngestion::decryptField($encryptedInstructions);
                if ($imported !== null && $imported !== '') {
                    $currentNotes = ScanIngestion::decryptField($notes) ?? '';
                    $marker = function_exists('t')
                        ? t('scanner.instructions_imported_header')
                        : 'Imported scanner instructions:';
                    if (strpos($currentNotes, $imported) === false) {
                        $combined = trim($currentNotes . "\n\n" . $marker . " " . $imported);
                        $notes = PIIEncryption::encrypt($combined);
                    }
                }
            }

            $upd = $pdo->prepare("
                UPDATE cases_cache
                SET attachments_json = :attachments, notes = :notes,
                    last_update_date = :updated, version = version + 1
                WHERE case_id = :cid AND practice_id = :pid AND version = :expected
            ");
            $upd->execute([
                ':attachments' => json_encode(array_values($mergedAttachments)),
                ':notes'       => $notes,
                ':updated'     => gmdate('Y-m-d H:i:s'),
                ':cid'         => $caseId,
                ':pid'         => $practiceId,
                ':expected'    => (int)$row['version'],
            ]);
            if ($upd->rowCount() > 0) {
                return ['success' => true, 'attachmentsAdded' => count($toAdd)];
            }
            // Version conflict: loop re-reads and re-merges.
        }
        return ['success' => false, 'error' => 'case_conflict'];
    }

    /**
     * Mark the scan linked (conditional: only if not already linked by a
     * concurrent request). If a concurrent attach linked it to a different
     * case, report the conflict.
     */
    private static function finalizeAttach(PDO $pdo, int $practiceId, int $userId, int $scanId, string $caseId, bool $appendedInstructions): array {
        $upd = $pdo->prepare("
            UPDATE scanner_incoming_scans
            SET status = :linked, case_id = :case_id, linked_by_user_id = :uid, linked_at = NOW()
            WHERE id = :id AND practice_id = :pid AND status <> :linked_state
        ");
        $upd->execute([
            ':linked'       => ScanIngestion::SCAN_LINKED,
            ':case_id'      => $caseId,
            ':uid'          => $userId,
            ':id'           => $scanId,
            ':pid'          => $practiceId,
            ':linked_state' => ScanIngestion::SCAN_LINKED,
        ]);

        $fresh = ScanIngestion::findScanForPractice($pdo, $practiceId, $scanId);
        if ($upd->rowCount() === 0) {
            // Already linked - converge or report conflict.
            if ($fresh && (string)$fresh['case_id'] === $caseId) {
                return ['success' => true, 'caseId' => $caseId, 'alreadyLinked' => true];
            }
            return ['success' => false, 'conflict' => true, 'error' => 'already_linked',
                    'caseId' => $fresh['case_id'] ?? null];
        }

        ScanIngestion::recordEvent($pdo, $scanId, $practiceId, $userId, 'linked',
            'case=' . $caseId . ($appendedInstructions ? ' instructions_appended=1' : ''));
        return ['success' => true, 'caseId' => $caseId];
    }
}
