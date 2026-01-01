<?php
/**
 * Database Reset Script (DEVELOPMENT ONLY)
 * 
 * This script deletes all records from all tables but preserves the table structure.
 * IMPORTANT: This script will only work in the development environment!
 */

// Set display errors for direct script execution
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load app configuration
require_once 'appConfig.php';

// Add some basic styling
echo "
<style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
    h1 { color: #2c3e50; }
    h2 { color: #3498db; margin-top: 30px; }
    p { margin: 10px 0; }
    .success { color: #27ae60; }
    .error { color: #e74c3c; font-weight: bold; }
    .warning { color: #f39c12; font-weight: bold; }
    .info { color: #3498db; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 4px; }
    .button { 
        display: inline-block;
        background: #e74c3c;
        color: white;
        padding: 10px 15px;
        text-decoration: none;
        border-radius: 4px;
        margin: 20px 0;
    }
    .button.confirm {
        background: #e74c3c;
    }
    .button.cancel {
        background: #7f8c8d;
        margin-right: 10px;
    }
</style>
";

echo "<h1>Database Reset Tool</h1>";

// SAFETY CHECK: Ensure this is the development environment
if ($appConfig !== $appConfigDevelopment) {
    die("<p class='error'>⛔ CRITICAL ERROR: This script can only be run in the development environment!</p>
        <p>The current environment appears to be production. This script has been blocked for safety.</p>");
}

// Additional verification: Check the database name as extra protection
if ($appConfig['db_name'] !== 'dental_case_tracker' || $appConfig['db_host'] !== 'localhost') {
    die("<p class='error'>⛔ CRITICAL ERROR: This script can only run against the local development database!</p>
        <p>Current database: {$appConfig['db_name']} on {$appConfig['db_host']}</p>
        <p>Expected: dental_case_tracker on localhost</p>");
}

// Check if confirmation parameter is set
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo "<p class='warning'>⚠️ WARNING: This will delete ALL data from ALL tables in your database!</p>";
    echo "<p>Database: <b>{$appConfig['db_name']}</b> on <b>{$appConfig['db_host']}</b></p>";
    echo "<p>Tables will be preserved, but all records will be permanently deleted.</p>";
    echo "<p>Are you sure you want to proceed?</p>";
    
    // Provide buttons for confirmation
    echo "<a href='../login.php' class='button cancel'>Cancel</a>";
    echo "<a href='reset-database.php?confirm=yes' class='button confirm'>Yes, Reset Database</a>";
    exit;
}

// If we get here, user has confirmed and we're in development environment
try {
    // Check if database connection exists
    if (!$pdo) {
        throw new PDOException("No valid database connection found");
    }

    echo "<h2>Resetting Database: {$appConfig['db_name']}</h2>";
    
    // Disable foreign key checks to avoid constraint errors
    $pdo->exec("SET foreign_key_checks = 0");
    echo "<p class='info'>Disabled foreign key checks for clean deletion</p>";
    
    // Get all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) === 0) {
        echo "<p class='warning'>No tables found in the database. Nothing to reset.</p>";
    } else {
        echo "<p>Found " . count($tables) . " tables to reset:</p>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>" . htmlspecialchars($table) . "</li>";
        }
        echo "</ul>";
        
        // Truncate all tables
        foreach ($tables as $table) {
            $pdo->exec("TRUNCATE TABLE `" . $table . "`");
            echo "<p class='success'>✓ Truncated table: " . htmlspecialchars($table) . "</p>";
        }
    }
    
    // Re-enable foreign key checks
    $pdo->exec("SET foreign_key_checks = 1");
    echo "<p class='info'>Re-enabled foreign key checks</p>";
    
    echo "<h2>Database Reset Complete</h2>";
    echo "<p class='success'>All data has been deleted from the database. The table structure remains intact.</p>";
    echo "<p>You can now start with a clean database.</p>";
    
    // Link to return to homepage
    echo "<p><a href='../login.php' style='color: #3498db;'>Return to Login</a></p>";
    
} catch (PDOException $e) {
    echo "<p class='error'>Database reset failed: " . $e->getMessage() . "</p>";
    
    // Re-enable foreign key checks in case of error
    try {
        if ($pdo) {
            $pdo->exec("SET foreign_key_checks = 1");
            echo "<p class='info'>Re-enabled foreign key checks after error</p>";
        }
    } catch (Exception $innerEx) {
        echo "<p class='error'>Additional error: " . $innerEx->getMessage() . "</p>";
    }
    
    // Add some diagnostic info
    echo "<h3>Diagnostic Information:</h3>";
    echo "<pre>";
    echo "Database Host: " . $appConfig['db_host'] . "\n";
    echo "Database Name: " . $appConfig['db_name'] . "\n";
    echo "Database User: " . $appConfig['db_user'] . "\n";
    echo "Database Port: " . ($appConfig['db_port'] ?? 'default') . "\n";
    echo "</pre>";
}
