<?php
/**
 * Practice-Wide Two-Factor Authentication Policy API
 *
 * Lets Practice Owners/Admins view and change the practice-level
 * "require 2FA for all users" setting, and see per-member 2FA status.
 *
 * Security:
 * - Authenticated session + valid practice context + admin role required
 * - Lab collaborators are denied
 * - POST mutations require CSRF
 * - Enabling requires the ACTOR to already have 2FA configured - this
 *   prevents an admin from locking themselves (and the practice) out
 * - Responses never include TOTP secrets, codes, or recovery material;
 *   only the boolean configured/not-configured status per member
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/unified-identity.php';
require_once __DIR__ . '/2fa-recovery-helpers.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$currentPracticeId = requireValidPracticeContext();
$userId = (int)$_SESSION['db_user_id'];

requirePracticeAdmin($currentPracticeId);
requireNotLabCollaborator($currentPracticeId, t('settings.external_collaborator_denied'));

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        handleStatus((int)$currentPracticeId);
        break;
    case 'update':
        handleUpdate((int)$currentPracticeId, $userId);
        break;
    case 'send_member_recovery':
        handleSendMemberRecovery((int)$currentPracticeId, $userId);
        break;
    case 'revoke_member_sessions':
        handleRevokeMemberSessions((int)$currentPracticeId, $userId);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Return the policy state plus per-member 2FA status (no secrets).
 */
function handleStatus(int $practiceId): void {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name,
                   IFNULL(u.totp_enabled, 0) AS totp_enabled,
                   pu.role, pu.is_owner
            FROM practice_users pu
            JOIN users u ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id AND u.is_active = 1
            ORDER BY pu.is_owner DESC, u.first_name ASC, u.last_name ASC, u.email ASC
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[practice-2fa-policy] status failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error loading 2FA status']);
        return;
    }

    $enabled = 0;
    $list = array_map(function ($m) use (&$enabled) {
        $has2fa = ((int)$m['totp_enabled'] === 1);
        if ($has2fa) {
            $enabled++;
        }
        $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        return [
            'id' => (int)$m['id'],
            'name' => $name !== '' ? $name : $m['email'],
            'email' => $m['email'],
            'role' => $m['role'],
            'is_owner' => (bool)$m['is_owner'],
            'totp_enabled' => $has2fa
        ];
    }, $members);

    echo json_encode([
        'success' => true,
        'required' => practiceRequires2FA($practiceId),
        'counts' => [
            'total' => count($list),
            'enabled' => $enabled,
            'needs_setup' => count($list) - $enabled
        ],
        'members' => $list,
        'actor_has_2fa' => userHas2FAConfigured((int)$_SESSION['db_user_id'])
    ]);
}

/**
 * Enable or disable the practice-wide requirement.
 *
 * Enabling additionally requires the acting admin to have 2FA configured
 * already - otherwise they would immediately lose practice access. The
 * caller is told to route the actor through enrollment and retry.
 */
function handleUpdate(int $practiceId, int $userId): void {
    global $pdo;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    requireCsrfToken();

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $enabled = !empty($data['enabled']);

    // Schema guard: if the column has not been migrated, refuse cleanly.
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'require_2fa'");
        if (!$colStmt || !$colStmt->fetch()) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error_code' => 'SCHEMA_NOT_MIGRATED',
                'message' => 'Two-factor enforcement is not available yet. Please run pending migrations.'
            ]);
            return;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error checking 2FA policy schema']);
        return;
    }

    // Owner/admin lockout protection: the actor must already satisfy the
    // policy they are about to enforce on everyone else.
    if ($enabled && !userHas2FAConfigured($userId)) {
        http_response_code(428);
        echo json_encode([
            'success' => false,
            'error_code' => 'ACTOR_2FA_REQUIRED',
            'message' => t('settings.security.practice_2fa.actor_setup_required'),
            'redirect' => '2fa-required.php?practice_id=' . $practiceId . '&return=settings'
        ]);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE practices SET require_2fa = :enabled WHERE id = :id");
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $practiceId]);
    } catch (PDOException $e) {
        error_log('[practice-2fa-policy] update failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating 2FA policy']);
        return;
    }

    $event = $enabled ? 'practice_2fa_required_enabled' : 'practice_2fa_required_disabled';
    logSecurityEvent($event, [
        'actor_user_id' => $userId,
        'practice_id' => $practiceId
    ]);
    if (function_exists('logUserActivity')) {
        logUserActivity($userId, $event,
            ($enabled ? 'Enabled' : 'Disabled') . " practice-wide 2FA requirement for practice {$practiceId}");
    }

    echo json_encode([
        'success' => true,
        'required' => $enabled,
        'message' => $enabled
            ? t('settings.security.practice_2fa.enabled_success')
            : t('settings.security.practice_2fa.disabled_success')
    ]);
}

