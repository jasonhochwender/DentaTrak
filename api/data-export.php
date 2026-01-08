<?php
/**
 * Data Export API Endpoint
 * 
 * Handles user data export requests:
 * - Initiates async export job
 * - Generates secure download links
 * - Sends email notification when ready
 * 
 * Security: Export is scoped to user's practice. No sensitive data in logs.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/encryption.php';

header('Content-Type: application/json');
setApiSecurityHeaders();

// ============================================
// SECURITY: Require authenticated user with valid practice
// ============================================
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];
$userEmail = $_SESSION['user_email'] ?? '';

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? 'request';

switch ($action) {
    case 'request':
        handleExportRequest($userId, $currentPracticeId, $userEmail);
        break;
        
    case 'status':
        handleExportStatus($userId);
        break;
        
    case 'download':
        handleExportDownload($userId);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

/**
 * Ensure export tables exist
 */
function ensureExportTables(): void {
    global $pdo;
    
    if (!$pdo) return;
    
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_exports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                practice_id INT UNSIGNED NOT NULL,
                status ENUM('pending', 'processing', 'completed', 'failed', 'expired') NOT NULL DEFAULT 'pending',
                download_token VARCHAR(64) NULL UNIQUE COMMENT 'Secure token for download link',
                file_path VARCHAR(500) NULL COMMENT 'Path to export file',
                file_size BIGINT UNSIGNED NULL,
                expires_at DATETIME NULL COMMENT 'When download link expires',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                error_message TEXT NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_practice_id (practice_id),
                INDEX idx_status (status),
                INDEX idx_download_token (download_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        error_log('[data-export] Error creating export table: ' . $e->getMessage());
    }
}

/**
 * Handle export request - initiate async export job
 */
function handleExportRequest(int $userId, int $practiceId, string $userEmail): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        return;
    }
    
    requireCsrfToken();
    
    global $pdo;
    
    ensureExportTables();
    
    // ============================================
    // RATE LIMITING: Prevent abuse
    // Only allow one pending/processing export per user at a time
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, status, created_at 
        FROM data_exports 
        WHERE user_id = :user_id 
        AND status IN ('pending', 'processing')
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);
    $existingExport = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingExport) {
        echo json_encode([
            'success' => false,
            'message' => 'An export is already in progress. Please wait for it to complete.',
            'exportId' => $existingExport['id'],
            'status' => $existingExport['status']
        ]);
        return;
    }
    
    try {
        // Create export record
        $stmt = $pdo->prepare("
            INSERT INTO data_exports (user_id, practice_id, status, created_at)
            VALUES (:user_id, :practice_id, 'pending', NOW())
        ");
        $stmt->execute([
            'user_id' => $userId,
            'practice_id' => $practiceId
        ]);
        
        $exportId = $pdo->lastInsertId();
        
        // ============================================
        // AUDIT: Log export request (no sensitive data)
        // ============================================
        if (function_exists('logUserActivity')) {
            logUserActivity($userId, 'data_export_requested', "User requested data export (ID: {$exportId})");
        }
        
        // Process export immediately (synchronous for simplicity)
        // In production, this could be queued for async processing
        processExport($exportId, $userId, $practiceId, $userEmail);
        
        echo json_encode([
            'success' => true,
            'message' => 'Export request submitted. You will receive an email when your data is ready.',
            'exportId' => $exportId
        ]);
        
    } catch (PDOException $e) {
        error_log('[data-export] Error creating export: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to initiate export. Please try again.'
        ]);
    }
}

/**
 * Process the export job
 */
