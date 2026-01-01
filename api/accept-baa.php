<?php
/**
 * Accept BAA (Business Associate Agreement) API Endpoint
 * 
 * This endpoint handles the acceptance of the BAA for a practice.
 * It records all required fields and marks the practice as having accepted the BAA.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/csrf.php';

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
$baaVersion = 'v1.0-2025-12-18';

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
    
    // Check if user has a practice or needs to create one
    $practiceId = $_SESSION['current_practice_id'] ?? null;
    
    if (!$practiceId) {
        // User doesn't have a practice yet - create one
        $practiceUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $stmt = $pdo->prepare("
            INSERT INTO practices (
                practice_id, practice_name, legal_name, display_name, practice_address,
                baa_accepted, baa_accepted_at, baa_version, baa_accepted_by_user_id,
                baa_signer_name, baa_signer_title, created_by
            ) VALUES (
                :practice_uuid, :practice_name, :legal_name, :display_name, :practice_address,
                1, NOW(), :baa_version, :user_id,
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
            'created_by' => $userId
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
        
        // Update session
        $_SESSION['current_practice_id'] = $practiceId;
        $_SESSION['practice_name'] = $legalName;
        $_SESSION['needs_practice_setup'] = false;
        $_SESSION['needs_baa_acceptance'] = false;
        
        userLog("Created new practice with BAA acceptance: {$legalName} (ID: {$practiceId})", false);
        
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
