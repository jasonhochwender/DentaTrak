<?php
/**
 * IntegrationOutbound
 *
 * Provider-agnostic OUTBOUND queue + processing orchestration: DentaTrak-
 * originated changes that must be pushed to the PMS (Open Dental LabCase
 * writes first). Structurally mirrors IntegrationEvents (the inbound queue)
 * so both directions share the same operational contract: durable rows,
 * atomic claims, bounded retries, stale-claim recovery, PHI-free errors.
 *
 * RESPONSIBILITIES:
 *  - Enqueue DentaTrak-originated changes for mapped entities
 *    (integration_outbound_events), deduplicated by content hash.
 *  - Coalesce rapid repeat changes: a newer queued event for the same
 *    (connection, entity, operation) supersedes older queued ones, merging
 *    their payload fields forward so nothing is lost.
 *  - Drain queued events: resolve connection -> verify outbound opt-in ->
 *    re-verify practice + mapping -> read current remote state -> conflict
 *    check -> write through the adapter -> record provenance.
 *  - Loop suppression: every delivered write stamps an `outbound_sync`
 *    marker (fields + remote DateTStamp) into the entity mapping's
 *    metadata_json; the inbound drainer recognizes that watermark as a
 *    self-originated echo rather than a new remote change.
 *
 * GATING (all three must hold for any remote write):
 *  1. SHOW_PMS_INTEGRATIONS + PMS_OUTBOUND_SYNC feature flags on.
 *  2. connection.status = 'active' AND sync_enabled = 1.
 *  3. config_json.outbound.enabled = true for the connection (and
 *     outbound.create_enabled for create operations). The adapter instance
 *     is also built with writesEnabled from the same flag, so the write
 *     methods themselves fail closed without it.
 *
 * FIELD POLICY (Phase 2 initial scope):
 *  Open Dental stays authoritative for patient identity, provider,
 *  appointment, due date and instructions - they are NEVER written here.
 *  Only whitelisted lifecycle timestamps (DateTimeSent / DateTimeRecd /
 *  DateTimeChecked) may flow outbound, via the connection's
 *  outbound.field_map (status -> OD field). The map ships EMPTY: until a
 *  practice opts in and approves a mapping, no transition enqueues any
 *  write. See docs/open-dental-outbound-sync.md.
 *
 * CONFLICT POLICY (approved rules):
 *  Lifecycle dates are write-once. At write time the worker GETs the
 *  remote record and compares PER FIELD:
 *    remote == desired            -> field already in target state; skip
 *    remote populated (!= desired)-> 'conflict' (terminal, human reconciles;
 *                                     a nonempty lifecycle date is NEVER
 *                                     overwritten - history is preserved)
 *    remote empty (sentinel/null) -> write
 *  An event never performs a partial write: one conflicting field fails
 *  the whole event atomically. expected_remote_json is retained on the
 *  event purely for human inspection of what we believed remote state was.
 *  Consequence: a backward move (or stage re-entry) that would rewrite an
 *  already-stamped lifecycle date always surfaces as a conflict instead.
 *
 * CREATE IDEMPOTENCY:
 *  Open Dental POST /labcases has no idempotency key. Outbound create
 *  payloads carry DateTimeCreated = the event's enqueue timestamp as a
 *  fingerprint; on retry-after-ambiguous-failure the worker re-lists the
 *  patient's lab cases and adopts (maps) one whose stored DateTimeCreated
 *  equals the fingerprint instead of creating a duplicate.
 */

require_once __DIR__ . '/IntegrationManager.php';
require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../case-activity-log.php';
require_once __DIR__ . '/../billing-bypass.php';
require_once __DIR__ . '/../subscription-owner.php';
require_once __DIR__ . '/../subscription-access.php';

class IntegrationOutbound {

    const ENTITY_CASE = 'case';

    const OP_UPDATE_LABCASE = 'update_labcase';
    const OP_CREATE_LABCASE = 'create_labcase';

    const STATUS_QUEUED     = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_SUPERSEDED = 'superseded';
    const STATUS_CONFLICT   = 'conflict';
    const STATUS_SKIPPED    = 'skipped';
    const STATUS_FAILED     = 'failed';

    const ORIGIN_DENTATRAK = 'dentatrak';

    /**
     * Policy-level whitelist of remote fields a field_map may target -
     * Open Dental lifecycle timestamps only. The adapter enforces its own
     * narrower whitelist again at write time (defense in depth): a
     * misconfigured field_map can never aim a write at an OD-owned field
     * like Instructions or DateTimeDue.
     */
    const ALLOWED_REMOTE_FIELDS = ['DateTimeSent', 'DateTimeRecd', 'DateTimeChecked'];

    /** Retry tuning mirrors the inbound queue (IntegrationEvents). */
    const DEFAULT_RETRY_SECONDS   = 60;
    const MAX_RETRY_SECONDS       = 300;
    const PROCESSING_STALE_MINUTES = 10;
    const MAX_ATTEMPTS            = 10;

    // ----------------------------------------------------------------------
    // Connection outbound configuration (config_json.outbound)
    // ----------------------------------------------------------------------

    /**
     * Decoded config_json.outbound block. Shape:
     *   enabled:        bool - master per-connection write opt-in
     *   create_enabled: bool - separate opt-in for remote create
     *   field_map:      { "<internal status>": "<OD datetime field>" }
     *   capability:     { checked_at, has_permission, min_od_version } -
     *                   last probe result, written by the admin check action
     */
    public static function outboundConfig(array $connection): array {
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        $out = is_array($config) ? ($config['outbound'] ?? null) : null;
        return is_array($out) ? $out : [];
    }

