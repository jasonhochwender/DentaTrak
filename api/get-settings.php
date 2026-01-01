<?php
/**
 * Get User Settings API Endpoint
 * Returns the user's preferences from the database
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/practice-security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$userId = $_SESSION['db_user_id'];

// SECURITY: Require valid practice context for settings that include practice data
$currentPracticeId = requireValidPracticeContext();

try {
    // Ensure tour_completed column exists (MySQL compatible)
    $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'tour_completed'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN tour_completed TINYINT(1) DEFAULT 0");
    }
    
    // Ensure google_drive_backup column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'google_drive_backup'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN google_drive_backup TINYINT(1) DEFAULT 0");
    }
    
    // Get user preferences
    $stmt = $pdo->prepare("
        SELECT * FROM user_preferences 
        WHERE user_id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get current practice information
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    $practiceCreatorId = null;
    $practiceCreatorEmail = null;
    $practiceCreatorAuthMethod = null;
    $practiceName = '';
    $logoPath = '';
    $isPracticeAdmin = false;
    
    // BAA-related fields
    $legalName = '';
    $displayName = '';
    $practiceAddress = '';
    $baaAccepted = false;
    $baaAcceptedAt = null;
    $baaVersion = '';
    $baaSignerName = '';
    $baaSignerTitle = '';
    
    if ($currentPracticeId) {
        // Check if BAA columns exist to avoid errors on databases that haven't been migrated
        $hasBaaColumns = false;
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'legal_name'");
            $hasBaaColumns = $checkStmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasBaaColumns = false;
        }
        
        if ($hasBaaColumns) {
            $stmt = $pdo->prepare("
                SELECT created_by, practice_name, logo_path,
                       legal_name, display_name, practice_address,
                       baa_accepted, baa_accepted_at, baa_version,
                       baa_signer_name, baa_signer_title
                FROM practices WHERE id = :practice_id
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT created_by, practice_name, logo_path
                FROM practices WHERE id = :practice_id
            ");
        }
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $practiceInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($practiceInfo) {
            $practiceCreatorId = $practiceInfo['created_by'];
            $practiceName = $practiceInfo['practice_name'] ?? '';
            $logoPath = $practiceInfo['logo_path'] ?? '';
            
            // BAA fields (only if columns exist)
            if ($hasBaaColumns) {
                $legalName = $practiceInfo['legal_name'] ?? '';
                $displayName = $practiceInfo['display_name'] ?? $practiceName;
                $practiceAddress = $practiceInfo['practice_address'] ?? '';
                $baaAccepted = (bool)($practiceInfo['baa_accepted'] ?? false);
                $baaAcceptedAt = $practiceInfo['baa_accepted_at'] ?? null;
                $baaVersion = $practiceInfo['baa_version'] ?? '';
                $baaSignerName = $practiceInfo['baa_signer_name'] ?? '';
                $baaSignerTitle = $practiceInfo['baa_signer_title'] ?? '';
            }
        }

        // Determine if current user is an admin for this practice
        try {
            $stmt = $pdo->prepare("
                SELECT role 
                FROM practice_users 
                WHERE practice_id = :practice_id AND user_id = :user_id
                LIMIT 1
            ");
            $stmt->execute([
                'practice_id' => $currentPracticeId,
                'user_id' => $userId
            ]);
            $role = $stmt->fetchColumn();
            $isPracticeAdmin = ($role === 'admin');
        } catch (PDOException $e) {
            userLog("Error determining practice admin status: " . $e->getMessage(), true);
        }
    }
    
    // Get admin users (all users with role='admin' or is_owner=TRUE for this practice)
    $adminUsers = [];
    $limitedVisibilityUsers = []; // Track which users have limited visibility
    $canViewAnalyticsUsers = []; // Track which users can view analytics
    $canEditCasesUsers = []; // Track which users can create/edit cases
    $canAddLabelsUsers = []; // Track which users can create new labels
    
    // Ensure permission columns exist (auto-migration)
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'limited_visibility'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN limited_visibility BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, user can only see cases assigned to them'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'can_view_analytics'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN can_view_analytics BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If true, user can view the analytics tab'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'can_edit_cases'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN can_edit_cases BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If true, user can create and edit cases'");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'can_add_labels'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practice_users ADD COLUMN can_add_labels BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If true, user can create new labels'");
        }
    } catch (PDOException $e) {
        // Column might already exist or table doesn't exist yet
    }
    
    try {
        userLog("Attempting to retrieve Admin users for practice ID: {$currentPracticeId}", false);
        
        // First, get the practice creator/owner email, auth method, and their permissions
        if ($practiceCreatorId) {
            $stmt = $pdo->prepare("
                SELECT u.email, u.auth_method,
                       IFNULL(pu.limited_visibility, 0) as limited_visibility,
                       IFNULL(pu.can_view_analytics, 1) as can_view_analytics,
                       IFNULL(pu.can_edit_cases, 1) as can_edit_cases,
                       IFNULL(pu.can_add_labels, 1) as can_add_labels
                FROM users u
                LEFT JOIN practice_users pu ON u.id = pu.user_id AND pu.practice_id = :practice_id
                WHERE u.id = :user_id
            ");
            $stmt->execute([
                'user_id' => $practiceCreatorId,
                'practice_id' => $currentPracticeId
            ]);
            $creatorRow = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($creatorRow && $creatorRow['email']) {
                $adminUsers[] = $creatorRow['email'];
                $practiceCreatorEmail = $creatorRow['email'];
                $practiceCreatorAuthMethod = $creatorRow['auth_method'] ?? 'google';
                $limitedVisibilityUsers[$creatorRow['email']] = (bool)$creatorRow['limited_visibility'];
                $canViewAnalyticsUsers[$creatorRow['email']] = (bool)$creatorRow['can_view_analytics'];
                $canEditCasesUsers[$creatorRow['email']] = (bool)$creatorRow['can_edit_cases'];
                $canAddLabelsUsers[$creatorRow['email']] = true; // Creator always can add labels
            }
        }
        
        // Then get other admin users
        $stmt = $pdo->prepare("
            SELECT u.email, 
                   IFNULL(pu.limited_visibility, 0) as limited_visibility,
                   IFNULL(pu.can_view_analytics, 1) as can_view_analytics,
                   IFNULL(pu.can_edit_cases, 1) as can_edit_cases,
                   IFNULL(pu.can_add_labels, 1) as can_add_labels
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id AND pu.role = 'admin' AND pu.user_id != :creator_id
            ORDER BY pu.created_at ASC
        ");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'creator_id' => $practiceCreatorId ?? 0
        ]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Avoid duplicates
            if (!in_array($row['email'], $adminUsers)) {
                $adminUsers[] = $row['email'];
                $limitedVisibilityUsers[$row['email']] = (bool)$row['limited_visibility'];
                $canViewAnalyticsUsers[$row['email']] = (bool)$row['can_view_analytics'];
                $canEditCasesUsers[$row['email']] = (bool)$row['can_edit_cases'];
                $canAddLabelsUsers[$row['email']] = true; // Admins always can add labels
            }
        }
        
        userLog("Retrieved " . count($adminUsers) . " Admin users: " . implode(", ", $adminUsers), false);
    } catch (PDOException $e) {
        userLog("Error retrieving Admin users: " . $e->getMessage(), true);
    }
    
    // Get regular users (non-admin users for this practice)
    $gmailUsers = [];
    $gmailUserLogins = [];
    try {
        userLog("Attempting to retrieve regular users for practice ID: {$currentPracticeId}", false);
        
        $stmt = $pdo->prepare("
            SELECT u.email, u.last_login_at, 
                   IFNULL(pu.limited_visibility, 0) as limited_visibility,
                   IFNULL(pu.can_view_analytics, 1) as can_view_analytics,
                   IFNULL(pu.can_edit_cases, 1) as can_edit_cases,
                   IFNULL(pu.can_add_labels, 1) as can_add_labels
            FROM users u
            JOIN practice_users pu ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id AND pu.role = 'user'
            ORDER BY pu.created_at ASC
        ");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = $row['email'];
            // Avoid duplicates and don't include emails already in admin list
            if (!in_array($email, $adminUsers) && !in_array($email, $gmailUsers)) {
                $gmailUsers[] = $email;
                // Track last login timestamp (may be NULL)
                $gmailUserLogins[$email] = $row['last_login_at'] ?? null;
                // Track permissions
                $limitedVisibilityUsers[$email] = (bool)$row['limited_visibility'];
                $canViewAnalyticsUsers[$email] = (bool)$row['can_view_analytics'];
                $canEditCasesUsers[$email] = (bool)$row['can_edit_cases'];
                $canAddLabelsUsers[$email] = (bool)$row['can_add_labels'];
            }
        }
        
        userLog("Retrieved " . count($gmailUsers) . " regular users: " . implode(", ", $gmailUsers), false);
    } catch (PDOException $e) {
        userLog("Error retrieving regular users: " . $e->getMessage(), true);
    }

    // Get assignment labels for this practice
    $assignmentLabels = [];
    if ($currentPracticeId) {
        try {
            $stmt = $pdo->prepare("
                SELECT label, sort_order
                FROM practice_assignment_labels
                WHERE practice_id = :practice_id
                ORDER BY sort_order ASC, label ASC
            ");
            $stmt->execute(['practice_id' => $currentPracticeId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $label = isset($row['label']) ? trim($row['label']) : '';
                if ($label !== '' && !in_array($label, $assignmentLabels, true)) {
                    $assignmentLabels[] = $label;
                }
            }
            userLog("Retrieved " . count($assignmentLabels) . " assignment labels for practice ID: {$currentPracticeId}", false);
        } catch (PDOException $e) {
            userLog("Error retrieving assignment labels: " . $e->getMessage(), true);
        }
    }
    
    // Normalize preferences and provide defaults
    if (!$preferences) {
        $preferences = [
            'theme' => 'light',
            'allow_card_delete' => true,
            'highlight_past_due' => false,
            'past_due_days' => 7,
            'delivered_hide_days' => 120,
            'tour_completed' => false,
            'google_drive_backup' => false
        ];
    } else {
        if (!isset($preferences['theme'])) $preferences['theme'] = 'light';
        if (!isset($preferences['allow_card_delete'])) $preferences['allow_card_delete'] = true;
        if (!isset($preferences['highlight_past_due'])) $preferences['highlight_past_due'] = false;
        if (!isset($preferences['past_due_days'])) $preferences['past_due_days'] = 7;
        if (!isset($preferences['delivered_hide_days'])) $preferences['delivered_hide_days'] = 120;
        if (!isset($preferences['tour_completed'])) $preferences['tour_completed'] = false;
        if (!isset($preferences['google_drive_backup'])) $preferences['google_drive_backup'] = false;
        // Convert tour_completed to boolean
        $preferences['tour_completed'] = (bool)$preferences['tour_completed'];
        // Convert google_drive_backup to boolean
        $preferences['google_drive_backup'] = (bool)$preferences['google_drive_backup'];
    }

    // Check if Google Drive is available for this practice
    // Drive is available if: current user has a Drive token OR the practice creator signed up with Google
    $currentUserHasDriveToken = isset($_SESSION['google_drive_token']) && !empty($_SESSION['google_drive_token']);
    $practiceCreatorHasGoogle = ($practiceCreatorAuthMethod === 'google' || $practiceCreatorAuthMethod === 'both');
    $isGoogleDriveConnected = $currentUserHasDriveToken || $practiceCreatorHasGoogle;
    
    // Return preferences, admin users, regular users, practice name, logo path, assignment labels, and BAA info
    echo json_encode([
        'success' => true,
        'preferences' => $preferences,
        'adminUsers' => $adminUsers,
        'gmailUsers' => $gmailUsers,
        'gmailUserLogins' => $gmailUserLogins,
        'limitedVisibilityUsers' => $limitedVisibilityUsers,
        'canViewAnalyticsUsers' => $canViewAnalyticsUsers,
        'canEditCasesUsers' => $canEditCasesUsers,
        'canAddLabelsUsers' => $canAddLabelsUsers,
        'practiceName' => $practiceName,
        'logoPath' => $logoPath,
        'assignmentLabels' => $assignmentLabels,
        'isPracticeAdmin' => $isPracticeAdmin,
        'practiceCreatorEmail' => $practiceCreatorEmail,
        'practiceCreatorHasGoogleAccount' => ($practiceCreatorAuthMethod === 'google' || $practiceCreatorAuthMethod === 'both'),
        'isGoogleDriveConnected' => $isGoogleDriveConnected,
        // BAA fields
        'legalName' => $legalName,
        'displayName' => $displayName,
        'practiceAddress' => $practiceAddress,
        'baaAccepted' => $baaAccepted,
        'baaAcceptedAt' => $baaAcceptedAt,
        'baaVersion' => $baaVersion,
        'baaSignerName' => $baaSignerName,
        'baaSignerTitle' => $baaSignerTitle
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to retrieve settings'
    ]);
    
    userLog("Error getting user settings: " . $e->getMessage(), true);
}
