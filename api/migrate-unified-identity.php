<?php
/**
 * Migration Script: Add Unified Identity Columns to Users Table
 * 
 * This script adds the auth_method, password_hash, and email_verified columns
 * to the users table to support the unified identity system.
 * 
 * Run this script once in production to update the database schema.
 */

require_once __DIR__ . '/appConfig.php';

header('Content-Type: text/plain');

if (!$pdo) {
    die("ERROR: Database connection not available\n");
}

echo "=== Unified Identity Migration ===\n\n";

$migrations = [
    [
        'name' => 'Add auth_method column',
        'check' => "SHOW COLUMNS FROM users LIKE 'auth_method'",
        'sql' => "ALTER TABLE users ADD COLUMN auth_method ENUM('google', 'email', 'both') NOT NULL DEFAULT 'google' AFTER role"
    ],
    [
        'name' => 'Add password_hash column',
        'check' => "SHOW COLUMNS FROM users LIKE 'password_hash'",
        'sql' => "ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER auth_method"
    ],
    [
        'name' => 'Add email_verified column',
        'check' => "SHOW COLUMNS FROM users LIKE 'email_verified'",
        'sql' => "ALTER TABLE users ADD COLUMN email_verified BOOLEAN NOT NULL DEFAULT TRUE AFTER password_hash"
    ],
    [
        'name' => 'Add last_login_at column',
        'check' => "SHOW COLUMNS FROM users LIKE 'last_login_at'",
        'sql' => "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL AFTER email_verified"
    ],
    [
        'name' => 'Create user_auth_methods table',
        'check' => "SHOW TABLES LIKE 'user_auth_methods'",
        'sql' => "CREATE TABLE IF NOT EXISTS user_auth_methods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            auth_type ENUM('google', 'email') NOT NULL,
            provider_id VARCHAR(255) DEFAULT NULL COMMENT 'Google sub ID for OAuth',
            password_hash VARCHAR(255) DEFAULT NULL COMMENT 'Bcrypt hash for email auth',
            email_verified BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            
            UNIQUE KEY unique_user_auth (user_id, auth_type),
            UNIQUE KEY unique_google_id (provider_id),
            INDEX idx_user_id (user_id),
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ],
    [
        'name' => 'Create password_setup_tokens table',
        'check' => "SHOW TABLES LIKE 'password_setup_tokens'",
        'sql' => "CREATE TABLE IF NOT EXISTS password_setup_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_token (token),
            INDEX idx_user_id (user_id),
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ]
];

$successCount = 0;
$skipCount = 0;
$errorCount = 0;

foreach ($migrations as $migration) {
    echo "Migration: {$migration['name']}\n";
    
    try {
        // Check if migration is needed
        $stmt = $pdo->query($migration['check']);
        $exists = $stmt->fetch();
        
        if ($exists) {
            echo "  -> SKIPPED (already exists)\n\n";
            $skipCount++;
            continue;
        }
        
        // Run migration
        $pdo->exec($migration['sql']);
        echo "  -> SUCCESS\n\n";
        $successCount++;
        
    } catch (PDOException $e) {
        echo "  -> ERROR: " . $e->getMessage() . "\n\n";
        $errorCount++;
    }
}

// Populate user_auth_methods for existing users
echo "Migration: Populate user_auth_methods for existing Google users\n";
try {
    $stmt = $pdo->query("SELECT id, google_id FROM users WHERE google_id IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO user_auth_methods (user_id, auth_type, provider_id, email_verified, last_used_at)
        VALUES (:user_id, 'google', :provider_id, 1, NOW())
    ");
    
    $populated = 0;
    foreach ($users as $user) {
        $insertStmt->execute([
            'user_id' => $user['id'],
            'provider_id' => $user['google_id']
        ]);
        if ($insertStmt->rowCount() > 0) {
            $populated++;
        }
    }
    
    echo "  -> SUCCESS (populated {$populated} records)\n\n";
    $successCount++;
    
} catch (PDOException $e) {
    echo "  -> ERROR: " . $e->getMessage() . "\n\n";
    $errorCount++;
}

echo "=== Migration Complete ===\n";
echo "Success: {$successCount}, Skipped: {$skipCount}, Errors: {$errorCount}\n";

if ($errorCount === 0) {
    echo "\nAll migrations completed successfully. You can now log in.\n";
} else {
    echo "\nSome migrations failed. Please check the errors above.\n";
}
