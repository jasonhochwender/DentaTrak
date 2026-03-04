<?php
// Google Drive Integration

// appConfig.php already configures error_reporting and logging
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer autoload

// Logging function for Google Drive errors (avoid verbose info logs)
function logGDMsg($msg) {
    error_log('[GoogleDrive] ' . $msg);
}

/**
 * Get Google Client instance for Drive operations
 * This client uses Drive-specific tokens stored separately from login tokens
 * @return Google_Client
 */
function getGoogleClient() {
    global $appConfig;
    
    $client = new Google_Client();
    $client->setApplicationName($appConfig['appName']);
    // Drive scope only - login scopes are handled separately in oauth-start.php
    $client->setScopes([
        Google_Service_Drive::DRIVE
    ]);
    $client->setClientId($appConfig['google_client_id']);
    $client->setClientSecret($appConfig['google_client_secret']);
    
    // Set redirect URI for Drive authorization callback
    if ($appConfig['environment'] === 'development') {
        $redirectUri = 'http://localhost/DentaTrak/api/google-drive-callback.php';
    } else {
        $redirectUri = rtrim($appConfig['baseUrl'], '/') . '/api/google-drive-callback.php';
    }
    
    $client->setRedirectUri($redirectUri);
    $client->setAccessType('offline');  // Need refresh token for Drive access
    $client->setPrompt('consent');  // Force consent to get refresh token

    if (class_exists('\\GuzzleHttp\\Client')) {
        $guzzleClient = new \GuzzleHttp\Client([
            // Overall request timeout (seconds)
            'timeout' => 25.0,
            // DNS/TCP connect timeout (seconds)
            'connect_timeout' => 10.0,
            // Extra safety: force cURL-level timeouts
            'curl' => [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_NOSIGNAL => true,
            ],
        ]);
        $client->setHttpClient($guzzleClient);
    }
    
    // Use existing DRIVE token if available (separate from login token)
    if (isset($_SESSION['google_drive_token']) && !empty($_SESSION['google_drive_token'])) {
        $client->setAccessToken($_SESSION['google_drive_token']);
    }
    
    // Handle token expiration using Drive-specific refresh token
    if ($client->isAccessTokenExpired() && isset($_SESSION['google_drive_refresh_token'])) {
        $client->fetchAccessTokenWithRefreshToken($_SESSION['google_drive_refresh_token']);
        $_SESSION['google_drive_token'] = $client->getAccessToken();
    }
    
    return $client;
}

/**
 * Check if user has connected Google Drive
 * @return bool
 */
function isGoogleDriveConnected() {
    return isset($_SESSION['google_drive_token']) && !empty($_SESSION['google_drive_token']);
}

/**
 * Get the URL to start Google Drive authorization
 * @return string
 */
function getGoogleDriveAuthUrl() {
    $client = getGoogleClient();
    return $client->createAuthUrl();
}

/**
 * Get or create the application's root folder in Google Drive
 * @return string|null Folder ID or null if no valid Google credentials
 */
function getAppRootFolder() {
    $client = getGoogleClient();
    
    // Check if we have valid Google credentials before making API calls
    if (!$client || !$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return null;
    }
    
    // Check if we have the folder ID in session
    if (isset($_SESSION['app_folder_id']) && !empty($_SESSION['app_folder_id'])) {
        return $_SESSION['app_folder_id'];
    }
    
    global $appConfig;
    $service = new Google_Service_Drive($client);
    $appFolderName = $appConfig['appName'] . " Data";
    
    // Check if the folder exists
    try {
        $response = $service->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='" . $appFolderName . "' and trashed=false",
            // 'fields' removed to avoid PHP 8 implode issues in older google/apiclient
        ]);
        
        if (count($response->getFiles()) > 0) {
            $folderId = $response->getFiles()[0]->getId();
            $_SESSION['app_folder_id'] = $folderId;
            return $folderId;
        }
        
        // Create the folder if it doesn't exist
        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $appFolderName,
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);
        
        // Do not request specific fields to avoid implode() bug in old google/apiclient
        $folder = $service->files->create($folderMetadata);
        
        $folderId = $folder->getId();
        $_SESSION['app_folder_id'] = $folderId;
        return $folderId;
    } catch (Exception $e) {
        logGDMsg("Error getting/creating app folder: " . $e->getMessage());
        throw $e;
    }
}

