<?php
// Start session
session_start();

// Load PDF generation library
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpPresentation\IOFactory;

try {
    // Increase execution time limit for large documents
    set_time_limit(120); // Increase from 30 to 120 seconds
    
    // IMPORTANT: Set up Ghostscript FIRST before any extension checks
    // Ensure Ghostscript is available by creating a local batch file if needed
    $ghostscriptPath = 'C:\Program Files\gs\gs10.06.0\bin\gswin64c.exe';
    $batchFile = __DIR__ . '\gswin64c.bat';
    
    if (!file_exists($batchFile) && file_exists($ghostscriptPath)) {
        $batchContent = '@echo off' . "\n" . '"' . $ghostscriptPath . '" %*';
        file_put_contents($batchFile, $batchContent);
    }
    
    // Add current directory to PATH so ImageMagick can find our batch file
    putenv('PATH=' . getenv('PATH') . ';' . __DIR__);
    
    // Include configuration
    require_once __DIR__ . '/appConfig.php';

    // Set timezone to fix the 5-hour offset
    date_default_timezone_set('America/New_York');

    // Enable error logging
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/error.log');

    // Include case activity logging and HIPAA compliance
    require_once __DIR__ . '/case-activity-log.php';
    require_once __DIR__ . '/hipaa-compliance.php';

    // Set headers to prevent caching
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    // Get JSON input
    $jsonInput = file_get_contents('php://input');
    $input = json_decode($jsonInput, true);
    
    if (!$input || !isset($input['caseData'])) {
        throw new Exception('Case data is required');
    }
    
    $caseData = $input['caseData'];
    
    // Log the print activity
    $caseId = $caseData['id'] ?? null;
    if ($caseId) {
        $patientName = trim(($caseData['patientFirstName'] ?? '') . ' ' . ($caseData['patientLastName'] ?? ''));
        logCaseActivity(
            $caseId,
            'case_printed',
            null,
            null,
            [
                'has_attachments' => !empty($caseData['attachments']),
                'attachment_count' => is_array($caseData['attachments'] ?? null) ? count($caseData['attachments']) : 0
            ]
        );
        
        // Log PHI access for HIPAA compliance (printing = exporting PHI)
        logPHIAccess('print_case', $caseId);
    }
    
    // Check if GD extension is available for image processing
    $gdAvailable = extension_loaded('gd') || extension_loaded('gd2');
    if (!$gdAvailable) {
        error_log('GD extension not available - PDF will be generated without images');
    }
    
    // Prepare attachments data
    $attachments = isset($caseData['attachments']) ? $caseData['attachments'] : [];
    
    // Handle different attachment formats
    if (is_string($attachments)) {
        try {
            $attachments = json_decode($attachments, true);
        } catch (Exception $e) {
            $attachments = [];
        }
    }
    
    // Generate HTML content optimized for PDF
    $htmlContent = generatePrintableHTML($caseData, $attachments, $gdAvailable);
    
    // Create PDF
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isFontSubsettingEnabled', true); // Optimize font usage
    $options->set('defaultMediaType', 'print'); // Optimize for print
    $options->set('enableCssFloat', false); // Disable complex CSS for performance
    $options->set('enableJavascript', false); // Disable JavaScript for performance
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($htmlContent);
    
    // Try portrait first
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    
    // More sophisticated width check - re-render in landscape if content seems wide
    // We'll use a more comprehensive heuristic for detecting wide content
    $hasWideContent = false;
    $maxColumns = 0;
    
    // Check for wide tables or images in the HTML content
    if (strpos($htmlContent, '<table') !== false || strpos($htmlContent, '<img') !== false) {
        // Count table columns more accurately
        preg_match_all('/<table[^>]*>(.*?)<\/table>/s', $htmlContent, $tables);
        foreach ($tables[1] as $tableContent) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tableContent, $cells);
            $columnCount = count($cells[1]);
            $maxColumns = max($maxColumns, $columnCount);
            
            // More than 8 columns or very wide content suggests landscape
            if ($columnCount > 8) {
                $hasWideContent = true;
                break;
            }
        }
        
        // Check for Excel content specifically (often wide)
        if (strpos($htmlContent, 'Excel Content') !== false && $maxColumns > 6) {
            $hasWideContent = true;
        }
        
        // Check for images with explicit width
        if (preg_match('/<img[^>]*width=["\']?(400|500|600|700|800|900|1000+)/i', $htmlContent)) {
            $hasWideContent = true;
        }
        
        // Check for very long text content that might overflow
        if (preg_match('/<pre[^>]*>[^<]{200,}<\/pre>/s', $htmlContent)) {
            $hasWideContent = true;
        }
    }
    
    // If content appears wide, re-render in landscape
    if ($hasWideContent) {
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('Letter', 'landscape');
        $dompdf->render();
    }
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Case_' . ($caseData['patientFirstName'] . '_' . $caseData['patientLastName']) . '_' . $caseData['id'] . '.pdf"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    echo $dompdf->output();
    
} catch (Exception $e) {
    // Output error as text if generation fails
    error_log('Error in print-case.php: ' . $e->getMessage());
    header('Content-Type: text/plain');
    echo 'Error generating document: ' . $e->getMessage();
    exit;
}

