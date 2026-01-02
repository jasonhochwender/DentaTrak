<?php
/**
 * AI-Driven Recommendations API
 * Uses OpenAI to generate practice recommendations based on analytics data
 * No PII is sent - only aggregated metrics
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';

header('Content-Type: application/json');

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();

// Check if user has analytics access (Control plan or Evaluate trial)
$userId = $_SESSION['db_user_id'];
$stmt = $pdo->prepare("SELECT billing_tier, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Check access: Control plan always has access, Evaluate plan has access during trial
$hasAccess = false;
$tierConfig = $appConfig['billing']['tiers'][$user['billing_tier']] ?? null;

if ($user['billing_tier'] === 'control') {
    $hasAccess = true;
} elseif ($tierConfig && ($tierConfig['is_trial'] ?? false)) {
    // Check if trial is still active
    if (isset($user['created_at'])) {
        $trialDays = $appConfig['billing']['trial_days'] ?? 30;
        $createdAt = new DateTime($user['created_at']);
        $now = new DateTime();
        $daysSinceSignup = $now->diff($createdAt)->days;
        $hasAccess = $daysSinceSignup < $trialDays;
    }
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
    // Gather aggregated analytics data (NO PII)
    $analyticsData = gatherAnalyticsData($pdo, $practiceId);
    
    if ($isAskRequest) {
        // Handle user question
        $response = answerUserQuestion($appConfig, $analyticsData, $userQuery, $aiProvider);
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
    
    // Map internal error codes to user-friendly messages
    $userMessage = match($errorMessage) {
        'AI_QUOTA_EXCEEDED' => 'AI service is temporarily unavailable due to high demand. Please try again in a few minutes.',
        'AI_AUTH_ERROR' => 'AI service configuration error. Please contact support.',
        'AI_SERVICE_UNAVAILABLE' => 'AI service is currently unavailable. Please try again later.',
        default => 'Unable to generate recommendations at this time. Please try again later.'
    };
    
    http_response_code(200); // Return 200 so frontend can handle gracefully
    echo json_encode([
        'error' => $userMessage,
        'error_code' => $errorMessage === 'AI_QUOTA_EXCEEDED' ? 'quota' : 'general',
        'retry_after' => $errorMessage === 'AI_QUOTA_EXCEEDED' ? 60 : 30
    ]);
}

/**
 * Gather aggregated analytics data without any PII
 */
