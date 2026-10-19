<?php
/**
 * Lab Acknowledgment Action API Endpoint
 *
 * Actions on the optional lab acknowledgment workflow:
 *   confirm           - lab user confirms receipt; optional promisedDate
 *                       (empty = "Date not yet confirmed")
 *   request_info      - lab user requests missing information (requires a
 *                       description; recorded as a normal case comment)
 *   staff_confirm     - practice staff record a phone/email confirmation
 *                       received outside the app (labeled "Recorded by
 *                       practice staff", never presented as a lab action)
 *   respond           - practice staff answer a needs_info request: a normal
 *                       case comment (with optional image attachments
 *                       uploaded via the comments pipeline) is created and
 *                       the acknowledgment moves to awaiting_lab_review
 *   set_promised_date - two authorized writers for the lab-promised
 *                       return date:
 *                       - practice staff (can_edit_cases, not a lab
 *                         collaborator) - also while the setting is OFF;
 *                       - the currently assigned lab revising its own
 *                         promised date after confirming (setting ON).
 *                       Changing an existing date requires a short reason.
 *
 * SECURITY: server-side checks only - valid practice context, CSRF, the
 * case resolved to its own practice (consolidated view safe), case access,
 * then action-specific role checks (lab identity match for lab actions,
 * can_edit_cases + not-a-lab-collaborator for staff actions).
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/organization-access.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-acknowledgment.php';
require_once __DIR__ . '/user-display.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification-service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

requireCsrfToken();

$currentPracticeId = requireValidPracticeContext();
$currentUserId = $_SESSION['db_user_id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$action = (string)($input['action'] ?? '');
$caseId = trim((string)($input['caseId'] ?? ''));

if ($caseId === '' || !in_array($action, ['confirm', 'request_info', 'staff_confirm', 'set_promised_date', 'respond'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.lab_ack.invalid_request')]);
    exit;
}

// Resolve the case's own practice (consolidated "All practices" safe), then
// verify the case belongs to it and the user can access the case.
$currentPracticeId = resolveCaseScopePracticeId($caseId);
$case = requireCaseAccess($caseId, $currentPracticeId);

ensureLabAcknowledgmentSchema();
$enabled = isLabAcknowledgmentEnabled($currentPracticeId);
$permissions = getUserPracticePermissions($currentPracticeId);
$isLab = $permissions && !empty($permissions['is_lab']);
$canEdit = $permissions && !empty($permissions['can_edit_cases']);

// Archived cases are read-only history - no acknowledgment mutations.
if (!empty($case['archived'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('api.lab_ack.case_archived')]);
    exit;
}

$openAck = getOpenLabAcknowledgment($caseId, $currentPracticeId);

/**
 * The open acknowledgment row must still belong to the case's CURRENT lab
 * assignee. A response arriving after reassignment (or written through a
 * path that predates transition tracking) is stale: reject rather than
 * mutate the newly assigned lab's state.
 */
function labAckRowIsCurrent($ackRow, $case, $practiceId) {
    if (!$ackRow) {
        return false;
    }
    $resolved = resolveAssignee($practiceId, $case['assigned_to'] ?? '');
    if (!$resolved || empty($resolved['is_lab'])) {
        return false;
    }
    return labIdentityKey($resolved) === $ackRow['lab_key'];
}

function labAckRespond($ok, $payload = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok], $payload));
    exit;
}

/**
 * Rebuild the client view model after a mutation so the modal re-renders
 * from authoritative state (status, promised date, per-user action rights).
 */
function labAckViewPayload($caseId, $practiceId, $enabled, $userId, $canEdit, $isLab) {
    $row = getOpenLabAcknowledgment($caseId, $practiceId);
    $promised = null;
    try {
        global $pdo;
        $s = $pdo->prepare("SELECT lab_promised_date FROM cases_cache WHERE case_id = :c AND practice_id = :p LIMIT 1");
        $s->execute(['c' => (string)$caseId, 'p' => (int)$practiceId]);
        $promised = $s->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $promised = null;
    }
    return [
        'labAck' => $row ? buildLabAckViewModel($row, $enabled, (int)$userId, (bool)$canEdit, (bool)$isLab, false) : null,
        'labAckStatus' => $row ? $row['status'] : null,
        'labPromisedDate' => $promised,
    ];
}

