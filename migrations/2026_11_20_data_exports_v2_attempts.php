<?php
/**
 * Data export v2 hardening migration
 *
 * Adds `attempts` to data_exports: incremented on each worker claim so
 * transient failures can be retried a bounded number of times and stale
 * claims can be recovered by the sweeper. Idempotent; no existing column
 * is altered or dropped.
 */

require_once __DIR__ . '/../api/appConfig.php';

function runDataExportsV2AttemptsMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM data_exports LIKE 'attempts'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE data_exports ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0");
            $performed[] = 'added column attempts';
        }
    } catch (PDOException $e) {
        $errors[] = 'attempts: ' . $e->getMessage();
    }
    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}

// CLI entry point: php migrations/2026_11_20_data_exports_v2_attempts.php
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $result = runDataExportsV2AttemptsMigration($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}
