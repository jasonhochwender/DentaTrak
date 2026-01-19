<?php
// Update Case Status API endpoint - Used for drag and drop status updates
require_once __DIR__ . '/session.php'; // centralized session handling
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';

// Disable PHP error display for API - return only JSON
ini_set('display_errors', '0');
// Suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Validate CSRF token
requireCsrfToken();

// Get the JSON data
$data = json_decode(file_get_contents('php://input'), true);

// Normalize and validate required fields (caseId and status are always required)
$caseId = isset($data['caseId']) ? trim((string)$data['caseId']) : '';
$status = isset($data['status']) ? trim((string)$data['status']) : '';
$driveFolderId = isset($data['driveFolderId']) ? trim((string)$data['driveFolderId']) : '';
$expectedVersion = isset($data['version']) ? (int)$data['version'] : null;

if ($caseId === '' || $status === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: caseId and status are required'
    ]);
    exit;
}

// Define workflow stage order (index 0 = earliest, higher = later)
$workflowStageOrder = [
    'Originated' => 0,
    'Sent To External Lab' => 1,
    'Designed' => 2,
    'Manufactured' => 3,
    'Received From External Lab' => 4,
    'Delivered' => 5
];

/**
 * Check if moving from oldStatus to newStatus is a backward (regression) movement
 */
function isBackwardMovement($oldStatus, $newStatus, $stageOrder) {
    if ($oldStatus === null || $oldStatus === $newStatus) {
        return false;
    }
    $oldIndex = isset($stageOrder[$oldStatus]) ? $stageOrder[$oldStatus] : -1;
    $newIndex = isset($stageOrder[$newStatus]) ? $stageOrder[$newStatus] : -1;
    // Backward movement = new stage has lower index than old stage
    return $newIndex < $oldIndex && $oldIndex >= 0 && $newIndex >= 0;
}

// If we do not have a driveFolderId (e.g., dev-only/cache-only cases),
// update only the local cache and log activity without touching Google Drive.
if ($driveFolderId === '') {
    $oldStatus = null;
    $lastUpdateDate = date('c');
    $revisionCount = null;
    $isRegression = false;
    $newVersion = null;

    // If version provided, use optimistic locking
    if ($expectedVersion !== null) {
        $versionResult = updateCaseStatusWithVersionCheck($caseId, $status, $lastUpdateDate, $expectedVersion);
        
        if (!$versionResult['success']) {
            if (isset($versionResult['conflict']) && $versionResult['conflict']) {
                // Version conflict - another user edited the case
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'conflict' => true,
                    'message' => $versionResult['message'] ?? 'This case was modified by another user.',
                    'expectedVersion' => $expectedVersion,
                    'currentVersion' => $versionResult['currentVersion'] ?? null,
                    'currentData' => $versionResult['currentData'] ?? null
                ]);
                exit;
            } else {
                // Other error
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => $versionResult['error'] ?? 'Failed to update status'
                ]);
                exit;
            }
        }
        
        $oldStatus = $versionResult['oldStatus'] ?? null;
        $newVersion = $versionResult['newVersion'];
        
        // Check if this is a regression
        if (isBackwardMovement($oldStatus, $status, $workflowStageOrder)) {
            $isRegression = true;
            $revisionCount = incrementCaseRevisionCount($caseId);
        }
    } else {
        // No version - use legacy non-locking update (backwards compatibility)
        // Best-effort fetch of previous status from cache for logging purposes
        try {
            if (isset($pdo) && $pdo) {
                $stmt = $pdo->prepare("SELECT status, revision_count FROM cases_cache WHERE case_id = :case_id LIMIT 1");
                $stmt->execute(['case_id' => $caseId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $oldStatus = $row['status'];
                    $revisionCount = (int)($row['revision_count'] ?? 0);
                }
            }
        } catch (Exception $e) {
            error_log('[update-case-status] Error fetching previous status: ' . $e->getMessage());
        }

        // Check if this is a regression (ANY backward stage movement)
        if (isBackwardMovement($oldStatus, $status, $workflowStageOrder)) {
            $isRegression = true;
            $revisionCount = incrementCaseRevisionCount($caseId);
        }

        // Update the local cache only (no version check)
        updateCaseStatusInCache($caseId, $status, $lastUpdateDate);
    }

    // Log status change activity
    $activityMeta = [
        'source' => 'update-case-status.php',
        'drive_folder_id' => null,
    ];
    
    if ($isRegression) {
        logCaseActivity(
            $caseId,
            'case_regression',
            $oldStatus,
            $status,
            array_merge($activityMeta, [
                'regression_number' => $revisionCount,
                'reason' => 'Stage moved backward from ' . $oldStatus . ' to ' . $status
            ])
        );
    } else {
        logCaseActivity(
            $caseId,
            'status_changed',
            $oldStatus,
            $status,
            $activityMeta
        );
    }

    // Return success with minimal updated case data (UI only needs lastUpdateDate)
    $responseData = [
        'id' => $caseId,
        'status' => $status,
        'lastUpdateDate' => $lastUpdateDate,
    ];
    if ($revisionCount !== null) {
        $responseData['revisionCount'] = $revisionCount;
    }
    if ($newVersion !== null) {
        $responseData['version'] = $newVersion;
    }
    
    echo json_encode([
        'success' => true,
        'message' => $isRegression 
            ? 'Stage moved backward from ' . $oldStatus . ' to ' . $status . ' — Regression ' . $revisionCount
            : 'Status updated to "' . $status . '" (cache only)',
        'caseData' => $responseData,
        'isRegression' => $isRegression,
        'newVersion' => $newVersion,
    ]);
    exit;
}

