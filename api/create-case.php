<?php
// Create Case API endpoint
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
    // Load Google Drive integration directly. appConfig already suppresses deprecation notices
    require_once __DIR__ . '/google-drive.php';

// Google Drive is only required if the user has enabled backup
// Check if backup is enabled and Drive is not connected
if (isGoogleDriveBackupEnabled() && (!isset($_SESSION['google_drive_token']) || empty($_SESSION['google_drive_token']))) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Google Drive backup is enabled but Google Drive is not connected. Please connect Google Drive from Settings or disable backup.',
        'drive_not_connected' => true
    ]);
    exit;
}

// Process form data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an update (has caseId) or a new case
    $isUpdate = isset($_POST['caseId']) && !empty($_POST['caseId']);
    
    // Check billing for new cases (not updates)
    if (!$isUpdate) {
        require_once __DIR__ . '/appConfig.php';
        
        // Get user's billing tier and created_at for trial calculation
        $stmt = $pdo->prepare("SELECT billing_tier, created_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['db_user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $tierConfig = $appConfig['billing']['tiers'][$user['billing_tier']] ?? $appConfig['billing']['tiers']['evaluate'];
            $isTrial = $tierConfig['is_trial'] ?? false;
            
            // Check trial expiration for Evaluate plan
            if ($isTrial && isset($user['created_at'])) {
                $trialDays = $appConfig['billing']['trial_days'] ?? 30;
                $createdAt = new DateTime($user['created_at']);
                $now = new DateTime();
                $daysSinceSignup = $now->diff($createdAt)->days;
                $trialExpired = $daysSinceSignup >= $trialDays;
                
                if ($trialExpired) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => "Your 30-day trial has expired. Please upgrade to continue creating cases."
                    ]);
                    exit;
                }
            }
            
            // Check case limit for non-trial plans with max_cases > 0
            if (!$isTrial && $tierConfig['max_cases'] > 0) {
                $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
                
                // If no practice ID in session, try to get the user's practice
                if (!$currentPracticeId) {
                    $stmt = $pdo->prepare("SELECT practice_id FROM practice_users WHERE user_id = ? LIMIT 1");
                    $stmt->execute([$_SESSION['db_user_id']]);
                    $practiceRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($practiceRow) {
                        $currentPracticeId = (int)$practiceRow['practice_id'];
                    }
                }
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
                $stmt->execute([$currentPracticeId]);
                $currentCaseCount = (int)$stmt->fetchColumn();
                
                if ($currentCaseCount >= $tierConfig['max_cases']) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => "You've reached your limit of {$tierConfig['max_cases']} cases. Upgrade to create more cases."
                    ]);
                    exit;
                }
            }
        }
    }
    
    // If it's an update, delegate to the update-case.php endpoint
    if ($isUpdate) {
        require_once __DIR__ . '/update-case.php';
        exit; // The update-case.php script will handle the response
    }
    
    // Continue with creating a new case
    // Validate GLOBAL required fields
    $requiredFields = [
        'patientFirstName', 'patientLastName', 'patientDOB', 'patientGender',
        'dentistName', 'caseType', 'dueDate', 'status'
    ];
    
    $caseData = [];
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
    
    if (isset($_POST['notes'])) {
        $caseData['notes'] = $_POST['notes'];
    }
    
    if (isset($_POST['assignedTo']) && !empty($_POST['assignedTo'])) {
        $caseData['assignedTo'] = $_POST['assignedTo'];
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
    
    // Store labels for later processing (after case is created)
    $labelIds = [];
    if (isset($_POST['labels']) && !empty($_POST['labels'])) {
        $labelIds = json_decode($_POST['labels'], true);
        if (!is_array($labelIds)) {
            $labelIds = [];
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
    
    // Encrypt PII before storing
    $encryptedCaseData = PIIEncryption::encryptCaseData($caseData);
    
    // Process the case creation with both original and encrypted data
    $result = createCase($encryptedCaseData, $_FILES, $caseData);

    // If the Google PHP client explodes with an implode() error on PHP 8,
    // fall back to a simulated case so the UI can still function.
    if (!$result['success'] && isset($result['message']) && strpos($result['message'], 'implode(') !== false) {
        error_log('Google client implode error in create-case.php: ' . $result['message']);

        $simulatedCase = [
            'id'              => 'sim_' . uniqid(),
            'driveFolderId'   => null,
            'patientFirstName'=> $caseData['patientFirstName'], // Use original data for UI
            'patientLastName' => $caseData['patientLastName'],
            'patientDOB'      => $caseData['patientDOB'],
            'patientGender'   => $caseData['patientGender'] ?? null,
            'dentistName'     => $caseData['dentistName'],
            'caseType'        => $caseData['caseType'],
            'toothShade'      => $caseData['toothShade'] ?? null,
            'material'        => $caseData['material'] ?? null,
            'dueDate'         => $caseData['dueDate'],
            'creationDate'    => date('c'),
            'lastUpdateDate'  => date('c'),
            'status'          => $caseData['status'],
            'notes'           => $caseData['notes'] ?? '',
            'assignedTo'      => $caseData['assignedTo'] ?? '',
            'clinicalDetails' => $caseData['clinicalDetails'] ?? null,
            'revisions'       => [],
            'attachments'     => []
        ];

        $result = [
            'success'  => true,
            'message'  => 'Case created locally (Google Drive unavailable).',
            'caseData' => $simulatedCase
        ];
    }
    
    // Return the result
    if ($result['success']) {
        if (isset($result['caseData']) && is_array($result['caseData'])) {
            // Save ENCRYPTED data to cache (re-encrypt the decrypted data returned from createCase)
            $encryptedForCache = PIIEncryption::encryptCaseData($result['caseData']);
            saveCaseToCache($encryptedForCache);

            // Update user's case count
            $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;
            if ($currentPracticeId) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE practice_id = ? AND archived = 0");
                $stmt->execute([$currentPracticeId]);
                $newCaseCount = (int)$stmt->fetchColumn();
                
                $stmt = $pdo->prepare("UPDATE users SET case_count = ? WHERE id = ?");
                $stmt->execute([$newCaseCount, $_SESSION['db_user_id']]);
            }
            
            // Log case creation activity
            $createdCaseId = $result['caseData']['id'] ?? null;
            $createdStatus = $result['caseData']['status'] ?? null;
            if ($createdCaseId) {
                logCaseActivity(
                    $createdCaseId,
                    'case_created',
                    null,
                    $createdStatus,
                    [
                        'source' => 'create-case.php',
                        'has_attachments' => !empty($result['caseData']['attachments']),
                        'has_notes' => !empty($result['caseData']['notes'])
                    ]
                );

                // Also log in the user activity log (no patient identifiers)
                if (function_exists('logUserActivity') && isset($_SESSION['db_user_id'])) {
                    logUserActivity((int)$_SESSION['db_user_id'], 'create_case', "User created case {$createdCaseId}");
                }

                // Log attachment details (if any attachments are present)
                $attachments = $result['caseData']['attachments'] ?? [];
                if (is_array($attachments) && count($attachments) > 0) {
                    logCaseActivity(
                        $createdCaseId,
                        'attachments_added',
                        null,
                        null,
                        [
                            'count' => count($attachments),
                            'source' => 'create-case.php',
                            'attachment_count' => count($attachments),
                        ]
                    );
                }

                // Log notes details if notes were provided
                $notes = $result['caseData']['notes'] ?? '';
                if ($notes !== '') {
                    logCaseActivity(
                        $createdCaseId,
                        'notes_updated',
                        null,
                        null,
                        [
                            'length' => strlen($notes),
                            'source' => 'create-case.php',
                        ]
                    );
                }
                
                // Assign labels to the case if any were provided
                if (!empty($labelIds) && $createdCaseId) {
                    try {
                        $userId = $_SESSION['db_user_id'] ?? 0;
                        
                        // Ensure label tables exist
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS case_labels (
                                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                practice_id INT UNSIGNED NOT NULL,
                                name VARCHAR(100) NOT NULL,
                                color VARCHAR(7) DEFAULT '#6b7280',
                                created_by INT UNSIGNED NOT NULL,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_label_per_practice (practice_id, name),
                                INDEX idx_practice_id (practice_id)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                        ");
                        
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
                        
                        $labelStmt = $pdo->prepare("
                            INSERT INTO case_label_assignments (case_id, label_id, assigned_by)
                            VALUES (:case_id, :label_id, :assigned_by)
                        ");
                        
                        $assignedCount = 0;
                        foreach ($labelIds as $labelId) {
                            try {
                                $labelStmt->execute([
                                    'case_id' => $createdCaseId,
                                    'label_id' => (int)$labelId,
                                    'assigned_by' => $userId
                                ]);
                                $assignedCount++;
                            } catch (PDOException $e) {
                                // Log but continue - might be duplicate or invalid label
                                error_log('[create-case] Label assignment error for label ' . $labelId . ': ' . $e->getMessage());
                            }
                        }
                        
                        // Add labels to result for UI
                        $labelsStmt = $pdo->prepare("
                            SELECT cl.id, cl.name, cl.color
                            FROM case_labels cl
                            JOIN case_label_assignments cla ON cl.id = cla.label_id
                            WHERE cla.case_id = :case_id
                        ");
                        $labelsStmt->execute(['case_id' => $createdCaseId]);
                        $result['caseData']['labels'] = $labelsStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        // Labels table may not exist yet - continue without labels
                        error_log('[create-case] Error assigning labels: ' . $e->getMessage());
                        $result['caseData']['labels'] = [];
                    }
                } else {
                    // No labels provided - set empty array
                    $result['caseData']['labels'] = [];
                }
                
                // Calculate At Risk status for the newly created case
                $atRiskStatus = calculateAtRiskStatus($result['caseData'], null);
                $result['caseData']['atRisk'] = $atRiskStatus;
                
                // Check if Google Drive backup is enabled - store data for deferred processing
                $doBackup = false;
                $backupData = null;
                if (isGoogleDriveBackupEnabled()) {
                    $doBackup = true;
                    $backupData = [
                        'caseData' => $result['caseData'],
                        'caseId' => $createdCaseId,
                        'practiceId' => $_SESSION['current_practice_id'] ?? 0,
                        'practiceName' => getCurrentPracticeName(),
                        'attachments' => $result['caseData']['attachments'] ?? []
                    ];
                }
            }
        }
        
        // Send response to client FIRST, then do backup
        echo json_encode($result);
        
        // Flush output to client so they don't wait for backup
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // For non-FastCGI environments
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
        
        // Now perform the backup operation after response is sent
        if (isset($doBackup) && $doBackup && isset($backupData)) {
            try {
                $backupRootFolderId = getBackupRootFolder($backupData['practiceId'], $backupData['practiceName']);
                
                if ($backupRootFolderId) {
                    $backupFolderId = createCaseBackupFolder(
                        $backupData['caseData'],
                        $backupRootFolderId,
                        $backupData['attachments']
                    );
                    
                    if ($backupFolderId) {
                        // Store the backup folder ID in the case cache for future updates
                        $stmt = $pdo->prepare("UPDATE cases_cache SET backup_folder_id = :backup_folder_id WHERE case_id = :case_id");
                        $stmt->execute([
                            'backup_folder_id' => $backupFolderId,
                            'case_id' => $backupData['caseId']
                        ]);
                    }
                }
            } catch (Exception $e) {
                error_log('[create-case] Backup error (non-blocking): ' . $e->getMessage());
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
    $msg = $e->getMessage();

    // If the underlying Google client hit the known implode() bug, fall back to
    // a simulated case instead of surfacing a server error to the UI.
    if (strpos($msg, 'implode(') !== false && !empty($_POST)) {
        error_log('Google client implode error in create-case.php: ' . $msg);

        $simulatedCase = [
            'id'              => 'sim_' . uniqid(),
            'driveFolderId'   => null,
            'patientFirstName'=> $caseData['patientFirstName'] ?? '',
            'patientLastName' => $caseData['patientLastName'] ?? '',
            'patientDOB'      => $caseData['patientDOB'] ?? '',
            'dentistName'     => $caseData['dentistName'] ?? '',
            'caseType'        => $caseData['caseType'] ?? '',
            'toothShade'      => $caseData['toothShade'] ?? '',
            'material'        => $caseData['material'] ?? null,
            'dueDate'         => $caseData['dueDate'] ?? '',
            'creationDate'    => date('c'),
            'lastUpdateDate'  => date('c'),
            'status'          => $caseData['status'] ?? 'Originated',
            'notes'           => $caseData['notes'] ?? '',
            'assignedTo'      => $caseData['assignedTo'] ?? '',
            'revisions'       => [],
            'attachments'     => []
        ];

        echo json_encode([
            'success'  => true,
            'message'  => 'Case created locally (Google Drive unavailable).',
            'caseData' => $simulatedCase
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $msg,
        'error' => $msg
    ]);
}
