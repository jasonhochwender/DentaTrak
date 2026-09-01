<?php
/**
 * Admin Practices API
 * 
 * Provides admin functionality for managing practices:
 * - List all practices with HIPAA compliance status
 * - Activate/deactivate practices
 * - View PHI access logs
 * - Data retention management
 * 
 * SECURITY: Only accessible by system admins (users with is_system_admin = true)
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/schema-helpers.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/admin-subscription-helpers.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');
ob_start();

// Suppress raw PHP error output for this API boundary. Errors are still logged;
// unexpected ones are converted below to a safe JSON 500 response.
ini_set('display_errors', '0');

// Catch any uncaught Throwable (Error or Exception) anywhere in this script and
// return a safe JSON 500 without exposing SQL, paths, or stack traces.
set_exception_handler(function (Throwable $e) {
    error_log('[admin-practices] Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(['success' => false, 'error' => 'Unable to load practice information.']);
    exit;
});

// Last-resort guard for fatal PHP errors (E_ERROR, E_PARSE, E_COMPILE_ERROR, etc.).
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    error_log('[admin-practices] Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(['success' => false, 'error' => 'Unable to load practice information.']);
});

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Load dev tools access control
require_once __DIR__ . '/dev-tools-access.php';

// Authorization: admin tools require either super-user privileges or an
// explicit development environment. The 'development' environment is the
// MAMP local DB configuration (127.0.0.1:3308); it must be strictly local and
// isolated, never exposed to the network or used with production data. In
// production, UAT, and Cloud Run, only configured super users can access these
// endpoints. All state-changing actions below enforce CSRF.
$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super user privileges required.']);
    exit;
}

/**
 * Record an admin email action to the audit log. The admin_email_log table must
 * already exist (created by migrations/2026_09_01_admin_email_log.php).
 */
