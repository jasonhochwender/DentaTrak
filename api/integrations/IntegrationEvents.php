<?php
/**
 * IntegrationEvents
 *
 * Provider-agnostic inbound-event store + processing orchestration for PMS
 * webhook events (Open Dental API Events / WatchTable subscriptions first).
 *
 * RESPONSIBILITIES (generic):
 *  - Persist deduplicated inbound events (integration_external_events).
 *  - Own the subscription metadata block inside connection.config_json
 *    (non-secret: numbers, flags, hashes - never tokens or credentials).
 *  - Drain pending events: resolve connection -> adapter -> fetch the
 *    affected external record -> normalize -> mark processed.
 *
 * WHAT DOES NOT LIVE HERE: provider payload parsing, endpoint paths, auth
 * schemes (all inside the adapter). DentaTrak case creation lives in
 * CaseService; this class only orchestrates reserve -> create -> attach
 * so each external LabCase yields exactly one case.
 *
 * UPDATE SCOPE: for an already-imported LabCase, Open Dental-owned fields
 * are synchronized onto the mapped case (change-detected, never blind
 * writes); DentaTrak-owned workflow/user fields are never touched, and
 * deletes never delete the DentaTrak case.
 *
 * FAILURE MODEL:
 *  - Transient (network/timeout/429/server_error): status returns to
 *    'received' with next_retry_at honoring Retry-After when provided.
 *  - Entity gone (404): terminal 'entity_gone' - the record was deleted.
 *  - Auth/bad data: terminal 'failed' - credentials/config need a human.
 *  - Duplicates can never re-process: the unique key on
 *    (connection_id, provider_event_id) makes redelivery a no-op.
 */

require_once __DIR__ . '/IntegrationManager.php';
require_once __DIR__ . '/SyncEngine.php';
require_once __DIR__ . '/../CaseService.php';

class IntegrationEvents {

    const EVENT_ENTITY_LABCASE = 'labcase';

    const STATUS_RECEIVED    = 'received';
    const STATUS_PROCESSING  = 'processing';
    const STATUS_PROCESSED   = 'processed';
    const STATUS_ENTITY_GONE = 'entity_gone';
    const STATUS_FAILED      = 'failed';

    /** Default retry delay when Open Dental supplies no Retry-After. */
    const DEFAULT_RETRY_SECONDS = 60;
    /** Cap for linear backoff on transient failures. */
    const MAX_RETRY_SECONDS = 300;
    /** A 'processing' event older than this is considered crashed and re-drainable. */
    const PROCESSING_STALE_MINUTES = 10;
    /** Max delivery attempts before an event is parked as failed. */
    const MAX_ATTEMPTS = 10;

    // ----------------------------------------------------------------------
    // Ingest (deduplicating store)
    // ----------------------------------------------------------------------

    /**
     * Store one parsed inbound event row. Returns
     * ['id' => ?int, 'inserted' => bool] - inserted=false means the unique
     * dedup key already existed (replay/redelivery) and the row was NOT
     * re-queued. Never throws on duplicates.
     */
    public static function record(PDO $pdo, int $connectionId, string $entityType,
                                  string $externalId, string $dedupKey, ?string $watermark): array {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO integration_external_events
                (connection_id, provider_event_id, external_entity_type, external_entity_id, event_watermark)
            VALUES
                (:connection_id, :provider_event_id, :entity_type, :external_id, :watermark)
        ");
        $stmt->execute([
            ':connection_id'     => $connectionId,
            ':provider_event_id' => substr($dedupKey, 0, 64),
            ':entity_type'       => substr($entityType, 0, 32),
            ':external_id'       => substr($externalId, 0, 128),
            ':watermark'         => $watermark !== null ? substr($watermark, 0, 64) : null,
        ]);
        if ($stmt->rowCount() === 0) {
            return ['id' => null, 'inserted' => false];
        }
        return ['id' => (int)$pdo->lastInsertId(), 'inserted' => true];
    }

    // ----------------------------------------------------------------------
    // Processing
    // ----------------------------------------------------------------------