function getCaseFromCache($caseId) {
    $cacheFile = __DIR__ . '/../cache/case_' . $caseId . '.json';
    if (file_exists($cacheFile)) {
        $jsonContent = file_get_contents($cacheFile);
        return json_decode($jsonContent, true);
    }
    return null;
}

function getPracticeNameFromSettings() {
    // For now, return a default practice name
    // In a full implementation, this would read from the database or settings
    return 'Practice';
}

function generatePrintableHTML($caseData, $attachments = [], $gdAvailable = true) {
    // Get practice name from case data (sent from JavaScript)
    $practiceName = isset($caseData['practiceName']) ? $caseData['practiceName'] : 'Practice';
    
    // Get app name from config
    global $commonConfig;
    $appName = isset($commonConfig['appName']) ? $commonConfig['appName'] : 'App';
    
    // Start output buffering to capture HTML
    ob_start();
    
    // Generate HTML content optimized for printing
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Case Report - <?php echo htmlspecialchars($caseData['patientFirstName'] . ' ' . $caseData['patientLastName']); ?></title>
        <style>
            @page {
                size: Letter;
                margin: 0.5in;
            }
            
            @page landscape {
                size: Letter landscape;
                margin: 0.5in;
            }
            
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                margin: 0;
                padding: 20px;
                color: #333;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                max-width: 100%;
                box-sizing: border-box;
            }
            
            /* Responsive table styling */
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9px;
                margin: 10px 0;
                table-layout: fixed;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            
            table td, table th {
                border: 1px solid #ddd;
                padding: 3px;
                text-align: left;
                vertical-align: top;
                word-wrap: break-word;
                overflow-wrap: break-word;
                max-width: 100%;
                white-space: normal;
                font-size: 8px;
            }
            
            /* Special handling for wide tables */
            .wide-table {
                font-size: 7px;
            }
            
            .wide-table td, .wide-table th {
                font-size: 7px;
                padding: 2px;
            }
            
            /* Image responsiveness */
            img {
                max-width: 100%;
                height: auto;
                display: block;
                margin: 10px auto;
            }
            
            /* Prevent content overflow */
            .file-content, .document-content {
                word-wrap: break-word;
                overflow-wrap: break-word;
                max-width: 100%;
            }
            
            .file-content pre, .document-content pre {
                white-space: pre-wrap;
                word-wrap: break-word;
                max-width: 100%;
                font-size: 9px;
            }
            
            .practice-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #333;
            }
            
            .practice-info {
                text-align: left;
                flex: 1;
            }
            
            .practice-logo {
                text-align: right;
                flex: 0 0 150px;
                height: 60px;
            }
            
            .practice-logo img {
                max-height: 60px;
                max-width: 150px;
                object-fit: contain;
            }
            
            .practice-name {
                font-size: 18px;
                font-weight: bold;
                color: #333;
                margin: 0;
            }
            
            .practice-address {
                font-size: 12px;
                color: #666;
                margin: 3px 0;
            }
            
            .practice-contact {
                font-size: 11px;
                color: #666;
                margin: 2px 0;
            }
            
            .header {
                text-align: center;
                border-bottom: 2px solid #333;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            
            .header h1 {
                margin: 0;
                font-size: 24px;
                color: #333;
            }
            
            .header .case-id {
                font-size: 14px;
                color: #666;
                margin-top: 5px;
            }
            
            /* Section spacing to prevent overlap */
            .section {
                margin-bottom: 40px;
                page-break-inside: avoid;
                clear: both;
                border-bottom: 1px solid #eee;
                padding-bottom: 20px;
            }
            
            .section:last-child {
                border-bottom: none;
                margin-bottom: 20px;
            }
            
            .section h2 {
                page-break-after: avoid;
                border-bottom: 2px solid #333;
                padding-bottom: 8px;
                margin-bottom: 20px;
                margin-top: 0;
            }
            
            /* Ensure attachments section doesn't overlap timeline */
            #attachments {
                page-break-after: auto;
                margin-bottom: 50px;
                clear: both;
            }
            
            #timeline {
                page-break-before: auto;
                page-break-inside: avoid;
                margin-top: 60px; /* Increased from 40px */
                border-top: 3px solid #333; /* Stronger border */
                padding-top: 30px; /* Increased from 25px */
                clear: both;
                background: white; /* Add background */
                padding: 30px 20px; /* Add horizontal padding */
                border-radius: 4px;
                border: 1px solid #ddd; /* Add border */
            }
            
            /* Document content spacing */
            .document-content {
                margin-bottom: 30px;
                page-break-inside: avoid;
                clear: both;
                background: white;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 4px;
                overflow: visible; /* Allow content to be visible */
                min-height: auto; /* Let content determine height */
                height: auto; /* Ensure container expands */
            }
            
            .document-content h4 {
                page-break-after: avoid;
                margin-bottom: 15px;
                border-bottom: 1px solid #ddd;
                padding-bottom: 8px;
                color: #333;
                margin-top: 0;
            }
            
            /* File section improvements */
            .file-section {
                margin-top: 25px;
                page-break-inside: avoid;
                clear: both;
                background: #fafafa;
                padding: 15px;
                border-radius: 4px;
                border: 1px solid #eee;
                overflow: visible; /* Allow content to be visible */
                min-height: auto; /* Let content determine height */
                height: auto; /* Ensure container expands */
            }
            
            /* Ensure attachments container expands */
            #attachments {
                page-break-after: auto;
                margin-bottom: 60px; /* More space before timeline */
                clear: both;
                overflow: visible;
                min-height: auto;
                height: auto;
            }
            
            .field {
                margin-bottom: 8px;
                display: flex;
            }
            
            .field-label {
                font-weight: bold;
                width: 150px;
                flex-shrink: 0;
            }
            
            .field-value {
                flex-grow: 1;
            }
            
            .file-section {
                margin-top: 20px;
                page-break-inside: avoid;
            }
            
            .file-header {
                background-color: #f5f5f5;
                padding: 10px;
                border: 1px solid #ddd;
                font-weight: bold;
                margin-bottom: 10px;
            }
            
            .file-content {
                border: 1px solid #ddd;
                padding: 15px;
                background-color: #fafafa;
                max-height: 400px;
                overflow-y: auto;
            }
            
            .file-content pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
                font-family: monospace;
                font-size: 10px;
            }
            
            .image-content {
                text-align: center;
                padding: 10px;
            }
            
            .image-content img {
                max-width: 100%;
                height: auto;
                max-height: 300px;
            }
            
            .no-files {
                font-style: italic;
                color: #666;
                text-align: center;
                padding: 20px;
            }
            
            .binary-file-info {
                padding: 15px;
                background-color: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 4px;
            }
            
            .binary-file-info p {
                margin: 5px 0;
                font-size: 11px;
            }
            
            .binary-file-info em {
                color: #6c757d;
                font-size: 10px;
            }
            
            .document-placeholder {
                padding: 15px;
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            
            .document-placeholder p {
                margin: 5px 0;
                font-size: 11px;
            }
            
            .document-placeholder em {
                color: #856404;
                font-size: 10px;
            }
            
            .document-content {
                padding: 15px;
                background-color: #d4edda;
                border: 1px solid #c3e6cb;
                border-radius: 4px;
                margin-bottom: 10px;
            }
            
            .document-content h4 {
                margin: 0 0 10px 0;
                font-size: 12px;
                color: #155724;
            }
            
            .document-content pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
                font-family: monospace;
                font-size: 9px;
                background-color: #f8f9fa;
                padding: 10px;
                border-radius: 3px;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #ccc;
                font-size: 10px;
                color: #666;
                text-align: center;
            }
            
            /* Print-specific styles */
            @media print {
                body {
                    margin: 0;
                    padding: 15px;
                }
                
                .section {
                    page-break-inside: avoid;
                    margin-bottom: 40px !important;
                    clear: both !important;
                    display: block !important;
                    overflow: visible !important;
                }
                
                .file-section {
                    page-break-inside: avoid;
                    margin-bottom: 30px !important;
                    clear: both !important;
                    display: block !important;
                    overflow: visible !important;
                }
                
                #attachments {
                    margin-bottom: 80px !important;
                    clear: both !important;
                    display: block !important;
                    overflow: visible !important;
                }
                
                #timeline {
                    margin-top: 60px !important;
                    clear: both !important;
                    display: block !important;
                    overflow: visible !important;
                }
                
                .document-content {
                    page-break-inside: avoid !important;
                    margin-bottom: 30px !important;
                    clear: both !important;
                    display: block !important;
                    overflow: visible !important;
                    height: auto !important;
                }
            }
            
            /* PDF-specific overrides */
            .section {
                display: block !important;
                clear: both !important;
                overflow: visible !important;
                height: auto !important;
            }
            
            #attachments {
                display: block !important;
                clear: both !important;
                overflow: visible !important;
                height: auto !important;
            }
            
            #timeline {
                display: block !important;
                clear: both !important;
                overflow: visible !important;
                height: auto !important;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Patient Case Report</h1>
            <div style="margin-top: 10px; font-size: 14px; color: #333; font-weight: bold;">
                <?php echo htmlspecialchars($practiceName); ?>
            </div>
            <div style="margin-top: 5px; font-size: 12px; color: #666;">
                Generated on <?php echo date('F j, Y g:i A'); ?>
            </div>
            <?php if (!$gdAvailable): ?>
            <div style="margin-top: 10px; padding: 8px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; font-size: 11px; color: #856404;">
                <strong>Note:</strong> PHP GD extension is not installed. Images and document previews are not displayed in this PDF.
            </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>Patient Information</h2>
            <div class="field">
                <div class="field-label">Patient Name:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['patientFirstName'] . ' ' . $caseData['patientLastName']); ?></div>
            </div>
            <?php if (!empty($caseData['patientDOB'])): ?>
            <div class="field">
                <div class="field-label">Date of Birth:</div>
                <div class="field-value"><?php echo htmlspecialchars(formatDate($caseData['patientDOB'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($caseData['patientGender'])): ?>
            <div class="field">
                <div class="field-label">Gender:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['patientGender']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($caseData['dentistName'])): ?>
            <div class="field">
                <div class="field-label">Dentist:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['dentistName']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <h2>Case Details</h2>
            <div class="field">
                <div class="field-label">Case Type:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['caseType']); ?></div>
            </div>
            <?php if (!empty($caseData['toothShade'])): ?>
            <div class="field">
                <div class="field-label">Tooth Shade:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['toothShade']); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($caseData['material'])): ?>
            <div class="field">
                <div class="field-label">Material:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['material']); ?></div>
            </div>
            <?php endif; ?>
            <div class="field">
                <div class="field-label">Due Date:</div>
                <div class="field-value"><?php echo htmlspecialchars(formatDate($caseData['dueDate'])); ?></div>
            </div>
            <div class="field">
                <div class="field-label">Status:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['status']); ?></div>
            </div>
            <?php if (!empty($caseData['assignedTo'])): ?>
            <div class="field">
                <div class="field-label">Assigned To:</div>
                <div class="field-value"><?php echo htmlspecialchars($caseData['assignedTo']); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php 
        // Clinical Details Section - case-type specific fields
        $clinicalDetails = isset($caseData['clinicalDetails']) ? $caseData['clinicalDetails'] : null;
        if (is_string($clinicalDetails)) {
            $clinicalDetails = json_decode($clinicalDetails, true);
        }
        
        // Define clinical field labels by case type
        $clinicalFieldLabels = [
            // Crown
            'toothNumber' => 'Tooth Number',
            // Bridge
            'abutmentTeeth' => 'Abutment Teeth',
            'ponticTeeth' => 'Pontic Teeth',
            // Implant Crown
            'implantToothNumber' => 'Implant Tooth Number',
            'abutmentType' => 'Abutment Type',
            'implantSystem' => 'Implant System',
            'platformSize' => 'Platform Size',
            'scanBodyUsed' => 'Scan Body Used',
            // Implant Surgical Guide
            'implantSites' => 'Implant Sites',
            // Denture
            'dentureJaw' => 'Jaw',
            'dentureType' => 'Denture Type',
            'gingivalShade' => 'Gingival Shade',
            // Partial
            'partialJaw' => 'Jaw',
            'teethToReplace' => 'Teeth to Replace',
            'partialMaterial' => 'Material',
            'partialGingivalShade' => 'Gingival Shade'
        ];
        
        if (!empty($clinicalDetails) && is_array($clinicalDetails)):
        ?>
        <div class="section">
            <h2>Clinical Details</h2>
            <?php foreach ($clinicalDetails as $key => $value): ?>
                <?php if (!empty($value)): ?>
                <div class="field">
                    <div class="field-label"><?php echo htmlspecialchars($clinicalFieldLabels[$key] ?? ucfirst(preg_replace('/([A-Z])/', ' $1', $key))); ?>:</div>
                    <div class="field-value"><?php echo htmlspecialchars($value); ?></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($caseData['notes'])): ?>
        <div class="section">
            <h2>Notes</h2>
            <div class="field-value" style="white-space: pre-wrap; border: 1px solid #ddd; padding: 10px; background-color: #f9f9f9;"><?php echo htmlspecialchars($caseData['notes']); ?></div>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>Attachments Summary</h2>
            <?php
            if (!empty($attachments) && is_array($attachments)) {
                echo '<div class="field">';
                echo '<div class="field-label">Total Files:</div>';
                echo '<div class="field-value">' . count($attachments) . ' attachments</div>';
                echo '</div>';
                
                foreach ($attachments as $attachment) {
                    echo '<div class="field">';
                    echo '<div class="field-label">File:</div>';
                    echo '<div class="field-value">' . htmlspecialchars(isset($attachment['fileName']) ? $attachment['fileName'] : 'Unknown file') . ' (' . htmlspecialchars(isset($attachment['type']) ? $attachment['type'] : 'Unknown type') . ')</div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="no-files">No attachments found for this case.</div>';
            }
            ?>
        </div>
        
        <div class="section" id="attachments" style="page-break-after: auto; margin-bottom: 100px; clear: both; overflow: visible; min-height: auto; height: auto; display: block; background: #fafafa; padding: 20px; border-radius: 4px; border: 1px solid #eee;">
            <h2 style="page-break-after: avoid; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 20px; margin-top: 0;">Attachment Contents</h2>
            <?php
            if (!empty($attachments) && is_array($attachments)) {
                $attachmentIndex = 0;
                foreach ($attachments as $attachment) {
                    // Each attachment starts on its own page (except the first one which follows the header)
                    $pageBreakStyle = $attachmentIndex > 0 ? 'page-break-before: always;' : '';
                    echo '<div class="file-section" style="' . $pageBreakStyle . '">';
                    echo '<div class="file-header">';
                    echo 'File: ' . htmlspecialchars(isset($attachment['fileName']) ? $attachment['fileName'] : 'Unknown file') . ' (' . htmlspecialchars(isset($attachment['type']) ? $attachment['type'] : 'Unknown type') . ')';
                    echo '</div>';
                    $attachmentIndex++;
                    
                    // Try to get file from local storage first
                    $filePath = null;
                    $fileContent = null;
                    
                    // Check for local path
                    if (isset($attachment['path']) && !empty($attachment['path'])) {
                        $filePath = __DIR__ . '/../' . $attachment['path'];
                    }
                    // Check for nested attachment structure (from createCacheOnlyCase)
                    else if (isset($attachment['name']) && !empty($attachment['name'])) {
                        // Try to construct path from case ID and attachment structure
                        $caseId = $caseData['id'] ?? 'unknown';
                        $fileName = $attachment['name'];
                        $attachmentType = isset($attachment['type']) ? strtolower($attachment['type']) : 'unknown';
                        
                        // Try common upload patterns
                        $possiblePaths = [
                            __DIR__ . '/../uploads/' . $caseId . '/' . $attachmentType . '/' . $fileName,
                            __DIR__ . '/../uploads/' . $caseId . '/' . $fileName,
                            __DIR__ . '/../uploads/' . $fileName
                        ];
                        
                        foreach ($possiblePaths as $path) {
                            if (file_exists($path)) {
                                $filePath = $path;
                                break;
                            }
                        }
                    }
                    
                    if ($filePath && file_exists($filePath)) {
                        try {
                            $fileContent = file_get_contents($filePath);
                            if ($fileContent !== false) {
                                echo '<div class="file-content">';
                                
                                $fileName = basename($filePath);
                                
                                // Check if it's an image
                                if (isImageFile($fileName)) {
                                    if ($gdAvailable) {
                                        echo '<div class="image-content">';
                                        echo '<img src="data:image/jpeg;base64,' . base64_encode($fileContent) . '" alt="' . htmlspecialchars($fileName) . '" />';
                                        echo '</div>';
                                    } else {
                                        // GD not available - show placeholder instead of image
                                        echo '<div class="file-info" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0; background: #f9f9f9;">';
                                        echo '<p><strong>Image File:</strong> ' . htmlspecialchars($fileName) . '</p>';
                                        echo '<p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p>';
                                        echo '<p><em><strong>Note:</strong> Image not displayed - PHP GD extension is not installed</em></p>';
                                        echo '</div>';
                                    }
                                } else if (isTextFile($fileName)) {
                                    // Display as text for text files
                                    echo '<pre>' . htmlspecialchars($fileContent) . '</pre>';
                                } else if (isDocumentFile($fileName)) {
                                    // Convert documents to displayable content
                                    $convertedContent = convertDocumentToImages($fileName, $fileContent);
                                    foreach ($convertedContent as $content) {
                                        echo $content;
                                    }
                                    // GD not available - show document info without conversion
                                    echo '<div class="file-info" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0; background: #f9f9f9;">';
                                    echo '<p><strong>Document File:</strong> ' . htmlspecialchars($fileName) . '</p>';
                                    echo '<p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p>';
                                    echo '<p><em><strong>Note:</strong> Document content not displayed - PHP GD extension is not installed</em></p>';
                                    echo '</div>';
                                } else {
                                    // Unknown file type - show file info
                                    echo '<div class="binary-file-info">';
                                    echo '<p><strong>File Type:</strong> Unknown Binary File</p>';
                                    echo '<p><strong>File Name:</strong> ' . htmlspecialchars($fileName) . '</p>';
                                    echo '<p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p>';
                                    echo '<p><strong>Content:</strong> Cannot be displayed in print format</p>';
                                    echo '<p><em>This file type is not supported for display.</em></p>';
                                    echo '</div>';
                                }
                                
                                echo '</div>';
                            } else {
                                echo '<div class="no-files">Unable to read file content</div>';
                            }
                        } catch (Exception $e) {
                            echo '<div class="no-files">Error loading file: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                    } else {
                        echo '<div class="no-files">File not found in local storage</div>';
                    }
                    
                    echo '</div>';
                }
            } else {
                echo '<div class="no-files">No attachments available.</div>';
            }
            ?>
        </div>
        
        <div class="section" id="timeline" style="page-break-inside: avoid; margin-top: 60px; border-top: 3px solid #333; padding-top: 30px; clear: both; background: white; padding: 30px 20px; border-radius: 4px; border: 1px solid #ddd; overflow: visible; min-height: auto; height: auto; display: block;">
            <h2 style="page-break-after: avoid; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 20px; margin-top: 0;">Timeline</h2>
            <div class="field">
                <div class="field-label">Created:</div>
                <div class="field-value"><?php echo htmlspecialchars(formatDateTime($caseData['creationDate'])); ?></div>
            </div>
            <div class="field">
                <div class="field-label">Last Updated:</div>
                <div class="field-value"><?php echo htmlspecialchars(formatDateTime($caseData['lastUpdateDate'])); ?></div>
            </div>
        </div>
        
        <div class="footer">
            <p>Generated by <?php echo htmlspecialchars($appName); ?> on <?php echo date('F j, Y g:i A'); ?></p>
        </div>
    </body>
    </html>
    <?php
    
    // Get the HTML content
    $htmlContent = ob_get_clean();
    
    return $htmlContent;
}

function isImageFile($filename) {
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $imageExtensions);
}

