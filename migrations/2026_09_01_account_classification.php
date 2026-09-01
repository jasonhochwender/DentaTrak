<?php
/**
 * Account classification and legal-acceptance migration
 *
 * Adds the columns required for:
 *  - Durable organization classification (practice and user)
 *  - DentaTrak-supervised laboratory Practice-creation approval
 *  - Material Terms of Service acceptance tracking
 *  - BAA acceptance fields on practices (moved from request-time ALTER)
 *
 * Idempotent, non-destructive, and safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database connection not available']) . PHP_EOL;
    }
    exit;
}

function runAccountClassificationMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];

    $practiceColumns = [
        'legal_name'              => "VARCHAR(255) DEFAULT NULL",
        'display_name'            => "VARCHAR(255) DEFAULT NULL",
        'practice_address'        => "TEXT DEFAULT NULL",
        'baa_accepted'            => "TINYINT(1) NOT NULL DEFAULT 0",
        'baa_accepted_at'         => "TIMESTAMP NULL DEFAULT NULL",
        'baa_version'             => "VARCHAR(50) DEFAULT NULL",
        'baa_accepted_by_user_id' => "INT UNSIGNED DEFAULT NULL",
        'baa_signer_name'         => "VARCHAR(255) DEFAULT NULL",
        'baa_signer_title'        => "VARCHAR(255) DEFAULT NULL",
        'organization_type'       => "VARCHAR(64) DEFAULT NULL",
    ];

    foreach ($practiceColumns as $col => $def) {
        try {
            $quoted = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE {$quoted}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE practices ADD COLUMN `{$col}` {$def}");
                $performed[] = "Added practices.{$col}";
            } else {
                $performed[] = "practices.{$col} already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "practices.{$col}: " . $e->getMessage();
        }
    }

    $userColumns = [
        'organization_type'             => "VARCHAR(64) DEFAULT NULL",
        'organization_type_other'       => "VARCHAR(255) DEFAULT NULL",
        'lab_practice_creation_approved' => "TINYINT(1) NOT NULL DEFAULT 0",
        'terms_accepted_version'        => "VARCHAR(64) DEFAULT NULL",
        'terms_accepted_at'             => "DATETIME NULL DEFAULT NULL",
    ];

    foreach ($userColumns as $col => $def) {
        try {
            $quoted = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE {$quoted}");
            if ($stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN `{$col}` {$def}");
                $performed[] = "Added users.{$col}";
            } else {
                $performed[] = "users.{$col} already exists";
            }
        } catch (PDOException $e) {
            $errors[] = "users.{$col}: " . $e->getMessage();
        }
    }

    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}

$isEntryPoint = PHP_SAPI === 'cli'
    || (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($isEntryPoint) {
    if (PHP_SAPI !== 'cli') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
        $testMode = (getEnvVar('DENTATRAK_TEST_MODE', 'false') === 'true')
            || ($appConfig['test_mode'] ?? false) === true
            || $environment === 'development';
        $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

        if (!$testMode && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: admin or test mode required']);
            exit;
        }
    }

    $result = runAccountClassificationMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
