<?php
/**
 * Analytics API Endpoint
 * Returns analytics data for the dashboard
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/practice-security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context - NO FALLBACKS
$practiceId = requireValidPracticeContext();

// Load configuration
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/at-risk-calculator.php';

// Get time period filters
$userId = $_SESSION['db_user_id'];
$teamPeriod = $_GET['team_period'] ?? '12'; // Default: last 12 months
$teamFilter = $_GET['team_filter'] ?? 'both'; // Default: both users and labels
$durationPeriod = $_GET['duration_period'] ?? 'active'; // Default: active cases

// SECURITY: Use ONLY the validated practice ID - NO fallbacks to other practices
$volumePeriod = $_GET['volume_period'] ?? '13'; // Default: last 13 months
$completionTimeframe = $_GET['completion_timeframe'] ?? '12'; // Default: last 12 months for completion predictions
$statusPeriod = $_GET['status_period'] ?? 'active'; // Default: active cases
$typePeriod = $_GET['type_period'] ?? 'active'; // Default: active cases

// Helper function to build time period WHERE clause
function buildTimePeriodClause($period, $dateColumn = 'creation_date') {
    if ($period === 'active') {
        return "AND status != 'Delivered'";
    } elseif ($period === 'all') {
        return "";
    } else {
        $months = (int)$period;
        if ($months < 1) $months = 1;
        if ($months > 60) $months = 60;
        // Handle string dates by extracting first 10 chars (YYYY-MM-DD) and converting
        return "AND STR_TO_DATE(LEFT(COALESCE($dateColumn, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)";
    }
}

try {
    // Core Metrics
    $metrics = [];
    
    // Total Active Cases - cases that are not archived (archived = 0)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cases_cache 
        WHERE practice_id = :practice_id AND archived = 0
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['totalActiveCases'] = (int)$stmt->fetchColumn();
    
    // Initialize empty arrays for charts when no data exists
    $statusDistribution = [];
    $monthlyVolume = [];
    $caseTypeBreakdown = [];
    $teamPerformance = [];
    
    // Total Delivered Cases - cases with status = 'Delivered' (not archived)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cases_cache 
        WHERE practice_id = :practice_id AND status = 'Delivered' AND archived = 0
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['totalDeliveredCases'] = (int)$stmt->fetchColumn();
    
    // Total Archived Cases - cases that are archived (archived = 1)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM cases_cache 
        WHERE practice_id = :practice_id AND archived = 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $metrics['totalArchivedCases'] = (int)$stmt->fetchColumn();
    
    // Cases This Month - based on creation_date (stored as string)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND MONTH(STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')) = MONTH(CURRENT_DATE())
            AND YEAR(STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesThisMonth'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM cases_cache");
        $metrics['casesThisMonth'] = (int)$stmt->fetchColumn();
    }
    
    // Average Case Duration - use creation_date and archived_date when available
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(
                DATEDIFF(
                    STR_TO_DATE(LEFT(COALESCE(archived_date, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )
            ) as avg_duration
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND status = 'Delivered'
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $avgDuration = $stmt->fetchColumn();
        $metrics['averageCaseDuration'] = $avgDuration ? round($avgDuration, 1) : 0;
    } catch (Exception $e) {
        $metrics['averageCaseDuration'] = 0;
    }
    
    // Cases Past Due - based on due_date, excluding Delivered status and archived cases
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != 'Delivered'
            AND due_date IS NOT NULL
            AND due_date != ''
            AND STR_TO_DATE(LEFT(due_date, 10), '%Y-%m-%d') < CURRENT_DATE()
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesPastDue'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesPastDue'] = 0;
    }
    
    // Cases Due This Week - for Insights quick stats
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != 'Delivered'
            AND due_date IS NOT NULL
            AND due_date != ''
            AND STR_TO_DATE(LEFT(due_date, 10), '%Y-%m-%d') BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesDueThisWeek'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesDueThisWeek'] = 0;
    }
    
    // Unassigned Cases - for Insights quick stats
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != 'Delivered'
            AND (assigned_to IS NULL OR assigned_to = '')
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['unassignedCases'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['unassignedCases'] = 0;
    }
    
    // At Risk Cases - computed dynamically
    try {
        // Get active cases for At Risk calculation
        $stmt = $pdo->prepare("
            SELECT * FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != 'Delivered'
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $activeCasesForRisk = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $metrics['atRiskCases'] = countAtRiskCases($activeCasesForRisk, $pdo);
    } catch (Exception $e) {
        $metrics['atRiskCases'] = 0;
    }
    
    // Regression Metrics - cases with backward stage movements
    try {
        // Total cases with at least 1 regression (active, non-archived)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND revision_count > 0
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesWithRegressions'] = (int)$stmt->fetchColumn();
        
        // Cases with multiple regressions (2+)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND revision_count >= 2
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['casesWithMultipleRegressions'] = (int)$stmt->fetchColumn();
        
        // Total regression count across all active cases
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(revision_count), 0) as total
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $metrics['totalRegressions'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $metrics['casesWithRegressions'] = 0;
        $metrics['casesWithMultipleRegressions'] = 0;
        $metrics['totalRegressions'] = 0;
    }
    
    // Case Status Distribution - use time period filtering
    try {
        $statusClause = buildTimePeriodClause($statusPeriod);
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM cases_cache 
            WHERE practice_id = :practice_id
            $statusClause
            GROUP BY status
            ORDER BY 
                CASE 
                    WHEN status = 'Originated' THEN 1
                    WHEN status = 'Sent To External Lab' THEN 2
                    WHEN status = 'Designed' THEN 3
                    WHEN status = 'Manufactured' THEN 4
                    WHEN status = 'Received From External Lab' THEN 5
                    WHEN status = 'Delivered' THEN 6
                    ELSE 7
                END
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $statusDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $statusDistribution = [];
    }
    
    // Monthly Case Volume - use creation_date (string) and requested period
    try {
        $months = (int)$volumePeriod;
        if ($months < 1) {
            $months = 1;
        }

        $sql = "
            SELECT 
                DATE_FORMAT(
                    STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    '%Y-%m'
                ) as month,
                COUNT(*) as cases_created,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as cases_delivered
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)
            GROUP BY month
            ORDER BY month DESC
            LIMIT $months
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['practice_id' => $practiceId]);
        $monthlyVolume = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $monthlyVolume = [];
    }
    
    // Case Type Breakdown - use time period filtering
    try {
        $typeClause = buildTimePeriodClause($typePeriod);
        
        // First try with practice filter
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN case_type IS NULL OR case_type = '' THEN 'Unspecified'
                    ELSE case_type 
                END as case_type,
                COUNT(*) as count
            FROM cases_cache 
            WHERE practice_id = :practice_id
            $typeClause
            GROUP BY case_type
            ORDER BY count DESC
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $caseTypeBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $caseTypeBreakdown = [];
    }
    
    // Team Performance - strict practice filter
    try {
        // Get list of practice user emails for filtering
        $practiceUserEmails = [];
        $userStmt = $pdo->prepare("
            SELECT DISTINCT u.email 
            FROM users u 
            JOIN practice_users pu ON u.id = pu.user_id 
            WHERE pu.practice_id = :practice_id
        ");
        $userStmt->execute(['practice_id' => $practiceId]);
        $practiceUserEmails = $userStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Build the team filter condition
        $teamFilterCondition = "";
        if ($teamFilter === 'users' && !empty($practiceUserEmails)) {
            // Only show practice users (emails that match practice users)
            $placeholders = implode(',', array_fill(0, count($practiceUserEmails), '?'));
            $teamFilterCondition = " AND assigned_to IN ($placeholders)";
        } elseif ($teamFilter === 'labels' && !empty($practiceUserEmails)) {
            // Only show assignment labels (non-email values that don't match practice users)
            $placeholders = implode(',', array_fill(0, count($practiceUserEmails), '?'));
            $teamFilterCondition = " AND (assigned_to IS NULL OR assigned_to = '' OR assigned_to NOT IN ($placeholders))";
        }
        // 'both' = no additional filter
        
        $teamSql = "
            SELECT 
                CASE 
                    WHEN assigned_to IS NULL OR assigned_to = '' THEN 'Unassigned'
                    ELSE assigned_to 
                END as assignee,
                COUNT(*) as cases_count,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as completed_cases
            FROM cases_cache 
            WHERE practice_id = ?
        ";
        
        // Add time period filter if not "all" (based on creation_date)
        if ($teamPeriod !== 'all') {
            $months = (int)$teamPeriod;
            if ($months < 1) {
                $months = 1;
            }
            $teamSql .= " AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL $months MONTH)";
        }
        
        $teamSql .= $teamFilterCondition;
        
        $teamSql .= "
            GROUP BY assigned_to
            ORDER BY cases_count DESC
            LIMIT 10
        ";
        
        // Build parameters array
        $params = [$practiceId];
        if ($teamFilter === 'users' && !empty($practiceUserEmails)) {
            $params = array_merge($params, $practiceUserEmails);
        } elseif ($teamFilter === 'labels' && !empty($practiceUserEmails)) {
            $params = array_merge($params, $practiceUserEmails);
        }
        
        $stmt = $pdo->prepare($teamSql);
        $stmt->execute($params);
        $teamPerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $teamPerformance = [];
    }
    
    // Status Duration Analytics - NEW
    $statusDurationData = [];
    try {
        // Build time period clause for duration data
        $durationClause = buildTimePeriodClause($durationPeriod);
        
        // Calculate average time in each status for active cases
        $stmt = $pdo->prepare("
            SELECT 
                status,
                COUNT(*) as case_count,
                AVG(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as avg_days_in_status,
                MIN(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as min_days_in_status,
                MAX(DATEDIFF(
                    CURRENT_DATE(),
                    STR_TO_DATE(LEFT(COALESCE(status_changed_at, creation_date, CURRENT_DATE()), 10), '%Y-%m-%d')
                )) as max_days_in_status
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND status != 'Delivered'
            AND archived = 0
            AND status_changed_at IS NOT NULL
            $durationClause
            GROUP BY status
            ORDER BY 
                CASE 
                    WHEN status = 'Originated' THEN 1
                    WHEN status = 'Sent To External Lab' THEN 2
                    WHEN status = 'Designed' THEN 3
                    WHEN status = 'Manufactured' THEN 4
                    WHEN status = 'Received From External Lab' THEN 5
                    ELSE 6
                END
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $statusDurationData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for frontend
        foreach ($statusDurationData as &$status) {
            $status['avg_days_in_status'] = round($status['avg_days_in_status'], 1);
            $status['min_days_in_status'] = (int)$status['min_days_in_status'];
            $status['max_days_in_status'] = (int)$status['max_days_in_status'];
        }
    } catch (Exception $e) {
        $statusDurationData = [];
    }
    
    // Case Lifecycle Analytics - NEW
    $lifecycleData = [];
    try {
        // Calculate total case lifecycle times (from creation to delivery)
        $stmt = $pdo->prepare("
            SELECT 
                AVG(DATEDIFF(
                    STR_TO_DATE(LEFT(COALESCE(last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')
                )) as avg_total_days,
                MIN(DATEDIFF(
                    STR_TO_DATE(LEFT(COALESCE(last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')
                )) as min_total_days,
                MAX(DATEDIFF(
                    STR_TO_DATE(LEFT(COALESCE(last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d'),
                    STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')
                )) as max_total_days,
                COUNT(*) as delivered_cases
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND status = 'Delivered'
            AND archived = 0
            AND creation_date IS NOT NULL
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $lifecycleData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lifecycleData) {
            $lifecycleData['avg_total_days'] = round($lifecycleData['avg_total_days'], 1);
            $lifecycleData['min_total_days'] = (int)$lifecycleData['min_total_days'];
            $lifecycleData['max_total_days'] = (int)$lifecycleData['max_total_days'];
        }
    } catch (Exception $e) {
        $lifecycleData = [
            'avg_total_days' => 0,
            'min_total_days' => 0,
            'max_total_days' => 0,
            'delivered_cases' => 0
        ];
    }
    
    // Calculate actual advanced insights from case data
    $advancedInsights = [
        'completion' => [
            'avgDays' => $metrics['averageCaseDuration'] ?? 0,
            'onTrack' => 0,
            'atRisk' => 0,
            'delayed' => $metrics['casesPastDue'] ?? 0
        ],
        'workload' => [
            'utilization' => $metrics['totalActiveCases'] > 0 ? min(100, round(($metrics['totalActiveCases'] / 20) * 100)) : 0,
            'topPerformer' => 'None',
            'busiest' => 'None',
            'capacity' => $metrics['totalActiveCases'] > 17 ? 'Near Capacity' : 'Optimal'
        ],
        'trends' => [
            'monthlyData' => [],
            'growthRate' => 0,
            'peakMonth' => 'N/A',
            'nextPeak' => 'N/A',
            'currentYear' => date('Y')
        ]
    ];
    
    // Calculate year-over-year trends
    try {
        $currentYear = date('Y');
        $lastYear = $currentYear - 1;
        
        // Get current year data - strict practice filter
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d'), '%Y-%m') as month,
                COUNT(*) as count
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND YEAR(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) = :currentYear
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute(['practice_id' => $practiceId, 'currentYear' => $currentYear]);
        $currentYearData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get last year data - strict practice filter
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d'), '%Y-%m') as month,
                COUNT(*) as count
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND YEAR(STR_TO_DATE(LEFT(creation_date, 10), '%Y-%m-%d')) = :lastYear
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute(['practice_id' => $practiceId, 'lastYear' => $lastYear]);
        $lastYearData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate monthly comparison data
        $monthlyData = [];
        $currentYearTotal = 0;
        $lastYearTotal = 0;
        $maxCurrentMonth = '';
        $maxCurrentCount = 0;
        
        // Process all months for comparison
        for ($month = 1; $month <= 12; $month++) {
            $monthStr = sprintf('%04d-%02d', $currentYear, $month);
            $lastMonthStr = sprintf('%04d-%02d', $lastYear, $month);
            
            $currentCount = 0;
            $lastCount = 0;
            
            // Find current year count for this month
            foreach ($currentYearData as $row) {
                if ($row['month'] === $monthStr) {
                    $currentCount = (int)$row['count'];
                    $currentYearTotal += $currentCount;
                    if ($currentCount > $maxCurrentCount) {
                        $maxCurrentCount = $currentCount;
                        $maxCurrentMonth = date('F', mktime(0, 0, 0, $month, 1, $currentYear));
                    }
                    break;
                }
            }
            
            // Find last year count for this month
            foreach ($lastYearData as $row) {
                if ($row['month'] === $lastMonthStr) {
                    $lastCount = (int)$row['count'];
                    $lastYearTotal += $lastCount;
                    break;
                }
            }
            
            if ($currentCount > 0 || $lastCount > 0) {
                $monthlyData[] = [
                    'month' => date('M', mktime(0, 0, 0, $month, 1, $currentYear)),
                    'currentYear' => $currentCount,
                    'lastYear' => $lastCount
                ];
            }
        }
        
        // Calculate growth rate
        $growthRate = 0;
        if ($lastYearTotal > 0) {
            $growthRate = round((($currentYearTotal - $lastYearTotal) / $lastYearTotal) * 100, 1);
        }
        
        // Find next peak month (simplified - use current year pattern)
        $nextPeak = 'N/A';
        if (!empty($currentYearData)) {
            $nextPeak = date('F', mktime(0, 0, 0, (date('n') + 1) % 12 + 1, 1, $currentYear));
        }
        
        $advancedInsights['trends'] = [
            'monthlyData' => $monthlyData,
            'growthRate' => $growthRate,
            'peakMonth' => $maxCurrentMonth ?: 'N/A',
            'nextPeak' => $nextPeak,
            'currentYear' => $currentYear
        ];
        
    } catch (Exception $e) {
        // Keep default empty values on error
    }
    
    // Calculate at-risk cases (due within 3 days) - strict practice filter, exclude archived
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM cases_cache 
            WHERE practice_id = :practice_id
            AND archived = 0
            AND status != 'Delivered'
            AND due_date IS NOT NULL
            AND due_date != ''
            AND DATE(STR_TO_DATE(LEFT(due_date, 10), '%Y-%m-%d')) BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY)
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $advancedInsights['completion']['atRisk'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $advancedInsights['completion']['atRisk'] = 0;
    }
    
    // Calculate on-track cases (not past due and not at risk)
    $advancedInsights['completion']['onTrack'] = max(0, ($metrics['totalActiveCases'] ?? 0) - $advancedInsights['completion']['atRisk'] - $advancedInsights['completion']['delayed']);
    
    // Find top performer from team performance data
    if (!empty($teamPerformance)) {
        $topPerformer = $teamPerformance[0]['assignee'] ?? 'None';
        $busiestMember = $teamPerformance[0]['assignee'] ?? 'None';
        $advancedInsights['workload']['topPerformer'] = $topPerformer;
        $advancedInsights['workload']['busiest'] = $busiestMember;
    }
    
    // Format data for charts
    $chartData = [
        'statusDistribution' => $statusDistribution,
        'monthlyVolume' => array_reverse($monthlyVolume), // Show oldest to newest
        'caseTypeBreakdown' => $caseTypeBreakdown,
        'teamPerformance' => $teamPerformance,
        'statusDuration' => $statusDurationData,
        'lifecycle' => $lifecycleData
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'metrics' => $metrics,
            'charts' => $chartData,
            'advancedInsights' => $advancedInsights
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving analytics data: ' . $e->getMessage()
    ]);
}
?>
