<?php
/**
 * Waitlist API Endpoint
 * Stores email signups for pre-launch waitlist
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/security-headers.php';

setSecurityHeaders();

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email is required']);
    exit;
}

$email = trim($input['email']);

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
    exit;
}

// Sanitize email
$email = filter_var($email, FILTER_SANITIZE_EMAIL);

try {
    // Connect to database
    $dsn = "mysql:host={$appConfig['db_host']};dbname={$appConfig['db_name']};charset=utf8mb4";
    if (!empty($appConfig['db_port'])) {
        $dsn = "mysql:host={$appConfig['db_host']};port={$appConfig['db_port']};dbname={$appConfig['db_name']};charset=utf8mb4";
    }
    
    $pdo = new PDO($dsn, $appConfig['db_user'], $appConfig['db_password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create waitlist table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waitlist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email)
        ) ENGINE=InnoDB
    ");
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM waitlist WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        // Email already on waitlist - still return success (don't reveal if email exists)
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Insert new email
    $stmt = $pdo->prepare("INSERT INTO waitlist (email) VALUES (?)");
    $stmt->execute([$email]);
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    error_log('Waitlist signup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
