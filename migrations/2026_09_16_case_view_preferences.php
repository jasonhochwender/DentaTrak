<?php

function runCaseViewPreferencesMigration(PDO $pdo): array {
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'case_view_preferences'")->fetch();
        if (!$exists) $pdo->exec('ALTER TABLE practice_users ADD COLUMN case_view_preferences JSON DEFAULT NULL');
        return ['success' => true, 'performed' => [$exists ? 'practice_users.case_view_preferences already exists' : 'Added practice_users.case_view_preferences'], 'errors' => []];
    } catch (Throwable $e) {
        return ['success' => false, 'performed' => [], 'errors' => [$e->getMessage()]];
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    require_once __DIR__ . '/../api/appConfig.php';
    $result = runCaseViewPreferencesMigration($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['success'] ? 0 : 1);
}
