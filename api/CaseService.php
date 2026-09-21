<?php
/**
 * CaseService
 *
 * Shared business logic for creating a complete DentaTrak case. Both entry
 * points converge here:
 *
 *   - api/create-case.php            (browser POST: session, CSRF, billing,
 *                                     form parsing, file/GCS verification)
 *   - api/integrations/IntegrationEvents.php (trusted import: normalized
 *                                     CanonicalCase from a PMS adapter)
 *
 * The service owns everything AFTER request validation:
 *   - PII encryption and persistence (via createCase/createCacheOnlyCase)
 *   - lab-assignment initialization, case counts
 *   - case activity logging + user activity logging
 *   - realtime update recording (case_updates)
 *   - case_created notification emission (caller-controlled)
 *   - Google Drive backup orchestration (deferred or inline)
 *   - creator display-name resolution for the response
 *
 * CALLER CONTEXT (all server-verified; nothing here trusts client input):
 *   practice_id        int    REQUIRED. The ONLY practice context used.
 *   created_by_user_id ?int   NULL = system-created (integrations).
 *   actor_user_id      ?int   User credited for notifications/user-activity.
 *   source             string Activity source tag, e.g. 'create-case.php',
 *                             'integration:open_dental'.
 *   source_metadata    array  Extra PHI-free activity metadata (merged in).
 *   notify             bool   Emit normal case_created notification events.
 *   update_case_count  bool   Recount users.case_count for the actor.
 *   files              array  $_FILES (manual only; storage path disabled).
 *   gcs_attachments    array  Server-verified GCS attachment metadata.
 *   required_fields    array  Field names that must be non-empty.
 *   validate           bool   Enforce required_fields/caseType/status.
 *   truncate_notes     bool   Truncate notes >3000 chars (import) instead
 *                             of rejecting (the web endpoint rejects first).
 *   defer_drive_backup bool   Return backupData for the caller to run after
 *                             the HTTP response flushes (web endpoint).
 *   updated_by         string updated_by recorded in case_updates.
 *
 * RETURNS: ['success','message','caseData','missingFields'?,'warning'?,
 *           'backupData'?]
 */

require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/at-risk-calculator.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/case-types.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/notification-service.php';

class CaseService {

    /** Business rule: notes are capped at 3,000 characters. */
    const NOTES_MAX_LENGTH = 3000;

    /**
     * Create one case. See file docblock for the $context contract.
     * @param array $input Plaintext case fields (create-form naming).
     */
    public static function create(array $input, array $context): array {
        global $pdo;

        $practiceId = (int)($context['practice_id'] ?? 0);
        if ($practiceId <= 0) {
            return ['success' => false, 'message' => 'A valid practice context is required.'];
        }

        $validate = $context['validate'] ?? true;
        $missingFields = [];

        if ($validate) {
            $required = $context['required_fields'] ?? [
                'patientFirstName', 'patientLastName', 'patientDOB',
                'patientGender', 'dentistName', 'caseType', 'status',
            ];
            foreach ($required as $field) {
                if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
                    $missingFields[] = $field;
                }
            }

            $caseType = $input['caseType'] ?? '';
            if ($caseType !== '' && !isValidCaseType($caseType)) {
                return [
                    'success' => false,
                    'message' => 'Invalid case type.',
                    'field' => 'caseType',
                ];
            }

            if (isset($input['status']) && $input['status'] !== ''
                && !isValidWorkflowStatusForPractice($input['status'], $practiceId)) {
                return [
                    'success' => false,
                    'message' => 'Invalid workflow status for this practice.',
                    'field' => 'status',
                ];
            }
        }

        if (!empty($missingFields)) {
            return [
                'success' => false,
                'message' => 'Missing required fields: ' . implode(', ', $missingFields),
                'missingFields' => $missingFields,
            ];
        }