function processExport(int $exportId, int $userId, int $practiceId, string $userEmail): void {
    global $pdo, $appConfig;
    
    try {
        // Update status to processing
        $stmt = $pdo->prepare("UPDATE data_exports SET status = 'processing' WHERE id = :id");
        $stmt->execute(['id' => $exportId]);
        
        // ============================================
        // COLLECT USER DATA
        // Scoped to user's practice for data isolation
        // ============================================
        $exportData = [
            'exportInfo' => [
                'exportId' => $exportId,
                'exportedAt' => date('c'),
                'practiceId' => $practiceId,
                'requestedBy' => $userEmail
            ],
            'cases' => [],
            'activityHistory' => [],
            'userSettings' => []
        ];
        
        // Get all cases for this practice
        $stmt = $pdo->prepare("
            SELECT case_id, patient_first_name, patient_last_name, patient_dob, patient_gender,
                   dentist_name, case_type, tooth_shade, material, due_date, creation_date,
                   last_update_date, status, notes, assigned_to, attachments_json,
                   clinical_details_json, archived, archived_date
            FROM cases_cache
            WHERE practice_id = :practice_id
            ORDER BY creation_date DESC
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process cases (decrypt PII fields, include attachment metadata)
        foreach ($cases as $case) {
            // Decrypt PII fields
            $patientFirstName = PIIEncryption::decrypt($case['patient_first_name']);
            $patientLastName = PIIEncryption::decrypt($case['patient_last_name']);
            $patientDOB = PIIEncryption::decrypt($case['patient_dob']);
            $dentistName = PIIEncryption::decrypt($case['dentist_name']);
            $notes = PIIEncryption::decrypt($case['notes']);
            
            $caseData = [
                'caseId' => $case['case_id'],
                'patientFirstName' => $patientFirstName,
                'patientLastName' => $patientLastName,
                'patientDOB' => $patientDOB,
                'patientGender' => $case['patient_gender'],
                'dentistName' => $dentistName,
                'caseType' => $case['case_type'],
                'toothShade' => $case['tooth_shade'],
                'material' => $case['material'],
                'dueDate' => $case['due_date'],
                'creationDate' => $case['creation_date'],
                'lastUpdateDate' => $case['last_update_date'],
                'status' => $case['status'],
                'notes' => $notes,
                'assignedTo' => $case['assigned_to'],
                'clinicalDetails' => json_decode($case['clinical_details_json'] ?? '{}', true),
                'archived' => (bool)$case['archived'],
                'archivedDate' => $case['archived_date'],
                'attachments' => [] // Metadata only, not actual files
            ];
            
            // Parse attachments metadata
            $attachments = json_decode($case['attachments_json'] ?? '[]', true);
            if (is_array($attachments)) {
                foreach ($attachments as $attachment) {
                    $caseData['attachments'][] = [
                        'fileName' => $attachment['fileName'] ?? $attachment['name'] ?? 'Unknown',
                        'type' => $attachment['type'] ?? 'Unknown',
                        'uploadedAt' => $attachment['uploadedAt'] ?? null
                    ];
                }
            }
            
            $exportData['cases'][] = $caseData;
        }
        
        // Get activity history (join through cases_cache to filter by practice)
        try {
            $stmt = $pdo->prepare("
                SELECT ca.case_id, ca.event_type, ca.old_status, ca.new_status, 
                       ca.meta_json, ca.created_at, ca.user_email
                FROM case_activity_log ca
                INNER JOIN cases_cache cc ON ca.case_id = cc.case_id
                WHERE cc.practice_id = :practice_id
                ORDER BY ca.created_at DESC
                LIMIT 1000
            ");
            $stmt->execute(['practice_id' => $practiceId]);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($activities as $activity) {
                $exportData['activityHistory'][] = [
                    'caseId' => $activity['case_id'],
                    'eventType' => $activity['event_type'],
                    'oldStatus' => $activity['old_status'],
                    'newStatus' => $activity['new_status'],
                    'userEmail' => $activity['user_email'],
                    'createdAt' => $activity['created_at'],
                    'metadata' => json_decode($activity['meta_json'] ?? '{}', true)
                ];
            }
        } catch (PDOException $e) {
            // Activity log might not exist
            error_log('[data-export] Activity log query failed: ' . $e->getMessage());
        }
        
        // Get user settings
        try {
            $stmt = $pdo->prepare("
                SELECT theme, allow_card_delete, highlight_past_due, past_due_days, 
                       delivered_hide_days, google_drive_backup
                FROM user_preferences
                WHERE user_id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($settings) {
                $exportData['userSettings'] = $settings;
            }
        } catch (PDOException $e) {
            error_log('[data-export] Settings query failed: ' . $e->getMessage());
        }
        
        // ============================================
        // GENERATE EXPORT FILE
        // ============================================
        $exportDir = __DIR__ . '/../exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }
        
        // Generate secure download token
        $downloadToken = bin2hex(random_bytes(32));
        
        // Create export filename
        $filename = "dentatrak_export_{$exportId}_{$downloadToken}.json";
        $filePath = $exportDir . '/' . $filename;
        
        // Write export data
        $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $jsonContent);
        
        $fileSize = filesize($filePath);
        
        // Set expiration (7 days)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Update export record
        $stmt = $pdo->prepare("
            UPDATE data_exports 
            SET status = 'completed',
                download_token = :token,
                file_path = :file_path,
                file_size = :file_size,
                expires_at = :expires_at,
                completed_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'token' => $downloadToken,
            'file_path' => $filename,
            'file_size' => $fileSize,
            'expires_at' => $expiresAt,
            'id' => $exportId
        ]);
        
        // ============================================
        // SEND EMAIL NOTIFICATION
        // ============================================
        sendExportReadyEmail($userEmail, $downloadToken, $expiresAt, $fileSize);
        
        // ============================================
        // AUDIT: Log export completion
        // ============================================
        if (function_exists('logUserActivity')) {
            logUserActivity($userId, 'data_export_completed', "Data export completed (ID: {$exportId}, Size: {$fileSize} bytes)");
        }
        
    } catch (Exception $e) {
        error_log('[data-export] Export processing failed: ' . $e->getMessage());
        
        // Update status to failed
        $stmt = $pdo->prepare("
            UPDATE data_exports 
            SET status = 'failed', error_message = :error
            WHERE id = :id
        ");
        $stmt->execute([
            'error' => 'Export processing failed. Please try again.',
            'id' => $exportId
        ]);
    }
}

/**
 * Send email notification when export is ready
 */
function sendExportReadyEmail(string $email, string $token, string $expiresAt, int $fileSize): void {
    global $appConfig;
    
    // Build download URL
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
               . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $downloadUrl = $baseUrl . '/api/data-export.php?action=download&token=' . urlencode($token);
    
    $fileSizeFormatted = number_format($fileSize / 1024, 1) . ' KB';
    $expiresFormatted = date('F j, Y \a\t g:i A', strtotime($expiresAt));
    
    $appName = $appConfig['appName'] ?? 'Dentatrak';
    $subject = "Your {$appName} Data Export is Ready";
    
    // HTML email body - matching app email style
    $htmlBody = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #2563eb;'>{$appName}</h2>
            <p>Hello,</p>
            <p>Your data export is ready for download.</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$downloadUrl}' style='background-color: #2563eb; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;'>Download Your Data</a>
            </p>
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break: break-all; color: #666;'>{$downloadUrl}</p>
            <div style='background-color: #f3f4f6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>File Size:</strong> {$fileSizeFormatted}</p>
                <p style='margin: 5px 0;'><strong>Link Expires:</strong> {$expiresFormatted}</p>
            </div>
            <p><strong>This link will expire in 7 days.</strong> Please download your data before then.</p>
            <p>For security, do not share this link with others.</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
            <p style='color: #666; font-size: 12px;'>This email was sent by {$appName}. Please do not reply to this email.</p>
        </div>
    </body>
    </html>
    ";

    // Plain text fallback
    $textBody = "Hello,

Your data export from {$appName} is ready for download.

Download Link: {$downloadUrl}

File Size: {$fileSizeFormatted}
Link Expires: {$expiresFormatted}

This link will expire in 7 days. Please download your data before then.

For security, do not share this link with others.

This email was sent by {$appName}.";
    
    // Send email using SendGrid
    $result = sendAppEmail($email, $subject, $htmlBody, $textBody);
    
    if (!$result['success']) {
        error_log('[data-export] Failed to send export email: ' . ($result['error'] ?? 'Unknown error'));
    }
}

/**
 * Handle export status check
 */
function handleExportStatus(int $userId): void {
    global $pdo;
    
    ensureExportTables();
    
    $stmt = $pdo->prepare("
        SELECT id, status, file_size, expires_at, created_at, completed_at, error_message
        FROM data_exports
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute(['user_id' => $userId]);
    $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'exports' => $exports
    ]);
}

