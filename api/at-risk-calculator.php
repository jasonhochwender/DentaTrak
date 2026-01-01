<?php
/**
 * At Risk Calculator
 * 
 * Computes derived "At Risk" status for cases based on operational signals.
 * This is a read-only, computed indicator - not a stored field.
 * 
 * A case is flagged as "At Risk" if ANY of the following are true:
 * 1. Late + Unscheduled: Past due date with no recent activity
 * 2. Approaching due date + Unassigned: Due within 3 days but no one assigned
 * 3. Excessive time-in-stage: Stuck in current stage > threshold days
 * 4. Multiple reassignments: Reassigned more than N times
 * 5. Multiple regressions: Case has moved backward in workflow 2+ times
 */

// Default thresholds (not configurable yet)
define('AT_RISK_TIME_IN_STAGE_DAYS', 7);  // Days in same stage before flagged
define('AT_RISK_REASSIGNMENT_THRESHOLD', 3);  // More than this many reassignments
define('AT_RISK_LATE_INACTIVITY_DAYS', 3);  // Days of inactivity when late
define('AT_RISK_REGRESSION_THRESHOLD', 1);  // 1+ regressions flags as at-risk
define('AT_RISK_APPROACHING_DUE_DAYS', 3);  // Days before due date to check for unassigned

/**
 * Calculate At Risk status for a single case
 * 
 * @param array $case The case data
 * @param array $activityData Optional activity log data for the case
 * @return array ['isAtRisk' => bool, 'reasons' => array of reason strings]
 */
function calculateAtRiskStatus($case, $activityData = null) {
    $reasons = [];
    
    // Skip delivered/completed cases - they're not at risk
    $status = $case['status'] ?? '';
    if ($status === 'Delivered') {
        return ['isAtRisk' => false, 'reasons' => []];
    }
    
    // 1. Check: Late + Unscheduled (past due with no recent activity)
    $lateReason = checkLateAndUnscheduled($case, $activityData);
    if ($lateReason) {
        $reasons[] = $lateReason;
    }
    
    // 2. Check: Approaching due date with no assignment
    $unassignedReason = checkApproachingDueUnassigned($case);
    if ($unassignedReason) {
        $reasons[] = $unassignedReason;
    }
    
    // 3. Check: Excessive time in current stage
    $stuckReason = checkExcessiveTimeInStage($case);
    if ($stuckReason) {
        $reasons[] = $stuckReason;
    }
    
    // 4. Check: Multiple reassignments
    $reassignReason = checkMultipleReassignments($case, $activityData);
    if ($reassignReason) {
        $reasons[] = $reassignReason;
    }
    
    // 5. Check: Multiple regressions (backward stage movements)
    $regressionReason = checkMultipleRegressions($case);
    if ($regressionReason) {
        $reasons[] = $regressionReason;
    }
    
    return [
        'isAtRisk' => !empty($reasons),
        'reasons' => $reasons
    ];
}

/**
 * Check if case is approaching due date but has no one assigned
 */
function checkApproachingDueUnassigned($case) {
    $dueDate = $case['dueDate'] ?? null;
    if (empty($dueDate)) {
        return null; // No due date set
    }
    
    // Check if case is unassigned
    $assignedTo = $case['assignedTo'] ?? '';
    if (!empty($assignedTo)) {
        return null; // Someone is assigned
    }
    
    try {
        $due = new DateTime($dueDate);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        // Check if due date is in the past (already late) - handled by checkLateAndUnscheduled
        if ($due < $today) {
            return null;
        }
        
        // Check if due date is within threshold days
        $daysUntilDue = $today->diff($due)->days;
        
        if ($daysUntilDue <= AT_RISK_APPROACHING_DUE_DAYS) {
            if ($daysUntilDue === 0) {
                return "Due today with no one assigned";
            } elseif ($daysUntilDue === 1) {
                return "Due tomorrow with no one assigned";
            } else {
                return "Due in {$daysUntilDue} days with no one assigned";
            }
        }
    } catch (Exception $e) {
        // Date parsing error, skip this check
    }
    
    return null;
}

/**
 * Check if case is late and has no recent activity (unscheduled)
 */