function gatherAnalyticsData($pdo, $practiceId) {
    $data = [];
    
    // Build practice filter with fallback
    $practiceFilter = "(practice_id = ? OR practice_id = 0 OR practice_id IS NULL)";
    
    // Total cases by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM cases_cache 
        WHERE $practiceFilter AND archived = 0 
        GROUP BY status
    ");
    $stmt->execute([$practiceId]);
    $data['cases_by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fallback: if no data, try without practice filter
    if (empty($data['cases_by_status'])) {
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM cases_cache WHERE archived = 0 GROUP BY status");
        $data['cases_by_status'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    // Total active cases
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE $practiceFilter AND archived = 0");
    $stmt->execute([$practiceId]);
    $data['total_active_cases'] = (int)$stmt->fetchColumn();
    
    // Fallback
    if ($data['total_active_cases'] === 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM cases_cache WHERE archived = 0");
        $data['total_active_cases'] = (int)$stmt->fetchColumn();
    }
    
    // Total archived cases
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE $practiceFilter AND archived = 1");
    $stmt->execute([$practiceId]);
    $data['total_archived_cases'] = (int)$stmt->fetchColumn();
    
    // Cases by type
    $stmt = $pdo->prepare("
        SELECT case_type, COUNT(*) as count 
        FROM cases_cache 
        WHERE $practiceFilter AND archived = 0 
        GROUP BY case_type
    ");
    $stmt->execute([$practiceId]);
    $data['cases_by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fallback
    if (empty($data['cases_by_type'])) {
        $stmt = $pdo->query("SELECT case_type, COUNT(*) as count FROM cases_cache WHERE archived = 0 GROUP BY case_type");
        $data['cases_by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    // Overdue cases (due_date < today and status not 'Completed' or 'Shipped')
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cases_cache 
        WHERE $practiceFilter 
        AND archived = 0 
        AND due_date < ? 
        AND status NOT IN ('Completed', 'Shipped', 'Delivered')
    ");
    $stmt->execute([$practiceId, $today]);
    $data['overdue_cases'] = (int)$stmt->fetchColumn();
    
    // Cases due this week
    $weekEnd = date('Y-m-d', strtotime('+7 days'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cases_cache 
        WHERE $practiceFilter 
        AND archived = 0 
        AND due_date BETWEEN ? AND ?
        AND status NOT IN ('Completed', 'Shipped', 'Delivered')
    ");
    $stmt->execute([$practiceId, $today, $weekEnd]);
    $data['cases_due_this_week'] = (int)$stmt->fetchColumn();
    
    // Cases created in last 30 days
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cases_cache 
        WHERE $practiceFilter 
        AND STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= ?
    ");
    $stmt->execute([$practiceId, $thirtyDaysAgo]);
    $data['cases_created_last_30_days'] = (int)$stmt->fetchColumn();
    
    // Fallback
    if ($data['cases_created_last_30_days'] === 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache WHERE STR_TO_DATE(LEFT(COALESCE(creation_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= ?");
        $stmt->execute([$thirtyDaysAgo]);
        $data['cases_created_last_30_days'] = (int)$stmt->fetchColumn();
    }
    
    // Cases completed in last 30 days
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cases_cache 
        WHERE $practiceFilter 
        AND status IN ('Completed', 'Shipped', 'Delivered')
        AND STR_TO_DATE(LEFT(COALESCE(last_update_date, CURRENT_DATE()), 10), '%Y-%m-%d') >= ?
    ");
    $stmt->execute([$practiceId, $thirtyDaysAgo]);
    $data['cases_completed_last_30_days'] = (int)$stmt->fetchColumn();
    
    // Workload distribution (cases per assignee - no names, just counts)
    $stmt = $pdo->prepare("
        SELECT 
            CASE WHEN assigned_to IS NULL OR assigned_to = '' THEN 'Unassigned' ELSE 'Assigned' END as assignment_status,
            COUNT(*) as count 
        FROM cases_cache 
        WHERE $practiceFilter AND archived = 0 
        GROUP BY assignment_status
    ");
    $stmt->execute([$practiceId]);
    $data['assignment_distribution'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fallback
    if (empty($data['assignment_distribution'])) {
        $stmt = $pdo->query("SELECT CASE WHEN assigned_to IS NULL OR assigned_to = '' THEN 'Unassigned' ELSE 'Assigned' END as assignment_status, COUNT(*) as count FROM cases_cache WHERE archived = 0 GROUP BY assignment_status");
        $data['assignment_distribution'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    // Count of unique assignees
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT assigned_to) 
        FROM cases_cache 
        WHERE $practiceFilter AND archived = 0 AND assigned_to IS NOT NULL AND assigned_to != ''
    ");
    $stmt->execute([$practiceId]);
    $data['unique_assignees'] = (int)$stmt->fetchColumn();
    
    // Fallback
    if ($data['unique_assignees'] === 0) {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT assigned_to) FROM cases_cache WHERE archived = 0 AND assigned_to IS NOT NULL AND assigned_to != ''");
        $data['unique_assignees'] = (int)$stmt->fetchColumn();
    }
    
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
        FROM cases_cache 
        WHERE $practiceFilter AND archived = 0 AND material IS NOT NULL AND material != ''
        GROUP BY material
    ");
    $stmt->execute([$practiceId]);
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
function answerUserQuestion($appConfig, $analyticsData, $userQuery, $provider = 'gemini') {
    $aiConfig = $appConfig[$provider];
    
    // Build context with practice data
    $dataString = json_encode($analyticsData, JSON_PRETTY_PRINT);
    
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
Cases flow through stages: Pending → In Progress → Quality Check → Ready for Pickup → Delivered
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
    
    // Build the full prompt with data
    $dataString = json_encode($analyticsData, JSON_PRETTY_PRINT);
    $systemPrompt = 'You are a dental lab workflow optimization expert. Always respond with valid JSON only, no markdown or extra text.';
    $fullPrompt = $prompt . $dataString;
    
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
            'temperature' => $aiConfig['temperature'],
            'maxOutputTokens' => $aiConfig['max_tokens']
        ]
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('API connection error: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        handleAPIError($httpCode, $response, 'Gemini');
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid API response format');
    }
    
    return $responseData['candidates'][0]['content']['parts'][0]['text'];
}

/**
 * Handle API errors consistently
 */
function handleAPIError($httpCode, $response, $provider) {
    $errorData = json_decode($response, true);
    $errorMessage = $errorData['error']['message'] ?? 'Unknown API error';
    
    if ($httpCode === 429) {
        throw new Exception('AI_QUOTA_EXCEEDED');
    } elseif ($httpCode === 401 || $httpCode === 403) {
        throw new Exception('AI_AUTH_ERROR');
    } elseif ($httpCode === 503 || $httpCode === 500) {
        throw new Exception('AI_SERVICE_UNAVAILABLE');
    }
    
    throw new Exception($provider . ' API error (' . $httpCode . '): ' . $errorMessage);
}
