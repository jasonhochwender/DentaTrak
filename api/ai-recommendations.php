<?php
/**
 * AI-Driven Recommendations API
 * Uses OpenAI to generate practice recommendations based on analytics data
 * No PII is sent - only aggregated metrics
 *
 * CACHING/STORAGE NOTE: Recommendations are generated on-demand and are not
 * cached in the database, session, or client-side. Any future cache must use
 * a cache key that includes the active locale (e.g. practice + analytics period
 * + locale) to avoid cross-language results.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/billing-bypass.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/workflow-stages.php';
require_once __DIR__ . '/ai-client.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

// SECURITY: CSRF protection for this state-triggering POST endpoint,
// consistent with the rest of the application's POST endpoints.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

// SECURITY: Insights (can_view_analytics) gates analytics + AI recommendations.
if (!canViewAnalytics($currentPracticeId)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => t('insights.errors.access_denied')
    ]);
    exit;
}

// Check if the practice has analytics access (Control plan or active practice trial)
$userId = $_SESSION['db_user_id'];

// Master billing gate: when billing is disabled (the production default until
// Stripe is fully configured), all users get AI access with no plan checks.
$billingEnabledRaw = getenv('BILLING_ENABLED');
if ($billingEnabledRaw === false) {
    $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
}
$billingEnabled = filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN);

if (!$billingEnabled) {
    $hasAccess = true;
} else {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(403);
        echo json_encode(['error' => t('billing.errors.user_not_found')]);
        exit;
    }

    // Practice-level subscription access is the sole authority here — never
    // trust Stripe metadata or any browser-supplied plan value. Reuses the
    // same hasControlAccess() rule as every other Control-only capability
    // (Practice Insights, and future Lab Insights).
    $hasAccess = hasControlAccess($pdo, $currentPracticeId, $user['email'] ?? '');
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['error' => t('insights.errors.plan_required'), 'error_code' => 'upgrade_required']);
    exit;
}

// Get practice ID
$practiceId = $_SESSION['current_practice_id'] ?? 0;
if (!$practiceId) {
    $stmt = $pdo->prepare("SELECT practice_id FROM practice_users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $practiceRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($practiceRow) {
        $practiceId = (int)$practiceRow['practice_id'];
    }
}

if (!$practiceId) {
    http_response_code(400);
    echo json_encode(['error' => t('admin_practices.practice_not_found')]);
    exit;
}

// Check AI configuration based on provider
$aiProvider = $appConfig['ai_provider'] ?? 'gemini';
$aiConfig = $appConfig[$aiProvider] ?? [];

if (empty($aiConfig['api_key'])) {
    http_response_code(500);
    echo json_encode(['error' => t('insights.errors.ai_not_configured')]);
    exit;
}

try {
    // SECURITY: Scope analytics (and therefore any AI input) to the cases the
    // current user is authorized to view, using the same rule as Practice
    // Insights and Lab Insights. Non-limited users get every practice case.
    ensureAuthorizedCaseIdsTempTable($practiceId);

    // Gather aggregated analytics data (NO PII)
    $analyticsData = gatherAnalyticsData($pdo, $practiceId);

    // Generate recommendations using configured AI provider
    $recommendations = getAIRecommendations($appConfig, $analyticsData, $aiProvider);
    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations,
        'generated_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log('AI Recommendations Error: ' . $errorMessage);

    // Map internal error codes to user-facing messages
    // config_error codes are permanent failures — the UI should not offer a retry
    $userMessage = match($errorMessage) {
        'AI_QUOTA_EXCEEDED'     => t('insights.errors.ai_quota'),
        'AI_MODEL_UNAVAILABLE'  => t('insights.errors.ai_model_unavailable'),
        'AI_INVALID_REQUEST'    => t('insights.errors.ai_config'),
        'AI_AUTH_ERROR'         => t('insights.errors.ai_config'),
        'AI_SERVICE_UNAVAILABLE' => t('insights.errors.ai_unavailable'),
        default                 => t('insights.errors.unable_later')
    };

    $errorCode = match($errorMessage) {
        'AI_QUOTA_EXCEEDED'                      => 'quota',
        'AI_MODEL_UNAVAILABLE', 'AI_AUTH_ERROR',
        'AI_INVALID_REQUEST'                     => 'config_error',
        default                                  => 'general',
    };

    http_response_code(200); // Return 200 so frontend can handle gracefully
    echo json_encode([
        'error'       => $userMessage,
        'error_code'  => $errorCode,
        'retry_after' => $errorMessage === 'AI_QUOTA_EXCEEDED' ? 60 : 30
    ]);
}

/**
 * Gather aggregated analytics data without any PII
 */