function getPracticeRootFolder($practiceId) {
    global $pdo, $appConfig;

    if (!$practiceId) {
        return getAppRootFolder();
    }

    try {
        if ($pdo) {
            // First check for the new google_drive_folder_id (practice-level backup folder)
            $stmt = $pdo->prepare("SELECT google_drive_folder_id, drive_root_id, practice_name FROM practices WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $practiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Prefer the new google_drive_folder_id if set
            if ($row && !empty($row['google_drive_folder_id'])) {
                return $row['google_drive_folder_id'];
            }
            
            // Fall back to legacy drive_root_id if set
            if ($row && !empty($row['drive_root_id'])) {
                return $row['drive_root_id'];
            }
        }
    } catch (Exception $e) {
        logGDMsg('Error resolving practice root: ' . $e->getMessage());
    }

    // No folder configured - backup should not proceed without explicit setup
    return null;
}

function sharePracticeRootWithEmail($practiceId, $email, $role = 'writer') {
    if (!$practiceId || !$email) {
        return;
    }

    $client = getGoogleClient();

    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return;
    }

    try {
        $service = new Google_Service_Drive($client);
        $folderId = getPracticeRootFolder($practiceId);

        if (!$folderId) {
            return;
        }

        $permission = new Google_Service_Drive_Permission([
            'type' => 'user',
            'role' => $role,
            'emailAddress' => $email
        ]);

        $service->permissions->create($folderId, $permission, [
            'sendNotificationEmail' => false
        ]);
    } catch (Exception $e) {
        logGDMsg('Error sharing practice root: ' . $e->getMessage());
    }
}

function getPracticeArchiveFolder($practiceId) {
    $client = getGoogleClient();

    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return null;
    }

    try {
        // For practiceId = 0, getPracticeRootFolder will fall back to the app root
        $service = new Google_Service_Drive($client);
        $rootFolderId = getPracticeRootFolder($practiceId);

        if (!$rootFolderId) {
            return null;
        }

        $response = $service->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='Archive' and '$rootFolderId' in parents and trashed=false",
        ]);

        $folders = $response->getFiles();

        if (count($folders) > 0) {
            return $folders[0]->getId();
        }

        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => 'Archive',
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$rootFolderId],
        ]);

        $folder = $service->files->create($folderMetadata);

        return $folder->getId();
    } catch (Exception $e) {
        logGDMsg('Error resolving practice archive folder: ' . $e->getMessage());
        return null;
    }
}

function archivePracticeCaseFolder($practiceId, $folderId) {
    if (!$folderId) {
        return false;
    }

    $client = getGoogleClient();

    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return false;
    }

    try {
        // For practiceId = 0, getPracticeRootFolder will fall back to the app root
        $service = new Google_Service_Drive($client);
        $rootFolderId = getPracticeRootFolder($practiceId);
        $archiveFolderId = getPracticeArchiveFolder($practiceId);

        if (!$rootFolderId || !$archiveFolderId) {
            return false;
        }

        $service->files->update($folderId, new Google_Service_Drive_DriveFile(), [
            'addParents' => $archiveFolderId,
            'removeParents' => $rootFolderId,
        ]);

        return true;
    } catch (Exception $e) {
        logGDMsg('Error archiving folder ' . $folderId . ': ' . $e->getMessage());
        return false;
    }
}

