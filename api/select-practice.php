<?php
/**
 * Select Practice API Endpoint
 * Sets the current practice for the user session
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

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
        'message' => 'User not authenticated'
    ]);
    exit;
}

$userId = $_SESSION['db_user_id'];

// Get practice ID from GET, POST, or JSON request
$practiceId = null;
$rememberPreference = false;

// Check GET parameter
if (isset($_GET['practice_id']) && !empty($_GET['practice_id'])) {
    $practiceId = $_GET['practice_id'];
    // Check if remember preference is set
    if (isset($_GET['remember'])) {
        $rememberPreference = (bool)$_GET['remember'];
    }
} else {
    // Try to get from POST or JSON body
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    
    if (isset($data['practice_id']) && !empty($data['practice_id'])) {
        $practiceId = $data['practice_id'];
        // Check if remember preference is set in JSON data
        if (isset($data['remember_preference'])) {
            $rememberPreference = (bool)$data['remember_preference'];
        }
    } else if (isset($_POST['practice_id']) && !empty($_POST['practice_id'])) {
        $practiceId = $_POST['practice_id'];
        // Check if remember preference is set in POST data
        if (isset($_POST['remember_preference'])) {
            $rememberPreference = (bool)$_POST['remember_preference'];
        }
    }
}

// Validate required fields
if (empty($practiceId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Practice ID is required'
    ]);
    exit;
}

try {
    // Check if user belongs to this practice
    $stmt = $pdo->prepare("
        SELECT 1
        FROM practice_users
        WHERE practice_id = :practice_id AND user_id = :user_id
    ");
    $stmt->execute([
        'practice_id' => $practiceId,
        'user_id' => $userId
    ]);
    
    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have access to this practice'
        ]);
        exit;
    }
    
    // Store the selected practice ID in the session
    $_SESSION['current_practice_id'] = $practiceId;
    
    // If the user wants to remember this preference, store it in user_preferences
    if ($rememberPreference) {
        try {
            ensureUserPreferencesSchema();
            $stmt = $pdo->prepare("INSERT INTO user_preferences (user_id, preferred_practice_id) VALUES (:user_id, :practice_id)
                ON DUPLICATE KEY UPDATE preferred_practice_id = VALUES(preferred_practice_id)");
            $stmt->execute([
                'user_id' => $userId,
                'practice_id' => $practiceId
            ]);
        } catch (PDOException $e) {
            userLog("Error saving preferred practice for user {$userId}: " . $e->getMessage(), true);
        }
    }
    
    // Clear any practice setup/selection flags
    $_SESSION['needs_practice_setup'] = false;
    $_SESSION['needs_practice_selection'] = false;
    $_SESSION['has_multiple_practices'] = false;
    $_SESSION['practice_setup_visits'] = 0;
    $_SESSION['from_practice_setup'] = true; // Mark that we're coming from setup process
    
    // Get practice details
    $stmt = $pdo->prepare("
        SELECT id, practice_id as uuid, practice_name
        FROM practices
        WHERE id = :id
    ");
    $stmt->execute(['id' => $practiceId]);
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
    
    // Force redirect if redirect=1 is set in the query string
    if (isset($_GET['redirect']) && $_GET['redirect'] == 1) {
        header('Location: ../main.php');
        exit;
    }
    
    // For direct browser access, redirect to main.php
    if ($isDirectAccess) {
        header('Location: ../main.php');
        exit;
    }
    
    // Otherwise return JSON for API calls
    echo json_encode([
        'success' => true,
        'message' => 'Practice selected successfully',
        'practice' => $practice,
        'preference_saved' => $rememberPreference
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error selecting practice: ' . $e->getMessage()
    ]);
    
    userLog("Error selecting practice: " . $e->getMessage(), true);
}