function isTextFile($filename) {
    $textExtensions = ['txt', 'csv', 'json', 'xml', 'html', 'htm', 'md', 'log', 'ini', 'config'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $textExtensions);
}

function isDocumentFile($filename) {
    $docExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, $docExtensions);
}

function convertDocumentToImages($filename, $fileContent) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    switch($extension) {
        case 'pdf':
            return extractPdfContent($fileContent, $filename);
        
        case 'pptx':
        case 'ppt':
            return extractPowerPointContent($fileContent, $filename);
        
        case 'docx':
        case 'doc':
            return extractWordContent($fileContent, $filename);
        
        case 'xlsx':
        case 'xls':
            return extractExcelContent($fileContent, $filename);
        
        default:
            return ['<div class="document-placeholder"><p><strong>Document:</strong> ' . htmlspecialchars($filename) . '</p><p><em>Document content would be processed here</em></p></div>'];
    }
}

function extractPdfContent($fileContent, $filename) {
    // Check if we can convert PDF to images
    if (!extension_loaded('gd') && !extension_loaded('gd2')) {
        return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: #f9f9f9;"><p><strong>PDF Document:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><strong>Note:</strong> PHP GD extension is not installed. Cannot convert PDF to images for display.</p></div>'];
    }
    
    // Check if Imagick is available and can actually process PDFs
    if (extension_loaded('imagick')) {
        // Test if Imagick can actually convert PDFs (with Ghostscript already set up)
        try {
            $testImagick = new Imagick();
            
            $testImagick->newImage(10, 10, 'white');
            $testImagick->setImageFormat('pdf');
            $testPdf = $testImagick->getImagesBlob();
            
            $testImagick2 = new Imagick();
            $testImagick2->readImageBlob($testPdf);
            $testImagick2->setImageFormat('png');
            $testPng = $testImagick2->getImagesBlob();
            
            return extractPdfAsImages($fileContent, $filename);
            
        } catch (Exception $e) {
            return extractPdfAsText($fileContent, $filename);
        }
    } else {
        return extractPdfAsText($fileContent, $filename);
    }
}

