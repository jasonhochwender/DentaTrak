<?php
/**
 * Demo request form endpoint for the DentaTrak marketing homepage.
 *
 * Accepts a public demo request, validates it, and forwards it to
 * the support team via the existing Resend email configuration.
 *
 * Spam protection is layered and passive (no user-facing challenge):
 *   1. CSRF token            - session-bound, required for any POST
 *   2. Honeypot              - hidden 'website' field; populated = spam
 *   3. Timing                - session render timestamp; <3s = spam
 *   4. Rate limit            - per-IP attempt/accepted caps (DB-backed)
 *   5. Duplicate detection   - same normalized content within 15 min
 *   6. Field allowlist       - unexpected POST keys rejected as spam
 *
 * Spam rejections return the same generic success payload as a real
 * acceptance so bots cannot probe which rule fired. Each block is logged
 * by category only - no form contents are logged.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed', 'message' => t('api.method_not_allowed')]);
    exit;
}

requireCsrfToken();

/* ------------------------------------------------------------------ */
/* Spam protection layer                                               */
/* ------------------------------------------------------------------ */

$now = time();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipHash = hash('sha256', 'dtk-demo-request|' . $ip);

// Tunables - conservative values that should never affect a real user.
define('DEMO_MIN_FILL_SECONDS', 3);      // form cannot be completed faster
define('DEMO_SESSION_MIN_SECONDS', 60);  // one submission per minute per session
define('DEMO_IP_ATTEMPTS_PER_HOUR', 30); // any POST attempts (spam probing)
define('DEMO_IP_ACCEPTED_PER_HOUR', 5);  // real emails sent per IP
define('DEMO_DUP_WINDOW_SECONDS', 900);  // identical resubmission window

/**
 * Lazily create the attempts table (same convention as login_attempts in
 * unified-identity.php). Records every decision so rate limits work across
 * sessions and Cloud Run instances, and so blocked traffic is auditable.
 */
function demoAttemptsTable(PDO $pdo) {
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demo_request_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash CHAR(64) NOT NULL,
            email_hash CHAR(64) DEFAULT NULL,
            fingerprint CHAR(64) DEFAULT NULL,
            reason VARCHAR(32) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ip_time (ip_hash, created_at),
            KEY idx_fp_time (fingerprint, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ready = true;
}

function demoRecordAttempt($reason, $emailHash = null, $fingerprint = null) {
    global $pdo, $ipHash;
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        demoAttemptsTable($pdo);
        $pdo->prepare(
            'INSERT INTO demo_request_attempts (ip_hash, email_hash, fingerprint, reason) VALUES (:ip, :email, :fp, :reason)'
        )->execute([
            ':ip' => $ipHash,
            ':email' => $emailHash,
            ':fp' => $fingerprint,
            ':reason' => $reason,
        ]);
    } catch (Throwable $e) {
        error_log('[demo-request] attempt log failed: ' . $e->getMessage());
    }
}

/**
 * All spam rejections converge here: indistinguishable from a successful
 * submission so bots cannot tell which rule fired. Only the reason category
 * and a truncated IP hash are logged - never field contents.
 */
function demoSilentAccept($reason, $meta = []) {
    demoRecordAttempt($reason, $meta['emailHash'] ?? null, $meta['fingerprint'] ?? null);

    global $ipHash;
    $bits = ['reason=' . $reason, 'ip=' . substr($ipHash, 0, 12)];
    if (isset($meta['scope'])) {
        $bits[] = 'scope=' . $meta['scope'];
    }
    if (isset($meta['elapsed'])) {
        $bits[] = 'elapsed=' . $meta['elapsed'];
    }
    error_log('[demo-request] blocked ' . implode(' ', $bits));

    echo json_encode(['success' => true]);
    exit;
}

// 1) Honeypot: hidden field that humans never see or fill.
if (!empty($_POST['website'])) {
    demoSilentAccept('honeypot');
}

