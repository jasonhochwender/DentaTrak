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
        'error' => 'Access denied. You do not have permission to view analytics or AI insights.'
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
        echo json_encode(['error' => 'User not found']);
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
    echo json_encode(['error' => 'Smart Recommendations require the Control plan', 'error_code' => 'upgrade_required']);
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
    echo json_encode(['error' => 'No practice found']);
    exit;
}

// Check AI configuration based on provider
$aiProvider = $appConfig['ai_provider'] ?? 'gemini';
$aiConfig = $appConfig[$aiProvider] ?? [];

if (empty($aiConfig['api_key'])) {
    http_response_code(500);
    echo json_encode(['error' => 'AI service not configured']);
    exit;
}

// Check if this is a POST request with a user question
$isAskRequest = false;
$userQuery = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['type']) && $input['type'] === 'ask' && !empty($input['query'])) {
        $isAskRequest = true;
        $userQuery = trim($input['query']);
    }
}

try {
    // SECURITY: Scope analytics (and therefore any AI input) to the cases the
    // current user is authorized to view, using the same rule as Practice
    // Insights and Lab Insights. Non-limited users get every practice case.
    ensureAuthorizedCaseIdsTempTable($practiceId);

    // Gather aggregated analytics data (NO PII)
    $analyticsData = gatherAnalyticsData($pdo, $practiceId);

    if ($isAskRequest) {
        // Handle user question
        $response = answerUserQuestion($appConfig, $analyticsData, $userQuery, $aiProvider, $practiceId);
        echo json_encode([
            'success' => true,
            'response' => $response,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    } else {
        // Generate recommendations using configured AI provider
        $recommendations = getAIRecommendations($appConfig, $analyticsData, $aiProvider);
        echo json_encode([
            'success' => true,
            'recommendations' => $recommendations,
            'generated_at' => date('Y-m-d H:i:s')
        ]);
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log('AI Recommendations Error: ' . $errorMessage);

    // Map internal error codes to user-facing messages
    // config_error codes are permanent failures — the UI should not offer a retry
    $userMessage = match($errorMessage) {
        'AI_QUOTA_EXCEEDED'     => 'AI service is temporarily unavailable due to high demand. Please try again in a few minutes.',
        'AI_MODEL_UNAVAILABLE'  => 'Smart Recommendations are temporarily unavailable because the configured AI model could not be reached.',
        'AI_INVALID_REQUEST'    => 'AI service configuration error. Please contact support.',
        'AI_AUTH_ERROR'         => 'AI service configuration error. Please contact support.',
        'AI_SERVICE_UNAVAILABLE' => 'AI service is currently unavailable. Please try again later.',
        default                 => 'Unable to generate recommendations at this time. Please try again later.'
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
 * Answer a user question about their practice data
 */
function answerUserQuestion($appConfig, $analyticsData, $userQuery, $provider = 'gemini', $practiceId = null) {
    $aiConfig = $appConfig[$provider];

    // Resolve the active locale and human-readable language for the AI
    $locale = getActiveLocale();
    $languageName = getActiveLanguageName();

    // Build context with practice data
    $dataString = json_encode($analyticsData, JSON_PRETTY_PRINT);
    $workflowStagesText = buildWorkflowStagesPromptText($practiceId);

    $systemPrompt = "You are DentaTrak's helpful assistant for a dental lab case management system.
You can answer questions about:
1. Practice data and case statistics
2. How to use DentaTrak features
3. Workflow and best practices

PRACTICE DATA (aggregated, no patient names):
$dataString

DENTATRAK FEATURES AND HOW-TO GUIDE:

**Adding Users/Team Members:**
To add a new user to your practice:
1. Click your profile icon in the top-right corner
2. Select 'Settings' from the dropdown menu
3. Go to the 'Team Members' section
4. Click 'Invite Team Member'
5. Enter their email address and select their role (Admin, Staff, or Limited)
6. They will receive an email invitation to join your practice

**Creating a New Case:**
1. Click the '+ New Case' button at the top of the Cases tab
2. Fill in patient name, dentist, case type, and due date
3. Assign the case to a team member
4. Click 'Create Case'

**Case Statuses (Kanban Board):**
Cases flow through stages: $workflowStagesText
Drag and drop cards between columns to update status.

**Filtering Cases:**
- Use the search bar to find cases by patient or dentist name
- Filter by Assigned To or Case Type
- Check 'Late cases only' to see overdue cases
- Check 'At Risk only' to see cases that may miss their deadline

**Insights/Analytics Tab:**
View practice performance metrics, case volume trends, and AI-powered recommendations.

**Archiving Cases:**
Completed cases can be archived. Click 'View Archived' to see past cases.

**Settings:**
Access Settings from your profile menu to configure:
- Practice information and logo
- Team members and permissions
- Board columns and workflow stages
- Notification preferences

RESPONSE GUIDELINES:
- For data questions, use exact numbers from the practice data
- For how-to questions, provide step-by-step instructions
- Keep responses concise but complete
- Use HTML formatting: <p> for paragraphs, <strong> for emphasis, <ul>/<li> for lists
- Generate all user-facing text in the response language for {$languageName} (locale: {$locale}). Preserve proper names, identifiers, and practice-provided values as provided. Do not translate UI labels, JSON keys, or status identifiers.
- Do NOT use markdown formatting";

    if ($provider === 'gemini') {
        $content = callGeminiAPI($aiConfig, $systemPrompt, $userQuery);
    } else {
        $content = callOpenAIAPI($aiConfig, $systemPrompt, $userQuery);
    }

    // Clean up the response - remove any markdown code blocks
    $content = preg_replace('/```html?\s*/i', '', $content);
    $content = preg_replace('/```\s*/', '', $content);
    $content = trim($content);

    // Ensure response is wrapped in HTML if it isn't already
    if (strpos($content, '<') === false) {
        $content = '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
    }

    return $content;
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

/**
 * Call OpenAI API
 */
function callOpenAIAPI($aiConfig, $systemPrompt, $userPrompt) {
    $requestBody = [
        'model' => $aiConfig['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'max_tokens' => $aiConfig['max_tokens'],
        'temperature' => $aiConfig['temperature']
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $aiConfig['api_key']
        ],
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('API connection error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        handleAPIError($httpCode, $response, 'OpenAI');
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Invalid API response format');
    }

    return $responseData['choices'][0]['message']['content'];
}

/**
 * Call Gemini API
 */
function callGeminiAPI($aiConfig, $systemPrompt, $userPrompt) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $aiConfig['model'] . ':generateContent?key=' . $aiConfig['api_key'];

    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt . "\n\n" . $userPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $aiConfig['max_tokens'],
            'thinkingConfig'  => [
                'thinkingLevel' => 'low',
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('API connection error: ' . $curlError);
    }

    if ($httpCode !== 200) {
        handleAPIError($httpCode, $response, 'Gemini');
    }

    $responseData = json_decode($response, true);

    // Check for MAX_TOKENS truncation before attempting to use the text
    $finishReason = $responseData['candidates'][0]['finishReason'] ?? 'UNKNOWN';
    if ($finishReason === 'MAX_TOKENS') {
        $usageData  = $responseData['usageMetadata'] ?? [];
        $textLength = strlen($responseData['candidates'][0]['content']['parts'][0]['text'] ?? '');
        error_log(sprintf(
            'Gemini MAX_TOKENS: model=%s maxOutputTokens=%d finishReason=%s '
            . 'promptTokens=%d candidateTokens=%d totalTokens=%d visibleTextLength=%d',
            $aiConfig['model'],
            $aiConfig['max_tokens'],
            $finishReason,
            $usageData['promptTokenCount']     ?? 0,
            $usageData['candidatesTokenCount'] ?? 0,
            $usageData['totalTokenCount']      ?? 0,
            $textLength
        ));
        throw new Exception('Failed to parse AI response as JSON');
    }

    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid API response format');
    }

    return $responseData['candidates'][0]['content']['parts'][0]['text'];
}

/**
 * Handle API errors consistently
 * Logs sanitized diagnostic info (status, model, error type, requestId).
 * Never logs API keys, prompts, analytics payloads, or patient data.
 */
function handleAPIError($httpCode, $response, $provider) {
    $errorData = json_decode($response, true);

    // Extract only safe diagnostic fields — no keys, no payload, no PII
    $errorType    = $errorData['error']['status'] ?? ($errorData['error']['code'] ?? 'UNKNOWN');
    $errorMessage = $errorData['error']['message'] ?? 'Unknown API error';
    $requestId    = $errorData['error']['details'][0]['requestId']
                    ?? ($errorData['error']['requestId'] ?? null);

    // Sanitize: truncate message to 200 chars, strip any key-like tokens
    $safeMessage = substr(preg_replace('/[A-Za-z0-9_\-]{30,}/', '[REDACTED]', $errorMessage), 0, 200);
    $requestIdLog = $requestId ? ' requestId=' . substr($requestId, 0, 32) : '';

    error_log(sprintf(
        '%s API error: HTTP %d | errorType=%s | message=%s%s',
        $provider, $httpCode, $errorType, $safeMessage, $requestIdLog
    ));

    if ($httpCode === 404) {
        // Model not found or retired — this is a configuration failure, not transient
        throw new Exception('AI_MODEL_UNAVAILABLE');
    } elseif ($httpCode === 400) {
        throw new Exception('AI_INVALID_REQUEST');
    } elseif ($httpCode === 429) {
        throw new Exception('AI_QUOTA_EXCEEDED');
    } elseif ($httpCode === 401 || $httpCode === 403) {
        throw new Exception('AI_AUTH_ERROR');
    } elseif ($httpCode >= 500) {
        throw new Exception('AI_SERVICE_UNAVAILABLE');
    }

    throw new Exception('AI_UNEXPECTED_ERROR');
}
