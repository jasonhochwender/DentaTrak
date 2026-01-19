<?php
/**
 * Waitlist Admin API
 * Handles CRUD operations for waitlist and promo email sending
 * Restricted to super admins only
 */

// Use centralized session handling
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/email-sender.php';

header('Content-Type: application/json');

// Check if user is logged in
if (empty($_SESSION['db_user_id']) || empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get user email from session
$userEmail = $_SESSION['user_email'];

// Check if user can access admin pages (super user OR dev environment)
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Ensure waitlist table exists and has promo tracking columns
try {
    // First, create the table if it doesn't exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS waitlist (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source VARCHAR(50) DEFAULT 'landing_page',
            promo_emails_sent INT DEFAULT 0,
            last_promo_sent_at TIMESTAMP NULL,
            INDEX idx_email (email)
        ) ENGINE=InnoDB
    ");
    
    // Check if columns exist (for existing tables)
    $columns = $pdo->query("SHOW COLUMNS FROM waitlist")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('source', $columns)) {
        $pdo->exec("ALTER TABLE waitlist ADD COLUMN source VARCHAR(50) DEFAULT 'landing_page'");
    }
    if (!in_array('promo_emails_sent', $columns)) {
        $pdo->exec("ALTER TABLE waitlist ADD COLUMN promo_emails_sent INT DEFAULT 0");
    }
    if (!in_array('last_promo_sent_at', $columns)) {
        $pdo->exec("ALTER TABLE waitlist ADD COLUMN last_promo_sent_at TIMESTAMP NULL");
    }
} catch (PDOException $e) {
    // Silently ignore - table/columns might already exist
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        // Get all waitlist entries
        handleGetWaitlist();
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'add') {
            handleAddEmail($input);
        } elseif ($action === 'send-promo') {
            handleSendPromo($input);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function handleGetWaitlist() {
    global $pdo;
    
    try {
        // Get column list to build dynamic query
        $columns = $pdo->query("SHOW COLUMNS FROM waitlist")->fetchAll(PDO::FETCH_COLUMN);
        
        // Build SELECT with available columns
        $selectCols = ['id', 'email', 'created_at'];
        if (in_array('source', $columns)) $selectCols[] = 'source';
        if (in_array('promo_emails_sent', $columns)) $selectCols[] = 'promo_emails_sent';
        if (in_array('last_promo_sent_at', $columns)) $selectCols[] = 'last_promo_sent_at';
        
        $sql = "SELECT " . implode(', ', $selectCols) . " FROM waitlist ORDER BY created_at DESC";
        $stmt = $pdo->query($sql);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure all expected fields exist in response (with defaults)
        foreach ($entries as &$entry) {
            if (!isset($entry['source'])) $entry['source'] = 'landing_page';
            if (!isset($entry['promo_emails_sent'])) $entry['promo_emails_sent'] = 0;
            if (!isset($entry['last_promo_sent_at'])) $entry['last_promo_sent_at'] = null;
        }
        
        echo json_encode([
            'success' => true,
            'entries' => $entries,
            'count' => count($entries)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch waitlist']);
    }
}

function handleAddEmail($input) {
    global $pdo;
    
    $email = trim($input['email'] ?? '');
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email address']);
        return;
    }
    
    try {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM waitlist WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Email already exists']);
            return;
        }
        
        // Insert new email
        $stmt = $pdo->prepare("INSERT INTO waitlist (email, source) VALUES (?, 'manual_add')");
        $stmt->execute([$email]);
        
        $newId = $pdo->lastInsertId();
        
        // Fetch the new entry
        $stmt = $pdo->prepare("SELECT id, email, created_at, source, promo_emails_sent, last_promo_sent_at FROM waitlist WHERE id = ?");
        $stmt->execute([$newId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Email added successfully',
            'entry' => $entry
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to add email']);
    }
}

function handleSendPromo($input) {
    global $pdo, $appConfig;
    
    $waitlistId = intval($input['id'] ?? 0);
    
    if (!$waitlistId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid waitlist ID']);
        return;
    }
    
    try {
        // Get the email address
        $stmt = $pdo->prepare("SELECT id, email FROM waitlist WHERE id = ?");
        $stmt->execute([$waitlistId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$entry) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Email not found']);
            return;
        }
        
        // Generate promo code (or use a fixed one)
        $promoCode = 'FOUNDING20';
        
        // Build the email
        $subject = 'Your Exclusive 20% Off DentaTrak Control Plan';
        
        $htmlBody = buildPromoEmailHtml($entry['email'], $promoCode);
        
        // Send the email
        $result = sendAppEmail($entry['email'], $subject, $htmlBody);
        
        if ($result['success']) {
            // Update the database
            $stmt = $pdo->prepare("
                UPDATE waitlist 
                SET promo_emails_sent = promo_emails_sent + 1,
                    last_promo_sent_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$waitlistId]);
            
            // Fetch updated entry
            $stmt = $pdo->prepare("SELECT id, email, created_at, source, promo_emails_sent, last_promo_sent_at FROM waitlist WHERE id = ?");
            $stmt->execute([$waitlistId]);
            $updatedEntry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Promo email sent successfully',
                'entry' => $updatedEntry
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to send email: ' . ($result['error'] ?? 'Unknown error')
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
}

function buildPromoEmailHtml($email, $promoCode) {
    return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Exclusive DentaTrak Offer</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f5;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%); padding: 30px 40px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">DentaTrak</h1>
                            <p style="margin: 10px 0 0; color: #a0c4e8; font-size: 14px;">Dental Case Management</p>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px; color: #1a365d; font-size: 24px;">Thank You for Your Interest!</h2>
                            
                            <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px; line-height: 1.6;">
                                As one of our founding practices, we\'re excited to offer you an exclusive discount on DentaTrak.
                            </p>
                            
                            <div style="background-color: #f0f7ff; border: 2px dashed #2d5a87; border-radius: 8px; padding: 25px; text-align: center; margin: 30px 0;">
                                <p style="margin: 0 0 10px; color: #4a5568; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Your Promo Code</p>
                                <p style="margin: 0; color: #1a365d; font-size: 32px; font-weight: 700; letter-spacing: 2px;">' . htmlspecialchars($promoCode) . '</p>
                                <p style="margin: 15px 0 0; color: #2d5a87; font-size: 18px; font-weight: 600;">20% Off Your First Year</p>
                                <p style="margin: 5px 0 0; color: #718096; font-size: 14px;">on the Control Plan</p>
                            </div>
                            
                            <p style="margin: 0 0 20px; color: #4a5568; font-size: 16px; line-height: 1.6;">
                                <strong>What you\'ll get with the Control Plan:</strong>
                            </p>
                            
                            <ul style="margin: 0 0 30px; padding-left: 20px; color: #4a5568; font-size: 15px; line-height: 1.8;">
                                <li>Unlimited case tracking</li>
                                <li>Full team collaboration</li>
                                <li>Advanced analytics & insights</li>
                                <li>Priority support</li>
                                <li>Custom workflow statuses</li>
                            </ul>
                            
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="https://dentatrak.com/login.php" style="display: inline-block; background: linear-gradient(135deg, #1a365d 0%, #2d5a87 100%); color: #ffffff; text-decoration: none; padding: 15px 40px; border-radius: 6px; font-size: 16px; font-weight: 600;">Get Started Now</a>
                            </div>
                            
                            <p style="margin: 30px 0 0; color: #718096; font-size: 14px; line-height: 1.6;">
                                Questions? Just reply to this email - we\'re here to help!
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; padding: 25px 40px; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; color: #718096; font-size: 13px; text-align: center;">
                                &copy; 2026 DentaTrak. All rights reserved.<br>
                                <a href="https://dentatrak.com" style="color: #2d5a87; text-decoration: none;">dentatrak.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}
