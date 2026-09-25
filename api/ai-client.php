<?php
/**
 * Shared AI provider client.
 *
 * Low-level Gemini/OpenAI request helpers plus the unified error mapper used
 * by Practice Insights recommendations and the Ask DentaTrak assistant.
 * These functions transport prompts and return raw model text - they contain
 * no authorization, data access, or product policy. Callers are responsible
 * for gating, scoping data, and auditing.
 */

if (!function_exists('callOpenAIAPI')) {

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

}