function extractPdfAsImages($fileContent, $filename) {
    try {
        // Create temporary file for PDF processing
        $tempPdfFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        file_put_contents($tempPdfFile, $fileContent);
        
        // Convert PDF to images using Imagick
        $imagick = new Imagick();
        $imagick->setResolution(150, 150); // Good resolution for printing
        
        // Read the PDF with error handling
        try {
            $imagick->readImage($tempPdfFile);
        } catch (ImagickException $e) {
            error_log('Failed to read PDF: ' . $e->getMessage());
            // Try with a different approach
            $imagick->clear();
            $imagick = new Imagick();
            $imagick->setResolution(100, 100); // Lower resolution
            $imagick->readImage($tempPdfFile);
        }
        
        $imageHtml = '';
        $maxPages = 5; // Limit to first 5 pages to prevent huge files
        $pageCount = min($imagick->getNumberImages(), $maxPages);
        
        for ($i = 0; $i < $pageCount; $i++) {
            $imagick->setIteratorIndex($i);
            
            // Get original dimensions first
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            
            // Convert to PNG
            $imagick->setImageFormat('png');
            $imagick->setImageCompressionQuality(85);
            
            // Validate geometry before scaling
            if ($width > 0 && $height > 0) {
                // Calculate scaling to fit max width of 700px
                $maxWidth = 700;
                if ($width > $maxWidth) {
                    $scaleRatio = $maxWidth / $width;
                    $newWidth = $maxWidth;
                    $newHeight = round($height * $scaleRatio);
                    
                    $imagick->scaleImage($newWidth, $newHeight, true);
                }
            } else {
                error_log('Invalid geometry detected for page ' . ($i + 1) . ': ' . $width . 'x' . $height);
                continue; // Skip this page
            }
            
            $imageData = $imagick->getImageBlob();
            
            if (strlen($imageData) > 0) {
                $base64Image = base64_encode($imageData);
                
                $imageHtml .= '<div style="page-break-inside: avoid; margin-bottom: 30px; clear: both;">';
                $imageHtml .= '<h5 style="margin: 0 0 10px 0; font-size: 12px; color: #333; page-break-after: avoid;">Page ' . ($i + 1) . ' of ' . $filename . '</h5>';
                $imageHtml .= '<img src="data:image/png;base64,' . $base64Image . '" alt="Page ' . ($i + 1) . '" style="max-width: 100%; height: auto; border: 1px solid #ddd; display: block;" />';
                $imageHtml .= '</div>';
            } else {
                error_log('Empty image data for page ' . ($i + 1));
            }
        }
        
        // Clean up
        $imagick->clear();
        $imagick->destroy();
        unlink($tempPdfFile);
        
        if (empty($imageHtml)) {
            return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: #f9f9f9;"><p><strong>PDF Document:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><strong>Error:</strong> Unable to convert PDF pages to images. The PDF may contain complex content that cannot be rendered.</p></div>'];
        }
        
        // Add note if pages were truncated
        if ($imagick->getNumberImages() > $maxPages) {
            $imageHtml .= '<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; margin-top: 10px; font-size: 11px; color: #856404;">';
            $imageHtml .= '<strong>Note:</strong> Only first ' . $maxPages . ' pages shown. This PDF has ' . $imagick->getNumberImages() . ' total pages. Please refer to the original file for complete content.</div>';
        }
        
        return ['<div class="document-content" style="margin-bottom: 30px; page-break-inside: avoid; clear: both; background: white; padding: 20px; border: 1px solid #ddd; border-radius: 4px; overflow: visible; min-height: auto; height: auto; display: block;"><h4 style="page-break-after: avoid; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 8px; color: #333; margin-top: 0;">PDF Content: ' . htmlspecialchars($filename) . '</h4>' . $imageHtml . '</div>'];
        
    } catch (Exception $e) {
        unlink($tempFile ?? '');
        return extractPdfAsText($fileContent, $filename);
    }
}

