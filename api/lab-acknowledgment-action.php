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
 *   set_promised_date - practice staff record/change the lab-promised
 *                       return date; changing an existing date requires a
 *                       short reason. Available even when the practice
 *                       setting is OFF.
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

if ($caseId === '' || !in_array($action, ['confirm', 'request_info', 'staff_confirm', 'set_promised_date'], true)) {
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

$now = date('Y-m-d H:i:s');

// ---------------------------------------------------------------------
// Staff actions: record promised return date (setting-independent)
// ---------------------------------------------------------------------
if ($action === 'set_promised_date') {
    if ($isLab || !$canEdit) {
        labAckRespond(false, ['message' => t('api.lab_ack.staff_only')], 403);
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
            'source' => 'lab-acknowledgment-action.php',
        ],
        null,
        false,
        $currentUserId
    );

    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'update');
    }
    emitCaseNotificationEvent($currentPracticeId, $caseId, $currentUserId, 'lab_promised_date_changed', ['lab_promised_date_changed'], []);

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
        WHERE id = :id AND status IN ('awaiting','needs_info')
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

    // needs_info is not a regression from confirmed - a confirmed case
    // cannot move back (lab would need to request again via comments).
    if ($openAck['status'] !== 'confirmed') {
        $stmt = $pdo->prepare("
            UPDATE case_lab_acknowledgments
            SET status = 'needs_info', info_requested_by = :uid,
                info_requested_at = :now, info_request_comment_id = :cid
            WHERE id = :id AND status <> 'superseded'
        ");
        $stmt->execute(['uid' => (int)$currentUserId, 'now' => $now, 'cid' => $commentId, 'id' => (int)$openAck['id']]);
        _syncCaseAckStatusMirror($caseId, $currentPracticeId, 'needs_info');
    }

    logCaseActivity(
        $caseId,
        'lab_info_requested',
        $openAck['status'],
        $openAck['status'] !== 'confirmed' ? 'needs_info' : $openAck['status'],
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
        WHERE id = :id AND status IN ('awaiting','needs_info')
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
