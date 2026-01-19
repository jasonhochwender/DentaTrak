<?php
require_once __DIR__ . '/appConfig.php';

function ensureCasesCacheTable() {
    global $pdo;
    static $initialized = false;
    if ($initialized) {
        return;
    }
    if (!$pdo) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS cases_cache (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        case_id VARCHAR(64) NOT NULL UNIQUE,
        drive_folder_id VARCHAR(128) DEFAULT NULL,
        backup_folder_id VARCHAR(128) DEFAULT NULL,
        patient_first_name VARCHAR(255) DEFAULT NULL,
        patient_last_name VARCHAR(255) DEFAULT NULL,
        patient_dob VARCHAR(500) DEFAULT NULL,
        patient_gender VARCHAR(20) DEFAULT NULL,
        dentist_name VARCHAR(255) DEFAULT NULL,
        case_type VARCHAR(100) DEFAULT NULL,
        tooth_shade VARCHAR(50) DEFAULT NULL,
        material VARCHAR(100) DEFAULT NULL,
        due_date VARCHAR(50) DEFAULT NULL,
        creation_date VARCHAR(50) DEFAULT NULL,
        last_update_date VARCHAR(50) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        status_changed_at DATETIME DEFAULT NULL,
        notes TEXT,
        assigned_to VARCHAR(255) DEFAULT NULL,
        attachments_json LONGTEXT,
        revisions_json LONGTEXT,
        clinical_details_json LONGTEXT,
        archived BOOLEAN DEFAULT FALSE,
        archived_date VARCHAR(50) DEFAULT NULL,
        practice_id INT UNSIGNED DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_due_date (due_date),
        INDEX idx_archived (archived),
        INDEX idx_practice_id (practice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $pdo->exec($sql);
        $initialized = true;
        
        // Add missing columns if they don't exist (for existing installations)
        $alterSqls = [
            "ALTER TABLE cases_cache ADD COLUMN archived BOOLEAN DEFAULT FALSE",
            "ALTER TABLE cases_cache ADD COLUMN archived_date VARCHAR(50) DEFAULT NULL", 
            "ALTER TABLE cases_cache ADD COLUMN practice_id INT UNSIGNED DEFAULT NULL",
            "ALTER TABLE cases_cache ADD COLUMN backup_folder_id VARCHAR(128) DEFAULT NULL",
            "ALTER TABLE cases_cache ADD COLUMN status_changed_at DATETIME DEFAULT NULL",
            "ALTER TABLE cases_cache MODIFY COLUMN patient_dob VARCHAR(500) DEFAULT NULL",
            "ALTER TABLE cases_cache ADD INDEX idx_archived (archived)",
            "ALTER TABLE cases_cache ADD INDEX idx_practice_id (practice_id)",
            "ALTER TABLE cases_cache ADD COLUMN patient_gender VARCHAR(20) DEFAULT NULL",
            "ALTER TABLE cases_cache ADD COLUMN clinical_details_json LONGTEXT",
            "ALTER TABLE cases_cache ADD COLUMN revision_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times case was returned to Originated'",
            "ALTER TABLE cases_cache ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Optimistic locking version for concurrent edit detection'"
        ];
        
        foreach ($alterSqls as $alterSql) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $e) {
                // Ignore errors if columns already exist (error 1060 = duplicate column name)
                if (strpos($e->getMessage(), '1060') === false && strpos($e->getMessage(), '1061') === false) {
                    error_log('[cases_cache] Error adding column/index: ' . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[cases_cache] Error creating table: ' . $e->getMessage());
    }
}

function saveCaseToCache(array $caseData) {
    global $pdo;
    if (!$pdo) {
        return;
    }
    if (empty($caseData['id'])) {
        return;
    }

    ensureCasesCacheTable();

    $attachments = isset($caseData['attachments']) ? json_encode($caseData['attachments']) : '[]';
    $revisions = isset($caseData['revisions']) ? json_encode($caseData['revisions']) : '[]';
    $assignedTo = isset($caseData['assignedTo']) ? $caseData['assignedTo'] : null;

    // Get practice_id from session or caseData
    $practiceId = null;
    if (isset($caseData['practice_id'])) {
        $practiceId = $caseData['practice_id'];
    } elseif (isset($_SESSION['current_practice_id'])) {
        $practiceId = $_SESSION['current_practice_id'];
    }

    $sql = "INSERT INTO cases_cache (
                case_id,
                drive_folder_id,
                patient_first_name,
                patient_last_name,
                patient_dob,
                patient_gender,
                dentist_name,
                case_type,
                tooth_shade,
                material,
                due_date,
                creation_date,
                last_update_date,
                status,
                status_changed_at,
                notes,
                assigned_to,
                attachments_json,
                revisions_json,
                clinical_details_json,
                practice_id
            ) VALUES (
                :case_id,
                :drive_folder_id,
                :patient_first_name,
                :patient_last_name,
                :patient_dob,
                :patient_gender,
                :dentist_name,
                :case_type,
                :tooth_shade,
                :material,
                :due_date,
                :creation_date,
                :last_update_date,
                :status,
                :status_changed_at,
                :notes,
                :assigned_to,
                :attachments_json,
                :revisions_json,
                :clinical_details_json,
                :practice_id
            )
            ON DUPLICATE KEY UPDATE
                drive_folder_id = VALUES(drive_folder_id),
                patient_first_name = VALUES(patient_first_name),
                patient_last_name = VALUES(patient_last_name),
                patient_dob = VALUES(patient_dob),
                patient_gender = VALUES(patient_gender),
                dentist_name = VALUES(dentist_name),
                case_type = VALUES(case_type),
                tooth_shade = VALUES(tooth_shade),
                material = VALUES(material),
                due_date = VALUES(due_date),
                creation_date = VALUES(creation_date),
                last_update_date = VALUES(last_update_date),
                status = VALUES(status),
                status_changed_at = VALUES(status_changed_at),
                notes = VALUES(notes),
                assigned_to = VALUES(assigned_to),
                attachments_json = VALUES(attachments_json),
                revisions_json = VALUES(revisions_json),
                clinical_details_json = VALUES(clinical_details_json),
                practice_id = VALUES(practice_id)";

    $clinicalDetailsJson = isset($caseData['clinicalDetails']) ? json_encode($caseData['clinicalDetails']) : null;
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'case_id' => $caseData['id'],
            'drive_folder_id' => isset($caseData['driveFolderId']) ? $caseData['driveFolderId'] : null,
            'patient_first_name' => isset($caseData['patientFirstName']) ? $caseData['patientFirstName'] : null,
            'patient_last_name' => isset($caseData['patientLastName']) ? $caseData['patientLastName'] : null,
            'patient_dob' => isset($caseData['patientDOB']) ? $caseData['patientDOB'] : null,
            'patient_gender' => isset($caseData['patientGender']) ? $caseData['patientGender'] : null,
            'dentist_name' => isset($caseData['dentistName']) ? $caseData['dentistName'] : null,
            'case_type' => isset($caseData['caseType']) ? $caseData['caseType'] : null,
            'tooth_shade' => isset($caseData['toothShade']) ? $caseData['toothShade'] : null,
            'material' => isset($caseData['material']) ? $caseData['material'] : null,
            'due_date' => isset($caseData['dueDate']) ? $caseData['dueDate'] : null,
            'creation_date' => isset($caseData['creationDate']) ? $caseData['creationDate'] : null,
            'last_update_date' => isset($caseData['lastUpdateDate']) ? $caseData['lastUpdateDate'] : null,
            'status' => isset($caseData['status']) ? $caseData['status'] : null,
            'status_changed_at' => isset($caseData['statusChangedAt']) ? date('Y-m-d H:i:s', strtotime($caseData['statusChangedAt'])) : null,
            'notes' => isset($caseData['notes']) ? $caseData['notes'] : null,
            'assigned_to' => $assignedTo,
            'attachments_json' => $attachments,
            'revisions_json' => $revisions,
            'clinical_details_json' => $clinicalDetailsJson,
            'practice_id' => $practiceId,
        ]);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error saving case: ' . $e->getMessage());
    }
}

