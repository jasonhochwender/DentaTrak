<?php
/**
 * Update Practice API Endpoint
 * Creates or updates a dental practice
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/google-drive.php';

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

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['practice_name']) || empty($data['practice_name'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Practice name is required'
    ]);
    exit;
}

$practiceName = $data['practice_name'];
$practiceId = isset($data['practice_id']) ? $data['practice_id'] : null;

try {
    // Start transaction
    $pdo->beginTransaction();

    if ($practiceId) {
        // Check if user has admin access to this practice
        $stmt = $pdo->prepare("
            SELECT practice_users.role 
            FROM practice_users 
            WHERE practice_id = :practice_id AND user_id = :user_id
        ");
        $stmt->execute([
            'practice_id' => $practiceId,
            'user_id' => $userId
        ]);
        $userRole = $stmt->fetchColumn();
        
        if ($userRole !== 'admin') {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have permission to update this practice'
            ]);
            exit;
        }
        
        // Update existing practice
        $legalName = isset($data['legal_name']) ? $data['legal_name'] : null;
        
        if ($legalName) {
            $stmt = $pdo->prepare("
                UPDATE practices
                SET practice_name = :practice_name,
                    legal_name = :legal_name,
                    display_name = COALESCE(display_name, :display_name)
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'practice_name' => $practiceName,
                'legal_name' => $legalName,
                'display_name' => $legalName,
                'id' => $practiceId
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE practices
                SET practice_name = :practice_name
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'practice_name' => $practiceName,
                'id' => $practiceId
            ]);
        }
    } else {
        $practiceUuid = uniqid('practice_', true);
        
        $stmt = $pdo->prepare("
            INSERT INTO practices (practice_id, practice_name, created_by)
            VALUES (:practice_uuid, :practice_name, :created_by)
        ");
        $result = $stmt->execute([
            'practice_uuid' => $practiceUuid,
            'practice_name' => $practiceName,
            'created_by' => $userId
        ]);
        
        $practiceId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("
            INSERT INTO practice_users (practice_id, user_id, role, is_owner)
            VALUES (:practice_id, :user_id, 'admin', TRUE)
        ");
        $stmt->execute([
            'practice_id' => $practiceId,
            'user_id' => $userId
        ]);

        // CRITICAL: Set the session to the newly created practice IMMEDIATELY
        // This ensures subsequent requests are scoped to this practice
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['needs_practice_setup'] = false;
        $_SESSION['needs_practice_selection'] = false;
        
        // Log the practice creation for security audit
        error_log("[SECURITY] New practice created: practice_id={$practiceId}, user_id={$userId}, name={$practiceName}");

        try {
            getPracticeRootFolder($practiceId);
        } catch (Exception $e) {
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Get the updated practice data
    $stmt = $pdo->prepare("
        SELECT id, practice_id as uuid, practice_name
        FROM practices
        WHERE id = :id
    ");
    $stmt->execute(['id' => $practiceId]);
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Return the practice ID in the response so the frontend can verify
    echo json_encode([
        'success' => true,
        'message' => $practiceId ? 'Practice updated successfully' : 'Practice created successfully',
        'practice' => $practice,
        'current_practice_id' => $_SESSION['current_practice_id'] // Include for verification
    ]);
    
} catch (PDOException $e) {
    // Roll back transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error updating practice: ' . $e->getMessage()
    ]);
    
    userLog("Error updating practice: " . $e->getMessage(), true);
}