    /**
     * The office's IANA timezone from config_json.outbound.timezone
     * (e.g. 'America/New_York'). Open Dental interprets LabCase datetimes
     * as office-local wall time; DentaTrak stamps status_changed_at in UTC
     * (server default). Until an office timezone is configured the enqueue
     * paths refuse to queue - writing a UTC instant as office-local would
     * silently shift every lifecycle timestamp by the office's UTC offset.
     */
    public static function officeTimezone(array $connection): ?DateTimeZone {
        $cfg = self::outboundConfig($connection);
        $tz = $cfg['timezone'] ?? null;
        if (!is_string($tz) || $tz === '') {
            return null;
        }
        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Convert a UTC datetime string to office-local "yyyy-MM-dd HH:mm:ss". */
    private static function toOfficeLocal($utcTs, DateTimeZone $tz): ?string {
        if (!is_string($utcTs) || trim($utcTs) === '') {
            return null;
        }
        try {
            $dt = new DateTime(trim($utcTs), new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    /** True only when every gate allows writes for this connection row. */
    public static function writesAllowed(array $connection): bool {
        $cfg = self::outboundConfig($connection);
        return !empty($cfg['enabled'])
            && ($connection['status'] ?? '') === 'active'
            && !empty($connection['sync_enabled']);
    }

    public static function createsAllowed(array $connection): bool {
        $cfg = self::outboundConfig($connection);
        return self::writesAllowed($connection) && !empty($cfg['create_enabled']);
    }

    /**
     * Merge fields into config_json.outbound, preserving unrelated config
     * keys. Non-secret bookkeeping only - never credentials or payloads.
     */
    public static function updateOutboundConfig(PDO $pdo, int $connectionId, array $fields): void {
        $connection = IntegrationManager::findConnection($pdo, $connectionId);
        if (!$connection) {
            return;
        }
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        if (!is_array($config)) {
            $config = [];
        }
        $out = isset($config['outbound']) && is_array($config['outbound']) ? $config['outbound'] : [];
        $config['outbound'] = array_merge($out, $fields);
        $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
            ->execute([':c' => json_encode($config), ':id' => $connectionId]);
    }

    /**
     * Feature-flag gate for the whole outbound pipeline. When the global
     * flag is off, enqueue is a no-op and the worker returns early.
     */
    public static function featureEnabled(): bool {
        return function_exists('isFeatureEnabled')
            && isFeatureEnabled('SHOW_PMS_INTEGRATIONS')
            && isFeatureEnabled('PMS_OUTBOUND_SYNC');
    }

    // ----------------------------------------------------------------------
    // Enqueue
    // ----------------------------------------------------------------------

    /**
     * Enqueue a DentaTrak workflow-status transition for outbound sync.
     * Called from the case write endpoints AFTER the local write commits.
     * No-ops (returns enqueued=false) whenever any gate fails, the case is
     * not PMS-mapped, the new status has no configured lifecycle mapping,
     * or the case is outside the caller's practice. NEVER throws on gate
     * failures - a sync queue must not break the request that produced it.
     *
     * @return array {enqueued:bool, id:?int, reason:?string}
     */
    public static function enqueueStatusTransition(
        PDO $pdo,
        int $practiceId,
        string $caseId,
        ?string $oldStatus,
        string $newStatus,
        ?int $actorUserId = null
    ): array {
        if ($newStatus === '' || $oldStatus === $newStatus) {
            return ['enqueued' => false, 'reason' => 'no_change'];
        }
        if (!self::featureEnabled()) {
            return ['enqueued' => false, 'reason' => 'flag_off'];
        }

        // Resolve the mapped connection THROUGH the practice boundary: the
        // mapping's connection must belong to the case's verified practice.
        $stmt = $pdo->prepare("
            SELECT m.id AS mapping_id, m.external_id, m.metadata_json, m.internal_id,
                   c.id AS connection_id, c.practice_id, c.provider, c.status,
                   c.sync_enabled, c.config_json
            FROM integration_entity_mappings m
            JOIN integration_connections c ON c.id = m.connection_id
            WHERE m.entity_type = :etype
              AND m.internal_id = :internal
              AND c.practice_id = :practice
              AND c.status = 'active'
              AND c.sync_enabled = 1
            ORDER BY m.id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':etype'    => self::ENTITY_CASE,
            ':internal' => $caseId,
            ':practice' => $practiceId,
        ]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return ['enqueued' => false, 'reason' => 'unmapped'];
        }

        $connection = IntegrationManager::findConnection($pdo, (int)$link['connection_id']);
        if (!$connection || !self::writesAllowed($connection)) {
            return ['enqueued' => false, 'reason' => 'writes_disabled'];
        }

        $cfg = self::outboundConfig($connection);
        $fieldMap = isset($cfg['field_map']) && is_array($cfg['field_map']) ? $cfg['field_map'] : [];
        // field_map keys are the STABLE internal status identifiers stored
        // in cases_cache.status (the six fixed stage ids, or 'Custom-*' ids
        // for practice-specific columns) - never display labels, which a
        // practice may rename at any time. Values must be whitelisted OD
        // lifecycle fields.
        $odField = $fieldMap[$newStatus] ?? null;
        if (!is_string($odField) || $odField === ''
            || !in_array($odField, self::ALLOWED_REMOTE_FIELDS, true)) {
            return ['enqueued' => false, 'reason' => 'status_unmapped'];
        }

        // Office timezone is mandatory: OD reads these as office-local.
        $tz = self::officeTimezone($connection);
        if ($tz === null) {
            return ['enqueued' => false, 'reason' => 'timezone_unconfigured'];
        }

        // The lifecycle timestamp is the transition time the local write
        // already committed (cases_cache.status_changed_at, UTC) converted
        // to office-local - NEVER the worker's later processing time.
        $tsStmt = $pdo->prepare("SELECT status_changed_at FROM cases_cache WHERE case_id = :id AND practice_id = :pid LIMIT 1");
        $tsStmt->execute([':id' => $caseId, ':pid' => $practiceId]);
        $changedAt = $tsStmt->fetchColumn();
        $when = self::toOfficeLocal($changedAt, $tz);
        if ($when === null) {
            return ['enqueued' => false, 'reason' => 'bad_timestamp'];
        }

        $payload = [$odField => $when];
        $expected = self::expectedRemoteSnapshot($link['metadata_json'] ?? null, array_keys($payload));

        // Provenance short-circuit: our last observation already equals the
        // desired value - the write has already landed; don't queue noise.
        $prior = $expected[$odField] ?? 'unknown';
        if ($prior !== 'unknown' && self::odDateOrNull($prior) === $when) {
            return ['enqueued' => false, 'reason' => 'already_synced'];
        }

        return self::enqueue(
            $pdo, (int)$connection['id'], $practiceId, self::OP_UPDATE_LABCASE,
            $caseId, (string)$link['external_id'], $payload, $expected, $actorUserId
        );
    }

    /**
     * Enqueue a remote CREATE for a locally-originated case. Foundation for
     * "create OD LabCase from DentaTrak" - requires outbound.create_enabled
     * on top of the normal write opt-in and a caller-built payload that has
     * already passed the PHI/detail contract (assertDetailSafe enforced
     * again inside enqueue()).
     */
    public static function enqueueCaseCreate(
        PDO $pdo,
        int $practiceId,
        string $caseId,
        array $payload,
        ?int $actorUserId = null
    ): array {
        if (!self::featureEnabled()) {
            return ['enqueued' => false, 'reason' => 'flag_off'];
        }

        // A case is only a create candidate when it is NOT already mapped to
        // an external record on any of this practice's connections.
        $stmt = $pdo->prepare("
            SELECT c.id, c.practice_id, c.status, c.sync_enabled, c.config_json
            FROM integration_connections c
            WHERE c.practice_id = :practice AND c.status = 'active' AND c.sync_enabled = 1
        ");
        $stmt->execute([':practice' => $practiceId]);
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($connections as $conn) {
            if (!self::createsAllowed($conn)) {
                continue;
            }
            $existing = SyncEngine::findMappingByInternalId($pdo, (int)$conn['id'], self::ENTITY_CASE, $caseId);
            if ($existing) {
                return ['enqueued' => false, 'reason' => 'already_mapped'];
            }
            // Same office-timezone requirement as lifecycle writes: every
            // datetime field in a create payload is interpreted by Open
            // Dental as office-local wall time, so a UTC instant must be
            // converted (or rejected) here - never at write time.
            $tz = self::officeTimezone($conn);
            if ($tz === null) {
                return ['enqueued' => false, 'reason' => 'timezone_unconfigured'];
            }
            // Create fingerprint: the enqueue instant rides along as
            // DateTimeCreated (converted to office-local) so a timed-out
            // POST can be recovered instead of duplicated.
            if (empty($payload['DateTimeCreated'])) {
                $payload['DateTimeCreated'] = gmdate('Y-m-d H:i:s');
            }
            foreach ($payload as $k => $v) {
                if (strncmp($k, 'DateTime', 8) === 0) {
                    $payload[$k] = self::toOfficeLocal($v, $tz) ?? $v;
                }
            }
            return self::enqueue(
                $pdo, (int)$conn['id'], $practiceId, self::OP_CREATE_LABCASE,
                $caseId, null, $payload, [], $actorUserId
            );
        }

        return ['enqueued' => false, 'reason' => 'creates_disabled'];
    }

    /**
     * Dedup + coalesce insert. UNIQUE(connection_id, dedup_key) makes the
     * enqueue itself idempotent; queued siblings for the same
     * (connection, entity, operation) are superseded with their pending
     * payload merged into the new event so a rapid A->B->C transition
     * produces one write carrying every staged field.
     *
     * An identical re-enqueue colliding with a TERMINAL row resurrects that
     * row to 'queued' (the change was already delivered or parked - either
     * way re-evaluating is correct and self-healing); a collision with a
     * live row is a true duplicate no-op.
     *
     * @return array {enqueued:bool, id:?int, reason:?string}
     */
    private static function enqueue(
        PDO $pdo,
        int $connectionId,
        int $practiceId,
        string $operation,
        string $internalId,
        ?string $externalId,
        array $payload,
        array $expectedRemote,
        ?int $actorUserId,
        string $origin = self::ORIGIN_DENTATRAK
    ): array {
        if (empty($payload)) {
            return ['enqueued' => false, 'reason' => 'empty_payload'];
        }
        // PHI contract: payload/expected maps must survive the same key
        // screening as sync-event details. A violation throws - callers
        // wrap enqueue in try/catch because this is a bug, not user error.
        SyncEngine::assertDetailSafe($payload);
        if (!empty($expectedRemote)) {
            SyncEngine::assertDetailSafe($expectedRemote);
        }

        $dedupKey = hash('sha256', $operation . '|' . ($externalId ?? '') . '|' . self::canonicalJson($payload));
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Coalesce: find still-queued events for the same target, fold
            // their staged fields/expectations into this event, supersede.
            $sel = $pdo->prepare("
                SELECT id, payload_json, expected_remote_json
                FROM integration_outbound_events
                WHERE connection_id = :conn AND entity_type = :etype
                  AND internal_entity_id = :internal AND operation = :op
                  AND status = :queued
                FOR UPDATE
            ");
            $sel->execute([
                ':conn'     => $connectionId,
                ':etype'    => self::ENTITY_CASE,
                ':internal' => $internalId,
                ':op'       => $operation,
                ':queued'   => self::STATUS_QUEUED,
            ]);
            $siblings = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($siblings as $sib) {
                $sibPayload = json_decode((string)$sib['payload_json'], true);
                if (is_array($sibPayload)) {
                    // Newest change wins per field.
                    $payload = array_merge($sibPayload, $payload);
                }
                $sibExpected = json_decode((string)$sib['expected_remote_json'], true);
                if (is_array($sibExpected)) {
                    // The EARLIEST expectation wins per field - it is the
                    // remote state preceding all queued writes.
                    $expectedRemote = $expectedRemote + $sibExpected;
                }
            }

            if ($siblings) {
                // Recompute dedup against the merged payload.
                $dedupKey = hash('sha256', $operation . '|' . ($externalId ?? '') . '|' . self::canonicalJson($payload));
                $ids = implode(',', array_map('intval', array_column($siblings, 'id')));
                $pdo->exec("UPDATE integration_outbound_events
                            SET status = '" . self::STATUS_SUPERSEDED . "',
                                last_error = 'Superseded by a newer queued change.',
                                processed_at = NOW()
                            WHERE id IN ({$ids}) AND status = '" . self::STATUS_QUEUED . "'");
            }

            $params = [
                ':conn'     => $connectionId,
                ':practice' => $practiceId,
                ':etype'    => self::ENTITY_CASE,
                ':internal' => $internalId,
                ':external' => $externalId,
                ':op'       => $operation,
                ':payload'  => json_encode($payload),
                ':expected' => !empty($expectedRemote) ? json_encode($expectedRemote) : null,
                ':origin'   => $origin,
                ':actor'    => $actorUserId,
                ':dedup'    => $dedupKey,
            ];
            $newId = null;
            try {
                $pdo->prepare("
                    INSERT INTO integration_outbound_events
                        (connection_id, practice_id, entity_type, internal_entity_id, external_entity_id,
                         operation, payload_json, expected_remote_json, origin, actor_user_id, dedup_key)
                    VALUES
                        (:conn, :practice, :etype, :internal, :external,
                         :op, :payload, :expected, :origin, :actor, :dedup)
                ")->execute($params);
                $newId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                // UNIQUE(connection_id, dedup_key) collision: either a true
                // duplicate (live row already holds this exact change) or a
                // terminal row being re-asserted - in that case resurrect it
                // as queued because its remote effect may never have landed.
                if (!self::isDuplicateKey($e)) {
                    throw $e;
                }
                $find = $pdo->prepare("
                    SELECT id, status FROM integration_outbound_events
                    WHERE connection_id = :conn AND dedup_key = :dedup LIMIT 1
                ");
                $find->execute([':conn' => $connectionId, ':dedup' => $dedupKey]);
                $existing = $find->fetch(PDO::FETCH_ASSOC);
                if ($existing
                    && in_array($existing['status'], [self::STATUS_SUPERSEDED, self::STATUS_SKIPPED,
                        self::STATUS_FAILED, self::STATUS_CONFLICT, self::STATUS_DELIVERED], true)) {
                    $pdo->prepare("
                        UPDATE integration_outbound_events
                        SET status = :queued, attempts = 0,
                            payload_json = :payload, expected_remote_json = :expected,
                            next_retry_at = NULL, processed_at = NULL, last_error = NULL
                        WHERE id = :id
                    ")->execute([
                        ':queued'   => self::STATUS_QUEUED,
                        ':payload'  => $params[':payload'],
                        ':expected' => $params[':expected'],
                        ':id'       => (int)$existing['id'],
                    ]);
                    $newId = (int)$existing['id'];
                } elseif ($existing) {
                    $newId = (int)$existing['id'];
                }
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
            // enqueued either way: a live/updated row now holds the change.
            return ['enqueued' => true, 'id' => $newId, 'reason' => null];
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Deterministic JSON for hashing: keys sorted, scalars only. */
    private static function canonicalJson(array $map): string {
        ksort($map);
        return (string)json_encode($map);
    }

    /** MySQL duplicate-key violation (1062 / SQLSTATE 23000). */
    private static function isDuplicateKey(PDOException $e): bool {
        return (int)($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * Snapshot of the remote field values our last inbound sync observed
     * (mapping provenance), limited to the fields this event will write.
     * Missing/empty provenance yields 'unknown' markers so the worker
     * applies the conservative first-write rule instead of assuming null.
     */
    private static function expectedRemoteSnapshot($metadataJson, array $fields): array {
        $meta = json_decode((string)$metadataJson, true);
        $prov = is_array($meta) ? $meta : [];
        $keyMap = [
            'DateTimeSent'    => 'date_time_sent',
            'DateTimeRecd'    => 'date_time_recd',
            'DateTimeChecked' => 'date_time_checked',
        ];
        $expected = [];
        foreach ($fields as $field) {
            $provKey = $keyMap[$field] ?? null;
            if ($provKey === null) {
                continue;
            }
            $expected[$field] = array_key_exists($provKey, $prov) ? $prov[$provKey] : 'unknown';
        }
        return $expected;
    }

    // ----------------------------------------------------------------------
    // Processing
    // ----------------------------------------------------------------------

    /**
     * Drain due outbound events + reclaim stale claims.
     * @return array {claimed, delivered, conflict, skipped, failed, retry, duplicate}
     */
    public static function processDue(PDO $pdo, int $limit = 25): array {
        $summary = ['claimed' => 0, 'delivered' => 0, 'conflict' => 0,
                    'skipped' => 0, 'failed' => 0, 'retry' => 0, 'duplicate' => 0];
        if (!self::featureEnabled()) {
            return $summary;
        }

        $limit = max(1, min(200, $limit));
        $stmt = $pdo->prepare("
            SELECT id FROM integration_outbound_events
            WHERE (status = :queued AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
               OR (status = :processing AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL :stale MINUTE)))
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([
            ':queued'     => self::STATUS_QUEUED,
            ':processing' => self::STATUS_PROCESSING,
            ':stale'      => self::PROCESSING_STALE_MINUTES,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $summary['claimed']++;
            $outcome = self::processEvent($pdo, (int)$id)['outcome'] ?? 'failed';
            if (isset($summary[$outcome])) {
                $summary[$outcome]++;
            }
        }
        return $summary;
    }

    /**
     * Claim and process one outbound event.
     * @return array {outcome: delivered|conflict|skipped|failed|retry|duplicate, message}
     */
    public static function processEvent(PDO $pdo, int $eventId): array {
        $event = self::findEvent($pdo, $eventId);
        if (!$event) {
            return ['outcome' => 'failed', 'message' => 'Outbound event not found.'];
        }
        if (!in_array($event['status'], [self::STATUS_QUEUED, self::STATUS_PROCESSING], true)) {
            return ['outcome' => 'duplicate', 'message' => 'Outbound event already finalized.'];
        }

        // Atomic claim - identical shape to the inbound queue so two
        // workers can never run the same write concurrently.
        $claim = $pdo->prepare("
            UPDATE integration_outbound_events
            SET status = :processing, attempts = attempts + 1, claimed_at = NOW()
            WHERE id = :id AND (
                (status = :queued AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
                OR (status = :stale_status AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL :stale MINUTE)))
            )
        ");
        $claim->execute([
            ':processing'   => self::STATUS_PROCESSING,
            ':queued'       => self::STATUS_QUEUED,
            ':stale_status' => self::STATUS_PROCESSING,
            ':stale'        => self::PROCESSING_STALE_MINUTES,
            ':id'           => $eventId,
        ]);
        if ($claim->rowCount() === 0) {
            return ['outcome' => 'duplicate', 'message' => 'Outbound event claimed by another worker or not yet due.'];
        }

        if (!self::featureEnabled()) {
            return self::scheduleRetry($pdo, $eventId, $event,
                'Outbound synchronization feature is disabled.');
        }

        $connection = IntegrationManager::findConnection($pdo, (int)$event['connection_id']);
        if (!$connection) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Integration connection no longer exists.');
        }
        // Practice defense-in-depth: the denormalized practice_id on the
        // event must equal the connection's - a mismatch means data
        // corruption, never a legitimate write.
        if ((int)$connection['practice_id'] !== (int)$event['practice_id']) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Outbound event practice mismatch.');
        }
        if (!self::writesAllowed($connection)) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'Outbound writes are disabled or the connection is inactive.');
        }

        // Plan entitlement: same enforcement point as the inbound drainer -
        // a downgraded practice loses write-back too. suspendForPlanEntitlement
        // parks the connection (disabled + sync_enabled=0), which also
        // short-circuits every later outbound event at the check above.
        $ownerEmail = '';
        $ownerUserId = getSubscriptionOwnerUserId($pdo, (int)$connection['practice_id']);
        if ($ownerUserId !== null) {
            $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
            $emailStmt->execute([':id' => $ownerUserId]);
            $ownerEmail = (string)($emailStmt->fetchColumn() ?: '');
        }
        if (!hasControlAccess($pdo, (int)$connection['practice_id'], $ownerEmail)) {
            IntegrationEvents::suspendForPlanEntitlement($pdo, $connection);
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'Plan entitlement required for integration.');
        }

        try {
            $adapter = IntegrationManager::getAdapter($connection);
        } catch (Throwable $e) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'No adapter for this integration provider.');
        }

        $credentials = IntegrationManager::getConnectionCredentials($pdo, (int)$connection['id']);

        if ($event['operation'] === self::OP_CREATE_LABCASE) {
            return self::processCreate($pdo, $eventId, $event, $connection, $adapter, $credentials);
        }
        return self::processUpdate($pdo, $eventId, $event, $connection, $adapter, $credentials);
    }

    // ----------------------------------------------------------------------
    // update_labcase
    // ----------------------------------------------------------------------

    private static function processUpdate(PDO $pdo, int $eventId, array $event, array $connection, $adapter, array $credentials): array {
        $connectionId = (int)$connection['id'];
        $externalId   = (string)($event['external_entity_id'] ?? '');
        $caseId       = (string)$event['internal_entity_id'];

        if (!method_exists($adapter, 'updateLabCase') || !method_exists($adapter, 'getLabCase')) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Adapter does not support external record writes.');
        }
        if ($externalId === '') {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Outbound update is missing its external record identifier.');
        }

        $payload = json_decode((string)$event['payload_json'], true);
        if (!is_array($payload) || empty($payload)) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Outbound update carries no payload.');
        }
        $expected = json_decode((string)$event['expected_remote_json'], true);
        $expected = is_array($expected) ? $expected : [];

        // The event's external id must still route to the same internal
        // case through the shared entity mapping - never trust the event
        // row alone.
        $mapping = SyncEngine::findMapping($pdo, $connectionId, SyncEngine::ENTITY_CASE, $externalId);
        if (!$mapping || (string)($mapping['internal_id'] ?? '') !== $caseId) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'Entity mapping no longer resolves to this case.');
        }
        if (!empty($mapping['source_deleted_at'])) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'External record was deleted upstream.');
        }