        // Notes length: the web endpoint rejects before calling; trusted
        // imports truncate so a long PMS instruction cannot kill the import.
        $maxNotes = self::NOTES_MAX_LENGTH;
        if (isset($input['notes']) && is_string($input['notes']) && strlen($input['notes']) > $maxNotes) {
            if (!empty($context['truncate_notes'])) {
                $input['notes'] = substr($input['notes'], 0, $maxNotes);
                $context['source_metadata']['notes_truncated'] = true;
            } else {
                return [
                    'success' => false,
                    'message' => 'Notes exceed the maximum length.',
                    'field' => 'notes',
                ];
            }
        }

        $createdByUserId = $context['created_by_user_id'] ?? null;
        $actorUserId = $context['actor_user_id'] ?? $createdByUserId;
        $source = $context['source'] ?? 'CaseService';
        $notify = $context['notify'] ?? true;
        $updateCaseCount = $context['update_case_count'] ?? ($actorUserId !== null);
        $files = $context['files'] ?? [];
        $gcsAttachments = $context['gcs_attachments'] ?? [];

        $input['createdByUserId'] = $createdByUserId;
        $input['practice_id'] = $practiceId;

        // Encrypt PII, then create through the same storage path the
        // endpoint always used (Drive-primary or cache-only fallback).
        $encryptedCaseData = PIIEncryption::encryptCaseData($input);
        $result = createCase($encryptedCaseData, $files, $input, $gcsAttachments, $practiceId);

        // Legacy workaround: an old google/apiclient can die inside the
        // Drive path with an implode() TypeError. Preserve the existing
        // simulated-case fallback for the manual flow only.
        if (!$result['success']
            && !empty($context['simulate_on_implode_bug'])
            && isset($result['message'])
            && strpos($result['message'], 'implode(') !== false) {
            error_log('Google client implode error in CaseService: ' . $result['message']);
            $result = [
                'success'  => true,
                'message'  => t('api.cases.created_local'),
                'caseData' => self::simulatedCaseData($input),
            ];
        }

        if (!$result['success'] || empty($result['caseData']) || !is_array($result['caseData'])) {
            return $result;
        }

        $createdCaseId = $result['caseData']['id'] ?? null;
        if (!$createdCaseId) {
            return ['success' => false, 'message' => 'Case creation produced no case id.'];
        }

        // Persist the canonical copy (re-encrypt the plaintext response;
        // saveCaseToCache upserts on case_id so the double write inside
        // createCacheOnlyCase remains harmless).
        $encryptedForCache = PIIEncryption::encryptCaseData($result['caseData']);
        $encryptedForCache['practice_id'] = $practiceId;
        saveCaseToCache($encryptedForCache);

        // saveCaseToCache swallows PDO errors; verify the row actually
        // exists so callers (especially the exact-once importer) can react.
        $verify = $pdo->prepare("SELECT case_id FROM cases_cache WHERE case_id = :id LIMIT 1");
        $verify->execute([':id' => $createdCaseId]);
        if (!$verify->fetchColumn()) {
            return ['success' => false, 'message' => 'Case persistence verification failed.'];
        }

        // Lab Insights foundation: record the initial assignment transition.
        // No-op when the initial assignee is not a lab-designated user/label.
        recordLabAssignmentChange($createdCaseId, $practiceId, '', $result['caseData']['assignedTo'] ?? '');

        // Update the creating user's case count (manual path only - a
        // system-created case has no user whose count should move).
        if ($updateCaseCount && $actorUserId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
            $stmt->execute([$practiceId]);
            $newCaseCount = (int)$stmt->fetchColumn();
            $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
            $stmt->execute([$newCaseCount, $actorUserId]);
        }

        // ------------------------------------------------------------------
        // Case activity
        // ------------------------------------------------------------------
        $createdStatus = $result['caseData']['status'] ?? null;
        $activityMeta = array_merge(
            [
                'source' => $source,
                'has_attachments' => !empty($result['caseData']['attachments']),
                'has_notes' => !empty($result['caseData']['notes']),
            ],
            $context['source_metadata'] ?? []
        );
        logCaseActivity($createdCaseId, 'case_created', null, $createdStatus, $activityMeta, null, false, $actorUserId);

