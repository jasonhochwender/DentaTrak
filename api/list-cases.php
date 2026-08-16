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

// Match the local calendar-day semantics used by the Kanban card state
// (see js/app.js getCalendarDayDiff() and the print-case.php time fix).
date_default_timezone_set('America/New_York');

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

function getCalendarDayDiff($dueDateString) {
    if (empty($dueDateString)) {
        return null;
    }
    try {
        $due = new DateTimeImmutable($dueDateString, new DateTimeZone(date_default_timezone_get()));
        $due = $due->setTime(0, 0, 0);
        $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
        $today = $today->setTime(0, 0, 0);
        $diffSeconds = $due->getTimestamp() - $today->getTimestamp();
        return (int) round($diffSeconds / 86400);
    } catch (Throwable $e) {
        return null;
    }
}

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
    
    // Load user due-state thresholds for late and due-soon filters
    $pastDueDays = 1;
    $comingDueDays = 5;
    if (isset($_SESSION['user_preferences']['past_due_days'])) {
        $pastDueDays = (int)$_SESSION['user_preferences']['past_due_days'];
    } elseif (isset($_SESSION['db_user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT past_due_days, coming_due_days FROM user_preferences WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $_SESSION['db_user_id']]);
            $duePrefs = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($duePrefs) {
                $pastDueDays = (int)($duePrefs['past_due_days'] ?? 1);
                $comingDueDays = (int)($duePrefs['coming_due_days'] ?? 5);
                if (!isset($_SESSION['user_preferences'])) {
                    $_SESSION['user_preferences'] = [];
                }
                $_SESSION['user_preferences']['past_due_days'] = $pastDueDays;
                $_SESSION['user_preferences']['coming_due_days'] = $comingDueDays;
            }
        } catch (Throwable $e) {
            // On error, use defaults
        }
    }

    // Apply late cases filter if requested
    $lateOnly = $_GET['late_only'] ?? '';
    if ($lateOnly === 'true') {
        $cases = array_filter($cases, function($case) use ($pastDueDays) {
            // Exclude delivered cases - they can't be late if they're completed
            if (isset($case['status']) && $case['status'] === 'Delivered') {
                return false;
            }

            // Check if case is past due by the user's configured threshold
            $daysUntil = getCalendarDayDiff($case['dueDate'] ?? '');
            if ($daysUntil === null) {
                return false;
            }

            return $daysUntil <= -$pastDueDays;
        });
        $cases = array_values($cases); // Re-index array
    }

    // Apply due soon filter if requested
    $dueSoon = $_GET['due_soon'] ?? '';
    if ($dueSoon === 'true') {
        $cases = array_filter($cases, function($case) use ($comingDueDays) {
            // Exclude delivered cases
            if (isset($case['status']) && $case['status'] === 'Delivered') {
                return false;
            }

            $daysUntil = getCalendarDayDiff($case['dueDate'] ?? '');
            if ($daysUntil === null) {
                return false;
            }

            // Due soon = today or within the configured coming-due window
            return $daysUntil >= 0 && $daysUntil <= $comingDueDays;
        });
        $cases = array_values($cases); // Re-index array
    }
    
    // Cases are already decrypted by getAllCasesFromCache()
    // No need to decrypt again - double decryption corrupts the data
    $decryptedCases = $cases;
    
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
