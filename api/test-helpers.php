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

header('Content-Type: application/json');

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
    case 'seed_owned_practices':
        handleSeedOwnedPractices($pdo, $input);
        break;
    case 'describe_plan_config':
        handleDescribePlanConfig($appConfig, $input);
        break;
    case 'get_subscription_state':
        handleGetSubscriptionState($pdo, $input);
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
    $firstName = trim($input['firstName'] ?? 'E2E');
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
            $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, created_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $userId]);
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
    $firstName = trim($input['firstName'] ?? 'E2E');
    $lastName = trim($input['lastName'] ?? 'Member');
    $adminEmail = strtolower(trim($input['adminEmail'] ?? ''));
    $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $limitedVisibility = !empty($input['limitedVisibility']) ? 1 : 0;

    if (empty($email) || empty($password) || empty($adminEmail)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'email, password, and adminEmail are required']);
        return;
    }

    try {
        // Resolve the practice from the existing admin's membership
        $stmt = $pdo->prepare("
            SELECT p.id
            FROM practices p
            JOIN practice_users pu ON p.id = pu.practice_id
            JOIN users u ON u.id = pu.user_id
            WHERE u.email = :admin_email
            LIMIT 1
        ");
        $stmt->execute(['admin_email' => $adminEmail]);
        $practiceId = $stmt->fetchColumn();

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
            $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = :id");
            $stmt->execute(['id' => $userId]);
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
                SET role = :role, limited_visibility = :limited_visibility
                WHERE practice_id = :practice_id AND user_id = :user_id
            ");
            $stmt->execute([
                'role' => $role,
                'limited_visibility' => $limitedVisibility,
                'practice_id' => $practiceId,
                'user_id' => $userId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility)
                VALUES (:practice_id, :user_id, :role, FALSE, :limited_visibility)
            ");
            $stmt->execute([
                'practice_id' => $practiceId,
                'user_id' => $userId,
                'role' => $role,
                'limited_visibility' => $limitedVisibility
            ]);
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
    $email      = strtolower(trim($input['email'] ?? ''));
    $plan       = trim($input['plan'] ?? '');
    $status     = trim($input['status'] ?? 'active');
    $customerId = trim($input['stripe_customer_id'] ?? '');

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
                stripe_subscription_id = COALESCE(stripe_subscription_id, :fake_sub_id),
                subscription_updated_at = UTC_TIMESTAMP()
            WHERE owner_user_id = :owner_user_id
        ");
        $stmt->execute([
            'plan'          => $plan,
            'status'        => $status,
            'fake_sub_id'   => 'sub_test_' . $userId,
            'owner_user_id' => $userId,
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
        $plans[$plan] = [
            'max_practices'   => getMaxOwnedPractices($plan),
            'display_name'    => getPlanDisplayName($plan),
            'upgrade_target'  => PLAN_UPGRADE_TARGET[$plan] ?? null,
            'meets_control'   => planMeetsTier($plan, 'control'),
            'price_ids'       => [
                'month' => getStripePriceId($plan, 'month', $appConfig),
                'year'  => getStripePriceId($plan, 'year',  $appConfig),
            ],
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

    try {
        // Get user ID
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => true, 'message' => 'User not found (already clean)']);
            return;
        }

        $userId = $user['id'];

        // Delete user's cases (cascade should handle related data)
        $stmt = $pdo->prepare("
            DELETE c FROM cases c
            JOIN practices p ON c.practice_id = p.id
            JOIN practice_users pu ON p.id = pu.practice_id
            WHERE pu.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        // Note: We don't delete the user or practice - just clean up test data
        // This allows the test user to persist between test runs

        echo json_encode(['success' => true, 'message' => 'Test data cleaned up']);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Cleanup failed']);
    }
}
