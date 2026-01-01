<?php
/**
 * Database Structure Checker
 * Helps identify the correct column names for analytics
 */

require_once __DIR__ . '/session.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    die("User not authenticated");
}

// Load configuration
require_once __DIR__ . '/appConfig.php';

echo "<h2>Database Structure Analysis</h2>";

try {
    // Check if cases_cache table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'cases_cache'");
    $tableExists = $stmt->rowCount() > 0;
    
    echo "<h3>Tables:</h3>";
    if ($tableExists) {
        echo "✅ cases_cache table exists<br>";
    } else {
        echo "❌ cases_cache table NOT found<br>";
        
        // Check for alternative table names
        $stmt = $pdo->query("SHOW TABLES LIKE '%case%'");
        echo "<h4>Tables containing 'case':</h4>";
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            echo "- " . $row[0] . "<br>";
        }
    }
    
    if ($tableExists) {
        // Get column structure
        echo "<h3>cases_cache table structure:</h3>";
        $stmt = $pdo->query("DESCRIBE cases_cache");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        
        $dateColumns = [];
        $relevantColumns = [];
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "</tr>";
            
            // Identify date columns
            if (strpos(strtolower($column['Type']), 'date') !== false || 
                strpos(strtolower($column['Field']), 'date') !== false ||
                strpos(strtolower($column['Field']), 'time') !== false) {
                $dateColumns[] = $column['Field'];
            }
            
            // Identify relevant columns
            if (in_array(strtolower($column['Field']), ['status', 'case_type', 'assigned_to', 'due_date', 'practice_id'])) {
                $relevantColumns[] = $column['Field'];
            }
        }
        echo "</table>";
        
        echo "<h4>Date-related columns found:</h4>";
        foreach ($dateColumns as $col) {
            echo "- " . $col . "<br>";
        }
        
        echo "<h4>Other relevant columns:</h4>";
        foreach ($relevantColumns as $col) {
            echo "- " . $col . "<br>";
        }
        
        // Check sample data
        echo "<h3>Sample data (first 3 rows):</h3>";
        $stmt = $pdo->query("SELECT * FROM cases_cache LIMIT 3");
        $sampleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($sampleData)) {
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr>";
            foreach (array_keys($sampleData[0]) as $key) {
                echo "<th>" . htmlspecialchars($key) . "</th>";
            }
            echo "</tr>";
            
            foreach ($sampleData as $row) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "No data found in cases_cache table<br>";
        }
    }
    
    // Check if user_preferences table exists
    echo "<h3>user_preferences table:</h3>";
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_preferences'");
    if ($stmt->rowCount() > 0) {
        echo "✅ user_preferences table exists<br>";
        $stmt = $pdo->query("DESCRIBE user_preferences");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ user_preferences table NOT found<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "<br>";
}
?>
