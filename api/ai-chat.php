<?php
/**
 * AI Chat Support API
 * Uses OpenAI to provide instant support answers
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get the message from POST
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = trim($input['message'] ?? '');

if (empty($userMessage)) {
    echo json_encode(['error' => 'Please enter a message']);
    exit;
}

// Check AI configuration based on provider
$aiProvider = $appConfig['ai_provider'] ?? 'gemini';
$aiConfig = $appConfig[$aiProvider] ?? [];

if (empty($aiConfig['api_key'])) {
    echo json_encode(['error' => 'AI chat is not configured. Please contact support.']);
    exit;
}

// System prompt for the support chatbot
$systemPrompt = 'You are a helpful AI support assistant for a dental lab case management application. Your role is to help users with:

1. **Using Features**: Explain how to create cases, manage workflows, use the Kanban board, search/filter cases, upload files, etc.
2. **Troubleshooting**: Help resolve common issues like login problems, file upload errors, sync issues, etc.
3. **Best Practices**: Provide tips for efficient case management, team collaboration, and workflow optimization.
4. **Account & Billing**: Answer questions about plans (Evaluate, Operate, Control), billing, upgrades, and account settings.

Key Application Features to Know:
- Kanban-style case board with drag-and-drop status updates
- Case types: Crown, Bridge, Implant, Veneer, Denture, AOX, Inlay/Onlay, Partial, Orthodontic Appliance
- Case statuses: New, In Progress, On Hold, Completed, Shipped
- Google Drive integration for file storage
- Team management with roles (Admin, Dentist, Staff, Lab Tech)
- Analytics dashboard (Control plan only)
- Keyboard shortcuts (Cmd on Mac, Ctrl on Windows):
  * Ctrl/Cmd+K: Open Create New Case modal
  * Ctrl/Cmd+,: Open Settings modal
  * Ctrl/Cmd+Shift+F: Open Feedback modal
  * Ctrl/Cmd+Shift+A: Open Archived Cases
  * Escape: Close any open modal or dialog
  * Enter: Submit forms, confirm dialogs, add comments
  * Shift+Enter: New line in text areas (comments, chat)
  * Arrow keys: Navigate dropdown menus and autocomplete lists
  * Tab: Navigate between form fields

- At Risk Indicators: Cases are automatically flagged as "At Risk" if ANY of these conditions are true:
  * Late + No Activity: Past due date with no recent activity (3+ days of inactivity)
  * Approaching Due + Unassigned: Due within 3 days but no one is assigned to the case
  * Stuck in Stage: Case has been in the same status/stage for more than 7 days
  * Multiple Reassignments: Case has been reassigned more than 3 times (4+ reassignments)
  * Revisions: Case has 1 or more revisions (backward workflow movements)

Guidelines:
- Be concise and helpful
- Use bullet points for step-by-step instructions
- If you don\'t know something specific about the app, say so and suggest contacting human support
- Never make up features that don\'t exist
- Be friendly and professional
- For billing issues requiring account changes, direct users to Settings → Billing or human support';

try {
    $response = callAI($appConfig, $aiProvider, $systemPrompt, $userMessage);
    echo json_encode([
        'success' => true,
        'message' => $response
    ]);
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log('AI Chat Error: ' . $errorMessage);
    
    // User-friendly error messages
    $userMessage = match($errorMessage) {
        'AI_QUOTA_EXCEEDED' => 'I\'m experiencing high demand right now. Please try again in a moment, or check our Help Center for immediate assistance.',
        'AI_AUTH_ERROR' => 'I\'m having trouble connecting. Please try again or contact human support.',
        'AI_SERVICE_UNAVAILABLE' => 'I\'m temporarily unavailable. Please try again shortly.',
        default => 'I couldn\'t process your request. Please try again or contact human support.'
    };
    
    echo json_encode([
        'success' => false,
        'message' => $userMessage,
        'is_error' => true
    ]);
}

/**
 * Call AI API for chat (supports OpenAI and Gemini)
 */
function callAI($appConfig, $provider, $systemPrompt, $userMessage) {
    $aiConfig = $appConfig[$provider];
    
    if ($provider === 'gemini') {
        return callGeminiChat($aiConfig, $systemPrompt, $userMessage);
    } else {
        return callOpenAIChat($aiConfig, $systemPrompt, $userMessage);
    }
}

/**
 * Call OpenAI API for chat
 */
function callOpenAIChat($aiConfig, $systemPrompt, $userMessage) {
    $requestBody = [
        'model' => $aiConfig['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ],
        'max_tokens' => 500,
        'temperature' => 0.7
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
        throw new Exception('Connection error');
    }
    
    if ($httpCode !== 200) {
        handleChatAPIError($httpCode);
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response');
    }
    
    return $responseData['choices'][0]['message']['content'];
}

/**
 * Call Gemini API for chat
 */
function callGeminiChat($aiConfig, $systemPrompt, $userMessage) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $aiConfig['model'] . ':generateContent?key=' . $aiConfig['api_key'];
    
    $requestBody = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $systemPrompt . "\n\nUser: " . $userMessage]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 500
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
        throw new Exception('Connection error');
    }
    
    if ($httpCode !== 200) {
        handleChatAPIError($httpCode);
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        throw new Exception('Invalid response');
    }
    
    return $responseData['candidates'][0]['content']['parts'][0]['text'];
}

/**
 * Handle API errors for chat
 */
function handleChatAPIError($httpCode) {
    if ($httpCode === 429) {
        throw new Exception('AI_QUOTA_EXCEEDED');
    } elseif ($httpCode === 401 || $httpCode === 403) {
        throw new Exception('AI_AUTH_ERROR');
    } elseif ($httpCode === 503 || $httpCode === 500) {
        throw new Exception('AI_SERVICE_UNAVAILABLE');
    }
    throw new Exception('API error: ' . $httpCode);
}
