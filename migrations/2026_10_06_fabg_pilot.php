<?php
/**
 * FABG pilot migration
 *
 * Adds practices.fabg_pilot (durable FABG pilot-program designation, set
 * when a practice is created via ?source=fabg signup or manually by an
 * administrator) and practices.fabg_welcome_shown (per-practice dismissal
 * of the one-time FABG pilot welcome message).
 *
 * The flag is marketing/pilot attribution only: it does not affect trial
 * duration, billing, or authorization.
 *
 * Idempotent: safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the FABG pilot migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runFabgPilotMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    $columns = [
        'fabg_pilot' => "ALTER TABLE practices ADD COLUMN fabg_pilot TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = practice enrolled in the FABG pilot program (attribution only; no billing or trial effect)'",
        'fabg_welcome_shown' => "ALTER TABLE practices ADD COLUMN fabg_welcome_shown TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = FABG pilot welcome message already shown/dismissed for this practice'",
    ];

    foreach ($columns as $column => $alterSql) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM practices LIKE '{$column}'");
            if ($check && $check->rowCount() > 0) {
                $performed[] = "practices.{$column} already present";
                continue;
            }
            $pdo->exec($alterSql);
            $performed[] = "practices.{$column} added";
        } catch (PDOException $e) {
            $errors[] = "practices.{$column}: " . $e->getMessage();
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

    $result = runFabgPilotMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