// 2) Unexpected fields: the real form posts an exact, fixed field set.
$allowedFields = ['csrf_token', 'name', 'email', 'practice', 'phone', 'preferred', 'message', 'website'];
$unexpected = array_diff(array_keys($_POST), $allowedFields);
if (!empty($unexpected)) {
    demoSilentAccept('unexpected_fields');
}

// 3) Timing: the form render stamps the session server-side; a submission
//    arriving <3s after render (or with no render at all) is a bot.
$renderedAt = $_SESSION['demo_form_rendered_at'] ?? null;
$elapsed = is_numeric($renderedAt) ? ($now - (int) $renderedAt) : null;
if ($elapsed === null || $elapsed < DEMO_MIN_FILL_SECONDS) {
    demoSilentAccept('too_fast', ['elapsed' => $elapsed === null ? 'no-render' : $elapsed]);
}

// 4) Rate limits: session burst guard plus DB-backed per-IP caps.
$last = $_SESSION['demo_request_last'] ?? 0;
if ($now - $last < DEMO_SESSION_MIN_SECONDS) {
    demoSilentAccept('rate_limit', ['scope' => 'session']);
}
if ($pdo instanceof PDO) {
    try {
        demoAttemptsTable($pdo);
        $stmt = $pdo->prepare(
            "SELECT
                SUM(reason = 'accepted') AS accepted_cnt,
                COUNT(*) AS attempt_cnt
             FROM demo_request_attempts
             WHERE ip_hash = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([':ip' => $ipHash]);
        $row = $stmt->fetch() ?: [];
        if ((int) ($row['attempt_cnt'] ?? 0) >= DEMO_IP_ATTEMPTS_PER_HOUR
            || (int) ($row['accepted_cnt'] ?? 0) >= DEMO_IP_ACCEPTED_PER_HOUR) {
            demoSilentAccept('rate_limit', ['scope' => 'ip']);
        }
    } catch (Throwable $e) {
        // If the tracking store is unavailable, fail open rather than block.
        error_log('[demo-request] rate-limit check failed: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Field collection + validation                                       */
/* ------------------------------------------------------------------ */

// Collect and trim fields
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$practice = trim($_POST['practice'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$preferred = trim($_POST['preferred'] ?? '');
$message = trim($_POST['message'] ?? '');

// Server-side validation
$fieldErrors = [];

function isEmpty($value) { return $value === ''; }
function tooLong($value, $max) { return mb_strlen($value, 'UTF-8') > $max; }

if (isEmpty($name) || tooLong($name, 100)) {
    $fieldErrors['name'] = t('marketing.demo.field_name_invalid');
}

if (isEmpty($email)) {
    $fieldErrors['email'] = t('marketing.demo.field_email_required');
} else {
    $sanitized = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($sanitized, FILTER_VALIDATE_EMAIL) || tooLong($sanitized, 254)) {
        $fieldErrors['email'] = t('validation.invalid_email');
    } else {
        $email = $sanitized;
    }
}

if (isEmpty($practice) || tooLong($practice, 120)) {
    $fieldErrors['practice'] = t('marketing.demo.field_practice_invalid');
}

if (tooLong($phone, 30)) {
    $fieldErrors['phone'] = t('marketing.demo.field_phone_too_long');
}

if (tooLong($preferred, 120)) {
    $fieldErrors['preferred'] = t('marketing.demo.field_preferred_too_long');
}

if (tooLong($message, 1000)) {
    $fieldErrors['message'] = t('marketing.demo.message_too_long');
}

if (!empty($fieldErrors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'validation', 'message' => t('marketing.demo.invalid_fields'), 'fields' => $fieldErrors]);
    exit;
}

// 5) Duplicate submissions: same normalized email + content within the
//    window counts as a resubmit (double-click, refresh-resubmit, bot loop).
$norm = function ($v) {
    return preg_replace('/\s+/', ' ', mb_strtolower(trim($v), 'UTF-8'));
};
$emailHash = hash('sha256', 'dtk-demo-email|' . $norm($email));
$fingerprint = hash('sha256', 'dtk-demo-content|' . implode('|', [
    $norm($email), $norm($name), $norm($practice), $norm($phone), $norm($message),
]));
if ($pdo instanceof PDO) {
    try {
        demoAttemptsTable($pdo);
        $stmt = $pdo->prepare(
            "SELECT 1 FROM demo_request_attempts
             WHERE fingerprint = :fp AND reason = 'accepted'
               AND created_at > DATE_SUB(NOW(), INTERVAL " . DEMO_DUP_WINDOW_SECONDS . " SECOND)
             LIMIT 1"
        );
        $stmt->execute([':fp' => $fingerprint]);
        if ($stmt->fetch()) {
            demoSilentAccept('duplicate', ['emailHash' => $emailHash, 'fingerprint' => $fingerprint]);
        }
    } catch (Throwable $e) {
        error_log('[demo-request] duplicate check failed: ' . $e->getMessage());
    }
}

// Extension point for a future interactive challenge: verify a Cloudflare
// Turnstile (or similar) token here and call demoSilentAccept('challenge')
// on failure. Not needed while passive protection is sufficient.

// Build the email to the support inbox, replying to the requester
$supportEmail = $appConfig['support_email'] ?? 'support@dentatrak.com';
$subject = 'DentaTrak Personal Demo Request';

$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safePractice = htmlspecialchars($practice, ENT_QUOTES, 'UTF-8');
$safePhone = htmlspecialchars($phone ?: 'Not provided', ENT_QUOTES, 'UTF-8');
$safePreferred = htmlspecialchars($preferred ?: 'Not provided', ENT_QUOTES, 'UTF-8');
$safeMessage = htmlspecialchars($message ?: 'None', ENT_QUOTES, 'UTF-8');

$htmlBody = '<p>New demo request from the DentaTrak homepage.</p>';
$htmlBody .= '<table style="border-collapse:collapse;">'
    . '<tr><td style="padding:4px 12px 4px 0; font-weight:600;">Name</td><td>' . $safeName . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0; font-weight:600;">Email</td><td>' . $safeEmail . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0; font-weight:600;">Practice</td><td>' . $safePractice . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0; font-weight:600;">Phone</td><td>' . $safePhone . '</td></tr>'
    . '<tr><td style="padding:4px 12px 4px 0; font-weight:600;">Preferred time</td><td>' . $safePreferred . '</td></tr>'
    . '</table>';
$htmlBody .= '<p style="font-weight:600; margin-top: 16px; margin-bottom: 4px;">Additional message</p>';
$htmlBody .= '<p style="white-space: pre-wrap; margin: 0;">' . nl2br($safeMessage, false) . '</p>';

$plainText = "New demo request from the DentaTrak homepage.\n\n";
$plainText .= "Name: " . $name . "\n";
$plainText .= "Email: " . $email . "\n";
$plainText .= "Practice: " . $practice . "\n";
$plainText .= "Phone: " . ($phone ?: 'Not provided') . "\n";
$plainText .= "Preferred time: " . ($preferred ?: 'Not provided') . "\n\n";
$plainText .= "Additional message:\n" . ($message ?: 'None') . "\n";

$sendResult = sendAppEmail($supportEmail, $subject, $htmlBody, $plainText, $email);

if (empty($sendResult['success'])) {
    error_log('[demo-request] Failed to send demo request from ' . $email . ': ' . ($sendResult['error'] ?? 'unknown'));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'delivery_failed', 'message' => t('errors.generic')]);
    exit;
}

// Record the accepted submission (also feeds the IP accepted-count limit)
// and keep the session throttle.
$_SESSION['demo_request_last'] = $now;
demoRecordAttempt('accepted', $emailHash, $fingerprint);

// Opportunistic cleanup of old attempt rows (older than 7 days).
if ($pdo instanceof PDO) {
    try {
        $pdo->exec("DELETE FROM demo_request_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Throwable $e) {
        // Non-critical; rows are bounded anyway by volume.
    }
}

echo json_encode([
    'success' => true,
    'message' => t('marketing.demo.success')
]);
