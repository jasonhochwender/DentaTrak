<?php
/**
 * Data export v2 migration
 *
 * Extends data_exports for the async export pipeline:
 *   - export_type / format distinguish legacy JSON, case-data CSV/JSON,
 *     and (later) practice archives
 *   - gcs_bucket/gcs_object/sha256 record where the finished artifact
 *     lives and its integrity hash
 *   - progress + sections_done power the status UI
 *   - url_issued_at records signed-URL issuance (distinct from any
 *     evidence that the artifact was actually downloaded)
 *   - claimed_at/claimed_by support atomic worker claims
 *
 * Idempotent and safe to run multiple times. Does NOT alter or drop any
 * existing column - the legacy synchronous export keeps working.
 */

require_once __DIR__ . '/../api/appConfig.php';

function runDataExportsV2Migration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    $ensureTable = function () use ($pdo, &$performed, &$errors) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS data_exports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                practice_id INT UNSIGNED NOT NULL,
                status ENUM('pending','processing','completed','failed','expired','queued','cancelled') NOT NULL DEFAULT 'pending',
                download_token VARCHAR(64) NULL UNIQUE,
                file_path VARCHAR(500) NULL,
                file_size BIGINT UNSIGNED NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                error_message TEXT NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_practice_id (practice_id),
                INDEX idx_status (status),
                INDEX idx_download_token (download_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $performed[] = 'data_exports table ensured';
        } catch (PDOException $e) {
            $errors[] = 'data_exports: ' . $e->getMessage();
        }
    };
    $ensureTable();

    $addColumn = function (string $name, string $ddl) use ($pdo, &$performed, &$errors) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM data_exports LIKE " . $pdo->quote($name))->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE data_exports ADD COLUMN {$ddl}");
                $performed[] = "added column {$name}";
            }
        } catch (PDOException $e) {
            $errors[] = "column {$name}: " . $e->getMessage();
        }
    };

    // New status values must be added to the ENUM before rows can use them.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM data_exports LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($col['Type'] ?? '');
        if ($type !== '' && strpos($type, "'queued'") === false) {
            $pdo->exec("ALTER TABLE data_exports MODIFY COLUMN status ENUM('pending','processing','completed','failed','expired','queued','cancelled') NOT NULL DEFAULT 'pending'");
            $performed[] = 'status enum extended';
        }
    } catch (PDOException $e) {
        $errors[] = 'status enum: ' . $e->getMessage();
    }

    $addColumn('export_type', "export_type VARCHAR(32) NOT NULL DEFAULT 'legacy_json' AFTER practice_id");
    $addColumn('format', "format VARCHAR(16) NULL AFTER export_type");
    $addColumn('gcs_bucket', "gcs_bucket VARCHAR(255) NULL AFTER file_size");
    $addColumn('gcs_object', "gcs_object VARCHAR(1024) NULL AFTER gcs_bucket");
    $addColumn('sha256', "sha256 CHAR(64) NULL AFTER gcs_object");
    $addColumn('manifest_json', "manifest_json MEDIUMTEXT NULL AFTER sha256");
    $addColumn('progress', "progress TINYINT UNSIGNED NULL AFTER manifest_json");
    $addColumn('sections_done', "sections_done JSON NULL AFTER progress");
    $addColumn('url_issued_at', "url_issued_at DATETIME NULL AFTER completed_at");
    $addColumn('url_issued_count', "url_issued_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER url_issued_at");
    $addColumn('claimed_at', "claimed_at DATETIME NULL AFTER error_message");
    $addColumn('claimed_by', "claimed_by VARCHAR(64) NULL AFTER claimed_at");

    try {
        $pdo->exec("CREATE INDEX idx_data_exports_claimable ON data_exports (status, created_at)");
        $performed[] = 'claim index ensured';
    } catch (PDOException $e) {
        // Index may already exist - non-fatal.
        if (strpos($e->getMessage(), 'Duplicate key name') === false) {
            $errors[] = 'claim index: ' . $e->getMessage();
        }
    }

    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}

// CLI entry point: php migrations/2026_11_13_data_exports_v2.php
if (php_sapi_name() === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $result = runDataExportsV2Migration($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}