/**
 * Get a single case from cache by case ID.
 * @param string $caseId The case ID to retrieve
 * @return array|null The case data or null if not found
 */
function getCaseFromCache($caseId) {
    global $pdo;
    if (!$pdo || empty($caseId)) {
        return null;
    }

    ensureCasesCacheTable();

    try {
        $stmt = $pdo->prepare("SELECT * FROM cases_cache WHERE case_id = :case_id LIMIT 1");
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }

        $attachments = [];
        if (!empty($row['attachments_json'])) {
            $decoded = json_decode($row['attachments_json'], true);
            if (is_array($decoded)) {
                $attachments = $decoded;
            }
        }

        $revisions = [];
        if (!empty($row['revisions_json'])) {
            $decoded = json_decode($row['revisions_json'], true);
            if (is_array($decoded)) {
                $revisions = $decoded;
            }
        }

        $clinicalDetails = [];
        if (!empty($row['clinical_details_json'])) {
            $decoded = json_decode($row['clinical_details_json'], true);
            if (is_array($decoded)) {
                $clinicalDetails = $decoded;
            }
        }

        return [
            'id' => $row['case_id'],
            'driveFolderId' => $row['drive_folder_id'],
            'patientFirstName' => $row['patient_first_name'],
            'patientLastName' => $row['patient_last_name'],
            'patientDOB' => $row['patient_dob'],
            'patientGender' => $row['patient_gender'] ?? null,
            'dentistName' => $row['dentist_name'],
            'caseType' => $row['case_type'],
            'toothShade' => $row['tooth_shade'],
            'material' => $row['material'],
            'dueDate' => $row['due_date'],
            'creationDate' => $row['creation_date'],
            'lastUpdateDate' => $row['last_update_date'],
            'status' => $row['status'],
            'statusChangedAt' => !empty($row['status_changed_at']) ? date('c', strtotime($row['status_changed_at'])) : null,
            'notes' => $row['notes'],
            'revisions' => $revisions,
            'attachments' => $attachments,
            'clinicalDetails' => $clinicalDetails,
            'assignedTo' => $row['assigned_to'] ?? null,
            'revisionCount' => (int)($row['revision_count'] ?? 0),
            'version' => (int)($row['version'] ?? 1),
        ];
    } catch (PDOException $e) {
        error_log('[cases_cache] Error getting case: ' . $e->getMessage());
        return null;
    }
}

