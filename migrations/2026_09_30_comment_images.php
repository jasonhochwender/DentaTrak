<?php
/**
 * Comment image attachments migration
 *
 * Adds case_comments.attachments_json (JSON array of verified GCS image
 * metadata: fileName/fileType/size/storagePath/storageType) so comments can
 * carry inline images. Creates case_comments when missing so this is safe on
 * installs where the comments endpoint has never run. Idempotent: safe to run
 * multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the comment images migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runCommentImagesMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    // Ensure the table exists (mirrors ensureCaseCommentsTable() in
    // api/case-comments.php so the migration is self-sufficient).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_comments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(64) NOT NULL,
            practice_id INT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            user_name VARCHAR(255) NOT NULL,
            user_email VARCHAR(255) NOT NULL,
            comment_text TEXT NOT NULL,
            mentions_json TEXT DEFAULT NULL,
            attachments_json TEXT DEFAULT NULL,
            is_deleted BOOLEAN DEFAULT FALSE,
            deleted_at DATETIME DEFAULT NULL,
            deleted_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_case_id (case_id),
            INDEX idx_practice_id (practice_id),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            INDEX idx_is_deleted (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $performed[] = "case_comments table present";
    } catch (PDOException $e) {
        $errors[] = "case_comments create: " . $e->getMessage();
    }

    // Add attachments_json if it is not already present.
    try {
        $quotedCol = $pdo->quote('attachments_json');
        $stmt = $pdo->query("SHOW COLUMNS FROM case_comments LIKE {$quotedCol}");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE case_comments ADD COLUMN attachments_json TEXT DEFAULT NULL");
            $performed[] = "Added case_comments.attachments_json";
        } else {
            $performed[] = "case_comments.attachments_json already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "case_comments.attachments_json: " . $e->getMessage();
    }

    return [
        'success' => empty($errors),
        'performed' => $performed,
        'errors' => $errors,
    ];
}

// If this file is invoked directly (CLI or HTTP), execute the migration and
// report. When required by another script, only the function is exposed.
$isMigrationEntryPoint = PHP_SAPI === 'cli'
    || (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($isMigrationEntryPoint) {
    if (PHP_SAPI !== 'cli') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
        $testMode = getEnvVar('DENTATRAK_TEST_MODE', 'false') === 'true'
            || ($appConfig['test_mode'] ?? false) === true
            || $environment === 'development';
        $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

        if (!$testMode && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: admin or test mode required']);
            exit;
        }
    }

    $result = runCommentImagesMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
