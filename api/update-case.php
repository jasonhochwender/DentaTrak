<?php
// Update Case API endpoint
require_once __DIR__ . '/session.php'; // centralized session handling
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/at-risk-calculator.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

// Set security headers
setApiSecurityHeaders();

// SECURITY: Require valid practice context before any case operations
$currentPracticeId = requireValidPracticeContext();

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// Disable PHP error display for API - return only JSON
ini_set('display_errors', '0');
// Suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Set up error handler to catch and return errors as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignore deprecation-style warnings from the Google client library
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return true; // swallow and continue
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "PHP Error: $errstr in $errfile on line $errline",
        'error' => $errstr
    ]);
    exit;
});

try {
    // Load Google Drive integration
    require_once __DIR__ . '/google-drive.php';

    // Function to update case in database only (when Google Drive fails)
    function updateCaseInDatabaseOnly($caseData, $files = [], $filesToDelete = []) {
        global $pdo;
        try {
            // Get existing case data from cache to preserve attachments and other fields
            $existingCase = getCaseFromCache($caseData['id']);
            
            // Merge existing case data with new data (new data takes precedence)
            if ($existingCase && is_array($existingCase)) {
                $caseData = array_merge($existingCase, $caseData);
            }
            
            $existingAttachments = [];
            if (isset($caseData['attachments'])) {
                $existingAttachments = $caseData['attachments'];
                // Flatten if nested by type
                if (is_array($existingAttachments) && !isset($existingAttachments[0])) {
                    $flattened = [];
                    foreach ($existingAttachments as $type => $typeAttachments) {
                        if (is_array($typeAttachments)) {
                            foreach ($typeAttachments as $att) {
                                $att['type'] = ucfirst($type);
                                $flattened[] = $att;
                            }
                        }
                    }
                    $existingAttachments = $flattened;
                }
            }
            
            // Handle file deletions
            if (!empty($filesToDelete) && is_array($filesToDelete)) {
                foreach ($filesToDelete as $fileInfo) {
                    $attachmentId = $fileInfo['attachmentId'] ?? null;
                    if ($attachmentId) {
                        $existingAttachments = array_filter($existingAttachments, function($att) use ($attachmentId) {
                            return !isset($att['id']) || $att['id'] != $attachmentId;
                        });
                    }
                }
                $existingAttachments = array_values($existingAttachments);
            }
            
            // Process new file uploads
            $attachmentTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];
            $caseId = $caseData['id'];
            
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
                                $existingAttachments[] = [
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
            
            // Update case data with attachments
            $caseData['attachments'] = $existingAttachments;
            
            // Encrypt the case data before saving to cache
            $encryptedCaseData = PIIEncryption::encryptCaseData($caseData);
            saveCaseToCache($encryptedCaseData);
            
            return [
                'success' => true,
                'message' => 'Case updated successfully (database only)',
                'caseData' => $caseData,
                'driveFolderId' => $caseData['driveFolderId'] ?? null
            ];
        } catch (Exception $e) {
            error_log('[update-case] Database update failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update case: ' . $e->getMessage()
            ];
        }
    }

    // Function to update a case in Google Drive
    function updateCase($caseId, $caseData, $files, $filesToDelete = []) {
        global $pdo;
        try {
            // Check if Google Drive backup is enabled
            $backupEnabled = isGoogleDriveBackupEnabled();
            
            // If backup is not enabled, just update the database
            if (!$backupEnabled) {
                return updateCaseInDatabaseOnly($caseData, $files, $filesToDelete);
            }
            
            $client = getGoogleClient();
            
            // Check for valid access token - if not available, fall back to database-only update
            if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
                // Google Drive token expired - update database only with warning
                $result = updateCaseInDatabaseOnly($caseData, $files, $filesToDelete);
                if ($result['success']) {
                    $result['warning'] = 'Google Drive session expired. Changes saved locally only. Reconnect Google Drive from Settings to sync.';
                }
                return $result;
            }
            
            $service = new Google_Service_Drive($client);
            
            // Get the case folder ID from the passed caseData
            $caseFolderId = $caseData['driveFolderId'] ?? null;
            if (!$caseFolderId) {
                try {
                    // Search for a folder with the case ID as its name
                    $folderResponse = $service->files->listFiles([
                        'q' => "name='" . addslashes($caseData['id']) . "' and mimeType='application/vnd.google-apps.folder' and trashed=false",
                        'fields' => 'files(id,name,parents)'
                    ]);
                    
                    if (count($folderResponse->getFiles()) > 0) {
                        $caseFolderId = $folderResponse->getFiles()[0]->getId();
                        
                        // Update the case data with the found folder ID
                        $caseData['driveFolderId'] = $caseFolderId;
                        
                        // Update the cache with the found drive folder ID so this doesn't happen again
                        try {
                            $updateStmt = $pdo->prepare("UPDATE cases_cache SET drive_folder_id = :drive_folder_id WHERE case_id = :case_id");
                            $updateStmt->execute([
                                'drive_folder_id' => $caseFolderId,
                                'case_id' => $caseData['id']
                            ]);
                        } catch (Exception $e) {
                            error_log('[update-case] Failed to update cache: ' . $e->getMessage());
                        }
                    } else {
                        // Create a new folder for this case
                        $folderName = $caseData['id'];
                        $folderMetadata = new Google_Service_Drive_DriveFile([
                            'name' => $folderName,
                            'mimeType' => 'application/vnd.google-apps.folder'
                        ]);
                        
                        $createdFolder = $service->files->create($folderMetadata, [
                            'fields' => 'id,name'
                        ]);
                        
                        $caseFolderId = $createdFolder->getId();
                        
                        // Update the case data with the new folder ID
                        $caseData['driveFolderId'] = $caseFolderId;
                        
                        // Update the cache with the new drive folder ID
                        try {
                            $updateStmt = $pdo->prepare("UPDATE cases_cache SET drive_folder_id = :drive_folder_id WHERE case_id = :case_id");
                            $updateStmt->execute([
                                'drive_folder_id' => $caseFolderId,
                                'case_id' => $caseData['id']
                            ]);
                        } catch (Exception $e) {
                            error_log('[update-case] Failed to update cache: ' . $e->getMessage());
                        }
                    }
                } catch (Exception $e) {
                    // Continue without Google Drive - just update database
                    return updateCaseInDatabaseOnly($caseData);
                }
            }
            
            // Find the case.json file in the folder
            $fileResponse = $service->files->listFiles([
                'q' => "'$caseFolderId' in parents and name='case.json' and trashed=false"
            ]);
            
            if (count($fileResponse->getFiles()) === 0) {
                // Create the case.json file if it doesn't exist
                $caseJsonContent = json_encode($caseData, JSON_PRETTY_PRINT);
                $fileMetadata = new Google_Service_Drive_DriveFile([
                    'name' => 'case.json',
                    'parents' => [$caseFolderId]
                ]);
                
                $createdFile = $service->files->create($fileMetadata, [
                    'data' => $caseJsonContent,
                    'mimeType' => 'application/json',
                    'uploadType' => 'multipart',
                    'fields' => 'id'
                ]);
                
                $caseFileId = $createdFile->getId();
            } else {
                $caseFileId = $fileResponse->getFiles()[0]->getId();
            }
            
            // Get current case data
            $content = $service->files->get($caseFileId, ['alt' => 'media']);
            $existingCaseData = json_decode($content->getBody()->getContents(), true);
            
            if (!$existingCaseData) {
                return [
                    'success' => false,
                    'message' => 'Failed to parse existing case data'
                ];
            }
            
            // Decrypt existing case data for comparison
            $existingCaseData = PIIEncryption::decryptCaseData($existingCaseData);
            
            // Update case data with new values
            $changedFields = [];
            
            if ($existingCaseData['patientFirstName'] !== $caseData['patientFirstName']) {
                $changedFields[] = 'patientFirstName';
            }
            if ($existingCaseData['patientLastName'] !== $caseData['patientLastName']) {
                $changedFields[] = 'patientLastName';
            }
            if ($existingCaseData['patientDOB'] !== $caseData['patientDOB']) {
                $changedFields[] = 'patientDOB';
            }
            if ($existingCaseData['dentistName'] !== $caseData['dentistName']) {
                $changedFields[] = 'dentistName';
            }
            if ($existingCaseData['caseType'] !== $caseData['caseType']) {
                $changedFields[] = 'caseType';
            }
            if (($existingCaseData['material'] ?? '') !== ($caseData['material'] ?? '')) {
                $changedFields[] = 'material';
            }
            if ($existingCaseData['dueDate'] !== $caseData['dueDate']) {
                $changedFields[] = 'dueDate';
            }
            if ($existingCaseData['status'] !== $caseData['status']) {
                $changedFields[] = 'status';
            }
            if (($existingCaseData['notes'] ?? '') !== ($caseData['notes'] ?? '')) {
                $changedFields[] = 'notes';
            }
            if (($existingCaseData['patientGender'] ?? '') !== ($caseData['patientGender'] ?? '')) {
                $changedFields[] = 'patientGender';
            }
            if (json_encode($existingCaseData['clinicalDetails'] ?? []) !== json_encode($caseData['clinicalDetails'] ?? [])) {
                $changedFields[] = 'clinicalDetails';
            }
            
            $existingCaseData['patientFirstName'] = $caseData['patientFirstName'];
            $existingCaseData['patientLastName'] = $caseData['patientLastName'];
            $existingCaseData['patientDOB'] = $caseData['patientDOB'];
            $existingCaseData['patientGender'] = $caseData['patientGender'] ?? null;
            $existingCaseData['dentistName'] = $caseData['dentistName'];
            $existingCaseData['caseType'] = $caseData['caseType'];
            $existingCaseData['toothShade'] = $caseData['toothShade'];
            $existingCaseData['material'] = $caseData['material'] ?? null;
            $existingCaseData['dueDate'] = $caseData['dueDate'];
            $existingCaseData['status'] = $caseData['status'];
            $existingCaseData['notes'] = $caseData['notes'] ?? '';
            $existingCaseData['clinicalDetails'] = $caseData['clinicalDetails'] ?? [];
            $existingCaseData['lastUpdateDate'] = date('c'); // Update the timestamp
            
            // Encrypt PII before saving
            $encryptedCaseData = PIIEncryption::encryptCaseData($existingCaseData);
            
            // Process files marked for deletion
            if (!empty($filesToDelete)) {
                foreach ($filesToDelete as $fileToDelete) {
                    $fileId = $fileToDelete['fileId'];
                    $attachmentId = $fileToDelete['attachmentId'];
                    
                    // Delete file from Google Drive (move to trash)
                    try {
                        $service->files->update($fileId, new Google_Service_Drive_DriveFile(['trashed' => true]));
                    } catch (Exception $e) {
                        // Log error but continue processing
                        error_log("Failed to delete file $fileId from Drive: " . $e->getMessage());
                    }
                    
                    // Remove attachment from case data
                    if (isset($existingCaseData['attachments']) && is_array($existingCaseData['attachments'])) {
                        $existingCaseData['attachments'] = array_filter($existingCaseData['attachments'], function($attachment) use ($attachmentId) {
                            return !isset($attachment['id']) || $attachment['id'] != $attachmentId;
                        });
                        // Re-index array
                        $existingCaseData['attachments'] = array_values($existingCaseData['attachments']);
                    }
                }
                
                // Log the file deletions as activity
                logCaseActivity(
                    $caseId,
                    'attachments_deleted',
                    null,
                    null,
                    ['files_deleted' => count($filesToDelete)]
                );
            }
            
            // Process file attachments
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
                            $existingCaseData['attachments'][] = [
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
            
            // Update case.json file in Drive with encrypted data
            $updatedFile = new Google_Service_Drive_DriveFile();
            $service->files->update($caseFileId, $updatedFile, [
                'data' => json_encode($encryptedCaseData, JSON_PRETTY_PRINT),
                'mimeType' => 'application/json',
                'uploadType' => 'multipart'
            ]);
            
            return [
                'success' => true,
                'message' => 'Case updated successfully',
                'caseData' => $existingCaseData, // Return decrypted data for UI
                'changedFields' => $changedFields
            ];
        } catch (Exception $e) {
            error_log('[update-case] Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error updating case: ' . $e->getMessage()
            ];
        }
    }

    // Google Drive is only required if the user has enabled backup
    if (isGoogleDriveBackupEnabled() && (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token']))) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'drive_not_connected' => true,
            'message' => 'Google Drive backup is enabled but Google Drive is not connected. Please connect Google Drive from Settings or disable backup.'
        ]);
        exit;
    }

    // Process form data
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if we have a case ID
        if (!isset($_POST['caseId']) || empty($_POST['caseId'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Case ID is required for updates'
            ]);
            exit;
        }
        
        // Validate GLOBAL required fields
        $requiredFields = [
            'patientFirstName', 'patientLastName', 'patientDOB', 'patientGender',
            'dentistName', 'caseType', 'dueDate', 'status'
        ];
        
        $caseData = [
            'id' => $_POST['caseId'], // Add the case ID
            'driveFolderId' => $_POST['driveFolderId'] ?? null // Make sure we have the folder ID
        ];
        
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                $missingFields[] = $field;
            } else {
                $caseData[$field] = $_POST[$field];
            }
        }
        
        // Add optional global fields
        if (isset($_POST['toothShade']) && !empty($_POST['toothShade'])) {
            $caseData['toothShade'] = $_POST['toothShade'];
        }
        
        if (isset($_POST['material']) && !empty($_POST['material'])) {
            $caseData['material'] = $_POST['material'];
        }
        
        // Add optional fields
        if (isset($_POST['notes'])) {
            $caseData['notes'] = $_POST['notes'];
        }
        
        // Add clinical details (case-type-specific fields)
        // Clinical details come as JSON from frontend getClinicalDetailsData()
        $clinicalDetails = [];
        if (isset($_POST['clinicalDetails']) && !empty($_POST['clinicalDetails'])) {
            $clinicalDetails = json_decode($_POST['clinicalDetails'], true);
            if (is_array($clinicalDetails)) {
                $caseData['clinicalDetails'] = $clinicalDetails;
            }
        }
        
        // Handle case assignment (optional)
        if (isset($_POST['assignedTo']) && !empty($_POST['assignedTo'])) {
            $caseData['assignedTo'] = $_POST['assignedTo'];
        }
        
        // Validate CASE-TYPE-SPECIFIC required fields
        // Check against the clinicalDetails JSON, not individual POST fields
        $caseType = $_POST['caseType'] ?? '';
        
        // Crown: Tooth # required (key: toothNumber)
        if ($caseType === 'Crown') {
            if (empty($clinicalDetails['toothNumber'])) {
                $missingFields[] = 'clinicalToothNumber';
            }
        }
        
        // Bridge: Abutment Teeth and Pontic Teeth required
        if ($caseType === 'Bridge') {
            if (empty($clinicalDetails['abutmentTeeth'])) {
                $missingFields[] = 'clinicalAbutmentTeeth';
            }
            if (empty($clinicalDetails['ponticTeeth'])) {
                $missingFields[] = 'clinicalPonticTeeth';
            }
        }
        
        // Implant Crown: Tooth # required (key: implantToothNumber)
        if ($caseType === 'Implant Crown') {
            if (empty($clinicalDetails['implantToothNumber'])) {
                $missingFields[] = 'clinicalImplantToothNumber';
            }
        }
        
        // Partial: Teeth to be Replaced required (key: teethToReplace)
        if ($caseType === 'Partial') {
            if (empty($clinicalDetails['teethToReplace'])) {
                $missingFields[] = 'clinicalTeethToReplace';
            }
        }
        
        // Return error if required fields are missing
        if (!empty($missingFields)) {
            http_response_code(400);
            
            // Generate user-friendly field names
            $fieldLabels = [
                'patientFirstName' => 'Patient First Name',
                'patientLastName' => 'Patient Last Name',
                'patientDOB' => 'Patient DOB',
                'patientGender' => 'Gender',
                'dentistName' => 'Dentist Name',
                'caseType' => 'Case Type',
                'dueDate' => 'Due Date',
                'status' => 'Status',
                'clinicalToothNumber' => 'Tooth # (required for Crown)',
                'clinicalAbutmentTeeth' => 'Abutment Teeth (required for Bridge)',
                'clinicalPonticTeeth' => 'Pontic Teeth (required for Bridge)',
                'clinicalImplantToothNumber' => 'Tooth # (required for Implant Crown)',
                'clinicalTeethToReplace' => 'Teeth to be Replaced (required for Partial)'
            ];
            
            $friendlyNames = array_map(function($field) use ($fieldLabels) {
                return $fieldLabels[$field] ?? $field;
            }, $missingFields);
            
            echo json_encode([
                'success' => false,
                'message' => 'Please fill in the following required fields: ' . implode(', ', $friendlyNames),
                'missingFields' => $missingFields
            ]);
            exit;
        }
        
        // Update the case
        $filesToDelete = [];
        if (isset($_POST['filesToDelete'])) {
            $filesToDelete = json_decode($_POST['filesToDelete'], true);
            if ($filesToDelete === null) {
                $filesToDelete = [];
            }
        }
        
        $result = updateCase($_POST['caseId'], $caseData, $_FILES, $filesToDelete);
        
        // Process case assignment if successful and assignedTo is provided
        if ($result['success'] && isset($caseData['assignedTo']) && !empty($caseData['assignedTo'])) {
            // Get user ID from email
            $assigneeEmail = $caseData['assignedTo'];
            try {
                // Find the user ID by email
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
                $stmt->execute(['email' => $assigneeEmail]);
                $assigneeUser = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($assigneeUser) {
                    $assigneeId = $assigneeUser['id'];
                    $currentUserId = $_SESSION['db_user_id'];
                    
                    // Check if assignment already exists
                    $stmt = $pdo->prepare("SELECT id FROM case_assignments WHERE case_id = :case_id LIMIT 1");
                    $stmt->execute(['case_id' => $_POST['caseId']]);
                    $existingAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingAssignment) {
                        // Update existing assignment
                        $stmt = $pdo->prepare("UPDATE case_assignments SET user_id = :user_id, assigned_by = :assigned_by, updated_at = NOW() WHERE id = :id");
                        $stmt->execute([
                            'user_id' => $assigneeId,
                            'assigned_by' => $currentUserId,
                            'id' => $existingAssignment['id']
                        ]);
                    } else {
                        // Create new assignment
                        $stmt = $pdo->prepare("INSERT INTO case_assignments (case_id, user_id, assigned_by) VALUES (:case_id, :user_id, :assigned_by)");
                        $stmt->execute([
                            'case_id' => $_POST['caseId'],
                            'user_id' => $assigneeId,
                            'assigned_by' => $currentUserId
                        ]);
                    }
                    
                    // Add assignment info to result
                    $result['assignment'] = [
                        'email' => $assigneeEmail,
                        'userId' => $assigneeId
                    ];
                }
            } catch (PDOException $e) {
                // Log error but don't fail the whole operation
                error_log('[update-case] Assignment error: ' . $e->getMessage());
                $result['assignmentError'] = 'Error updating assignment: ' . $e->getMessage();
            }
        }
        
        // Return the result
        if ($result['success']) {
            if (isset($result['caseData']) && is_array($result['caseData'])) {
                // Save ENCRYPTED data to cache (re-encrypt the decrypted data returned from updateCase)
                $encryptedForCache = PIIEncryption::encryptCaseData($result['caseData']);
                saveCaseToCache($encryptedForCache);

                // Log a generic case update activity (may include status changes)
                $updatedCaseId = $result['caseData']['id'] ?? ($_POST['caseId'] ?? null);
                $updatedStatus = $result['caseData']['status'] ?? ($_POST['status'] ?? null);
                $changedFields = $result['changedFields'] ?? [];
                if ($updatedCaseId) {
                    logCaseActivity(
                        $updatedCaseId,
                        'case_updated',
                        null,
                        $updatedStatus,
                        [
                            'changed_fields' => $changedFields,
                            'fields_count' => count($changedFields)
                        ]
                    );

                    // Log attachment summary on update ONLY if files were actually added
                    // (File deletions are logged separately above)
                    $attachments = $result['caseData']['attachments'] ?? [];
                    $filesWereAdded = !empty($files) && is_array($files) && count(array_filter($files, function($f) { 
                        return !empty($f['tmp_name']) && is_uploaded_file($f['tmp_name']); 
                    })) > 0;
                    if ($filesWereAdded && is_array($attachments)) {
                        logCaseActivity(
                            $updatedCaseId,
                            'attachments_updated',
                            null,
                            null,
                            [
                                'count' => count($attachments),
                                'source' => 'update-case.php',
                            ]
                        );
                    }

                    // Log notes summary on update ONLY if notes actually changed
                    if (in_array('notes', $changedFields)) {
                        $notes = $result['caseData']['notes'] ?? '';
                        logCaseActivity(
                            $updatedCaseId,
                            'notes_updated',
                            null,
                            null,
                            [
                                'length' => strlen($notes),
                                'source' => 'update-case.php',
                            ]
                        );
                    }
                    
                    // Handle labels update if provided
                    if (isset($_POST['labels'])) {
                        try {
                            $labelIds = json_decode($_POST['labels'], true);
                            if (!is_array($labelIds)) {
                                $labelIds = [];
                            }
                            
                            $userId = $_SESSION['db_user_id'] ?? 0;
                            
                            // Ensure label tables exist
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS case_label_assignments (
                                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                    case_id VARCHAR(64) NOT NULL,
                                    label_id INT UNSIGNED NOT NULL,
                                    assigned_by INT UNSIGNED NOT NULL,
                                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    UNIQUE KEY unique_case_label (case_id, label_id),
                                    INDEX idx_case_id (case_id),
                                    INDEX idx_label_id (label_id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                            ");
                            
                            // Remove existing label assignments
                            $stmt = $pdo->prepare("DELETE FROM case_label_assignments WHERE case_id = :case_id");
                            $stmt->execute(['case_id' => $updatedCaseId]);
                            
                            // Add new label assignments
                            if (!empty($labelIds)) {
                                $labelStmt = $pdo->prepare("
                                    INSERT INTO case_label_assignments (case_id, label_id, assigned_by)
                                    VALUES (:case_id, :label_id, :assigned_by)
                                ");
                                
                                foreach ($labelIds as $labelId) {
                                    try {
                                        $labelStmt->execute([
                                            'case_id' => $updatedCaseId,
                                            'label_id' => (int)$labelId,
                                            'assigned_by' => $userId
                                        ]);
                                    } catch (PDOException $e) {
                                        // Ignore duplicate or invalid label assignments
                                    }
                                }
                            }
                            
                            // Add labels to result for UI
                            $labelsStmt = $pdo->prepare("
                                SELECT cl.id, cl.name, cl.color
                                FROM case_labels cl
                                JOIN case_label_assignments cla ON cl.id = cla.label_id
                                WHERE cla.case_id = :case_id
                            ");
                            $labelsStmt->execute(['case_id' => $updatedCaseId]);
                            $result['caseData']['labels'] = $labelsStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            // Labels table may not exist yet - continue without labels
                            error_log('[update-case] Error updating labels: ' . $e->getMessage());
                        }
                    }
                    
                    // Check if Google Drive backup sync is needed - store data for deferred processing
                    $doBackupSync = false;
                    $backupSyncData = null;
                    if (isGoogleDriveBackupEnabled()) {
                        try {
                            $stmt = $pdo->prepare("SELECT backup_folder_id FROM cases_cache WHERE case_id = :case_id");
                            $stmt->execute(['case_id' => $updatedCaseId]);
                            $existingBackupFolderId = $stmt->fetchColumn();
                            
                            if ($existingBackupFolderId) {
                                $doBackupSync = true;
                                $backupSyncData = [
                                    'backupFolderId' => $existingBackupFolderId,
                                    'caseData' => $result['caseData'],
                                    'filesToDelete' => $filesToDelete,
                                    'attachments' => $result['caseData']['attachments'] ?? []
                                ];
                            }
                        } catch (Exception $e) {
                            error_log('[update-case] Error checking backup folder: ' . $e->getMessage());
                        }
                    }
                }
            }
            
            // Calculate At Risk status for the updated case
            if (isset($result['caseData']) && is_array($result['caseData'])) {
                $atRiskStatus = calculateAtRiskStatus($result['caseData'], null);
                $result['caseData']['atRisk'] = $atRiskStatus;
            }
            
            // Send response to client FIRST
            echo json_encode($result);
            
            // Flush output to client so they don't wait for backup sync
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }
            
            // Now perform the backup sync operation after response is sent
            if (isset($doBackupSync) && $doBackupSync && isset($backupSyncData)) {
                try {
                    // Update the JSON and TXT files
                    updateCaseBackupFiles($backupSyncData['backupFolderId'], $backupSyncData['caseData']);
                    
                    // Handle deleted files - remove from backup
                    if (!empty($backupSyncData['filesToDelete'])) {
                        foreach ($backupSyncData['filesToDelete'] as $fileToDelete) {
                            $fileName = $fileToDelete['fileName'] ?? null;
                            if ($fileName) {
                                removeFileFromBackup($backupSyncData['backupFolderId'], $fileName);
                            }
                        }
                    }
                    
                    // Handle newly added files - copy to backup
                    foreach ($backupSyncData['attachments'] as $attachment) {
                        $uploadedAt = $attachment['uploadedAt'] ?? null;
                        if ($uploadedAt) {
                            $uploadTime = strtotime($uploadedAt);
                            if ($uploadTime && (time() - $uploadTime) < 60) {
                                $driveFileId = $attachment['driveFileId'] ?? null;
                                $fileName = $attachment['fileName'] ?? null;
                                if ($driveFileId && $fileName) {
                                    addFileToBackup($backupSyncData['backupFolderId'], $driveFileId, $fileName);
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('[update-case] Backup sync error (non-blocking): ' . $e->getMessage());
                }
            }
            exit;
        } else {
            http_response_code(500);
            echo json_encode($result);
        }
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