function updateCaseStatusInCache($caseId, $status, $lastUpdateDate) {
    global $pdo;
    if (!$pdo) {
        return;
    }

    ensureCasesCacheTable();

    $sql = "UPDATE cases_cache
            SET status = :status, last_update_date = :last_update_date, status_changed_at = NOW()
            WHERE case_id = :case_id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $status,
            'last_update_date' => $lastUpdateDate,
            'case_id' => $caseId,
        ]);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error updating status: ' . $e->getMessage());
    }
}

function updateCaseAssignedToInCache($caseId, $assignedTo) {
    global $pdo;
    if (!$pdo) {
        return;
    }

    ensureCasesCacheTable();

    $sql = "UPDATE cases_cache
            SET assigned_to = :assigned_to
            WHERE case_id = :case_id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'assigned_to' => $assignedTo !== '' ? $assignedTo : null,
            'case_id' => $caseId,
        ]);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error updating assigned_to: ' . $e->getMessage());
    }
}

/**
 * Increment the revision count for a case when it's returned to Originated.
 * Returns the new revision count, or null on failure.
 */
function incrementCaseRevisionCount($caseId) {
    global $pdo;
    if (!$pdo) {
        return null;
    }

    ensureCasesCacheTable();

    $sql = "UPDATE cases_cache
            SET revision_count = revision_count + 1
            WHERE case_id = :case_id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['case_id' => $caseId]);
        
        // Return the new revision count
        $stmt = $pdo->prepare("SELECT revision_count FROM cases_cache WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[cases_cache] Error incrementing revision count: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get the current revision count for a case.
 */
function getCaseRevisionCount($caseId) {
    global $pdo;
    if (!$pdo) {
        return 0;
    }

    ensureCasesCacheTable();

    try {
        $stmt = $pdo->prepare("SELECT revision_count FROM cases_cache WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
        $count = $stmt->fetchColumn();
        return $count !== false ? (int)$count : 0;
    } catch (PDOException $e) {
        error_log('[cases_cache] Error getting revision count: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Update a case with optimistic locking (version check).
 * Returns: ['success' => true, 'newVersion' => X] on success
 *          ['success' => false, 'conflict' => true, 'currentVersion' => X, 'currentData' => [...]] on version conflict
 *          ['success' => false, 'error' => 'message'] on other errors
 * 
 * @param array $caseData Case data including 'id' and 'version'
 * @param int $expectedVersion The version the client expects (from when they loaded the case)
 * @return array Result with success status
 */
function updateCaseWithVersionCheck(array $caseData, $expectedVersion) {
    global $pdo;
    if (!$pdo) {
        return ['success' => false, 'error' => 'Database not available'];
    }
    if (empty($caseData['id'])) {
        return ['success' => false, 'error' => 'Case ID required'];
    }

    ensureCasesCacheTable();

    $caseId = $caseData['id'];
    $expectedVersion = (int)$expectedVersion;

    try {
        // Start transaction for atomic check-and-update
        $pdo->beginTransaction();

        // Get current version with row lock
        $stmt = $pdo->prepare("SELECT version FROM cases_cache WHERE case_id = :case_id FOR UPDATE");
        $stmt->execute(['case_id' => $caseId]);
        $currentVersion = $stmt->fetchColumn();

        if ($currentVersion === false) {
            // Case doesn't exist - this is a new case, allow insert
            $pdo->rollBack();
            // Use regular save for new cases
            $caseData['version'] = 1;
            saveCaseToCache($caseData);
            return ['success' => true, 'newVersion' => 1];
        }

        $currentVersion = (int)$currentVersion;

        // Check if version matches
        if ($currentVersion !== $expectedVersion) {
            // Version conflict - someone else edited the case
            $pdo->rollBack();
            
            // Get the current case data to return to client
            $currentData = getCaseFromCache($caseId);
            
            return [
                'success' => false,
                'conflict' => true,
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentVersion,
                'currentData' => $currentData,
                'message' => 'This case was modified by another user. Please review their changes.'
            ];
        }

        // Version matches - proceed with update
        $newVersion = $currentVersion + 1;
        
        $attachments = isset($caseData['attachments']) ? json_encode($caseData['attachments']) : '[]';
        $revisions = isset($caseData['revisions']) ? json_encode($caseData['revisions']) : '[]';
        $clinicalDetailsJson = isset($caseData['clinicalDetails']) ? json_encode($caseData['clinicalDetails']) : null;
        $assignedTo = isset($caseData['assignedTo']) ? $caseData['assignedTo'] : null;

        // Get practice_id from session or caseData
        $practiceId = null;
        if (isset($caseData['practice_id'])) {
            $practiceId = $caseData['practice_id'];
        } elseif (isset($_SESSION['current_practice_id'])) {
            $practiceId = $_SESSION['current_practice_id'];
        }

        $sql = "UPDATE cases_cache SET
                    drive_folder_id = :drive_folder_id,
                    patient_first_name = :patient_first_name,
                    patient_last_name = :patient_last_name,
                    patient_dob = :patient_dob,
                    patient_gender = :patient_gender,
                    dentist_name = :dentist_name,
                    case_type = :case_type,
                    tooth_shade = :tooth_shade,
                    material = :material,
                    due_date = :due_date,
                    last_update_date = :last_update_date,
                    status = :status,
                    status_changed_at = :status_changed_at,
                    notes = :notes,
                    assigned_to = :assigned_to,
                    attachments_json = :attachments_json,
                    revisions_json = :revisions_json,
                    clinical_details_json = :clinical_details_json,
                    practice_id = :practice_id,
                    version = :new_version
                WHERE case_id = :case_id AND version = :expected_version";

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'case_id' => $caseId,
            'drive_folder_id' => $caseData['driveFolderId'] ?? null,
            'patient_first_name' => $caseData['patientFirstName'] ?? null,
            'patient_last_name' => $caseData['patientLastName'] ?? null,
            'patient_dob' => $caseData['patientDOB'] ?? null,
            'patient_gender' => $caseData['patientGender'] ?? null,
            'dentist_name' => $caseData['dentistName'] ?? null,
            'case_type' => $caseData['caseType'] ?? null,
            'tooth_shade' => $caseData['toothShade'] ?? null,
            'material' => $caseData['material'] ?? null,
            'due_date' => $caseData['dueDate'] ?? null,
            'last_update_date' => $caseData['lastUpdateDate'] ?? null,
            'status' => $caseData['status'] ?? null,
            'status_changed_at' => isset($caseData['statusChangedAt']) ? date('Y-m-d H:i:s', strtotime($caseData['statusChangedAt'])) : null,
            'notes' => $caseData['notes'] ?? null,
            'assigned_to' => $assignedTo,
            'attachments_json' => $attachments,
            'revisions_json' => $revisions,
            'clinical_details_json' => $clinicalDetailsJson,
            'practice_id' => $practiceId,
            'new_version' => $newVersion,
            'expected_version' => $expectedVersion,
        ]);

        if ($stmt->rowCount() === 0) {
            // Race condition - version changed between SELECT and UPDATE
            $pdo->rollBack();
            $currentData = getCaseFromCache($caseId);
            return [
                'success' => false,
                'conflict' => true,
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentData['version'] ?? 'unknown',
                'currentData' => $currentData,
                'message' => 'This case was modified by another user. Please review their changes.'
            ];
        }

        $pdo->commit();
        
        return [
            'success' => true,
            'newVersion' => $newVersion
        ];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[cases_cache] Error in updateCaseWithVersionCheck: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Get the current version of a case.
 * @param string $caseId
 * @return int|null Version number or null if case not found
 */
function getCaseVersion($caseId) {
    global $pdo;
    if (!$pdo || empty($caseId)) {
        return null;
    }

    ensureCasesCacheTable();

    try {
        $stmt = $pdo->prepare("SELECT version FROM cases_cache WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
        $version = $stmt->fetchColumn();
        return $version !== false ? (int)$version : null;
    } catch (PDOException $e) {
        error_log('[cases_cache] Error getting version: ' . $e->getMessage());
        return null;
    }
}

/**
 * Update case status with version check for optimistic locking.
 * Used for drag-and-drop status changes.
 * 
 * @param string $caseId
 * @param string $status New status
 * @param string $lastUpdateDate
 * @param int $expectedVersion
 * @return array Result with success status
 */
function updateCaseStatusWithVersionCheck($caseId, $status, $lastUpdateDate, $expectedVersion) {
    global $pdo;
    if (!$pdo) {
        return ['success' => false, 'error' => 'Database not available'];
    }

    ensureCasesCacheTable();

    $expectedVersion = (int)$expectedVersion;

    try {
        $pdo->beginTransaction();

        // Get current version with row lock
        $stmt = $pdo->prepare("SELECT version, status FROM cases_cache WHERE case_id = :case_id FOR UPDATE");
        $stmt->execute(['case_id' => $caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Case not found'];
        }

        $currentVersion = (int)$row['version'];
        $oldStatus = $row['status'];

        if ($currentVersion !== $expectedVersion) {
            $pdo->rollBack();
            $currentData = getCaseFromCache($caseId);
            return [
                'success' => false,
                'conflict' => true,
                'expectedVersion' => $expectedVersion,
                'currentVersion' => $currentVersion,
                'currentData' => $currentData,
                'message' => 'This case was modified by another user.'
            ];
        }

        $newVersion = $currentVersion + 1;

        $sql = "UPDATE cases_cache
                SET status = :status, 
                    last_update_date = :last_update_date, 
                    status_changed_at = NOW(),
                    version = :new_version
                WHERE case_id = :case_id AND version = :expected_version";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $status,
            'last_update_date' => $lastUpdateDate,
            'case_id' => $caseId,
            'new_version' => $newVersion,
            'expected_version' => $expectedVersion,
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            $currentData = getCaseFromCache($caseId);
            return [
                'success' => false,
                'conflict' => true,
                'message' => 'This case was modified by another user.'
            ];
        }

        $pdo->commit();

        return [
            'success' => true,
            'newVersion' => $newVersion,
            'oldStatus' => $oldStatus
        ];

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[cases_cache] Error in updateCaseStatusWithVersionCheck: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteCaseFromCache($caseId) {
    global $pdo;
    if (!$pdo) {
        return;
    }

    ensureCasesCacheTable();

    $sql = "DELETE FROM cases_cache WHERE case_id = :case_id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['case_id' => $caseId]);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error deleting case: ' . $e->getMessage());
    }
}

function archiveCaseInCache($caseId) {
    global $pdo;
    if (!$pdo) {
        return;
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    ensureCasesCacheTable();

    // Get current practice ID from session
    $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
    
    $sql = "UPDATE cases_cache SET archived = 1, archived_date = :archived_date, practice_id = :practice_id WHERE case_id = :case_id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'case_id' => $caseId,
            'archived_date' => date('Y-m-d H:i:s'),
            'practice_id' => $currentPracticeId
        ]);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error archiving case: ' . $e->getMessage());
    }
}

function getAllCasesFromCache() {
    global $pdo;
    if (!$pdo) {
        return [];
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    ensureCasesCacheTable();

    // SECURITY: Get current practice ID from session - REQUIRED
    $currentPracticeId = $_SESSION['current_practice_id'] ?? null;
    
    // SECURITY: If no practice ID, return empty array - DO NOT return all cases
    if (!$currentPracticeId) {
        error_log('[SECURITY] getAllCasesFromCache called without current_practice_id in session');
        return [];
    }

    try {
        // SECURITY: Always filter by practice_id
        $stmt = $pdo->prepare("SELECT * FROM cases_cache WHERE practice_id = :practice_id ORDER BY last_update_date DESC");
        $stmt->execute(['practice_id' => $currentPracticeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[cases_cache] Error loading cases: ' . $e->getMessage());
        return [];
    }

    $cases = [];

    foreach ($rows as $row) {
        $attachments = [];
        if (!empty($row['attachments_json'])) {
            $decoded = json_decode($row['attachments_json'], true);
            if (is_array($decoded)) {
                $attachments = $decoded;
            }
        }

        $revisions = [];
        if (!empty($row['revisions_json'])) {
            $decoded = json_decode($row['revisions_json'], true);
            if (is_array($decoded)) {
                $revisions = $decoded;
            }
        }

        // Parse clinical details JSON
        $clinicalDetails = [];
        if (!empty($row['clinical_details_json'])) {
            $decoded = json_decode($row['clinical_details_json'], true);
            if (is_array($decoded)) {
                $clinicalDetails = $decoded;
            }
        }

        $case = [
            'id' => $row['case_id'],
            'driveFolderId' => $row['drive_folder_id'],
            'patientFirstName' => $row['patient_first_name'],
            'patientLastName' => $row['patient_last_name'],
            'patientDOB' => $row['patient_dob'],
            'patientGender' => $row['patient_gender'] ?? null,
            'dentistName' => $row['dentist_name'],
            'caseType' => $row['case_type'],
            'toothShade' => $row['tooth_shade'],
            'material' => $row['material'],
            'dueDate' => $row['due_date'],
            'creationDate' => $row['creation_date'],
            'lastUpdateDate' => $row['last_update_date'],
            'status' => $row['status'],
            'statusChangedAt' => !empty($row['status_changed_at']) ? date('c', strtotime($row['status_changed_at'])) : null,
            'notes' => $row['notes'],
            'revisions' => $revisions,
            'attachments' => $attachments,
            'clinicalDetails' => $clinicalDetails,
            'archived' => isset($row['archived']) ? $row['archived'] : false,
            'archivedDate' => $row['archived_date'] ?? null,
            'revisionCount' => isset($row['revision_count']) ? (int)$row['revision_count'] : 0,
        ];

        if (isset($row['assigned_to']) && $row['assigned_to'] !== null) {
            $case['assignedTo'] = $row['assigned_to'];
        }

        // Decrypt PII fields before returning
        try {
            if (class_exists('PIIEncryption')) {
                $case = PIIEncryption::decryptCaseData($case);
            }
        } catch (Exception $e) {
            // Continue with encrypted data if decryption fails
        }

        $cases[] = $case;
    }

    return $cases;
}
