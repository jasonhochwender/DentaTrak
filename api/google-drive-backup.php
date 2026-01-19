<?php
/**
 * Google Drive Backup Management API
 * 
 * Handles:
 * - Checking if user has Google Workspace (required for backup)
 * - Enabling backup (creates shared folder)
 * - Disabling backup
 * - Getting backup status
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/hipaa-compliance.php';

header('Content-Type: application/json');

// Ensure user is logged in and has a practice context
if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$practiceId = $_SESSION['current_practice_id'] ?? null;
if (!$practiceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No practice selected']);
    exit;
}

// Ensure HIPAA schema is up to date (adds google_drive_folder_id column if needed)
ensureHIPAASchema();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        handleGetRequest($action);
        break;
    case 'POST':
        handlePostRequest($action);
        break;
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handleGetRequest($action) {
    global $pdo;
    
    switch ($action) {
        case 'status':
            // Get current backup status for the practice
            $practiceId = $_SESSION['current_practice_id'];
            
            try {
                $stmt = $pdo->prepare("SELECT google_drive_backup_enabled, google_drive_folder_id FROM practices WHERE id = :id");
                $stmt->execute(['id' => $practiceId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $backupEnabled = (bool)($row['google_drive_backup_enabled'] ?? false);
                $hasFolderId = !empty($row['google_drive_folder_id']);
                
                // Check if current user has Drive connected
                $driveConnected = isset($_SESSION['google_drive_token']) && !empty($_SESSION['google_drive_token']);
                
                echo json_encode([
                    'success' => true,
                    'backupEnabled' => $backupEnabled,
                    'folderConfigured' => $hasFolderId,
                    'driveConnected' => $driveConnected
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error checking backup status']);
            }
            break;
            
        case 'check-workspace':
            // Check if current user has Google Workspace
            $result = checkGoogleWorkspaceAccess();
            echo json_encode([
                'success' => true,
                'hasWorkspace' => $result['hasWorkspace'],
                'error' => $result['error']
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePostRequest($action) {
    global $pdo;
    
    // Only practice admins can change backup settings
    if (!isPracticeAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only practice administrators can change backup settings']);
        return;
    }
    
    switch ($action) {
        case 'enable':
            // Enable backup - creates folder in user's Google Drive
            
            // First check if Drive is connected
            if (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token'])) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Please connect Google Drive first. Go to Settings and click "Connect Google Drive".',
                    'needsDriveConnection' => true
                ]);
                return;
            }
            
            // Get practice name for folder
            $practiceId = $_SESSION['current_practice_id'];
            $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
            $stmt->execute(['id' => $practiceId]);
            $practiceName = $stmt->fetchColumn() ?: 'Practice ' . $practiceId;
            
            try {
                // Create folder directly (same approach as test-drive.php which works)
                $client = getGoogleClient();
                $service = new Google_Service_Drive($client);
                
                $folderName = $practiceName . ' - Case Backups';
                $folderMetadata = new Google_Service_Drive_DriveFile([
                    'name' => $folderName,
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'description' => 'Automated case backups for ' . $practiceName . '. Created by DentaTrack.'
                ]);
                
                $folder = $service->files->create($folderMetadata, ['fields' => 'id, webViewLink']);
                $folderId = $folder->getId();
                
                // Store the folder ID in the practice record
                $stmt = $pdo->prepare("UPDATE practices SET google_drive_folder_id = :folder_id, google_drive_backup_enabled = 1 WHERE id = :id");
                $stmt->execute(['folder_id' => $folderId, 'id' => $practiceId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Google Drive backup enabled! Folder created: ' . $folderName,
                    'folderId' => $folderId,
                    'folderLink' => $folder->getWebViewLink()
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to create backup folder: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'disable':
            // Disable backup (keeps existing folder and files)
            $result = disablePracticeBackup();
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Google Drive backup disabled. Existing backup files have been preserved.'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to disable backup']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
