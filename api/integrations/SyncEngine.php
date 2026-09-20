<?php
/**
 * SyncEngine
 *
 * Generic primitives for PMS synchronization runs: entity-mapping
 * deduplication, sync-run lifecycle bookkeeping, and PHI-safe per-entity
 * event logging. Provider-agnostic - nothing here knows about Open Dental.
 *
 * PHASE A SCOPE: this class deliberately does NOT create or modify
 * DentaTrak cases, does not touch cases_cache, does not call any PMS, and
 * does not emit notifications. It exists so later phases get dedup,
 * locking, and audit logging correct from day one.
 *
 * DEDUP GUARANTEE:
 *  UNIQUE(connection_id, entity_type, external_id) on
 *  integration_entity_mappings is the primary protection. reserveMapping()
 *  inserts FIRST (before any internal record exists), so a losing racer
 *  gets a duplicate-key error and falls back to the winner's row - the same
 *  external entity can never be imported twice for one connection.
 *  internal_id stays NULL until attachInternalId() fills it once the real
 *  record exists; no placeholder IDs are ever written.
 *
 * PHI CONTRACT:
 *  recordEvent() mechanically rejects detail arrays containing PHI- or
 *  credential-named keys and non-scalar values, and caps payload size.
 *  Messages are caller-supplied; keep them to IDs, counts, and HTTP/status
 *  codes - never names, DOBs, note text, or raw API payloads.
 */

class SyncEngine {

    // Entity types (VARCHAR in schema - new types need no migration).
    public const ENTITY_CASE        = 'case';
    public const ENTITY_PATIENT     = 'patient';
    public const ENTITY_PROVIDER    = 'provider';
    public const ENTITY_LABORATORY  = 'laboratory';
    public const ENTITY_APPOINTMENT = 'appointment';

    // Event actions (free-form VARCHAR; constants for the common ones).
    public const ACTION_DISCOVERED        = 'discovered';
    public const ACTION_MAPPING_FOUND     = 'mapping_found';
    public const ACTION_MAPPING_RESERVED  = 'mapping_reserved';
    public const ACTION_CREATED           = 'created';
    public const ACTION_UPDATED           = 'updated';
    public const ACTION_SKIPPED           = 'skipped';
    public const ACTION_UNMAPPED          = 'unmapped';
    public const ACTION_ERROR             = 'error';

    // Run types.
    public const RUN_SCHEDULED = 'scheduled';
    public const RUN_MANUAL    = 'manual';
    public const RUN_BACKFILL  = 'backfill';

    // Run statuses.
    public const RUN_STATUS_RUNNING               = 'running';
    public const RUN_STATUS_COMPLETED             = 'completed';
    public const RUN_STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const RUN_STATUS_FAILED                = 'failed';

    private const VALID_COUNTERS = [
        'cases_seen', 'cases_created', 'cases_updated', 'cases_skipped', 'errors_count',
    ];

    /** Max encoded size for event detail_json - prevents payload dumps. */
    private const MAX_DETAIL_BYTES = 4000;

    /**
     * Detail keys matching this pattern are rejected as potential
     * PHI/credential leakage. Deliberately broad on the credential side.
     */
    private const FORBIDDEN_DETAIL_KEY_PATTERN =
        '/patient|dob|birth|ssn|phi|note|instruct|diagnos|cred|secret|api_?key|token|passw/i';

    // ----------------------------------------------------------------------
    // Entity mappings - the dedup backbone
    // ----------------------------------------------------------------------

