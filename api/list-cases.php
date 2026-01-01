<?php
// List Cases API endpoint

require_once __DIR__ . '/session.php';      // Centralized session handling
header('Content-Type: application/json');

// Do not show errors in the browser for this endpoint
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
// Keep deprecations suppressed but allow other errors to be logged
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/at-risk-calculator.php';

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

try {
    // getAllCasesFromCache now enforces practice_id filtering internally
    $cases = getAllCasesFromCache();
    
    // Filter out archived cases
    $cases = array_filter($cases, function($case) {
        $archived = isset($case['archived']) ? ($case['archived'] == 1 || $case['archived'] === true) : false;
        return !$archived;
    });
    
    // Re-index array to ensure proper JSON structure
    $cases = array_values($cases);
    
    // Check if current user has limited visibility (can only see cases assigned to them)
    $hasLimitedVisibility = false;
    $currentUserEmail = '';
    if (isset($_SESSION['db_user_id']) && isset($_SESSION['current_practice_id'])) {
        try {
            $stmt = $pdo->prepare("
                SELECT u.email, IFNULL(pu.limited_visibility, 0) as limited_visibility
                FROM users u
                JOIN practice_users pu ON u.id = pu.user_id
                WHERE u.id = :user_id AND pu.practice_id = :practice_id
            ");
            $stmt->execute([
                'user_id' => $_SESSION['db_user_id'],
                'practice_id' => $_SESSION['current_practice_id']
            ]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userInfo) {
                $hasLimitedVisibility = (bool)$userInfo['limited_visibility'];
                $currentUserEmail = strtolower($userInfo['email']);
            }
        } catch (Throwable $e) {
            // On error, don't filter (fail open for usability)
        }
    }
    
    // Filter cases for limited visibility users - only show cases assigned to them
    if ($hasLimitedVisibility && $currentUserEmail) {
        $cases = array_filter($cases, function($case) use ($currentUserEmail) {
            // Check if the case is assigned to this user
            $assignedTo = isset($case['assignedTo']) ? strtolower($case['assignedTo']) : '';
            return $assignedTo === $currentUserEmail;
        });
        $cases = array_values($cases);
    }

    // Apply Delivered case hiding based on user preference
    $deliveredHideDays = 0;
    if (isset($_SESSION['user_preferences']['delivered_hide_days'])) {
        $deliveredHideDays = (int)$_SESSION['user_preferences']['delivered_hide_days'];
    } else {
        // Fallback: load from user_preferences table if available
        if (isset($_SESSION['db_user_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT delivered_hide_days FROM user_preferences WHERE user_id = :user_id");
                $stmt->execute(['user_id' => $_SESSION['db_user_id']]);
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null) {
                    $deliveredHideDays = (int)$value;
                    // Cache in session for next call
                    if (!isset($_SESSION['user_preferences'])) {
                        $_SESSION['user_preferences'] = [];
                    }
                    $_SESSION['user_preferences']['delivered_hide_days'] = $deliveredHideDays;
                }
            } catch (Throwable $e) {
                // On error, just default to 0 (show all)
            }
        }
    }

    if ($deliveredHideDays > 0) {
        $cutoffTimestamp = strtotime('-' . $deliveredHideDays . ' days');
        $filtered = [];
        $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;

        foreach ($cases as $case) {
            if (!isset($case['status']) || $case['status'] !== 'Delivered') {
                $filtered[] = $case;
                continue;
            }

            $lastUpdate = isset($case['lastUpdateDate']) ? strtotime($case['lastUpdateDate']) : false;
            if ($lastUpdate === false) {
                // If we can't parse the date, keep the case (defensive)
                $filtered[] = $case;
                continue;
            }

            if ($lastUpdate >= $cutoffTimestamp) {
                $filtered[] = $case;
                continue;
            }

            $caseId = isset($case['id']) ? $case['id'] : null;
            $driveFolderId = isset($case['driveFolderId']) ? $case['driveFolderId'] : null;

            $archived = false;
            if ($driveFolderId) {
                $practiceIdForArchive = $currentPracticeId ? (int)$currentPracticeId : 0;
                $archived = archivePracticeCaseFolder($practiceIdForArchive, $driveFolderId);
            }

            if ($caseId) {
                if ($archived || !$driveFolderId) {
                    deleteCaseFromCache($caseId);
                }

                try {
                    logCaseActivity(
                        $caseId,
                        'case_archived_auto',
                        'Delivered',
                        null,
                        [
                            'source' => 'list-cases.php',
                            'reason' => 'delivered_hide_days',
                            'delivered_hide_days' => $deliveredHideDays,
                            'drive_folder_id' => $driveFolderId,
                            'drive_archived' => $archived,
                        ]
                    );
                } catch (Throwable $e) {
                }
            }
        }

        $cases = $filtered;
    }
    
    // Apply search filter if provided
    $searchTerm = $_GET['search'] ?? '';
    if (!empty($searchTerm)) {
        $cases = PIIEncryption::filterCasesBySearch($cases, $searchTerm);
    }
    
    // Apply late cases filter if requested
    $lateOnly = $_GET['late_only'] ?? '';
    if ($lateOnly === 'true') {
        $cases = array_filter($cases, function($case) {
            // Exclude delivered cases - they can't be late if they're completed
            if (isset($case['status']) && $case['status'] === 'Delivered') {
                return false;
            }
            
            // Check if case is past due
            if (!isset($case['dueDate']) || empty($case['dueDate'])) {
                return false; // No due date means not late
            }
            
            $dueDate = new DateTime($case['dueDate']);
            $today = new DateTime();
            $today->setTime(0, 0, 0); // Set to start of day for comparison
            
            // Case is late if due date is before today
            return $dueDate < $today;
        });
        $cases = array_values($cases); // Re-index array
    }
    
    // Decrypt PII fields for display
    $decryptedCases = array_map(function($case) {
        return PIIEncryption::decryptCaseData($case);
    }, $cases);
    
    // Fetch labels for all cases in one query for efficiency
    $caseIds = array_column($decryptedCases, 'id');
    $caseLabelsMap = [];
    if (!empty($caseIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
            $stmt = $pdo->prepare("
                SELECT cla.case_id, cl.id as label_id, cl.name, cl.color
                FROM case_label_assignments cla
                JOIN case_labels cl ON cla.label_id = cl.id
                WHERE cla.case_id IN ($placeholders)
                ORDER BY cl.name ASC
            ");
            $stmt->execute($caseIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($caseLabelsMap[$row['case_id']])) {
                    $caseLabelsMap[$row['case_id']] = [];
                }
                $caseLabelsMap[$row['case_id']][] = [
                    'id' => (int)$row['label_id'],
                    'name' => $row['name'],
                    'color' => $row['color']
                ];
            }
        } catch (PDOException $e) {
            // Labels table may not exist yet - continue without labels
        }
    }
    
    // Attach labels to each case
    foreach ($decryptedCases as &$case) {
        $case['labels'] = $caseLabelsMap[$case['id']] ?? [];
    }
    unset($case);
    
    // Calculate At Risk status for all cases
    $atRiskStatuses = batchCalculateAtRiskStatus($decryptedCases, $pdo);
    
    // Attach At Risk status to each case
    foreach ($decryptedCases as &$case) {
        $caseId = $case['id'] ?? null;
        if ($caseId && isset($atRiskStatuses[$caseId])) {
            $case['atRisk'] = $atRiskStatuses[$caseId];
        } else {
            $case['atRisk'] = ['isAtRisk' => false, 'reasons' => []];
        }
    }
    unset($case);
    
    // Apply At Risk filter if requested
    $atRiskOnly = $_GET['at_risk_only'] ?? '';
    if ($atRiskOnly === 'true') {
        $decryptedCases = array_filter($decryptedCases, function($case) {
            return isset($case['atRisk']['isAtRisk']) && $case['atRisk']['isAtRisk'] === true;
        });
        $decryptedCases = array_values($decryptedCases);
    }

    echo json_encode([
        'success' => true,
        'cases'   => $decryptedCases
    ]);
} catch (Throwable $e) {
    error_log('Error in list-cases.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error listing cases: ' . $e->getMessage()
    ]);
}