        if ($actorUserId && function_exists('logUserActivity')) {
            logUserActivity((int)$actorUserId, 'create_case', "User created case {$createdCaseId}");
        }

        $attachments = $result['caseData']['attachments'] ?? [];
        if (is_array($attachments) && count($attachments) > 0) {
            logCaseActivity($createdCaseId, 'attachments_added', null, null, [
                'count' => count($attachments),
                'source' => $source,
                'attachment_count' => count($attachments),
            ]);
        }

        $notes = $result['caseData']['notes'] ?? '';
        if ($notes !== '') {
            logCaseActivity($createdCaseId, 'notes_updated', null, null, [
                'length' => strlen($notes),
                'source' => $source,
            ]);
        }

        $result['caseData']['atRisk'] = calculateAtRiskStatus(
            $result['caseData'], null, getLastActiveWorkflowColumnId($practiceId)
        );

        // Realtime update feed for other viewers of this practice.
        if (function_exists('recordCaseUpdate')) {
            recordCaseUpdate(
                $createdCaseId, 'create', null, null,
                $practiceId, $context['updated_by'] ?? 'system'
            );
        }

        // Structured in-app notification (Phase 2 pipeline). Caller sets
        // notify=false for silent imports (e.g. future backfill); a NULL
        // actor also yields no notifications, so integration imports are
        // naturally quiet.
        if ($notify && $actorUserId) {
            try {
                $categories = buildCreateCaseNotificationCategories($result['caseData'], is_array($attachments) ? $attachments : []);
                $metadata = buildCreateCaseNotificationMetadata($result['caseData'], is_array($attachments) ? $attachments : []);
                $eventType = getPrimaryNotificationType($categories);
                emitCaseNotificationEvent($practiceId, $createdCaseId, (int)$actorUserId, $eventType, $categories, $metadata);
            } catch (Throwable $e) {
                error_log('[CaseService] notification emit error (non-fatal): ' . $e->getMessage());
            }
        }

        // Creator display name for immediate UI rendering.
        if (isset($result['caseData']['createdByUserId']) && !isset($result['caseData']['createdByName']) && $pdo) {
            try {
                $creatorStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1");
                $creatorStmt->execute(['id' => (int)$result['caseData']['createdByUserId']]);
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                if ($creator) {
                    require_once __DIR__ . '/user-display.php';
                    $name = formatUserDisplayName($creator['first_name'] ?? '', $creator['last_name'] ?? '', $creator['email'] ?? '', '');
                    if ($name !== '') {
                        $result['caseData']['createdByName'] = $name;
                    }
                }
            } catch (Exception $e) {
                // Leave as Unknown on lookup error
            }
        }

        // ------------------------------------------------------------------
        // Google Drive backup
        // ------------------------------------------------------------------
        // Web endpoint: defer - it must run AFTER the HTTP response flushes
        // (fastcgi_finish_request). Worker: run inline, best-effort - Drive
        // tokens live in the browser session so a session-free caller simply
        // gets a no-op (getBackupRootFolder returns null).
        if (isGoogleDriveBackupEnabled($practiceId)) {
            $backupData = [
                'caseData' => $result['caseData'],
                'caseId' => $createdCaseId,
                'practiceId' => $practiceId,
                'practiceName' => self::practiceName($practiceId),
                'attachments' => is_array($attachments) ? $attachments : [],
            ];
            if (!empty($context['defer_drive_backup'])) {
                $result['backupData'] = $backupData;
            } else {
                self::runDriveBackup($backupData);
            }
        }

