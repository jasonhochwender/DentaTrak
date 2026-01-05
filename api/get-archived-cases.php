<?php
/**
 * Get Archived Cases API Endpoint
 * Returns paginated list of archived cases with search and filtering
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/encryption.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

try {
    // Get pagination and filter parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 25;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $dateRange = isset($_GET['dateRange']) ? (int)$_GET['dateRange'] : 0;
    $caseType = isset($_GET['caseType']) ? trim($_GET['caseType']) : '';
    
    $offset = ($page - 1) * $pageSize;
    
    // Build WHERE conditions
    $whereConditions = ['cc.archived = 1'];
    $params = [];
    
    // Add practice filter
    if ($currentPracticeId) {
        $whereConditions[] = 'cc.practice_id = :practice_id';
        $params['practice_id'] = $currentPracticeId;
    }
    
    // Add search filter
    if (!empty($search)) {
        $whereConditions[] = '(cc.patient_first_name LIKE :search_first OR cc.patient_last_name LIKE :search_last)';
        $params['search_first'] = '%' . $search . '%';
        $params['search_last'] = '%' . $search . '%';
    }
    
    // Add date range filter
    if ($dateRange > 0) {
        $whereConditions[] = 'cc.archived_date >= DATE_SUB(CURRENT_DATE, INTERVAL :date_range DAY)';
        $params['date_range'] = $dateRange;
    }
    
    // Add case type filter
    if (!empty($caseType)) {
        $whereConditions[] = 'cc.case_type = :case_type';
        $params['case_type'] = $caseType;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) as total
        FROM cases_cache cc
        $whereClause
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetchColumn();
    
    // Get paginated results
    $sql = "
        SELECT 
            cc.case_id as id,
            cc.patient_first_name,
            cc.patient_last_name,
            cc.dentist_name,
            cc.case_type,
            cc.status,
            cc.creation_date,
            cc.archived_date,
            cc.drive_folder_id as driveFolderId
        FROM cases_cache cc
        $whereClause
        ORDER BY cc.archived_date DESC
        LIMIT :limit OFFSET :offset
    ";
    
    // Add pagination parameters to a separate array for the main query
    $queryParams = $params;
    $queryParams['limit'] = $pageSize;
    $queryParams['offset'] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt PII fields for display
    $decryptedCases = array_map(function($case) {
        // Map database column names to the format expected by decryptCaseData
        $caseData = [
            'patientFirstName' => $case['patient_first_name'] ?? '',
            'patientLastName' => $case['patient_last_name'] ?? '',
            'dentistName' => $case['dentist_name'] ?? ''
        ];
        $decrypted = PIIEncryption::decryptCaseData($caseData);
        
        return [
            'id' => $case['id'],
            'patient_first_name' => $decrypted['patientFirstName'],
            'patient_last_name' => $decrypted['patientLastName'],
            'dentist_name' => $decrypted['dentistName'],
            'case_type' => $case['case_type'],
            'status' => $case['status'],
            'creation_date' => $case['creation_date'],
            'archived_date' => $case['archived_date'],
            'driveFolderId' => $case['driveFolderId']
        ];
    }, $cases);
    
    echo json_encode([
        'success' => true,
        'cases' => $decryptedCases,
        'totalCount' => $totalCount,
        'page' => $page,
        'pageSize' => $pageSize
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve archived cases: ' . $e->getMessage()
    ]);
    
    error_log('Error getting archived cases: ' . $e->getMessage());
}
?>