function extractPdfAsText($fileContent, $filename) {
    try {
        $content = '';
        
        // Look for text between BT (Begin Text) and ET (End Text) operators
        if (preg_match_all('/BT\s+(.*?)\s+ET/s', $fileContent, $textMatches)) {
            // Removed error_log statement
            
            foreach ($textMatches[1] as $textBlock) {
                // Extract text from between parentheses in PDF text operators
                if (preg_match_all('/\(([^)]+)\)/', $textBlock, $matches)) {
                    foreach ($matches[1] as $text) {
                        // Clean up the text - remove PDF escape sequences
                        $cleanText = str_replace(['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'], ["\n", "\r", "\t", "(", ")", "\\"], $text);
                        if (!empty(trim($cleanText))) {
                            $content .= $cleanText . ' ';
                        }
                    }
                }
            }
        }
        
        // Also look for text in stream objects that might contain readable text
        if (empty($content)) {
            // Look for text streams that contain actual readable text (not binary)
            preg_match_all('/stream\s+(.*?)\s+endstream/s', $fileContent, $streamMatches);
            
            foreach ($streamMatches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded && is_string($decoded)) {
                    // Look for text between parentheses in the decoded stream
                    if (preg_match_all('/\(([^)]{20,})\)/', $decoded, $matches)) {
                        foreach ($matches[1] as $text) {
                            $cleanText = preg_replace('/[^a-zA-Z0-9\s.,;:!?()-]/', '', $text);
                            if (!empty(trim($cleanText))) {
                                $content .= $cleanText . ' ';
                            }
                        }
                    }
                }
            }
        }
        
        if (!empty(trim($content))) {
            // Limit content length to prevent timeouts
            if (strlen($content) > 20000) { // 20KB limit for text extraction
                $content = substr($content, 0, 20000) . "\n\n... [Content truncated due to size limits] ...";
            }
            
            return ['<div class="document-content"><h4>PDF Content: ' . htmlspecialchars($filename) . '</h4><pre style="font-size: 9px; line-height: 1.3; white-space: pre-wrap; word-wrap: break-word;">' . htmlspecialchars($content) . '</pre><p style="font-size: 10px; color: #666; font-style: italic; margin-top: 10px;"><em>Note: Text extraction may not preserve formatting. For better PDF display, install the PHP Imagick extension.</em></p></div>'];
        } else {
            return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: #f9f9f9;"><p><strong>PDF Document:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><strong>Note:</strong> Unable to extract readable text content from this PDF. The PDF may contain images, scanned content, or complex formatting. Install the PHP Imagick extension for better PDF display.</p></div>'];
        }
    } catch (Exception $e) {
        return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: #f9f9f9;"><p><strong>PDF Document:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><strong>Error:</strong> PDF content extraction failed - ' . htmlspecialchars($e->getMessage()) . '</p></div>'];
    }
}

