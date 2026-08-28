<?php
/**
 * Workflow columns migration
 *
 * Adds the practices.workflow_columns column used to store practice-specific
 * workflow column definitions (add/reorder/archive/restore). Idempotent and
 * safe to run multiple times.
 */

require_once __DIR__ . '/../api/appConfig.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    }
    exit;
}

/**
 * Run the workflow columns migration.
 */
function runWorkflowColumnsMigration(PDO $pdo): array
{
    $performed = [];
    $errors = [];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'workflow_columns'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN workflow_columns LONGTEXT DEFAULT NULL COMMENT 'JSON array of workflow column definitions: id, label, position, archived'");
            $performed[] = "Added practices.workflow_columns";
        } else {
            $performed[] = "practices.workflow_columns already exists";
        }
    } catch (PDOException $e) {
        $errors[] = "practices.workflow_columns: " . $e->getMessage();
    }

    return ['success' => empty($errors), 'performed' => $performed, 'errors' => $errors];
}

if (PHP_SAPI === 'cli') {
    $result = runWorkflowColumnsMigration($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}

// Web access: run once and return JSON
$result = runWorkflowColumnsMigration($pdo);
header('Content-Type: application/json');
echo json_encode($result);
