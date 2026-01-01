<?php
// Delete File API endpoint
require_once __DIR__ . '/session.php'; // centralized session handling
header('Content-Type: application/json');
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/csrf.php';

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

    // Validate CSRF token for POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
    }

    // Ensure Google Drive is connected
    if (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'drive_not_connected' => true,
            'message' => 'Google Drive is not connected. Please sign out and sign back in to reconnect your Google account.'
        ]);
        exit;
    }

    // Process request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate required fields
        $requiredFields = ['fileId', 'caseId', 'caseFolderId'];
        $missingFields = [];
        
        $data = [];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                $missingFields[] = $field;
            } else {
                $data[$field] = $_POST[$field];
            }
        }
        
        // Return error if required fields are missing
        if (!empty($missingFields)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields',
                'missingFields' => $missingFields
            ]);
            exit;
        }
        
        // Delete the file from Google Drive
        try {
            $client = getGoogleClient();
            
            // Check for valid access token
            if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
                throw new Exception('Not authenticated with Google Drive');
            }
            
            $service = new Google_Service_Drive($client);
            
            // Delete file by ID (trash it, don't permanently delete)
            $service->files->delete($data['fileId']);
            
            // Now we need to update the case.json file to remove this attachment
            $caseId = $data['caseId'];
            $caseFolderId = $data['caseFolderId'];
            $fileId = $data['fileId'];
            $attachmentId = isset($_POST['attachmentId']) ? $_POST['attachmentId'] : null;

            // Find the case.json file in the case folder
            $fileResponse = $service->files->listFiles([
                'q' => "'$caseFolderId' in parents and name='case.json' and trashed=false"
            ]);
            
            if (count($fileResponse->getFiles()) > 0) {
                $caseFileId = $fileResponse->getFiles()[0]->getId();
                
                // Get current case data
                $content = $service->files->get($caseFileId, ['alt' => 'media']);
                $caseData = json_decode($content->getBody()->getContents(), true);
                
                // Remove the attachment from the case data
                if (isset($caseData['attachments']) && is_array($caseData['attachments'])) {
                    $originalCount = count($caseData['attachments']);
                    
                    $updatedAttachments = [];
                    foreach ($caseData['attachments'] as $attachment) {
                        // Skip the attachment we're deleting
                        if ($attachment['driveFileId'] === $fileId || 
                            ($attachmentId && $attachment['id'] === $attachmentId)) {
                            continue;
                        }
                        $updatedAttachments[] = $attachment;
                    }
                    $caseData['attachments'] = $updatedAttachments;
                    $caseData['lastUpdateDate'] = date('c'); // Update modification timestamp
                    
                    // Update the case.json file in Google Drive
                    $updatedFile = new Google_Service_Drive_DriveFile();
                    $service->files->update($caseFileId, $updatedFile, [
                        'data' => json_encode($caseData, JSON_PRETTY_PRINT),
                        'mimeType' => 'application/json',
                        'uploadType' => 'multipart'
                    ]);
                }
            }
            
            // Log attachment deletion as a case activity event
            logCaseActivity(
                $caseId,
                'attachment_deleted',
                null,
                null,
                [
                    'source' => 'delete-file.php',
                    'files_deleted' => 1,
                ]
            );

            // Success
            echo json_encode([
                'success' => true,
                'message' => 'File deleted successfully',
                'fileId' => $data['fileId']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error deleting file: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ]);
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
