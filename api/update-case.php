<?php
// Update Case API endpoint
require_once __DIR__ . '/session.php'; // centralized session handling
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/at-risk-calculator.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/tooth-number-parser.php';
require_once __DIR__ . '/gcs-attachments.php';

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

            // Detect a dueDate change BEFORE the merge below overwrites it,
            // so callers (and case_updated activity logging) can tell
            // whether the due date was edited. Scoped to dueDate only (not
            // a full field diff) since dueDate is the one field consumers
            // currently depend on for historical accuracy (Lab Insights'
            // Late Delivery Rate excludes cases whose due date was edited
            // after their lab period completed - see get-lab-insights.php).
            // PII fields are intentionally NOT compared here: $existingCase
            // holds still-encrypted ciphertext from the DB while incoming
            // $caseData holds plaintext from the request, so a naive
            // comparison would always appear "changed" for those fields.
            $changedFields = [];
            if ($existingCase && is_array($existingCase) && array_key_exists('dueDate', $caseData)) {
                $oldDueDate = $existingCase['dueDate'] ?? null;
                $newDueDate = $caseData['dueDate'] ?? null;
                if ($oldDueDate !== $newDueDate) {
                    $changedFields[] = 'dueDate';
                }
            }
            if ($existingCase && is_array($existingCase) && array_key_exists('patientAppointmentDate', $caseData)) {
                $oldApptDate = $existingCase['patientAppointmentDate'] ?? null;
                $newApptDate = $caseData['patientAppointmentDate'] ?? null;
                if ($oldApptDate !== $newApptDate) {
                    $changedFields[] = 'patientAppointmentDate';
                }
            }

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
            // SECURITY: The authoritative storage path is looked up from the
            // case's own existing attachment record (never trusted from the
            // client), then the GCS object is physically deleted to reclaim
            // storage.
            if (!empty($filesToDelete) && is_array($filesToDelete)) {
                foreach ($filesToDelete as $fileInfo) {
                    $attachmentId = $fileInfo['attachmentId'] ?? null;
                    if ($attachmentId) {
                        foreach ($existingAttachments as $att) {
                            if (isset($att['id']) && $att['id'] == $attachmentId
                                && ($att['storageType'] ?? '') === 'gcs' && !empty($att['storagePath'])) {
                                if (!deleteGcsObject($att['storagePath'])) {
                                    error_log("[update-case] Failed to delete GCS object {$att['storagePath']} for attachment {$attachmentId}");
                                }
                                break;
                            }
                        }
                        $existingAttachments = array_filter($existingAttachments, function($att) use ($attachmentId) {
                            return !isset($att['id']) || $att['id'] != $attachmentId;
                        });
                    }
                }
                $existingAttachments = array_values($existingAttachments);
            }
            
            // Process GCS file uploads (new direct-to-GCS flow).
            // SECURITY: Attachment metadata is verified server-side against the
            // actual GCS object (existence, size, MIME type, path ownership,
            // per-type/aggregate limits) via processGcsAttachments() rather than
            // trusting client-declared metadata directly.
            global $currentPracticeId;
            $gcsFilesRaw = $_POST['gcs_files'] ?? '';
            if (!empty($gcsFilesRaw)) {
                $gcsResult = processGcsAttachments(
                    is_string($gcsFilesRaw) ? $gcsFilesRaw : json_encode($gcsFilesRaw),
                    $currentPracticeId
                );

                if (!$gcsResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'File upload verification failed: ' . implode('; ', $gcsResult['errors'])
                    ];
                }

                // Build set of existing storage paths to prevent duplicates
                $existingPaths = [];
                foreach ($existingAttachments as $att) {
                    $path = $att['storagePath'] ?? $att['path'] ?? '';
                    if (!empty($path)) {
                        $existingPaths[$path] = true;
                    }
                }

                foreach ($gcsResult['attachments'] as $attachment) {
                    $storagePath = $attachment['storagePath'];
                    if (!empty($existingPaths[$storagePath])) {
                        continue;
                    }
                    $attachment['path'] = $storagePath;
                    $existingAttachments[] = $attachment;
                    $existingPaths[$storagePath] = true;
                }
            }

            // SECURITY: Legacy local $_FILES attachment path is disabled.
            // All attachments must go through the GCS signed-URL flow above.
            $attachmentTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];

            foreach ($attachmentTypes as $type) {
                if (isset($files[$type]) && !empty($files[$type]['name'][0])) {
                    return [
                        'success' => false,
                        'message' => 'Direct file uploads are no longer supported. Please use the standard attachment upload flow.'
                    ];
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
                'driveFolderId' => $caseData['driveFolderId'] ?? null,
                'changedFields' => $changedFields
            ];
        } catch (Exception $e) {
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
            if (($existingCaseData['patientAppointmentDate'] ?? '') !== ($caseData['patientAppointmentDate'] ?? '')) {
                $changedFields[] = 'patientAppointmentDate';
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
            if (($existingCaseData['carrier'] ?? '') !== ($caseData['carrier'] ?? '')) {
                $changedFields[] = 'carrier';
            }
            if (($existingCaseData['trackingNumber'] ?? '') !== ($caseData['trackingNumber'] ?? '')) {
                $changedFields[] = 'trackingNumber';
            }
            if (($existingCaseData['customCarrier'] ?? '') !== ($caseData['customCarrier'] ?? '')) {
                $changedFields[] = 'customCarrier';
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
            $existingCaseData['patientAppointmentDate'] = $caseData['patientAppointmentDate'] ?? ($existingCaseData['patientAppointmentDate'] ?? '');
            $existingCaseData['status'] = $caseData['status'];
            $existingCaseData['notes'] = $caseData['notes'] ?? '';
            // Assigned To (including clearing it to empty) - see the
            // matching comment above where $caseData['assignedTo'] is built.
            $existingCaseData['assignedTo'] = $caseData['assignedTo'] ?? ($existingCaseData['assignedTo'] ?? null);
            $existingCaseData['carrier'] = $caseData['carrier'] ?? ($existingCaseData['carrier'] ?? '');
            $existingCaseData['trackingNumber'] = $caseData['trackingNumber'] ?? ($existingCaseData['trackingNumber'] ?? '');
            $existingCaseData['customCarrier'] = $caseData['customCarrier'] ?? ($existingCaseData['customCarrier'] ?? '');
            $existingCaseData['clinicalDetails'] = $caseData['clinicalDetails'] ?? [];
            $existingCaseData['lastUpdateDate'] = date('c'); // Update the timestamp
            
            // Encrypt PII before saving
            $encryptedCaseData = PIIEncryption::encryptCaseData($existingCaseData);
            
            // Process files marked for deletion.
            // SECURITY: The authoritative storage path is looked up from the
            // case's own existing attachment record (never trusted from the
            // client), then the GCS object is physically deleted to reclaim
            // storage. Legacy Google Drive fileId-based deletion has been
            // removed since all current attachments are GCS-backed.
            if (!empty($filesToDelete)) {
                foreach ($filesToDelete as $fileToDelete) {
                    $attachmentId = $fileToDelete['attachmentId'] ?? null;
                    if (!$attachmentId) {
                        continue;
                    }

                    if (isset($existingCaseData['attachments']) && is_array($existingCaseData['attachments'])) {
                        foreach ($existingCaseData['attachments'] as $attachment) {
                            if (isset($attachment['id']) && $attachment['id'] == $attachmentId
                                && ($attachment['storageType'] ?? '') === 'gcs' && !empty($attachment['storagePath'])) {
                                if (!deleteGcsObject($attachment['storagePath'])) {
                                    error_log("[update-case] Failed to delete GCS object {$attachment['storagePath']} for attachment {$attachmentId}");
                                }
                                break;
                            }
                        }

                        // Remove attachment from case data
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
            
            // Process GCS file uploads (new direct-to-GCS flow).
            // SECURITY: Attachment metadata is verified server-side against the
            // actual GCS object (existence, size, MIME type, path ownership,
            // per-type/aggregate limits) via processGcsAttachments() rather than
            // trusting client-declared metadata directly.
            global $currentPracticeId;
            $gcsFilesRaw = $_POST['gcs_files'] ?? '';
            if (!empty($gcsFilesRaw)) {
                if (!isset($existingCaseData['attachments']) || !is_array($existingCaseData['attachments'])) {
                    $existingCaseData['attachments'] = [];
                }

                $gcsResult = processGcsAttachments(
                    is_string($gcsFilesRaw) ? $gcsFilesRaw : json_encode($gcsFilesRaw),
                    $currentPracticeId
                );

                if (!$gcsResult['success']) {
                    return [
                        'success' => false,
                        'message' => 'File upload verification failed: ' . implode('; ', $gcsResult['errors'])
                    ];
                }

                // Build set of existing storage paths to prevent duplicates
                $existingPaths = [];
                foreach ($existingCaseData['attachments'] as $att) {
                    $path = $att['storagePath'] ?? $att['path'] ?? '';
                    if (!empty($path)) {
                        $existingPaths[$path] = true;
                    }
                }

                foreach ($gcsResult['attachments'] as $attachment) {
                    $storagePath = $attachment['storagePath'];
                    if (!empty($existingPaths[$storagePath])) {
                        continue;
                    }
                    $attachment['path'] = $storagePath;
                    $existingCaseData['attachments'][] = $attachment;
                    $existingPaths[$storagePath] = true;
                }
            }

            // SECURITY: Legacy local $_FILES attachment path is disabled.
            // All attachments must go through the GCS signed-URL flow above.
            $attachmentTypes = ['photos', 'intraoralScans', 'facialScans', 'photogrammetry', 'completedDesigns'];

            foreach ($attachmentTypes as $type) {
                if (isset($files[$type]) && !empty($files[$type]['name'][0])) {
                    return [
                        'success' => false,
                        'message' => 'Direct file uploads are no longer supported. Please use the standard attachment upload flow.'
                    ];
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

        // SECURITY: Verify this case belongs to the current practice and,
        // for Assigned Only users, is assigned to them, before any edits
        // are processed.
        requireCaseAccess($_POST['caseId'], $currentPracticeId);
        
        // Get field requirements from config (allows easy customization)
        $fieldRequirements = $appConfig['case_required_fields'] ?? [];
        
        // Build required fields list from config
        $requiredFields = [];
        $allFields = ['patientFirstName', 'patientLastName', 'patientDOB', 'patientGender',
                      'dentistName', 'caseType', 'dueDate', 'patientAppointmentDate', 'status', 'toothShade', 'material',
                      'assignedTo', 'notes', 'carrier', 'trackingNumber', 'customCarrier'];
        
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
            } elseif (($field === 'notes' || $field === 'assignedTo' || $field === 'carrier' || $field === 'trackingNumber' || $field === 'customCarrier' || $field === 'patientAppointmentDate') && isset($_POST[$field])) {
                // Notes, Assigned To, carrier and tracking number can be intentionally submitted as an
                // empty string (clearing an assignment). This key MUST still
                // be captured here - updateCaseInDatabaseOnly() below does
                // array_merge($existingCase, $caseData), and if this key is
                // ever absent from $caseData, array_merge() silently keeps
                // the OLD assigned_to value instead of clearing it (this was
                // the root cause of a stale assignment label surviving in
                // the database after "Assigned To" was set to None).
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
        
        // Trim and cap shipping metadata (tracking numbers vary by carrier,
        // but they should not be unbounded strings).
        if (isset($caseData['carrier'])) {
            $caseData['carrier'] = trim($caseData['carrier']);
        }
        if (isset($caseData['trackingNumber'])) {
            $caseData['trackingNumber'] = trim($caseData['trackingNumber']);
            if (strlen($caseData['trackingNumber']) > 100) {
                $caseData['trackingNumber'] = substr($caseData['trackingNumber'], 0, 100);
            }
        }
        if (isset($caseData['customCarrier'])) {
            $caseData['customCarrier'] = trim($caseData['customCarrier']);
            if (strlen($caseData['customCarrier']) > 100) {
                $caseData['customCarrier'] = substr($caseData['customCarrier'], 0, 100);
            }
            // Clear custom carrier whenever a standard carrier is chosen.
            if (($caseData['carrier'] ?? '') !== 'Other') {
                $caseData['customCarrier'] = '';
            }
        }

        // ============================================
        // CUSTOM CARRIER VALIDATION
        // Other carrier requires a custom name when a tracking number is provided.
        // ============================================
        if (($caseData['carrier'] ?? '') === 'Other' && !empty($caseData['trackingNumber']) && empty($caseData['customCarrier'] ?? '')) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please enter an Other Carrier name when providing a tracking number.',
                'field' => 'customCarrier'
            ]);
            exit;
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
        // Business Rule: For Crown case type, validates tooth number(s)
        // using standard dental numbering (1-32 for adult teeth).
        // Supports multiple formats: single (14), comma-separated (14, 30),
        // space-separated (14 30), ranges (14-18), or combinations.
        // Server-side enforcement prevents bypass of client-side validation.
        // ============================================
        $caseType = $_POST['caseType'] ?? '';
        
        if ($caseType === 'Crown' && !empty($clinicalDetails['toothNumber'])) {
            $toothNumberInput = trim($clinicalDetails['toothNumber']);
            $parseResult = parseToothNumbers($toothNumberInput);
            
            if (!$parseResult['valid']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => $parseResult['error'],
                    'field' => 'clinicalToothNumber'
                ]);
                exit;
            }
            
            // Store normalized value (sorted, deduplicated, comma-separated)
            $clinicalDetails['toothNumber'] = $parseResult['normalized'];
            $caseData['clinicalDetails'] = $clinicalDetails;
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

        // Authoritative status validation: when a status is supplied, it
        // must be one of the six internal workflow values defined by
        // getWorkflowStageOrder() (cases-cache.php). Reject anything else
        // outright rather than silently coercing it, so an unrecognized
        // string (e.g. a future custom display label) can never be
        // persisted via Edit Case save.
        if (isset($caseData['status']) && !isValidWorkflowStatus($caseData['status'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status value',
                'field' => 'status'
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
        
        // Lab Insights foundation: capture the assignment text as it exists
        // BEFORE this update, so a genuine assignment change can be detected
        // afterward. Must be read before updateCase() overwrites the cache.
        // Also capture the pre-update status here so a backward stage move
        // made via Edit Case (as opposed to board drag/drop) can be detected
        // using the exact same rule as update-case-status.php.
        $previousAssignedToForLabHistory = null;
        $previousStatusForRevision = null;
        try {
            $prevStmt = $pdo->prepare("SELECT assigned_to, status FROM cases_cache WHERE case_id = :case_id LIMIT 1");
            $prevStmt->execute(['case_id' => $_POST['caseId']]);
            $prevRow = $prevStmt->fetch(PDO::FETCH_ASSOC);
            if ($prevRow) {
                if ($prevRow['assigned_to'] !== null) {
                    $previousAssignedToForLabHistory = $prevRow['assigned_to'];
                }
                $previousStatusForRevision = $prevRow['status'];
            }
        } catch (Exception $e) {
            // Ignore errors fetching previous assignment/status; treated as unknown/empty.
        }

        $result = updateCase($_POST['caseId'], $caseData, $_FILES, $filesToDelete);

        // Backend-enforced revision count: a backward stage transition must
        // increment the revision count regardless of whether the status
        // change came from board drag/drop (update-case-status.php) or from
        // here (Edit Case save). isBackwardStatusMovement()/
        // incrementCaseRevisionCount() are the same shared functions
        // (cases-cache.php) used by the drag/drop endpoint, so both entry
        // points apply the identical "backward" rule and can never disagree
        // or double-count.
        if ($result['success'] && isset($result['caseData']) && is_array($result['caseData'])) {
            $newStatusForRevision = $result['caseData']['status'] ?? null;
            if (isBackwardStatusMovement($previousStatusForRevision, $newStatusForRevision)) {
                $newRevisionCount = incrementCaseRevisionCount($_POST['caseId']);
                $result['caseData']['revisionCount'] = $newRevisionCount;
                $result['isRegression'] = true;
                logCaseActivity(
                    $_POST['caseId'],
                    'case_regression',
                    $previousStatusForRevision,
                    $newStatusForRevision,
                    [
                        'source' => 'update-case.php',
                        'regression_number' => $newRevisionCount,
                        'reason' => 'Stage moved backward from ' . $previousStatusForRevision . ' to ' . $newStatusForRevision
                    ]
                );
            }
        }

        // Lab Insights foundation: record any lab-assignment-period transition.
        // Runs whenever assignedTo was explicitly submitted (including an
        // explicit clear to empty), so a Lab-designated assignment being
        // cleared here correctly closes its open lab period, exactly like
        // update-case-assignment.php already does.
        if ($result['success'] && isset($caseData['assignedTo'])) {
            recordLabAssignmentChange($_POST['caseId'], $currentPracticeId, $previousAssignedToForLabHistory, $caseData['assignedTo']);
        }

        // Lab Insights: a case reaching Delivered through Edit Case closes
        // its own open lab period (if any); a case regressing FROM
        // Delivered back to a non-terminal status through Edit Case opens
        // a brand-new period if it's still assigned to a currently live
        // lab - same rule/no-op semantics as update-case-status.php's
        // closeOpenLabPeriodForDeliveredCase() / reopenLabPeriodOnDeliveredRegression().
        // Runs after the assignment-change handling above so a same-request
        // reassignment + delivery/regression is always resolved against
        // the case's final assignedTo for this request.
        if ($result['success'] && isset($result['caseData']['status'])) {
            $newStatusForLabPeriod = $result['caseData']['status'];
            if ($newStatusForLabPeriod === 'Delivered') {
                closeOpenLabPeriodForDeliveredCase($_POST['caseId'], $currentPracticeId);
            } else {
                reopenLabPeriodOnDeliveredRegression($_POST['caseId'], $currentPracticeId, $previousStatusForRevision, $newStatusForLabPeriod);
            }
        }

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

                $result['assignmentError'] = 'Error updating assignment: ' . $e->getMessage();
            }
        } elseif ($result['success'] && isset($caseData['assignedTo']) && empty($caseData['assignedTo'])) {
            // Assignment explicitly cleared to None: remove any stale
            // relational case_assignments row left over from a previous
            // real-user assignment. Mirrors the same cleanup already done
            // by update-case-assignment.php's quick-assign dropdown, so
            // both write paths agree once this saves.
            try {
                $stmt = $pdo->prepare("DELETE FROM case_assignments WHERE case_id = :case_id");
                $stmt->execute(['case_id' => $_POST['caseId']]);
            } catch (PDOException $e) {
                $result['assignmentError'] = 'Error clearing assignment: ' . $e->getMessage();
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

                    // Log attachment summary on update ONLY if files were actually
                    // added via the GCS upload flow this request (the legacy
                    // $_FILES path is disabled - see updateCase()).
                    // (File deletions are logged separately above)
                    $attachments = $result['caseData']['attachments'] ?? [];
                    $gcsFilesSubmitted = $_POST['gcs_files'] ?? '';
                    $gcsFilesDecoded = is_string($gcsFilesSubmitted) ? json_decode($gcsFilesSubmitted, true) : $gcsFilesSubmitted;
                    $filesWereAdded = is_array($gcsFilesDecoded) && count($gcsFilesDecoded) > 0;
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
