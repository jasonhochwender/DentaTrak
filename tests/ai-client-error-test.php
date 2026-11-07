<?php
/**
 * AI client error-mapping tests.
 *
 * handleAPIError() translates provider HTTP statuses into the internal
 * AI_* exception codes that ai-recommendations.php and ask-dentatrak-tools.php
 * map to localized user-facing messages. HTTP 402 (prepaid credits depleted)
 * previously fell through to AI_UNEXPECTED_ERROR, which surfaced the generic
 * "try again later" message even though retrying cannot succeed until billing
 * is restored.
 *
 * No network access - handleAPIError only inspects the status code and a
 * canned JSON error body.
 *
 * Run: php tests/ai-client-error-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/ai-client.php';

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}

/** Call handleAPIError and return the thrown exception message (or null). */
function errorCodeFor($httpCode, $body = '{}', $provider = 'Gemini') {
    try {
        handleAPIError($httpCode, $body, $provider);
        return null;
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

$billingBody = json_encode([
    'error' => [
        'status' => 'RESOURCE_EXHAUSTED',
        'message' => 'Your prepayment credits are depleted.',
    ],
]);

// 402 must map to AI_BILLING_ERROR (regression: was AI_UNEXPECTED_ERROR)
assertTrue(
    errorCodeFor(402, $billingBody) === 'AI_BILLING_ERROR',
    'HTTP 402 maps to AI_BILLING_ERROR'
);

// Existing mappings must be preserved
$cases = [
    [404, 'AI_MODEL_UNAVAILABLE'],
    [400, 'AI_INVALID_REQUEST'],
    [429, 'AI_QUOTA_EXCEEDED'],
    [401, 'AI_AUTH_ERROR'],
    [403, 'AI_AUTH_ERROR'],
    [500, 'AI_SERVICE_UNAVAILABLE'],
    [503, 'AI_SERVICE_UNAVAILABLE'],
];
foreach ($cases as [$code, $expected]) {
    assertTrue(
        errorCodeFor($code) === $expected,
        "HTTP {$code} maps to {$expected}"
    );
}

// Unmapped statuses still fall through to the generic code
assertTrue(
    errorCodeFor(418) === 'AI_UNEXPECTED_ERROR',
    'HTTP 418 maps to AI_UNEXPECTED_ERROR'
);

// Provider name does not affect mapping
assertTrue(
    errorCodeFor(402, $billingBody, 'OpenAI') === 'AI_BILLING_ERROR',
    'HTTP 402 maps to AI_BILLING_ERROR for OpenAI too'
);

echo "\n========================================\n";
echo "Results: {$pass} passed, {$fail} failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
