<?php
/**
 * Accept BAA (Business Associate Agreement) API Endpoint
 *
 * This endpoint handles the acceptance of the BAA for a practice.
 * It records all required fields and marks the practice as having accepted the BAA.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-trial.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/subscription-owner.php';
require_once __DIR__ . '/plan-entitlements.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/scale-subscription-addons.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/welcome-email.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
requireCsrfToken();

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$requiredFields = ['legalName', 'practiceAddress', 'signerName', 'signerTitle', 'authorizedToBind'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: ' . implode(', ', $missingFields)
    ]);
    exit;
}

// Validate authorization checkbox
if ($data['authorizedToBind'] !== true) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'You must confirm you are authorized to bind this practice'
    ]);
    exit;
}

// Sanitize inputs
$legalName = trim($data['legalName']);
$practiceAddress = trim($data['practiceAddress']);
$signerName = trim($data['signerName']);
$signerTitle = trim($data['signerTitle']);

// Validate lengths
if (strlen($legalName) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Legal name must be 255 characters or less']);
    exit;
}

if (strlen($signerName) > 255 || strlen($signerTitle) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Signer name and title must be 255 characters or less']);
    exit;
}

// Current BAA version
$baaVersion = 'v1.0-2026-08-07';

try {
    // Ensure BAA columns exist (run migration if needed)
    $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'baa_accepted'");
    if ($stmt->rowCount() === 0) {
        // Need to run migration - add columns
        $columnsToAdd = [
            'legal_name' => "VARCHAR(255) DEFAULT NULL",
            'display_name' => "VARCHAR(255) DEFAULT NULL",
            'practice_address' => "TEXT DEFAULT NULL",
            'baa_accepted' => "TINYINT(1) NOT NULL DEFAULT 0",
            'baa_accepted_at' => "TIMESTAMP NULL DEFAULT NULL",
            'baa_version' => "VARCHAR(50) DEFAULT NULL",
            'baa_accepted_by_user_id' => "INT UNSIGNED DEFAULT NULL",
            'baa_signer_name' => "VARCHAR(255) DEFAULT NULL",
            'baa_signer_title' => "VARCHAR(255) DEFAULT NULL"
        ];

        foreach ($columnsToAdd as $col => $def) {
            try {
                $pdo->exec("ALTER TABLE practices ADD COLUMN `{$col}` {$def}");
            } catch (PDOException $e) {
                // Column might already exist
            }
        }
    }

    // Check if user has a practice or needs to create one. $creatingNew is
    // true either when the client explicitly signaled it (the page was
    // loaded via ?new=1 - see baa-acceptance.php's IS_CREATING_NEW_PRACTICE),
    // or when the user genuinely has no current practice at all. Relying on
    // an explicit flag - rather than solely on $_SESSION['current_practice_id']
    // being absent - means an ADDITIONAL practice can be created for a user
    // who already has one, without requiring the session to be cleared just
    // to load the form (which previously orphaned the session if the user
    // abandoned or failed to submit the form).
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    $creatingNew = !empty($data['new']) || !$practiceId;

    if ($creatingNew) {
        // A subscription belongs to the OWNER (this user), not to any one
        // practice. Every practice this user owns (practice_users.is_owner=1)
        // shares that single subscription/trial/plan - see
        // api/subscription-owner.php and api/plan-entitlements.php. The
        // limit check and the practice INSERT happen in one transaction,
        // with the owner's subscription row locked throughout, so two
        // concurrent "create practice" requests from the same owner can
        // never both succeed and exceed the plan's practice limit.
        $ownerEmailStmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
        $ownerEmailStmt->execute(['id' => $userId]);
        $ownerEmail = $ownerEmailStmt->fetchColumn() ?: '';

        $pdo->beginTransaction();

        try {
            // Locks (and creates, on first-ever call for this owner) the
            // owner's subscription row for the duration of this transaction.
            getOrCreateSubscriptionForOwner($pdo, $userId);

            $entitlement = evaluatePracticeCreationEntitlement($pdo, $userId, $ownerEmail);

            if (!$entitlement['allowed']) {
                $pdo->rollBack();
                http_response_code(403);
                $limitText = $entitlement['max_practices'] === null ? 'unlimited' : $entitlement['max_practices'];
                echo json_encode([
                    'success'        => false,
                    'error_code'     => 'PRACTICE_LIMIT_REACHED',
                    'message'        => "Your {$entitlement['plan_name']} plan allows {$limitText} owned practice(s). Upgrade to add another.",
                    'plan'           => $entitlement['plan'],
                    'plan_name'      => $entitlement['plan_name'],
                    'max_practices'  => $entitlement['max_practices'],
                    'current_count'  => $entitlement['current_count'],
                    'upgrade_target' => $entitlement['upgrade_target'],
                ]);
                exit;
            }

            $practiceUuid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            // NOTE: practices.subscription_status / trial_ends_at are legacy,
            // deprecated columns (see migrate-subscription-owner.php) and are
            // deliberately left NULL for new practices. The authoritative
            // trial/subscription state now lives on the owner's single
            // `subscriptions` row (created/locked above), shared by every
            // practice this owner creates.
            $stmt = $pdo->prepare("
                INSERT INTO practices (
                    practice_id, practice_name, legal_name, display_name, practice_address,
                    baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
                    baa_signer_name, baa_signer_title, created_by
                ) VALUES (
                    :practice_uuid, :practice_name, :legal_name, :display_name, :practice_address,
                    1, UTC_TIMESTAMP(), :baa_version, :user_id,
                    :signer_name, :signer_title, :created_by
                )
            ");

            $stmt->execute([
                'practice_uuid' => $practiceUuid,
                'practice_name' => $legalName, // Legacy field - keep in sync
                'legal_name' => $legalName,
                'display_name' => $legalName, // Default display name to legal name
                'practice_address' => $practiceAddress,
                'baa_version' => $baaVersion,
                'user_id' => $userId,
                'signer_name' => $signerName,
                'signer_title' => $signerTitle,
                'created_by' => $userId,
            ]);

            $practiceId = $pdo->lastInsertId();

            // Add user to practice_users as admin/owner
            $stmt = $pdo->prepare("
                INSERT INTO practice_users (practice_id, user_id, role, is_owner)
                VALUES (:practice_id, :user_id, 'admin', TRUE)
            ");
            $stmt->execute([
                'practice_id' => $practiceId,
                'user_id' => $userId
            ]);

            // For Scale, the base price includes 5 practices. Any additional
            // owned practices must be reflected on the active Stripe
            // subscription before the local DB commit. This is the "Stripe
            // before DB" two-phase commit: if Stripe fails, we roll back the
            // DB and tell the user; if the DB commit then fails, we attempt
            // to restore the previous add-on quantity on Stripe.
            $previousAddOnQty = null;
            $newOwnedCount = $entitlement['current_count'] + 1;
            if ($entitlement['plan'] === 'scale' && $newOwnedCount > 5) {
                try {
                    $previousAddOnQty = syncScaleAddOnQuantity($pdo, $userId, $newOwnedCount, $appConfig);
                } catch (Exception $stripeEx) {
                    error_log('[accept-baa] Stripe Scale add-on sync failed: ' . $stripeEx->getMessage());
                    $pdo->rollBack();
                    http_response_code(502);
                    echo json_encode([
                        'success'   => false,
                        'message'   => 'Billing update failed. Please try again or contact support.',
                        'error_code' => 'BILLING_UPDATE_FAILED',
                    ]);
                    exit;
                }
            }

            try {
                $pdo->commit();
            } catch (PDOException $commitEx) {
                if ($previousAddOnQty !== null) {
                    try {
                        restoreScaleAddOnQuantity($pdo, $userId, $previousAddOnQty, $appConfig);
                    } catch (Exception $restoreEx) {
                        error_log('[accept-baa] Failed to restore Scale add-on after commit failure: ' . $restoreEx->getMessage());
                    }
                }
                throw $commitEx;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Update session
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['practice_name'] = $legalName;
        $_SESSION['needs_practice_setup'] = false;
        $_SESSION['needs_baa_acceptance'] = false;

        userLog("Created new practice with BAA acceptance: {$legalName} (ID: {$practiceId})", false);

        // Send welcome email only for the very first owned practice
        if ($entitlement['current_count'] === 0) {
            sendWelcomeEmail($pdo, (int)$userId, $legalName, $appConfig);
        }

    } else {
        // Practice exists - check if BAA already accepted
        $stmt = $pdo->prepare("SELECT baa_accepted, legal_name FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $practice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($practice && $practice['baa_accepted']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'BAA has already been accepted for this practice. Legal name cannot be changed.'
            ]);
            exit;
        }

        // Update existing practice with BAA acceptance
        $stmt = $pdo->prepare("
            UPDATE practices SET
                legal_name = :legal_name,
                display_name = COALESCE(display_name, :display_name),
                practice_name = :practice_name,
                practice_address = :practice_address,
                baa_accepted = 1,
                baa_accepted_at = NOW(),
                baa_version = :baa_version,
                baa_accepted_by_user_id = :user_id,
                baa_signer_name = :signer_name,
                baa_signer_title = :signer_title
            WHERE id = :practice_id
        ");

        $stmt->execute([
            'legal_name' => $legalName,
            'display_name' => $legalName,
            'practice_name' => $legalName,
            'practice_address' => $practiceAddress,
            'baa_version' => $baaVersion,
            'user_id' => $userId,
            'signer_name' => $signerName,
            'signer_title' => $signerTitle,
            'practice_id' => $practiceId
        ]);

        // Update session
        $_SESSION['practice_name'] = $legalName;
        $_SESSION['needs_baa_acceptance'] = false;

        userLog("BAA accepted for existing practice: {$legalName} (ID: {$practiceId})", false);
    }

    // Log the BAA acceptance in user activity
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_log (user_id, activity_type, description, ip_address)
            VALUES (:user_id, 'baa_accepted', :description, :ip_address)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'description' => json_encode([
                'practice_id' => $practiceId,
                'legal_name' => $legalName,
                'baa_version' => $baaVersion,
                'signer_name' => $signerName,
                'signer_title' => $signerTitle
            ]),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (PDOException $e) {
        // Activity log failure shouldn't block BAA acceptance
        userLog("Failed to log BAA acceptance activity: " . $e->getMessage(), true);
    }

    echo json_encode([
        'success' => true,
        'message' => 'BAA accepted successfully',
        'practice_id' => $practiceId,
        'legal_name' => $legalName,
        'baa_version' => $baaVersion,
        'baa_accepted_at' => date('c')
    ]);

} catch (PDOException $e) {
    userLog("Error accepting BAA: " . $e->getMessage(), true);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