/**
 * Handle export download
 */
function handleExportDownload(int $userId): void {
    global $pdo;
    
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Download token required']);
        return;
    }
    
    ensureExportTables();
    
    // ============================================
    // SECURITY: Verify token and user ownership
    // ============================================
    $stmt = $pdo->prepare("
        SELECT id, user_id, file_path, file_size, expires_at, status
        FROM data_exports
        WHERE download_token = :token
        LIMIT 1
    ");
    $stmt->execute(['token' => $token]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$export) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export not found or link has expired']);
        return;
    }
    
    // Verify user owns this export
    if ((int)$export['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }
    
    // Check if expired
    if ($export['status'] === 'expired' || strtotime($export['expires_at']) < time()) {
        // Mark as expired if not already
        $stmt = $pdo->prepare("UPDATE data_exports SET status = 'expired' WHERE id = :id");
        $stmt->execute(['id' => $export['id']]);
        
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'This download link has expired']);
        return;
    }
    
    // Check file exists
    $filePath = __DIR__ . '/../exports/' . $export['file_path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export file not found']);
        return;
    }
    
    // ============================================
    // SERVE FILE DOWNLOAD
    // ============================================
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="dentatrak_export.json"');
    header('Content-Length: ' . $export['file_size']);
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($filePath);
    exit;
}
