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
            
            // Process GCS file uploads (new direct-to-GCS flow)
            $gcsFilesJson = $_POST['gcs_files'] ?? '';
            if (!empty($gcsFilesJson)) {
                require_once __DIR__ . '/gcs-attachments.php';
                $gcsResult = processGcsAttachments($gcsFilesJson, $_SESSION['current_practice_id'] ?? 0);
                if ($gcsResult['success'] && !empty($gcsResult['attachments'])) {
                    // Build set of existing storage paths to prevent duplicates
                    $existingPaths = [];
                    foreach ($existingAttachments as $att) {
                        if (!empty($att['storagePath'])) {
                            $existingPaths[$att['storagePath']] = true;
                        }
                    }
                    
                    foreach ($gcsResult['attachments'] as $gcsAtt) {
                        // Only add if not already present (prevent duplicates)
                        if (empty($existingPaths[$gcsAtt['storagePath']])) {
                            $existingAttachments[] = $gcsAtt;
                            $existingPaths[$gcsAtt['storagePath']] = true;
                        }
                    }
                }
            }
            
            // Process legacy direct file uploads (fallback)
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
            
            // Process GCS file uploads (new direct-to-GCS flow)
            $gcsFilesJson = $_POST['gcs_files'] ?? '';
            if (!empty($gcsFilesJson)) {
                require_once __DIR__ . '/gcs-attachments.php';
                $gcsResult = processGcsAttachments($gcsFilesJson, $_SESSION['current_practice_id'] ?? 0);
                if ($gcsResult['success'] && !empty($gcsResult['attachments'])) {
                    if (!isset($existingCaseData['attachments']) || !is_array($existingCaseData['attachments'])) {
                        $existingCaseData['attachments'] = [];
                    }
                    
                    // Build set of existing storage paths to prevent duplicates
                    $existingPaths = [];
                    foreach ($existingCaseData['attachments'] as $att) {
                        if (!empty($att['storagePath'])) {
                            $existingPaths[$att['storagePath']] = true;
                        }
                    }
                    
                    foreach ($gcsResult['attachments'] as $gcsAtt) {
                        // Only add if not already present (prevent duplicates)
                        if (empty($existingPaths[$gcsAtt['storagePath']])) {
                            $existingCaseData['attachments'][] = $gcsAtt;
                            $existingPaths[$gcsAtt['storagePath']] = true;
                        }
                    }
                }
            }
            
            // Process legacy file attachments (fallback for direct uploads)
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

    // Check if backup is enabled and the practice has a Drive folder configured
    if (isGoogleDriveBackupEnabled() && !isPracticeCreatorDriveConnected()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'drive_not_connected' => true,
            'message' => 'Google Drive backup is enabled but the backup folder is not configured. A practice admin needs to re-enable backup from Settings.'
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
        
        // Get field requirements from config (allows easy customization)
        $fieldRequirements = $appConfig['case_required_fields'] ?? [];
        
        // Build required fields list from config
        $requiredFields = [];
        $allFields = ['patientFirstName', 'patientLastName', 'patientDOB', 'patientGender',
                      'dentistName', 'caseType', 'dueDate', 'status', 'toothShade', 'material',
                      'assignedTo', 'notes'];
        
        foreach ($allFields as $field) {
            // Default: first 8 fields are required, rest are optional
            $defaultRequired = in_array($field, ['patientFirstName', 'patientLastName', 'patientDOB', 
                                                  'patientGender', 'dentistName', 'caseType', 'dueDate', 'status']);
            $isRequired = $fieldRequirements[$field] ?? $defaultRequired;
            if ($isRequired) {
                $requiredFields[] = $field;
            }
        }
        
        $caseData = [
            'id' => $_POST['caseId'], // Add the case ID
            'driveFolderId' => $_POST['driveFolderId'] ?? null // Make sure we have the folder ID
        ];
        
        // Get version for optimistic locking (concurrent edit detection)
        $expectedVersion = isset($_POST['version']) ? (int)$_POST['version'] : null;
        
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || $_POST[$field] === '') {
                $missingFields[] = $field;
            } else {
                $caseData[$field] = $_POST[$field];
            }
        }
        
        // Add optional fields (fields not in requiredFields)
        $optionalFields = array_diff($allFields, $requiredFields);
        foreach ($optionalFields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $caseData[$field] = $_POST[$field];
            } elseif ($field === 'notes' && isset($_POST[$field])) {
                // Notes can be empty string
                $caseData[$field] = $_POST[$field];
            }
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
        
        // ============================================
        // CASE NOTES CHARACTER LIMIT VALIDATION
        // Business Rule: Notes field is limited to 3,000 characters.
        // Server-side enforcement prevents bypass of client-side limit.
        // ============================================
        $notesMaxLength = 3000;
        if (isset($caseData['notes']) && strlen($caseData['notes']) > $notesMaxLength) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Notes cannot exceed {$notesMaxLength} characters. Current length: " . strlen($caseData['notes']) . " characters.",
                'field' => 'notes'
            ]);
            exit;
        }
        
        // ============================================
        // TOOTH NUMBER VALIDATION (Case-Type Aware)
        // Business Rule: For Crown case type, validates tooth number
        // using standard dental numbering (1-32 for adult teeth).
        // Server-side enforcement prevents bypass of client-side validation.
        // ============================================
        $caseType = $_POST['caseType'] ?? '';
        
        if ($caseType === 'Crown' && !empty($clinicalDetails['toothNumber'])) {
            $toothNumber = trim($clinicalDetails['toothNumber']);
            
            // Validate: must be numeric and between 1-32
            if (!ctype_digit($toothNumber)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Tooth number must be a number (1-32)',
                    'field' => 'clinicalToothNumber'
                ]);
                exit;
            }
            
            $toothNum = (int)$toothNumber;
            if ($toothNum < 1 || $toothNum > 32) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Tooth number must be between 1 and 32',
                    'field' => 'clinicalToothNumber'
                ]);
                exit;
            }
        }
        
        // Validate CASE-TYPE-SPECIFIC required fields from config
        
        // Map case types to their clinical fields
        $caseTypeClinicalFields = [
            'Crown' => ['toothNumber'],
            'Bridge' => ['abutmentTeeth', 'ponticTeeth'],
            'Implant Crown' => ['implantToothNumber', 'abutmentType', 'implantSystem', 'platformSize', 'scanBodyUsed'],
            'Implant Surgical Guide' => ['implantSites'],
            'Denture' => ['dentureJaw', 'dentureType', 'gingivalShade'],
            'Partial' => ['partialJaw', 'teethToReplace', 'partialMaterial', 'partialGingivalShade'],
        ];
        
        // Check clinical fields for current case type
        if (isset($caseTypeClinicalFields[$caseType])) {
            foreach ($caseTypeClinicalFields[$caseType] as $clinicalField) {
                $isRequired = $fieldRequirements[$clinicalField] ?? false;
                if ($isRequired && empty($clinicalDetails[$clinicalField])) {
                    $missingFields[] = 'clinical_' . $clinicalField;
                }
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
                'toothShade' => 'Tooth Shade',
                'material' => 'Material',
                'assignedTo' => 'Assigned To',
                'notes' => 'Notes',
                // Clinical fields
                'clinical_toothNumber' => 'Tooth # (Crown)',
                'clinical_abutmentTeeth' => 'Abutment Teeth (Bridge)',
                'clinical_ponticTeeth' => 'Pontic Teeth (Bridge)',
                'clinical_implantToothNumber' => 'Tooth # (Implant Crown)',
                'clinical_abutmentType' => 'Abutment Type (Implant Crown)',
                'clinical_implantSystem' => 'Implant System (Implant Crown)',
                'clinical_platformSize' => 'Platform Size (Implant Crown)',
                'clinical_scanBodyUsed' => 'Scan Body Used (Implant Crown)',
                'clinical_implantSites' => 'Implant Sites (Surgical Guide)',
                'clinical_dentureJaw' => 'Jaw (Denture)',
                'clinical_dentureType' => 'Denture Type',
                'clinical_gingivalShade' => 'Gingival Shade (Denture)',
                'clinical_partialJaw' => 'Jaw (Partial)',
                'clinical_teethToReplace' => 'Teeth to Replace (Partial)',
                'clinical_partialMaterial' => 'Material (Partial)',
                'clinical_partialGingivalShade' => 'Gingival Shade (Partial)',
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
        
        // EARLY VERSION CHECK - must happen BEFORE any data modifications
        // This prevents the race condition where data is modified before conflict is detected
        if ($expectedVersion !== null) {
            $currentVersion = getCaseVersion($_POST['caseId']);
            if ($currentVersion !== null && $currentVersion !== $expectedVersion) {
                // Version mismatch - another user has edited this case
                $currentData = getCaseFromCache($_POST['caseId']);
                http_response_code(409); // Conflict
                echo json_encode([
                    'success' => false,
                    'conflict' => true,
                    'message' => 'This case was modified by another user. Please review their changes.',
                    'expectedVersion' => $expectedVersion,
                    'currentVersion' => $currentVersion,
                    'currentData' => $currentData
                ]);
                exit;
            }
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
                // Save ENCRYPTED data to cache with version check (optimistic locking)
                $encryptedForCache = PIIEncryption::encryptCaseData($result['caseData']);
                
                // If version was provided, use optimistic locking
                if ($expectedVersion !== null) {
                    $versionResult = updateCaseWithVersionCheck($encryptedForCache, $expectedVersion);
                    
                    if (!$versionResult['success'] && isset($versionResult['conflict']) && $versionResult['conflict']) {
                        // Version conflict - another user edited the case
                        http_response_code(409); // Conflict
                        echo json_encode([
                            'success' => false,
                            'conflict' => true,
                            'message' => $versionResult['message'],
                            'expectedVersion' => $versionResult['expectedVersion'] ?? $expectedVersion,
                            'currentVersion' => $versionResult['currentVersion'],
                            'currentData' => $versionResult['currentData']
                        ]);
                        exit;
                    } elseif (!$versionResult['success']) {
                        // Other error
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'message' => $versionResult['error'] ?? 'Failed to save case'
                        ]);
                        exit;
                    }
                    
                    // Update the result with new version
                    $result['caseData']['version'] = $versionResult['newVersion'];
                    $result['newVersion'] = $versionResult['newVersion'];
                } else {
                    // No version provided - use regular save (backwards compatibility)
                    saveCaseToCache($encryptedForCache);
                }

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
            
            // Record update for real-time notifications to other users
            if ($updatedCaseId && function_exists('recordCaseUpdate')) {
                recordCaseUpdate($updatedCaseId, 'update');
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