function checkLateAndUnscheduled($case, $activityData = null) {
    // Check if past due
    $dueDate = $case['dueDate'] ?? null;
    if (empty($dueDate)) {
        return null; // No due date, can't be late
    }
    
    try {
        $due = new DateTime($dueDate);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if ($due >= $today) {
            return null; // Not past due
        }
        
        // Case is late - check for recent activity
        $lastActivity = null;
        
        // Use statusChangedAt or lastUpdateDate as proxy for activity
        if (!empty($case['statusChangedAt'])) {
            $lastActivity = new DateTime($case['statusChangedAt']);
        } elseif (!empty($case['lastUpdateDate'])) {
            $lastActivity = new DateTime($case['lastUpdateDate']);
        }
        
        // If we have activity data, use the most recent event
        if ($activityData && !empty($activityData)) {
            $mostRecent = $activityData[0]['created_at'] ?? null;
            if ($mostRecent) {
                $activityDate = new DateTime($mostRecent);
                if (!$lastActivity || $activityDate > $lastActivity) {
                    $lastActivity = $activityDate;
                }
            }
        }
        
        // Calculate days late
        $daysLate = $today->diff($due)->days;
        
        // Check if there's been recent activity
        $hasRecentActivity = false;
        if ($lastActivity) {
            $daysSinceActivity = $today->diff($lastActivity)->days;
            $hasRecentActivity = $daysSinceActivity < AT_RISK_LATE_INACTIVITY_DAYS;
        }
        
        if (!$hasRecentActivity) {
            return "Late by {$daysLate} day" . ($daysLate !== 1 ? 's' : '') . " with no recent activity";
        }
        
    } catch (Exception $e) {
        // Date parsing error, skip this check
    }
    
    return null;
}

/**
 * Check if case has been in current stage too long
 */
function checkExcessiveTimeInStage($case) {
    $statusChangedAt = $case['statusChangedAt'] ?? null;
    
    // If no status change date, use creation date
    if (empty($statusChangedAt)) {
        $statusChangedAt = $case['creationDate'] ?? null;
    }
    
    if (empty($statusChangedAt)) {
        return null; // Can't determine time in stage
    }
    
    try {
        $stageStart = new DateTime($statusChangedAt);
        $today = new DateTime();
        $daysInStage = $today->diff($stageStart)->days;
        
        if ($daysInStage > AT_RISK_TIME_IN_STAGE_DAYS) {
            $status = $case['status'] ?? 'current stage';
            return "In '{$status}' for {$daysInStage} days";
        }
    } catch (Exception $e) {
        // Date parsing error, skip this check
    }
    
    return null;
}

/**
 * Check if case has multiple revisions
 */
function checkMultipleRegressions($case) {
    $revisionCount = $case['revisionCount'] ?? $case['revision_count'] ?? 0;
    
    if ($revisionCount >= AT_RISK_REGRESSION_THRESHOLD) {
        return "{$revisionCount} revision" . ($revisionCount !== 1 ? 's' : '');
    }
    
    return null;
}

/**
 * Check if case has been reassigned multiple times
 */
function checkMultipleReassignments($case, $activityData = null) {
    if (!$activityData || empty($activityData)) {
        return null; // No activity data to check
    }
    
    // Count assignment_changed events
    $reassignmentCount = 0;
    foreach ($activityData as $event) {
        if (($event['event_type'] ?? '') === 'assignment_changed') {
            $reassignmentCount++;
        }
    }
    
    if ($reassignmentCount > AT_RISK_REASSIGNMENT_THRESHOLD) {
        return "Reassigned {$reassignmentCount} times";
    }
    
    return null;
}

/**
 * Batch calculate At Risk status for multiple cases
 * More efficient than calling calculateAtRiskStatus individually
 * 
 * @param array $cases Array of case data
 * @param PDO $pdo Database connection for activity lookup
 * @return array Map of case_id => ['isAtRisk' => bool, 'reasons' => array]
 */
function batchCalculateAtRiskStatus($cases, $pdo = null) {
    $results = [];
    $caseIds = array_column($cases, 'id');
    
    // Batch fetch activity data if PDO is available
    $activityMap = [];
    if ($pdo && !empty($caseIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
            $stmt = $pdo->prepare("
                SELECT case_id, event_type, created_at
                FROM case_activity_log
                WHERE case_id IN ($placeholders)
                ORDER BY case_id, created_at DESC
            ");
            $stmt->execute($caseIds);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $caseId = $row['case_id'];
                if (!isset($activityMap[$caseId])) {
                    $activityMap[$caseId] = [];
                }
                $activityMap[$caseId][] = $row;
            }
        } catch (PDOException $e) {
            // Activity table may not exist - continue without activity data
        }
    }
    
    // Calculate At Risk for each case
    foreach ($cases as $case) {
        $caseId = $case['id'] ?? null;
        if (!$caseId) continue;
        
        $activity = $activityMap[$caseId] ?? [];
        $results[$caseId] = calculateAtRiskStatus($case, $activity);
    }
    
    return $results;
}

/**
 * Get count of At Risk cases for analytics
 * 
 * @param array $cases Array of case data
 * @param PDO $pdo Database connection
 * @return int Count of at-risk cases
 */
function countAtRiskCases($cases, $pdo = null) {
    $atRiskStatuses = batchCalculateAtRiskStatus($cases, $pdo);
    $count = 0;
    foreach ($atRiskStatuses as $status) {
        if ($status['isAtRisk']) {
            $count++;
        }
    }
    return $count;
}
