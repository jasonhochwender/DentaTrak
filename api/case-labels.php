<?php
/**
 * Case Labels API
 * 
 * Handles practice-scoped labels for cases.
 * Labels are tags/context markers, NOT owners.
 * 
 * Endpoints:
 * - GET: Get all labels for the current practice
 * - POST action=create: Create a new label
 * - POST action=assign: Assign labels to a case
 * - POST action=remove: Remove a label from a case
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context with membership verification
$practiceId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Ensure tables exist
ensureLabelTables();

/**
 * Ensure label tables exist
 */
function ensureLabelTables() {
    global $pdo;
    
    if (!$pdo) return;
    
    try {
        // Practice-scoped labels table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS case_labels (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                practice_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                color VARCHAR(7) DEFAULT '#6b7280',
                created_by INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_label_per_practice (practice_id, name),
                INDEX idx_practice_id (practice_id),
                FOREIGN KEY (practice_id) REFERENCES practices(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Case-to-label assignments (many-to-many)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS case_label_assignments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id VARCHAR(64) NOT NULL,
                label_id INT UNSIGNED NOT NULL,
                assigned_by INT UNSIGNED NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_case_label (case_id, label_id),
                INDEX idx_case_id (case_id),
                INDEX idx_label_id (label_id),
                FOREIGN KEY (label_id) REFERENCES case_labels(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Tables may already exist
        if (strpos($e->getMessage(), '1050') === false) {
            error_log('[case-labels] Error creating tables: ' . $e->getMessage());
        }
    }
}

/**
 * Get all labels for a practice
 */
function getLabelsForPractice($practiceId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, name, color, created_at
        FROM case_labels
        WHERE practice_id = :practice_id
        ORDER BY name ASC
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $labels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure IDs are integers for JavaScript comparison
    return array_map(function($label) {
        $label['id'] = (int)$label['id'];
        return $label;
    }, $labels);
}

/**
 * Get labels assigned to a specific case
 */
function getLabelsForCase($caseId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT cl.id, cl.name, cl.color
        FROM case_labels cl
        JOIN case_label_assignments cla ON cl.id = cla.label_id
        WHERE cla.case_id = :case_id
        ORDER BY cl.name ASC
    ");
    $stmt->execute(['case_id' => $caseId]);
    $labels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure IDs are integers for JavaScript comparison
    return array_map(function($label) {
        $label['id'] = (int)$label['id'];
        return $label;
    }, $labels);
}

/**
 * Create a new label
 */
function createLabel($practiceId, $name, $userId, $color = '#6b7280') {
    global $pdo;
    
    $name = trim($name);
    if (empty($name)) {
        return ['success' => false, 'error' => 'Label name is required'];
    }
    
    if (mb_strlen($name) > 100) {
        return ['success' => false, 'error' => 'Label name must be 100 characters or less'];
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO case_labels (practice_id, name, color, created_by)
            VALUES (:practice_id, :name, :color, :created_by)
        ");
        $stmt->execute([
            'practice_id' => $practiceId,
            'name' => $name,
            'color' => $color,
            'created_by' => $userId
        ]);
        
        $labelId = $pdo->lastInsertId();
        
        return [
            'success' => true,
            'label' => [
                'id' => (int)$labelId,
                'name' => $name,
                'color' => $color
            ]
        ];
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
            // Label already exists, return it
            $stmt = $pdo->prepare("SELECT id, name, color FROM case_labels WHERE practice_id = :practice_id AND name = :name");
            $stmt->execute(['practice_id' => $practiceId, 'name' => $name]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'success' => true,
                    'label' => [
                        'id' => (int)$existing['id'],
                        'name' => $existing['name'],
                        'color' => $existing['color']
                    ],
                    'existed' => true
                ];
            }
        }
        error_log('[case-labels] Error creating label: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create label'];
    }
}

/**
 * Assign labels to a case
 */
function assignLabelsToCase($caseId, $labelIds, $userId) {
    global $pdo;
    
    if (empty($caseId)) {
        return ['success' => false, 'error' => 'Case ID is required'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Remove existing label assignments for this case
        $stmt = $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id = :case_id");
        $stmt->execute(['case_id' => $caseId]);
        
        // Add new assignments
        if (!empty($labelIds) && is_array($labelIds)) {
            $stmt = $pdo->prepare("
                INSERT INTO case_label_assignments (case_id, label_id, assigned_by)
                VALUES (:case_id, :label_id, :assigned_by)
            ");
            
            foreach ($labelIds as $labelId) {
                $stmt->execute([
                    'case_id' => $caseId,
                    'label_id' => (int)$labelId,
                    'assigned_by' => $userId
                ]);
            }
        }
        
        $pdo->commit();
        
        // Return updated labels for the case
        $labels = getLabelsForCase($caseId);
        
        return [
            'success' => true,
            'labels' => $labels
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('[case-labels] Error assigning labels: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to assign labels'];
    }
}

/**
 * Remove a single label from a case
 */
function removeLabelFromCase($caseId, $labelId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM case_label_assignments 
            WHERE case_id = :case_id AND label_id = :label_id
        ");
        $stmt->execute([
            'case_id' => $caseId,
            'label_id' => (int)$labelId
        ]);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log('[case-labels] Error removing label: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to remove label'];
    }
}

/**
 * Delete a label entirely (admin only)
 */
function deleteLabel($labelId, $practiceId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM case_labels 
            WHERE id = :id AND practice_id = :practice_id
        ");
        $stmt->execute([
            'id' => (int)$labelId,
            'practice_id' => $practiceId
        ]);
        
        return ['success' => true];
    } catch (PDOException $e) {
        error_log('[case-labels] Error deleting label: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to delete label'];
    }
}

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all labels for practice, optionally with case-specific assignments
    $caseId = $_GET['case_id'] ?? null;
    
    $labels = getLabelsForPractice($practiceId);
    
    $response = [
        'success' => true,
        'labels' => $labels
    ];
    
    if ($caseId) {
        $response['caseLabels'] = getLabelsForCase($caseId);
    }
    
    echo json_encode($response);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $name = $input['name'] ?? '';
            $color = $input['color'] ?? '#6b7280';
            $result = createLabel($practiceId, $name, $userId, $color);
            echo json_encode($result);
            break;
            
        case 'assign':
            $caseId = $input['case_id'] ?? '';
            $labelIds = $input['label_ids'] ?? [];
            $result = assignLabelsToCase($caseId, $labelIds, $userId);
            echo json_encode($result);
            break;
            
        case 'remove':
            $caseId = $input['case_id'] ?? '';
            $labelId = $input['label_id'] ?? 0;
            $result = removeLabelFromCase($caseId, $labelId);
            echo json_encode($result);
            break;
            
        case 'delete':
            if (!isPracticeAdmin()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Admin access required']);
                exit;
            }
            $labelId = $input['label_id'] ?? 0;
            $result = deleteLabel($labelId, $practiceId);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
