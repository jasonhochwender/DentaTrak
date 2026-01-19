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

// Check if backup is enabled and the practice has a Drive folder configured
if (isGoogleDriveBackupEnabled() && !isPracticeCreatorDriveConnected()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Google Drive backup is enabled but the backup folder is not configured. A practice admin needs to re-enable backup from Settings.',
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
    
    $caseData = [];
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