function logAdminEmail($adminUserId, $adminEmail, $recipientUserId, $recipientEmail, $practiceId, $emailType, $subject, $success, $provider = null, $error = null) {
    global $pdo;

    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_email_log (admin_user_id, admin_email, recipient_user_id, recipient_email, practice_id, email_type, email_subject, success, provider, error_message)
            VALUES (:admin_user_id, :admin_email, :recipient_user_id, :recipient_email, :practice_id, :email_type, :email_subject, :success, :provider, :error_message)
        ");
        $stmt->execute([
            ':admin_user_id' => $adminUserId,
            ':admin_email' => $adminEmail,
            ':recipient_user_id' => $recipientUserId,
            ':recipient_email' => $recipientEmail,
            ':practice_id' => $practiceId,
            ':email_type' => $emailType,
            ':email_subject' => $subject,
            ':success' => $success ? 1 : 0,
            ':provider' => $provider,
            ':error_message' => $error,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error logging admin email: ' . $e->getMessage());
        return false;
    }
}

/**
 * Compose and send an admin-triggered practice-user email.
 *
 * @return array { success: bool, message: string, provider: ?string, error: ?string }
 */
function sendAdminPracticeEmail(PDO $pdo, int $adminUserId, string $adminEmail, int $practiceId, int $recipientUserId, string $emailType, string $customSubject = '', string $customMessage = ''): array {
    global $appConfig;

    // Verify the recipient actually belongs to the selected practice
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name, p.practice_name
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        JOIN practices p ON p.id = pu.practice_id
        WHERE pu.practice_id = :practice_id AND u.id = :user_id
        LIMIT 1
    ");
    $stmt->execute(['practice_id' => $practiceId, 'user_id' => $recipientUserId]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient) {
        return ['success' => false, 'message' => 'Recipient not found or does not belong to this practice.', 'provider' => null, 'error' => null];
    }

    $toName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
    $toEmail = $recipient['email'];
    $practiceName = $recipient['practice_name'] ?? 'Your Practice';
    $firstName = $recipient['first_name'] ?? '';
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
    $fromName = $appConfig['email_from_name'] ?? 'DentaTrak';
    $baseUrl = rtrim($appConfig['app_base_url'] ?? 'https://dentatrak.com', '/');
    $loginUrl = $baseUrl . '/login.php';
    $userGuideUrl = $appConfig['user_guide_url'] ?? ($baseUrl . '/resources/user-guide');

    $subject = '';
    $html = '';
    $text = '';

    if ($emailType === 'getting_started') {
        $subject = 'Getting started with DentaTrak';
        $greeting = $firstName ? "Hi {$firstName}," : "Hi there,";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  <p>You have access to <strong>DentaTrak</strong> for <strong>{$practiceName}</strong>.</p>
  <p>DentaTrak helps dental practices track cases across the office, labs, and referrals without losing them in spreadsheets or PMS systems.</p>
  <p><a href="{$loginUrl}">Sign in to DentaTrak</a></p>
  <p>Get started with the <a href="{$userGuideUrl}">DentaTrak User Guide</a>.</p>
  <p>If you have any questions, reach out to us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Thanks,<br>{$fromName} Team</p>
</body>
</html>
HTML;

        $text = <<<TEXT
{$greeting}

You have access to DentaTrak for "{$practiceName}".

DentaTrak helps dental practices track cases across the office, labs, and referrals without losing them in spreadsheets or PMS systems.

Sign in: {$loginUrl}

Get started with the User Guide: {$userGuideUrl}

If you have any questions, reach out to us at {$supportEmail}.

Thanks,
{$fromName} Team
TEXT;
    } elseif ($emailType === 'user_guide') {
        $subject = 'DentaTrak User Guide';
        $greeting = $firstName ? "Hi {$firstName}," : "Hi there,";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  <p>Here is a link to the <a href="{$userGuideUrl}">DentaTrak User Guide</a> for <strong>{$practiceName}</strong>.</p>
  <p>The guide covers the most common DentaTrak workflows and is a good place to start if you are new to the platform.</p>
  <p><a href="{$loginUrl}">Sign in to DentaTrak</a></p>
  <p>If you have any questions, reach out to us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Thanks,<br>{$fromName} Team</p>
</body>
</html>
HTML;

        $text = <<<TEXT
{$greeting}

Here is a link to the DentaTrak User Guide for "{$practiceName}":

{$userGuideUrl}

The guide covers the most common DentaTrak workflows and is a good place to start if you are new to the platform.

Sign in: {$loginUrl}

If you have any questions, reach out to us at {$supportEmail}.

Thanks,
{$fromName} Team
TEXT;
    } elseif ($emailType === 'trial_reminder') {
        // Resolve the effective subscription/trial for this practice's owner
        $ownerUserId = getSubscriptionOwnerUserId($pdo, $practiceId);
        if (!$ownerUserId) {
            return ['success' => false, 'message' => 'Could not determine subscription owner for this practice.', 'provider' => null, 'error' => null];
        }

        $sub = getSubscriptionForOwner($pdo, $ownerUserId);
        if (empty($sub) || ($sub['status'] ?? '') !== 'trialing' || empty($sub['trial_ends_at'])) {
            return ['success' => false, 'message' => 'This practice is not currently in a trial.', 'provider' => null, 'error' => null];
        }

        $storedPlan = $sub['plan'] ?? null;
        $planDisplay = !empty($storedPlan) ? getPlanDisplayName($storedPlan) : '—';

        try {
            $end = new DateTimeImmutable($sub['trial_ends_at'], new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $days = (int)$now->diff($end)->format('%r%a');

            if ($days > 1) {
                $remainingText = "{$days} days remaining";
            } elseif ($days === 1) {
                $remainingText = "1 day remaining";
            } elseif ($days === 0) {
                $remainingText = "ends today";
            } else {
                $remainingText = "has expired";
            }

            $trialEndDate = $end->format('M j, Y');
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Could not parse trial end date.', 'provider' => null, 'error' => $e->getMessage()];
        }

        $subject = 'Your DentaTrak trial is ending soon';
        $greeting = $firstName ? "Hi {$firstName}," : "Hi there,";

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  <p>This is a friendly reminder that the DentaTrak trial for <strong>{$practiceName}</strong> ({$planDisplay} plan) {$remainingText}.</p>
  <p><strong>Trial end date:</strong> {$trialEndDate}</p>
  <p><a href="{$loginUrl}">Sign in to DentaTrak</a></p>
  <p>If you have any questions, reach out to us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Thanks,<br>{$fromName} Team</p>
</body>
</html>
HTML;

        $text = <<<TEXT
{$greeting}

This is a friendly reminder that the DentaTrak trial for "{$practiceName}" ({$planDisplay} plan) {$remainingText}.

Trial end date: {$trialEndDate}

Sign in: {$loginUrl}

If you have any questions, reach out to us at {$supportEmail}.

Thanks,
{$fromName} Team
TEXT;
    } elseif ($emailType === 'custom') {
        $subject = trim($customSubject);
        $message = trim($customMessage);
        if ($subject === '' || $message === '') {
            return ['success' => false, 'message' => 'Custom email subject and message are required.', 'provider' => null, 'error' => null];
        }

        $greeting = $firstName ? "Hi {$firstName}," : "Hi there,";
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeMessageHtml = nl2br($safeMessage, false);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<body>
  <p>{$greeting}</p>
  <p>{$safeMessageHtml}</p>
  <p>If you have any questions, reach out to us at <a href="mailto:{$supportEmail}">{$supportEmail}</a>.</p>
  <p>Thanks,<br>{$fromName} Team</p>
</body>
</html>
HTML;

        $text = <<<TEXT
{$greeting}

{$message}

If you have any questions, reach out to us at {$supportEmail}.

Thanks,
{$fromName} Team
TEXT;
    } else {
        return ['success' => false, 'message' => 'Invalid email type.', 'provider' => null, 'error' => null];
    }

    $result = sendAppEmail($toEmail, $subject, $html, $text, $supportEmail);

    $success = !empty($result['success']);
    $provider = $result['provider'] ?? null;
    $error = $result['error'] ?? null;

    logAdminEmail(
        $adminUserId,
        $adminEmail,
        $recipientUserId,
        $toEmail,
        $practiceId,
        $emailType,
        $subject,
        $success,
        $provider,
        $error
    );

    if ($success) {
        return ['success' => true, 'message' => 'Email sent to ' . $toEmail, 'provider' => $provider, 'error' => null];
    }

    return ['success' => false, 'message' => 'Failed to send email. ' . ($error ?: 'Unknown error'), 'provider' => $provider, 'error' => $error];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action);
            break;
        case 'POST':
            handlePostRequest($action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    // Unexpected server error: log full context, then return a safe JSON 500
    // without exposing SQL, paths, or stack traces to the client.
    error_log('[admin-practices] Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    // Discard any partial output (warnings, stack traces) that may have leaked.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode(['success' => false, 'error' => 'Unable to load practice information.']);
    exit;
}

// Normal flow: flush the buffered JSON output.
while (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * Build adoption/usage metrics for a single practice.
 */
function getAdoptionForPractice(PDO $pdo, int $practiceId, ?array $users = null): array {
    if ($users === null) {
        $users = getPracticeUsers($practiceId);
    }

    $totalUsers = count($users);
    $withLogin = 0;
    $withoutLogin = 0;
    $unknownLegacy = 0;
    $mostRecentLogin = null;

    foreach ($users as $user) {
        if (!empty($user['last_login'])) {
            $withLogin++;
            if ($mostRecentLogin === null || $user['last_login'] > $mostRecentLogin) {
                $mostRecentLogin = $user['last_login'];
            }
        } else {
            $withoutLogin++;
        }
    }

    // Active cases: archived=0 and not the practice's terminal (last) column
    $terminalStatus = getLastActiveWorkflowColumnId($practiceId);
    $activeStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache
        WHERE practice_id = :practice_id AND archived = 0 AND status != :terminal
    ");
    $activeStmt->execute(['practice_id' => $practiceId, 'terminal' => $terminalStatus]);
    $activeCases = (int)$activeStmt->fetchColumn();

    // Cases created in last 30 rolling days
    $createdStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache
        WHERE practice_id = :practice_id
          AND DATE(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $createdStmt->execute(['practice_id' => $practiceId]);
    $createdLast30 = (int)$createdStmt->fetchColumn();

    // Terminal-column cases in last 30 rolling days
    $terminalStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.case_id)
        FROM cases_cache c
        LEFT JOIN (
            SELECT case_id, MAX(created_at) as delivered_at
            FROM case_activity_log
            WHERE event_type = 'status_changed' AND new_status = :terminal_inner
            GROUP BY case_id
        ) l ON l.case_id = c.case_id
        WHERE c.practice_id = :practice_id
          AND c.status = :terminal_outer
          AND COALESCE(c.status_changed_at, l.delivered_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $terminalStmt->execute(['practice_id' => $practiceId, 'terminal_inner' => $terminalStatus, 'terminal_outer' => $terminalStatus]);
    $deliveredLast30 = (int)$terminalStmt->fetchColumn();

    // Demo data count
    $demoStmt = $pdo->prepare("
        SELECT COUNT(*) FROM cases_cache
        WHERE practice_id = :practice_id
          AND (demo_generation_run_id IS NOT NULL OR LEFT(case_id, 5) = 'demo_')
    ");
    $demoStmt->execute(['practice_id' => $practiceId]);
    $demoCaseCount = (int)$demoStmt->fetchColumn();

    // Last case activity: authoritative case-use signals only (case creation + case_activity_log)
    $lastCaseActivity = null;

    $caseActivityStmt = $pdo->prepare("
        SELECT MAX(created_at) as last_activity
        FROM case_activity_log
        WHERE case_id IN (SELECT case_id FROM cases_cache WHERE practice_id = :practice_id)
    ");
    $caseActivityStmt->execute(['practice_id' => $practiceId]);
    $caseActivityRow = $caseActivityStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($caseActivityRow['last_activity'])) {
        $lastCaseActivity = $caseActivityRow['last_activity'];
    }

    $caseCreateStmt = $pdo->prepare("
        SELECT MAX(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) as last_created
        FROM cases_cache
        WHERE practice_id = :practice_id
    ");
    $caseCreateStmt->execute(['practice_id' => $practiceId]);
    $createRow = $caseCreateStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($createRow['last_created'])) {
        $createDate = $createRow['last_created'];
        if ($lastCaseActivity === null || $createDate > $lastCaseActivity) {
            $lastCaseActivity = $createDate;
        }
    }

    // Descriptive case-activity summary based only on actual case work
    $summary = 'No recorded case activity';
    if ($lastCaseActivity !== null) {
        try {
            $caseActivityDate = new DateTimeImmutable($lastCaseActivity, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $days = (int)$now->diff($caseActivityDate)->format('%r%a');
            if ($days <= 30) {
                $summary = 'Recent case activity';
            } else {
                $summary = 'Historical case activity';
            }
        } catch (Throwable $e) {
            $summary = 'Historical case activity';
        }
    }

    // Last activity: most recent customer activity only
    // Sources: user login, case creation, case activity log (unchanged)
    $lastActivity = null;

    $loginStmt = $pdo->prepare("
        SELECT MAX(u.last_login_at) as last_login_at
        FROM users u
        JOIN practice_users pu ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id
    ");
    $loginStmt->execute(['practice_id' => $practiceId]);
    $loginRow = $loginStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($loginRow['last_login_at'])) {
        $lastActivity = $loginRow['last_login_at'];
    }

    if (!empty($caseActivityRow['last_activity'])) {
        if ($lastActivity === null || $caseActivityRow['last_activity'] > $lastActivity) {
            $lastActivity = $caseActivityRow['last_activity'];
        }
    }

    if (!empty($createRow['last_created'])) {
        $createDate = $createRow['last_created'];
        if ($lastActivity === null || $createDate > $lastActivity) {
            $lastActivity = $createDate;
        }
    }

    return [
        'total_users' => $totalUsers,
        'users_with_login' => $withLogin,
        'users_without_login' => $withoutLogin,
        'most_recent_login' => $mostRecentLogin,
        'active_cases' => $activeCases,
        'created_last_30_days' => $createdLast30,
        'delivered_last_30_days' => $deliveredLast30,
        'terminal_status' => $terminalStatus,
        'terminal_label' => resolveWorkflowStageLabelForPractice($terminalStatus, $practiceId),
        'demo_case_count' => $demoCaseCount,
        'last_case_activity' => $lastCaseActivity,
        'last_activity' => $lastActivity,
        'summary' => $summary,
    ];
}

/**
 * Build lightweight list-level adoption metrics for multiple practices.
 */
function getListAdoptionMetrics(PDO $pdo, array $practiceIds): array {
    if (empty($practiceIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($practiceIds), '?'));

    $metrics = [];
    foreach ($practiceIds as $id) {
        $metrics[(int)$id] = [
            'total_cases' => 0,
            'last_activity' => null,
        ];
    }

    // Total cases per practice (same definition as the right-detail compliance panel:
    // active, completed, delivered, and archived — all rows in cases_cache).
    $totalStmt = $pdo->prepare("
        SELECT practice_id, COUNT(*) as cnt
        FROM cases_cache
        WHERE practice_id IN ($placeholders)
        GROUP BY practice_id
    ");
    $totalStmt->execute($practiceIds);
    foreach ($totalStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metrics[(int)$row['practice_id']]['total_cases'] = (int)$row['cnt'];
    }

    // Last activity per practice
    $lastLoginStmt = $pdo->prepare("
        SELECT pu.practice_id, MAX(u.last_login_at) as last_login
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id IN ($placeholders)
        GROUP BY pu.practice_id
    ");
    $lastLoginStmt->execute($practiceIds);
    $loginMap = [];
    foreach ($lastLoginStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $loginMap[(int)$row['practice_id']] = $row['last_login'];
    }

    $lastActivityStmt = $pdo->prepare("
        SELECT c.practice_id, MAX(l.created_at) as last_activity
        FROM cases_cache c
        LEFT JOIN case_activity_log l ON l.case_id = c.case_id
        WHERE c.practice_id IN ($placeholders)
        GROUP BY c.practice_id
    ");
    $lastActivityStmt->execute($practiceIds);
    $activityMap = [];
    foreach ($lastActivityStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['last_activity'])) {
            $activityMap[(int)$row['practice_id']] = $row['last_activity'];
        }
    }

    foreach ($practiceIds as $id) {
        $lastLogin = $loginMap[$id] ?? null;
        $lastCaseActivity = $activityMap[$id] ?? null;
        $last = null;
        if (!empty($lastLogin)) {
            $last = $lastLogin;
        }
        if (!empty($lastCaseActivity) && ($last === null || $lastCaseActivity > $last)) {
            $last = $lastCaseActivity;
        }
        $metrics[$id]['last_activity'] = $last;
    }

    return $metrics;
}

function handleGetRequest($action) {
    global $pdo;
    switch ($action) {
        case 'list':
            // List all practices with compliance status and subscription context
            ensureAdminHiddenPracticesSchema();
            $practices = getAllPracticesWithComplianceStatus();
            $hiddenIds = getAdminHiddenPracticeIds($_SESSION['db_user_id'] ?? 0);

            // Batch-load owner + subscription for all practices in one query
            $practiceIds = array_filter(array_column($practices, 'id'), 'is_numeric');
            $ownerMap = [];
            $ownerUserIds = [];
            if (!empty($practiceIds) && $pdo) {
                $placeholders = implode(',', array_fill(0, count($practiceIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT
                        pu.practice_id,
                        u.id AS owner_user_id,
                        u.email AS owner_email,
                        u.first_name AS owner_first_name,
                        u.last_name AS owner_last_name,
                        s.id,
                        s.stripe_customer_id,
                        s.stripe_subscription_id,
                        s.stripe_price_id,
                        s.plan,
                        s.billing_interval,
                        s.status,
                        s.trial_ends_at,
                        s.current_period_ends_at,
                        s.cancel_at_period_end,
                        s.subscription_updated_at,
                        s.stripe_event_created,
                        s.created_at,
                        p.trial_ends_at AS practice_trial_ends_at,
                        p.subscription_status AS practice_subscription_status,
                        p.subscription_plan AS practice_plan,
                        p.billing_interval AS practice_billing_interval,
                        p.current_period_ends_at AS practice_current_period_ends_at,
                        p.stripe_customer_id AS practice_stripe_customer_id,
                        p.stripe_subscription_id AS practice_stripe_subscription_id,
                        p.cancel_at_period_end AS practice_cancel_at_period_end,
                        p.subscription_updated_at AS practice_subscription_updated_at
                    FROM practice_users pu
                    JOIN users u ON u.id = pu.user_id
                    JOIN practices p ON p.id = pu.practice_id
                    LEFT JOIN subscriptions s ON s.owner_user_id = u.id
                    WHERE pu.practice_id IN ($placeholders)
                      AND pu.is_owner = 1
                ");
                $stmt->execute(array_values($practiceIds));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $ownerMap[(int)$row['practice_id']] = $row;
                    $ownerUserIds[] = (int)$row['owner_user_id'];
                }
                $ownerUserIds = array_unique($ownerUserIds);

                // Batch-load owned-practice counts for all owners in one query
                $ownedCounts = [];
                if (!empty($ownerUserIds)) {
                    $opPlaceholders = implode(',', array_fill(0, count($ownerUserIds), '?'));
                    $countStmt = $pdo->prepare("
                        SELECT user_id, COUNT(*) as cnt
                        FROM practice_users
                        WHERE user_id IN ($opPlaceholders) AND is_owner = 1
                        GROUP BY user_id
                    ");
                    $countStmt->execute(array_values($ownerUserIds));
                    $ownedCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                }
            }

            // Batch-load lightweight adoption metrics for all practices
            $adoptionMetrics = getListAdoptionMetrics($pdo, array_map('intval', $practiceIds));

            foreach ($practices as &$practice) {
                $practice['is_hidden'] = in_array((int)$practice['id'], $hiddenIds, true);
                $owner = $ownerMap[(int)$practice['id']] ?? null;
                $ownerId = $owner ? (int)$owner['owner_user_id'] : null;
                $ownedCount = $ownerId ? (int)($ownedCounts[$ownerId] ?? 1) : 0;
                $practice['subscription'] = buildSubscriptionInfo($owner, $owner, $ownedCount);
                $practice['adoption'] = $adoptionMetrics[(int)$practice['id']] ?? [
                    'total_cases' => 0,
                    'last_activity' => null,
                ];
            }
            unset($practice);
            echo json_encode([
                'success' => true,
                'practices' => $practices,
                'data_retention_years' => DATA_RETENTION_YEARS
            ]);
            break;
            
        case 'compliance':
            // Get compliance summary for a specific practice
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $summary = getComplianceSummary($practiceId);
            echo json_encode([
                'success' => true,
                'compliance' => $summary
            ]);
            break;
            
        case 'phi_log':
            // Get PHI access log for a practice
            $practiceId = $_GET['practice_id'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 100), 500);
            $offset = (int)($_GET['offset'] ?? 0);
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $log = getPHIAccessLog($practiceId, $limit, $offset);
            echo json_encode([
                'success' => true,
                'log' => $log,
                'limit' => $limit,
                'offset' => $offset
            ]);
            break;
            
        case 'users':
            // Get users for a practice
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $users = getPracticeUsers($practiceId);
            echo json_encode([
                'success' => true,
                'users' => $users
            ]);
            break;
            
        case 'settings':
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            $settings = getPracticeSettings($practiceId);
            echo json_encode([
                'success' => true,
                'settings' => $settings
            ]);
            break;

        case 'adoption':
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }

            $adoptionUsers = getPracticeUsers((int)$practiceId);
            $adoption = getAdoptionForPractice($pdo, (int)$practiceId, $adoptionUsers);
            echo json_encode([
                'success' => true,
                'adoption' => $adoption
            ]);
            break;

        case 'affected_practices':
            $practiceId = $_GET['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }

            $ownerUserId = getSubscriptionOwnerUserId($pdo, (int)$practiceId);
            if (!$ownerUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Could not resolve subscription owner for this practice']);
                return;
            }

            $affectedStmt = $pdo->prepare("
                SELECT p.id, p.practice_name, p.legal_name, p.display_name
                FROM practice_users pu
                JOIN practices p ON p.id = pu.practice_id
                WHERE pu.user_id = ? AND pu.is_owner = 1
                ORDER BY p.practice_name, p.id
            ");
            $affectedStmt->execute([$ownerUserId]);
            $affected = $affectedStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($affected as &$ap) {
                $ap['name'] = $ap['practice_name'] ?: ($ap['legal_name'] ?: ($ap['display_name'] ?: 'Unnamed'));
                unset($ap['practice_name'], $ap['legal_name'], $ap['display_name']);
            }
            unset($ap);

            echo json_encode(['success' => true, 'affected_practices' => $affected]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePostRequest($action) {
    global $pdo;
    $input = json_decode(file_get_contents('php://input'), true);

    // Enforce CSRF token for all state-changing POST actions. This is checked
    // before any case handler so no DB or email side effects occur without it.
    if (!validateCsrfToken($input['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token']);
        return;
    }

    // Owners and administrators must accept the current Terms before performing
    // DentaTrak administrative mutations.
    if (isset($_SESSION['db_user_id'])) {
        requireCurrentTermsAcceptedForApi((int)$_SESSION['db_user_id']);
    }

    switch ($action) {
        case 'deactivate':
            // Deactivate a practice
            $practiceId = $input['practice_id'] ?? null;
            $reason = $input['reason'] ?? 'Deactivated by administrator';
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $result = deactivatePractice($practiceId, $reason, $_SESSION['db_user_id']);
            
            if ($result) {
                // Log this admin action
                logAdminAction('practice_deactivated', [
                    'practice_id' => $practiceId,
                    'reason' => $reason
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Practice deactivated successfully',
                    'deletion_eligible_at' => (new DateTime())->modify('+' . DATA_RETENTION_YEARS . ' years')->format('Y-m-d')
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to deactivate practice']);
            }
            break;
            
        case 'reactivate':
            // Reactivate a practice
            $practiceId = $input['practice_id'] ?? null;
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            $result = reactivatePractice($practiceId);
            
            if ($result) {
                // Log this admin action
                logAdminAction('practice_reactivated', ['practice_id' => $practiceId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Practice reactivated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to reactivate practice']);
            }
            break;
            
        case 'delete':
            // Permanently delete a practice (only if retention period has passed)
            $practiceId = $input['practice_id'] ?? null;
            $confirmDelete = $input['confirm'] ?? false;
            
            if (!$practiceId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID required']);
                return;
            }
            
            // Check if practice is eligible for deletion
            $status = checkPracticeStatus($practiceId);
            
            if (!$status['can_delete']) {
                $yearsRemaining = DATA_RETENTION_YEARS - ($status['years_inactive'] ?? 0);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Cannot delete practice. Data must be retained for " . DATA_RETENTION_YEARS . " years. " .
                                 "This practice has been inactive for " . ($status['years_inactive'] ?? 0) . " years. " .
                                 "Deletion will be available in approximately " . $yearsRemaining . " more years.",
                    'years_remaining' => $yearsRemaining
                ]);
                return;
            }
            
            if (!$confirmDelete) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Deletion requires confirmation. Set confirm=true to proceed.',
                    'warning' => 'This action is PERMANENT and cannot be undone. All practice data will be deleted.'
                ]);
                return;
            }
            
            // Perform deletion (implement this carefully)
            $result = permanentlyDeletePractice($practiceId);
            
            if ($result) {
                logAdminAction('practice_deleted', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice permanently deleted']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete practice']);
            }
            break;
            
        case 'hide':
            $practiceId = $input['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            ensureAdminHiddenPracticesSchema();
            $result = hidePracticeForAdmin($practiceId, $_SESSION['db_user_id'] ?? 0);
            
            if ($result) {
                logAdminAction('practice_hidden', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice hidden from admin view']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to hide practice']);
            }
            break;
            
        case 'unhide':
            $practiceId = $input['practice_id'] ?? null;
            if (!$practiceId || !is_numeric($practiceId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid practice ID required']);
                return;
            }
            
            ensureAdminHiddenPracticesSchema();
            $result = unhidePracticeForAdmin($practiceId, $_SESSION['db_user_id'] ?? 0);
            
            if ($result) {
                logAdminAction('practice_unhidden', ['practice_id' => $practiceId]);
                echo json_encode(['success' => true, 'message' => 'Practice unhidden']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to unhide practice']);
            }
            break;

        case 'send_email':
            $practiceId = $input['practice_id'] ?? null;
            $recipientUserId = $input['user_id'] ?? null;
            $emailType = $input['email_type'] ?? '';

            if (!$practiceId || !is_numeric($practiceId) || !$recipientUserId || !is_numeric($recipientUserId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Practice ID and user ID are required']);
                return;
            }

            if (!in_array($emailType, ['getting_started', 'user_guide', 'trial_reminder', 'custom'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid email type']);
                return;
            }

            $customSubject = $input['custom_subject'] ?? '';
            $customMessage = $input['custom_message'] ?? '';

            $result = sendAdminPracticeEmail(
                $pdo,
                (int)($_SESSION['db_user_id'] ?? 0),
                $_SESSION['user_email'] ?? '',
                (int)$practiceId,
                (int)$recipientUserId,
                $emailType,
                $customSubject,
                $customMessage
            );

            if ($result['success']) {
                http_response_code(200);
                echo json_encode(['success' => true, 'message' => $result['message']]);
            } else {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $result['message']]);
            }
            break;

        case 'extend_trial':
            handleExtendTrial($input);
            break;

        case 'set_user_classification':
            handleSetUserClassification($input);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

/**
 * Set a user's authoritative account classification and lab-creation approval.
 * Super-user only; any change is written to users.organization_type,
 * users.organization_type_other, and users.lab_practice_creation_approved.
 */
function handleSetUserClassification(array $input): void {
    global $pdo;

    $userId = (int)($input['user_id'] ?? 0);
    $organizationType = $input['organization_type'] ?? null;
    $organizationTypeOther = $input['organization_type_other'] ?? null;
    $approved = $input['lab_practice_creation_approved'] ?? null;

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id is required']);
        return;
    }

    $validTypes = ['dental_practice', 'dso', 'lab', 'other', null];
    if ($organizationType !== null && !in_array($organizationType, $validTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid organization_type']);
        return;
    }

    requireAccountClassificationSchema($pdo);

    try {
        $stmt = $pdo->prepare("
            UPDATE users
            SET organization_type = :organization_type,
                organization_type_other = :organization_type_other,
                lab_practice_creation_approved = :lab_practice_creation_approved
            WHERE id = :user_id
        ");
        $stmt->execute([
            'organization_type' => $organizationType,
            'organization_type_other' => $organizationTypeOther,
            'lab_practice_creation_approved' => ($approved === true || $approved === 1 || $approved === '1') ? 1 : 0,
            'user_id' => $userId,
        ]);

        logAdminAction('set_user_classification', [
            'user_id' => $userId,
            'organization_type' => $organizationType,
            'lab_practice_creation_approved' => $approved,
        ]);

        echo json_encode(['success' => true, 'message' => 'User classification updated']);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error setting user classification: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update user classification']);
    }
}

/**
 * Load all practices owned by a subscription owner.
 *
 * @return array List of [id, name] practice records.
 */
function loadAffectedPracticesForOwner(PDO $pdo, int $ownerUserId): array {
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_name, p.legal_name, p.display_name
        FROM practice_users pu
        JOIN practices p ON p.id = pu.practice_id
        WHERE pu.user_id = ? AND pu.is_owner = 1
        ORDER BY p.practice_name, p.id
    ");
    $stmt->execute([$ownerUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['name'] = $row['practice_name'] ?: ($row['legal_name'] ?: ($row['display_name'] ?: 'Unnamed'));
        unset($row['practice_name'], $row['legal_name'], $row['display_name']);
    }
    unset($row);
    return $rows;
}

/**
 * Resolve or create the authoritative owner-level subscriptions row.
 *
 * If a row already exists, it is returned unchanged unless it lacks a trial end
 * date and a legacy practice row contains a later one (backfill without
 * replacing later authoritative data). If no row exists, a new one is created
 * from the latest legacy trial data for this owner.
 *
 * This must be called inside a transaction so the SELECT ... FOR UPDATE lock
 * protects against duplicate inserts.
 */
function resolveOrBackfillSubscriptionForOwner(PDO $pdo, int $ownerUserId): ?array {
    // Latest legacy trial data among all practices owned by this owner.
    $legacyStmt = $pdo->prepare("
        SELECT p.trial_ends_at,
               p.subscription_status,
               p.billing_interval,
               p.current_period_ends_at,
               p.stripe_customer_id,
               p.stripe_subscription_id,
               p.cancel_at_period_end
        FROM practice_users pu
        JOIN practices p ON p.id = pu.practice_id
        WHERE pu.user_id = :owner_user_id
          AND pu.is_owner = 1
          AND p.trial_ends_at IS NOT NULL
        ORDER BY p.trial_ends_at DESC
        LIMIT 1
    ");
    $legacyStmt->execute(['owner_user_id' => $ownerUserId]);
    $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);

    // Lock the owner row (or confirm it does not exist) for the transaction.
    $subStmt = $pdo->prepare("
        SELECT id, owner_user_id, plan, billing_interval, status, trial_ends_at,
               current_period_ends_at, cancel_at_period_end, stripe_customer_id,
               stripe_subscription_id, subscription_updated_at
        FROM subscriptions
        WHERE owner_user_id = :owner_user_id
        FOR UPDATE
    ");
    $subStmt->execute(['owner_user_id' => $ownerUserId]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($sub) && !empty($sub['trial_ends_at'])) {
        // Authoritative row with a trial end date already exists; never replace.
        return $sub;
    }

    if (empty($legacy) || empty($legacy['trial_ends_at'])) {
        return null;
    }

    $backfillEnd = $legacy['trial_ends_at'];
    $backfillStatus = $legacy['subscription_status'] ?: 'trialing';

    if (!empty($sub)) {
        // Row exists but has no usable trial end; backfill if the legacy end is
        // later than the existing one (or the existing one is null).
        $existingEnd = null;
        if (!empty($sub['trial_ends_at'])) {
            try {
                $existingEnd = new DateTimeImmutable($sub['trial_ends_at'], new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                $existingEnd = null;
            }
        }
        try {
            $legacyEnd = new DateTimeImmutable($backfillEnd, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
        $shouldUpdate = ($existingEnd === null) || ($existingEnd < $legacyEnd);

        if ($shouldUpdate) {
            $updateStmt = $pdo->prepare("
                UPDATE subscriptions
                SET trial_ends_at = :trial_ends_at,
                    status = COALESCE(status, :status),
                    billing_interval = COALESCE(billing_interval, :billing_interval),
                    current_period_ends_at = COALESCE(current_period_ends_at, :current_period_ends_at),
                    stripe_customer_id = COALESCE(stripe_customer_id, :stripe_customer_id),
                    stripe_subscription_id = COALESCE(stripe_subscription_id, :stripe_subscription_id),
                    cancel_at_period_end = COALESCE(cancel_at_period_end, :cancel_at_period_end),
                    subscription_updated_at = UTC_TIMESTAMP()
                WHERE owner_user_id = :owner_user_id
            ");
            $updateStmt->execute([
                'trial_ends_at' => $backfillEnd,
                'status' => $backfillStatus,
                'billing_interval' => $legacy['billing_interval'] ?? null,
                'current_period_ends_at' => $legacy['current_period_ends_at'] ?? null,
                'stripe_customer_id' => $legacy['stripe_customer_id'] ?? null,
                'stripe_subscription_id' => $legacy['stripe_subscription_id'] ?? null,
                'cancel_at_period_end' => $legacy['cancel_at_period_end'] ?? 0,
                'owner_user_id' => $ownerUserId,
            ]);
        }

        $subStmt->execute(['owner_user_id' => $ownerUserId]);
        return $subStmt->fetch(PDO::FETCH_ASSOC);
    }

    // No row and no duplicate is possible because of the FOR UPDATE read above.
    $insertStmt = $pdo->prepare("
        INSERT INTO subscriptions (
            owner_user_id, status, trial_ends_at, billing_interval, current_period_ends_at,
            stripe_customer_id, stripe_subscription_id, cancel_at_period_end, subscription_updated_at
        ) VALUES (
            :owner_user_id, :status, :trial_ends_at, :billing_interval, :current_period_ends_at,
            :stripe_customer_id, :stripe_subscription_id, :cancel_at_period_end, UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE id = id
    ");
    $insertStmt->execute([
        'owner_user_id' => $ownerUserId,
        'status' => $backfillStatus,
        'trial_ends_at' => $backfillEnd,
        'billing_interval' => $legacy['billing_interval'] ?? null,
        'current_period_ends_at' => $legacy['current_period_ends_at'] ?? null,
        'stripe_customer_id' => $legacy['stripe_customer_id'] ?? null,
        'stripe_subscription_id' => $legacy['stripe_subscription_id'] ?? null,
        'cancel_at_period_end' => $legacy['cancel_at_period_end'] ?? 0,
    ]);

    $subStmt->execute(['owner_user_id' => $ownerUserId]);
    return $subStmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Validate that the resolved subscription is in an active trial state.
 *
 * @return string|null Error message, or null if the trial can be extended.
 */
function getTrialExtensionError(array $sub): ?string {
    $trialEndsAt = $sub['trial_ends_at'] ?? null;
    if (empty($trialEndsAt)) {
        return 'This practice is not currently on a trial';
    }

    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0, 0, 0);
        $end = (new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC')))->setTime(0, 0, 0);
        if ($end < $today) {
            return 'This trial has already expired and cannot be extended';
        }
    } catch (Throwable $e) {
        return 'Could not parse existing trial end date';
    }

    $status = $sub['status'] ?? null;
    $stripeSubscriptionId = $sub['stripe_subscription_id'] ?? null;

    // A Stripe subscription that is not explicitly trialing means the account
    // has converted to a paid subscription or is in a terminal state.
    if (!empty($stripeSubscriptionId) && $status !== 'trialing') {
        return 'This practice is already on a paid subscription and cannot have its trial extended';
    }

    // Only 'trialing' or no status (legacy backfill) can represent an active
    // trial. Any other explicit status is not a trial.
    if (!empty($status) && $status !== 'trialing') {
        return 'This practice is not currently on an active trial';
    }

    return null;
}

/**
 * Extend the DentaTrak trial for a practice's subscription owner.
 *
 * Authorization is already enforced by the caller. This action requires a valid
 * CSRF token, validates the extension length server-side (1-24 calendar months),
 * resolves all practices affected by the owner's subscription, backfills an
 * authoritative subscriptions row from legacy practice data when needed, updates
 * the trial end in a transaction, optionally emails the owner, and writes an
 * admin audit log entry.
 */
function handleExtendTrial(array $input): void {
    global $pdo;

    // CSRF is validated by handlePostRequest before this function is called.

    $practiceId = $input['practice_id'] ?? null;
    $extensionMonths = $input['extension_months'] ?? null;
    $sendEmail = !empty($input['send_email']);

    if (!$practiceId || !is_numeric($practiceId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Practice ID is required']);
        return;
    }

    $practiceId = (int)$practiceId;

    if (!is_int($extensionMonths) && !is_numeric($extensionMonths)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Extension length is required']);
        return;
    }

    $extensionMonths = (int)$extensionMonths;
    if ($extensionMonths < 1 || $extensionMonths > 24) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Extension length must be between 1 and 24 months']);
        return;
    }

    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection unavailable']);
        return;
    }

    $ownerUserId = getSubscriptionOwnerUserId($pdo, $practiceId);
    if (!$ownerUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not resolve a valid subscription owner for this practice']);
        return;
    }

    $ownerStmt = $pdo->prepare("SELECT id, email, first_name, last_name, locale FROM users WHERE id = ? LIMIT 1");
    $ownerStmt->execute([$ownerUserId]);
    $ownerUser = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ownerUser) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not resolve a valid subscription owner for this practice']);
        return;
    }

    // Resolve all practices affected by this owner's subscription. This is used
    // in the confirmation, the audit log, and the client-side refresh.
    $affectedPractices = loadAffectedPracticesForOwner($pdo, $ownerUserId);

    try {
        $pdo->beginTransaction();

        $sub = resolveOrBackfillSubscriptionForOwner($pdo, $ownerUserId);

        if (empty($sub)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This practice has no active trial or legacy trial data to extend']);
            return;
        }

        $extensionError = getTrialExtensionError($sub);
        if ($extensionError) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $extensionError]);
            return;
        }

        $oldEnd = new DateTimeImmutable($sub['trial_ends_at'], new DateTimeZone('UTC'));
        $newEnd = addCalendarMonths($oldEnd, $extensionMonths);
        $newEndFormatted = $newEnd->format('Y-m-d H:i:s');
        $previousEndFormatted = $sub['trial_ends_at'];

        $updateStmt = $pdo->prepare("
            UPDATE subscriptions
            SET trial_ends_at = :trial_ends_at,
                status = COALESCE(status, 'trialing'),
                subscription_updated_at = UTC_TIMESTAMP()
            WHERE owner_user_id = :owner_user_id
              AND (status IS NULL OR status = 'trialing')
        ");
        $updateStmt->execute([
            'trial_ends_at' => $newEndFormatted,
            'owner_user_id' => $ownerUserId,
        ]);

        if ($updateStmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This practice is not currently on an active trial']);
            return;
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[admin-practices] Error extending trial: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to extend trial due to a database error']);
        return;
    }

    // Re-fetch the updated subscription row and all affected practice counts.
    $updatedSub = getSubscriptionForOwner($pdo, $ownerUserId);
    $displaySub = buildSubscriptionInfo($updatedSub ?: [], $ownerUser, count($affectedPractices));

    $emailResultState = 'not_requested';
    $emailResult = ['success' => false, 'provider' => null, 'error' => null];
    $emailMessage = null;

    if ($sendEmail) {
        $emailResult = sendTrialExtensionEmail(
            $pdo,
            (int)($_SESSION['db_user_id'] ?? 0),
            $_SESSION['user_email'] ?? '',
            $practiceId,
            (int)$ownerUser['id'],
            $ownerUser,
            $extensionMonths,
            $newEndFormatted
        );

        if ($emailResult['success']) {
            $emailResultState = 'sent';
        } else {
            $emailResultState = 'failed';
            $emailMessage = $emailResult['error'] ?? 'The notification email could not be sent.';
        }
    }

    $affectedPracticeNames = array_column($affectedPractices, 'name');

    $successMessage = buildTrialExtensionMessage(
        $extensionMonths,
        $affectedPracticeNames,
        $emailResultState,
        $emailMessage
    );

    logAdminAction('trial_extended', [
        'practice_id' => $practiceId,
        'affected_practices' => $affectedPractices,
        'affected_practice_names' => $affectedPracticeNames,
        'owner_user_id' => $ownerUserId,
        'previous_trial_ends_at' => $previousEndFormatted,
        'new_trial_ends_at' => $newEndFormatted,
        'extension_months' => $extensionMonths,
        'email_result' => $emailResultState,
        'email_provider' => $emailResult['provider'] ?? null,
        'email_error' => $emailMessage,
    ]);

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'subscription' => $displaySub,
        'affected_practices' => $affectedPractices,
        'affected_practice_ids' => array_column($affectedPractices, 'id'),
        'email_result' => $emailResultState,
        'email_message' => $emailMessage,
    ]);
}

/**
 * Build a user-visible success message that makes clear whether one or many
 * practices were affected and whether the email succeeded.
 */
function buildTrialExtensionMessage(int $extensionMonths, array $names, string $emailResult, ?string $emailError): string {
    $monthLabel = $extensionMonths === 1 ? 'month' : 'months';
    $base = 'Trial extended by ' . $extensionMonths . ' ' . $monthLabel;

    if (count($names) === 1) {
        $base .= ' for ' . $names[0];
    } else {
        $base .= ' for ' . count($names) . ' practices using this subscription: ' . implode(', ', $names);
    }

    if ($emailResult === 'sent') {
        $base .= '. Notification email sent.';
    } elseif ($emailResult === 'failed') {
        $base .= '. The trial was extended, but the notification email could not be sent. The trial extension has still been applied.';
    }

    return $base;
}

/**
 * Send a trial extension notification to the subscription owner.
 *
 * This uses the existing sendAppEmail infrastructure. It does not roll back the
 * trial extension on email failure, but returns a safe failure message so the
 * caller can report it without exposing mail-system details to the browser.
 * Technical failures are written to the server error log.
 *
 * @return array { success: bool, provider: ?string, error: ?string }
 */
function sendTrialExtensionEmail(
    PDO $pdo,
    int $adminUserId,
    string $adminEmail,
    int $practiceId,
    int $recipientUserId,
    array $owner,
    int $extensionMonths,
    string $newTrialEndsAt
): array {
    global $appConfig;

    $toEmail = $owner['email'] ?? '';
    if (!$toEmail) {
        return ['success' => false, 'provider' => null, 'error' => 'No recipient email address for subscription owner'];
    }

    $firstName = trim($owner['first_name'] ?? '');
    $supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';

    try {
        $newEnd = new DateTimeImmutable($newTrialEndsAt, new DateTimeZone('UTC'));
        $newEndDate = $newEnd->format('M j, Y');
    } catch (Throwable $e) {
        $newEndDate = $newTrialEndsAt;
    }

    $monthLabel = $extensionMonths === 1
        ? t('admin_practices.month_singular')
        : t('admin_practices.month_plural');

    $greeting = t('admin_practices.email_trial_extended_greeting', ['name' => $firstName ?: 'there']);

    $html = '<!DOCTYPE html><html><body>';
    $html .= '<p>' . escapeHtmlEmail($greeting) . '</p>';
    $html .= '<p>' . escapeHtmlEmail(t('admin_practices.email_trial_extended_body_1', ['months' => $extensionMonths . ' ' . $monthLabel])) . '</p>';
    $html .= '<p>' . escapeHtmlEmail(t('admin_practices.email_trial_extended_body_2', ['date' => $newEndDate])) . '</p>';
    $html .= '<p>' . escapeHtmlEmail(t('admin_practices.email_trial_extended_body_3')) . '</p>';
    $html .= '<p>' . escapeHtmlEmail(t('admin_practices.email_trial_extended_closing')) . '<br>' . escapeHtmlEmail(t('admin_practices.email_trial_extended_team')) . '</p>';
    $html .= '</body></html>';

    $text = $greeting . "\n\n" .
        t('admin_practices.email_trial_extended_body_1', ['months' => $extensionMonths . ' ' . $monthLabel]) . "\n\n" .
        t('admin_practices.email_trial_extended_body_2', ['date' => $newEndDate]) . "\n\n" .
        t('admin_practices.email_trial_extended_body_3') . "\n\n" .
        t('admin_practices.email_trial_extended_closing') . "\n" .
        t('admin_practices.email_trial_extended_team');

    $subject = t('admin_practices.email_trial_extended_subject');

    $result = sendAppEmail($toEmail, $subject, $html, $text, $supportEmail);

    $success = !empty($result['success']);
    $provider = $result['provider'] ?? null;
    $technicalError = $result['error'] ?? null;

    if (!$success && $technicalError) {
        error_log('[admin-practices] Trial extension email failed for ' . $toEmail . ': ' . $technicalError);
    }

    logAdminEmail(
        $adminUserId,
        $adminEmail,
        $recipientUserId,
        $toEmail,
        $practiceId,
        'trial_extended',
        $subject,
        $success,
        $provider,
        $technicalError
    );

    return [
        'success' => $success,
        'provider' => $provider,
        'error' => $success ? null : 'The notification email could not be sent. The trial extension has still been applied.',
    ];
}

function escapeHtmlEmail(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function getPracticeUsers($practiceId) {
    global $pdo;
    
    try {
        // Check which columns exist to build a compatible query
        $userColumns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $puColumns = $pdo->query("SHOW COLUMNS FROM practice_users")->fetchAll(PDO::FETCH_COLUMN);
        
        $hasLastLoginAt = in_array('last_login_at', $userColumns);
        $hasIsOwner = in_array('is_owner', $puColumns);
        $hasIsActive = in_array('is_active', $userColumns);
        $hasEmailVerified = in_array('email_verified', $userColumns);
        $hasLimitedVisibility = in_array('limited_visibility', $puColumns);
        $hasCanViewAnalytics = in_array('can_view_analytics', $puColumns);
        $hasCanEditCases = in_array('can_edit_cases', $puColumns);
        $hasIsLab = in_array('is_lab', $puColumns);

        $lastLoginSelect = $hasLastLoginAt ? 'u.last_login_at as last_login' : 'NULL as last_login';
        $isOwnerSelect = $hasIsOwner ? 'IFNULL(pu.is_owner, 0) as is_owner' : '0 as is_owner';
        $isActiveSelect = $hasIsActive ? 'IFNULL(u.is_active, 1) as is_active' : '1 as is_active';
        $emailVerifiedSelect = $hasEmailVerified ? 'IFNULL(u.email_verified, 0) as email_verified' : '1 as email_verified';
        $limitedVisibilitySelect = $hasLimitedVisibility ? 'IFNULL(pu.limited_visibility, 0) as limited_visibility' : '0 as limited_visibility';
        $canViewAnalyticsSelect = $hasCanViewAnalytics ? 'IFNULL(pu.can_view_analytics, 0) as can_view_analytics' : '0 as can_view_analytics';
        $canEditCasesSelect = $hasCanEditCases ? 'IFNULL(pu.can_edit_cases, 0) as can_edit_cases' : '0 as can_edit_cases';
        $isLabSelect = $hasIsLab ? 'IFNULL(pu.is_lab, 0) as is_lab' : '0 as is_lab';
        $orderBy = $hasIsOwner ? 'pu.is_owner DESC, pu.role, u.email' : 'pu.role, u.email';

        $sql = "
            SELECT
                u.id,
                u.email,
                IFNULL(u.first_name, '') as first_name,
                IFNULL(u.last_name, '') as last_name,
                u.created_at as user_created_at,
                $lastLoginSelect,
                IFNULL(pu.role, 'user') as role,
                $isOwnerSelect,
                $isActiveSelect,
                $emailVerifiedSelect,
                $limitedVisibilitySelect,
                $canViewAnalyticsSelect,
                $canEditCasesSelect,
                $isLabSelect,
                pu.created_at as joined_at
            FROM practice_users pu
            JOIN users u ON pu.user_id = u.id
            WHERE pu.practice_id = ?
            ORDER BY $orderBy
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$practiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting practice users: ' . $e->getMessage());
        return [];
    }
}

function permanentlyDeletePractice($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) return false;
    
    try {
        $pdo->beginTransaction();
        
        // Delete in order of dependencies
        $pdo->prepare("DELETE FROM phi_access_log WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM case_activity_log WHERE case_id IN (SELECT id FROM cases_cache WHERE practice_id = ?)")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM cases_cache WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM practice_users WHERE practice_id = ?")->execute([$practiceId]);
        $pdo->prepare("DELETE FROM practices WHERE id = ?")->execute([$practiceId]);
        
        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[admin-practices] Error deleting practice: ' . $e->getMessage());
        return false;
    }
}

function logAdminAction($action, $details = []) {
    global $pdo;
    
    try {
        // Ensure admin_audit_log table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            admin_email VARCHAR(255),
            action VARCHAR(100) NOT NULL,
            details_json TEXT,
            ip_address VARCHAR(45),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_log (admin_user_id, admin_email, action, details_json, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['db_user_id'],
            $_SESSION['user_email'] ?? '',
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error logging admin action: ' . $e->getMessage());
    }
}

/**
 * Ensure the admin_hidden_practices table exists.
 */
function ensureAdminHiddenPracticesSchema() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_hidden_practices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            practice_id BIGINT UNSIGNED NOT NULL,
            hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_practice_id (practice_id),
            UNIQUE KEY idx_admin_practice (admin_user_id, practice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $initialized = true;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error ensuring admin hidden practices schema: ' . $e->getMessage());
    }
}

/**
 * Get the practice IDs hidden by the given admin.
 */
function getAdminHiddenPracticeIds($adminUserId) {
    global $pdo;
    
    if (!$pdo || !$adminUserId) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT practice_id FROM admin_hidden_practices WHERE admin_user_id = ?");
        $stmt->execute([$adminUserId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting hidden practice IDs: ' . $e->getMessage());
        return [];
    }
}

/**
 * Hide a practice from the admin's view.
 */
function hidePracticeForAdmin($practiceId, $adminUserId) {
    global $pdo;
    
    if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO admin_hidden_practices (admin_user_id, practice_id, hidden_at) VALUES (?, ?, NOW())");
        return $stmt->execute([$adminUserId, $practiceId]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error hiding practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Unhide a practice from the admin's view.
 */
function unhidePracticeForAdmin($practiceId, $adminUserId) {
    global $pdo;
    
    if (!$pdo || !$practiceId || !$adminUserId || !is_numeric($practiceId)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_hidden_practices WHERE admin_user_id = ? AND practice_id = ?");
        return $stmt->execute([$adminUserId, $practiceId]);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error unhiding practice: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get a representative user ID for a practice (owner/creator preferred, then first admin).
 */
function getRepresentativeUserId($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT created_by FROM practices WHERE id = ?");
        $stmt->execute([$practiceId]);
        $createdBy = $stmt->fetchColumn();
        
        if ($createdBy) {
            $stmt = $pdo->prepare("SELECT 1 FROM user_preferences WHERE user_id = ?");
            $stmt->execute([$createdBy]);
            if ($stmt->fetchColumn()) {
                return (int)$createdBy;
            }
        }
        
        $puColumns = $pdo->query("SHOW COLUMNS FROM practice_users")->fetchAll(PDO::FETCH_COLUMN);
        $hasIsOwner = in_array('is_owner', $puColumns);
        
        $ownerClause = $hasIsOwner ? "OR pu.is_owner = 1" : "";
        $stmt = $pdo->prepare("SELECT u.id 
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = ? AND (pu.role = 'admin' {$ownerClause})
            ORDER BY pu.created_at ASC, u.id ASC
            LIMIT 1");
        $stmt->execute([$practiceId]);
        $adminId = $stmt->fetchColumn();
        
        if ($adminId) {
            return (int)$adminId;
        }
        
        $stmt = $pdo->prepare("SELECT u.id 
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = ?
            ORDER BY pu.created_at ASC
            LIMIT 1");
        $stmt->execute([$practiceId]);
        $firstUserId = $stmt->fetchColumn();
        return $firstUserId ? (int)$firstUserId : null;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting representative user: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get user preference values for a representative user.
 */
function getRepresentativeUserPreferences($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return [];
    }
    
    $userId = getRepresentativeUserId($practiceId);
    if (!$userId) {
        return [];
    }
    
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM user_preferences")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error reading user_preferences columns: ' . $e->getMessage());
        return [];
    }
    
    $desired = [
        'allow_card_delete',
        'delivered_hide_days',
        'highlight_past_due',
        'past_due_days',
        'highlight_coming_due',
        'coming_due_days'
    ];
    $available = array_intersect($desired, $columns);
    
    if (empty($available)) {
        return [];
    }
    
    try {
        $sql = "SELECT " . implode(', ', $available) . " FROM user_preferences WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting user preferences: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get assignment labels for the Settings tab.
 */
function getPracticeAssignmentLabelsForSettings($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return [];
    }
    
    ensureLabDesignationColumns();
    
    try {
        $stmt = $pdo->prepare("SELECT label, is_lab FROM practice_assignment_labels WHERE practice_id = ? ORDER BY sort_order ASC, label ASC");
        $stmt->execute([$practiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[admin-practices] Error getting assignment labels: ' . $e->getMessage());
        return [];
    }
}

/**
 * Check if any practice user has two-factor authentication enabled.
 */
function getTwoFactorEnabledForPractice($practiceId) {
    global $pdo;
    
    if (!$pdo || !$practiceId) {
        return false;
    }
    
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_enabled'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('totp_enabled', $columns)) {
            return false;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN practice_users pu ON u.id = pu.user_id WHERE pu.practice_id = ? AND u.totp_enabled = 1");
        $stmt->execute([$practiceId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error checking 2FA status: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get read-only Settings payload for the practice.
 */
function getPracticeSettings($practiceId) {
    global $pdo;
    
    $preferences = getRepresentativeUserPreferences($practiceId);
    $workflowLabels = getResolvedWorkflowStageLabels(
        getWorkflowStageLabelOverridesForPractice($practiceId)
    );
    $users = getPracticeUsers($practiceId);
    $labels = getPracticeAssignmentLabelsForSettings($practiceId);
    $twoFactorEnabled = getTwoFactorEnabledForPractice($practiceId);
    
    $deliveredHideDays = isset($preferences['delivered_hide_days']) ? (int)$preferences['delivered_hide_days'] : 0;
    $autoArchive = $deliveredHideDays > 0;
    
    $allowArchiving = isset($preferences['allow_card_delete']) ? (bool)$preferences['allow_card_delete'] : true;
    $archiveAfterDays = $autoArchive ? $deliveredHideDays : 0;
    
    $userList = [];
    foreach ($users as $u) {
        $isOwner = !empty($u['is_owner']);
        $isAdmin = $isOwner || (($u['role'] ?? '') === 'admin');
        $userList[] = [
            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? ''),
            'email' => $u['email'] ?? '',
            'role' => $isOwner ? 'Owner' : 'User',
            'admin' => $isAdmin,
            'assigned_only' => !$isOwner && !empty($u['limited_visibility']),
            'insights' => $isOwner || !empty($u['can_view_analytics']),
            'edit_cases' => $isOwner || !empty($u['can_edit_cases']),
            'lab' => !empty($u['is_lab']),
            'active' => !empty($u['is_active'])
        ];
    }
    
    $labelList = [];
    foreach ($labels as $l) {
        $labelList[] = [
            'label' => $l['label'],
            'is_lab' => (bool)$l['is_lab']
        ];
    }
    
    return [
        'case_management' => [
            'allow_archiving_individual_cases' => $allowArchiving,
            'auto_archive_delivered_cases' => $autoArchive,
            'archive_delivered_cases_after_days' => $archiveAfterDays
        ],
        'due_date_highlighting' => [
            'highlight_past_due' => isset($preferences['highlight_past_due']) ? (bool)$preferences['highlight_past_due'] : false,
            'past_due_days' => isset($preferences['past_due_days']) ? (int)$preferences['past_due_days'] : 0,
            'highlight_coming_due' => isset($preferences['highlight_coming_due']) ? (bool)$preferences['highlight_coming_due'] : false,
            'coming_due_days' => isset($preferences['coming_due_days']) ? (int)$preferences['coming_due_days'] : 5,
            'highlight_appointment_risk' => isset($preferences['highlight_appointment_risk']) ? (bool)$preferences['highlight_appointment_risk'] : true,
            'appointment_risk_days' => isset($preferences['appointment_risk_days']) ? (int)$preferences['appointment_risk_days'] : 3
        ],
        'workflow_stages' => $workflowLabels,
        'users' => $userList,
        'assignment_labels' => $labelList,
        'security' => [
            'two_factor_authentication_enabled' => $twoFactorEnabled
        ]
    ];
}
