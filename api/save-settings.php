<?php
/**
 * Save Settings API Endpoint
 * Handles saving user preferences to the database
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/csrf.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context for settings that affect practice data
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Get JSON data from request
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data format']);
    exit;
}

// Validate data
$theme = isset($data['theme']) ? $data['theme'] : 'light';
if (!in_array($theme, ['light', 'dark'])) {
    $theme = 'light';
}

$allowCardDelete = isset($data['allowCardDelete']) ? (bool)$data['allowCardDelete'] : false;
$highlightPastDue = isset($data['highlightPastDue']) ? (bool)$data['highlightPastDue'] : true;
$pastDueDays = isset($data['pastDueDays']) ? (int)$data['pastDueDays'] : 7;

// New: hide Delivered cases older than N days (0 = show all). Default is 120 days.
$deliveredHideDays = isset($data['deliveredHideDays']) ? (int)$data['deliveredHideDays'] : 120;

// Google Drive backup setting
$googleDriveBackup = isset($data['googleDriveBackup']) ? (bool)$data['googleDriveBackup'] : false;

// Ensure pastDueDays is within valid range
if ($pastDueDays < 1) {
    $pastDueDays = 1;
} elseif ($pastDueDays > 99) {
    $pastDueDays = 99;
}

// Ensure deliveredHideDays is within a sane range (0 = off)
if ($deliveredHideDays < 0) {
    $deliveredHideDays = 0;
} elseif ($deliveredHideDays > 365) {
    $deliveredHideDays = 365;
}

// Practice name handling
$practiceName = isset($data['practiceName']) ? trim($data['practiceName']) : '';

// Logo action handling (e.g. 'update', 'remove', or 'none')
$logoAction = isset($data['logoAction']) ? $data['logoAction'] : 'none';

// Optional logo path when updating logo
$logoPath = isset($data['logoPath']) ? trim($data['logoPath']) : '';

// Handle Admin users
$adminUsers = isset($data['adminUsers']) ? $data['adminUsers'] : [];
$validAdminUsers = [];

foreach ($adminUsers as $email) {
    // Validate each email (accept any valid email, not just Gmail)
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validAdminUsers[] = $email;
    }
}

// Handle regular users
$gmailUsers = isset($data['gmailUsers']) ? $data['gmailUsers'] : [];
$validGmailUsers = [];

foreach ($gmailUsers as $email) {
    // Validate each email (accept any valid email, not just Gmail)
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validGmailUsers[] = $email;
    }
}

// Handle limited visibility users (map of email => boolean)
$limitedVisibilityUsers = isset($data['limitedVisibilityUsers']) && is_array($data['limitedVisibilityUsers']) 
    ? $data['limitedVisibilityUsers'] 
    : [];

// Handle can view analytics users (map of email => boolean, default true)
$canViewAnalyticsUsers = isset($data['canViewAnalyticsUsers']) && is_array($data['canViewAnalyticsUsers']) 
    ? $data['canViewAnalyticsUsers'] 
    : [];

// Handle can edit cases users (map of email => boolean, default true)
$canEditCasesUsers = isset($data['canEditCasesUsers']) && is_array($data['canEditCasesUsers']) 
    ? $data['canEditCasesUsers'] 
    : [];

// Handle can add labels permission (map of email => boolean, default true)
$canAddLabelsUsers = isset($data['canAddLabelsUsers']) && is_array($data['canAddLabelsUsers']) 
    ? $data['canAddLabelsUsers'] 
    : [];

// Handle assignment labels (free-text, per practice)
$assignmentLabels = isset($data['assignmentLabels']) && is_array($data['assignmentLabels']) ? $data['assignmentLabels'] : [];
$validAssignmentLabels = [];
$seenAssignmentLabels = [];

foreach ($assignmentLabels as $label) {
    if (!is_string($label)) {
        continue;
    }

    $trimmed = trim($label);
    if ($trimmed === '') {
        continue;
    }

    // Limit length to 150 characters to avoid excessively long labels
    if (mb_strlen($trimmed) > 150) {
        $trimmed = mb_substr($trimmed, 0, 150);
    }

    $lower = mb_strtolower($trimmed);
    if (isset($seenAssignmentLabels[$lower])) {
        continue;
    }

    $seenAssignmentLabels[$lower] = true;
    $validAssignmentLabels[] = $trimmed;
}

try {
    ensureUserPreferencesSchema();

    // Ensure google_drive_backup column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'google_drive_backup'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN google_drive_backup TINYINT(1) DEFAULT 0");
    }

    // First, update user preferences
    $stmt = $pdo->prepare("
        INSERT INTO user_preferences (
            user_id, theme, allow_card_delete, highlight_past_due, past_due_days, delivered_hide_days, google_drive_backup
        ) VALUES (
            :user_id, :theme, :allow_card_delete, :highlight_past_due, :past_due_days, :delivered_hide_days, :google_drive_backup
        ) ON DUPLICATE KEY UPDATE
            theme = VALUES(theme),
            allow_card_delete = VALUES(allow_card_delete),
            highlight_past_due = VALUES(highlight_past_due),
            past_due_days = VALUES(past_due_days),
            delivered_hide_days = VALUES(delivered_hide_days),
            google_drive_backup = VALUES(google_drive_backup)
    ");
    
    $result = $stmt->execute([
        'user_id' => $userId,
        'theme' => $theme,
        'allow_card_delete' => $allowCardDelete ? 1 : 0,
        'highlight_past_due' => $highlightPastDue ? 1 : 0,
        'past_due_days' => $pastDueDays,
        'delivered_hide_days' => $deliveredHideDays,
        'google_drive_backup' => $googleDriveBackup ? 1 : 0
    ]);
    
    // Update practice settings (name and logo) if user is an admin of the practice
    // and allow any user who belongs to the practice to update assignment labels
    if (isset($_SESSION['current_practice_id'])) {
        $currentPracticeId = $_SESSION['current_practice_id'];
        
        // Check if user belongs to this practice and get their role
        $stmt = $pdo->prepare("SELECT role FROM practice_users WHERE practice_id = :practice_id AND user_id = :user_id");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'user_id' => $userId
        ]);
        $userRole = $stmt->fetchColumn();
        $isPracticeMember = !empty($userRole);
        
        if ($userRole === 'admin') {
            // Handle display name update (separate from legal name which is immutable)
            $displayName = isset($data['displayName']) ? trim($data['displayName']) : '';
            
            if (!empty($displayName)) {
                // Update display_name (editable) and practice_name (legacy field for UI)
                // Note: legal_name is IMMUTABLE and cannot be changed after BAA acceptance
                $stmt = $pdo->prepare("UPDATE practices SET display_name = :display_name, practice_name = :practice_name WHERE id = :id");
                $stmt->execute([
                    'display_name' => $displayName,
                    'practice_name' => $displayName,
                    'id' => $currentPracticeId
                ]);
                
                // Update session with new display name
                $_SESSION['practice_name'] = $displayName;
                
                userLog("Updated display name for practice {$currentPracticeId} to '{$displayName}'", false);
            }
            // Legacy support: also check practiceName for backwards compatibility
            elseif (!empty($practiceName)) {
                $stmt = $pdo->prepare("UPDATE practices SET display_name = :display_name, practice_name = :practice_name WHERE id = :id");
                $stmt->execute([
                    'display_name' => $practiceName,
                    'practice_name' => $practiceName,
                    'id' => $currentPracticeId
                ]);
                
                // Update session with new practice name
                $_SESSION['practice_name'] = $practiceName;
                
                userLog("Updated display name for practice {$currentPracticeId} to '{$practiceName}'", false);
            }

            // Handle logo removal if requested
            if ($logoAction === 'remove') {
                // Get current logo path
                $stmt = $pdo->prepare("SELECT logo_path FROM practices WHERE id = :practice_id");
                $stmt->execute(['practice_id' => $currentPracticeId]);
                $currentLogoPath = $stmt->fetchColumn();

                // Clear logo_path in DB
                $stmt = $pdo->prepare("UPDATE practices SET logo_path = NULL WHERE id = :practice_id");
                $stmt->execute(['practice_id' => $currentPracticeId]);

                // Delete logo file if it exists on disk
                if ($currentLogoPath) {
                    $fullPath = __DIR__ . '/../' . $currentLogoPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }

                userLog("Removed logo for practice {$currentPracticeId}", false);
            }
            // Handle logo update if requested and a path was provided
            elseif ($logoAction === 'update' && $logoPath !== '') {
                $stmt = $pdo->prepare("UPDATE practices SET logo_path = :logo_path WHERE id = :practice_id");
                $stmt->execute([
                    'logo_path' => $logoPath,
                    'practice_id' => $currentPracticeId
                ]);

                userLog("Updated logo for practice {$currentPracticeId} to '{$logoPath}'", false);
            }
        } else {
            // Not an admin; log any attempted changes to practice name or logo
            if (!empty($practiceName) || $logoAction === 'remove') {
                userLog("User {$userId} attempted to update practice name or logo but is not an admin", true);
            }
        }

        // Update assignment labels for this practice for any practice member
        if ($isPracticeMember) {
            try {
                // Delete existing labels for this practice
                $stmt = $pdo->prepare("DELETE FROM practice_assignment_labels WHERE practice_id = :practice_id");
                $stmt->execute(['practice_id' => $currentPracticeId]);

                // Insert current labels, preserving order
                if (!empty($validAssignmentLabels)) {
                    $stmt = $pdo->prepare("INSERT INTO practice_assignment_labels (practice_id, label, sort_order) VALUES (:practice_id, :label, :sort_order)");
                    $sortOrder = 0;
                    foreach ($validAssignmentLabels as $label) {
                        $stmt->execute([
                            'practice_id' => $currentPracticeId,
                            'label' => $label,
                            'sort_order' => $sortOrder++
                        ]);
                    }
                }

                userLog("Updated assignment labels for practice {$currentPracticeId} (" . count($validAssignmentLabels) . " labels) by user {$userId}", false);
            } catch (PDOException $e) {
                userLog("Error updating assignment labels for practice {$currentPracticeId} by user {$userId}: " . $e->getMessage(), true);
            }
        } else {
            if (!empty($validAssignmentLabels)) {
                userLog("User {$userId} attempted to update assignment labels but does not belong to practice {$currentPracticeId}", true);
            }
        }
    }
    
    // Get current practice ID
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    if (!$currentPracticeId) {
        throw new Exception('No practice selected');
    }
    
    if ($userRole === 'admin') {
        // Get practice creator ID
        $stmt = $pdo->prepare("SELECT created_by FROM practices WHERE id = :practice_id");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $practiceCreatorId = $stmt->fetchColumn();
        
        // Don't allow modifying the practice creator's role
        // Get creator email to preserve in admin list
        $creatorEmail = null;
        if ($practiceCreatorId) {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id");
            $stmt->execute(['user_id' => $practiceCreatorId]);
            $creatorEmail = $stmt->fetchColumn();
            
            // Make sure creator is in admin list
            if ($creatorEmail && !in_array($creatorEmail, $validAdminUsers)) {
                // Add creator to beginning of admin list
                array_unshift($validAdminUsers, $creatorEmail);
            }
        }
        
        // Begin transaction to ensure consistency
        $pdo->beginTransaction();
        
        try {
            // First, remove all non-creator users from the practice
            $stmt = $pdo->prepare("
                DELETE FROM practice_users 
                WHERE practice_id = :practice_id AND user_id != :creator_id
            ");
            $stmt->execute([
                'practice_id' => $currentPracticeId,
                'creator_id' => $practiceCreatorId ?? 0
            ]);
            
            userLog("Removed all non-creator users from practice {$currentPracticeId}", false);
            
            // Process admin users
            userLog("Processing admin users: " . count($validAdminUsers) . " - " . implode(", ", $validAdminUsers), false);
            
            foreach ($validAdminUsers as $email) {
                // Skip if it's the creator (already in the database)
                if ($email === $creatorEmail) continue;
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                $userId = $stmt->fetchColumn();
                
                if (!$userId) {
                    // Create user if they don't exist
                    $stmt = $pdo->prepare("
                        INSERT INTO users (email, role, is_active, created_at)
                        VALUES (:email, 'user', 1, NOW())
                    ");
                    $stmt->execute(['email' => $email]);
                    $userId = $pdo->lastInsertId();
                }
                
                // Check permissions for this user (default: limited=false, analytics=true, edit=true, add_labels=true for admins)
                $hasLimitedVisibility = isset($limitedVisibilityUsers[$email]) && $limitedVisibilityUsers[$email] ? 1 : 0;
                $canViewAnalytics = isset($canViewAnalyticsUsers[$email]) ? ($canViewAnalyticsUsers[$email] ? 1 : 0) : 1;
                $canEditCases = isset($canEditCasesUsers[$email]) ? ($canEditCasesUsers[$email] ? 1 : 0) : 1;
                $canAddLabels = 1; // Admins always can add labels
                
                // Add user to practice as admin
                $stmt = $pdo->prepare("
                    INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases, can_add_labels, created_at)
                    VALUES (:practice_id, :user_id, 'admin', 0, :limited_visibility, :can_view_analytics, :can_edit_cases, :can_add_labels, NOW())
                ");
                $stmt->execute([
                    'practice_id' => $currentPracticeId,
                    'user_id' => $userId,
                    'limited_visibility' => $hasLimitedVisibility,
                    'can_view_analytics' => $canViewAnalytics,
                    'can_edit_cases' => $canEditCases,
                    'can_add_labels' => $canAddLabels
                ]);
            }
            
            // Process regular users
            userLog("Processing regular users: " . count($validGmailUsers) . " - " . implode(", ", $validGmailUsers), false);
            
            foreach ($validGmailUsers as $email) {
                // Skip if user is already an admin
                if (in_array($email, $validAdminUsers)) continue;
                
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
                $stmt->execute(['email' => $email]);
                $userId = $stmt->fetchColumn();
                
                if (!$userId) {
                    // Create user if they don't exist
                    $stmt = $pdo->prepare("
                        INSERT INTO users (email, role, is_active, created_at)
                        VALUES (:email, 'user', 1, NOW())
                    ");
                    $stmt->execute(['email' => $email]);
                    $userId = $pdo->lastInsertId();
                }
                
                // Check permissions for this user (default: limited=false, analytics=true, edit=true, add_labels=true)
                $hasLimitedVisibility = isset($limitedVisibilityUsers[$email]) && $limitedVisibilityUsers[$email] ? 1 : 0;
                $canViewAnalytics = isset($canViewAnalyticsUsers[$email]) ? ($canViewAnalyticsUsers[$email] ? 1 : 0) : 1;
                $canEditCases = isset($canEditCasesUsers[$email]) ? ($canEditCasesUsers[$email] ? 1 : 0) : 1;
                $canAddLabels = isset($canAddLabelsUsers[$email]) ? ($canAddLabelsUsers[$email] ? 1 : 0) : 1;
                
                // Add user to practice as regular user
                $stmt = $pdo->prepare("
                    INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, can_view_analytics, can_edit_cases, can_add_labels, created_at)
                    VALUES (:practice_id, :user_id, 'user', 0, :limited_visibility, :can_view_analytics, :can_edit_cases, :can_add_labels, NOW())
                ");
                $stmt->execute([
                    'practice_id' => $currentPracticeId,
                    'user_id' => $userId,
                    'limited_visibility' => $hasLimitedVisibility,
                    'can_view_analytics' => $canViewAnalytics,
                    'can_edit_cases' => $canEditCases,
                    'can_add_labels' => $canAddLabels
                ]);
            }
            
            $pdo->commit();
            userLog("Successfully updated practice users for practice {$currentPracticeId}", false);

            foreach ($validAdminUsers as $email) {
                try {
                    sharePracticeRootWithEmail($currentPracticeId, $email, 'writer');
                } catch (Exception $e) {
                }
            }

            foreach ($validGmailUsers as $email) {
                if (in_array($email, $validAdminUsers)) {
                    continue;
                }

                try {
                    sharePracticeRootWithEmail($currentPracticeId, $email, 'writer');
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
            // Roll back transaction on error
            $pdo->rollBack();
            userLog("Error updating practice users: " . $e->getMessage(), true);
        }
    } else {
        if (!empty($validAdminUsers) || !empty($validGmailUsers)) {
            userLog("User {$userId} attempted to modify practice users but is not an admin of practice {$currentPracticeId}", true);
        }
    }
    
    // Update session data
    $_SESSION['user_preferences'] = [
        'theme' => $theme,
        'allow_card_delete' => $allowCardDelete,
        'highlight_past_due' => $highlightPastDue,
        'past_due_days' => $pastDueDays,
        'delivered_hide_days' => $deliveredHideDays,
        'google_drive_backup' => $googleDriveBackup
    ];
    
    // Log the activity
    logUserActivity($userId, 'update_settings', 'User updated preferences');
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings saved successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while saving settings'
    ]);
    
    userLog("Error saving user settings: " . $e->getMessage(), true);
}