function extractPowerPointContent($fileContent, $filename) {
    try {
        // Create a temporary file to work with
        $tempFile = tempnam(sys_get_temp_dir(), 'pptx_');
        file_put_contents($tempFile, $fileContent);
        
        // Try to load with PHPPresentation
        $presentation = IOFactory::load($tempFile);
        
        $content = '';
        foreach ($presentation->getAllSlides() as $slide) {
            foreach ($slide->getShapeCollection() as $shape) {
                if (method_exists($shape, 'getText')) {
                    $content .= $shape->getText() . "\n\n";
                }
            }
        }
        
        unlink($tempFile);
        
        if (!empty(trim($content))) {
            return ['<div class="document-content"><h4>PowerPoint Content: ' . htmlspecialchars($filename) . '</h4><pre>' . htmlspecialchars($content) . '</pre></div>'];
        } else {
            return ['<div class="document-placeholder"><p><strong>PowerPoint:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><em><strong>Note:</strong> Unable to extract text content from this PowerPoint file</em></p></div>'];
        }
    } catch (Exception $e) {
        unlink($tempFile ?? '');
        return ['<div class="document-placeholder"><p><strong>PowerPoint:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><em><strong>Note:</strong> PowerPoint content extraction failed - ' . htmlspecialchars($e->getMessage()) . '</em></p></div>'];
    }
}