    /**
     * Look up the mapping for one external entity on a connection.
     * Returns the row or null.
     */
    public static function findMapping(PDO $pdo, int $connectionId, string $entityType, string $externalId): ?array {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_entity_mappings
            WHERE connection_id = :connection_id
              AND entity_type = :entity_type
              AND external_id = :external_id
            LIMIT 1
        ");
        $stmt->execute([
            ':connection_id' => $connectionId,
            ':entity_type'   => $entityType,
            ':external_id'   => $externalId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reverse lookup: find the mapping for a known internal record
     * (e.g. "which external case does this DentaTrak case_id come from?").
     */
    public static function findMappingByInternalId(PDO $pdo, int $connectionId, string $entityType, string $internalId): ?array {
        $stmt = $pdo->prepare("
            SELECT * FROM integration_entity_mappings
            WHERE connection_id = :connection_id
              AND entity_type = :entity_type
              AND internal_id = :internal_id
            LIMIT 1
        ");
        $stmt->execute([
            ':connection_id' => $connectionId,
            ':entity_type'   => $entityType,
            ':internal_id'   => $internalId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reserve a mapping for an external entity. This is the
     * claim-before-create step that makes import idempotent: insert the row
     * first, and only create the DentaTrak record when this returns
     * created=true. A duplicate-key collision means another run/request
     * already claimed (or completed) the import - the existing mapping is
     * returned with created=false.
     *
     * internal_type/internal_id stay NULL for a pure reservation;
     * attachInternalId() fills them in afterwards. They may be supplied
     * here only when the internal record is already known to exist.
     *
     * @return array{mapping: array, created: bool}
     */
    public static function reserveMapping(
        PDO $pdo,
        int $connectionId,
        string $entityType,
        string $externalId,
        ?string $internalType = null,
        ?string $internalId = null,
        ?string $externalParentId = null,
        ?array $metadata = null
    ): array {
        if ($entityType === '' || $externalId === '') {
            throw new InvalidArgumentException('entity_type and external_id are required.');
        }
        if ($metadata !== null) {
            self::assertDetailSafe($metadata);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO integration_entity_mappings
                    (connection_id, entity_type, external_id, internal_type, internal_id, external_parent_id, metadata_json, last_seen_at)
                VALUES
                    (:connection_id, :entity_type, :external_id, :internal_type, :internal_id, :external_parent_id, :metadata_json, NOW())
            ");
            $stmt->execute([
                ':connection_id'      => $connectionId,
                ':entity_type'        => $entityType,
                ':external_id'        => $externalId,
                ':internal_type'      => $internalType,
                ':internal_id'        => $internalId,
                ':external_parent_id' => $externalParentId,
                ':metadata_json'      => $metadata !== null ? json_encode($metadata) : null,
            ]);

            $mapping = self::findMapping($pdo, $connectionId, $entityType, $externalId);
            return ['mapping' => $mapping, 'created' => true];
        } catch (PDOException $e) {
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }
            // Another run/request already reserved (or completed) this
            // entity - return the winner's row. This is the safe path for
            // concurrent workers and retries.
            $mapping = self::findMapping($pdo, $connectionId, $entityType, $externalId);
            return ['mapping' => $mapping, 'created' => false];
        }
    }

    /**
     * Fill in the internal record reference on an existing mapping after
     * the internal record has been created. Refuses to overwrite an
     * already-attached mapping (returns false) so a retry can never
     * silently re-point an external ID at a different internal record.
     */
    public static function attachInternalId(PDO $pdo, int $mappingId, string $internalType, string $internalId): bool {
        $stmt = $pdo->prepare("
            UPDATE integration_entity_mappings
            SET internal_type = :internal_type, internal_id = :internal_id
            WHERE id = :id AND internal_id IS NULL
        ");
        $stmt->execute([
            ':internal_type' => $internalType,
            ':internal_id'   => $internalId,
            ':id'            => $mappingId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Stamp last_seen_at = now for a mapping (call when the external entity
     * is observed during a sync pass; rows not touched for long spans are
     * candidates for upstream-deletion handling in later phases).
     */
    public static function touchMapping(PDO $pdo, int $mappingId): void {
        $stmt = $pdo->prepare("UPDATE integration_entity_mappings SET last_seen_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $mappingId]);
    }

    /**
     * Release a RESERVATION-ONLY mapping (internal_id IS NULL) so a failed
     * or orphaned import can retry cleanly. Never deletes a finalized
     * mapping - the WHERE clause makes that impossible. Returns true when
     * the row was removed.
     */
    public static function releaseMapping(PDO $pdo, int $mappingId): bool {
        $stmt = $pdo->prepare("
            DELETE FROM integration_entity_mappings
            WHERE id = :id AND internal_id IS NULL
        ");
        $stmt->execute([':id' => $mappingId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Decide what to do with an existing reservation-only mapping (claimed
     * but never attached to an internal record):
     *
     *   'in_flight'  - reserved recently; another worker is probably still
     *                  creating the record. Callers should retry later.
     *   'orphaned'   - older than $staleSeconds; the original creator died.
     *                  The reservation is released so this call can claim
     *                  the entity fresh.
     *   'finalized'  - internal_id present (defensive; callers normally
     *                  check that first).
     */
    public static function reservationState(PDO $pdo, array $mapping, int $staleSeconds = 900): string {
        if (!empty($mapping['internal_id'])) {
            return 'finalized';
        }
        // Age computed inside MySQL so it shares the same clock as
        // created_at regardless of PHP timezone configuration.
        $stmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM integration_entity_mappings WHERE id = :id
        ");
        $stmt->execute([':id' => (int)$mapping['id']]);
        $age = (int)$stmt->fetchColumn();
        if ($age >= $staleSeconds) {
            return 'orphaned';
        }
        return 'in_flight';
    }

    // ----------------------------------------------------------------------
    // Sync runs
    // ----------------------------------------------------------------------

    /**
     * Open a sync run. Returns the new run id.
     * $cursorStart is the watermark this run will sync from (typically the
     * connection's last_success_at); it is recorded, not interpreted here.
     */
    public static function beginRun(PDO $pdo, int $connectionId, string $runType = self::RUN_SCHEDULED, ?string $cursorStart = null): int {
        $stmt = $pdo->prepare("
            INSERT INTO integration_sync_runs (connection_id, run_type, status, cursor_start, started_at)
            VALUES (:connection_id, :run_type, :status, :cursor_start, NOW())
        ");
        $stmt->execute([
            ':connection_id' => $connectionId,
            ':run_type'      => $runType,
            ':status'        => self::RUN_STATUS_RUNNING,
            ':cursor_start'  => $cursorStart,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Increment one run counter. Counter names are whitelisted so callers
     * cannot inject arbitrary columns.
     */
    public static function incrementRunCounter(PDO $pdo, int $syncRunId, string $counter, int $by = 1): void {
        if (!in_array($counter, self::VALID_COUNTERS, true)) {
            throw new InvalidArgumentException('Unknown sync run counter.');
        }
        $by = max(1, $by);
        $stmt = $pdo->prepare("
            UPDATE integration_sync_runs SET {$counter} = {$counter} + :by WHERE id = :id
        ");
        $stmt->execute([':by' => $by, ':id' => $syncRunId]);
    }

    /**
     * Close a run as finished. Status becomes 'completed' or
     * 'completed_with_errors' depending on errors_count.
     */
    public static function completeRun(PDO $pdo, int $syncRunId, ?string $cursorEnd = null): void {
        $stmt = $pdo->prepare("
            UPDATE integration_sync_runs
            SET status = CASE WHEN errors_count > 0 THEN :status_errors ELSE :status_ok END,
                finished_at = NOW(),
                cursor_end = :cursor_end
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_errors' => self::RUN_STATUS_COMPLETED_WITH_ERRORS,
            ':status_ok'     => self::RUN_STATUS_COMPLETED,
            ':cursor_end'    => $cursorEnd,
            ':id'            => $syncRunId,
        ]);
    }

    /**
     * Close a run as failed (e.g. the PMS was unreachable). $errorSummary
     * is truncated and must be PHI-free/credential-free - the same contract
     * as event messages.
     */
    public static function failRun(PDO $pdo, int $syncRunId, string $errorSummary, ?string $cursorEnd = null): void {
        $stmt = $pdo->prepare("
            UPDATE integration_sync_runs
            SET status = :status,
                finished_at = NOW(),
                cursor_end = :cursor_end,
                error_summary = :error_summary
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'        => self::RUN_STATUS_FAILED,
            ':cursor_end'    => $cursorEnd,
            ':error_summary' => substr($errorSummary, 0, 1000),
            ':id'            => $syncRunId,
        ]);
    }

    // ----------------------------------------------------------------------
    // Sync events (PHI-free by enforcement)
    // ----------------------------------------------------------------------

    /**
     * Record one per-entity diagnostic event. connection_id is DERIVED from
     * the run row, never accepted from the caller - an event can never be
     * attributed to the wrong connection.
     *
     * Keep $message to IDs, counts, and status codes ("External case 12345
     * discovered", "HTTP 429 from provider"). $detail must be a flat map of
     * scalars with no PHI/credential-named keys; violations throw.
     */
    public static function recordEvent(
        PDO $pdo,
        int $syncRunId,
        string $entityType,
        ?string $externalId,
        string $action,
        ?string $caseId = null,
        ?string $message = null,
        ?array $detail = null
    ): int {
        if ($detail !== null) {
            self::assertDetailSafe($detail);
        }

        $run = $pdo->prepare("SELECT connection_id FROM integration_sync_runs WHERE id = :id LIMIT 1");
        $run->execute([':id' => $syncRunId]);
        $connectionId = $run->fetchColumn();
        if ($connectionId === false) {
            throw new RuntimeException('Cannot record sync event: parent sync run not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO integration_sync_events
                (sync_run_id, connection_id, entity_type, external_id, action, case_id, message, detail_json)
            VALUES
                (:sync_run_id, :connection_id, :entity_type, :external_id, :action, :case_id, :message, :detail_json)
        ");
        $stmt->execute([
            ':sync_run_id'   => $syncRunId,
            ':connection_id' => (int)$connectionId,
            ':entity_type'   => $entityType,
            ':external_id'   => $externalId,
            ':action'        => $action,
            ':case_id'       => $caseId,
            ':message'       => $message !== null ? substr($message, 0, 2000) : null,
            ':detail_json'   => $detail !== null ? json_encode($detail) : null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Enforce the PHI-free contract on a detail/metadata array before it is
     * persisted: flat scalars only, no PHI- or credential-named keys,
     * bounded size. Throws InvalidArgumentException on violation - callers
     * should treat a rejection as a bug, not a user error.
     */
    public static function assertDetailSafe(array $detail): void {
        array_walk_recursive($detail, function ($value, $key) {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Integration event detail must contain scalar values only.');
            }
            if (is_string($key) && preg_match(self::FORBIDDEN_DETAIL_KEY_PATTERN, $key)) {
                throw new InvalidArgumentException('Integration event detail contains a restricted key.');
            }
        });

        $encoded = json_encode($detail);
        if ($encoded !== false && strlen($encoded) > self::MAX_DETAIL_BYTES) {
            throw new InvalidArgumentException('Integration event detail exceeds the size limit.');
        }
    }

    /**
     * MySQL duplicate-key detection (SQLSTATE 23000 / errno 1062).
     */
    private static function isDuplicateKey(PDOException $e): bool {
        if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
            return true;
        }
        return $e->getCode() === '23000';
    }
}