    /**
     * Claim and process a single stored event: fetch the current external
     * record through the provider adapter, hydrate supporting resources,
     * normalize to a CanonicalCase, then import it as exactly one
     * DentaTrak case via SyncEngine mapping + CaseService.
     *
     * @return array {outcome: processed|entity_gone|retry|failed|duplicate,
     *                message: safe string}
     */
    public static function processEvent(PDO $pdo, int $eventId): array {
        $event = self::findEvent($pdo, $eventId);
        if (!$event) {
            return ['outcome' => 'failed', 'message' => 'Event not found.'];
        }
        if (in_array($event['status'], [self::STATUS_PROCESSED, self::STATUS_ENTITY_GONE, self::STATUS_FAILED], true)) {
            return ['outcome' => 'duplicate', 'message' => 'Event already finalized.'];
        }

        // Atomic claim. Only an event still eligible for work may transition
        // to 'processing': a 'received' row whose retry time has arrived, or
        // a stale 'processing' row from a crashed worker. rowCount=0 means
        // another worker owns it - do nothing so two workers can never run
        // the same event concurrently.
        $claim = $pdo->prepare("
            UPDATE integration_external_events
            SET status = :processing, attempts = attempts + 1, claimed_at = NOW()
            WHERE id = :id AND (
                (status = :received AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
                OR (status = :stale_status AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL :stale MINUTE)))
            )
        ");
        $claim->execute([
            ':processing'   => self::STATUS_PROCESSING,
            ':received'     => self::STATUS_RECEIVED,
            ':stale_status' => self::STATUS_PROCESSING,
            ':stale'        => self::PROCESSING_STALE_MINUTES,
            ':id'           => $eventId,
        ]);
        if ($claim->rowCount() === 0) {
            return ['outcome' => 'duplicate', 'message' => 'Event already claimed by another worker or not yet due.'];
        }

        $connection = IntegrationManager::findConnection($pdo, (int)$event['connection_id']);
        if (!$connection || $connection['status'] !== 'active') {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Integration connection is not active.');
        }

        try {
            $adapter = IntegrationManager::getAdapter($connection);
        } catch (Throwable $e) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'No adapter for this integration provider.');
        }

        $credentials = IntegrationManager::getConnectionCredentials($pdo, (int)$connection['id']);
        $externalId  = (int)$event['external_entity_id'];

        // A delete-watch event has nothing to fetch - the record is gone.
        // Phase E: never delete the DentaTrak case; the diagnostic below
        // notes whether a mapped case was left intact.
        if ($event['external_entity_type'] === self::EVENT_ENTITY_LABCASE . '_deleted') {
            $existing = SyncEngine::findMapping($pdo, (int)$connection['id'], SyncEngine::ENTITY_CASE, (string)$externalId);
            return self::finalize($pdo, $eventId, self::STATUS_ENTITY_GONE,
                'External record reported deleted.' .
                ($existing && $existing['internal_id'] ? ' Linked DentaTrak case left intact.' : ''));
        }

        // The entity fetch is adapter-specific today (only open_dental
        // exists). If a second provider arrives, promote this to the
        // PmsAdapterInterface contract.
        if (!method_exists($adapter, 'getLabCase')) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Adapter cannot fetch the external record.');
        }

        try {
            $row = $adapter->getLabCase($credentials, $externalId);
        } catch (Throwable $e) {
            return self::handleFetchFailure($pdo, $eventId, $event, $e);
        }

        if (!is_array($row)) {
            return self::finalize($pdo, $eventId, self::STATUS_ENTITY_GONE,
                'External record no longer exists.');
        }

        // Hydrate supporting resources (patient/provider/lab/appointment).
        // 404s resolve to null; transient failures retry the whole event.
        $support = self::fetchSupportingResources($adapter, $credentials, $row);
        if ($support instanceof Throwable) {
            return self::handleFetchFailure($pdo, $eventId, $event, $support);
        }

        try {
            $canonical = $adapter->normalizeCase($support);
        } catch (Throwable $e) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'External record could not be normalized.');
        }

        if (empty($canonical->externalCaseId)) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'External record normalized without an identifier.');
        }

        return self::importCanonicalCase($pdo, $eventId, $event, $connection, $canonical);
    }

    /**
     * Exact-once import: reserve the (connection, 'case', external_id)
     * mapping, create the DentaTrak case only when this process owns the
     * reservation, then attach the generated case_id. Concurrent workers
     * converge through the UNIQUE key; orphaned reservations (crashed
     * worker) are reclaimed after a staleness window.
     */
    private static function importCanonicalCase(PDO $pdo, int $eventId, array $event, array $connection, CanonicalCase $canonical): array {
        $connectionId = (int)$connection['id'];
        $externalCaseId = (string)$canonical->externalCaseId;
        $provider = $connection['provider'] ?? 'unknown';

        $reservation = SyncEngine::reserveMapping(
            $pdo, $connectionId, SyncEngine::ENTITY_CASE, $externalCaseId
        );
        $mapping = $reservation['mapping'];
        $ownsReservation = $reservation['created'];

        if (!$ownsReservation) {
            if (!empty($mapping['internal_id'])) {
                // Already imported - synchronize Open Dental-owned fields
                // only; never creates a second case, never touches
                // DentaTrak-owned workflow/user data.
                return self::updateCaseFromCanonical(
                    $pdo, $eventId, $event, $connection, $canonical,
                    (string)$mapping['internal_id'], $mapping
                );
            }

            $state = SyncEngine::reservationState($pdo, $mapping);
            if ($state === 'in_flight') {
                // Another worker is mid-import. Defer - on retry the
                // mapping will be finalized (or reclaimed if orphaned).
                return self::scheduleRetry($pdo, $eventId, $event,
                    'Concurrent import of this external case in progress.');
            }
            // Orphaned reservation from a crashed worker - reclaim it.
            SyncEngine::releaseMapping($pdo, (int)$mapping['id']);
            $reservation = SyncEngine::reserveMapping(
                $pdo, $connectionId, SyncEngine::ENTITY_CASE, $externalCaseId
            );
            $mapping = $reservation['mapping'];
            $ownsReservation = $reservation['created'];
        }

        if (!$ownsReservation) {
            // Lost a re-reserve race - the winner will finish the import.
            return self::scheduleRetry($pdo, $eventId, $event,
                'External case reservation claimed by another worker.');
        }

        $practiceId = (int)$connection['practice_id'];
        $input = CaseService::caseInputFromCanonical($canonical, $practiceId);

        try {
            $result = CaseService::create($input, [
                'practice_id'        => $practiceId,
                'created_by_user_id' => null,   // system-created
                'actor_user_id'      => null,
                'source'             => 'integration:' . $provider,
                'source_metadata'    => [
                    'integration'       => $provider,
                    'external_event_id' => $eventId,
                ],
                'notify'             => true,   // normal create notifications;
                                                // actor-less + unassigned = quiet
                'required_fields'    => CaseService::integrationRequiredFields(),
                'validate'           => true,
                'truncate_notes'     => true,
                'defer_drive_backup' => false,  // inline, best-effort
                'updated_by'         => 'integration:' . $provider,
            ]);
        } catch (Throwable $e) {
            // Unexpected internal failure: release the reservation so the
            // retry starts clean, then back off.
            SyncEngine::releaseMapping($pdo, (int)$mapping['id']);
            return self::scheduleRetry($pdo, $eventId, $event,
                'Case creation error: ' . substr($e->getMessage(), 0, 300));
        }

        if (!$result['success']) {
            SyncEngine::releaseMapping($pdo, (int)$mapping['id']);
            // Validation failures are permanent - the external data cannot
            // produce a well-formed case without human attention.
            if (!empty($result['missingFields']) || isset($result['field'])) {
                return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                    'External case missing data required for import: ' . ($result['message'] ?? 'validation failed'));
            }
            return self::scheduleRetry($pdo, $eventId, $event,
                'Case creation failed: ' . substr((string)($result['message'] ?? 'unknown'), 0, 300));
        }

        $caseId = (string)$result['caseData']['id'];
        if (!SyncEngine::attachInternalId($pdo, (int)$mapping['id'], 'case', $caseId)) {
            // Reservation was finalized by someone else between our create
            // and attach - should be unreachable, but never overwrite.
            return self::finalize($pdo, $eventId, self::STATUS_FAILED,
                'Mapping was concurrently finalized during import.');
        }

        // Supporting entity mappings as external metadata (no first-class
        // DentaTrak counterpart exists yet - internal_id stays NULL).
        self::reserveSupportMappings($pdo, $connectionId, $canonical, $externalCaseId);

        return self::finalize($pdo, $eventId, self::STATUS_PROCESSED, null);
    }

    /**
     * V1 update synchronization for an already-imported LabCase.
     *
     * OWNERSHIP RULE: Open Dental may only write fields Open Dental owns.
     * DentaTrak-owned workflow/user data is NEVER touched here:
     *   status, case_type, assigned_to, reviewed flags (beyond the
     *   meaningful-change reset below), comments, clinical_details,
     *   tooth_shade, material, attachments, carrier/tracking, lab
     *   assignment - and patient demographics (name/DOB/gender), which are
     *   user-editable in DentaTrak and may hold intentional corrections.
     *
     * Updatable fields and their null policy:
     *   due_date                  <- DateTimeDue        null clears the field
     *   patient_appointment_date  <- AptDateTime        null clears the field
     *   notes                     <- Instructions       empty/null clears
     *   dentist_name              <- provider identity  null/empty KEEPS the
     *                                                     stored value (never
     *                                                     blank on unreadable
     *                                                     provider data)
     *
     * Change detection compares the normalized canonical value against the
     * decrypted stored value; identical values produce no write, no
     * activity entry, and no review reset.
     */
    private static function updateCaseFromCanonical(PDO $pdo, int $eventId, array $event, array $connection, CanonicalCase $canonical, string $caseId, array $mapping): array {
        $practiceId = (int)$connection['practice_id'];
        $provider   = $connection['provider'] ?? 'unknown';
        $source     = 'integration:' . $provider;

        $stmt = $pdo->prepare("
            SELECT due_date, patient_appointment_date, notes, dentist_name, status, reviewed_at
            FROM cases_cache
            WHERE case_id = :case_id AND practice_id = :practice_id
            LIMIT 1
        ");
        $stmt->execute(['case_id' => $caseId, 'practice_id' => $practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // The mapped case was deleted in DentaTrak. Never recreate it -
            // the mapping is the source of truth that this LabCase was
            // already imported.
            self::updateMappingProvenance($pdo, (int)$mapping['id'], $canonical, $eventId);
            return self::finalize($pdo, $eventId, self::STATUS_PROCESSED,
                'Mapped DentaTrak case no longer exists; update skipped.');
        }

        // column => [canonical value, is-encrypted, null-means-keep]
        $fieldSpec = [
            'due_date'                 => [$canonical->dueDate, false, false],
            'patient_appointment_date' => [$canonical->appointmentDate, false, false],
            'notes'                    => [$canonical->instructions, true, false],
            'dentist_name'             => [$canonical->providerName, true, true],
        ];

        $changes = [];
        foreach ($fieldSpec as $column => [$newRaw, $encrypted, $nullKeeps]) {
            $newVal = is_string($newRaw) ? trim($newRaw) : $newRaw;
            if ($newVal === '') {
                $newVal = null;
            }
            if ($newVal === null && $nullKeeps) {
                continue;
            }

            $currentRaw = $row[$column] ?? null;
            if ($encrypted && $currentRaw !== null && $currentRaw !== '') {
                try {
                    $currentRaw = PIIEncryption::decrypt($currentRaw);
                } catch (Throwable $e) {
                    // Cannot safely compare undecryptable data - leave the
                    // stored value untouched rather than overwriting blind.
                    continue;
                }
            }
            $currentVal = is_string($currentRaw) ? trim($currentRaw) : $currentRaw;
            if ($currentVal === '') {
                $currentVal = null;
            }

            if ($currentVal !== $newVal) {
                $changes[$column] = [$newVal, $encrypted];
            }
        }

        // Provenance (mapping metadata_json) updates even on a no-op event -
        // it is integration bookkeeping, not a case write.
        self::updateMappingProvenance($pdo, (int)$mapping['id'], $canonical, $eventId);
        SyncEngine::touchMapping($pdo, (int)$mapping['id']);

        if (empty($changes)) {
            return self::finalize($pdo, $eventId, self::STATUS_PROCESSED,
                'No changes; case already in sync.');
        }

        $wasReviewed = !empty($row['reviewed_at']);
        $set = ['last_update_date = :lud', 'version = version + 1'];
        $params = [
            'lud'      => date('c'),
            'case_id'  => $caseId,
            'practice' => $practiceId,
        ];
        foreach ($changes as $column => [$newVal, $encrypted]) {
            $set[] = "{$column} = :{$column}";
            $params[$column] = ($encrypted && $newVal !== null)
                ? PIIEncryption::encrypt($newVal)
                : $newVal;
        }
        if ($wasReviewed) {
            // Meaningful external change resets Needs Review, mirroring the
            // different-user reset in update-case.php. Atomically part of
            // the same write; no fake user is credited.
            $set[] = 'reviewed_at = NULL';
            $set[] = 'reviewed_by_user_id = NULL';
        }

        $changedFields = array_map(function ($col) {
            return [
                'due_date'                 => 'dueDate',
                'patient_appointment_date' => 'patientAppointmentDate',
                'notes'                    => 'notes',
                'dentist_name'             => 'dentistName',
            ][$col] ?? $col;
        }, array_keys($changes));

        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare(
                'UPDATE cases_cache SET ' . implode(', ', $set)
                . ' WHERE case_id = :case_id AND practice_id = :practice'
            );
            $upd->execute($params);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Atomic: a failed update wrote nothing. Retry is bounded by
            // the existing attempt cap.
            return self::scheduleRetry($pdo, $eventId, $event,
                'Case update error: ' . substr($e->getMessage(), 0, 300));
        }

        // Audit: same 'case_updated' event type as the manual edit path,
        // credited to the integration (actor null = system), field names
        // only - never values.
        if (function_exists('logCaseActivity')) {
            logCaseActivity($caseId, 'case_updated', null, $row['status'] ?? null, [
                'changed_fields'    => $changedFields,
                'fields_count'      => count($changedFields),
                'source'            => $source,
                'integration'       => $provider,
                'external_event_id' => $eventId,
            ]);
            if ($wasReviewed) {
                logCaseActivity($caseId, 'review_status_changed', 'reviewed', 'needs_review', [
                    'review_status' => 'needs_review',
                    'source'        => $source,
                    'reason'        => 'meaningful_change_by_integration',
                ]);
            }
        }
        if (function_exists('recordCaseUpdate')) {
            recordCaseUpdate($caseId, 'update', null, null, $practiceId, $source);
        }

        return self::finalize($pdo, $eventId, self::STATUS_PROCESSED,
            'Case updated: ' . count($changedFields) . ' field(s).');
    }

    /**
     * Refresh non-PHI provenance on the case mapping (latest Open Dental
     * lifecycle timestamps + linked entity ids). Sentinels arrive already
     * normalized to null by the adapter.
     */
    private static function updateMappingProvenance(PDO $pdo, int $mappingId, CanonicalCase $canonical, int $eventId): void {
        $meta = $canonical->metadata;
        $provenance = array_filter([
            'pat_num'           => $canonical->externalPatientId,
            'prov_num'          => $canonical->externalProviderId,
            'laboratory_num'    => $canonical->externalLaboratoryId,
            'apt_num'           => $meta['apt_num'] ?? null,
            'planned_apt_num'   => $meta['planned_apt_num'] ?? null,
            'date_tstamp'       => $meta['date_tstamp'] ?? null,
            'date_time_created' => $meta['date_time_created'] ?? null,
            'date_time_sent'    => $meta['date_time_sent'] ?? null,
            'date_time_recd'    => $meta['date_time_recd'] ?? null,
            'date_time_checked' => $meta['date_time_checked'] ?? null,
            'invoice_num'       => $meta['invoice_num'] ?? null,
            'last_event_id'     => $eventId,
        ], function ($v) { return $v !== null; });

        try {
            SyncEngine::assertDetailSafe($provenance);
            $pdo->prepare("UPDATE integration_entity_mappings SET metadata_json = :meta WHERE id = :id")
                ->execute(['meta' => json_encode($provenance), 'id' => $mappingId]);
        } catch (Throwable $e) {
            error_log('[IntegrationEvents] provenance update skipped: ' . $e->getMessage());
        }
    }

    /**
     * Fetch patient/provider/laboratory/appointment records referenced by
     * the LabCase row. Missing/404 resources normalize to null; transient
     * failures return the exception so the caller can schedule a retry.
     *
     * @return array|Throwable normalized-resource bundle for normalizeCase()
     */
    private static function fetchSupportingResources($adapter, array $credentials, array $labcase) {
        $support = ['labcase' => $labcase];

        $fetch = function (string $method, $id) use ($adapter, $credentials) {
            if ($id === null || (int)$id <= 0) {
                return null;
            }
            try {
                return $adapter->$method($credentials, (int)$id);
            } catch (OpenDentalApiException $e) {
                if (in_array($e->category, ['network', 'timeout', 'server_error', 'rate_limited', 'econnector_offline'], true)) {
                    return $e; // transient - caller retries the whole event
                }
                return null;   // permanent/absent - degrade gracefully
            }
        };

        foreach ([
            'patient'     => ['getPatient', $labcase['PatNum'] ?? null],
            'provider'    => ['getProviderByNum', $labcase['ProvNum'] ?? null],
            'laboratory'  => ['getLaboratory', $labcase['LaboratoryNum'] ?? null],
            'appointment' => ['getAppointment', $labcase['AptNum'] ?? ($labcase['PlannedAptNum'] ?? null)],
        ] as $key => [$method, $id]) {
            if (!method_exists($adapter, $method)) {
                continue;
            }
            $value = $fetch($method, $id);
            if ($value instanceof Throwable) {
                return $value;
            }
            $support[$key] = $value;
        }

        return $support;
    }

    /**
     * Reserve metadata-only mappings for related external entities. These
     * carry no PHI (external IDs only) and no fabricated internal ids -
     * they exist so future phases can join "all cases for patient X"
     * without inventing DentaTrak entities.
     */
    private static function reserveSupportMappings(PDO $pdo, int $connectionId, CanonicalCase $canonical, string $labCaseNum): void {
        $related = [
            [SyncEngine::ENTITY_PATIENT,     $canonical->externalPatientId],
            [SyncEngine::ENTITY_PROVIDER,    $canonical->externalProviderId],
            [SyncEngine::ENTITY_LABORATORY,  $canonical->externalLaboratoryId],
            // The winning appointment link plus the secondary reference
            // (PlannedAptNum when AptNum won, and vice versa) - both are
            // meaningful joins for future phases.
            [SyncEngine::ENTITY_APPOINTMENT, $canonical->externalAppointmentId],
            [SyncEngine::ENTITY_APPOINTMENT, $canonical->metadata['planned_apt_num'] ?? null],
            [SyncEngine::ENTITY_APPOINTMENT, $canonical->metadata['apt_num'] ?? null],
        ];
        foreach ($related as [$entityType, $externalId]) {
            if ($externalId === null || $externalId === '') {
                continue;
            }
            try {
                SyncEngine::reserveMapping(
                    $pdo, $connectionId, $entityType, (string)$externalId,
                    null, null, $labCaseNum, null
                );
            } catch (Throwable $e) {
                // Metadata-only - never let a support mapping block import.
                error_log('[IntegrationEvents] support mapping skipped: ' . $e->getMessage());
            }
        }
    }

    /**
     * Put the event back to 'received' with a bounded backoff, or fail it
     * permanently once MAX_ATTEMPTS is exhausted.
     */
    private static function scheduleRetry(PDO $pdo, int $eventId, array $event, string $message): array {
        if ((int)$event['attempts'] + 1 >= self::MAX_ATTEMPTS) {
            return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
        }
        $delay = min(self::DEFAULT_RETRY_SECONDS * ((int)$event['attempts'] + 1), self::MAX_RETRY_SECONDS);
        $pdo->prepare("
            UPDATE integration_external_events
            SET status = :status, next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), last_error = :err
            WHERE id = :id
        ")->execute([
            ':status' => self::STATUS_RECEIVED,
            ':delay'  => $delay,
            ':err'    => substr($message, 0, 500),
            ':id'     => $eventId,
        ]);
        self::logEventOutcome($pdo, $eventId, 'retry', $message);
        return ['outcome' => 'retry', 'message' => $message];
    }

    /**
     * One safe diagnostics line per event outcome. Carries only ids, the
     * entity type (WatchTable), the numeric external id, attempt count and
     * the already-sanitized message - never credentials, patient data,
     * instructions, or raw API payloads.
     */
    private static function logEventOutcome(PDO $pdo, int $eventId, string $outcome, string $message): void {
        try {
            $e = self::findEvent($pdo, $eventId);
            if (!$e) {
                return;
            }
            error_log('[IntegrationEvents] event=' . $eventId
                . ' conn=' . (int)$e['connection_id']
                . ' entity=' . $e['external_entity_type'] . '/' . $e['external_entity_id']
                . ' outcome=' . $outcome
                . ' attempts=' . (int)$e['attempts']
                . ($message !== '' ? ' detail=' . substr($message, 0, 200) : ''));
        } catch (Throwable $t) {
            // Logging must never break event processing.
        }
    }

    /**
     * Drain pending events whose retry time has arrived, plus crashed
     * 'processing' rows past the stale threshold.
     * @return array {claimed:int, processed:int, entity_gone:int, retry:int, failed:int}
     */
    public static function processDue(PDO $pdo, int $limit = 25): array {
        $limit = max(1, min(200, $limit));
        $stmt = $pdo->prepare("
            SELECT id FROM integration_external_events
            WHERE (status = :received AND (next_retry_at IS NULL OR next_retry_at <= NOW()))
               OR (status = :processing AND (claimed_at IS NULL OR claimed_at <= (NOW() - INTERVAL :stale MINUTE)))
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([
            ':received'  => self::STATUS_RECEIVED,
            ':processing'=> self::STATUS_PROCESSING,
            ':stale'     => self::PROCESSING_STALE_MINUTES,
        ]);

        $summary = ['claimed' => 0, 'processed' => 0, 'entity_gone' => 0, 'retry' => 0, 'failed' => 0, 'duplicate' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $summary['claimed']++;
            $outcome = self::processEvent($pdo, (int)$id)['outcome'] ?? 'failed';
            if (isset($summary[$outcome])) {
                $summary[$outcome]++;
            }
        }
        return $summary;
    }

    // ----------------------------------------------------------------------
    // Failure classification
    // ----------------------------------------------------------------------

    private static function handleFetchFailure(PDO $pdo, int $eventId, array $event, Throwable $e): array {
        $category = $e instanceof OpenDentalApiException ? $e->category : 'unexpected_response';
        $retryAfter = $e instanceof OpenDentalApiException ? $e->retryAfterSeconds : null;
        $message = substr($e->getMessage(), 0, 500);

        // econnector_offline is transient too: the office may simply be
        // closed or mid-update; OD's own at-least-once delivery will also
        // resend for up to 3 days, generating fresh events.
        if (in_array($category, ['network', 'timeout', 'server_error', 'rate_limited', 'econnector_offline'], true)) {
            if ((int)$event['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
            }
            $delay = $retryAfter ?? min(self::DEFAULT_RETRY_SECONDS * ((int)$event['attempts'] + 1), self::MAX_RETRY_SECONDS);
            $pdo->prepare("
                UPDATE integration_external_events
                SET status = :status, next_retry_at = DATE_ADD(NOW(), INTERVAL :delay SECOND), last_error = :err
                WHERE id = :id
            ")->execute([
                ':status' => self::STATUS_RECEIVED,
                ':delay'  => $delay,
                ':err'    => $message,
                ':id'     => $eventId,
            ]);
            self::logEventOutcome($pdo, $eventId, 'retry', $message);
            return ['outcome' => 'retry', 'message' => $message];
        }

        return self::finalize($pdo, $eventId, self::STATUS_FAILED, $message);
    }

    private static function finalize(PDO $pdo, int $eventId, string $status, ?string $error): array {
        $pdo->prepare("
            UPDATE integration_external_events
            SET status = :status, last_error = :err, next_retry_at = NULL, processed_at = NOW()
            WHERE id = :id
        ")->execute([
            ':status' => $status,
            ':err'    => $error !== null ? substr($error, 0, 500) : null,
            ':id'     => $eventId,
        ]);
        $outcome = $status === self::STATUS_PROCESSED ? 'processed'
            : ($status === self::STATUS_ENTITY_GONE ? 'entity_gone' : 'failed');
        self::logEventOutcome($pdo, $eventId, $outcome, $error ?? '');
        return ['outcome' => $outcome, 'message' => $error ?? ''];
    }

    public static function findEvent(PDO $pdo, int $eventId): ?array {
        $stmt = $pdo->prepare("SELECT * FROM integration_external_events WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ----------------------------------------------------------------------
    // Subscription metadata (stored in config_json.subscription)
    // ----------------------------------------------------------------------

    /**
     * Decoded subscription block from config_json, or null. Contains only
     * non-secret metadata - the callback token exists solely as a hash.
     */
    public static function getSubscriptionConfig(array $connection): ?array {
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        $sub = is_array($config) ? ($config['subscription'] ?? null) : null;
        return is_array($sub) ? $sub : null;
    }

    /**
     * Merge fields into config_json.subscription, preserving unrelated
     * config keys. Pass ['enabled'=>false] to mark disabled; the block is
     * retained so the admin UI can show "previously configured" state.
     */
    public static function updateSubscriptionConfig(PDO $pdo, int $connectionId, array $fields): void {
        $connection = IntegrationManager::findConnection($pdo, $connectionId);
        if (!$connection) {
            return;
        }
        $config = json_decode((string)($connection['config_json'] ?? ''), true);
        if (!is_array($config)) {
            $config = [];
        }
        $sub = isset($config['subscription']) && is_array($config['subscription']) ? $config['subscription'] : [];
        $config['subscription'] = array_merge($sub, $fields);
        $pdo->prepare("UPDATE integration_connections SET config_json = :c WHERE id = :id")
            ->execute([':c' => json_encode($config), ':id' => $connectionId]);
    }

    /**
     * Build the public callback URL registered as Subscription.EndPointUrl.
     * The token appears in the URL once (at Open Dental); we persist only
     * its sha256 for verification.
     */
    public static function buildCallbackUrl(string $baseUrl, int $connectionId, string $token): string {
        return rtrim($baseUrl, '/') . '/api/integrations/open-dental-events.php'
            . '?c=' . $connectionId . '&k=' . $token;
    }

    public static function generateCallbackToken(): string {
        return bin2hex(random_bytes(24));
    }

    public static function hashCallbackToken(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Safe projection of subscription state for the settings UI. Contains
     * no token, no endpoint URL, no workstation name (ops detail), and no
     * credential material.
     */
    public static function subscriptionProjection(PDO $pdo, array $connection): ?array {
        $sub = self::getSubscriptionConfig($connection);
        if ($sub === null) {
            return ['status' => 'not_configured'];
        }

        $lastEvent = $pdo->prepare("
            SELECT MAX(received_at) FROM integration_external_events WHERE connection_id = :id
        ");
        $lastEvent->execute([':id' => (int)$connection['id']]);

        // Trustworthy counter: finalized case mappings, never raw events
        // (redeliveries can't inflate it).
        $imported = $pdo->prepare("
            SELECT COUNT(*) FROM integration_entity_mappings
            WHERE connection_id = :id AND entity_type = 'case' AND internal_id IS NOT NULL
        ");
        $imported->execute([':id' => (int)$connection['id']]);

        return [
            'status'                => !empty($sub['enabled']) ? 'active' : 'disabled',
            'watch_table'           => $sub['watch_table'] ?? null,
            'polling_seconds'       => isset($sub['polling_seconds']) ? (int)$sub['polling_seconds'] : null,
            'subscribed_at'         => $sub['subscribed_at'] ?? null,
            'disabled_at'           => $sub['disabled_at'] ?? null,
            'subsequent_failures'   => isset($sub['subsequent_failures']) ? (int)$sub['subsequent_failures'] : 0,
            'last_failure_reason'   => $sub['last_failure_reason'] ?? null,
            'last_event_received_at'=> $lastEvent->fetchColumn() ?: null,
            'cases_imported'        => (int)$imported->fetchColumn(),
        ];
    }
}