/**
 * Verify client-submitted response image metadata against GCS. Mirrors
 * processCommentImages() in case-comments.php: same comments/ path prefix
 * (so attachment-content.php case-access covers the files), image
 * extensions only, configured caps, and object verification.
 *
 * @return array ['success' => bool, 'attachments' => array, 'errors' => array]
 */
function _labAckProcessResponseImages($images, $practiceId, $caseId) {
    global $appConfig;

    $result = ['success' => true, 'attachments' => [], 'errors' => []];
    if (!is_array($images) || empty($images)) {
        return $result;
    }

    $maxCount = (int)($appConfig['comments']['max_images'] ?? 6);
    if (count($images) > $maxCount) {
        $result['success'] = false;
        $result['errors'][] = t('api.comments.too_many_images', [
            'max' => $maxCount,
            'count' => count($images),
        ]);
        return $result;
    }

    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg'];
    $sizeByType = $appConfig['gcs']['max_file_size_by_type'] ?? [];
    $expectedPrefix = "cases/{$practiceId}/{$caseId}/comments/";

    foreach ($images as $index => $imageInfo) {
        $storagePath  = is_array($imageInfo) ? ($imageInfo['storage_path'] ?? '') : '';
        $originalName = is_array($imageInfo) ? basename((string)($imageInfo['original_filename'] ?? '')) : '';
        $contentType  = is_array($imageInfo) ? ($imageInfo['content_type'] ?? '') : '';
        $fileSize     = is_array($imageInfo) ? (int)($imageInfo['file_size'] ?? 0) : 0;

        if ($storagePath === '' || $originalName === '') {
            $result['errors'][] = t('api.comments.image_missing_fields', ['index' => $index]);
            $result['success'] = false;
            continue;
        }
        if (strpos($storagePath, $expectedPrefix) !== 0 || strpos($storagePath, '..') !== false) {
            $result['errors'][] = t('api.comments.image_invalid_path', ['name' => $originalName]);
            $result['success'] = false;
            continue;
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $imageExts)) {
            $result['errors'][] = t('api.comments.image_not_image', ['name' => $originalName]);
            $result['success'] = false;
            continue;
        }
        require_once __DIR__ . '/gcs-storage.php';
        $verification = verifyGcsUpload($storagePath, $fileSize, $contentType);
        if (!$verification['valid']) {
            $result['errors'][] = t('api.comments.image_verify_failed', [
                'name' => $originalName,
                'detail' => $verification['error'],
            ]);
            $result['success'] = false;
            continue;
        }
        $actualSize = $verification['size'];
        $maxForType = $sizeByType[$ext] ?? ($sizeByType['default'] ?? (100 * 1024 * 1024));
        if ($actualSize > $maxForType) {
            $result['errors'][] = t('api.comments.image_too_large', [
                'name' => $originalName,
                'limit' => round($maxForType / 1024 / 1024),
                'ext' => $ext,
            ]);
            $result['success'] = false;
            continue;
        }

        $result['attachments'][] = [
            'fileName'    => $originalName,
            'fileType'    => $contentType,
            'size'        => $actualSize,
            'storagePath' => $storagePath,
            'storageType' => 'gcs',
        ];
    }

    return $result;
}

$now = date('Y-m-d H:i:s');