function trashDriveFolder($folderId) {
    if (empty($folderId)) {
        return false;
    }

    $client = getGoogleClient();

    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return false;
    }

    try {
        $service = new Google_Service_Drive($client);
        $fileMetadata = new Google_Service_Drive_DriveFile([
            'trashed' => true,
        ]);
        $service->files->update($folderId, $fileMetadata);
        return true;
    } catch (Exception $e) {
        logGDMsg('Error trashing folder ' . $folderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a case folder in Google Drive and store case data
 * @param array $caseData The case data from the form
 * @param array $files The uploaded files data
 * @return array Status and message
 */
function createCase($caseData, $files, $originalCaseData = null, $gcsAttachments = []) {
    global $pdo;
    
    // Use original data if provided, otherwise use encrypted data
    $dataForResponse = $originalCaseData ?? $caseData;
    
    try {
        // Keep the case data encrypted - DO NOT decrypt it
        // The data should remain encrypted when stored in Google Drive
        
        // Check if Google Drive backup is enabled
        $backupEnabled = isGoogleDriveBackupEnabled();
        
        // If backup is not enabled, create a cache-only case
        if (!$backupEnabled) {
            return createCacheOnlyCase($caseData, $files, $originalCaseData, $gcsAttachments);
        }
        
        $client = getGoogleClient();
        
        // Check for valid access token - if not available and backup is enabled, fall back to cache-only
        if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
            // Google Drive token expired but backup was enabled - create cache-only with warning
            $result = createCacheOnlyCase($caseData, $files, $originalCaseData, $gcsAttachments);
            if ($result['success']) {
                $result['warning'] = 'Google Drive session expired. Case saved locally only. Reconnect Google Drive from Settings to enable backup.';
            }
            return $result;
        }
        
        $service = new Google_Service_Drive($client);
        $practiceId = isset($_SESSION['current_practice_id']) ? (int)$_SESSION['current_practice_id'] : 0;
        $rootFolderId = getPracticeRootFolder($practiceId);
        
        // Create unique case ID and timestamp
        $caseId = uniqid() . bin2hex(random_bytes(4));
        $timestamp = time();
        
        // For folder naming, we need to temporarily decrypt the patient name
        $tempDecrypted = PIIEncryption::decryptCaseData($caseData);
        $patientFullName = ($tempDecrypted['patientFirstName'] ?? '') . ' ' . ($tempDecrypted['patientLastName'] ?? '');
        $folderName = trim($patientFullName) . ' - ' . $timestamp;
        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$rootFolderId]
        ]);
        
        // Do not request specific fields to avoid implode() bug in old google/apiclient
        $folder = $service->files->create($folderMetadata);
        
        $caseFolderId = $folder->getId();
        
        // Prepare the complete case data (keeping PII encrypted)
        $completeCase = [
            'id' => $caseId,
            'driveFolderId' => $caseFolderId,
            'patientFirstName' => $caseData['patientFirstName'] ?? '',
            'patientLastName' => $caseData['patientLastName'] ?? '',
            'patientDOB' => $caseData['patientDOB'] ?? '',
            'dentistName' => $caseData['dentistName'] ?? '',
            'caseType' => $caseData['caseType'] ?? '',
            'toothShade' => $caseData['toothShade'] ?? '',
            'material' => $caseData['material'] ?? null,
            'dueDate' => $caseData['dueDate'] ?? '',
            'creationDate' => date('c'), // ISO 8601 format
            'lastUpdateDate' => date('c'),
            'status' => $caseData['status'] ?? 'Originated',
            'notes' => $caseData['notes'] ?? '',
            'assignedTo' => $caseData['assignedTo'] ?? '',
            'revisions' => [],
            'attachments' => []
        ];
        
        // Process GCS attachments first (new direct-to-GCS upload flow)
        if (!empty($gcsAttachments)) {
            // Move files from pending path to final case path
            $practiceId = $_SESSION['current_practice_id'] ?? 0;
            if (function_exists('finalizeGcsAttachmentPaths')) {
                $gcsAttachments = finalizeGcsAttachmentPaths($gcsAttachments, $practiceId, $caseId);
            }
            
            foreach ($gcsAttachments as $gcsAttachment) {
                $completeCase['attachments'][] = $gcsAttachment;
            }
        }
        
        // Process legacy direct file attachments (fallback for small files or old flow)
        $attachmentTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];
        
        foreach ($attachmentTypes as $type) {
            if (isset($files[$type]) && !empty($files[$type]['name'][0])) {
                for ($i = 0; $i < count($files[$type]['name']); $i++) {
                    if ($files[$type]['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpFilePath = $files[$type]['tmp_name'][$i];
                        $fileName = $files[$type]['name'][$i];
                        $fileType = $files[$type]['type'][$i];
                        
                        // Upload file to Google Drive
                        $fileMetadata = new Google_Service_Drive_DriveFile([
                            'name' => $fileName,
                            'parents' => [$caseFolderId]
                        ]);
                        
                        $content = file_get_contents($tmpFilePath);
                        $file = $service->files->create($fileMetadata, [
                            'data' => $content,
                            'mimeType' => $fileType,
                            'uploadType' => 'multipart'
                        ]);
                        
                        $fileId = $file->getId();
                        
                        // Add to case attachments
                        $completeCase['attachments'][] = [
                            'id' => uniqid(),
                            'type' => ucfirst($type),
                            'fileName' => $fileName,
                            'fileType' => $fileType,
                            'driveFileId' => $fileId,
                            'uploadedAt' => date('c')
                        ];
                    }
                }
            }
        }
        
        // Create the case.json file with ENCRYPTED data
        $caseJsonMetadata = new Google_Service_Drive_DriveFile([
            'name' => 'case.json',
            'parents' => [$caseFolderId]
        ]);
        
        $service->files->create($caseJsonMetadata, [
            'data' => json_encode($completeCase, JSON_PRETTY_PRINT),
            'mimeType' => 'application/json',
            'uploadType' => 'multipart'
        ]);
        
        // Return the original case data for frontend display (already unencrypted)
        $responseData = array_merge($dataForResponse, [
            'id' => $caseId,
            'driveFolderId' => $caseFolderId,
            'creationDate' => date('c'),
            'lastUpdateDate' => date('c'),
            'status' => $dataForResponse['status'] ?? 'Originated',
            'revisions' => [],
            'attachments' => $completeCase['attachments'] ?? []
        ]);
        
        return [
            'success' => true,
            'message' => 'Case created successfully',
            'caseData' => $responseData
        ];
    } catch (Exception $e) {
        logGDMsg("Error creating case: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error creating case: ' . $e->getMessage()
        ];
    }
}

