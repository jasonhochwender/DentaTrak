<?php
// Create Case API endpoint
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
require_once __DIR__ . '/notification-service.php';

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
        'message' => t('api.cases.drive_backup_not_connected'),
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
        // When billing is disabled (the production default until Stripe is fully
        // configured), all billing/trial checks are bypassed.
        $billingEnabledRaw = getenv('BILLING_ENABLED');
        if ($billingEnabledRaw === false) {
            $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
        }
        if (!filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN)) {
            // Skip all billing checks
        } else {
            require_once __DIR__ . '/billing-bypass.php';
            require_once __DIR__ . '/subscription-access.php';

            // Get user's email to check for billing bypass (partner practices, etc.)
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['db_user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $isBypassUser = $user ? isBillingBypassEmail($user['email'] ?? '') : false;

            if (!$isBypassUser) {
                // Practice-level subscription access is the sole authority for
                // whether new cases can be created — see subscription-access.php.
                $access = getPracticeSubscriptionAccess($pdo, $currentPracticeId);

                if (!$access || !$access['full_access']) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => $access['access_message'] ?? t('api.cases.access_denied')
                    ]);
                    exit;
                }
            }
        } // end billing enabled
    } // end if (!$isUpdate)

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
        } elseif (($field === 'notes' || $field === 'carrier' || $field === 'trackingNumber' || $field === 'customCarrier' || $field === 'patientAppointmentDate') && isset($_POST[$field])) {
            // Notes can be empty string; carrier/tracking number are always
            // captured (even when cleared) so the cache stays in sync.
            $caseData[$field] = $_POST[$field];
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
    // CREATOR ATTRIBUTION (immutable, server-side only)
    // The frontend never submits this. Use the authenticated session user.
    // ============================================
    $caseData['createdByUserId'] = isset($_SESSION['db_user_id']) ? (int)$_SESSION['db_user_id'] : null;

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
            'message' => t('api.cases.notes_too_long', ['max' => $notesMaxLength, 'current' => strlen($caseData['notes'])]),
            'field' => 'notes'
        ]);
        exit;
    }

    // ============================================
    // CUSTOM CARRIER VALIDATION
    // Other carrier requires a custom name when a tracking number is provided.
    // ============================================
    if (($caseData['carrier'] ?? '') === 'Other' && !empty($caseData['trackingNumber']) && empty($caseData['customCarrier'] ?? '')) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => t('api.cases.other_carrier_required'),
            'field' => 'customCarrier'
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
                'message' => t('validation.tooth_numbers'),
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

    // Validate canonical Jaw values (applies to Denture and Partial)
    $validJawValues = ['Maxillary', 'Mandibular', 'Both'];
    foreach (['dentureJaw', 'partialJaw'] as $jawField) {
        if (!empty($clinicalDetails[$jawField]) && !in_array($clinicalDetails[$jawField], $validJawValues, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => t('validation.invalid_value'),
                'field' => 'clinical' . ucfirst($jawField)
            ]);
            exit;
        }
    }

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
        $friendlyNames = array_map(function($field) {
            return t('cases.fields.' . $field);
        }, $missingFields);

        echo json_encode([
            'success' => false,
            'message' => t('api.cases.missing_required', ['fields' => implode(', ', $friendlyNames)]),
            'missingFields' => $missingFields
        ]);
        exit;
    }

    // Authoritative status validation: when a status is supplied, it must
    // be one of the six internal workflow values defined by
    // getWorkflowStageOrder() (cases-cache.php). Reject anything else
    // outright rather than silently coercing it, so an unrecognized string
    // (e.g. a future custom display label) can never be persisted.
    if (isset($caseData['status']) && !isValidWorkflowStatus($caseData['status'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => t('api.cases.invalid_status'),
            'field' => 'status'
        ]);
        exit;
    }

    // Process GCS file uploads (if any).
    // SECURITY: Attachment metadata is verified server-side against the
    // actual GCS object (existence, size, MIME type, path ownership,
    // per-type/aggregate limits) via processGcsAttachments() rather than
    // trusting client-declared metadata directly.
    $gcsAttachments = [];
    $gcsFilesRaw = $_POST['gcs_files'] ?? '';
    if (!empty($gcsFilesRaw)) {
        $gcsResult = processGcsAttachments(
            is_string($gcsFilesRaw) ? $gcsFilesRaw : json_encode($gcsFilesRaw),
            $currentPracticeId
        );

        if (!$gcsResult['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => t('api.cases.upload_verification_failed', ['message' => implode('; ', $gcsResult['errors'])])
            ]);
            exit;
        }

        $gcsAttachments = $gcsResult['attachments'];
        foreach ($gcsAttachments as &$att) {
            $att['path'] = $att['storagePath'];
        }
        unset($att);
    }

    // Encrypt PII before storing
    $encryptedCaseData = PIIEncryption::encryptCaseData($caseData);

    // Process the case creation with both original and encrypted data
    // Pass GCS attachments as 4th parameter; $_FILES may be empty with GCS flow
    $result = createCase($encryptedCaseData, $_FILES, $caseData, $gcsAttachments);

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
            'patientAppointmentDate' => $caseData['patientAppointmentDate'] ?? '',
            'creationDate'    => date('c'),
            'lastUpdateDate'  => date('c'),
            'status'          => $caseData['status'],
            'notes'           => $caseData['notes'] ?? '',
            'assignedTo'      => $caseData['assignedTo'] ?? '',
            'carrier'         => $caseData['carrier'] ?? '',
            'trackingNumber'  => $caseData['trackingNumber'] ?? '',
            'customCarrier'   => $caseData['customCarrier'] ?? '',
            'clinicalDetails' => $caseData['clinicalDetails'] ?? null,
            'createdByUserId' => $caseData['createdByUserId'] ?? null,
            'revisions'       => [],
            'attachments'     => []
        ];

        $result = [
            'success'  => true,
            'message'  => t('api.cases.created_local'),
            'caseData' => $simulatedCase
        ];
    }

    // Return the result
    if ($result['success']) {
        if (isset($result['caseData']) && is_array($result['caseData'])) {
            // Save ENCRYPTED data to cache (re-encrypt the decrypted data returned from createCase)
            $encryptedForCache = PIIEncryption::encryptCaseData($result['caseData']);
            saveCaseToCache($encryptedForCache);

            // Lab Insights foundation: record the initial assignment transition.
            // No-op when the initial assignee is not a lab-designated user/label.
            $createdCaseId = $result['caseData']['id'] ?? null;
            if ($createdCaseId && $currentPracticeId) {
                recordLabAssignmentChange($createdCaseId, $currentPracticeId, '', $result['caseData']['assignedTo'] ?? '');
            }

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

        // Record create for real-time notifications to other users
        if ($createdCaseId && function_exists('recordCaseUpdate')) {
            recordCaseUpdate($createdCaseId, 'create');
        }

        // Emit structured in-app notification for the new case (Phase 2).
        // This runs before the final response so Cloud Run cannot throttle it.
        if ($createdCaseId && $currentPracticeId) {
            try {
                $attachmentsForNotify = $result['caseData']['attachments'] ?? [];
                $categories = buildCreateCaseNotificationCategories($result['caseData'], $attachmentsForNotify);
                $metadata = buildCreateCaseNotificationMetadata($result['caseData'], $attachmentsForNotify);
                $eventType = getPrimaryNotificationType($categories);
                emitCaseNotificationEvent($currentPracticeId, $createdCaseId, $_SESSION['db_user_id'] ?? 0, $eventType, $categories, $metadata);
            } catch (Throwable $e) {
                error_log('[create-case] notification emit error (non-fatal): ' . $e->getMessage());
            }
        }

        // Resolve creator display name for the new-case response so the
        // Kanban card and edit modal can show the creator immediately.
        if (isset($result['caseData']['createdByUserId']) && !isset($result['caseData']['createdByName']) && $pdo) {
            try {
                $creatorStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id LIMIT 1");
                $creatorStmt->execute(['id' => (int)$result['caseData']['createdByUserId']]);
                $creator = $creatorStmt->fetch(PDO::FETCH_ASSOC);
                if ($creator) {
                    $name = trim(($creator['first_name'] ?? '') . ' ' . ($creator['last_name'] ?? ''));
                    if ($name !== '') {
                        $result['caseData']['createdByName'] = $name;
                    }
                }
            } catch (Exception $e) {
                // Leave as Unknown on lookup error
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
        'message' => t('api.cases.method_not_allowed')
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
            'message'  => t('api.cases.created_local'),
            'caseData' => $simulatedCase
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => t('api.cases.server_error', ['message' => $msg]),
        'error' => $msg
    ]);
}
