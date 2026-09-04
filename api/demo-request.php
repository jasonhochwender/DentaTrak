<?php
/**
 * Demo request form endpoint for the DentaTrak marketing homepage.
 *
 * Accepts a public demo request, validates it, and forwards it to
 * the support team via the existing Resend email configuration.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed', 'message' => 'Method not allowed.']);
    exit;
}

requireCsrfToken();

// Honeypot: if this hidden field has a value, treat it as a quiet success for bots
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true]);
    exit;
}

// Rate limit per IP/session
$now = time();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipKey = 'demo_request_' . md5($ip);
$last = $_SESSION['demo_request_last'] ?? 0;
$ipLast = $_SESSION[$ipKey] ?? 0;

if ($now - $last < 60 || $now - $ipLast < 60) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'rate_limited', 'message' => 'Please wait a moment before submitting again.']);
    exit;
}

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
    $fieldErrors['name'] = 'Name is required and must be 100 characters or less.';
}

if (isEmpty($email)) {
    $fieldErrors['email'] = 'Work email is required.';
} else {
    $sanitized = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($sanitized, FILTER_VALIDATE_EMAIL) || tooLong($sanitized, 254)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    } else {
        $email = $sanitized;
    }
}

if (isEmpty($practice) || tooLong($practice, 120)) {
    $fieldErrors['practice'] = 'Practice name is required and must be 120 characters or less.';
}

if (tooLong($phone, 30)) {
    $fieldErrors['phone'] = 'Phone number is too long.';
}

if (tooLong($preferred, 120)) {
    $fieldErrors['preferred'] = 'Preferred day or time is too long.';
}

if (tooLong($message, 1000)) {
    $fieldErrors['message'] = 'Message is too long.';
}

if (!empty($fieldErrors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'Please correct the highlighted fields.', 'fields' => $fieldErrors]);
    exit;
}

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
    echo json_encode(['success' => false, 'error' => 'delivery_failed', 'message' => 'Something went wrong. Please try again later.']);
    exit;
}

// Record successful submission timestamps
$_SESSION['demo_request_last'] = $now;
$_SESSION[$ipKey] = $now;

echo json_encode([
    'success' => true,
    'message' => "Your demo request has been received. We'll contact you shortly to find a time that works."
]);
