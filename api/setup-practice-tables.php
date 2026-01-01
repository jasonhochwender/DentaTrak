<?php
/**
 * Setup Practice Tables Script
 * Creates the necessary tables for dental practices and user assignments
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

// Check if tables already exist
try {
    // Make sure to use InnoDB engine for foreign key support
    $pdo->exec("SET foreign_key_checks = 0");
    
    // Clean up any incomplete tables first
    $pdo->exec("DROP TABLE IF EXISTS practice_users");
    $pdo->exec("DROP TABLE IF EXISTS practices");
    
    $pdo->exec("SET foreign_key_checks = 1");
    
    // First, check if users table exists and its ID column type
    $stmt = $pdo->query("DESCRIBE users");
    $usersColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $userIdType = 'INT'; // Default assumption
    
    // Find the id column and get its type
    foreach ($usersColumns as $column) {
        if ($column['Field'] === 'id') {
            $userIdType = strtoupper($column['Type']);
            break;
        }
    }
    
    // Clean up the type string (e.g., "INT(11)" becomes "INT")
    if (strpos($userIdType, '(') !== false) {
        $userIdType = substr($userIdType, 0, strpos($userIdType, '('));
    }
    
    userLog("Detected user ID column type: {$userIdType}", false);
    
    // Create practices table using the correct data type
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS practices (
            id {$userIdType} AUTO_INCREMENT PRIMARY KEY,
            practice_id VARCHAR(36) NOT NULL COMMENT 'UUID for the practice',
            practice_name VARCHAR(255) NOT NULL,
            drive_root_id VARCHAR(128) DEFAULT NULL,
            created_by {$userIdType} NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (practice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Add foreign key after table creation to avoid issues
    $pdo->exec("
        ALTER TABLE practices 
        ADD CONSTRAINT fk_practices_created_by 
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ");
    
    // Create practice_users table with matching data types
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS practice_users (
            id {$userIdType} AUTO_INCREMENT PRIMARY KEY,
            practice_id {$userIdType} NOT NULL,
            user_id {$userIdType} NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            is_owner BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (practice_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // Add foreign keys separately
    $pdo->exec("
        ALTER TABLE practice_users
        ADD CONSTRAINT fk_practice_users_practice_id
        FOREIGN KEY (practice_id) REFERENCES practices(id) ON DELETE CASCADE,
        ADD CONSTRAINT fk_practice_users_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ");
    
    echo "Practice tables created successfully!";
} catch (PDOException $e) {
    // Check if there's a problem with existing tables
    if (strpos($e->getMessage(), 'already exists') !== false) {
        // Tables exist but might have wrong structure, try to fix
        try {
            // Drop the tables in reverse order due to foreign key constraints
            $pdo->exec("DROP TABLE IF EXISTS practice_users");
            $pdo->exec("DROP TABLE IF EXISTS practices");
            
            echo "Dropped existing tables with incorrect structure. Refreshing the page will recreate them properly.";
            userLog("Dropped practice tables with incorrect structure for recreation", true);
        } catch (PDOException $dropError) {
            echo "Error dropping existing tables: " . $dropError->getMessage();
            userLog("Error dropping practice tables: " . $dropError->getMessage(), true);
        }
    } else {
        echo "Error setting up practice tables: " . $e->getMessage();
        userLog("Error setting up practice tables: " . $e->getMessage(), true);
        
        // Additional diagnostics
        try {
            // Check users table structure
            $stmt = $pdo->query("SHOW CREATE TABLE users");
            $userTableDef = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $diagError) {
            userLog("Diagnostic error: " . $diagError->getMessage(), true);
        }
    }
}
