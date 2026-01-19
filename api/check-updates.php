<?php
/**
 * Check Updates API Endpoint
 * 
 * Lightweight polling endpoint that returns case updates since a given timestamp.
 * Used for real-time updates without requiring SSE or WebSockets.
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/encryption.php';

// Disable error display for API
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// SECURITY: Require valid practice context
$currentPracticeId = requireValidPracticeContext();

if (!$currentPracticeId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get the last check timestamp from query parameter
$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

// Get current user info for limited visibility check
$currentUserEmail = $_SESSION['user_email'] ?? '';
$hasLimitedVisibility = false;

// Check if user has limited visibility
if ($currentUserEmail && $currentPracticeId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT limited_visibility 
            FROM practice_users pu
            JOIN users u ON pu.user_id = u.id
            WHERE pu.practice_id = :practice_id AND u.email = :email
        ");
        $stmt->execute([
            'practice_id' => $currentPracticeId,
            'email' => $currentUserEmail
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $hasLimitedVisibility = $row && (bool)$row['limited_visibility'];
    } catch (PDOException $e) {
        // Ignore errors, assume no limited visibility
    }
}

try {
    // Ensure the case_updates table exists
    ensureCaseUpdatesTable();
    
    global $pdo;
    
    // Get updates since the given timestamp for this practice
    $stmt = $pdo->prepare("
        SELECT cu.*, cc.case_id, cc.patient_first_name, cc.patient_last_name, 
               cc.status, cc.assigned_to, cc.due_date, cc.case_type, cc.dentist_name,
               cc.tooth_shade, cc.material, cc.notes, cc.version,
               cc.patient_dob, cc.patient_gender, cc.drive_folder_id,
               cc.clinical_details_json, cc.attachments_json, cc.revisions_json,
               cc.creation_date, cc.last_update_date, cc.status_changed_at,
               cc.revision_count
        FROM case_updates cu
        JOIN cases_cache cc ON cu.case_id = cc.case_id
        WHERE cu.practice_id = :practice_id 
          AND cu.updated_at > FROM_UNIXTIME(:since)
        ORDER BY cu.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute([
        'practice_id' => $currentPracticeId,
        'since' => $since
    ]);
    
    $updates = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // For limited visibility users, only include cases assigned to them
        if ($hasLimitedVisibility && $row['assigned_to'] !== $currentUserEmail) {
            // Check if this was previously assigned to them (they need to know it was removed)
            if ($row['update_type'] === 'assignment' && $row['previous_assigned_to'] === $currentUserEmail) {
                // Include this update so they know the case was unassigned from them
            } else {
                continue; // Skip updates for cases not assigned to this user
            }
        }
        
        // Build case data object
        $caseData = [
            'id' => $row['case_id'],
            'patientFirstName' => $row['patient_first_name'],
            'patientLastName' => $row['patient_last_name'],
            'patientDOB' => $row['patient_dob'],
            'patientGender' => $row['patient_gender'],
            'dentistName' => $row['dentist_name'],
            'caseType' => $row['case_type'],
            'toothShade' => $row['tooth_shade'],
            'material' => $row['material'],
            'dueDate' => $row['due_date'],
            'status' => $row['status'],
            'assignedTo' => $row['assigned_to'],
            'notes' => $row['notes'],
            'version' => (int)($row['version'] ?? 1),
            'driveFolderId' => $row['drive_folder_id'],
            'creationDate' => $row['creation_date'],
            'lastUpdateDate' => $row['last_update_date'],
            'statusChangedAt' => $row['status_changed_at'],
            'revisionCount' => (int)($row['revision_count'] ?? 0),
        ];
        
        // Parse JSON fields
        if (!empty($row['clinical_details_json'])) {
            $caseData['clinicalDetails'] = json_decode($row['clinical_details_json'], true) ?: [];
        }
        if (!empty($row['attachments_json'])) {
            $caseData['attachments'] = json_decode($row['attachments_json'], true) ?: [];
        }
        if (!empty($row['revisions_json'])) {
            $caseData['revisions'] = json_decode($row['revisions_json'], true) ?: [];
        }
        
        // Decrypt PII fields
        if (class_exists('PIIEncryption')) {
            $caseData = PIIEncryption::decryptCaseData($caseData);
        }
        
        $updates[] = [
            'type' => $row['update_type'],
            'caseId' => $row['case_id'],
            'updatedBy' => $row['updated_by'],
            'updatedAt' => strtotime($row['updated_at']),
            'caseData' => $caseData
        ];
    }
    
    // Return current server timestamp for next poll
    echo json_encode([
        'success' => true,
        'updates' => $updates,
        'serverTime' => time()
    ]);
    
} catch (PDOException $e) {
    error_log('check-updates.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => true,
        'updates' => [],
        'serverTime' => time()
    ]);
}

/**
 * Ensure the case_updates table exists
 */
function ensureCaseUpdatesTable() {
    global $pdo;
    static $initialized = false;
    
    if ($initialized || !$pdo) {
        return;
    }
    
    $sql = "CREATE TABLE IF NOT EXISTS case_updates (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL,
        practice_id INT UNSIGNED NOT NULL,
        update_type ENUM('create', 'update', 'status', 'assignment', 'delete') NOT NULL,
        updated_by VARCHAR(255) DEFAULT NULL,
        previous_status VARCHAR(100) DEFAULT NULL,
        previous_assigned_to VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_practice_updated (practice_id, updated_at),
        INDEX idx_case_id (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    try {
        $pdo->exec($sql);
        $initialized = true;
        
        // Clean up old updates (older than 1 hour) to keep table small
        $pdo->exec("DELETE FROM case_updates WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    } catch (PDOException $e) {
        error_log('Failed to create case_updates table: ' . $e->getMessage());
    }
}