        return $result;
    }

    /**
     * Build the integration-context case input from a normalized
     * CanonicalCase. Provider-agnostic: adapters own field extraction;
     * this only maps canonical fields onto DentaTrak's create schema.
     *
     * Import-once semantics: fields are copied at creation time only -
     * later PMS changes never touch the stored case (that is a separate
     * synchronization phase).
     */
    public static function caseInputFromCanonical(CanonicalCase $canonical, int $practiceId): array {
        return [
            'patientFirstName' => $canonical->patientFirstName ?? '',
            'patientLastName'  => $canonical->patientLastName ?? '',
            'patientDOB'       => $canonical->patientDob,
            'patientGender'    => $canonical->patientGender,
            'dentistName'      => $canonical->providerName ?? '',
            // PMS LabCases expose no structured case type - never guess.
            'caseType'         => CASE_TYPE_NEEDS_CLASSIFICATION,
            'toothShade'       => null,
            'material'         => null,
            'dueDate'          => $canonical->dueDate,
            'patientAppointmentDate' => $canonical->appointmentDate,
            // Same originating workflow stage a brand-new manual case gets.
            'status'           => getFirstActiveWorkflowColumnId($practiceId),
            'notes'            => $canonical->instructions ?? '',
            'assignedTo'       => '',
            'carrier'          => '',
            'trackingNumber'   => '',
            'customCarrier'    => '',
            'clinicalDetails'  => null,
        ];
    }

    /**
     * Core fields a trusted integration import must have. Deliberately
     * narrower than the manual form's required set: PMS records may lack
     * DOB/gender/provider, and missing optional data must not block import.
     * caseType/status are enforced separately by the caller/validation.
     */
    public static function integrationRequiredFields(): array {
        return ['patientFirstName', 'patientLastName', 'caseType', 'status'];
    }

    /** Practice name lookup that works without a session (worker-safe). */
    private static function practiceName(int $practiceId): string {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
            $stmt->execute(['id' => $practiceId]);
            $name = $stmt->fetchColumn();
            return $name ?: 'Practice ' . $practiceId;
        } catch (Exception $e) {
            return 'Practice ' . $practiceId;
        }
    }

    /** Best-effort Drive backup; failures are logged, never fatal. */
    private static function runDriveBackup(array $backupData): void {
        global $pdo;
        try {
            $backupRootFolderId = getBackupRootFolder($backupData['practiceId'], $backupData['practiceName']);
            if (!$backupRootFolderId) {
                return;
            }
            $backupFolderId = createCaseBackupFolder(
                $backupData['caseData'],
                $backupRootFolderId,
                $backupData['attachments']
            );
            if ($backupFolderId) {
                $stmt = $pdo->prepare("UPDATE cases_cache SET backup_folder_id = :bf WHERE case_id = :cid");
                $stmt->execute([':bf' => $backupFolderId, ':cid' => $backupData['caseId']]);
            }
        } catch (Exception $e) {
            error_log('[CaseService] Backup error (non-blocking): ' . $e->getMessage());
        }
    }

    /** Simulated case payload for the legacy Google implode() fallback. */
    private static function simulatedCaseData(array $input): array {
        return [
            'id'              => 'sim_' . uniqid(),
            'driveFolderId'   => null,
            'patientFirstName'=> $input['patientFirstName'] ?? '',
            'patientLastName' => $input['patientLastName'] ?? '',
            'patientDOB'      => $input['patientDOB'] ?? null,
            'patientGender'   => $input['patientGender'] ?? null,
            'dentistName'     => $input['dentistName'] ?? '',
            'caseType'        => $input['caseType'] ?? '',
            'toothShade'      => $input['toothShade'] ?? null,
            'material'        => $input['material'] ?? null,
            'dueDate'         => $input['dueDate'] ?? null,
            'patientAppointmentDate' => $input['patientAppointmentDate'] ?? '',
            'creationDate'    => date('c'),
            'lastUpdateDate'  => date('c'),
            'status'          => $input['status'] ?? 'Originated',
            'notes'           => $input['notes'] ?? '',
            'assignedTo'      => $input['assignedTo'] ?? '',
            'carrier'         => $input['carrier'] ?? '',
            'trackingNumber'  => $input['trackingNumber'] ?? '',
            'customCarrier'   => $input['customCarrier'] ?? '',
            'clinicalDetails' => $input['clinicalDetails'] ?? null,
            'createdByUserId' => $input['createdByUserId'] ?? null,
            'revisions'       => [],
            'attachments'     => [],
        ];
    }
}
