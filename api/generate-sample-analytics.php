<?php
/**
 * Generate Sample Analytics Data
 * Creates sample data directly in the database for testing analytics
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

$practiceId = $_SESSION['current_practice_id'] ?? 0;

echo "<h2>Generate Sample Analytics Data</h2>";
echo "<h3>Practice ID: $practiceId</h3>";

try {
    // Sample data
    $sampleCases = [
        // Active cases
        ['patient_first_name' => 'John', 'patient_last_name' => 'Smith', 'case_type' => 'Crown', 'status' => 'Originated', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-12-01', 'due_date' => '2024-12-15'],
        ['patient_first_name' => 'Mary', 'patient_last_name' => 'Jones', 'case_type' => 'Bridge', 'status' => 'Sent To External Lab', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-12-03', 'due_date' => '2024-12-17'],
        ['patient_first_name' => 'Robert', 'patient_last_name' => 'Brown', 'case_type' => 'Implant', 'status' => 'Designed', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-12-05', 'due_date' => '2024-12-19'],
        ['patient_first_name' => 'Lisa', 'patient_last_name' => 'Davis', 'case_type' => 'Crown', 'status' => 'Manufactured', 'assigned_to' => 'Dr. Wilson', 'creation_date' => '2024-12-07', 'due_date' => '2024-12-21'],
        ['patient_first_name' => 'Michael', 'patient_last_name' => 'Miller', 'case_type' => 'Bridge', 'status' => 'Received From External Lab', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-12-10', 'due_date' => '2024-12-24'],
        
        // Recently delivered cases
        ['patient_first_name' => 'Sarah', 'patient_last_name' => 'Taylor', 'case_type' => 'Crown', 'status' => 'Delivered', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-11-15', 'archived_date' => '2024-12-01'],
        ['patient_first_name' => 'David', 'patient_last_name' => 'Anderson', 'case_type' => 'Implant', 'status' => 'Delivered', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-11-20', 'archived_date' => '2024-12-05'],
        ['patient_first_name' => 'Jennifer', 'patient_last_name' => 'Thomas', 'case_type' => 'Bridge', 'status' => 'Delivered', 'assigned_to' => 'Dr. Wilson', 'creation_date' => '2024-11-25', 'archived_date' => '2024-12-08'],
        
        // Past due cases
        ['patient_first_name' => 'William', 'patient_last_name' => 'Jackson', 'case_type' => 'Crown', 'status' => 'Originated', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-11-01', 'due_date' => '2024-11-15'],
        ['patient_first_name' => 'Amanda', 'patient_last_name' => 'White', 'case_type' => 'Bridge', 'status' => 'Sent To External Lab', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-11-05', 'due_date' => '2024-11-20'],
        
        // Historical data for trends (past few months)
        ['patient_first_name' => 'James', 'patient_last_name' => 'Harris', 'case_type' => 'Crown', 'status' => 'Delivered', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-10-15', 'archived_date' => '2024-11-01'],
        ['patient_first_name' => 'Patricia', 'patient_last_name' => 'Martin', 'case_type' => 'Implant', 'status' => 'Delivered', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-10-20', 'archived_date' => '2024-11-05'],
        ['patient_first_name' => 'Christopher', 'patient_last_name' => 'Garcia', 'case_type' => 'Bridge', 'status' => 'Delivered', 'assigned_to' => 'Dr. Wilson', 'creation_date' => '2024-09-15', 'archived_date' => '2024-10-01'],
        ['patient_first_name' => 'Linda', 'patient_last_name' => 'Martinez', 'case_type' => 'Crown', 'status' => 'Delivered', 'assigned_to' => 'Dr. Johnson', 'creation_date' => '2024-09-20', 'archived_date' => '2024-10-05'],
        ['patient_first_name' => 'Daniel', 'patient_last_name' => 'Robinson', 'case_type' => 'Implant', 'status' => 'Delivered', 'assigned_to' => 'Dr. Smith', 'creation_date' => '2024-08-15', 'archived_date' => '2024-09-01'],
    ];
    
    // Clear existing test data for this practice
    $stmt = $pdo->prepare("DELETE FROM cases_cache WHERE practice_id = ? AND patient_first_name LIKE 'Test%'");
    $stmt->execute([$practiceId]);
    
    // Insert sample cases
    $inserted = 0;
    foreach ($sampleCases as $case) {
        $caseId = 'CASE-' . strtoupper(uniqid());
        $driveFolderId = 'FOLDER-' . strtoupper(uniqid());
        
        $stmt = $pdo->prepare("
            INSERT INTO cases_cache (
                case_id, drive_folder_id, patient_first_name, patient_last_name,
                case_type, status, assigned_to, creation_date, due_date,
                archived_date, notes, practice_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $caseId,
            $driveFolderId,
            $case['patient_first_name'],
            $case['patient_last_name'],
            $case['case_type'],
            $case['status'],
            $case['assigned_to'],
            $case['creation_date'],
            $case['due_date'] ?? null,
            $case['archived_date'] ?? null,
            'Sample case for analytics testing',
            $practiceId
        ]);
        
        if ($result) {
            $inserted++;
        }
    }
    
    echo "<h3>✅ Successfully inserted $inserted sample cases</h3>";
    
    // Verify data
    echo "<h3>Verification:</h3>";
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM cases_cache WHERE practice_id = ?");
    $stmt->execute([$practiceId]);
    $total = $stmt->fetchColumn();
    echo "Total cases for practice $practiceId: $total<br>";
    
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM cases_cache WHERE practice_id = ? GROUP BY status");
    $stmt->execute([$practiceId]);
    $statusData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Status breakdown:<br>";
    foreach ($statusData as $row) {
        echo "- {$row['status']}: {$row['count']}<br>";
    }
    
    echo "<br><a href='../main.php'>← Back to Application</a>";
    echo "<br><br><button onclick='window.location.href=\"../api/test-analytics-data.php\"'>Test Analytics Data</button>";
    
} catch (Exception $e) {
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
