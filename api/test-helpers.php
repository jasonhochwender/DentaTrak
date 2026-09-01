<?php
/**
 * Test Helpers API
 *
 * SECURITY: This endpoint should ONLY be available in development/test environments.
 * It provides helper functions for automated testing.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-trial.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/scale-subscription-addons.php';
require_once __DIR__ . '/stripe-webhook-guard.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/feature-flags.php';

// SECURITY CHECK: Only allow in development environment
$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Test helpers not available in production']);
    exit;
}

// Also check for a test mode flag
$testMode = getenv('DENTATRAK_TEST_MODE') === 'true' ||
            ($appConfig['test_mode'] ?? false) === true ||
            $environment === 'development';

if (!$testMode && $environment !== 'development') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Test mode not enabled']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

define('TEST_CASE_MARKER', 'DentaTrakTest');

// Get JSON input
$jsonInput = file_get_contents('php://input');
$input = json_decode($jsonInput, true);

$action = $input['action'] ?? '';

switch ($action) {
    case 'verify_email':
        handleVerifyEmail($pdo, $input);
        break;
    case 'setup_test_user':
        handleSetupTestUser($pdo, $input);
        break;
    case 'cleanup_test_user':
        handleCleanupTestUser($pdo, $input);
        break;
    case 'setup_practice_member':
        handleSetupPracticeMember($pdo, $input);
        break;
    case 'set_subscription_plan':
        handleSetSubscriptionPlan($pdo, $input);
        break;
    case 'set_stripe_subscription_id':
        handleSetStripeSubscriptionId($pdo, $input);
        break;
    case 'test_scale_addon_restore':
        handleTestScaleAddonRestore($pdo, $appConfig, $input);
        break;
    case 'test_webhook_mode_guard':
        handleTestWebhookModeGuard($appConfig, $input);
        break;
    case 'seed_owned_practices':
        handleSeedOwnedPractices($pdo, $input);
        break;
    case 'describe_plan_config':
        handleDescribePlanConfig($appConfig, $input);
        break;
    case 'preview_scale_checkout':
        handlePreviewScaleCheckout($pdo, $appConfig, $input);
        break;
    case 'get_subscription_state':
        handleGetSubscriptionState($pdo, $input);
        break;
    case 'set_workflow_stage_labels':
        handleSetWorkflowStageLabels($pdo, $input);
        break;
    case 'get_ai_workflow_stages_prompt_text':
        handleGetAiWorkflowStagesPromptText($pdo, $input);
        break;
    case 'get_export_file_by_id':
        handleGetExportFileById($pdo, $input);
        break;
    case 'cleanup_test_data':
        handleCleanupTestData($pdo, $input);
        break;
    case 'list_test_cases':
        handleListTestCases($pdo, $input);
        break;
    case 'delete_test_cases':
        handleDeleteTestCases($pdo, $input);
        break;
    case 'get_last_app_email':
        handleGetLastAppEmail($appConfig, $input);
        break;
    case 'get_all_app_emails':
        handleGetAllAppEmails($appConfig, $input);
        break;
    case 'clear_test_email_log':
        handleClearTestEmailLog($appConfig, $input);
        break;
    case 'force_email_failure':
        handleForceEmailFailure($appConfig, $input);
        break;
    case 'clear_email_failure':
        handleClearEmailFailure($appConfig, $input);
        break;
    case 'get_practice_user':
        handleGetPracticeUser($pdo, $input);
        break;

    case 'seed_lab_insights_data':
        handleSeedLabInsightsData($pdo, $input);
        break;

    case 'cleanup_lab_insights_data':
        handleCleanupLabInsightsData($pdo, $input);
        break;

    case 'set_practice_member_permissions':
        handleSetPracticeMemberPermissions($pdo, $input);
        break;

    case 'delete_test_users':
        handleDeleteTestUsers($pdo, $input);
        break;

    case 'make_legacy_trial':
        handleMakeLegacyTrial($pdo, $input);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Verify email for a test user (bypasses email verification)
 */
