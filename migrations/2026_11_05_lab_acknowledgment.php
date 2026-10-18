<?php
/**
 * Lab Acknowledgment migration
 *
 * Adds the optional practice-level lab acknowledgment workflow:
 *   - practices.lab_acknowledgment_enabled (default 0 - existing and new
 *     practices are never opted in automatically)
 *   - cases_cache.lab_ack_status + cases_cache.lab_promised_date
 *     (denormalized current state for list rendering and filters)
 *   - case_lab_acknowledgments table (per-handoff records; history is
 *     preserved on reassignment via status='superseded')
 *
 * This migration is idempotent and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the lab acknowledgment migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runLabAcknowledgmentMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_lab_acknowledgments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_id VARCHAR(64) NOT NULL,
            practice_id INT UNSIGNED NOT NULL,
            lab_key VARCHAR(64) NOT NULL,
            lab_assignee_type ENUM('user','label') NOT NULL,
            lab_user_id BIGINT UNSIGNED DEFAULT NULL,
            lab_label_id BIGINT UNSIGNED DEFAULT NULL,
            lab_display_name VARCHAR(255) NOT NULL,
            status ENUM('awaiting','needs_info','confirmed','superseded') NOT NULL DEFAULT 'awaiting',
            promised_return_date VARCHAR(50) DEFAULT NULL,
            promised_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            promised_at DATETIME DEFAULT NULL,
            info_requested_by BIGINT UNSIGNED DEFAULT NULL,
            info_requested_at DATETIME DEFAULT NULL,
            info_request_comment_id BIGINT UNSIGNED DEFAULT NULL,
            confirmed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
            confirmed_by_name VARCHAR(255) DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            confirmation_source ENUM('lab','staff') DEFAULT NULL,
            confirmation_method ENUM('portal','phone','email') DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            superseded_at DATETIME DEFAULT NULL,
            INDEX idx_case_id (case_id),
            INDEX idx_practice_status (practice_id, status),
            INDEX idx_lab_user (lab_user_id),
            INDEX idx_lab_label (lab_label_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $performed[] = "case_lab_acknowledgments table ensured";
    } catch (PDOException $e) {
        $errors[] = "case_lab_acknowledgments: " . $e->getMessage();
    }

    $practiceCols = ['lab_acknowledgment_enabled' => "ALTER TABLE practices ADD COLUMN lab_acknowledgment_enabled TINYINT(1) NOT NULL DEFAULT 0"];
    foreach ($practiceCols as $col => $alterSql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE " . $pdo->quote($col));
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
                $performed[] = "Added practices.$col";
            } else {
                $performed[] = "practices.$col already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "practices.$col: " . $e->getMessage();
        }
    }

    $caseCols = [
        'lab_ack_status' => "ALTER TABLE cases_cache ADD COLUMN lab_ack_status VARCHAR(20) DEFAULT NULL",
        'lab_promised_date' => "ALTER TABLE cases_cache ADD COLUMN lab_promised_date VARCHAR(50) DEFAULT NULL",
    ];
    foreach ($caseCols as $col => $alterSql) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM cases_cache LIKE " . $pdo->quote($col));
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec($alterSql);
                $performed[] = "Added cases_cache.$col";
            } else {
                $performed[] = "cases_cache.$col already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "cases_cache.$col: " . $e->getMessage();
        }
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

    $result = runLabAcknowledgmentMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