function extractWordContent($fileContent, $filename) {
    // For now, return a placeholder for Word documents
    return ['<div class="document-placeholder"><p><strong>Word Document:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><em><strong>Note:</strong> Word document content extraction is not yet implemented</em></p></div>'];
}

function extractExcelContent($fileContent, $filename) {
    try {
        // Create a temporary file to work with
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        file_put_contents($tempFile, $fileContent);
        
        $zip = new ZipArchive();
        if ($zip->open($tempFile) === TRUE) {
            $content = '';
            
            // Try to extract from shared strings
            $sharedStrings = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedStrings) {
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $sharedStrings, $matches);
                foreach ($matches[1] as $text) {
                    $cleanText = htmlspecialchars_decode($text);
                    if (!empty(trim($cleanText))) {
                        $content .= $cleanText . "\t";
                    }
                }
            }
            
            // Also try to extract from worksheet files as fallback
            if (empty($content)) {
                for ($i = 1; $i <= 10; $i++) { // Check first 10 worksheets
                    $worksheetXml = $zip->getFromName('xl/worksheets/sheet' . $i . '.xml');
                    if ($worksheetXml) {
                        preg_match_all('/<v[^>]*>(.*?)<\/v>/s', $worksheetXml, $valueMatches);
                        if (!empty($valueMatches[1])) {
                            foreach ($valueMatches[1] as $value) {
                                $cleanValue = trim($value);
                                if (!empty($cleanValue) && is_numeric($cleanValue)) {
                                    $content .= $cleanValue . "\t";
                                }
                            }
                        }
                    }
                }
            }
            
            $zip->close();
        }
        
        unlink($tempFile);
        
        if (!empty(trim($content))) {
            // Limit content length to prevent timeouts
            if (strlen($content) > 30000) { // 30KB limit for Excel
                $content = substr($content, 0, 30000) . "\t... [Content truncated due to size limits] ...";
            }
            
            // Format as table with better responsive sizing
            $rows = explode("\t", trim($content));
            $tableHtml = '<table style="border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 8px; table-layout: fixed;">';
            
            // Better column detection - analyze actual data structure
            $colsPerRow = 10; // Default
            if (count($rows) > 0) {
                // Look for patterns in the data to determine actual columns
                $firstFewRows = array_slice($rows, 0, min(10, count($rows)));
                $totalTabs = 0;
                foreach ($firstFewRows as $row) {
                    $totalTabs += substr_count($row, "\t");
                }
                if (count($firstFewRows) > 0) {
                    $avgTabs = $totalTabs / count($firstFewRows);
                    $colsPerRow = max(3, min(15, round($avgTabs) + 1)); // Between 3-15 columns
                }
            }
            
            // Calculate column width for landscape optimization
            $columnWidth = round(100 / $colsPerRow, 1);
            $isWideTable = $colsPerRow > 8;
            
            $tableHtml .= '<tr style="background: #f0f0f0; font-weight: bold;">';
            for ($col = 0; $col < $colsPerRow; $col++) {
                $tableHtml .= '<td style="border: 1px solid #ddd; padding: 3px; text-align: left; width: ' . $columnWidth . '%; font-size: 7px; word-wrap: break-word;">Col ' . ($col + 1) . '</td>';
            }
            $tableHtml .= '</tr>';
            
            // Add data rows with better text wrapping
            $maxRows = $isWideTable ? 30 : 50; // Fewer rows for wide tables
            $rowCount = 0;
            
            for ($i = 0; $i < count($rows) && $rowCount < $maxRows; $i += $colsPerRow) {
                $tableHtml .= '<tr>';
                for ($col = 0; $col < $colsPerRow && ($i + $col) < count($rows); $col++) {
                    $cellValue = htmlspecialchars(trim($rows[$i + $col]));
                    if (empty($cellValue)) $cellValue = '&nbsp;';
                    
                    // Truncate very long cell content
                    if (strlen($cellValue) > 50) {
                        $cellValue = substr($cellValue, 0, 47) . '...';
                    }
                    
                    $tableHtml .= '<td style="border: 1px solid #ddd; padding: 2px; text-align: left; width: ' . $columnWidth . '%; font-size: 7px; word-wrap: break-word; overflow-wrap: break-word;">' . $cellValue . '</td>';
                }
                $tableHtml .= '</tr>';
                $rowCount++;
            }
            
            if (count($rows) > ($maxRows * $colsPerRow)) {
                $tableHtml .= '<tr><td colspan="' . $colsPerRow . '" style="border: 1px solid #ddd; padding: 6px; text-align: center; font-style: italic; background: #f9f9f9; font-size: 8px;">... additional data not shown (' . (count($rows) - ($maxRows * $colsPerRow)) . ' more cells) ...</td></tr>';
            }
            
            $tableHtml .= '</table>';
            
            // Add note about wide table
            if ($isWideTable) {
                $tableHtml .= '<p style="font-size: 9px; color: #666; font-style: italic; margin-top: 5px;">Note: This table has ' . $colsPerRow . ' columns and may be best viewed in landscape orientation.</p>';
            }
            
            return ['<div class="document-content"><h4>Excel Content: ' . htmlspecialchars($filename) . '</h4>' . $tableHtml . '</div>'];
        } else {
            return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0; background: #f9f9f9;"><p><strong>Excel Spreadsheet:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><em><strong>Note:</strong> Unable to extract Excel content</em></p></div>'];
        }
    } catch (Exception $e) {
        return ['<div class="document-placeholder" style="border: 1px solid #ddd; padding: 10px; margin: 10px 0; background: #f9f9f9;"><p><strong>Excel Spreadsheet:</strong> ' . htmlspecialchars($filename) . '</p><p><strong>File Size:</strong> ' . number_format(strlen($fileContent)) . ' bytes</p><p><em><strong>Note:</strong> Excel content extraction failed - ' . htmlspecialchars($e->getMessage()) . '</em></p></div>'];
    }
}

function formatDate($dateString) {
    if (empty($dateString)) return '';
    
    try {
        $date = new DateTime($dateString);
        return $date->format('m/d/Y');
    } catch (Exception $e) {
        return htmlspecialchars($dateString);
    }
}

function formatDateTime($dateString) {
    if (empty($dateString)) return 'N/A';
    
    try {
        $date = new DateTime($dateString);
        return $date->format('F j, Y g:i A');
    } catch (Exception $e) {
        return htmlspecialchars($dateString);
    }
}
?>