// When driveFolderId is present, update both Google Drive and the local cache
require_once __DIR__ . '/google-drive.php';

try {
    $client = getGoogleClient();
    
    // Check for valid access token - if not available, fall back to cache-only update
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        // Google Drive token not available - update cache only and warn
        $lastUpdateDate = date('c');
        $oldStatus = null;
        
        // Best-effort fetch of previous status from cache
        try {
            if (isset($pdo) && $pdo) {
                $stmt = $pdo->prepare("SELECT status FROM cases_cache WHERE case_id = :case_id LIMIT 1");
                $stmt->execute(['case_id' => $caseId]);
                $prev = $stmt->fetchColumn();
                if ($prev !== false && $prev !== null) {
                    $oldStatus = $prev;
                }
            }
        } catch (Exception $e) {
            error_log('[update-case-status] Error fetching previous status: ' . $e->getMessage());
        }
        
        // Update the local cache only
        updateCaseStatusInCache($caseId, $status, $lastUpdateDate);
        
        // Log status change activity
        logCaseActivity(
            $caseId,
            'status_changed',
            $oldStatus,
            $status,
            [
                'source' => 'update-case-status.php',
                'drive_folder_id' => $driveFolderId,
                'note' => 'Google Drive token expired - cache only update'
            ]
        );
        
        // Return success with warning
        echo json_encode([
            'success' => true,
            'message' => 'Status updated (cache only - Google Drive will sync on next full refresh)',
            'warning' => 'Google Drive session expired. Changes saved locally.',
            'caseData' => [
                'id' => $caseId,
                'status' => $status,
                'lastUpdateDate' => $lastUpdateDate,
            ],
        ]);
        exit;
    }
    
    $service = new Google_Service_Drive($client);
    $caseFolderId = $driveFolderId;
    
    // Find the case.json file in the folder
    $fileResponse = $service->files->listFiles([
        'q' => "'$caseFolderId' in parents and name='case.json' and trashed=false"
    ]);
    
    if (count($fileResponse->getFiles()) === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Case data file not found'
        ]);
        exit;
    }
    
    $caseFileId = $fileResponse->getFiles()[0]->getId();
    
    // Get current case data
    $content = $service->files->get($caseFileId, ['alt' => 'media']);
    $existingCaseData = json_decode($content->getBody()->getContents(), true);
    
    if (!$existingCaseData) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to parse existing case data'
        ]);
        exit;
    }
    
    // Make sure we're updating the right case
    if (!isset($existingCaseData['id']) || $existingCaseData['id'] !== $caseId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Case ID mismatch'
        ]);
        exit;
    }
    
    // Update status
    $oldStatus = isset($existingCaseData['status']) ? $existingCaseData['status'] : null;
    $existingCaseData['status'] = $status;
    $existingCaseData['lastUpdateDate'] = date('c'); // Update timestamp
    
    // Check if this is a regression (ANY backward stage movement)
    $isRegression = false;
    $revisionCount = getCaseRevisionCount($caseId);
    
    if (isBackwardMovement($oldStatus, $status, $workflowStageOrder)) {
        $isRegression = true;
        $revisionCount = incrementCaseRevisionCount($caseId);
        // Store regression count in case data for Google Drive backup
        $existingCaseData['revisionCount'] = $revisionCount;
    }
    
    // Update case.json file in Drive
    $updatedFile = new Google_Service_Drive_DriveFile();
    $service->files->update($caseFileId, $updatedFile, [
        'data' => json_encode($existingCaseData, JSON_PRETTY_PRINT),
        'mimeType' => 'application/json',
        'uploadType' => 'multipart'
    ]);

    // Update local cache
    updateCaseStatusInCache($caseId, $status, $existingCaseData['lastUpdateDate']);

    // Log activity
    $activityMeta = [
        'source' => 'update-case-status.php',
        'drive_folder_id' => $caseFolderId,
    ];
    
    if ($isRegression) {
        // Log as a regression event (backward stage movement)
        logCaseActivity(
            $caseId,
            'case_regression',
            $oldStatus,
            $status,
            array_merge($activityMeta, [
                'regression_number' => $revisionCount,
                'reason' => 'Stage moved backward from ' . $oldStatus . ' to ' . $status
            ])
        );
    } else {
        logCaseActivity(
            $caseId,
            'status_changed',
            $oldStatus,
            $status,
            $activityMeta
        );
    }

    // Include regression count in response
    $existingCaseData['revisionCount'] = $revisionCount;

    // Record status change for real-time notifications to other users
    if (function_exists('recordCaseUpdate')) {
        recordCaseUpdate($caseId, 'status', $oldStatus, null);
    }

    // Return success with the updated case data
    echo json_encode([
        'success' => true,
        'message' => $isRegression 
            ? 'Stage moved backward from ' . $oldStatus . ' to ' . $status . ' — Regression ' . $revisionCount
            : 'Status updated from "' . $oldStatus . '" to "' . $status . '"',
        'caseData' => $existingCaseData,
        'isRegression' => $isRegression,
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating case status: ' . $e->getMessage()
    ]);
}
