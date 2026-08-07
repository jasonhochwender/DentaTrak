<?php
/**
 * Reset All Data API Endpoint
 * Resets all data for the current user's practice
 * 
 * Access Control:
 * - Always allowed in development environment
 * - In UAT/Production: Only allowed for super users with dev_tools_enabled
 *
 * Tenant scoping:
 * - Operations are ALWAYS scoped to the current authenticated practice
 *   ($_SESSION['current_practice_id']), in every environment. There is no
 *   environment-specific full-database-reset path - a complete local
 *   database wipe, if ever needed, belongs in a separate, explicitly-named
 *   developer tool, not this per-practice action.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/dev-tools-access.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// Do not expose notices/warnings to the client
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

try {
    // Basic auth check
    if (!isset($_SESSION['user']) && !isset($_SESSION['db_user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.'
        ]);
        exit;
    }

    // Check dev tools access (handles both development and super user in UAT/Prod)
    $userEmail = $_SESSION['user_email'] ?? ($_SESSION['user']['email'] ?? '');
    if (!canAccessDevTools($appConfig, $userEmail)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Dev tools access not authorized.'
        ]);
        exit;
    }
    
    // For super users in UAT/Production, verify they have admin access to the practice
    $isSuperUserInProd = isProductionOrUAT($appConfig);
    if ($isSuperUserInProd) {
        $userId = $_SESSION['db_user_id'] ?? 0;
        $practiceId = $_SESSION['current_practice_id'] ?? 0;
        
        if (!superUserHasPracticeAdminAccess($pdo, $userId, $practiceId)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You must be an admin of this practice to perform this action.'
            ]);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
        exit;
    }
    
    // Validate CSRF token
    requireCsrfToken();

    if (!$pdo) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection is not configured.'
        ]);
        exit;
    }

    // Optional confirmation flag in the body for extra safety
    $inputRaw = file_get_contents('php://input');
    $input = $inputRaw ? json_decode($inputRaw, true) : [];
    
    if (!isset($input['confirm']) || !$input['confirm']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing confirmation flag.'
        ]);
        exit;
    }

    // Get practice ID for scoped operations
    $userId = $_SESSION['db_user_id'] ?? null;
    $practiceId = isset($_SESSION['current_practice_id']) ? (int)$_SESSION['current_practice_id'] : 0;
    
    // Reset starting - no debug logging needed for normal operations
    
    // Clean up Google Drive folders (only if user has valid Google OAuth credentials)
    try {
        // First check if we have valid Google credentials before attempting any Drive operations
        $client = getGoogleClient();
        $hasValidGoogleAuth = $client && $client->getAccessToken() && !$client->isAccessTokenExpired();
        
        if ($userId && $hasValidGoogleAuth) {
            // Get the practice root folder ID from the database (don't call getPracticeRootFolder which may trigger API calls)
            $rootFolderId = null;
            if ($practiceId && $pdo) {
                $stmt = $pdo->prepare("SELECT drive_root_id FROM practices WHERE id = ? LIMIT 1");
                $stmt->execute([$practiceId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $rootFolderId = $row['drive_root_id'] ?? null;
            }
            
            if ($rootFolderId) {
                $service = new Google_Service_Drive($client);
                
                // List all folders in the practice root (these are case folders)
                $response = $service->files->listFiles([
                    'q' => "'{$rootFolderId}' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false",
                    'fields' => 'files(id, name)'
                ]);
                
                $folders = $response->getFiles();
                
                // Delete each case folder
                foreach ($folders as $folder) {
                    trashDriveFolder($folder->getId());
                }
                
                // Also delete the practice root folder itself
                trashDriveFolder($rootFolderId);
            }
        }
        // If no valid Google auth, skip Drive cleanup silently - database reset will still proceed
    } catch (Exception $driveError) {
        // Log Drive cleanup error but don't fail the reset
        error_log('Drive cleanup error during reset: ' . $driveError->getMessage());
    }

    // TENANT SAFETY: "Start Over" always performs the same practice-scoped
    // reset in every environment (development, UAT, production). There is
    // intentionally no environment-specific branch here and no TRUNCATE of
    // any kind - a per-practice Dev Tool must never be able to affect any
    // practice other than $practiceId, regardless of where it's run. A
    // separate, explicitly-named tool (e.g. "Wipe Local Database") would be
    // the right place for a full local sandbox wipe, and is out of scope
    // for this action.
    $pdo->beginTransaction();
    try {
        // Get all user IDs in this practice before deleting (check if table exists)
        $userIds = [];
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM practice_users WHERE practice_id = ?");
            $stmt->execute([$practiceId]);
            $practiceUserRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($practiceUserRows as $row) {
                $userIds[] = $row['user_id'];
            }
        } catch (PDOException $e) {
            error_log('practice_users table does not exist or error: ' . $e->getMessage());
        }
        
        // Delete cases for this practice (check if table exists)
        $casesDeleted = 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM cases_cache WHERE practice_id = ?");
            $stmt->execute([$practiceId]);
            $casesDeleted = $stmt->rowCount();
            // Cases deleted successfully
        } catch (PDOException $e) {
            error_log('cases_cache table does not exist or error: ' . $e->getMessage());
        }
        
        // Delete practice_users associations (check if table exists)
        $practiceUsersDeleted = 0;
        try {
            $stmt = $pdo->prepare("DELETE FROM practice_users WHERE practice_id = ?");
            $stmt->execute([$practiceId]);
            $practiceUsersDeleted = $stmt->rowCount();
            // Practice user associations deleted successfully
        } catch (PDOException $e) {
            error_log('practice_users table does not exist or error: ' . $e->getMessage());
        }
        
        // SECURITY: Determine which of this practice's users have NO
        // remaining membership in ANY practice (not just this one), now
        // that this practice's practice_users rows are gone. This single
        // membership check is reused below for every shared, user-level
        // table (preferences, auth methods, billing, and the users row
        // itself) so a user who still belongs to another practice - e.g.
        // owner of Practice A, member of Practice B - keeps their login
        // method, preferences, and billing records intact when Practice A
        // is reset.
        $orphanedUserIds = [];
        if (!empty($userIds)) {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            try {
                $stmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE id IN ($placeholders)
                    AND id NOT IN (
                        SELECT DISTINCT user_id
                        FROM practice_users
                        WHERE user_id IN ($placeholders)
                    )
                ");
                $stmt->execute(array_merge($userIds, $userIds));
                $orphanedUserIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            } catch (PDOException $e) {
                error_log('Error determining orphaned users during reset: ' . $e->getMessage());
            }
        }
        
        // Only touch shared user-level records for users who truly have no
        // remaining practice membership anywhere.
        if (!empty($orphanedUserIds)) {
            $orphanPlaceholders = str_repeat('?,', count($orphanedUserIds) - 1) . '?';
            
            // Delete user preferences (check if table exists first)
            try {
                $stmt = $pdo->prepare("DELETE FROM user_preferences WHERE user_id IN ($orphanPlaceholders)");
                $stmt->execute($orphanedUserIds);
            } catch (PDOException $e) {
                error_log('user_preferences table does not exist or error: ' . $e->getMessage());
            }
            
            // Delete user auth methods (check if table exists first)
            try {
                $stmt = $pdo->prepare("DELETE FROM user_auth_methods WHERE user_id IN ($orphanPlaceholders)");
                $stmt->execute($orphanedUserIds);
            } catch (PDOException $e) {
                error_log('user_auth_methods table does not exist or error: ' . $e->getMessage());
            }
            
            // Delete billing info (check if table exists first)
            try {
                $stmt = $pdo->prepare("DELETE FROM user_billing WHERE user_id IN ($orphanPlaceholders)");
                $stmt->execute($orphanedUserIds);
            } catch (PDOException $e) {
                error_log('user_billing table does not exist or error: ' . $e->getMessage());
            }
            
            // Delete the users themselves - already confirmed above that
            // these specific IDs have no remaining practice membership.
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($orphanPlaceholders)");
                $stmt->execute($orphanedUserIds);
            } catch (PDOException $e) {
                error_log('Error deleting users: ' . $e->getMessage());
            }
        }
        
        // Finally, delete the practice itself (check if table exists)
        try {
            $stmt = $pdo->prepare("DELETE FROM practices WHERE id = ?");
            $stmt->execute([$practiceId]);
            // Practice deleted successfully
        } catch (PDOException $e) {
            error_log('practices table does not exist or error: ' . $e->getMessage());
        }
        
        $pdo->commit();
        
        $message = "Your practice and all associated data have been completely deleted.";
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Clear relevant cookies (including preferred practice selection)
    if (isset($_COOKIE['preferred_practice_id'])) {
        setcookie('preferred_practice_id', '', time() - 3600, '/');
    }

    // Destroy the current PHP session and its cookie
    if (session_id() !== '') {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    // Clear all session variables
    $_SESSION = [];
    
    // Destroy the session
    session_destroy();
    
    // Also clear any additional auth-related cookies
    if (isset($_COOKIE['google_drive_token'])) {
        setcookie('google_drive_token', '', time() - 3600, '/');
    }
    if (isset($_COOKIE['auth_token'])) {
        setcookie('auth_token', '', time() - 3600, '/');
    }

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while resetting all data: ' . $e->getMessage()
    ]);
}
