<?php
/**
 * Fix Case Status Script
 * Fixes cases with invalid 'New' status by changing them to 'Originated'
 * This addresses the issue where 'New' was being used as default status
 * but 'New' is not a valid ENUM value in the database.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';

// Check if user is logged in and is admin/super user
if (!isset($_SESSION['db_user_id'])) {
    die("Error: Authentication required. Please log in first.\n");
}

// Check if user has admin privileges or is super user
$isAdmin = false;
$isSuperUser = false;

try {
    // Check if super user
    require_once __DIR__ . '/dev-tools-access.php';
    global $appConfig;
    $userEmail = $_SESSION['user_email'] ?? '';
    $isSuperUser = isSuperUser($appConfig, $userEmail);
    
    // Check if practice admin
    $userId = $_SESSION['db_user_id'];
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT role FROM practice_users 
        WHERE user_id = :user_id AND practice_id = :practice_id
    ");
    $stmt->execute(['user_id' => $userId, 'practice_id' => $practiceId]);
    $role = $stmt->fetchColumn();
    $isAdmin = ($role === 'admin');
    
} catch (Exception $e) {
    die("Error checking permissions: " . $e->getMessage() . "\n");
}

if (!$isAdmin && !$isSuperUser) {
    die("Error: Admin privileges required to run this script.\n");
}

echo "<h1>Fix Case Status - Invalid 'New' Values</h1>";

try {
    // Check for cases with 'New' status in cases_cache table
    echo "<h2>Checking cases_cache table for invalid status values...</h2>";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE status = 'New'");
    $stmt->execute();
    $invalidCount = (int)$stmt->fetchColumn();
    
    if ($invalidCount > 0) {
        echo "<p style='color: orange;'>Found {$invalidCount} cases with invalid 'New' status</p>";
        
        // Show sample of affected cases
        $stmt = $pdo->prepare("
            SELECT case_id, patient_first_name, patient_last_name, status, creation_date 
            FROM cases_cache 
            WHERE status = 'New' 
            LIMIT 10
        ");
        $stmt->execute();
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Sample affected cases:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Case ID</th><th>Patient</th><th>Status</th><th>Created</th></tr>";
        
        foreach ($cases as $case) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($case['case_id']) . "</td>";
            echo "<td>" . htmlspecialchars($case['patient_first_name'] . ' ' . $case['patient_last_name']) . "</td>";
            echo "<td style='color: red;'>" . htmlspecialchars($case['status']) . "</td>";
            echo "<td>" . htmlspecialchars($case['creation_date']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Fix the invalid statuses
        echo "<h2>Fixing invalid status values...</h2>";
        
        $stmt = $pdo->prepare("
            UPDATE cases_cache 
            SET status = 'Originated', status_changed_at = NOW() 
            WHERE status = 'New'
        ");
        $result = $stmt->execute();
        
        if ($result) {
            echo "<p style='color: green;'>✓ Successfully fixed {$invalidCount} cases</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to fix cases</p>";
        }
        
    } else {
        echo "<p style='color: green;'>✓ No cases with invalid 'New' status found</p>";
    }
    
    // Check if there's also a 'cases' table that needs fixing
    echo "<h2>Checking cases table (if it exists)...</h2>";
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE status = 'New'");
        $stmt->execute();
        $invalidCasesCount = (int)$stmt->fetchColumn();
        
        if ($invalidCasesCount > 0) {
            echo "<p style='color: orange;'>Found {$invalidCasesCount} cases in 'cases' table with invalid 'New' status</p>";
            
            $stmt = $pdo->prepare("
                UPDATE cases 
                SET status = 'Originated' 
                WHERE status = 'New'
            ");
            $result = $stmt->execute();
            
            if ($result) {
                echo "<p style='color: green;'>✓ Successfully fixed {$invalidCasesCount} cases in 'cases' table</p>";
            } else {
                echo "<p style='color: red;'>✗ Failed to fix cases in 'cases' table</p>";
            }
        } else {
            echo "<p style='color: green;'>✓ No cases with invalid 'New' status found in 'cases' table</p>";
        }
    } catch (PDOException $e) {
        echo "<p style='color: gray;'>Note: 'cases' table doesn't exist or no access - skipping</p>";
    }
    
    echo "<h2>Verification</h2>";
    
    // Verify the fix
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE status = 'New'");
    $stmt->execute();
    $remainingInvalid = (int)$stmt->fetchColumn();
    
    if ($remainingInvalid === 0) {
        echo "<p style='color: green; font-weight: bold;'>✓ All invalid status values have been fixed!</p>";
    } else {
        echo "<p style='color: red;'>⚠ {$remainingInvalid} invalid status values still remain</p>";
    }
    
    echo "<h2>Current Status Distribution</h2>";
    
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM cases_cache 
        GROUP BY status 
        ORDER BY count DESC
    ");
    $stmt->execute();
    $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Status</th><th>Count</th></tr>";
    
    foreach ($statusCounts as $status) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($status['status']) . "</td>";
        echo "<td>" . $status['count'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p><a href='../main.php'>Return to Application</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    error_log('Error fixing case status: ' . $e->getMessage());
}
?>

<style>
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
.warning { color: orange; font-weight: bold; }
table { border-collapse: collapse; margin: 20px 0; }
th { background-color: #f0f0f0; font-weight: bold; }
</style>
