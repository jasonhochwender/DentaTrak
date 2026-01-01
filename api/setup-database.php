<?php
/**
 * Database Setup Script
 * 
 * This script creates the dental_case_tracker database and its tables
 * Run this file directly to initialize the database structure
 */

// Load app configuration
require_once 'appConfig.php';

// Connect to MySQL without specifying a database
try {
    $rootPdo = new PDO(
        "mysql:host={$appConfig['db_host']}" . 
        (!empty($appConfig['db_port']) ? ";port={$appConfig['db_port']}" : ""),
        $appConfig['db_user'],
        $appConfig['db_password']
    );
    $rootPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL server successfully.<br>";
    
    // Create database if it doesn't exist
    $dbName = $appConfig['db_name'];
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '$dbName' created or already exists.<br>";
    
    // Switch to the new database
    $rootPdo->exec("USE `$dbName`");
    echo "Now using database '$dbName'.<br>";
    
    // Create tables
    echo "Creating tables...<br>";
    
    // Users table
    $rootPdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            google_id VARCHAR(255) UNIQUE,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            profile_picture TEXT,
            role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login_at TIMESTAMP NULL,
            
            INDEX idx_email (email),
            INDEX idx_google_id (google_id)
        ) ENGINE=InnoDB
    ");
    echo "- Users table created.<br>";
    
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
    echo "- Sessions table created.<br>";
    
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
    echo "- User preferences table created.<br>";
    
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
    echo "- Billing information table created.<br>";
    
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
    echo "- Billing transactions table created.<br>";
    
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
    echo "- User activity log table created.<br>";
    
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
    echo "- Case assignments table created.<br>";
    
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
    echo "- Gmail Users table created.<br>";
    
    echo "<br>Database setup completed successfully!";
    
} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}