function gatherAnalyticsData($pdo, $practiceId) {
    $data = [];

    // SECURITY: Strict practice filter — no OR practice_id = 0 / IS NULL
    // fallback (that previously allowed orphaned/legacy rows from other
    // practices to leak in), and no "no data? query everything" fallback
    // (that previously leaked other practices' aggregates into a quiet
    // practice's recommendations). A practice with zero matching cases
    // must see zero, not another practice's data.
    //
    // Authorization is performed by the temp table built by
    // ensureAuthorizedCaseIdsTempTable() before this function runs. Every
    // cases_cache query below INNER JOINs that table, so the practice filter
    // stays the same for all users and label-based assignments are respected.
    $practiceFilter = "practice_id = ?";
    $filterParams = [$practiceId];

    // Total cases by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter AND archived = 0
        GROUP BY status
    ");
    $stmt->execute($filterParams);
    $data['cases_by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Total active cases
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci WHERE $practiceFilter AND archived = 0");
    $stmt->execute($filterParams);
    $data['total_active_cases'] = (int)$stmt->fetchColumn();

    // Total archived cases
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci WHERE $practiceFilter AND archived = 1");
    $stmt->execute($filterParams);
    $data['total_archived_cases'] = (int)$stmt->fetchColumn();

    // Cases by type
    $stmt = $pdo->prepare("
        SELECT case_type, COUNT(*) as count
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter AND archived = 0
        GROUP BY case_type
    ");
    $stmt->execute($filterParams);
    $data['cases_by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Overdue cases (due_date < today and status not the practice's terminal column)
    $today = date('Y-m-d');
    $terminalStatus = getLastActiveWorkflowColumnId($currentPracticeId);
    $legacyDone = ['Completed', 'Shipped'];
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter
        AND archived = 0
        AND due_date IS NOT NULL AND due_date != ''
        AND due_date < ?
        AND status NOT IN (?, ?, ?)
    ");
    $stmt->execute(array_merge($filterParams, [$today, $terminalStatus, $legacyDone[0], $legacyDone[1]]));
    $data['overdue_cases'] = (int)$stmt->fetchColumn();

    // Cases due this week
    $weekEnd = date('Y-m-d', strtotime('+7 days'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter
        AND archived = 0
        AND due_date IS NOT NULL AND due_date != ''
        AND due_date BETWEEN ? AND ?
        AND status NOT IN (?, ?, ?)
    ");
    $stmt->execute(array_merge($filterParams, [$today, $weekEnd, $terminalStatus, $legacyDone[0], $legacyDone[1]]));
    $data['cases_due_this_week'] = (int)$stmt->fetchColumn();

    // Cases created in last 30 days
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter
        AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= ?
    ");
    $stmt->execute(array_merge($filterParams, [$thirtyDaysAgo]));
    $data['cases_created_last_30_days'] = (int)$stmt->fetchColumn();

    // Cases completed in last 30 days (terminal + legacy done statuses)
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter
        AND status IN (?, ?, ?)
        AND STR_TO_DATE(LEFT(COALESCE(last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= ?
    ");
    $stmt->execute(array_merge($filterParams, [$terminalStatus, $legacyDone[0], $legacyDone[1], $thirtyDaysAgo]));
    $data['cases_completed_last_30_days'] = (int)$stmt->fetchColumn();

    // Workload distribution (cases per assignee - no names, just counts)
    $stmt = $pdo->prepare("
        SELECT
            CASE WHEN assigned_to IS NULL OR assigned_to = '' THEN 'Unassigned' ELSE 'Assigned' END as assignment_status,
            COUNT(*) as count
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter AND archived = 0
        GROUP BY assignment_status
    ");
    $stmt->execute($filterParams);
    $data['assignment_distribution'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Count of unique assignees
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT assigned_to)
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter AND archived = 0 AND assigned_to IS NOT NULL AND assigned_to != ''
    ");
    $stmt->execute($filterParams);
    $data['unique_assignees'] = (int)$stmt->fetchColumn();

    // Average cases per assignee
    if ($data['unique_assignees'] > 0) {
        $assignedCount = $data['assignment_distribution']['Assigned'] ?? 0;
        $data['avg_cases_per_assignee'] = round($assignedCount / $data['unique_assignees'], 1);
    } else {
        $data['avg_cases_per_assignee'] = 0;
    }

    // Cases by material (for case types that use materials)
    $stmt = $pdo->prepare("
        SELECT material, COUNT(*) as count
        FROM cases_cache INNER JOIN authorized_case_ids a ON a.case_id = cases_cache.case_id COLLATE utf8mb4_unicode_ci
        WHERE $practiceFilter AND archived = 0 AND material IS NOT NULL AND material != ''
        GROUP BY material
    ");
    $stmt->execute($filterParams);
    $data['cases_by_material'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Team size
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM practice_users WHERE practice_id = ?");
    $stmt->execute([$practiceId]);
    $data['team_size'] = (int)$stmt->fetchColumn();

    // Fallback - if no team found, default to 1
    if ($data['team_size'] === 0) {
        $data['team_size'] = 1;
    }

    return $data;
}

/**
 * Call AI API to get recommendations (supports OpenAI and Gemini)
 */
function getAIRecommendations($appConfig, $analyticsData, $provider = 'gemini') {
    $aiConfig = $appConfig[$provider];
    $prompt = $appConfig['ai_prompt'];

    // Resolve the active locale and human-readable language for the AI
    $locale = getActiveLocale();
    $languageName = getActiveLanguageName();

    // Build the full prompt with data
    $dataString = json_encode($analyticsData, JSON_PRETTY_PRINT);
    $systemPrompt = 'You are a dental lab workflow optimization expert. Always respond with valid JSON only, no markdown or extra text.';
    $languageInstruction = "\n\nRESPONSE LANGUAGE (locale: {$locale}; language: {$languageName}):\n" .
        "Generate all user-facing recommendations, headings, explanations, and narrative text in the specified response language.\n" .
        "Preserve proper names, identifiers, practice-provided values, and other user-provided data as provided.\n" .
        "Do NOT translate JSON property names or internal enum values such as 'recommendations', 'title', 'description', 'priority', 'high', 'medium', 'low', 'category', 'efficiency', 'quality', 'scheduling', 'workload', or 'communication'. Only the values of 'title' and 'description' should be generated in the requested language.";
    $fullPrompt = $prompt . $dataString . $languageInstruction;

    if ($provider === 'gemini') {
        $content = callGeminiAPI($aiConfig, $systemPrompt, $fullPrompt);
    } else {
        $content = callOpenAIAPI($aiConfig, $systemPrompt, $fullPrompt);
    }

    // Parse the JSON response
    $recommendations = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to extract JSON from the response if it contains extra text
        if (preg_match('/\[[\s\S]*\]/', $content, $matches)) {
            $recommendations = json_decode($matches[0], true);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse AI response as JSON');
        }
    }

    // Validate and sanitize recommendations
    $validRecommendations = [];

    // Handle case where recommendations might be wrapped in an object
    if (isset($recommendations['recommendations'])) {
        $recommendations = $recommendations['recommendations'];
    }

    if (!is_array($recommendations)) {
        return [];
    }

    foreach ($recommendations as $rec) {
        if (isset($rec['title']) && isset($rec['description'])) {
            // Don't use htmlspecialchars here - it causes double-encoding (&#039;)
            // Sanitization should happen at display time in the frontend
            $validRecommendations[] = [
                'title' => strip_tags(trim($rec['title'])),
                'description' => strip_tags(trim($rec['description'])),
                'priority' => in_array($rec['priority'] ?? '', ['high', 'medium', 'low']) ? $rec['priority'] : 'medium',
                'category' => in_array($rec['category'] ?? '', ['efficiency', 'quality', 'scheduling', 'workload', 'communication']) ? $rec['category'] : 'efficiency'
            ];
        }

        // Only keep top 3
        if (count($validRecommendations) >= 3) {
            break;
        }
    }

    return $validRecommendations;
}
