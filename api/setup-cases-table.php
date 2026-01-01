<?php
/**
 * Setup Cases Table Script
 * Creates the cases table needed for the application
 */

require_once __DIR__ . '/appConfig.php';

echo "<h1>Setting Up Cases Table</h1>";

try {
    echo "<p>Creating cases table...</p>";
    
    // Create cases table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cases (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            patient_name VARCHAR(255) NOT NULL,
            case_type VARCHAR(100) NOT NULL,
            status ENUM('Originated', 'Sent To External Lab', 'Designed', 'Manufactured', 'Received From External Lab', 'Delivered') NOT NULL DEFAULT 'Originated',
            assigned_to VARCHAR(255) DEFAULT NULL,
            created_at DATE NOT NULL,
            due_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            google_drive_folder_id VARCHAR(255) DEFAULT NULL,
            practice_id INT UNSIGNED DEFAULT NULL,
            user_id INT UNSIGNED NOT NULL,
            archived BOOLEAN NOT NULL DEFAULT FALSE,
            created_at_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_user_id (user_id),
            INDEX idx_practice_id (practice_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_assigned_to (assigned_to),
            INDEX idx_archived (archived),
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (practice_id) REFERENCES practices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "<p class='success'>✓ Cases table created successfully!</p>";
    
    // Check if table exists and show structure
    $result = $pdo->query("DESCRIBE cases");
    echo "<h2>Table Structure:</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>Setup Complete!</h2>";
    echo "<p>The cases table is now ready for use. You can now:</p>";
    echo "<ul>";
    echo "<li>Create new cases</li>";
    echo "<li>Delete all cases from dev tools</li>";
    echo "<li>Generate test cases for analytics</li>";
    echo "</ul>";
    echo "<p><a href='../main.php'>Return to Application</a></p>";
    
} catch (PDOException $e) {
    echo "<p class='error'>✗ Error creating cases table: " . htmlspecialchars($e->getMessage()) . "</p>";
    error_log('Error creating cases table: ' . $e->getMessage());
}
?>

<style>
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
table { border-collapse: collapse; margin: 20px 0; }
th { background-color: #f0f0f0; font-weight: bold; }
</style>
