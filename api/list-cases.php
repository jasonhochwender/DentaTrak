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
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/google-drive.php';
require_once __DIR__ . '/case-activity-log.php';
require_once __DIR__ . '/lab-assignment-history.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/at-risk-calculator.php';
require_once __DIR__ . '/case-types.php';

// Ensure appointment-risk preference columns exist before querying user_preferences
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'highlight_appointment_risk'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE user_preferences ADD COLUMN highlight_appointment_risk TINYINT(1) DEFAULT 1");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'appointment_risk_days'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE user_preferences ADD COLUMN appointment_risk_days INT(11) DEFAULT 3");
        }
    } catch (Throwable $e) {
        // Continue with defaults if columns cannot be added
    }
}

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
        // If the string contains an ISO-like date prefix, use it as a local
        // calendar date to avoid timezone/offset shifting the displayed day.
        $datePart = $dueDateString;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dueDateString, $m)) {
            $datePart = $m[1];
        }

        $due = new DateTimeImmutable($datePart, new DateTimeZone(date_default_timezone_get()));
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

    $lastStageId = $currentPracticeId ? getLastActiveWorkflowColumnId($currentPracticeId) : 'Delivered';

    if ($deliveredHideDays > 0) {
        $cutoffTimestamp = strtotime('-' . $deliveredHideDays . ' days');
        $filtered = [];
        $currentPracticeId = $_SESSION['current_practice_id'] ?? 0;

        foreach ($cases as $case) {
            if (!isset($case['status']) || $case['status'] !== $lastStageId) {
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
                    // Soft-archive: keep the cases_cache row (flagged
                    // archived) so historical fields - case type, due date,
                    // appointment date, final metadata - survive for
                    // analytics. Active-case queries filter archived=1, so
                    // the case still disappears from the board exactly as
                    // delivered_hide_days intends, and it becomes restorable
                    // through the existing Archived view like a manually
                    // archived case.
                    archiveCaseInCache($caseId);

                    // The case has left active workflow - close any open
                    // lab-assignment period (e.g. one backfilled open after
                    // the terminal-status close already ran) so no lab is
                    // left looking responsible for a hidden case.
                    try {
                        closeOpenLabPeriodForCaseRemoval($caseId, $currentPracticeId, 'case_archived');
                    } catch (Throwable $e) {
                    }
                }

                try {
                    logCaseActivity(
                        $caseId,
                        'case_archived_auto',
                        $lastStageId,
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

    // Restrict to an explicit set of case ids when provided (used by the
    // Open Dental patient launch, which resolves a synced patient's cases
    // server-side and then reuses this endpoint to render them). The set is
    // intersected with the practice-scoped, visibility-filtered list above,
    // so it can only ever narrow what the caller is already allowed to see.
    $caseIdsParam = $_GET['case_ids'] ?? '';
    if ($caseIdsParam !== '') {
        $allowedCaseIds = [];
        foreach (explode(',', (string)$caseIdsParam) as $token) {
            $token = trim($token);
            if ($token !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $token)) {
                $allowedCaseIds[$token] = true;
            }
            if (count($allowedCaseIds) >= 500) {
                break;
            }
        }
        $cases = array_values(array_filter($cases, function($case) use ($allowedCaseIds) {
            return isset($allowedCaseIds[(string)($case['id'] ?? '')]);
        }));
    }

    // Apply dropdown filters using raw stored values (display values are not compared).
    $filterCarrier = $_GET['carrier'] ?? '';
    if ($filterCarrier !== '') {
        $cases = array_filter($cases, function($case) use ($filterCarrier) {
            return ($case['carrier'] ?? '') === $filterCarrier;
        });
        $cases = array_values($cases);
    }

    $filterCaseType = $_GET['case_type'] ?? '';
    if ($filterCaseType !== '') {
        // Match on the normalized slug, not the raw string: legacy aliases
        // ('Mixed' vs 'Mixed Case Type') share a slug and must filter
        // through the single selectable option.
        $filterCaseTypeSlug = normalizeCaseType($filterCaseType);
        $cases = array_filter($cases, function($case) use ($filterCaseTypeSlug) {
            return normalizeCaseType((string)($case['caseType'] ?? '')) === $filterCaseTypeSlug;
        });
        $cases = array_values($cases);
    }

    $filterAssignedTo = $_GET['assigned_to'] ?? '';
    if ($filterAssignedTo !== '') {
        $cases = array_filter($cases, function($case) use ($filterAssignedTo) {
            return ($case['assignedTo'] ?? '') === $filterAssignedTo;
        });
        $cases = array_values($cases);
    }

    // Review Status filtering is only honored while the practice-level Case
    // Review Tracking feature is enabled. When disabled, a stale or manually
    // supplied review_status parameter must not hide cases.
    $filterReviewStatus = $_GET['review_status'] ?? '';
    $caseReviewTrackingEnabled = isCaseReviewTrackingEnabled($currentPracticeId);
    if (!$caseReviewTrackingEnabled) {
        $filterReviewStatus = '';
    }
    if (in_array($filterReviewStatus, ['needs_review', 'reviewed'], true)) {
        $cases = array_filter($cases, function($case) use ($filterReviewStatus) {
            return ($case['reviewStatus'] ?? 'needs_review') === $filterReviewStatus;
        });
        $cases = array_values($cases);
    }

    // Load current user urgency settings from the database each request.
    // Do not rely on stale session preferences; the Kanban uses locally
    // stored (localStorage) values that can get out of sync with $_SESSION.
    $pastDueDays = 1;
    $comingDueDays = 5;
    $appointmentRiskDays = 3;
    $highlightPastDue = false;
    $highlightComingDue = false;
    $highlightAppointmentRisk = false;

    if (isset($_SESSION['db_user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT highlight_past_due, past_due_days, highlight_coming_due, coming_due_days, highlight_appointment_risk, appointment_risk_days FROM user_preferences WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $_SESSION['db_user_id']]);
            $duePrefs = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($duePrefs) {
                $highlightPastDue = (bool)(int)($duePrefs['highlight_past_due'] ?? 0);
                $pastDueDays = (int)($duePrefs['past_due_days'] ?? 1);
                $highlightComingDue = (bool)(int)($duePrefs['highlight_coming_due'] ?? 0);
                $comingDueDays = (int)($duePrefs['coming_due_days'] ?? 5);
                $highlightAppointmentRisk = (bool)(int)($duePrefs['highlight_appointment_risk'] ?? 1);
                $appointmentRiskDays = (int)($duePrefs['appointment_risk_days'] ?? 3);
            }
        } catch (Throwable $e) {
            // On error, use defaults
        }
    }

    // Apply late cases filter if requested
    $lateOnly = $_GET['late_only'] ?? '';
    if ($lateOnly === 'true') {
        $cases = array_filter($cases, function($case) use ($pastDueDays, $lastStageId) {
            // Exclude terminal-stage cases - they can't be late if they're completed
            if (isset($case['status']) && $case['status'] === $lastStageId) {
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
        $cases = array_filter($cases, function($case) use ($comingDueDays, $lastStageId) {
            // Exclude terminal-stage cases
            if (isset($case['status']) && $case['status'] === $lastStageId) {
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
    
    // Apply appointment risk filter if requested.
    // Mirror the Kanban card's effective urgency classification (Late > Appt Risk > Due Soon).
    $apptRiskOnly = $_GET['appt_risk_only'] ?? '';
    if ($apptRiskOnly === 'true') {
        $cases = array_filter($cases, function($case) use ($highlightPastDue, $pastDueDays, $highlightAppointmentRisk, $appointmentRiskDays, $lastStageId) {
            // Terminal-stage cases are never urgent
            if (isset($case['status']) && $case['status'] === $lastStageId) {
                return false;
            }

            // Late (red) outranks Appointment Risk (purple)
            if ($highlightPastDue) {
                $daysUntilDue = getCalendarDayDiff($case['dueDate'] ?? '');
                if ($daysUntilDue !== null && $daysUntilDue <= -$pastDueDays) {
                    return false;
                }
            }

            // Appointment Risk highlight must be enabled for a card to be purple
            if (!$highlightAppointmentRisk) {
                return false;
            }

            $apptDate = $case['patientAppointmentDate'] ?? '';
            if (empty($apptDate)) {
                return false;
            }

            $daysUntilAppt = getCalendarDayDiff($apptDate);
            if ($daysUntilAppt !== null && $daysUntilAppt <= $appointmentRiskDays) {
                return true;
            }

            // Lab acknowledgment: a lab-promised return date AFTER the patient
            // appointment is an additional risk signal. A missing promised date
            // or missing acknowledgment never contributes either way - the
            // existing window check above is authoritative for that.
            $promised = $case['labPromisedDate'] ?? '';
            if (!empty($promised)) {
                return strtotime($promised) > strtotime($apptDate);
            }

            return false;
        });
        $cases = array_values($cases);
    }

    // Optional lab acknowledgment workflow filters. These read the denormalized
    // open-acknowledgment status on cases_cache; when the practice setting is
    // off (or the schema predates the feature) the column is simply NULL for
    // every case, which naturally matches nothing.
    $ackAwaitingOnly = $_GET['ack_awaiting_only'] ?? '';
    if ($ackAwaitingOnly === 'true') {
        $cases = array_values(array_filter($cases, function($case) {
            return ($case['labAckStatus'] ?? null) === 'awaiting';
        }));
    }
    $ackNeedsInfoOnly = $_GET['ack_needs_info_only'] ?? '';
    if ($ackNeedsInfoOnly === 'true') {
        $cases = array_values(array_filter($cases, function($case) {
            return ($case['labAckStatus'] ?? null) === 'needs_info';
        }));
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

    // PMS outbound state per case (Phase 3): external link + latest
    // outbound event status in two batched queries, only when the feature
    // flags and a writable connection make it reachable. Never adds fields
    // when the integration surface is off.
    try {
        if (function_exists('isFeatureEnabled')
            && isFeatureEnabled('SHOW_PMS_INTEGRATIONS')
            && !empty($decryptedCases)) {
            require_once __DIR__ . '/integrations/IntegrationOutbound.php';
            $caseIds = array_values(array_filter(array_map(function ($c) {
                return $c['id'] ?? null;
            }, $decryptedCases)));
            if ($caseIds) {
                $in = implode(',', array_fill(0, count($caseIds), '?'));
                $linkStmt = $pdo->prepare("
                    SELECT m.internal_id, m.external_id
                    FROM integration_entity_mappings m
                    JOIN integration_connections c ON c.id = m.connection_id
                    WHERE m.entity_type = 'case' AND c.practice_id = ?
                      AND m.internal_id IN ({$in})
                ");
                $linkStmt->execute(array_merge([(int)$currentPracticeId], $caseIds));
                $links = [];
                foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $links[$r['internal_id']] = $r['external_id'];
                }
                $evStmt = $pdo->prepare("
                    SELECT internal_entity_id, status
                    FROM integration_outbound_events
                    WHERE practice_id = ? AND entity_type = 'case'
                      AND internal_entity_id IN ({$in})
                    ORDER BY id ASC
                ");
                $evStmt->execute(array_merge([(int)$currentPracticeId], $caseIds));
                $latest = [];
                foreach ($evStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $latest[$r['internal_entity_id']] = $r['status']; // last wins
                }
                foreach ($decryptedCases as &$case) {
                    $cid = $case['id'] ?? null;
                    if (!$cid) continue;
                    $linked = isset($links[$cid]);
                    $st = $latest[$cid] ?? null;
                    if ($linked || $st) {
                        $case['pmsSync'] = [
                            'linked'      => $linked,
                            'external_id' => $links[$cid] ?? null,
                            'state'       => $st,
                        ];
                    }
                }
                unset($case);
            }
        }
    } catch (Throwable $e) {
        // Sync badges are advisory - never break the case list.
        error_log('list-cases pmsSync enrichment skipped: ' . $e->getMessage());
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
        'message' => t('api.cases.list_error', ['message' => $e->getMessage()])
    ]);
}