/**
 * Get or create the backup root folder for a practice in Google Drive
 * This is separate from the main case folders - it's for the backup feature
 * @param int $practiceId The practice ID
 * @param string $practiceName The practice name for folder naming
 * @return string|null Folder ID or null on failure
 */
function getBackupRootFolder($practiceId, $practiceName) {
    global $pdo;
    
    $client = getGoogleClient();
    
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return null;
    }
    
    try {
        $service = new Google_Service_Drive($client);
        
        // Folder name: "[AppName] Backup - [Practice Name]"
        global $appConfig;
        $folderName = $appConfig['appName'] . ' Backup - ' . ($practiceName ?: 'Practice ' . $practiceId);
        
        // Check if the folder already exists
        $response = $service->files->listFiles([
            'q' => "mimeType='application/vnd.google-apps.folder' and name='" . addslashes($folderName) . "' and trashed=false"
        ]);
        
        if (count($response->getFiles()) > 0) {
            return $response->getFiles()[0]->getId();
        }
        
        // Create the folder if it doesn't exist
        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);
        
        $folder = $service->files->create($folderMetadata);
        return $folder->getId();
        
    } catch (Exception $e) {
        logGDMsg('Error getting/creating backup root folder: ' . $e->getMessage());
        return null;
    }
}

/**
 * Create a backup folder for a case and populate it with case data files
 * @param array $caseData The case data (decrypted)
 * @param string $backupRootFolderId The backup root folder ID
 * @param array $attachments Array of attachment info with driveFileId
 * @return string|null The created backup folder ID or null on failure
 */
