<?php
/**
 * PHI access log resource columns migration
 *
 * Adds phi_access_log.resource_type / resource_id / meta_json so audit events
 * can carry the resource category, a safe internal identifier, and small
 * whitelisted metadata, plus a composite (practice_id, accessed_at) index for
 * the admin audit report's primary query pattern. Existing rows keep NULL
 * resource columns and remain fully valid history. Idempotent: safe to run
 * multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

/**
 * Run the PHI access log resource migration.
 *
 * @param PDO $pdo
 * @return array {success: bool, performed: string[], errors: string[]}
 */
function runPhiAccessLogResourcesMigration(PDO $pdo): array {
    $performed = [];
    $errors = [];

    // Ensure the table exists (mirrors ensureHIPAASchema() in
    // api/hipaa-compliance.php so the migration is self-sufficient).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS phi_access_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            user_email VARCHAR(255),
            practice_id BIGINT UNSIGNED NOT NULL,
            case_id VARCHAR(64),
            access_type VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_practice_id (practice_id),
            INDEX idx_case_id (case_id),
            INDEX idx_access_type (access_type),
            INDEX idx_accessed_at (accessed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $performed[] = "phi_access_log table present";
    } catch (PDOException $e) {
        $errors[] = "phi_access_log create: " . $e->getMessage();
        return ['success' => false, 'performed' => $performed, 'errors' => $errors];
    }

    try {
        $quoted = function (string $col) use ($pdo) {
            $q = $pdo->quote($col);
            $stmt = $pdo->query("SHOW COLUMNS FROM phi_access_log LIKE {$q}");
            return $stmt && $stmt->rowCount() > 0;
        };

        if (!$quoted('resource_type')) {
            $pdo->exec("ALTER TABLE phi_access_log ADD COLUMN resource_type VARCHAR(50) DEFAULT NULL");
            $performed[] = "Added phi_access_log.resource_type";
        } else {
            $performed[] = "phi_access_log.resource_type already exists";
        }

        if (!$quoted('resource_id')) {
            $pdo->exec("ALTER TABLE phi_access_log ADD COLUMN resource_id VARCHAR(500) DEFAULT NULL");
            $performed[] = "Added phi_access_log.resource_id";
        } else {
            $performed[] = "phi_access_log.resource_id already exists";
        }

        if (!$quoted('meta_json')) {
            $pdo->exec("ALTER TABLE phi_access_log ADD COLUMN meta_json TEXT DEFAULT NULL");
            $performed[] = "Added phi_access_log.meta_json";
        } else {
            $performed[] = "phi_access_log.meta_json already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "phi_access_log columns: " . $e->getMessage();
    }

    // Composite index for the report's primary predicate
    // (WHERE practice_id = ? AND accessed_at range, ORDER BY accessed_at).
    try {
        $hasIndex = false;
        foreach ($pdo->query("SHOW INDEX FROM phi_access_log")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            if ($idx['Key_name'] === 'idx_practice_accessed') {
                $hasIndex = true;
                break;
            }
        }
        if (!$hasIndex) {
            $pdo->exec("ALTER TABLE phi_access_log ADD INDEX idx_practice_accessed (practice_id, accessed_at)");
            $performed[] = "Added index idx_practice_accessed (practice_id, accessed_at)";
        } else {
            $performed[] = "idx_practice_accessed already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "phi_access_log index: " . $e->getMessage();
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

    $result = runPhiAccessLogResourcesMigration($pdo);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

return ['success' => true, 'performed' => ['Function definitions loaded'], 'errors' => []];
