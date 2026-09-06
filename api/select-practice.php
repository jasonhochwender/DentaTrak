<?php
/**
 * Select Practice API Endpoint
 * Sets the current practice for the user session
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/csrf.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => t('auth.errors.user_not_authenticated')
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'success' => false,
        'message' => 'Saving a default practice requires a POST request with a valid CSRF token.',
        'preference_saved' => false
    ]);
    exit;
}

if ($requestMethod === 'POST') {
    requireCsrfToken();
}

// Get practice ID from GET, POST, or JSON request
$practiceId = null;
$rememberPreference = false;

// Check GET parameter
if (isset($_GET['practice_id']) && !empty($_GET['practice_id'])) {
    $practiceId = $_GET['practice_id'];
    // Check if remember preference is set
    if (isset($_GET['remember'])) {
        $rememberPreference = filter_var($_GET['remember'], FILTER_VALIDATE_BOOLEAN);
    }
} else {
    // Try to get from POST or JSON body
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    
    if (isset($data['practice_id']) && !empty($data['practice_id'])) {
        $practiceId = $data['practice_id'];
        // Check if remember preference is set in JSON data
        if (isset($data['remember_preference'])) {
            $rememberPreference = filter_var($data['remember_preference'], FILTER_VALIDATE_BOOLEAN);
        }
    } else if (isset($_POST['practice_id']) && !empty($_POST['practice_id'])) {
        $practiceId = $_POST['practice_id'];
        // Check if remember preference is set in POST data
        if (isset($_POST['remember_preference'])) {
            $rememberPreference = filter_var($_POST['remember_preference'], FILTER_VALIDATE_BOOLEAN);
        }
    }
}

if ($requestMethod !== 'POST' &&
    ($rememberPreference || filter_var($_GET['remember'] ?? false, FILTER_VALIDATE_BOOLEAN))) {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'success' => false,
        'message' => 'Saving a default practice requires a POST request with a valid CSRF token.',
        'preference_saved' => false
    ]);
    exit;
}

// Validate required fields
if ((!is_int($practiceId) && !is_string($practiceId)) ||
    !preg_match('/^[1-9][0-9]*$/D', (string)$practiceId) ||
    filter_var($practiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => t('auth.errors.practice_id_required')
    ]);
    exit;
}

$practiceId = (int)$practiceId;

try {
    // Check if user belongs to this practice
    $stmt = $pdo->prepare("
        SELECT p.id, p.practice_id AS uuid, p.practice_name, pu.role, pu.is_owner,
               IFNULL(pu.limited_visibility, 0) AS limited_visibility,
               IFNULL(pu.can_view_analytics, 1) AS can_view_analytics,
               IFNULL(pu.can_edit_cases, 1) AS can_edit_cases,
               IFNULL(pu.is_lab, 0) AS is_lab
        FROM practice_users pu
        JOIN practices p ON p.id = pu.practice_id
        JOIN users u ON u.id = pu.user_id
        WHERE pu.practice_id = :practice_id AND pu.user_id = :user_id
          AND u.is_active = 1
          AND (p.is_active = 1 OR p.is_active IS NULL)
    ");
    $stmt->execute([
        'practice_id' => $practiceId,
        'user_id' => $userId
    ]);
    
    // Get practice details
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$practice) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => t('auth.errors.no_access_practice')
        ]);
        exit;
    }
    
    // If the user wants to remember this preference, store it in user_preferences
    if ($rememberPreference) {
        try {
            ensureUserPreferencesSchema();
            $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, preferred_practice_id) VALUES (:user_id, :practice_id)
                ON DUPLICATE KEY UPDATE preferred_practice_id = VALUES(preferred_practice_id)");
            if (!$stmt->execute([
                'user_id' => $userId,
                'practice_id' => $practiceId
            ])) {
                throw new PDOException('Preferred practice save failed');
            }
        } catch (PDOException $e) {
            userLog("Error saving preferred practice for user {$userId}: " . $e->getMessage(), true);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to save your default practice. Your selected practice has not changed. Please try again.',
                'preference_saved' => false
            ]);
            exit;
        }
    }

    // Store the selected practice ID in the session
    unset($_SESSION['cases_cache'], $_SESSION['practice_users_cache'], $_SESSION['practice_settings_cache']);
    $_SESSION['current_practice_id'] = $practiceId;
    $_SESSION['practice_name'] = $practice['practice_name'];
    $_SESSION['practice_role'] = $practice['role'];
    $_SESSION['practice_is_owner'] = (bool)$practice['is_owner'];
    $_SESSION['practice_permissions'] = [
        'limited_visibility' => (bool)$practice['limited_visibility'],
        'can_view_analytics' => (bool)$practice['can_view_analytics'],
        'can_edit_cases' => (bool)$practice['can_edit_cases'],
        'is_lab' => (bool)$practice['is_lab']
    ];

    // Resolve and persist the active locale for the newly-selected practice
    setResolvedLocale(resolveLocale(null, $userId, $practiceId));
    
    // Clear any practice setup/selection flags
    $_SESSION['needs_practice_setup'] = false;
    $_SESSION['needs_practice_selection'] = false;
    $_SESSION['has_multiple_practices'] = false;
    $_SESSION['practice_setup_visits'] = 0;
    $_SESSION['from_practice_setup'] = true; // Mark that we're coming from setup process
    
    // Log the activity
    if (function_exists('logUserActivity')) {
        logUserActivity($userId, 'select_practice', "User selected practice: {$practice['practice_name']}");
    }
    
    // Check if this is a direct browser request or an API call
    $isDirectAccess = false;
    
    // If it's a GET request with practice_id parameter, it's likely direct browser access
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['practice_id'])) {
        $isDirectAccess = true;
    }
    
    // If the Accept header doesn't specify application/json, it may be a direct browser request
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
        $isDirectAccess = true;
    }
    
    // If the request doesn't come from fetch or XHR
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        // But also check Content-Type to avoid redirecting API calls
        if (!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
            $isDirectAccess = true;
        }
    }
    
    // Optional deep link: callers that need the user to land in the Billing
    // modal (e.g. an "Upgrade" action on the plan-limit screen) can pass
    // billing=1. Selecting a practice first guarantees main.php has a valid
    // practice context instead of bouncing to the practice chooser.
    $mainUrl = '../main.php';
    if (isset($_GET['billing']) && $_GET['billing'] == 1) {
        $mainUrl .= '?billing=1';
    }

    // Force redirect if redirect=1 is set in the query string
    if (isset($_GET['redirect']) && $_GET['redirect'] == 1) {
        header('Location: ' . $mainUrl);
        exit;
    }
    
    // For direct browser access, redirect to main.php
    if ($isDirectAccess) {
        header('Location: ' . $mainUrl);
        exit;
    }
    
    // Otherwise return JSON for API calls
    echo json_encode([
        'success' => true,
        'message' => t('auth.errors.practice_selected'),
        'practice' => [
            'id' => $practice['id'],
            'uuid' => $practice['uuid'],
            'practice_name' => $practice['practice_name']
        ],
        'preference_saved' => $rememberPreference
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => t('auth.errors.error_selecting_practice')
    ]);
    
    userLog("Error selecting practice: " . $e->getMessage(), true);
}