function createCaseBackupFolder($caseData, $backupRootFolderId, $attachments = []) {
    $client = getGoogleClient();
    
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return null;
    }
    
    try {
        $service = new Google_Service_Drive($client);
        
        // Create folder name: "PatientLastName, PatientFirstName - CaseID"
        $patientName = trim(($caseData['patientLastName'] ?? '') . ', ' . ($caseData['patientFirstName'] ?? ''));
        $caseId = $caseData['id'] ?? uniqid();
        $folderName = $patientName . ' - ' . $caseId;
        
        // Create the case backup folder
        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$backupRootFolderId]
        ]);
        
        $folder = $service->files->create($folderMetadata);
        $backupFolderId = $folder->getId();
        
        // Create case.json file
        $jsonContent = json_encode($caseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $jsonMetadata = new Google_Service_Drive_DriveFile([
            'name' => 'case.json',
            'parents' => [$backupFolderId]
        ]);
        $service->files->create($jsonMetadata, [
            'data' => $jsonContent,
            'mimeType' => 'application/json',
            'uploadType' => 'multipart'
        ]);
        
        // Create case.txt file (human-readable format)
        $txtContent = generateCaseTextContent($caseData);
        $txtMetadata = new Google_Service_Drive_DriveFile([
            'name' => 'case.txt',
            'parents' => [$backupFolderId]
        ]);
        $service->files->create($txtMetadata, [
            'data' => $txtContent,
            'mimeType' => 'text/plain',
            'uploadType' => 'multipart'
        ]);
        
        // Copy attachments to backup folder
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (!empty($attachment['driveFileId'])) {
                    try {
                        $copyMetadata = new Google_Service_Drive_DriveFile([
                            'name' => $attachment['fileName'] ?? 'attachment',
                            'parents' => [$backupFolderId]
                        ]);
                        $service->files->copy($attachment['driveFileId'], $copyMetadata);
                    } catch (Exception $e) {
                        logGDMsg('Error copying attachment to backup: ' . $e->getMessage());
                    }
                }
            }
        }
        
        return $backupFolderId;
        
    } catch (Exception $e) {
        logGDMsg('Error creating case backup folder: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generate human-readable text content for a case
 * @param array $caseData The case data
 * @return string The formatted text content
 */
function generateCaseTextContent($caseData) {
    $lines = [];
    $lines[] = "===========================================";
    $lines[] = "DENTAL CASE RECORD";
    $lines[] = "===========================================";
    $lines[] = "";
    $lines[] = "Case ID: " . ($caseData['id'] ?? 'N/A');
    $lines[] = "Created: " . ($caseData['creationDate'] ?? 'N/A');
    $lines[] = "Last Updated: " . ($caseData['lastUpdateDate'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "--- PATIENT INFORMATION ---";
    $lines[] = "Name: " . ($caseData['patientFirstName'] ?? '') . " " . ($caseData['patientLastName'] ?? '');
    $lines[] = "Date of Birth: " . ($caseData['patientDOB'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "--- CASE DETAILS ---";
    $lines[] = "Dentist: " . ($caseData['dentistName'] ?? 'N/A');
    $lines[] = "Case Type: " . ($caseData['caseType'] ?? 'N/A');
    $lines[] = "Material: " . ($caseData['material'] ?? 'N/A');
    $lines[] = "Tooth Shade: " . ($caseData['toothShade'] ?? 'N/A');
    $lines[] = "Due Date: " . ($caseData['dueDate'] ?? 'N/A');
    $lines[] = "Status: " . ($caseData['status'] ?? 'N/A');
    $lines[] = "";
    $lines[] = "--- NOTES ---";
    $lines[] = ($caseData['notes'] ?? 'No notes');
    $lines[] = "";
    $lines[] = "--- ATTACHMENTS ---";
    
    $attachments = $caseData['attachments'] ?? [];
    if (empty($attachments)) {
        $lines[] = "No attachments";
    } else {
        foreach ($attachments as $i => $att) {
            $lines[] = ($i + 1) . ". " . ($att['fileName'] ?? 'Unknown') . " (" . ($att['type'] ?? 'Unknown type') . ")";
        }
    }
    
    $lines[] = "";
    $lines[] = "===========================================";
    $lines[] = "Generated: " . date('Y-m-d H:i:s T');
    $lines[] = "===========================================";
    
    return implode("\n", $lines);
}

/**
 * Update case backup files (JSON and TXT) in an existing backup folder
 * @param string $backupFolderId The backup folder ID
 * @param array $caseData The updated case data
 * @return bool Success status
 */
function updateCaseBackupFiles($backupFolderId, $caseData) {
    $client = getGoogleClient();
    
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return false;
    }
    
    try {
        $service = new Google_Service_Drive($client);
        
        // Find and update case.json
        $jsonResponse = $service->files->listFiles([
            'q' => "'$backupFolderId' in parents and name='case.json' and trashed=false"
        ]);
        
        if (count($jsonResponse->getFiles()) > 0) {
            $jsonFileId = $jsonResponse->getFiles()[0]->getId();
            $jsonContent = json_encode($caseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $service->files->update($jsonFileId, new Google_Service_Drive_DriveFile(), [
                'data' => $jsonContent,
                'mimeType' => 'application/json',
                'uploadType' => 'multipart'
            ]);
        }
        
        // Find and update case.txt
        $txtResponse = $service->files->listFiles([
            'q' => "'$backupFolderId' in parents and name='case.txt' and trashed=false"
        ]);
        
        if (count($txtResponse->getFiles()) > 0) {
            $txtFileId = $txtResponse->getFiles()[0]->getId();
            $txtContent = generateCaseTextContent($caseData);
            $service->files->update($txtFileId, new Google_Service_Drive_DriveFile(), [
                'data' => $txtContent,
                'mimeType' => 'text/plain',
                'uploadType' => 'multipart'
            ]);
        }
        
        return true;
        
    } catch (Exception $e) {
        logGDMsg('Error updating case backup files: ' . $e->getMessage());
        return false;
    }
}

/**
 * Add a file to the case backup folder
 * @param string $backupFolderId The backup folder ID
 * @param string $sourceFileId The source file ID to copy
 * @param string $fileName The file name
 * @return bool Success status
 */
function addFileToBackup($backupFolderId, $sourceFileId, $fileName) {
    $client = getGoogleClient();
    
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return false;
    }
    
    try {
        $service = new Google_Service_Drive($client);
        
        $copyMetadata = new Google_Service_Drive_DriveFile([
            'name' => $fileName,
            'parents' => [$backupFolderId]
        ]);
        $service->files->copy($sourceFileId, $copyMetadata);
        
        return true;
        
    } catch (Exception $e) {
        logGDMsg('Error adding file to backup: ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove a file from the case backup folder by filename
 * @param string $backupFolderId The backup folder ID
 * @param string $fileName The file name to remove
 * @return bool Success status
 */
function removeFileFromBackup($backupFolderId, $fileName) {
    $client = getGoogleClient();
    
    if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
        return false;
    }
    
    try {
        $service = new Google_Service_Drive($client);
        
        // Find the file by name in the backup folder
        $response = $service->files->listFiles([
            'q' => "'$backupFolderId' in parents and name='" . addslashes($fileName) . "' and trashed=false"
        ]);
        
        if (count($response->getFiles()) > 0) {
            $fileId = $response->getFiles()[0]->getId();
            // Move to trash
            $service->files->update($fileId, new Google_Service_Drive_DriveFile(['trashed' => true]));
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        logGDMsg('Error removing file from backup: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a cache-only case (no Google Drive backup)
 * Used when Google Drive backup is disabled or unavailable
 * @param array $caseData The case data from the form
 * @param array $files The uploaded files data
 * @return array Status and message
 */
function createCacheOnlyCase($caseData, $files, $originalCaseData = null, $gcsAttachments = []) {
    global $pdo;
    
    // Use original data if provided, otherwise use encrypted data
    $dataForResponse = $originalCaseData ?? $caseData;
    
    try {
        // Create unique case ID
        $caseId = uniqid() . bin2hex(random_bytes(4));
        $practiceId = isset($_SESSION['current_practice_id']) ? (int)$_SESSION['current_practice_id'] : 0;
        
        // Prepare the complete case data (keeping PII encrypted)
        $completeCase = [
            'id' => $caseId,
            'driveFolderId' => null, // No Google Drive folder
            'patientFirstName' => $caseData['patientFirstName'] ?? '',
            'patientLastName' => $caseData['patientLastName'] ?? '',
            'patientDOB' => $caseData['patientDOB'] ?? '',
            'dentistName' => $caseData['dentistName'] ?? '',
            'caseType' => $caseData['caseType'] ?? '',
            'toothShade' => $caseData['toothShade'] ?? '',
            'material' => $caseData['material'] ?? null,
            'dueDate' => $caseData['dueDate'] ?? '',
            'creationDate' => date('c'),
            'lastUpdateDate' => date('c'),
            'status' => $caseData['status'] ?? 'Originated',
            'notes' => $caseData['notes'] ?? '',
            'assignedTo' => $caseData['assignedTo'] ?? '',
            'revisions' => [],
            'attachments' => []
        ];
        
        // Process GCS attachments first (new direct-to-GCS upload flow)
        if (!empty($gcsAttachments)) {
            // Move files from pending path to final case path
            if (function_exists('finalizeGcsAttachmentPaths')) {
                require_once __DIR__ . '/gcs-attachments.php';
                $gcsAttachments = finalizeGcsAttachmentPaths($gcsAttachments, $practiceId, $caseId);
            }
            
            foreach ($gcsAttachments as $gcsAttachment) {
                $completeCase['attachments'][] = $gcsAttachment;
            }
        }
        
        // Process legacy file attachments - store locally (in uploads directory)
        $attachmentTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];
        
        foreach ($attachmentTypes as $type) {
            if (isset($files[$type]) && !empty($files[$type]['name'][0])) {
                // Create uploads directory if it doesn't exist
                $uploadsDir = __DIR__ . '/../uploads/' . $caseId . '/' . $type;
                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                }
                
                foreach ($files[$type]['name'] as $index => $fileName) {
                    if ($files[$type]['error'][$index] === UPLOAD_ERR_OK) {
                        $tmpName = $files[$type]['tmp_name'][$index];
                        $destPath = $uploadsDir . '/' . $fileName;
                        
                        if (move_uploaded_file($tmpName, $destPath)) {
                            // Use flat array structure matching Google Drive format
                            $completeCase['attachments'][] = [
                                'id' => uniqid(),
                                'type' => ucfirst($type),
                                'fileName' => $fileName,
                                'name' => $fileName,
                                'path' => 'uploads/' . $caseId . '/' . $type . '/' . $fileName,
                                'fileType' => $files[$type]['type'][$index],
                                'mimeType' => $files[$type]['type'][$index],
                                'size' => $files[$type]['size'][$index],
                                'uploadedAt' => date('c')
                            ];
                        }
                    }
                }
            }
        }
        
        // Store in database cache
        require_once __DIR__ . '/cases-cache.php';
        saveCaseToCache($completeCase);
        
        // Return the original case data for frontend display (already unencrypted)
        $responseData = array_merge($dataForResponse, [
            'id' => $caseId,
            'driveFolderId' => null,
            'creationDate' => date('c'),
            'lastUpdateDate' => date('c'),
            'status' => $dataForResponse['status'] ?? 'Originated',
            'revisions' => [],
            'attachments' => $completeCase['attachments'] ?? []
        ]);
        
        return [
            'success' => true,
            'message' => 'Case created successfully',
            'caseData' => $responseData
        ];
        
    } catch (Exception $e) {
        logGDMsg('Error creating cache-only case: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to create case: ' . $e->getMessage()
        ];
    }
}

/**
 * Check if Google Drive backup is enabled for the current practice.
 * Backup is a practice-level setting stored in the practices table.
 * @return bool Whether backup is enabled for the practice
 */
function isGoogleDriveBackupEnabled() {
    global $pdo;
    
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    if (!$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT google_drive_backup_enabled FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $result = $stmt->fetchColumn();
        return (bool)$result;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if the practice has a Google Drive folder configured.
 * @return bool Whether the practice has a Drive folder set up
 */
function isPracticeCreatorDriveConnected() {
    global $pdo;
    
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    if (!$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT google_drive_folder_id FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $folderId = $stmt->fetchColumn();
        return !empty($folderId);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get the practice's Google Drive folder ID.
 * @return string|null The folder ID or null if not set
 */
function getPracticeDriveFolderId() {
    global $pdo;
    
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    if (!$practiceId) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT google_drive_folder_id FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        return $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Check if the current user has Google Workspace (can access Shared Drives).
 * This is detected by checking if the user can list Shared Drives.
 * @return array ['hasWorkspace' => bool, 'error' => string|null]
 */
function checkGoogleWorkspaceAccess() {
    if (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token'])) {
        return ['hasWorkspace' => false, 'error' => 'Google Drive not connected'];
    }
    
    try {
        $client = getGoogleClient();
        if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
            return ['hasWorkspace' => false, 'error' => 'Google Drive session expired'];
        }
        
        $service = new Google_Service_Drive($client);
        
        // Try to list Shared Drives - only Workspace accounts can do this
        // We just need to check if the API call succeeds, not the results
        $response = $service->drives->listDrives(['pageSize' => 1]);
        
        // If we get here without an exception, user has Workspace
        return ['hasWorkspace' => true, 'error' => null];
    } catch (Google_Service_Exception $e) {
        // Error 403 or similar means no Workspace access
        return ['hasWorkspace' => false, 'error' => 'Google Workspace required for backup feature'];
    } catch (Exception $e) {
        return ['hasWorkspace' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Create a shared folder for the practice's backups.
 * This creates a regular folder (not a Shared Drive) that can be shared with team members.
 * @param string $practiceName The practice name for the folder
 * @return array ['success' => bool, 'folderId' => string|null, 'error' => string|null]
 */
function createPracticeBackupFolder($practiceName) {
    global $pdo;
    
    if (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token'])) {
        return ['success' => false, 'folderId' => null, 'error' => 'Google Drive not connected'];
    }
    
    try {
        $client = getGoogleClient();
        if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
            return ['success' => false, 'folderId' => null, 'error' => 'Google Drive session expired'];
        }
        
        $service = new Google_Service_Drive($client);
        
        // Create the practice backup folder
        $folderName = $practiceName . ' - Case Backups';
        $folderMetadata = new Google_Service_Drive_DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'description' => 'Automated case backups for ' . $practiceName . '. Created by DentaTrack.'
        ]);
        
        $folder = $service->files->create($folderMetadata, ['fields' => 'id']);
        $folderId = $folder->getId();
        
        // Store the folder ID in the practice record
        $practiceId = $_SESSION['current_practice_id'] ?? 0;
        if ($practiceId && $folderId) {
            $stmt = $pdo->prepare("UPDATE practices SET google_drive_folder_id = :folder_id, google_drive_backup_enabled = TRUE WHERE id = :id");
            $stmt->execute(['folder_id' => $folderId, 'id' => $practiceId]);
        }
        
        return ['success' => true, 'folderId' => $folderId, 'error' => null];
    } catch (Exception $e) {
        logGDMsg('Error creating practice backup folder: ' . $e->getMessage());
        return ['success' => false, 'folderId' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Disable Google Drive backup for the practice.
 * @return bool Success status
 */
function disablePracticeBackup() {
    global $pdo;
    
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    if (!$practiceId) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE practices SET google_drive_backup_enabled = FALSE WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get practice name for the current practice
 * @return string The practice name
 */
function getCurrentPracticeName() {
    global $pdo;
    
    $practiceId = $_SESSION['current_practice_id'] ?? 0;
    if (!$practiceId) {
        return 'Unknown Practice';
    }
    
    try {
        $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
        $stmt->execute(['id' => $practiceId]);
        $name = $stmt->fetchColumn();
        return $name ?: 'Practice ' . $practiceId;
    } catch (Exception $e) {
        return 'Practice ' . $practiceId;
    }
}

/**
 * List all cases from Google Drive
 * @return array List of cases or error message
 */
function listCases() {
    try {
        $client = getGoogleClient();
        
        // Check for valid access token
        if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
            return [
                'success' => false,
                'message' => 'Not authenticated with Google Drive'
            ];
        }
        
        $service = @new Google_Service_Drive($client);
        $practiceId = isset($_SESSION['current_practice_id']) ? (int)$_SESSION['current_practice_id'] : 0;
        $rootFolderId = getPracticeRootFolder($practiceId);
        
        // Get all case folders. Do not request specific fields to avoid implode() bug
        $response = $service->files->listFiles([
            'q' => "'$rootFolderId' in parents and mimeType='application/vnd.google-apps.folder' and trashed=false"
        ]);
        
        $caseFolders = $response->getFiles();
        $cases = [];
        
        foreach ($caseFolders as $folder) {
            // Find the case.json file in each folder. Do not request specific fields
            $fileResponse = $service->files->listFiles([
                'q' => "'{$folder->getId()}' in parents and name='case.json' and trashed=false"
            ]);
            
            if (count($fileResponse->getFiles()) > 0) {
                $caseFileId = $fileResponse->getFiles()[0]->getId();
                
                // Get file content
                $content = $service->files->get($caseFileId, ['alt' => 'media']);
                $rawContent = $content->getBody()->getContents();
                
                $caseData = json_decode($rawContent, true);
                
                // Ensure driveFolderId is set correctly
                $caseData['driveFolderId'] = $folder->getId();
                $cases[] = $caseData;
            }
        }
        
        return [
            'success' => true,
            'cases' => $cases
        ];
    } catch (Exception $e) {
        logGDMsg("Error listing cases: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error listing cases: ' . $e->getMessage()
        ];
    }
}
