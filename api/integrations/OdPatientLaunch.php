<?php
/**
 * Patient-scoped launch resolution for the Open Dental toolbar bridge.
 *
 * The Program Links / Custom Bridge button opens DentaTrak with the selected
 * patient's PatNum (od-launch.php?pat=<PatNum>). The PatNum is ONLY a lookup
 * hint - it never authenticates anything. Resolution happens here, server
 * side, strictly inside the caller's already-verified practice context:
 *
 *   practice -> integration connection -> 'patient' mapping (PatNum)
 *       -> 'case' mappings -> DentaTrak case ids
 *
 * Only non-PHI data flows through this class: external ids, internal case
 * ids, and (for the banner) the patient display name copied off the case
 * records the user is already authorized to see.
 */

require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/../encryption.php';

final class OdPatientLaunch {

    public const STATUS_OK             = 'ok';
    public const STATUS_NO_INTEGRATION = 'no_integration'; // practice has no OD connection
    public const STATUS_NO_PATIENT     = 'no_patient';     // PatNum never observed in sync
    public const STATUS_NO_CASES       = 'no_cases';       // patient mapped, no live cases
    public const STATUS_INVALID        = 'invalid';        // malformed PatNum

    /**
     * Resolve an Open Dental PatNum to the DentaTrak case ids imported for
     * that patient under the given practice's connection.
     *
     * @return array{
     *   status: string,
     *   case_ids: string[],
     *   patient_name: string,
     *   case_count: int
     * }
     */
    public static function resolvePatientCases(PDO $pdo, int $practiceId, string $patNum): array {
        $empty = [
            'status'       => self::STATUS_NO_PATIENT,
            'case_ids'     => [],
            'patient_name' => '',
            'case_count'   => 0,
        ];

        if (!preg_match('/^\d{1,20}$/', $patNum)) {
            $empty['status'] = self::STATUS_INVALID;
            return $empty;
        }

        // One connection per (practice, provider) is enforced by
        // uk_integration_practice_provider. Status is intentionally not
        // filtered - a disabled/error connection still owns mappings for
        // cases that remain in the practice's case list.
        $stmt = $pdo->prepare("
            SELECT id FROM integration_connections
            WHERE practice_id = :practice_id AND provider = 'open_dental'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':practice_id' => $practiceId]);
        $connectionId = $stmt->fetchColumn();

        if ($connectionId === false) {
            $empty['status'] = self::STATUS_NO_INTEGRATION;
            return $empty;
        }
        $connectionId = (int)$connectionId;

        // A resolver call only happens through the od-launch.php patient
        // launch flow, so reaching this point with a real connection means
        // a toolbar-style launch arrived. Stamp the first one; subsequent
        // launches keep the original timestamp. Non-authoritative - a URL
        // parameter can be supplied manually - so the UI must present this
        // as "first launch detected", never "installation verified".
        self::recordToolbarLaunch($pdo, $connectionId);

        // The 'patient' support mapping is reserved at import time for every
        // imported case, so its absence means this PatNum was never synced.
        $patientMapping = SyncEngine::findMapping(
            $pdo, $connectionId, SyncEngine::ENTITY_PATIENT, $patNum
        );
        if ($patientMapping === null) {
            return $empty;
        }

        // Case mappings stamped with external_parent_id = PatNum (all
        // imports/updates after this feature) are the primary path. The
        // patient mapping's own external_parent_id (the first-seen
        // LabCaseNum) is kept as a fallback so legacy rows that predate the
        // stamp still resolve their single case.
        $stmt = $pdo->prepare("
            SELECT internal_id FROM integration_entity_mappings
            WHERE connection_id = :connection_id
              AND entity_type = :entity_type
              AND external_parent_id = :pat
              AND internal_id IS NOT NULL
        ");
        $stmt->execute([
            ':connection_id' => $connectionId,
            ':entity_type'   => SyncEngine::ENTITY_CASE,
            ':pat'           => $patNum,
        ]);
        $caseIds = array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        $legacyLabCase = (string)($patientMapping['external_parent_id'] ?? '');
        if ($legacyLabCase !== '') {
            $legacy = SyncEngine::findMapping(
                $pdo, $connectionId, SyncEngine::ENTITY_CASE, $legacyLabCase
            );
            if ($legacy && !empty($legacy['internal_id'])
                && !in_array((string)$legacy['internal_id'], $caseIds, true)) {
                $caseIds[] = (string)$legacy['internal_id'];
            }
        }

        if (empty($caseIds)) {
            // The patient is known to the integration but no case mapping
            // links to them (e.g. mappings imported before the patient
            // back-link existed, and the first-case fallback missed).
            $empty['status'] = self::STATUS_NO_CASES;
            return $empty;
        }

        // Confirm the cases are live rows in this practice and pull the
        // patient display name from the case data itself (already visible
        // to this user in the case list). Archived/deleted rows drop out
        // here so the banner count matches what the board will render.
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $params = array_merge([$practiceId], array_values($caseIds));
        $stmt = $pdo->prepare("
            SELECT case_id, patient_first_name, patient_last_name
            FROM cases_cache
            WHERE practice_id = ?
              AND case_id IN ({$placeholders})
              AND (archived = 0 OR archived IS NULL)
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $liveIds = [];
        $patientName = '';
        foreach ($rows as $row) {
            $liveIds[] = (string)$row['case_id'];
            if ($patientName === '') {
                $patientName = self::patientDisplayName($row);
            }
        }

        if (empty($liveIds)) {
            $empty['status'] = self::STATUS_NO_CASES;
            return $empty;
        }

        return [
            'status'       => self::STATUS_OK,
            'case_ids'     => array_values($liveIds),
            'patient_name' => $patientName,
            'case_count'   => count($liveIds),
        ];
    }

    /**
     * Stamp toolbar_first_launch_at on the connection's config_json the
     * first time a patient launch is observed. Idempotent - only writes
     * when the key is absent. Failure is non-fatal to resolution.
     */
    public static function recordToolbarLaunch(PDO $pdo, int $connectionId): void {
        try {
            $stmt = $pdo->prepare(
                "SELECT config_json FROM integration_connections WHERE id = :id"
            );
            $stmt->execute([':id' => $connectionId]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                return;
            }
            $config = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($config)) {
                $config = [];
            }
            if (!empty($config['toolbar_first_launch_at'])) {
                return;
            }
            $config['toolbar_first_launch_at'] = gmdate('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE integration_connections SET config_json = :cfg WHERE id = :id"
            )->execute([':cfg' => json_encode($config), ':id' => $connectionId]);
        } catch (Throwable $e) {
            // Non-critical - detection must never break the launch itself.
        }
    }

    /**
     * Build the banner display name from a cases_cache row. Fields are
     * PII-encrypted at rest; decryptCaseData handles the decode and blanks
     * any field that fails rather than leaking ciphertext.
     */
    private static function patientDisplayName(array $row): string {
        $case = [
            'patientFirstName' => $row['patient_first_name'] ?? null,
            'patientLastName'  => $row['patient_last_name'] ?? null,
        ];
        try {
            $case = PIIEncryption::decryptCaseData($case);
        } catch (Throwable $e) {
            $case['patientFirstName'] = '';
            $case['patientLastName']  = '';
        }
        return trim(trim((string)($case['patientFirstName'] ?? ''))
            . ' ' . trim((string)($case['patientLastName'] ?? '')));
    }
}