/**
 * Admin-initiated 2FA recovery for a practice member.
 *
 * IMPORTANT: this does NOT disable the member's 2FA. It only issues the
 * same member-verified emailed recovery token used by the self-service
 * flow - the member still has to prove mailbox control AND re-verify
 * their identity before anything resets. The request simply records who
 * initiated it for audit.
 */
function handleSendMemberRecovery(int $practiceId, int $actorId): void {
    global $pdo;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    requireCsrfToken();

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $memberId = (int)($data['member_id'] ?? 0);

    // The target must be an active member of THIS practice with 2FA on -
    // admins can never reach across practices or reset an account that
    // has nothing to recover.
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.totp_enabled
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id AND u.id = :member_id AND u.is_active = 1
    ");
    $stmt->execute(['practice_id' => $practiceId, 'member_id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('settings.security.practice_2fa.recovery_member_not_found')]);
        return;
    }
    if (empty($member['totp_enabled'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => t('settings.security.practice_2fa.recovery_not_needed')]);
        return;
    }

    $token = issue2FAResetToken((int)$member['id'], $actorId);
    if (!$token) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('settings.security.practice_2fa.recovery_send_failed')]);
        return;
    }

    send2FARecoveryEmail($member, $token, true);

    logSecurityEvent('admin_2fa_reset_requested', [
        'actor_user_id' => $actorId,
        'affected_user_id' => (int)$member['id'],
        'practice_id' => $practiceId
    ]);
    if (function_exists('logUserActivity')) {
        logUserActivity($actorId, 'admin_2fa_reset_requested',
            "Admin sent a 2FA recovery link to member {$member['id']} in practice {$practiceId}");
    }

    echo json_encode([
        'success' => true,
        'message' => t('settings.security.practice_2fa.recovery_sent')
    ]);
}

/**
 * Admin-initiated account-wide session revocation for a practice member.
 *
 * Signs the target member out of every browser and device on their
 * DentaTrak account (all practices) and rejects their remember-me
 * cookies. Uses the centralized revokeAllUserSessions() helper - the
 * target must be an active member of THE CURRENT practice, so knowing
 * a user ID never grants reach across tenant boundaries.
 */
function handleRevokeMemberSessions(int $practiceId, int $actorId): void {
    global $pdo;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }

    requireCsrfToken();

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $memberId = (int)($data['member_id'] ?? 0);

    // Same membership gate as send_member_recovery: the target must be an
    // active member of this practice. A user ID alone is never sufficient.
    $stmt = $pdo->prepare("
        SELECT u.id
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id AND u.id = :member_id AND u.is_active = 1
    ");
    $stmt->execute(['practice_id' => $practiceId, 'member_id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => t('settings.security.practice_2fa.recovery_member_not_found')]);
        return;
    }

    try {
        $newVersion = revokeAllUserSessions((int)$member['id'], 'admin_revocation', $actorId);
    } catch (Throwable $e) {
        error_log('[practice-2fa-policy] member session revocation failed: ' . $e->getMessage());
        logSecurityEvent('admin_user_sessions_revoke_failed', [
            'actor_user_id' => $actorId,
            'affected_user_id' => (int)$member['id'],
            'practice_id' => $practiceId
        ]);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => t('settings.security.practice_2fa.signout_failed')]);
        return;
    }

    // Self-target keeps the admin's CURRENT session alive - same semantics
    // as the self-service action. Every other session of the target
    // (including the admin's own other devices when self-targeting) still
    // carries the older stamp and fails on its next request.
    if ($memberId === $actorId) {
        $_SESSION['auth_version'] = $newVersion;
    }

    logSecurityEvent('admin_user_sessions_revoked', [
        'actor_user_id' => $actorId,
        'affected_user_id' => (int)$member['id'],
        'practice_id' => $practiceId,
        'reason' => 'admin_revocation'
    ]);
    if (function_exists('logUserActivity')) {
        logUserActivity($actorId, 'admin_user_sessions_revoked',
            "Admin signed out member {$member['id']} on all devices in practice {$practiceId}");
    }

    echo json_encode([
        'success' => true,
        'message' => t('settings.security.practice_2fa.signout_success')
    ]);
}