function handleVerifyEmail($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE email = :email");
        $stmt->execute(['email' => $email]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Email verified']);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

/**
 * Set up a complete test user with practice and BAA
 * This is a one-stop setup for E2E testing
 */
function handleSetupTestUser($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $firstName = trim($input['firstName'] ?? TEST_CASE_MARKER);
    $lastName = trim($input['lastName'] ?? 'Test');
    $practiceName = trim($input['practiceName'] ?? 'E2E Test Practice');

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and password are required']);
        return;
    }

    try {
        $pdo->beginTransaction();

        // Check if user already exists
        $stmt = $pdo->prepare("SELECT id, email_verified FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        $userId = null;

        if ($existingUser) {
            $userId = $existingUser['id'];

            // Ensure email is verified and reset created_at to prevent trial expiration
            $stmt = $pdo->prepare("
                UPDATE users
                SET email_verified = 1,
                    created_at = NOW(),
                    first_name = :first_name,
                    last_name = :last_name
                WHERE id = :id
            ");
            $stmt->execute([
                'id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        } else {
            // Create new user
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO users (
                    email, password_hash, auth_method, first_name, last_name,
                    role, is_active, email_verified, created_at
                ) VALUES (
                    :email, :password_hash, 'email', :first_name, :last_name,
                    'admin', 1, 1, NOW()
                )
            ");
            $stmt->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'first_name' => $firstName,
                'last_name' => $lastName
            ]);

            $userId = $pdo->lastInsertId();

            // Create default preferences
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO user_preferences (user_id, theme, allow_card_delete, highlight_past_due, past_due_days, tour_completed)
                    VALUES (:user_id, 'light', TRUE, TRUE, 7, TRUE)
                ");
                $stmt->execute(['user_id' => $userId]);
            } catch (PDOException $e) {
                // Preferences table might not exist or have different schema
            }
        }

        // Mirror api/accept-baa.php's real creation path: every owner gets
        // their single owner-level `subscriptions` row (with its trial)
        // regardless of whether this call is creating their first practice or
        // just re-confirming an existing one (this call is idempotent - see
        // getOrCreateSubscriptionForOwner()). Without this, a test owner
        // whose only practice was created directly by this endpoint would
        // have NO subscriptions row - unlike every real signup - and would
        // incorrectly read as "no active subscription" (read-only) instead
        // of trialing wherever full account access is gated on it (e.g.
        // api/create-case.php).
        getOrCreateSubscriptionForOwner($pdo, $userId);

        // Check if user has a practice
        $stmt = $pdo->prepare("
            SELECT p.id, p.baa_accepted
            FROM practices p
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $existingPractice = $stmt->fetch(PDO::FETCH_ASSOC);

        $practiceId = null;

        if ($existingPractice) {
            $practiceId = $existingPractice['id'];

            // Ensure BAA is accepted
            if (!$existingPractice['baa_accepted']) {
                $stmt = $pdo->prepare("
                    UPDATE practices SET
                        baa_accepted = 1,
                        baa_accepted_at = NOW(),
                        baa_version = 'v1.0-test',
                        baa_accepted_by_user_id = :user_id,
                        baa_signer_name = :signer_name,
                        baa_signer_title = 'Test Admin'
                    WHERE id = :practice_id
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'signer_name' => $firstName . ' ' . $lastName,
                    'practice_id' => $practiceId
                ]);
            }
        } else {
            // Create practice with BAA
            $practiceUuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            $trial = getNewPracticeTrialDefaults();

            $stmt = $pdo->prepare("
                INSERT INTO practices (
                    practice_id, practice_name, legal_name, display_name, practice_address,
                    baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
                    baa_signer_name, baa_signer_title, created_by,
                    subscription_status, trial_ends_at
                ) VALUES (
                    :practice_uuid, :practice_name, :legal_name, :display_name, :practice_address,
                    1, UTC_TIMESTAMP(), 'v1.0-test', :user_id,
                    :signer_name, 'Test Admin', :created_by,
                    :subscription_status, :trial_ends_at
                )
            ");

            $stmt->execute([
                'practice_uuid' => $practiceUuid,
                'practice_name' => $practiceName,
                'legal_name' => $practiceName,
                'display_name' => $practiceName,
                'practice_address' => '123 Test Street, Test City, TS 12345',
                'user_id' => $userId,
                'signer_name' => $firstName . ' ' . $lastName,
                'created_by' => $userId,
                'subscription_status' => $trial['subscription_status'],
                'trial_ends_at'       => $trial['trial_ends_at'],
            ]);

            $practiceId = $pdo->lastInsertId();

            // Add user to practice as admin/owner
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner)
                VALUES (:practice_id, :user_id, 'admin', TRUE)
            ");
            $stmt->execute([
                'practice_id' => $practiceId,
                'user_id' => $userId
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Test user setup complete',
            'user_id' => $userId,
            'practice_id' => $practiceId,
            'email' => $email
        ]);

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[test-helpers] Setup error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Setup failed: ' . $e->getMessage()]);
    }
}

/**
 * Add (or update) a secondary test user as a member of an EXISTING test
 * practice, with a configurable role and limited_visibility flag.
 *
 * Used by Playwright regression tests to exercise the admin-only permission
 * model with real, independently-logged-in sessions for each role (admin,
 * normal non-admin, Assigned Only) rather than mutating a single shared
 * test account's role mid-test-run.
 */
function handleSetupPracticeMember($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $firstName = trim($input['firstName'] ?? TEST_CASE_MARKER);
    $lastName = trim($input['lastName'] ?? 'Member');
    $adminEmail = strtolower(trim($input['adminEmail'] ?? ''));
    $explicitPracticeId = !empty($input['practiceId']) ? (int)$input['practiceId'] : 0;
    $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $limitedVisibility = !empty($input['limitedVisibility']) ? 1 : 0;
    $canViewAnalytics = !empty($input['canViewAnalytics']) ? 1 : 0;
    $canEditCases = !empty($input['canEditCases']) ? 1 : 0;

    if (empty($email) || empty($password) || (empty($adminEmail) && !$explicitPracticeId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email, password, and adminEmail or practiceId are required']);
        return;
    }

    try {
        $practiceId = null;

        // Use an explicit practice_id if provided; this is more reliable when the
        // same admin email might belong to multiple practices across fixture runs.
        if ($explicitPracticeId) {
            $stmt = $pdo->prepare("SELECT 1 FROM practices WHERE id = :practice_id");
            $stmt->execute(['practice_id' => $explicitPracticeId]);
            if ($stmt->fetchColumn()) {
                $practiceId = $explicitPracticeId;
            }
        }

        // Resolve from the existing admin's owned membership if no explicit id.
        if (!$practiceId && $adminEmail) {
            $stmt = $pdo->prepare("
                SELECT p.id
                FROM practices p
                JOIN practice_users pu ON p.id = pu.practice_id
                JOIN users u ON u.id = pu.user_id
                WHERE u.email = :admin_email AND pu.is_owner = 1
                LIMIT 1
            ");
            $stmt->execute(['admin_email' => $adminEmail]);
            $practiceId = $stmt->fetchColumn();
        }

        if (!$practiceId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No practice found for adminEmail. Run setup_test_user first.']);
            return;
        }

        $pdo->beginTransaction();

        // Create or reuse the member user account
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET email_verified = 1,
                    password_hash = :password_hash,
                    auth_method = 'email'
                WHERE id = :id
            ");
            $stmt->execute(['id' => $userId, 'password_hash' => $passwordHash]);
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    email, password_hash, auth_method, first_name, last_name,
                    role, is_active, email_verified, created_at
                ) VALUES (
                    :email, :password_hash, 'email', :first_name, :last_name,
                    'user', 1, 1, NOW()
                )
            ");
            $stmt->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'first_name' => $firstName,
                'last_name' => $lastName
            ]);
            $userId = $pdo->lastInsertId();

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO user_preferences (user_id, theme, allow_card_delete, highlight_past_due, past_due_days, tour_completed)
                    VALUES (:user_id, 'light', TRUE, TRUE, 7, TRUE)
                ");
                $stmt->execute(['user_id' => $userId]);
            } catch (PDOException $e) {
                // Preferences table might not exist or have different schema
            }
        }

        // Insert or update this user's membership row for the practice.
        // is_owner is always FALSE here - ownership is set once at practice
        // creation (setup_test_user) and is not something this helper grants.
        $stmt = $pdo->prepare("
            SELECT 1 FROM practice_users WHERE practice_id = :practice_id AND user_id = :user_id
        ");
        $stmt->execute(['practice_id' => $practiceId, 'user_id' => $userId]);

        if ($stmt->fetchColumn()) {
            $stmt = $pdo->prepare("
                UPDATE practice_users
                SET role = :role, limited_visibility = :limited_visibility,
                    can_view_analytics = :can_view_analytics,
                    can_edit_cases = :can_edit_cases
                WHERE practice_id = :practice_id AND user_id = :user_id
            ");
            $stmt->execute([
                'role' => $role,
                'limited_visibility' => $limitedVisibility,
                'can_view_analytics' => $canViewAnalytics,
                'can_edit_cases' => $canEditCases,
                'practice_id' => $practiceId,
                'user_id' => $userId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases)
                VALUES (:practice_id, :user_id, :role, FALSE, :limited_visibility, :can_view_analytics, :can_edit_cases)
            ");
            $stmt->execute([
                'practice_id' => $practiceId,
                'user_id' => $userId,
                'role' => $role,
                'limited_visibility' => $limitedVisibility,
                'can_view_analytics' => $canViewAnalytics,
                'can_edit_cases' => $canEditCases
            ]);
        }

        // Optional: grant this user access to specific assignment labels (used
        // by Lab Insights tests for Assigned Only users whose cases are routed
        // to lab labels rather than to their personal email).
        $labelIds = $input['labelIds'] ?? [];
        if (is_array($labelIds) && !empty($labelIds)) {
            $checkStmt = $pdo->prepare("
                SELECT 1
                FROM practice_assignment_labels
                WHERE id = :id AND practice_id = :practice_id
            ");
            $insertStmt = $pdo->prepare("
                INSERT IGNORE INTO practice_assignment_label_recipients (practice_id, label_id, user_id)
                VALUES (:practice_id, :label_id, :user_id)
            ");
            foreach ($labelIds as $labelId) {
                $labelId = (int)$labelId;
                if ($labelId <= 0) {
                    continue;
                }
                $checkStmt->execute([':id' => $labelId, ':practice_id' => $practiceId]);
                if ($checkStmt->fetchColumn()) {
                    $insertStmt->execute([':practice_id' => $practiceId, ':label_id' => $labelId, ':user_id' => $userId]);
                }
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Practice member setup complete',
            'user_id' => $userId,
            'practice_id' => $practiceId,
            'email' => $email,
            'role' => $role,
            'limited_visibility' => (bool)$limitedVisibility
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] setup_practice_member error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Setup failed: ' . $e->getMessage()]);
    }
}

/**
 * Directly set a test user's (subscription owner's) plan, bypassing Stripe
 * Checkout entirely. Used by Playwright tests to exercise Control/Scale
 * practice-count entitlement boundaries without needing a real Stripe
 * checkout flow for every plan tier.
 *
 * Resolves the owner from the given email (must already own at least one
 * practice - i.e. have run through setup_test_user first) and upserts their
 * `subscriptions` row with the given plan and status.
 *
 * Optional input:
 *   stripe_customer_id - stamp a known customer ID on the row so webhook
 *                        tests can route Stripe-signed events to this owner
 *                        the same way real events are routed.
 */
function handleSetSubscriptionPlan($pdo, $input) {
    $email             = strtolower(trim($input['email'] ?? ''));
    $plan              = trim($input['plan'] ?? '');
    $status            = trim($input['status'] ?? 'active');
    $customerId        = trim($input['stripe_customer_id'] ?? '');
    $subscriptionId    = trim($input['stripe_subscription_id'] ?? '');
    $billingInterval   = trim($input['billing_interval'] ?? '');

    $allowedPlans = ['operate', 'control', 'scale'];
    if (empty($email) || !in_array($plan, $allowedPlans, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and a valid plan (operate|control|scale) are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }

        // Ensures a subscriptions row exists (creating the trial once, if
        // this is the owner's first practice) before overwriting the plan.
        getOrCreateSubscriptionForOwner($pdo, (int)$userId);

        $stmt = $pdo->prepare("
            UPDATE subscriptions
            SET plan = :plan,
                status = :status,
                stripe_subscription_id = COALESCE(NULLIF(:stripe_subscription_id, ''), COALESCE(stripe_subscription_id, :fake_sub_id)),
                billing_interval = COALESCE(NULLIF(:billing_interval, ''), billing_interval),
                subscription_updated_at = UTC_TIMESTAMP()
            WHERE owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'plan'                   => $plan,
            'status'                 => $status,
            'stripe_subscription_id' => $subscriptionId,
            'billing_interval'       => $billingInterval,
            'fake_sub_id'            => 'sub_test_' . $userId,
            'owner_user_id'          => $userId,
        ]);

        if ($customerId !== '') {
            $pdo->prepare("
                UPDATE subscriptions SET stripe_customer_id = :cid WHERE owner_user_id = :owner_user_id
            ")->execute([
                'cid'           => $customerId,
                'owner_user_id' => $userId,
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Subscription plan updated',
            'user_id' => (int)$userId,
            'plan'    => $plan,
            'status'  => $status,
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] set_subscription_plan error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

/**
 * Directly stamp a Stripe subscription ID and billing interval on an
 * existing owner's subscriptions row. Useful when a test needs an explicit,
 * known subscription ID (e.g., an invalid fake ID for the billing-failure
 * path) but does not want to change the plan/status.
 */
function handleSetStripeSubscriptionId($pdo, $input) {
    $email           = strtolower(trim($input['email'] ?? ''));
    $subscriptionId  = trim($input['stripe_subscription_id'] ?? '');
    $billingInterval = trim($input['billing_interval'] ?? '');

    if (empty($email) || empty($subscriptionId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and stripe_subscription_id are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }

        $fields = ['stripe_subscription_id = :stripe_subscription_id'];
        $params = [
            'stripe_subscription_id' => $subscriptionId,
            'owner_user_id'          => $userId,
        ];

        if ($billingInterval !== '') {
            $fields[] = 'billing_interval = :billing_interval';
            $params['billing_interval'] = $billingInterval;
        }

        $sql = "UPDATE subscriptions SET " . implode(', ', $fields) . ", subscription_updated_at = UTC_TIMESTAMP() WHERE owner_user_id = :owner_user_id";
        $pdo->prepare($sql)->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'Stripe subscription ID updated',
            'user_id' => (int)$userId,
            'stripe_subscription_id' => $subscriptionId,
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] set_stripe_subscription_id error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

/**
 * Test-only helper that exercises the Scale add-on compensation path.
 *
 * Looks up the owner by email, ensures a real Stripe test subscription
 * exists (creating one if the stored ID is missing/invalid), then calls
 * syncScaleAddOnQuantity() and restoreScaleAddOnQuantity() and returns the
 * quantities observed. This is the recoverable half of the compensation path
 * in api/accept-baa.php and is intentionally verified without forcing an
 * unrecoverable PDO commit failure from the browser.
 */
function handleTestScaleAddonRestore($pdo, $appConfig, $input) {
    $email    = strtolower(trim($input['email'] ?? ''));
    $interval = in_array($input['interval'] ?? '', ['month', 'year'], true) ? $input['interval'] : 'month';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email is required']);
        return;
    }

    $secretKey = $appConfig['stripe']['secret_key'] ?? null;
    if (empty($secretKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Stripe secret key not configured']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }
        $userId = (int)$user['id'];

        $stmt = $pdo->prepare("
            SELECT stripe_customer_id, stripe_subscription_id, billing_interval, plan, status
            FROM subscriptions
            WHERE owner_user_id = :owner_user_id
            LIMIT 1
        ");
        $stmt->execute(['owner_user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $subId      = $row['stripe_subscription_id'] ?? '';
        $customerId = $row['stripe_customer_id'] ?? '';
        $hasValidSub = false;

        \Stripe\Stripe::setApiKey($secretKey);

        // Treat test-only fake IDs (sub_test_*) and un-verifiable IDs as
        // invalid so the helper can stand up a real Stripe test subscription.
        if (!empty($subId) && !str_starts_with($subId, 'sub_test_')) {
            try {
                \Stripe\Subscription::retrieve($subId);
                $hasValidSub = true;
            } catch (\Exception $e) {
                $hasValidSub = false;
            }
        }

        if (!$hasValidSub) {
            $basePriceId  = getStripePriceId('scale', $interval, $appConfig);
            $addOnPriceId = getScaleAdditionalPriceId($interval, $appConfig);

            if (empty($basePriceId) || empty($addOnPriceId)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Scale Price IDs not configured for ' . $interval]);
                return;
            }

            if (empty($customerId)) {
                $customer = \Stripe\Customer::create(['email' => $user['email'], 'name' => 'E2E Test ' . $userId]);
                $customerId = $customer->id;
            }

            $subscription = \Stripe\Subscription::create([
                'customer' => $customerId,
                'items'    => [
                    ['price' => $basePriceId],
                    ['price' => $addOnPriceId, 'quantity' => 1],
                ],
                'trial_end' => time() + 365 * 24 * 60 * 60,
            ]);

            $subId = $subscription->id;

            $pdo->prepare("
                UPDATE subscriptions
                SET stripe_customer_id = :customer_id,
                    stripe_subscription_id = :sub_id,
                    billing_interval = :interval,
                    plan = :plan,
                    status = :status,
                    subscription_updated_at = UTC_TIMESTAMP()
                WHERE owner_user_id = :owner_user_id
            ")->execute([
                'customer_id'   => $customerId,
                'sub_id'        => $subId,
                'interval'      => $interval,
                'plan'          => 'scale',
                'status'        => 'active',
                'owner_user_id' => $userId,
            ]);
        }

        $previousQuantity = syncScaleAddOnQuantity($pdo, $userId, 6, $appConfig);
        restoreScaleAddOnQuantity($pdo, $userId, 1, $appConfig);

        // Read back the restored add-on quantity for the test assertion.
        $stmt = $pdo->prepare("
            SELECT stripe_subscription_id, billing_interval
            FROM subscriptions
            WHERE owner_user_id = :owner_user_id
            LIMIT 1
        ");
        $stmt->execute(['owner_user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $subId = $row['stripe_subscription_id'] ?? $subId;
        $dbInterval = $row['billing_interval'] ?? $interval;

        $addOnPriceId = getScaleAdditionalPriceId($dbInterval, $appConfig);
        $items = \Stripe\SubscriptionItem::all(['subscription' => $subId, 'limit' => 100]);
        $restoredQuantity = 0;
        foreach ($items->data as $item) {
            if (($item->price->id ?? null) === $addOnPriceId) {
                $restoredQuantity = (int)$item->quantity;
                break;
            }
        }

        echo json_encode([
            'success'                => true,
            'previous_quantity'      => $previousQuantity ?? 0,
            'restored_quantity'      => $restoredQuantity,
            'stripe_subscription_id' => $subId,
        ]);
    } catch (\Exception $e) {
        error_log('[test-helpers] test_scale_addon_restore error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
    }
}

/**
 * Verify the webhook mode guard without sending a real HTTP request.
 * Takes an environment ('live' or 'test'), livemode flag, and whether the
 * signature was verified with the test secret, and returns should_process.
 */
function handleTestWebhookModeGuard(array $appConfig, array $input) {
    $environment          = in_array($input['environment'] ?? '', ['live', 'test'], true) ? $input['environment'] : 'test';
    $livemode             = (bool)($input['livemode'] ?? false);
    $verifiedWithTest     = (bool)($input['verified_with_test_secret'] ?? false);

    $testConfig = $appConfig;
    $testConfig['stripe']['environment'] = $environment;

    $event = new stdClass();
    $event->livemode = $livemode;

    $shouldProcess = shouldProcessWebhookEvent($event, $verifiedWithTest, $testConfig);

    echo json_encode([
        'success'        => true,
        'environment'    => $environment,
        'livemode'       => $livemode,
        'verified_test'  => $verifiedWithTest,
        'should_process' => $shouldProcess,
    ]);
}

/**
 * Read-only snapshot of an owner's single subscriptions row plus their
 * owned-practice count, so tests can assert what checkout/webhook handling
 * actually persisted at the OWNER level (rather than inferring it from the
 * UI, or from the deprecated per-practice billing columns).
 */
function handleGetSubscriptionState($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT owner_user_id, stripe_customer_id, stripe_subscription_id, stripe_price_id,
                   plan, billing_interval, status, trial_ends_at, current_period_ends_at,
                   cancel_at_period_end, subscription_updated_at, stripe_event_created
            FROM subscriptions
            WHERE owner_user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($subscription) {
            $subscription['cancel_at_period_end'] = (bool)$subscription['cancel_at_period_end'];
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM practice_users WHERE user_id = :user_id AND is_owner = 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $ownedCount = (int)$stmt->fetchColumn();

        // Deprecated per-practice Stripe columns are reported only so tests
        // can assert they are NOT being written as a source of truth.
        $legacy = [];
        try {
            $stmt = $pdo->prepare("
                SELECT p.id, p.subscription_plan, p.subscription_status, p.stripe_subscription_id
                FROM practices p
                JOIN practice_users pu ON pu.practice_id = p.id
                WHERE pu.user_id = :user_id AND pu.is_owner = 1
            ");
            $stmt->execute(['user_id' => $userId]);
            $legacy = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $legacy = []; // Columns may already be dropped - not an error here
        }

        echo json_encode([
            'success'                     => true,
            'user_id'                     => (int)$userId,
            'subscription'                => $subscription,
            'owned_practice_count'        => $ownedCount,
            'legacy_practice_billing_rows' => $legacy,
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] get_subscription_state error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
    }
}

/**
 * Read-only view of the plan/Stripe-price configuration for the RUNNING
 * environment, so tests can assert the Price ID <-> plan mapping and the
 * environment-safety rules without needing Stripe credentials or a browser.
 *
 * Returns no secrets: Price IDs are not credentials, and this endpoint is
 * already hard-blocked in production (see the environment gate at the top
 * of this file).
 *
 * Optional input:
 *   price_id  - resolve a specific Stripe Price ID to [plan, interval]
 */
function handleDescribePlanConfig(array $appConfig, array $input) {
    require_once __DIR__ . '/stripe-price-map.php';
    require_once __DIR__ . '/plan-entitlements.php';

    $plans = [];
    foreach (getKnownPlans() as $plan) {
        $priceIds = [
            'month' => getStripePriceId($plan, 'month', $appConfig),
            'year'  => getStripePriceId($plan, 'year',  $appConfig),
        ];
        if ($plan === 'scale') {
            $priceIds['additional_month'] = getScaleAdditionalPriceId('month', $appConfig);
            $priceIds['additional_year']  = getScaleAdditionalPriceId('year',  $appConfig);
        }
        $plans[$plan] = [
            'max_practices'   => getMaxOwnedPractices($plan),
            'display_name'    => getPlanDisplayName($plan),
            'upgrade_target'  => PLAN_UPGRADE_TARGET[$plan] ?? null,
            'meets_control'   => planMeetsTier($plan, 'control'),
            'price_ids'       => $priceIds,
            'display_prices'  => $appConfig['stripe']['display_prices'][$plan] ?? null,
        ];
    }

    $response = [
        'success'           => true,
        'stripe_environment' => $appConfig['stripe']['environment'] ?? null,
        'config_error'      => $appConfig['stripe']['config_error'] ?? null,
        'configured_plans'  => getConfiguredStripePlans($appConfig),
        'plans'             => $plans,
    ];

    if (!empty($input['price_id'])) {
        [$plan, $interval] = resolvePlanFromPriceId((string)$input['price_id'], $appConfig);
        $response['resolved'] = ['plan' => $plan, 'interval' => $interval];
    }

    echo json_encode($response);
}

/**
 * Preview the line_items that a Scale Checkout Session would be created
 * with for the given owner, without actually calling Stripe. Used to
 * exercise the base + add-on item structure for 5 vs 6+ owned practices.
 */
function handlePreviewScaleCheckout(PDO $pdo, array $appConfig, array $input) {
    require_once __DIR__ . '/stripe-price-map.php';
    require_once __DIR__ . '/plan-entitlements.php';

    $email = strtolower(trim($input['email'] ?? ''));
    $interval = in_array($input['interval'] ?? '', ['month', 'year'], true) ? $input['interval'] : 'month';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }

        $ownedCount = getOwnedPracticeCount($pdo, (int)$userId);
        $basePriceId = getStripePriceId('scale', $interval, $appConfig);
        $addOnPriceId = getScaleAdditionalPriceId($interval, $appConfig);
        $addOnQty = max(0, $ownedCount - 5);

        $lineItems = [[
            'price'    => $basePriceId,
            'quantity' => 1,
        ]];
        if ($addOnQty > 0) {
            $lineItems[] = [
                'price'    => $addOnPriceId,
                'quantity' => $addOnQty,
            ];
        }

        echo json_encode([
            'success'             => true,
            'owned_practice_count' => $ownedCount,
            'additional_quantity'  => $addOnQty,
            'line_items'           => $lineItems,
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] preview_scale_checkout error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Preview failed: ' . $e->getMessage()]);
    }
}

/**
 * Directly seed N bare-minimum OWNED practices (practice_users.is_owner=1)
 * for a user, bypassing the BAA/accept-baa.php flow entirely.
 *
 * Used only to quickly set up plan-limit BOUNDARY conditions (e.g. "an
 * owner already at 49 of Scale's 50") for testing - the count these
 * practices contribute is the exact same practice_users.is_owner=1 signal
 * api/plan-entitlements.php reads, so this is a faithful way to reach a
 * given owned-practice count without actually driving 50 real practices
 * through the full E2E creation UI.
 */
function handleSeedOwnedPractices($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $count = (int)($input['count'] ?? 0);

    if (empty($email) || $count < 1 || $count > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and count (1-100) are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }

        $pdo->beginTransaction();
        $createdIds = [];

        for ($i = 0; $i < $count; $i++) {
            $practiceUuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            $seedName = 'Seeded Practice ' . $userId . '-' . $i . '-' . mt_rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO practices (
                    practice_id, practice_name, legal_name, display_name, practice_address,
                    baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id, created_by
                ) VALUES (
                    :practice_uuid, :name1, :name2, :name3, 'Seeded Address',
                    1, UTC_TIMESTAMP(), 'v1.0-test-seed', :user_id1, :user_id2
                )
            ");
            $stmt->execute([
                'practice_uuid' => $practiceUuid,
                'name1'         => $seedName,
                'name2'         => $seedName,
                'name3'         => $seedName,
                'user_id1'      => $userId,
                'user_id2'      => $userId,
            ]);
            $practiceId = $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner)
                VALUES (:practice_id, :user_id, 'admin', TRUE)
            ")->execute([
                'practice_id' => $practiceId,
                'user_id'     => $userId,
            ]);

            $createdIds[] = (int)$practiceId;
        }

        $pdo->commit();

        echo json_encode([
            'success'      => true,
            'created'      => count($createdIds),
            'practice_ids' => $createdIds,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] seed_owned_practices error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Seeding failed: ' . $e->getMessage()]);
    }
}

/**
 * Simulate a pre-migration practice that has legacy trial data but no
 * authoritative owner-level subscriptions row. Test-only.
 */
function handleMakeLegacyTrial($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $trialEndsAt = trim($input['trial_ends_at'] ?? '');

    if (empty($email) || empty($trialEndsAt)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and trial_ends_at are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }

        $pdo->beginTransaction();

        // Delete any owner-level subscription to simulate pre-migration state.
        $pdo->prepare("DELETE FROM subscriptions WHERE owner_user_id = :owner_user_id")
            ->execute(['owner_user_id' => $userId]);

        // Stamp legacy trial data on the most recently created practice for this user.
        $practiceStmt = $pdo->prepare("
            SELECT p.id
            FROM practices p
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id AND pu.is_owner = 1
            ORDER BY p.id DESC
            LIMIT 1
        ");
        $practiceStmt->execute(['user_id' => $userId]);
        $practiceId = $practiceStmt->fetchColumn();

        if ($practiceId) {
            $pdo->prepare("
                UPDATE practices
                SET trial_ends_at = :trial_ends_at,
                    subscription_status = 'trialing',
                    subscription_updated_at = UTC_TIMESTAMP()
                WHERE id = :practice_id
            ")->execute([
                'trial_ends_at' => $trialEndsAt,
                'practice_id' => $practiceId,
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Legacy trial simulated',
            'user_id' => (int)$userId,
            'practice_id' => (int)$practiceId,
            'trial_ends_at' => $trialEndsAt,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] make_legacy_trial error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Legacy setup failed: ' . $e->getMessage()]);
    }
}

/**
 * Clean up test user data (for test isolation)
 */
function handleCleanupTestUser($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        return;
    }

    // Safety check - only allow cleanup of test emails
    if (!str_contains($email, 'test') && !str_contains($email, 'e2e')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Can only cleanup test accounts']);
        return;
    }

    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection unavailable']);
        return;
    }

    try {
        // Get user ID
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => true, 'message' => 'User not found (already clean)']);
            return;
        }

        $userId = (int)$user['id'];

        $pdo->beginTransaction();

        // Resolve practices this user owns/memberships before removing the mapping.
        $practiceStmt = $pdo->prepare("SELECT practice_id FROM practice_users WHERE user_id = :user_id");
        $practiceStmt->execute(['user_id' => $userId]);
        $practiceIds = $practiceStmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete case data from either known table name; ignore missing tables.
        if (!empty($practiceIds)) {
            $caseTables = ['cases', 'cases_cache'];
            $placeholders = implode(',', array_fill(0, count($practiceIds), '?'));
            foreach ($caseTables as $table) {
                try {
                    $tableExists = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
                    if ($tableExists) {
                        $deleteStmt = $pdo->prepare("DELETE FROM {$table} WHERE practice_id IN ({$placeholders})");
                        $deleteStmt->execute(array_values($practiceIds));
                    }
                } catch (PDOException $caseEx) {
                    // Either table name is wrong or it is not present in this schema.
                    // Continue cleaning up the rest of the fixture.
                }
            }
        }

        // Remove membership mappings.
        $pdo->prepare("DELETE FROM practice_users WHERE user_id = :user_id")
            ->execute(['user_id' => $userId]);

        // Remove the owner-level subscription for this user.
        $pdo->prepare("DELETE FROM subscriptions WHERE owner_user_id = :user_id")
            ->execute(['user_id' => $userId]);

        // Remove the test practices owned by this user.
        if (!empty($practiceIds)) {
            $placeholders = implode(',', array_fill(0, count($practiceIds), '?'));
            $pdo->prepare("DELETE FROM practices WHERE id IN ({$placeholders})")
                ->execute($practiceIds);
        }

        // Finally remove the test user.
        $pdo->prepare("DELETE FROM users WHERE id = :user_id")
            ->execute(['user_id' => $userId]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Test user and fixtures removed']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] cleanup_test_user failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed']);
    }
}

/**
 * Test-only fixture setup for the workflow-stage display-label foundation
 * (no Settings UI exists to do this yet). Sets the given practice's
 * `workflow_stage_labels` column directly so tests can exercise the
 * resolver/get-settings.php without a save endpoint.
 *
 * Normal mode: `{ email, overrides: { <internalStatus>: <label>, ... } }`
 * - runs the exact same normalizeWorkflowStageLabelsForSave() validation
 * the future Settings save endpoint will use, so this helper can never
 * store anything the real save path wouldn't also allow.
 *
 * Escape-hatch mode: `{ email, raw: <string|null> }` - writes the raw
 * value directly, bypassing validation entirely, so tests can exercise the
 * defensive read-time parser (parseWorkflowStageLabelOverrides()) against
 * malformed/legacy data (invalid JSON, unknown keys, overlong values).
 */
function handleSetWorkflowStageLabels($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email is required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $userId = $stmt->fetchColumn();

        if (!$userId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
            return;
        }

        $stmt = $pdo->prepare("SELECT id FROM practices WHERE created_by = :user_id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $practiceId = $stmt->fetchColumn();

        if (!$practiceId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No practice found for this user.']);
            return;
        }

        ensureWorkflowStageLabelsColumn();

        if (array_key_exists('raw', $input)) {
            $raw = $input['raw'];
            $stmt = $pdo->prepare("UPDATE practices SET workflow_stage_labels = :labels WHERE id = :id");
            $stmt->execute(['labels' => $raw === null ? null : (string)$raw, 'id' => $practiceId]);
            echo json_encode(['success' => true, 'practice_id' => (int)$practiceId, 'raw' => $raw]);
            return;
        }

        $overridesInput = $input['overrides'] ?? [];
        if (!is_array($overridesInput)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'overrides must be an object']);
            return;
        }

        $normalized = normalizeWorkflowStageLabelsForSave($overridesInput);
        if (!$normalized['valid']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid overrides', 'errors' => $normalized['errors']]);
            return;
        }

        $json = empty($normalized['overrides']) ? null : json_encode($normalized['overrides']);
        $stmt = $pdo->prepare("UPDATE practices SET workflow_stage_labels = :labels WHERE id = :id");
        $stmt->execute(['labels' => $json, 'id' => $practiceId]);

        echo json_encode([
            'success' => true,
            'practice_id' => (int)$practiceId,
            'overrides' => $normalized['overrides'],
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] set_workflow_stage_labels error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}

/**
 * Development-only: returns the exact "Cases flow through stages: ..."
 * text buildWorkflowStagesPromptText() (api/workflow-stages.php) would
 * embed in the AI assistant's system prompt for this practice, WITHOUT
 * making any real call to an AI provider. Lets tests verify the stale
 * hardcoded workflow wording is gone and the real, resolved, ordered
 * stages are used - a live AI call is impractical to exercise in this
 * test suite (external API, no mock/test-mode hook, non-deterministic).
 */
function handleGetAiWorkflowStagesPromptText($pdo, $input) {
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email is required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found. Run setup_test_user first.']);
        return;
    }

    $stmt = $pdo->prepare("SELECT id FROM practices WHERE created_by = :user_id ORDER BY id DESC LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $practiceId = $stmt->fetchColumn();

    if (!$practiceId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No practice found for this user.']);
        return;
    }

    echo json_encode([
        'success' => true,
        'practice_id' => (int)$practiceId,
        'promptText' => buildWorkflowStagesPromptText((int)$practiceId),
    ]);
}

/**
 * Development-only: reads back the JSON file api/data-export.php's
 * processExport() writes to disk (exports/dentatrak_export_{id}_{token}.json),
 * given only the exportId returned by the normal `?action=request` response.
 * The real download flow requires a token that is only ever delivered by
 * email (by design, for security) - this exists purely so tests can verify
 * export content (statusLabel/oldStatusLabel/newStatusLabel additions)
 * without needing a real mailbox.
 */
function handleGetExportFileById($pdo, $input) {
    $exportId = (int)($input['exportId'] ?? 0);
    if (!$exportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'exportId is required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT file_path FROM data_exports WHERE id = :id");
    $stmt->execute(['id' => $exportId]);
    $filePath = $stmt->fetchColumn();

    if (!$filePath) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export not found']);
        return;
    }

    $fullPath = __DIR__ . '/../exports/' . $filePath;
    if (!file_exists($fullPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export file not found on disk']);
        return;
    }

    $content = json_decode(file_get_contents($fullPath), true);
    echo json_encode(['success' => true, 'export' => $content]);
}

/**
 * Development-only: deletes E2E-test-generated local data, identified
 * ONLY by deterministic naming convention - never by age:
 *   - users whose email matches 'e2e.%@dentatrak.com' (every test helper
 *     in this codebase - setup_test_user, setup_practice_member,
 *     seed_owned_practices, etc. - creates accounts this way)
 *   - users whose email ends in '@example.com' (used by a few specs for
 *     throwaway/never-persisted-login users)
 * The shared 'e2e.test@dentatrak.com' account (used by tests/global-setup.ts
 * and every test that calls helpers/login.ts's login()) is explicitly
 * excluded from deletion - only ITS accumulated cases/activity/assignment
 * labels are reset to a clean baseline, exactly like every other run.
 *
 * cases_cache and its dependent tables (case_activity_log, case_comments,
 * case_assignments, case_lab_assignment_periods, case_label_assignments,
 * data_exports) have NO foreign-key cascade from practices/users in this
 * schema, so they are deleted explicitly and in FK-safe order BEFORE the
 * owning users are deleted. Deleting the users themselves then cascades
 * (ON DELETE CASCADE) to practices, practice_users, practice_assignment_labels,
 * subscriptions, sessions, and the other user-owned tables.
 *
 * Pass `{ "dryRun": true }` to only COUNT what would be deleted (used by
 * tests/global-teardown.ts to log expected counts before actually
 * deleting, and by the representative regression check to confirm no net
 * growth). Wrapped in a single transaction; if any statement fails,
 * nothing is deleted.
 */
function handleCleanupTestData($pdo, $input) {
    $dryRun = !empty($input['dryRun']);
    $sharedEmail = 'e2e.test@dentatrak.com';

    $testPracticeSubquery = "
        SELECT p.id FROM practices p
        JOIN users u ON p.created_by = u.id
        WHERE u.email LIKE 'e2e.%@dentatrak.com' AND u.email <> :shared_email
    ";
    $testUserSubquery = "
        SELECT id FROM users
        WHERE (email LIKE 'e2e.%@dentatrak.com' AND email <> :shared_email)
           OR email LIKE '%@example.com'
    ";
    $testCaseSubquery = "
        SELECT case_id FROM cases_cache
        WHERE practice_id IN ($testPracticeSubquery)
    ";

    try {
        $counts = [];

        $countSql = function ($sql) use ($pdo, $sharedEmail) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['shared_email' => $sharedEmail]);
            return (int)$stmt->fetchColumn();
        };

        $counts['test_practices'] = $countSql("SELECT COUNT(*) FROM ($testPracticeSubquery) t");
        $counts['test_users'] = $countSql("SELECT COUNT(*) FROM ($testUserSubquery) t");
        $counts['test_cases'] = $countSql("SELECT COUNT(*) FROM cases_cache WHERE practice_id IN ($testPracticeSubquery)");
        $counts['test_case_activity_log'] = $countSql("SELECT COUNT(*) FROM case_activity_log WHERE case_id IN ($testCaseSubquery)");
        // No :shared_email placeholder in this one - query directly rather
        // than through $countSql (native prepares reject an unused bound
        // parameter as "Invalid parameter number").
        $counts['orphaned_case_activity_log'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM case_activity_log WHERE case_id NOT IN (SELECT case_id FROM cases_cache)"
        )->fetchColumn();
        $counts['test_case_comments'] = $countSql("SELECT COUNT(*) FROM case_comments WHERE case_id IN ($testCaseSubquery)");
        $counts['test_case_assignments'] = $countSql("SELECT COUNT(*) FROM case_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($testCaseSubquery)");
        $counts['test_case_lab_assignment_periods'] = $countSql("SELECT COUNT(*) FROM case_lab_assignment_periods WHERE practice_id IN ($testPracticeSubquery)");
        $counts['test_case_label_assignments'] = $countSql("SELECT COUNT(*) FROM case_label_assignments WHERE case_id IN ($testCaseSubquery)");
        $counts['test_data_exports'] = $countSql("SELECT COUNT(*) FROM data_exports WHERE practice_id IN ($testPracticeSubquery)");
        $counts['shared_account_cases'] = (int)$pdo->query("SELECT COUNT(*) FROM cases_cache cc JOIN practices p ON cc.practice_id = p.id JOIN users u ON p.created_by = u.id WHERE u.email = " . $pdo->quote($sharedEmail))->fetchColumn();

        if ($dryRun) {
            echo json_encode(['success' => true, 'dryRun' => true, 'counts' => $counts]);
            return;
        }

        $pdo->beginTransaction();

        // Test-owned practices' case-dependent rows (no FK cascade exists).
        $pdo->prepare("DELETE FROM case_comments WHERE case_id IN ($testCaseSubquery)")->execute(['shared_email' => $sharedEmail]);
        $pdo->prepare("DELETE FROM case_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($testCaseSubquery)")->execute(['shared_email' => $sharedEmail]);
        $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE practice_id IN ($testPracticeSubquery)")->execute(['shared_email' => $sharedEmail]);
        $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id IN ($testCaseSubquery)")->execute(['shared_email' => $sharedEmail]);
        $pdo->prepare("DELETE FROM case_activity_log WHERE case_id IN ($testCaseSubquery)")->execute(['shared_email' => $sharedEmail]);
        // Pre-existing orphaned rows from any past ad-hoc cleanup that deleted
        // cases_cache directly without cleaning case_activity_log.
        $pdo->exec("DELETE FROM case_activity_log WHERE case_id NOT IN (SELECT case_id FROM cases_cache)");
        $pdo->prepare("DELETE FROM data_exports WHERE practice_id IN ($testPracticeSubquery)")->execute(['shared_email' => $sharedEmail]);
        $pdo->prepare("DELETE FROM cases_cache WHERE practice_id IN ($testPracticeSubquery)")->execute(['shared_email' => $sharedEmail]);

        // Deleting the test users cascades to their owned practices,
        // practice_users, practice_assignment_labels, subscriptions, etc.
        $pdo->prepare("DELETE FROM users WHERE (email LIKE 'e2e.%@dentatrak.com' AND email <> :shared_email) OR email LIKE '%@example.com'")
            ->execute(['shared_email' => $sharedEmail]);

        // Reset the shared account's OWN accumulated cases/activity/labels to
        // a clean baseline. Membership rows (owner + the "Assigned Only"
        // fixture member) are left untouched.
        $sharedPracticeIds = $pdo->prepare("SELECT p.id FROM practices p JOIN users u ON p.created_by = u.id WHERE u.email = :shared_email");
        $sharedPracticeIds->execute(['shared_email' => $sharedEmail]);
        foreach ($sharedPracticeIds->fetchAll(PDO::FETCH_COLUMN) as $practiceId) {
            $pdo->prepare("DELETE FROM case_comments WHERE case_id IN (SELECT case_id FROM cases_cache WHERE practice_id = :pid)")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM case_assignments WHERE case_id COLLATE utf8mb4_general_ci IN (SELECT case_id FROM cases_cache WHERE practice_id = :pid)")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE practice_id = :pid")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id IN (SELECT case_id FROM cases_cache WHERE practice_id = :pid)")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM case_activity_log WHERE case_id IN (SELECT case_id FROM cases_cache WHERE practice_id = :pid)")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM data_exports WHERE practice_id = :pid")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM cases_cache WHERE practice_id = :pid")->execute(['pid' => $practiceId]);
            $pdo->prepare("DELETE FROM practice_assignment_labels WHERE practice_id = :pid")->execute(['pid' => $practiceId]);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'dryRun' => false, 'deleted' => $counts]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] cleanup_test_data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

/**
 * List test case IDs in the current practice that carry the TEST_CASE_MARKER.
 * Returns case IDs only; does not delete anything. This lets a test run discover
 * and then delete its own previously-created cases without scanning the whole board.
 */
function handleListTestCases($pdo, $input) {
    $currentPracticeId = isset($input['practice_id']) ? (int)$input['practice_id'] : ($_SESSION['current_practice_id'] ?? null);
    if (!$currentPracticeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No current practice in session']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT case_id FROM cases_cache WHERE practice_id = :practice_id");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $caseIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $markedIds = [];
        foreach ($caseIds as $caseId) {
            $case = getCaseFromCache($caseId);
            if (!$case) continue;

            $firstName = $case['patientFirstName'] ?? '';
            $notes = $case['notes'] ?? '';
            if (stripos($firstName, TEST_CASE_MARKER) === 0 || stripos($notes, TEST_CASE_MARKER) === 0) {
                $markedIds[] = $caseId;
            }
        }

        echo json_encode([
            'success' => true,
            'case_ids' => $markedIds,
            'marker' => TEST_CASE_MARKER,
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] list_test_cases error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'List failed: ' . $e->getMessage()]);
    }
}

/**
 * Delete only specific test-marked cases that were created by the current test run.
 *
 * Requirements:
 * - Caller must be in a test environment (already gated by this file's top-level checks).
 * - A current practice must be in the session.
 * - Each requested case must exist, belong to the current practice, and be marked
 *   with the TEST_CASE_MARKER prefix in patient_first_name or notes.
 * - Only the requested case IDs are deleted; unrelated cases, comments,
 *   assignments, activity, etc., are left untouched.
 * - This cannot be activated in production because the endpoint itself is blocked
 *   when the environment is production.
 */
function handleDeleteTestCases($pdo, $input) {
    $currentPracticeId = isset($input['practice_id']) ? (int)$input['practice_id'] : ($_SESSION['current_practice_id'] ?? null);
    if (!$currentPracticeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No current practice in session']);
        return;
    }

    $requestedIds = $input['case_ids'] ?? [];
    if (!is_array($requestedIds) || empty($requestedIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'case_ids array is required']);
        return;
    }

    // De-duplicate while preserving order.
    $uniqueIds = array_values(array_unique($requestedIds));

    try {
        $selectSql = "
            SELECT case_id, practice_id, patient_first_name, notes
            FROM cases_cache
            WHERE case_id = :case_id
            FOR UPDATE
        ";
        $selectStmt = $pdo->prepare($selectSql);

        $results = [];
        $deletable = [];

        $pdo->beginTransaction();

        foreach ($uniqueIds as $caseId) {
            if (!is_string($caseId) || $caseId === '') {
                $results[] = ['case_id' => (string) $caseId, 'deleted' => false, 'reason' => 'invalid_id'];
                continue;
            }

            $selectStmt->execute(['case_id' => $caseId]);
            $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $results[] = ['case_id' => $caseId, 'deleted' => false, 'reason' => 'not_found'];
                continue;
            }

            if ((int) $row['practice_id'] !== (int) $currentPracticeId) {
                $results[] = ['case_id' => $caseId, 'deleted' => false, 'reason' => 'wrong_practice'];
                continue;
            }

            // Decrypt PII before marker check because the cache stores encrypted values.
            $decrypted = [];
            if (class_exists('PIIEncryption')) {
                $decrypted = PIIEncryption::decryptCaseData([
                    'patientFirstName' => $row['patient_first_name'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            $firstName = $decrypted['patientFirstName'] ?? '';
            $notes = $decrypted['notes'] ?? '';
            $isMarked = (stripos($firstName, TEST_CASE_MARKER) === 0 || stripos($notes, TEST_CASE_MARKER) === 0);

            if (!$isMarked) {
                $results[] = ['case_id' => $caseId, 'deleted' => false, 'reason' => 'not_marked'];
                continue;
            }

            $deletable[] = $caseId;
            $results[] = ['case_id' => $caseId, 'deleted' => true, 'reason' => null];
        }

        if (!empty($deletable)) {
            $inClause = implode(',', array_fill(0, count($deletable), '?'));

            $pdo->prepare("DELETE FROM case_comments WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
            $pdo->prepare("DELETE FROM case_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
            $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
            $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
            $pdo->prepare("DELETE FROM case_activity_log WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
            $pdo->prepare("DELETE FROM cases_cache WHERE case_id COLLATE utf8mb4_general_ci IN ($inClause)")->execute($deletable);
        }

        $pdo->commit();

        $deletedCount = count(array_filter($results, function ($r) { return $r['deleted']; }));
        $failedCount = count($results) - $deletedCount;

        echo json_encode([
            'success' => true,
            'message' => 'Deleted ' . $deletedCount . ' test case(s); ' . $failedCount . ' rejected.',
            'marker' => TEST_CASE_MARKER,
            'results' => $results,
            'summary' => ['deleted' => $deletedCount, 'failed' => $failedCount]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[test-helpers] delete_test_cases error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}

/**
 * Return the most recent email recorded by the test-mode email sender.
 */
function handleGetLastAppEmail(array $appConfig, array $input) {
    $recordPath = __DIR__ . '/../testResults/last-email.json';

    if (!file_exists($recordPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No recorded email found']);
        return;
    }

    $content = file_get_contents($recordPath);
    $email = json_decode($content, true);

    if (!$email) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Recorded email file is not valid JSON']);
        return;
    }

    echo json_encode([
        'success' => true,
        'email' => $email,
    ]);
}

/**
 * Return all emails recorded by the test-mode email sender.
 */
function handleGetAllAppEmails(array $appConfig, array $input) {
    $allPath = __DIR__ . '/../testResults/all-emails.json';

    if (!file_exists($allPath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No recorded emails found']);
        return;
    }

    $content = file_get_contents($allPath);
    $emails = json_decode($content, true);

    if (!is_array($emails)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Recorded email log is not valid JSON']);
        return;
    }

    echo json_encode([
        'success' => true,
        'emails' => $emails,
    ]);
}

/**
 * Clear the recorded test email log.
 */
function handleClearTestEmailLog(array $appConfig, array $input) {
    $recordPath = __DIR__ . '/../testResults/last-email.json';
    $allPath = __DIR__ . '/../testResults/all-emails.json';

    if (file_exists($recordPath)) {
        @unlink($recordPath);
    }
    if (file_exists($allPath)) {
        @unlink($allPath);
    }

    echo json_encode(['success' => true, 'message' => 'Test email logs cleared']);
}

/**
 * Force the test-mode email sender to return a failure without recording.
 */
function handleForceEmailFailure(array $appConfig, array $input) {
    $recordPath = __DIR__ . '/../testResults/force-email-failure.json';
    $recordDir = dirname($recordPath);
    if (!is_dir($recordDir)) {
        @mkdir($recordDir, 0750, true);
    }
    file_put_contents($recordPath, json_encode(['enabled' => true, 'timestamp' => date('c')]));
    echo json_encode(['success' => true, 'message' => 'Forced email failure enabled for test mode']);
}

/**
 * Clear the forced email failure flag.
 */
function handleClearEmailFailure(array $appConfig, array $input) {
    $recordPath = __DIR__ . '/../testResults/force-email-failure.json';
    if (file_exists($recordPath)) {
        @unlink($recordPath);
    }
    echo json_encode(['success' => true, 'message' => 'Forced email failure cleared']);
}

/**
 * Look up a practice membership by email and practice_id.
 */
function handleGetPracticeUser(PDO $pdo, array $input) {
    $email = strtolower(trim($input['email'] ?? ''));
    $practiceId = isset($input['practice_id']) ? (int)$input['practice_id'] : 0;

    if (!$email || !$practiceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and practice_id are required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT pu.*, u.email AS user_email
        FROM practice_users pu
        JOIN users u ON u.id = pu.user_id
        WHERE u.email = :email AND pu.practice_id = :practice_id
        LIMIT 1
    ");
    $stmt->execute(['email' => $email, 'practice_id' => $practiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Practice user not found']);
        return;
    }

    echo json_encode(['success' => true, 'practice_user' => $row]);
}

/**
 * Delete specific test-member users by email and their practice membership rows.
 * Only available in development/test. Cascades (ON DELETE CASCADE) remove
 * practices the deleted user owns, but the helper explicitly removes
 * practice_users and practice_assignment_label_recipients rows first.
 *
 * Input: { marker: 'DentaTrakTest', emails: [ ... ] }
 *
 * SAFETY: The caller must supply the shared TEST_CASE_MARKER. Every target
 * user must have first_name starting with that marker. Requests that omit the
 * marker, target unmarked users, or exceed the per-call limit are rejected
 * before any row is deleted.
 */
function handleDeleteTestUsers($pdo, $input) {
    $marker = $input['marker'] ?? '';
    if ($marker !== TEST_CASE_MARKER) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing test marker']);
        return;
    }

    $emails = $input['emails'] ?? [];
    if (!is_array($emails) || empty($emails)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'emails array is required']);
        return;
    }

    $maxBatch = 50;
    if (count($emails) > $maxBatch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "At most {$maxBatch} emails may be supplied"]);
        return;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));

        // Find user IDs for these emails and verify they are marked test users.
        $stmt = $pdo->prepare("
            SELECT id, email, first_name
            FROM users
            WHERE email IN ($placeholders)
        ");
        $stmt->execute($emails);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $foundEmails = array_map(function($u) { return strtolower($u['email']); }, $users);
        $missing = array_diff(array_map('strtolower', $emails), $foundEmails);
        if (!empty($missing)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'One or more target users do not exist', 'missing' => array_values($missing)]);
            return;
        }

        foreach ($users as $u) {
            $fn = $u['first_name'] ?? '';
            if (strpos($fn, TEST_CASE_MARKER) !== 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Refusing to delete unmarked user: ' . $u['email']]);
                return;
            }
        }

        $userIds = array_map(function($u) { return (int)$u['id']; }, $users);

        if (!empty($userIds)) {
            $idPlaceholders = implode(',', array_fill(0, count($userIds), '?'));

            // Remove membership rows that would otherwise block user deletion.
            $pdo->prepare("DELETE FROM practice_users WHERE user_id IN ($idPlaceholders)")->execute($userIds);
            $pdo->prepare("DELETE FROM practice_assignment_label_recipients WHERE user_id IN ($idPlaceholders)")->execute($userIds);

            // Delete the users. Owned practices and dependent tables cascade.
            $pdo->prepare("DELETE FROM users WHERE id IN ($idPlaceholders)")->execute($userIds);
        }

        echo json_encode([
            'success' => true,
            'deleted' => count($userIds),
            'emails' => array_map(function($u) { return $u['email']; }, $users)
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] delete_test_users error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Delete test users failed']);
    }
}

/**
 * Set a practice member's can_view_analytics and can_edit_cases flags.
 * Used by Playwright tests to exercise the Insights permission matrix.
 */
function handleSetPracticeMemberPermissions($pdo, $input) {
    $sessionPracticeId = requireValidPracticeContext();
    $practiceId = isset($input['practice_id']) ? (int)$input['practice_id'] : 0;
    if ($practiceId !== $sessionPracticeId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid practice context']);
        return;
    }

    if (!isPracticeAdmin($practiceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin privileges required']);
        return;
    }

    $email = strtolower(trim($input['email'] ?? ''));
    $canViewAnalytics = !empty($input['canViewAnalytics']) ? 1 : 0;
    $canEditCases = !empty($input['canEditCases']) ? 1 : 0;

    if (empty($email) || !$practiceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email and practice_id are required']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE practice_users
            SET can_view_analytics = :can_view_analytics,
                can_edit_cases = :can_edit_cases
            WHERE practice_id = :practice_id
              AND user_id = (SELECT id FROM users WHERE email = :email LIMIT 1)
        ");
        $stmt->execute([
            'can_view_analytics' => $canViewAnalytics,
            'can_edit_cases' => $canEditCases,
            'practice_id' => $practiceId,
            'email' => $email,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Permissions updated',
            'affected' => $stmt->rowCount(),
        ]);
    } catch (PDOException $e) {
        error_log('[test-helpers] set_practice_member_permissions error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Permission update failed']);
    }
}

/**
 * Seed scoped Lab Insights fixture data. Available only in development/test.
 *
 * Creates test lab-designated assignment labels, cases, and
 * case_lab_assignment_periods so the mobile Insights test can verify the
 * populated Lab table, sorting, drill-down, trend chart, and permissions.
 * All records are tagged with DentaTrakTest* markers and are removed by
 * cleanup_lab_insights_data.
 *
 * Input: { } (uses session practice)
 * Output: { success, practice_id, labs: {key: name}, cases: [...], labelIds: [...] }
 */
function handleSeedLabInsightsData($pdo, $input) {
    // requireValidPracticeContext() enforces authenticated membership.
    $practiceId = requireValidPracticeContext();
    if (!$practiceId) {
        return;
    }

    ensureLabAssignmentHistoryTable();
    ensureLabDesignationColumns();

    $caseMarker = TEST_CASE_MARKER;
    $labMarker = $caseMarker . 'Lab';
    $now = new DateTimeImmutable('now');

    $resolved = getResolvedWorkflowStageLabelsForPractice($practiceId);
    $statusIds = array_keys($resolved);
    $firstStatus = $statusIds[0] ?? 'Originated';
    $midStatus = $statusIds[1] ?? $firstStatus;
    $terminalStatus = getLastActiveWorkflowColumnId($practiceId);

    $labNameByKey = [
        'Alpha' => $labMarker . '-Alpha',
        'BetaLong' => $labMarker . '-Very Long External Dental Laboratory Name For Wrapping Test',
        'Gamma' => $labMarker . '-GammaNoCompleted',
    ];

    // Create lab labels.
    $labIds = [];
    $sortOrder = 9000;
    $insertLabelStmt = $pdo->prepare("
        INSERT INTO practice_assignment_labels (practice_id, label, sort_order, is_lab)
        VALUES (:practice_id, :label, :sort_order, 1)
    ");
    foreach ($labNameByKey as $key => $label) {
        $insertLabelStmt->execute([
            'practice_id' => $practiceId,
            'label' => $label,
            'sort_order' => $sortOrder++,
        ]);
        $labIds[$key] = (int)$pdo->lastInsertId();
    }

    // Helper to insert a case cache row and initial activity log.
    $caseIdList = [];
    $caseIndex = 0;
    function insertLabCase($pdo, $practiceId, $patientFirst, $patientLast, $assignedTo, $status, $dueDate, $caseType) {
        $caseId = 'dt-lab-' . bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        $caseData = [
            'id' => $caseId,
            'patientFirstName' => $patientFirst,
            'patientLastName' => $patientLast,
            'patientDOB' => '1980-01-01',
            'patientGender' => 'Male',
            'dentistName' => 'Dr. Test',
            'caseType' => $caseType,
            'toothShade' => 'A1',
            'material' => 'Zirconia',
            'dueDate' => $dueDate,
            'patientAppointmentDate' => '',
            'creationDate' => $now,
            'lastUpdateDate' => $now,
            'status' => $status,
            'statusChangedAt' => $now,
            'notes' => TEST_CASE_MARKER . ' lab insights seed',
            'assignedTo' => $assignedTo,
            'attachments' => [],
            'revisions' => [],
            'clinicalDetails' => [],
            'carrier' => '',
            'trackingNumber' => '',
            'customCarrier' => '',
            'practice_id' => $practiceId,
            'createdByUserId' => $_SESSION['db_user_id'] ?? null,
        ];
        $encrypted = PIIEncryption::encryptCaseData($caseData);
        saveCaseToCache($encrypted);
        logCaseActivity($caseId, 'case_created', null, $status, ['seed' => true]);
        return $caseId;
    }

    // Helper to insert a lab assignment period.
    function insertLabPeriod($pdo, $practiceId, $caseId, $labelId, $label, $startedAt, $endedAt = null, $endReason = null) {
        $pdo->prepare("
            INSERT INTO case_lab_assignment_periods (
                case_id, practice_id, assignee_type, user_id, label_id,
                label_text_normalized, assignee_display_name_snapshot,
                is_lab_snapshot, started_at, ended_at, end_reason, history_quality
            ) VALUES (
                :case_id, :practice_id, 'label', NULL, :label_id,
                :label_text, :display_name, 1, :started_at, :ended_at, :end_reason, 'observed'
            )
        ")->execute([
            'case_id' => $caseId,
            'practice_id' => $practiceId,
            'label_id' => $labelId,
            'label_text' => mb_strtolower(trim($label)),
            'display_name' => $label,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'end_reason' => $endReason,
        ]);
    }

    // Alpha lab: mixed active, completed, late, transfer, revision.
    $alpha = $labNameByKey['Alpha'];
    $alphaId = $labIds['Alpha'];

    $a1 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A1', 'Smith', $alpha, $midStatus, $now->format('Y-m-d'), 'Crown');
    insertLabPeriod($pdo, $practiceId, $a1, $alphaId, $alpha, $now->modify('-20 days')->format('Y-m-d H:i:s'));
    $caseIdList[] = $a1;

    $a2 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A2', 'Jones', $alpha, $midStatus, $now->modify('-10 days')->format('Y-m-d'), 'Bridge');
    insertLabPeriod($pdo, $practiceId, $a2, $alphaId, $alpha, $now->modify('-18 days')->format('Y-m-d H:i:s'));
    $caseIdList[] = $a2;

    $a3 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A3', 'Doe', $alpha, $terminalStatus, $now->modify('-56 days')->format('Y-m-d'), 'Veneer');
    insertLabPeriod($pdo, $practiceId, $a3, $alphaId, $alpha,
        $now->modify('-60 days')->format('Y-m-d H:i:s'),
        $now->modify('-55 days')->format('Y-m-d H:i:s'),
        'delivered');
    $caseIdList[] = $a3;

    $a4 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A4', 'Wilson', $alpha, $terminalStatus, $now->modify('-65 days')->format('Y-m-d'), 'Crown');
    insertLabPeriod($pdo, $practiceId, $a4, $alphaId, $alpha,
        $now->modify('-80 days')->format('Y-m-d H:i:s'),
        $now->modify('-70 days')->format('Y-m-d H:i:s'),
        'delivered');
    $caseIdList[] = $a4;

    // Alpha transfer: first Alpha, then Long (BetaLong).
    $long = $labNameByKey['BetaLong'];
    $longId = $labIds['BetaLong'];
    $a5 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A5', 'Brown', $long, $midStatus, $now->modify('+14 days')->format('Y-m-d'), 'Bridge');
    insertLabPeriod($pdo, $practiceId, $a5, $alphaId, $alpha,
        $now->modify('-40 days')->format('Y-m-d H:i:s'),
        $now->modify('-35 days')->format('Y-m-d H:i:s'),
        'reassigned_to_lab');
    insertLabPeriod($pdo, $practiceId, $a5, $longId, $long, $now->modify('-35 days')->format('Y-m-d H:i:s'));
    $caseIdList[] = $a5;

    // Alpha revision: completed with a regression logged during the period.
    $a6 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-A6', 'Taylor', $alpha, $terminalStatus, $now->modify('-75 days')->format('Y-m-d'), 'Veneer');
    insertLabPeriod($pdo, $practiceId, $a6, $alphaId, $alpha,
        $now->modify('-90 days')->format('Y-m-d H:i:s'),
        $now->modify('-80 days')->format('Y-m-d H:i:s'),
        'delivered');
    logCaseActivity($a6, 'case_regression', null, null, ['seed' => true], $now->modify('-85 days')->format('Y-m-d H:i:s'));
    $caseIdList[] = $a6;

    // BetaLong lab: one active, one completed, to exercise long-name wrapping.
    $b1 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-B1', 'Anderson', $long, $midStatus, $now->modify('+21 days')->format('Y-m-d'), 'Crown');
    insertLabPeriod($pdo, $practiceId, $b1, $longId, $long, $now->modify('-12 days')->format('Y-m-d H:i:s'));
    $caseIdList[] = $b1;

    $b2 = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-B2', 'Thomas', $long, $terminalStatus, $now->modify('-25 days')->format('Y-m-d'), 'Bridge');
    insertLabPeriod($pdo, $practiceId, $b2, $longId, $long,
        $now->modify('-30 days')->format('Y-m-d H:i:s'),
        $now->modify('-20 days')->format('Y-m-d H:i:s'),
        'delivered');
    $caseIdList[] = $b2;

    // Gamma lab: only active cases (no completed periods).
    $gamma = $labNameByKey['Gamma'];
    $gammaId = $labIds['Gamma'];
    for ($i = 1; $i <= 3; $i++) {
        $due = $i === 2 ? $now->modify('-5 days')->format('Y-m-d') : $now->modify('+10 days')->format('Y-m-d');
        $g = insertLabCase($pdo, $practiceId, TEST_CASE_MARKER . '-G' . $i, 'Gamma' . $i, $gamma, $midStatus, $due, $i === 3 ? 'Veneer' : 'Crown');
        insertLabPeriod($pdo, $practiceId, $g, $gammaId, $gamma, $now->modify('-' . ($i * 5) . ' days')->format('Y-m-d H:i:s'));
        $caseIdList[] = $g;
    }

    echo json_encode([
        'success' => true,
        'practice_id' => $practiceId,
        'labs' => $labNameByKey,
        'labKeys' => array_map(function ($k) use ($labIds) { return 'label:' . $labIds[$k]; }, array_keys($labIds)),
        'case_ids' => $caseIdList,
        'label_ids' => array_values($labIds),
    ]);
}

/**
 * Remove all records created by handleSeedLabInsightsData in the current practice.
 * Scoped to the authenticated session practice and DentaTrakTest* markers.
 */
function handleCleanupLabInsightsData($pdo, $input) {
    $sessionPracticeId = requireValidPracticeContext();
    $inputPracticeId = isset($input['practice_id']) ? (int)$input['practice_id'] : 0;
    $practiceId = $inputPracticeId ?: $sessionPracticeId;
    if (!$practiceId || $practiceId !== $sessionPracticeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid current practice in session']);
        return;
    }

    $labMarker = TEST_CASE_MARKER . 'Lab';
    $deleted = [
        'cases' => 0,
        'lab_periods' => 0,
        'activity_log' => 0,
        'labels' => 0,
    ];

    try {
        // Find all test lab labels in this practice.
        $labelStmt = $pdo->prepare("
            SELECT id FROM practice_assignment_labels
            WHERE practice_id = :practice_id AND (label LIKE :marker OR label LIKE :marker_dash)
        ");
        $labelStmt->execute([
            'practice_id' => $practiceId,
            'marker' => $labMarker . '%',
            'marker_dash' => $labMarker . '-%',
        ]);
        $labelIds = $labelStmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete label recipients for these labels.
        if (!empty($labelIds)) {
            $inLabels = implode(',', array_fill(0, count($labelIds), '?'));
            $pdo->prepare("DELETE FROM practice_assignment_label_recipients WHERE label_id IN ($inLabels)")
                ->execute($labelIds);
        }

        // Find test cases in this practice by decrypting patient first name and notes.
        $caseStmt = $pdo->prepare("SELECT * FROM cases_cache WHERE practice_id = :practice_id");
        $caseStmt->execute(['practice_id' => $practiceId]);
        $caseIds = [];
        foreach ($caseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decrypted = class_exists('PIIEncryption')
                ? PIIEncryption::decryptCaseData([
                    'patientFirstName' => $row['patient_first_name'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ])
                : ['patientFirstName' => $row['patient_first_name'], 'notes' => $row['notes']];
            $firstName = $decrypted['patientFirstName'] ?? '';
            $notes = $decrypted['notes'] ?? '';
            if (stripos($firstName, TEST_CASE_MARKER) === 0 || stripos($notes, TEST_CASE_MARKER) === 0) {
                $caseIds[] = $row['case_id'];
            }
        }

        if (!empty($caseIds)) {
            $inCases = implode(',', array_fill(0, count($caseIds), '?'));
            $pdo->prepare("DELETE FROM case_comments WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $pdo->prepare("DELETE FROM case_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $pdo->prepare("DELETE FROM case_activity_log WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $pdo->prepare("DELETE FROM cases_cache WHERE case_id COLLATE utf8mb4_general_ci IN ($inCases)")->execute($caseIds);
            $deleted['cases'] = count($caseIds);
            $deleted['activity_log'] = count($caseIds);
        }

        // Delete any periods for the test labels that were not caught by case_id.
        if (!empty($labelIds)) {
            $inLabels = implode(',', array_fill(0, count($labelIds), '?'));
            $periodStmt = $pdo->prepare("DELETE FROM case_lab_assignment_periods WHERE label_id IN ($inLabels) AND practice_id = ?");
            $periodStmt->execute(array_merge($labelIds, [$practiceId]));
            $deleted['lab_periods'] = $periodStmt->rowCount();

            $labelDelStmt = $pdo->prepare("DELETE FROM practice_assignment_labels WHERE id IN ($inLabels) AND practice_id = ?");
            $labelDelStmt->execute(array_merge($labelIds, [$practiceId]));
            $deleted['labels'] = $labelDelStmt->rowCount();
        }

        echo json_encode(['success' => true, 'deleted' => $deleted]);
    } catch (PDOException $e) {
        error_log('[test-helpers] cleanup_lab_insights_data error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed: ' . $e->getMessage()]);
    }
}
