<?php
/**
 * Download BAA (Business Associate Agreement) PDF
 * 
 * Generates and downloads a PDF copy of the accepted BAA for a practice.
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/user-manager.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$userId = $_SESSION['db_user_id'];
$practiceId = $_SESSION['current_practice_id'] ?? null;

if (!$practiceId) {
    http_response_code(400);
    echo "No practice selected";
    exit;
}

try {
    // Get practice BAA details
    $stmt = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name, u.email
        FROM practices p
        LEFT JOIN users u ON p.baa_accepted_by_user_id = u.id
        WHERE p.id = :practice_id
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $practice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$practice) {
        http_response_code(404);
        echo "Practice not found";
        exit;
    }
    
    if (!$practice['baa_accepted']) {
        http_response_code(400);
        echo "BAA has not been accepted for this practice";
        exit;
    }
    
    // Verify user belongs to this practice
    $stmt = $pdo->prepare("
        SELECT 1 FROM practice_users 
        WHERE practice_id = :practice_id AND user_id = :user_id
    ");
    $stmt->execute(['practice_id' => $practiceId, 'user_id' => $userId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo "Access denied";
        exit;
    }
    
    // Format the acceptance date
    $acceptedAt = $practice['baa_accepted_at'] 
        ? date('F j, Y \a\t g:i A T', strtotime($practice['baa_accepted_at']))
        : 'Unknown';
    
    $acceptedByName = trim(($practice['first_name'] ?? '') . ' ' . ($practice['last_name'] ?? ''));
    if (empty($acceptedByName)) {
        $acceptedByName = $practice['email'] ?? 'Unknown';
    }
    
    // Generate HTML content for the BAA
    $appName = $appConfig['appName'] ?? 'Dental Case Manager';
    $legalName = htmlspecialchars($practice['legal_name'] ?? $practice['practice_name']);
    $practiceAddress = htmlspecialchars($practice['practice_address'] ?? 'Not provided');
    $signerName = htmlspecialchars($practice['baa_signer_name'] ?? 'Not provided');
    $signerTitle = htmlspecialchars($practice['baa_signer_title'] ?? 'Not provided');
    $baaVersion = htmlspecialchars($practice['baa_version'] ?? 'v1.0');
    $generatedDate = date('F j, Y \a\t g:i A T');
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Business Associate Agreement - {$legalName}</title>
    <style>
        @page { margin: 1in; }
        body { 
            font-family: 'Times New Roman', Times, serif; 
            font-size: 12pt; 
            line-height: 1.6;
            color: #000;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        .header h1 { 
            font-size: 18pt; 
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header h2 {
            font-size: 14pt;
            margin: 0;
            font-weight: normal;
        }
        .section { margin: 20px 0; }
        .section-title { 
            font-weight: bold; 
            font-size: 13pt;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .info-box {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 15px;
            margin: 20px 0;
        }
        .info-row {
            margin: 8px 0;
        }
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 200px;
        }
        .article { margin: 25px 0; }
        .article-title { 
            font-weight: bold;
            margin-bottom: 10px;
        }
        .signature-block {
            margin-top: 50px;
            border-top: 1px solid #333;
            padding-top: 20px;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            width: 300px;
            display: inline-block;
            margin: 5px 0;
        }
        .footer {
            margin-top: 50px;
            font-size: 10pt;
            color: #666;
            text-align: center;
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .acceptance-stamp {
            background: #e8f5e9;
            border: 2px solid #4caf50;
            padding: 15px;
            margin: 30px 0;
            text-align: center;
        }
        .acceptance-stamp .checkmark {
            font-size: 24pt;
            color: #4caf50;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Business Associate Agreement</h1>
        <h2>{$appName}</h2>
    </div>
    
    <div class="info-box">
        <div class="info-row"><span class="info-label">Legal Practice Name:</span> {$legalName}</div>
        <div class="info-row"><span class="info-label">Practice Address:</span> {$practiceAddress}</div>
        <div class="info-row"><span class="info-label">BAA Version:</span> {$baaVersion}</div>
        <div class="info-row"><span class="info-label">Acceptance Date:</span> {$acceptedAt}</div>
    </div>
    
    <div class="section">
        <div class="section-title">Preamble</div>
        <p>This Business Associate Agreement ("Agreement") is entered into by and between {$appName} ("Business Associate") and {$legalName} ("Covered Entity"), collectively referred to as the "Parties."</p>
        <p>This Agreement is intended to ensure that Business Associate will establish and implement appropriate safeguards for Protected Health Information ("PHI") that Business Associate may create, receive, maintain, or transmit on behalf of Covered Entity pursuant to the Health Insurance Portability and Accountability Act of 1996 ("HIPAA") and the Health Information Technology for Economic and Clinical Health Act ("HITECH Act").</p>
    </div>
    
    <div class="article">
        <div class="article-title">Article 1: Definitions</div>
        <p>Terms used but not otherwise defined in this Agreement shall have the same meaning as those terms in the HIPAA Rules (45 CFR Parts 160 and 164).</p>
    </div>
    
    <div class="article">
        <div class="article-title">Article 2: Obligations of Business Associate</div>
        <p>Business Associate agrees to:</p>
        <ul>
            <li>Not use or disclose PHI other than as permitted or required by this Agreement or as required by law;</li>
            <li>Use appropriate safeguards to prevent use or disclosure of PHI other than as provided for by this Agreement;</li>
            <li>Report to Covered Entity any use or disclosure of PHI not provided for by this Agreement of which it becomes aware;</li>
            <li>Ensure that any agents or subcontractors that create, receive, maintain, or transmit PHI on behalf of Business Associate agree to the same restrictions and conditions;</li>
            <li>Make available PHI in accordance with the individual's rights under HIPAA;</li>
            <li>Make its internal practices and records relating to the use and disclosure of PHI available to the Secretary of Health and Human Services;</li>
            <li>Return or destroy all PHI received from, or created or received by Business Associate on behalf of, Covered Entity upon termination of this Agreement.</li>
        </ul>
    </div>
    
    <div class="article">
        <div class="article-title">Article 3: Permitted Uses and Disclosures</div>
        <p>Business Associate may use or disclose PHI:</p>
        <ul>
            <li>To perform functions, activities, or services for, or on behalf of, Covered Entity as specified in the Terms of Service;</li>
            <li>For the proper management and administration of Business Associate;</li>
            <li>To provide data aggregation services relating to the health care operations of Covered Entity;</li>
            <li>As required by law.</li>
        </ul>
    </div>
    
    <div class="article">
        <div class="article-title">Article 4: Term and Termination</div>
        <p>This Agreement shall be effective as of the date of electronic acceptance and shall terminate when all PHI provided by Covered Entity to Business Associate is destroyed or returned to Covered Entity, or if it is infeasible to return or destroy PHI, protections are extended to such information.</p>
    </div>
    
    <div class="article">
        <div class="article-title">Article 5: Miscellaneous</div>
        <p>This Agreement shall be governed by the laws of the State of Georgia. Any ambiguity in this Agreement shall be resolved to permit Covered Entity to comply with HIPAA.</p>
    </div>
    
    <div class="acceptance-stamp">
        <div class="checkmark">✓</div>
        <strong>ELECTRONICALLY ACCEPTED</strong><br>
        This agreement was electronically accepted on {$acceptedAt}
    </div>
    
    <div class="signature-block">
        <p><strong>Authorized Signer Information:</strong></p>
        <div class="info-row"><span class="info-label">Name:</span> {$signerName}</div>
        <div class="info-row"><span class="info-label">Title:</span> {$signerTitle}</div>
        <div class="info-row"><span class="info-label">Accepted By User:</span> {$acceptedByName}</div>
        <div class="info-row"><span class="info-label">Date:</span> {$acceptedAt}</div>
    </div>
    
    <div class="footer">
        <p>Document ID: BAA-{$practice['id']}-{$baaVersion}</p>
        <p>This document serves as a record of the Business Associate Agreement acceptance.</p>
        <p>Generated on: {$generatedDate}</p>
    </div>
</body>
</html>
HTML;

    // Set headers for PDF download (using HTML for now, can be converted to PDF with a library)
    // For simplicity, we'll serve as HTML that can be printed to PDF
    $filename = 'BAA-' . preg_replace('/[^a-zA-Z0-9]/', '-', $legalName) . '-' . date('Y-m-d') . '.html';
    
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    echo $html;
    
    userLog("BAA downloaded for practice: {$legalName} (ID: {$practiceId})", false);
    
} catch (PDOException $e) {
    userLog("Error downloading BAA: " . $e->getMessage(), true);
    http_response_code(500);
    echo "Error generating BAA document";
}