        // The case itself must still exist in the connection's practice.
        $casePractice = $pdo->prepare("SELECT practice_id FROM cases_cache WHERE case_id = :id LIMIT 1");
        $casePractice->execute([':id' => $caseId]);
        $casePracticeId = $casePractice->fetchColumn();
        if ($casePracticeId === false || (int)$casePracticeId !== (int)$connection['practice_id']) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'DentaTrak case no longer exists in this practice.');
        }

        // Read-before-write: compare current remote state against the
        // expected snapshot so a remote edit since our last sync can never
        // be silently overwritten.
        try {
            $remote = $adapter->getLabCase($credentials, (int)$externalId);
        } catch (Throwable $e) {
            return self::handleFailure($pdo, $eventId, $event, $e);
        }
        if (!is_array($remote)) {
            // 404 - the source record is gone. Mark the mapping exactly
            // like an inbound delete event would.
            self::markSourceDeleted($pdo, $connectionId, $connection, $externalId, $eventId);
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'External record no longer exists.');
        }

        [$toWrite, $conflictField] = self::planWrite($payload, $expected, $remote);
        if ($conflictField !== null) {
            return self::finalize($pdo, $eventId, self::STATUS_CONFLICT,
                'Remote field changed since last sync; not overwritten: ' . $conflictField);
        }
        if (empty($toWrite)) {
            self::stampOutboundProvenance($pdo, (int)$mapping['id'], $remote, $eventId, array_keys($payload));
            return self::finalize($pdo, $eventId, self::STATUS_DELIVERED,
                'External record already in target state.');
        }

        try {
            $written = $adapter->updateLabCase($credentials, (int)$externalId, $toWrite);
        } catch (Throwable $e) {
            return self::handleFailure($pdo, $eventId, $event, $e);
        }

        $remoteAfter = is_array($written) && !empty($written) ? $written : $remote;
        self::stampOutboundProvenance($pdo, (int)$mapping['id'], $remoteAfter, $eventId, array_keys($toWrite));
        self::logCaseSyncActivity($caseId, 'integration_outbound_delivered', $connection['provider'] ?? 'unknown', $eventId, array_keys($toWrite));

        return self::finalize($pdo, $eventId, self::STATUS_DELIVERED,
            'External record updated: ' . count($toWrite) . ' field(s).');
    }

    /**
     * Conflict-aware write plan. Returns [fieldsToWrite, ?conflictingField].
     *
     * Lifecycle history is write-once: a remote lifecycle date that is
     * already populated is NEVER overwritten by sync, whatever provenance
     * says. Per field:
     *   remote == desired            -> already in target state; skip
     *   remote populated (!= desired)-> conflict: preserve it, surface it
     *   remote empty (sentinel/null) -> write
     * One conflicting field fails the whole event - never a partial write.
     * This also makes backward moves and stage re-entries safe by
     * construction: re-entering a mapped stage after a regression finds the
     * earlier timestamp still populated and parks as conflict instead of
     * rewriting lifecycle history.
     * ($expected is kept in the event row for human diagnostics only.)
     */
    private static function planWrite(array $payload, array $expected, array $remote): array {
        $toWrite = [];
        foreach ($payload as $field => $desired) {
            $remoteNow = self::odDateOrNull($remote[$field] ?? null);
            $desiredVal = is_string($desired) ? $desired : null;

            if ($remoteNow === $desiredVal) {
                continue; // already in target state - no write needed
            }
            if ($remoteNow !== null) {
                return [$toWrite, $field]; // populated remote wins - never overwritten
            }
            $toWrite[$field] = $desiredVal;
        }
        return [$toWrite, null];
    }

    /** OD "not set" sentinels normalize to null for comparisons. */
    private static function odDateOrNull($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        foreach (['0001-01-01', '2000-01-01'] as $sentinel) {
            if (strncmp($v, $sentinel, strlen($sentinel)) === 0) {
                return null;
            }
        }
        return $v;
    }

    // ----------------------------------------------------------------------
    // create_labcase
    // ----------------------------------------------------------------------

    private static function processCreate(PDO $pdo, int $eventId, array $event, array $connection, $adapter, array $credentials): array {
        $connectionId = (int)$connection['id'];
        $caseId       = (string)$event['internal_entity_id'];

        if (!self::createsAllowed($connection)) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'Outbound creates are not enabled for this connection.');
        }
        if (!method_exists($adapter, 'createLabCase')) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Adapter does not support external record creation.');
        }

        // Never create a second remote record for an already-mapped case.
        $existing = SyncEngine::findMappingByInternalId($pdo, $connectionId, SyncEngine::ENTITY_CASE, $caseId);
        if ($existing && !empty($existing['external_id'])) {
            return self::finalize($pdo, $eventId, self::STATUS_SKIPPED,
                'Case already maps to an external record.');
        }

        $payload = json_decode((string)$event['payload_json'], true);
        if (!is_array($payload) || empty($payload)) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Outbound create carries no payload.');
        }

        // Fingerprint recovery: if a previous attempt timed out AFTER the
        // POST committed remotely, the record exists but no mapping does.
        // Re-list the patient's lab cases and adopt the one whose stored
        // DateTimeCreated equals the fingerprint we would have sent.
        $fingerprint = $payload['DateTimeCreated'] ?? null;
        $patNum = (int)($payload['PatNum'] ?? 0);
        if ($fingerprint !== null && $patNum > 0 && method_exists($adapter, 'listLabCases')) {
            try {
                $candidates = $adapter->listLabCases($credentials, ['PatNum' => $patNum], 0);
            } catch (Throwable $e) {
                return self::handleFailure($pdo, $eventId, $event, $e);
            }
            foreach ($candidates as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $created = self::odDateOrNull($row['DateTimeCreated'] ?? null);
                $num = (int)($row['LabCaseNum'] ?? 0);
                if ($created === $fingerprint && $num > 0) {
                    self::attachCreatedMapping($pdo, $connectionId, $connection, $caseId, $num, $patNum, $row, $eventId);
                    return self::finalize($pdo, $eventId, self::STATUS_DELIVERED,
                        'Recovered previously created external record.');
                }
            }
        }

        try {
            $created = $adapter->createLabCase($credentials, $payload);
        } catch (Throwable $e) {
            return self::handleFailure($pdo, $eventId, $event, $e);
        }

        $newNum = (int)($created['LabCaseNum'] ?? 0);
        if ($newNum <= 0) {
            return self::scheduleRetry($pdo, $eventId, $event,
                'Create response did not include an external identifier.');
        }
        self::attachCreatedMapping($pdo, $connectionId, $connection, $caseId, $newNum, $patNum > 0 ? $patNum : null, $created, $eventId);
        self::logCaseSyncActivity($caseId, 'integration_outbound_created', $connection['provider'] ?? 'unknown', $eventId, []);

        return self::finalize($pdo, $eventId, self::STATUS_DELIVERED,
            'External record created.');
    }

    /**
     * Reserve + attach the case mapping for a freshly created (or
     * fingerprint-recovered) external record, then stamp provenance.
     * Reuses SyncEngine's claim-before-attach primitives so a concurrent
     * inbound event for the same LabCaseNum converges on one mapping.
     */
    private static function attachCreatedMapping(PDO $pdo, int $connectionId, array $connection, string $caseId, int $externalNum, ?int $patNum, array $remoteRow, int $eventId): void {
        $reservation = SyncEngine::reserveMapping(
            $pdo, $connectionId, SyncEngine::ENTITY_CASE, (string)$externalNum,
            'case', $caseId,
            $patNum !== null ? (string)$patNum : null
        );
        $mapping = $reservation['mapping'];
        if (empty($mapping['internal_id'])) {
            SyncEngine::attachInternalId($pdo, (int)$mapping['id'], 'case', $caseId);
            $mapping = SyncEngine::findMapping($pdo, $connectionId, SyncEngine::ENTITY_CASE, (string)$externalNum) ?: $mapping;
        }
        self::stampOutboundProvenance($pdo, (int)$mapping['id'], $remoteRow, $eventId, []);
    }

    // ----------------------------------------------------------------------
    // Shared helpers
    // ----------------------------------------------------------------------

    /**
     * Record post-write remote state on the case mapping: lifecycle
     * provenance (same keys the inbound path writes) plus an outbound_sync
     * marker carrying the remote DateTStamp. The inbound drainer compares
     * that stamp to recognize our own write's echo and suppress it.
     */
    private static function stampOutboundProvenance(PDO $pdo, int $mappingId, array $remoteRow, int $eventId, array $writtenFields): void {
        $meta = [];
        $stmt = $pdo->prepare("SELECT metadata_json FROM integration_entity_mappings WHERE id = :id");
        $stmt->execute([':id' => $mappingId]);
        $existing = json_decode((string)$stmt->fetchColumn(), true);
        if (is_array($existing)) {
            $meta = $existing;
        }

        foreach ([
            'DateTimeSent'    => 'date_time_sent',
            'DateTimeRecd'    => 'date_time_recd',
            'DateTimeChecked' => 'date_time_checked',
        ] as $remoteKey => $provKey) {
            $v = self::odDateOrNull($remoteRow[$remoteKey] ?? null);
            if ($v !== null) {
                $meta[$provKey] = $v;
            }
        }
        $tstamp = self::odDateOrNull($remoteRow['DateTStamp'] ?? null);
        if ($tstamp !== null) {
            $meta['date_tstamp'] = $tstamp;
        }
        $meta['outbound_sync'] = [
            'fields'       => array_values($writtenFields),
            'delivered_at' => gmdate('Y-m-d H:i:s'),
            'event_id'     => $eventId,
            'last_tstamp'  => $tstamp,
        ];

        try {
            SyncEngine::assertDetailSafe($meta);
            $pdo->prepare("UPDATE integration_entity_mappings SET metadata_json = :m, last_seen_at = NOW() WHERE id = :id")
                ->execute([':m' => json_encode($meta), ':id' => $mappingId]);
        } catch (Throwable $e) {
            error_log('[IntegrationOutbound] provenance update skipped: ' . $e->getMessage());
        }
    }

    /**
     * Mark the case mapping's upstream source as deleted (same conditional
     * UPDATE as the inbound path; the DentaTrak case is never touched).
     */
    private static function markSourceDeleted(PDO $pdo, int $connectionId, array $connection, string $externalId, int $eventId): void {
        $mapping = SyncEngine::findMapping($pdo, $connectionId, SyncEngine::ENTITY_CASE, $externalId);
        if (!$mapping) {
            return;
        }
        try {
            $pdo->prepare("
                UPDATE integration_entity_mappings
                SET source_deleted_at = NOW()
                WHERE id = :id AND source_deleted_at IS NULL
            ")->execute([':id' => (int)$mapping['id']]);
        } catch (Throwable $e) {
            // Pre-migration schema - the event still finalizes as skipped.
        }
    }

    /**
     * Case-activity audit for a successful outbound write. Field names and
     * ids only - never values or PHI. Best-effort.
     */
    private static function logCaseSyncActivity(string $caseId, string $eventType, string $provider, int $eventId, array $fields): void {
        if (!function_exists('logCaseActivity')) {
            return;
        }
        try {
            logCaseActivity($caseId, $eventType, null, null, [
                'source'             => 'integration:' . $provider,
                'integration'        => $provider,
                'direction'          => 'outbound',
                'outbound_event_id'  => $eventId,
                'fields'             => $fields,
            ]);
        } catch (Throwable $e) {
            // Audit must never break delivery accounting.
        }
    }

    /**
     * Transient-vs-terminal failure classification, mirroring the inbound
     * queue: network/timeout/server_error/rate_limited/econnector_offline
     * retry with bounded backoff (Retry-After honored); auth, permission,
     * bad data, unsupported version, and writes_disabled fail terminally.
     */
    private static function handleFailure(PDO $pdo, int $eventId, array $event, Throwable $e): array {
        $category   = $e instanceof OpenDentalApiException ? $e->category : 'unexpected_response';
        $retryAfter = $e instanceof OpenDentalApiException ? $e->retryAfterSeconds : null;
        $message    = substr($e->getMessage(), 0, 500);

        if (in_array($category, ['network', 'timeout', 'server_error', 'rate_limited', 'econnector_offline'], true)) {
            if ((int)$event['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
            }
            $delay = $retryAfter ?? min(self::DEFAULT_RETRY_SECONDS * ((int)$event['attempts'] + 1), self::MAX_RETRY_SECONDS);
            $pdo->prepare("
                UPDATE integration_outbound_events
                SET status = :status, next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), last_error = :err
                WHERE id = :id
            ")->execute([
                ':status' => self::STATUS_QUEUED,
                ':delay'  => $delay,
                ':err'    => $message,
                ':id'     => $eventId,
            ]);
            self::logOutcome($pdo, $eventId, 'retry', $message);
            return ['outcome' => 'retry', 'message' => $message];
        }

        return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
    }

    private static function scheduleRetry(PDO $pdo, int $eventId, array $event, string $message): array {
        if ((int)$event['attempts'] + 1 >= self::MAX_ATTEMPTS) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
        }
        $delay = min(self::DEFAULT_RETRY_SECONDS * ((int)$event['attempts'] + 1), self::MAX_RETRY_SECONDS);
        $pdo->prepare("
            UPDATE integration_outbound_events
            SET status = :status, next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), last_error = :err
            WHERE id = :id
        ")->execute([
            ':status' => self::STATUS_QUEUED,
            ':delay'  => $delay,
            ':err'    => substr($message, 0, 500),
            ':id'     => $eventId,
        ]);
        self::logOutcome($pdo, $eventId, 'retry', $message);
        return ['outcome' => 'retry', 'message' => $message];
    }

    private static function finalize(PDO $pdo, int $eventId, string $status, ?string $error): array {
        $pdo->prepare("
            UPDATE integration_outbound_events
            SET status = :status, last_error = :err, next_retry_at = NULL, processed_at = NOW()
            WHERE id = :id
        ")->execute([
            ':status' => $status,
            ':err'    => $error !== null ? substr($error, 0, 500) : null,
            ':id'     => $eventId,
        ]);
        $outcome = $status === self::STATUS_DELIVERED ? 'delivered'
            : ($status === self::STATUS_CONFLICT ? 'conflict'
            : ($status === self::STATUS_SKIPPED ? 'skipped' : 'failed'));
        self::logOutcome($pdo, $eventId, $outcome, $error ?? '');
        return ['outcome' => $outcome, 'message' => $error ?? ''];
    }

    /** One PHI-free diagnostics line per outcome: ids and field names only. */
    private static function logOutcome(PDO $pdo, int $eventId, string $outcome, string $message): void {
        try {
            $e = self::findEvent($pdo, $eventId);
            if (!$e) {
                return;
            }
            error_log('[IntegrationOutbound] event=' . $eventId
                . ' conn=' . (int)$e['connection_id']
                . ' op=' . $e['operation']
                . ' entity=' . $e['entity_type'] . '/' . $e['internal_entity_id']
                . ' outcome=' . $outcome
                . ' attempts=' . (int)$e['attempts']
                . ($message !== '' ? ' detail=' . substr($message, 0, 200) : ''));
        } catch (Throwable $t) {
            // Logging must never break event processing.
        }
    }

    public static function findEvent(PDO $pdo, int $eventId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM integration_outbound_events WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ----------------------------------------------------------------------
    // Projection
    // ----------------------------------------------------------------------

    /**
     * Safe outbound state for the settings projection: opt-in flags and
     * queue counters only - never payloads, patient data, or credentials.
     */
    public static function outboundProjection(PDO $pdo, array $connection): array {
        $cfg = self::outboundConfig($connection);
        $counts = ['pending' => 0, 'conflicts' => 0, 'failed' => 0];
        $lastDelivered = null;
        try {
            $stmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN status IN ('queued','processing') THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'conflict' THEN 1 ELSE 0 END) AS conflicts,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    MAX(CASE WHEN status = 'delivered' THEN processed_at END) AS last_delivered
                FROM integration_outbound_events
                WHERE connection_id = :id
            ");
            $stmt->execute([':id' => (int)$connection['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $counts['pending']   = (int)($row['pending'] ?? 0);
            $counts['conflicts'] = (int)($row['conflicts'] ?? 0);
            $counts['failed']    = (int)($row['failed'] ?? 0);
            $lastDelivered       = $row['last_delivered'] ?? null;
        } catch (Throwable $e) {
            // Pre-migration schema - report zeros rather than breaking the
            // whole connection projection.
        }

        return [
            'enabled'            => !empty($cfg['enabled']),
            'create_enabled'     => !empty($cfg['create_enabled']),
            'field_map'          => isset($cfg['field_map']) && is_array($cfg['field_map'])
                ? array_keys($cfg['field_map']) : [],
            'capability'         => isset($cfg['capability']) && is_array($cfg['capability'])
                ? $cfg['capability'] : null,
            'pending'            => $counts['pending'],
            'conflicts'          => $counts['conflicts'],
            'failed'             => $counts['failed'],
            'last_delivered_at'  => $lastDelivered,
        ];
    }
}
