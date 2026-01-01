<?php
// Include necessary files
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/email-sender.php';
require_once __DIR__ . '/csrf.php';

// Set proper content type for JSON response
header('Content-Type: application/json');

// Check if the user is logged in
if (!isset($_SESSION['user'])) {
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated'
    ]);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

// Validate CSRF token
requireCsrfToken();

// Feedback submission endpoint

// Get the POST data (try both JSON and form-encoded)
$data = [];

// Try getting JSON data
$jsonInput = file_get_contents('php://input');

if (!empty($jsonInput)) {
    $data = json_decode($jsonInput, true);
}

// If JSON parsing failed or was empty, try form data
if (empty($data)) {
    $data = $_POST;
}

// Validate required fields
if (!isset($data['feedback_type']) || !isset($data['feedback_comments'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields'
    ]);
    exit;
}

// Get user info
$user = $_SESSION['user'];
$userName = $user['name'] ?? 'Unknown User';
$userEmail = $user['email'] ?? 'unknown@example.com';

// Get practice name
$practiceName = 'Unknown Practice';
$currentPracticeId = $_SESSION['current_practice_id'] ?? null;
if ($currentPracticeId && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT practice_name FROM practices WHERE id = :id");
        $stmt->execute(['id' => $currentPracticeId]);
        $practice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($practice && !empty($practice['practice_name'])) {
            $practiceName = $practice['practice_name'];
        }
    } catch (PDOException $e) {
        // Use default practice name
    }
}

// Get feedback data
$feedbackType = htmlspecialchars($data['feedback_type']);
$comments = htmlspecialchars($data['feedback_comments']);

// Format feedback type
switch ($feedbackType) {
    case 'positive':
        $feedbackEmoji = '😃';
        break;
    case 'neutral':
        $feedbackEmoji = '😐';
        break;
    case 'negative':
        $feedbackEmoji = '🙁';
        break;
    default:
        $feedbackEmoji = '';
}

// Use the configuration to get email settings
$fromEmail = $appConfig['fromEmail'] ?? 'noreply@example.com';
$fromName = $appConfig['fromName'] ?? $appConfig['appName'];
$feedbackEmail = $appConfig['feedback_email'] ?? 'feedback@dentatrak.com';

// Prepare email content
$subject = "User Feedback: {$feedbackType} {$feedbackEmoji}";

$message = "
<html>
<head>
    <title>User Feedback</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f1f1f1; padding: 15px; border-radius: 5px; }
        .feedback-type { font-size: 18px; margin-bottom: 20px; }
        .comments { background-color: #f9f9f9; padding: 15px; border-left: 4px solid #ccc; }
        .user-info { margin-top: 30px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>User Feedback Received</h2>
        </div>
        <div class='feedback-type'>
            <strong>Feedback Type:</strong> {$feedbackType} {$feedbackEmoji}
        </div>
        <div class='comments'>
            <strong>Comments:</strong><br>
            " . nl2br($comments) . "
        </div>
        <div class='user-info'>
            <strong>Practice:</strong> {$practiceName}<br>
            <strong>Submitted by:</strong> {$userName}<br>
            <strong>Email:</strong> {$userEmail}
        </div>
    </div>
</body>
</html>
";

// Send email using SendGrid
$emailSuccess = true;

if (!empty($feedbackEmail)) {
    $result = sendAppEmail($feedbackEmail, $subject, $message, null, $userEmail);
    $emailSuccess = (bool) ($result['success'] ?? false);
}

// Return success response
echo json_encode([
    'success' => true,
    'email_sent' => $emailSuccess,
    'message' => 'Feedback received. Thank you!'
]);
exit;
