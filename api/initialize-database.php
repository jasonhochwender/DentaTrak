<?php
/**
 * Complete Database Initialization Script
 * 
 * This script:
 * 1. Creates the database if it doesn't exist
 * 2. Creates all tables needed for the application
 * 3. Shows detailed progress of what's happening
 */

// Set display errors for direct script execution
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load app configuration
require_once 'appConfig.php';

echo "<h1>Database Initialization</h1>";
echo "<p>Starting database setup process...</p>";

// Verify we're connecting with correct credentials
echo "<p>Using database connection: {$appConfig['db_host']}, User: {$appConfig['db_user']}, Database: {$appConfig['db_name']}</p>";

// Connect to MySQL without specifying a database
try {
    echo "<h2>Step 1: Connecting to MySQL</h2>";
    
    $rootPdo = new PDO(
        "mysql:host={$appConfig['db_host']}" . 
        (!empty($appConfig['db_port']) ? ";port={$appConfig['db_port']}" : ""),
        $appConfig['db_user'],
        $appConfig['db_password']
    );
    $rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ Connected to MySQL server successfully.</p>";
    
    // Create database if it doesn't exist
    echo "<h2>Step 2: Creating Database</h2>";
    $dbName = $appConfig['db_name'];
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "<p class='success'>✓ Database '$dbName' created or already exists.</p>";
    
    // Switch to the new database
    $rootPdo->exec("USE `$dbName`");
    echo "<p class='success'>✓ Now using database '$dbName'.</p>";
    
    // Create user management tables
    echo "<h2>Step 3: Creating User Management Tables</h2>";
    
    // Users table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            google_id VARCHAR(255) UNIQUE,
            password_hash VARCHAR(255) NULL COMMENT 'Bcrypt hashed password for email/password auth',
            auth_method ENUM('google', 'email', 'both') NOT NULL DEFAULT 'google' COMMENT 'How user authenticates',
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            profile_picture TEXT,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            email_verified BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Whether email has been verified for email auth',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login_at TIMESTAMP NULL,
            
            INDEX idx_email (email),
            INDEX idx_google_id (google_id)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Users table created.</p>";
    
    // Password reset tokens table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NOT NULL,
            used BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Password reset tokens table created.</p>";
    
    // Sessions table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            session_token VARCHAR(255) NOT NULL UNIQUE,
            ip_address VARCHAR(45),
            user_agent TEXT,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_session_token (session_token),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Sessions table created.</p>";
    
    // User preferences table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INT UNSIGNED PRIMARY KEY,
            theme ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'light',
            allow_card_delete BOOLEAN NOT NULL DEFAULT TRUE,
            highlight_past_due BOOLEAN NOT NULL DEFAULT TRUE,
            past_due_days INT UNSIGNED NOT NULL DEFAULT 7,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ User preferences table created.</p>";
    
    // Billing information table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS billing_information (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            plan_type ENUM('free', 'basic', 'premium', 'enterprise') NOT NULL DEFAULT 'free',
            billing_status ENUM('active', 'past_due', 'canceled', 'trial') NOT NULL DEFAULT 'trial',
            subscription_id VARCHAR(255),
            payment_method_id VARCHAR(255),
            card_last_four VARCHAR(4),
            card_brand VARCHAR(50),
            card_expiry_month TINYINT UNSIGNED,
            card_expiry_year SMALLINT UNSIGNED,
            trial_ends_at DATETIME NULL,
            subscription_ends_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_subscription_id (subscription_id),
            INDEX idx_billing_status (billing_status)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Billing information table created.</p>";
    
    // Billing transactions table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS billing_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            billing_info_id INT UNSIGNED NOT NULL,
            transaction_id VARCHAR(255) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            status ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL,
            transaction_type ENUM('charge', 'refund', 'credit') NOT NULL,
            description TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (billing_info_id) REFERENCES billing_information(id) ON DELETE CASCADE,
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Billing transactions table created.</p>";
    
    // User activity log table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS user_activity_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            activity_type VARCHAR(50) NOT NULL,
            description TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_activity_type (activity_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ User activity log table created.</p>";
    
    // Case assignment table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS case_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(255) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_case_id (case_id),
            UNIQUE KEY unique_case_user (case_id, user_id)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Case assignments table created.</p>";
    
    // Gmail Users table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS gmail_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_email_per_user (user_id, email),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB
    ");
    echo "<p class='success'>✓ Gmail Users table created.</p>";
    
    // Create practice tables
    echo "<h2>Step 4: Creating Practice Management Tables</h2>";
    
    // Create practices table with BAA fields
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS practices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            practice_id VARCHAR(36) NOT NULL COMMENT 'UUID for the practice',
            practice_name VARCHAR(255) NOT NULL,
            legal_name VARCHAR(255) DEFAULT NULL COMMENT 'Immutable legal practice name set at BAA acceptance',
            display_name VARCHAR(255) DEFAULT NULL COMMENT 'Editable display name for UI',
            practice_address TEXT DEFAULT NULL COMMENT 'Practice address for BAA',
            logo_path VARCHAR(512) DEFAULT NULL COMMENT 'Path to practice logo',
            drive_root_id VARCHAR(128) DEFAULT NULL,
            baa_accepted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether BAA has been accepted',
            baa_accepted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When BAA was accepted',
            baa_version VARCHAR(50) DEFAULT NULL COMMENT 'Version of BAA that was accepted',
            baa_accepted_by_user_id INT UNSIGNED DEFAULT NULL COMMENT 'User ID who accepted the BAA',
            baa_signer_name VARCHAR(255) DEFAULT NULL COMMENT 'Name of authorized signer',
            baa_signer_title VARCHAR(255) DEFAULT NULL COMMENT 'Title of authorized signer',
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (practice_id),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (baa_accepted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p class='success'>✓ Practices table created (with BAA fields).</p>";
    
    // Create practice_users table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS practice_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            practice_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            is_owner BOOLEAN NOT NULL DEFAULT FALSE,
            limited_visibility BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'If true, user can only see cases assigned to them',
            can_view_analytics BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If true, user can view the analytics tab',
            can_edit_cases BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'If true, user can create and edit cases',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (practice_id, user_id),
            FOREIGN KEY (practice_id) REFERENCES practices(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p class='success'>✓ Practice users table created.</p>";

    // Create practice assignment labels table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS practice_assignment_labels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            practice_id INT UNSIGNED NOT NULL,
            label VARCHAR(255) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (practice_id) REFERENCES practices(id) ON DELETE CASCADE,
            INDEX idx_practice_id (practice_id),
            INDEX idx_practice_label (practice_id, label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p class='success'>✓ Practice assignment labels table created.</p>";
    
    echo "<h2>Database Initialization Complete</h2>";
    echo "<p class='success'>All tables have been created successfully! Your database is now ready to use.</p>";
    
    // Add some basic styling
    echo "
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #2c3e50; }
        h2 { color: #3498db; margin-top: 30px; }
        p { margin: 10px 0; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; font-weight: bold; }
    </style>
    ";
    
} catch (PDOException $e) {
    echo "<p class='error'>Database setup failed: " . $e->getMessage() . "</p>";
    
    // Add some diagnostic info
    echo "<h3>Diagnostic Information:</h3>";
    echo "<pre>";
    echo "Database Host: " . $appConfig['db_host'] . "\n";
    echo "Database Name: " . $appConfig['db_name'] . "\n";
    echo "Database User: " . $appConfig['db_user'] . "\n";
    echo "Database Port: " . ($appConfig['db_port'] ?? 'default') . "\n";
    echo "</pre>";
}
