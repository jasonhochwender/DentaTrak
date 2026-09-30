<?php
/**
 * Logo Upload API Endpoint
 * Handles uploading practice logos
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/user-manager.php';
require_once __DIR__ . '/csrf.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context before any operations
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

try {
    // Check if user is admin for this practice
    $stmt = $pdo->prepare("SELECT role FROM practice_users WHERE practice_id = :practice_id AND user_id = :user_id");
    $stmt->execute([
        'practice_id' => $currentPracticeId,
        'user_id' => $userId
    ]);
    $userRole = $stmt->fetchColumn();
    
    if ($userRole !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only practice administrators can upload logos']);
        exit;
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'No file uploaded';
        if (isset($_FILES['logo']['error'])) {
            switch ($_FILES['logo']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMessage = 'File is too large';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMessage = 'File upload was interrupted';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errorMessage = 'Server configuration error';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errorMessage = 'Server write error';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errorMessage = 'File upload blocked by extension';
                    break;
            }
        }
        
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errorMessage]);
        exit;
    }
    
    $uploadedFile = $_FILES['logo'];
    
    // Validate file type using actual file content (not client-provided MIME type)
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
    
    // Get actual MIME type from file content using finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMimeType = $finfo->file($uploadedFile['tmp_name']);
    
    // For images, also verify with getimagesize (except SVG which isn't a bitmap)
    $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    
    // Validate extension
    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file extension. Only JPG, PNG, GIF, SVG, and WebP files are allowed.']);
        exit;
    }
    
    // Validate actual MIME type
    if (!in_array($actualMimeType, $allowedTypes)) {
        // Special case: SVG files may be detected as text/xml or text/plain
        if ($fileExtension === 'svg' && in_array($actualMimeType, ['text/xml', 'text/plain', 'application/xml', 'text/html'])) {
            // Additional SVG validation - check for SVG tag
            $fileContent = file_get_contents($uploadedFile['tmp_name'], false, null, 0, 1024);
            if (stripos($fileContent, '<svg') === false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid SVG file.']);
                exit;
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file type. The file content does not match an allowed image format.']);
            exit;
        }
    }
    
    // For bitmap images, verify with getimagesize
    if ($fileExtension !== 'svg') {
        $imageInfo = @getimagesize($uploadedFile['tmp_name']);
        if ($imageInfo === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid image file. Could not verify image dimensions.']);
            exit;
        }
    }
    
    // Validate file size (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($uploadedFile['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size is 5MB.']);
        exit;
    }
    
    $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
    $gcsBucketName = trim($appConfig['gcs']['bucket_name'] ?? '');

    if ($gcsBucketName !== '') {
        // Cloud Run instances have an ephemeral filesystem: a logo written to
        // the local uploads/ directory exists only on the instance that
        // received the upload and disappears on redeploy, autoscaling, or
        // instance recycling - which is why stored upload paths 404 after the
        // session that uploaded them. With a bucket configured (production),
        // store the logo in GCS and reference it through api/logo.php.
        require_once __DIR__ . '/gcs-storage.php';

        $objectPath = sprintf('logos/%d/%s.%s', $currentPracticeId, bin2hex(random_bytes(16)), $fileExtension);
        $contentType = ($fileExtension === 'svg') ? 'image/svg+xml' : $actualMimeType;

        try {
            $bucket = getGcsBucket();
            $bucket->upload(fopen($uploadedFile['tmp_name'], 'r'), [
                'name' => $objectPath,
                'metadata' => ['contentType' => $contentType],
            ]);
        } catch (Exception $e) {
            error_log('[upload-logo] GCS upload failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
            exit;
        }

        // logo_path stores the streaming URL so existing img src usage works
        // unchanged; api/logo.php enforces session + practice membership.
        $relativePath = 'api/logo.php?p=' . rawurlencode($objectPath);

        // Log the activity (staged upload)
        logUserActivity($userId, 'upload_logo_staged', 'User uploaded practice logo (staged, awaiting Save Settings)');

        echo json_encode([
            'success' => true,
            'message' => 'Logo uploaded successfully',
            'logoPath' => $relativePath
        ]);
        exit;
    }

    // Local development without a GCS bucket: keep the original on-disk
    // staging under uploads/logos.
    $uploadsDir = __DIR__ . '/../uploads/logos';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    // Generate unique filename
    $fileName = 'practice_' . $currentPracticeId . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadsDir . '/' . $fileName;
    $relativePath = 'uploads/logos/' . $fileName;

    // Move uploaded file (staging only; DB is updated later on Save Settings)
    if (move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
        // Log the activity (staged upload)
        logUserActivity($userId, 'upload_logo_staged', 'User uploaded practice logo (staged, awaiting Save Settings)');

        echo json_encode([
            'success' => true,
            'message' => 'Logo uploaded successfully',
            'logoPath' => $relativePath
        ]);

    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error while uploading logo'
    ]);
    
    userLog("Error uploading logo: " . $e->getMessage(), true);
}