// ---------------------------------------------------------------------
// Staff actions: record promised return date (setting-independent)
// ---------------------------------------------------------------------
if ($action === 'set_promised_date') {
    // Staff path stays available with the setting OFF; the lab path is an
    // acknowledgment action and therefore requires the setting ON plus a
    // current open acknowledgment the lab identity actually owns. A lab
    // can only revise its own promised date after confirming receipt, and
    // a previous lab is rejected after reassignment.
    $actorIsLab = false;
    if ($isLab || !$canEdit) {
        if (!$enabled) {
            labAckRespond(false, ['message' => t('api.lab_ack.disabled')], 403);
        }
        if (!$openAck
            || !labAckRowIsCurrent($openAck, $case, $currentPracticeId)
            || !userMatchesLabAckIdentity($currentUserId, $openAck)
            || $openAck['status'] !== 'confirmed') {
            labAckRespond(false, ['message' => t('api.lab_ack.lab_only')], 403);
        }
        $actorIsLab = true;
    }

    $newDate = normalizeLabAckDate($input['promisedDate'] ?? '');
    $rawNew = trim((string)($input['promisedDate'] ?? ''));
    if ($rawNew !== '' && $newDate === null) {
        labAckRespond(false, ['message' => t('api.lab_ack.invalid_date')], 400);
    }

    $oldDate = trim((string)($case['lab_promised_date'] ?? ''));
    $normalizedOld = $oldDate !== '' ? normalizeLabAckDate($oldDate) : null;

    // No-op: identical date submitted again (duplicate submissions safe).
    if (($normalizedOld ?? '') === ($newDate ?? '')) {
        labAckRespond(true, array_merge([
            'changed' => false,
        ], labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    // Changing an existing promised date requires a short reason.
    $reason = trim((string)($input['reason'] ?? ''));
    if ($normalizedOld !== null && $reason === '') {
        labAckRespond(false, ['message' => t('api.lab_ack.reason_required'), 'reasonRequired' => true], 422);
    }

    if (!updateCaseLabPromisedDate($caseId, $currentPracticeId, $newDate, $currentUserId, $reason)) {
        labAckRespond(false, ['message' => t('api.lab_ack.save_failed')], 500);
    }

    // Mirror onto the open ack row when there is one (history fidelity).
    if ($openAck && labAckRowIsCurrent($openAck, $case, $currentPracticeId)) {
        $stmt = $pdo->prepare("
            UPDATE case_lab_acknowledgments
            SET promised_return_date = :d, promised_by_user_id = :uid, promised_at = :now
            WHERE id = :id
        ");
        $stmt->execute(['d' => $newDate, 'uid' => (int)$currentUserId, 'now' => $now, 'id' => (int)$openAck['id']]);
    }

    logCaseActivity(
        $caseId,
        'lab_promised_date_changed',
        $normalizedOld,
        $newDate,
        [
            'old_date' => $normalizedOld,
            'new_date' => $newDate,
            'reason' => $reason !== '' ? $reason : null,
            // Lets case history tell the lab's own revision apart from a
            // staff-recorded change.
            'actor_role' => $actorIsLab ? 'lab' : 'staff',
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    // Promised-date notifications belong to the acknowledgment notification
    // family: suppressed while the setting is OFF (the staff-only date entry
    // stays available, just silent).
    if ($enabled) {
        emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_promised_date_changed', ['lab_promised_date_changed'], []);
    }

    labAckRespond(true, array_merge([
        'changed' => true,
        'message' => t('api.lab_ack.promised_saved'),
    ], labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
}

// ---------------------------------------------------------------------
// Lab/staff acknowledgment actions - require the setting and a current
// open acknowledgment row.
// ---------------------------------------------------------------------
if (!$enabled) {
    labAckRespond(false, ['message' => t('api.lab_ack.disabled')], 403);
}

if (!$openAck || !labAckRowIsCurrent($openAck, $case, $currentPracticeId)) {
    // Stale action (e.g. lab responding after reassignment) or no pending
    // acknowledgment for this case - never mutate another lab's record.
    labAckRespond(false, ['message' => t('api.lab_ack.no_pending'), 'stale' => true], 409);
}

$currentLabKey = $openAck['lab_key'];

if ($action === 'confirm') {
    // Only the assigned lab may confirm receipt as a lab action. Identity
    // match is the gate: it covers direct lab-user assignment and mapped
    // recipients of a lab label (who need no separate is_lab flag).
    if (!userMatchesLabAckIdentity($currentUserId, $openAck)) {
        labAckRespond(false, ['message' => t('api.lab_ack.lab_only')], 403);
    }

    // Duplicate submission: already confirmed -> idempotent success.
    if ($openAck['status'] === 'confirmed') {
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    $promisedDate = normalizeLabAckDate($input['promisedDate'] ?? '');
    $rawPromised = trim((string)($input['promisedDate'] ?? ''));
    if ($rawPromised !== '' && $promisedDate === null) {
        labAckRespond(false, ['message' => t('api.lab_ack.invalid_date')], 400);
    }

    $actorName = formatUserDisplayName($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '', $_SESSION['user_email'] ?? '', '');

    $stmt = $pdo->prepare("
        UPDATE case_lab_acknowledgments
        SET status = 'confirmed',
            promised_return_date = :d,
            promised_by_user_id = :puid,
            promised_at = :pat,
            confirmed_by_user_id = :cuid,
            confirmed_by_name = :cname,
            confirmed_at = :cat,
            confirmation_source = 'lab',
            confirmation_method = 'portal'
        WHERE id = :id AND status IN ('awaiting','needs_info','awaiting_lab_review')
    ");
    $stmt->execute([
        'd' => $promisedDate,
        'puid' => $promisedDate !== null ? (int)$currentUserId : null,
        'pat' => $promisedDate !== null ? $now : null,
        'cuid' => (int)$currentUserId,
        'cname' => $actorName,
        'cat' => $now,
        'id' => (int)$openAck['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        // Another request confirmed first (double-click/parallel tab).
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    _syncCaseAckStatusMirror($caseId, $currentPracticeId, 'confirmed');
    if ($promisedDate !== null) {
        updateCaseLabPromisedDate($caseId, $currentPracticeId, $promisedDate, $currentUserId, null);
    }

    logCaseActivity(
        $caseId,
        'lab_ack_confirmed',
        $openAck['status'],
        'confirmed',
        [
            'lab_display_name' => $openAck['lab_display_name'],
            'confirmed_by_name' => $actorName,
            'confirmation_source' => 'lab',
            'confirmation_method' => 'portal',
            'promised_return_date' => $promisedDate,
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_ack_confirmed', ['lab_ack_confirmed'], [
        'lab_ack_method' => 'portal',
    ]);

    labAckRespond(true, array_merge(['changed' => true],
        labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
}

if ($action === 'request_info') {
    if (!userMatchesLabAckIdentity($currentUserId, $openAck)) {
        labAckRespond(false, ['message' => t('api.lab_ack.lab_only')], 403);
    }

    $description = trim((string)($input['description'] ?? ''));
    if ($description === '') {
        labAckRespond(false, ['message' => t('api.lab_ack.description_required')], 400);
    }
    if (mb_strlen($description) > 2000) {
        $description = mb_substr($description, 0, 2000);
    }

    $userEmail = $_SESSION['user_email'] ?? '';
    $userName = formatUserDisplayName($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '', $userEmail, '');

    // Reuse case comments: the request is a normal comment authored by the
    // lab user, so it is visible to the practice in the existing thread.
    $commentText = t('api.lab_ack.info_request_prefix') . ' ' . $description;
    $stmt = $pdo->prepare("
        INSERT INTO case_comments
        (case_id, practice_id, user_id, user_name, user_email, comment_text)
        VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text)
    ");
    $stmt->execute([
        'case_id' => $caseId,
        'practice_id' => $currentPracticeId,
        'user_id' => (int)$currentUserId,
        'user_name' => $userName,
        'user_email' => $userEmail,
        'comment_text' => $commentText,
    ]);
    $commentId = (int)$pdo->lastInsertId();

    // Further information requests are allowed from any open state -
    // including confirmed (a confirmed lab can still flag missing info) and
    // awaiting_lab_review (the lab reviewed the response and needs more).
    // Confirmation fields stay on the row so history keeps the confirm.
    $stmt = $pdo->prepare("
        UPDATE case_lab_acknowledgments
        SET status = 'needs_info', info_requested_by = :uid,
            info_requested_at = :now, info_request_comment_id = :cid
        WHERE id = :id AND status <> 'superseded'
    ");
    $stmt->execute(['uid' => (int)$currentUserId, 'now' => $now, 'cid' => $commentId, 'id' => (int)$openAck['id']]);
    _syncCaseAckStatusMirror($caseId, $currentPracticeId, 'needs_info');

    logCaseActivity(
        $caseId,
        'lab_info_requested',
        $openAck['status'],
        'needs_info',
        [
            'lab_display_name' => $openAck['lab_display_name'],
            'comment_id' => $commentId,
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_info_requested', ['lab_info_requested'], [
        'comment_id' => $commentId,
    ]);

    labAckRespond(true, array_merge(['changed' => true, 'commentId' => $commentId],
        labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
}

if ($action === 'respond') {
    // Practice staff answer the lab's needs_info request. The response is a
    // normal case comment (author = the staff member) linked to the open
    // acknowledgment, then the state moves to awaiting_lab_review.
    if ($isLab || !$canEdit) {
        labAckRespond(false, ['message' => t('api.lab_ack.staff_only')], 403);
    }

    // Idempotent retry/double-submit: the transition already happened -
    // do not create a second comment, transition, or notification.
    if ($openAck['status'] === 'awaiting_lab_review') {
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }
    if ($openAck['status'] !== 'needs_info') {
        labAckRespond(false, ['message' => t('api.lab_ack.no_info_request')], 409);
    }

    $message = trim((string)($input['message'] ?? ''));
    if ($message === '') {
        labAckRespond(false, ['message' => t('api.lab_ack.message_required')], 400);
    }
    if (mb_strlen($message) > 2000) {
        $message = mb_substr($message, 0, 2000);
    }

    // Attachments arrive as already-uploaded GCS metadata (the client uses
    // the comments upload pipeline); verify every object before storing.
    $processedImages = _labAckProcessResponseImages($input['images'] ?? [], $currentPracticeId, $caseId);
    if (!$processedImages['success']) {
        labAckRespond(false, [
            'message' => !empty($processedImages['errors']) ? $processedImages['errors'][0] : t('api.lab_ack.save_failed'),
            'errors'  => $processedImages['errors'],
        ], 400);
    }

    $userEmail = $_SESSION['user_email'] ?? '';
    $userName = formatUserDisplayName($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '', $userEmail, '');
    $commentText = t('api.lab_ack.response_prefix') . ' ' . $message;

    $stmt = $pdo->prepare("
        INSERT INTO case_comments
        (case_id, practice_id, user_id, user_name, user_email, comment_text, attachments_json)
        VALUES (:case_id, :practice_id, :user_id, :user_name, :user_email, :comment_text, :attachments_json)
    ");
    $stmt->execute([
        'case_id' => $caseId,
        'practice_id' => $currentPracticeId,
        'user_id' => (int)$currentUserId,
        'user_name' => $userName,
        'user_email' => $userEmail,
        'comment_text' => $commentText,
        'attachments_json' => !empty($processedImages['attachments']) ? json_encode($processedImages['attachments']) : null,
    ]);
    $commentId = (int)$pdo->lastInsertId();

    // Guarded transition: a parallel/duplicated submission loses the race
    // and its just-inserted comment is soft-deleted so no duplicate stays.
    $stmt = $pdo->prepare("
        UPDATE case_lab_acknowledgments
        SET status = 'awaiting_lab_review',
            response_comment_id = :cid,
            responded_by = :uid,
            responded_at = :now
        WHERE id = :id AND status = 'needs_info'
    ");
    $stmt->execute([
        'cid' => $commentId,
        'uid' => (int)$currentUserId,
        'now' => $now,
        'id'  => (int)$openAck['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        $pdo->prepare("UPDATE case_comments SET is_deleted = 1, deleted_at = NOW(), deleted_by = :uid WHERE id = :id")
            ->execute(['uid' => (int)$currentUserId, 'id' => $commentId]);
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    _syncCaseAckStatusMirror($caseId, $currentPracticeId, 'awaiting_lab_review');

    logCaseActivity(
        $caseId,
        'lab_response_sent',
        $openAck['status'],
        'awaiting_lab_review',
        [
            'lab_display_name' => $openAck['lab_display_name'],
            'comment_id' => $commentId,
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_response_sent', ['lab_response_sent'], [
        'comment_id' => $commentId,
    ]);

    labAckRespond(true, array_merge([
        'changed' => true,
        'commentId' => $commentId,
        'message' => t('api.lab_ack.response_sent'),
    ], labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
}

if ($action === 'staff_confirm') {
    // Staff-recorded confirmation: practice members with case-edit rights
    // who are not lab collaborators.
    if ($isLab || !$canEdit) {
        labAckRespond(false, ['message' => t('api.lab_ack.staff_only')], 403);
    }

    $method = (string)($input['method'] ?? '');
    if (!in_array($method, ['phone', 'email'], true)) {
        labAckRespond(false, ['message' => t('api.lab_ack.method_required')], 400);
    }

    $promisedDate = normalizeLabAckDate($input['promisedDate'] ?? '');
    $rawPromised = trim((string)($input['promisedDate'] ?? ''));
    if ($rawPromised !== '' && $promisedDate === null) {
        labAckRespond(false, ['message' => t('api.lab_ack.invalid_date')], 400);
    }

    if ($openAck['status'] === 'confirmed') {
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    $staffName = formatUserDisplayName($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '', $_SESSION['user_email'] ?? '', '');

    $stmt = $pdo->prepare("
        UPDATE case_lab_acknowledgments
        SET status = 'confirmed',
            promised_return_date = COALESCE(:d, promised_return_date),
            promised_by_user_id = COALESCE(:puid, promised_by_user_id),
            promised_at = COALESCE(:pat, promised_at),
            confirmed_by_user_id = :cuid,
            confirmed_by_name = :cname,
            confirmed_at = :cat,
            confirmation_source = 'staff',
            confirmation_method = :method
        WHERE id = :id AND status IN ('awaiting','needs_info','awaiting_lab_review')
    ");
    $stmt->execute([
        'd' => $promisedDate,
        'puid' => $promisedDate !== null ? (int)$currentUserId : null,
        'pat' => $promisedDate !== null ? $now : null,
        'cuid' => (int)$currentUserId,
        'cname' => $staffName,
        'cat' => $now,
        'method' => $method,
        'id' => (int)$openAck['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        labAckRespond(true, array_merge(['changed' => false],
            labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
    }

    _syncCaseAckStatusMirror($caseId, $currentPracticeId, 'confirmed');
    if ($promisedDate !== null) {
        updateCaseLabPromisedDate($caseId, $currentPracticeId, $promisedDate, $currentUserId, null);
    }

    logCaseActivity(
        $caseId,
        'lab_ack_confirmed',
        $openAck['status'],
        'confirmed',
        [
            'lab_display_name' => $openAck['lab_display_name'],
            'confirmed_by_name' => $staffName,
            'confirmation_source' => 'staff',
            'confirmation_method' => $method,
            'promised_return_date' => $promisedDate,
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_ack_confirmed', ['lab_ack_confirmed'], [
        'lab_ack_method' => $method,
    ]);

    labAckRespond(true, array_merge(['changed' => true],
        labAckViewPayload($caseId, $currentPracticeId, $enabled, $currentUserId, $canEdit, $isLab)));
}

labAckRespond(false, ['message' => t('api.lab_ack.invalid_request')], 400);
