<?php
/**
 * Database Migration Script for Analytics
 * Fixes missing columns and creates necessary tables
 */

require_once __DIR__ . '/appConfig.php';

echo "<h2>Analytics Database Migration</h2>";

try {
    // Start transaction
    $pdo->beginTransaction();
    
    echo "<h3>Step 1: Check and fix cases_cache table</h3>";
    
    // Check if cases_cache exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'cases_cache'");
    if ($stmt->rowCount() == 0) {
        echo "❌ cases_cache table not found. Creating it from cases table...<br>";
        
        // Create cases_cache as a copy of cases if it doesn't exist
        $pdo->exec("
            CREATE TABLE cases_cache AS 
            SELECT * FROM cases WHERE 1=0
        ");
        
        // Copy data from cases to cases_cache
        $pdo->exec("
            INSERT INTO cases_cache 
            SELECT * FROM cases
        ");
        
        echo "✅ Created cases_cache table and copied data<br>";
    } else {
        echo "✅ cases_cache table exists<br>";
    }
    
    // Check for missing columns in cases_cache
    echo "<h3>Step 2: Adding missing columns to cases_cache</h3>";
    
    $requiredColumns = [
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'delivered_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'due_date' => 'DATE NULL DEFAULT NULL',
        'practice_id' => 'INT NOT NULL DEFAULT 1'
    ];
    
    $stmt = $pdo->query("DESCRIBE cases_cache");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    foreach ($requiredColumns as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            echo "Adding column: $column<br>";
            $pdo->exec("ALTER TABLE cases_cache ADD COLUMN $column $definition");
            echo "✅ Added $column<br>";
        } else {
            echo "✅ $column already exists<br>";
        }
    }
    
    // Update data if needed
    echo "<h3>Step 3: Updating case data</h3>";
    
    // Set created_at for existing records if null
    $pdo->exec("
        UPDATE cases_cache 
        SET created_at = NOW() 
        WHERE created_at IS NULL
    ");
    echo "✅ Updated created_at for existing records<br>";
    
    // Set delivered_at for delivered cases if null
    $pdo->exec("
        UPDATE cases_cache 
        SET delivered_at = updated_at 
        WHERE status = 'Delivered' AND delivered_at IS NULL AND updated_at IS NOT NULL
    ");
    echo "✅ Updated delivered_at for delivered cases<br>";
    
    echo "<h3>Step 4: Create user_preferences table</h3>";
    
    // Check if user_preferences exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_preferences'");
    if ($stmt->rowCount() == 0) {
        echo "Creating user_preferences table...<br>";
        $pdo->exec("
            CREATE TABLE user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                dev_environment VARCHAR(20) DEFAULT 'development',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "✅ Created user_preferences table<br>";
    } else {
        // Check if dev_environment column exists
        $stmt = $pdo->query("DESCRIBE user_preferences");
        $userPrefColumns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        if (!in_array('dev_environment', $userPrefColumns)) {
            echo "Adding dev_environment column to user_preferences...<br>";
            $pdo->exec("ALTER TABLE user_preferences ADD COLUMN dev_environment VARCHAR(20) DEFAULT 'development'");
            echo "✅ Added dev_environment column<br>";
        } else {
            echo "✅ dev_environment column already exists<br>";
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    echo "<h3>✅ Migration completed successfully!</h3>";
    
    // Test the analytics query
    echo "<h3>Step 5: Testing analytics queries</h3>";
    
    $testQueries = [
        "Total Active Cases" => "SELECT COUNT(*) as count FROM cases_cache WHERE practice_id = 1 AND status != 'Delivered'",
        "Total Archived Cases" => "SELECT COUNT(*) as count FROM cases_cache WHERE practice_id = 1 AND status = 'Delivered'",
        "Cases This Month" => "SELECT COUNT(*) as count FROM cases_cache WHERE practice_id = 1 AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())"
    ];
    
    foreach ($testQueries as $name => $query) {
        try {
            $stmt = $pdo->query($query);
            $result = $stmt->fetchColumn();
            echo "✅ $name: $result<br>";
        } catch (Exception $e) {
            echo "❌ $name failed: " . $e->getMessage() . "<br>";
        }
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Migration failed: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
