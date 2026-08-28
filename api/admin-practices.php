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
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Load dev tools access control
require_once __DIR__ . '/dev-tools-access.php';

// Check if current user can access admin pages (super user OR dev environment)
$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Super user privileges required.']);
    exit;
}

/**
 * Ensure the admin practice email audit log table exists.
 */
function ensureAdminEmailLogSchema() {
    global $pdo;
    static $initialized = false;

    if ($initialized || !$pdo) {
        return;
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_email_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            admin_email VARCHAR(255),
            recipient_user_id BIGINT UNSIGNED NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            practice_id BIGINT UNSIGNED NOT NULL,
            email_type VARCHAR(64) NOT NULL,
            email_subject VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            provider VARCHAR(64) DEFAULT NULL,
            error_message TEXT,
            INDEX idx_practice_id (practice_id),
            INDEX idx_recipient_user_id (recipient_user_id),
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $initialized = true;
    } catch (PDOException $e) {
        error_log('[admin-practices] Error ensuring admin email log schema: ' . $e->getMessage());
    }
}

/**
 * Record an admin email action to the audit log.
 */
function logAdminEmail($adminUserId, $adminEmail, $recipientUserId, $recipientEmail, $practiceId, $emailType, $subject, $success, $provider = null, $error = null) {
    global $pdo;

    if (!$pdo) {
        return false;
    }

    ensureAdminEmailLogSchema();

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
 * Build a normalized, display-friendly subscription info array for admin use.
 *
 * @param array|null $sub      subscriptions row (or null)
 * @param array|null $owner    owner user row (or null)
 * @param int        $ownedCount number of practices this owner has
 * @return array
 */
function buildSubscriptionInfo(?array $sub, ?array $owner, int $ownedCount): array {
    if (empty($sub) || empty($sub['plan'])) {
        $ownerId = $owner ? (int)($owner['owner_user_id'] ?? $owner['id'] ?? null) : null;
        return [
            'has_subscription' => false,
            'plan' => null,
            'plan_display' => '—',
            'status' => 'no_subscription',
            'status_display' => 'No Subscription',
            'is_trialing' => false,
            'trial_ends_at' => null,
            'trial_days_remaining' => null,
            'trial_display' => '',
            'owner_user_id' => $ownerId,
            'owner_email' => $owner ? ($owner['owner_email'] ?? $owner['email'] ?? null) : null,
            'owner_name' => $owner ? trim(($owner['owner_first_name'] ?? $owner['first_name'] ?? '') . ' ' . ($owner['owner_last_name'] ?? $owner['last_name'] ?? '')) : null,
            'owned_practice_count' => $ownedCount,
            'max_practices' => null,
            'capacity_display' => '—',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'current_period_ends_at' => null,
            'billing_interval' => null,
            'cancel_at_period_end' => false,
            'subscription_updated_at' => null,
        ];
    }

    $plan = $sub['plan'] ?? null;
    $maxPractices = null;
    $capacityDisplay = '';
    if (!empty($plan)) {
        $maxPractices = getMaxOwnedPractices($plan);
        if ($maxPractices === null) {
            $capacityDisplay = 'Practices: ' . $ownedCount;
        } else {
            $capacityDisplay = 'Practices: ' . $ownedCount . ' of ' . $maxPractices;
        }
    }

    $status = $sub['status'] ?? null;
    $statusDisplay = 'Unknown';
    if (!empty($status)) {
        $statusDisplay = match ($status) {
            'trialing' => 'Trial',
            'active' => 'Active',
            'past_due' => 'Past Due',
            'unpaid' => 'Unpaid',
            'canceled' => 'Canceled',
            'incomplete' => 'Incomplete',
            'incomplete_expired' => 'Incomplete Expired',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    $trialEndsAt = null;
    $trialDaysRemaining = null;
    $trialDisplay = '';
    $isTrialing = ($status === 'trialing');
    if ($isTrialing && !empty($sub['trial_ends_at'])) {
        $trialEndsAt = $sub['trial_ends_at'];
        try {
            $end = new DateTimeImmutable($trialEndsAt, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $endDate = $end->setTime(0, 0, 0);
            $nowDate = $now->setTime(0, 0, 0);
            $diff = $nowDate->diff($endDate);
            $days = (int)$diff->format('%r%a');

            if ($days > 1) {
                $trialDisplay = $days . ' days left';
                $trialDaysRemaining = $days;
            } elseif ($days === 1) {
                $trialDisplay = '1 day left';
                $trialDaysRemaining = 1;
            } elseif ($days === 0) {
                $trialDisplay = 'Ends today';
                $trialDaysRemaining = 0;
            } else {
                $trialDisplay = 'Trial expired';
                $trialDaysRemaining = $days;
            }
        } catch (Throwable $e) {
            $trialDisplay = '';
        }
    }

    return [
        'has_subscription' => true,
        'plan' => $plan,
        'plan_display' => !empty($plan) ? getPlanDisplayName($plan) : '—',
        'status' => $status,
        'status_display' => $statusDisplay,
        'is_trialing' => $isTrialing,
        'trial_ends_at' => $trialEndsAt,
        'trial_days_remaining' => $trialDaysRemaining,
        'trial_display' => $trialDisplay,
        'owner_user_id' => $owner ? (int)($owner['owner_user_id'] ?? $owner['id'] ?? null) : null,
        'owner_email' => $owner ? ($owner['owner_email'] ?? $owner['email'] ?? null) : null,
        'owner_name' => $owner ? trim(($owner['owner_first_name'] ?? $owner['first_name'] ?? '') . ' ' . ($owner['owner_last_name'] ?? $owner['last_name'] ?? '')) : null,
        'owned_practice_count' => $ownedCount,
        'max_practices' => $maxPractices,
        'capacity_display' => $capacityDisplay,
        'stripe_customer_id' => $sub['stripe_customer_id'] ?? null,
        'stripe_subscription_id' => $sub['stripe_subscription_id'] ?? null,
        'current_period_ends_at' => $sub['current_period_ends_at'] ?? null,
        'billing_interval' => $sub['billing_interval'] ?? null,
        'cancel_at_period_end' => !empty($sub['cancel_at_period_end']),
        'subscription_updated_at' => $sub['subscription_updated_at'] ?? null,
    ];
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
            WHERE event_type = 'status_changed' AND new_status = :terminal
            GROUP BY case_id
        ) l ON l.case_id = c.case_id
        WHERE c.practice_id = :practice_id
          AND c.status = :terminal
          AND COALESCE(c.status_changed_at, l.delivered_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $terminalStmt->execute(['practice_id' => $practiceId, 'terminal' => $terminalStatus]);
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
                        s.created_at
                    FROM practice_users pu
                    JOIN users u ON u.id = pu.user_id
                    LEFT JOIN subscriptions s ON s.owner_user_id = u.id
                    WHERE pu.practice_id IN ($placeholders)
                      AND pu.is_owner = 1
                ");
                $stmt->execute($practiceIds);
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
                    $countStmt->execute($ownerUserIds);
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

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePostRequest($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
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

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
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
