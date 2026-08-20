<?php
/**
 * Dev Tools: Demo Data lifecycle management
 *
 * - status: summary of demo data for the current practice
 * - delete: remove all demo data for the current practice
 * - reset: delete then signal the caller to generate a new dataset
 */

require_once __DIR__ . '/session.php';
header('Content-Type: application/json');
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/cases-cache.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/csrf.php';

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

const DEMO_PREFIX = 'demo_';
const DEMO_PREFIX_LENGTH = 5;

/**
 * Get a summary of demo data for the current practice.
 */
function getDemoDataStatus(PDO $pdo, int $practiceId): array {
    $demoCaseStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN archived = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN archived = 1 THEN 1 ELSE 0 END) as historical
        FROM cases_cache
        WHERE practice_id = :practice_id
          AND (demo_generation_run_id IS NOT NULL OR LEFT(case_id, " . DEMO_PREFIX_LENGTH . ") = :demo_prefix)
    ");
    $demoCaseStmt->execute(['practice_id' => $practiceId, 'demo_prefix' => DEMO_PREFIX]);
    $demoCounts = $demoCaseStmt->fetch(PDO::FETCH_ASSOC);

    $legacyStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN archived = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN archived = 1 THEN 1 ELSE 0 END) as historical
        FROM cases_cache
        WHERE practice_id = :practice_id
          AND demo_generation_run_id IS NULL
          AND LEFT(case_id, " . DEMO_PREFIX_LENGTH . ") = :demo_prefix
    ");
    $legacyStmt->execute(['practice_id' => $practiceId, 'demo_prefix' => DEMO_PREFIX]);
    $legacyCounts = $legacyStmt->fetch(PDO::FETCH_ASSOC);

    $runStmt = $pdo->prepare("
        SELECT id, dataset_size, active_case_count, historical_case_count, status, created_at
        FROM demo_generation_runs
        WHERE practice_id = :practice_id
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $runStmt->execute(['practice_id' => $practiceId]);
    $runs = $runStmt->fetchAll(PDO::FETCH_ASSOC);

    $lastRun = $runs[0] ?? null;

    return [
        'success' => true,
        'active' => (int)($demoCounts['active'] ?? 0),
        'historical' => (int)($demoCounts['historical'] ?? 0),
        'total' => (int)($demoCounts['total'] ?? 0),
        'legacyActive' => (int)($legacyCounts['active'] ?? 0),
        'legacyHistorical' => (int)($legacyCounts['historical'] ?? 0),
        'legacyTotal' => (int)($legacyCounts['total'] ?? 0),
        'runCount' => count($runs),
        'lastGenerated' => $lastRun ? $lastRun['created_at'] : null,
        'lastDataset' => $lastRun ? $lastRun['dataset_size'] : null,
        'runs' => $runs,
    ];
}

/**
 * Identify demo case IDs for the current practice.
 */
function getDemoCaseIds(PDO $pdo, int $practiceId): array {
    $stmt = $pdo->prepare("
        SELECT case_id
        FROM cases_cache
        WHERE practice_id = :practice_id
          AND (demo_generation_run_id IS NOT NULL OR LEFT(case_id, " . DEMO_PREFIX_LENGTH . ") = :demo_prefix)
    ");
    $stmt->execute(['practice_id' => $practiceId, 'demo_prefix' => DEMO_PREFIX]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Delete demo data for the current practice. Returns a status array.
 */
function deleteDemoData(PDO $pdo, int $practiceId): array {
    $pdo->beginTransaction();

    try {
        // Identify demo cases: run-tracked OR legacy demo_ prefix
        $caseStmt = $pdo->prepare("
            SELECT case_id
            FROM cases_cache
            WHERE practice_id = :practice_id
              AND (demo_generation_run_id IS NOT NULL OR LEFT(case_id, " . DEMO_PREFIX_LENGTH . ") = :demo_prefix)
            FOR UPDATE
        ");
        $caseStmt->execute(['practice_id' => $practiceId, 'demo_prefix' => DEMO_PREFIX]);
        $caseIds = $caseStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($caseIds)) {
            $pdo->rollBack();
            return ['success' => true, 'deleted' => 0, 'message' => 'No demo data found to delete.'];
        }

        $inPlaceholders = implode(',', array_fill(0, count($caseIds), '?'));

        // Delete related records anchored to these demo case IDs
        $tables = [
            'case_activity_log' => 'case_id',
            'case_comments' => 'case_id',
            'case_lab_assignment_periods' => 'case_id',
            'lab_assignment_history' => 'case_id',
        ];

        foreach ($tables as $table => $column) {
            try {
                $pdo->prepare("DELETE FROM {$table} WHERE {$column} IN ({$inPlaceholders})")->execute($caseIds);
            } catch (PDOException $e) {
                error_log("[demo-cleanup] Error deleting from {$table}: " . $e->getMessage());
            }
        }

        // Delete the demo cases themselves
        $deletedStmt = $pdo->prepare("DELETE FROM cases_cache WHERE case_id IN ({$inPlaceholders})");
        $deletedStmt->execute($caseIds);
        $deleted = $deletedStmt->rowCount();

        // Mark the run records as deleted rather than removing them entirely, preserving the audit trail
        $pdo->prepare("
            UPDATE demo_generation_runs
            SET status = 'deleted'
            WHERE practice_id = :practice_id
        ")->execute(['practice_id' => $practiceId]);

        $pdo->commit();

        return [
            'success' => true,
            'deleted' => count($caseIds),
            'message' => "Demo data removed: {$deleted} cases. Real cases were not affected.",
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[demo-cleanup] deleteDemoData error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete demo data: ' . $e->getMessage()];
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $currentPracticeId = requireValidPracticeContext();

    $userEmail = $_SESSION['user_email'] ?? '';
    if (!canAccessDevTools($appConfig, $userEmail)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to manage demo data.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
    }

    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection is not configured.']);
        exit;
    }

    ensureDemoGenerationRunsSchema();
    ensureCasesCacheTable();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $action = $_GET['action'] ?? $input['action'] ?? 'status';
    $confirmed = !empty($input['confirmed']);

    if ($action === 'status') {
        echo json_encode(getDemoDataStatus($pdo, $currentPracticeId));
        exit;
    }

    if ($action === 'delete') {
        if (!$confirmed) {
            $demoCaseIds = getDemoCaseIds($pdo, $currentPracticeId);
            $count = count($demoCaseIds);
            echo json_encode([
                'success' => false,
                'needsConfirmation' => true,
                'count' => $count,
                'message' => "This will permanently remove {$count} generated demo case" . ($count === 1 ? '' : 's') . " and related history from this practice. Real cases will not be affected.",
            ]);
            exit;
        }
        echo json_encode(deleteDemoData($pdo, $currentPracticeId));
        exit;
    }

    if ($action === 'reset') {
        if (!$confirmed) {
            $demoCaseIds = getDemoCaseIds($pdo, $currentPracticeId);
            $count = count($demoCaseIds);
            echo json_encode([
                'success' => false,
                'needsConfirmation' => true,
                'count' => $count,
                'message' => "This will delete {$count} generated demo case" . ($count === 1 ? '' : 's') . " for this practice and generate a new demo dataset. Real cases will not be affected.",
            ]);
            exit;
        }

        $deleteResult = deleteDemoData($pdo, $currentPracticeId);
        if (!$deleteResult['success']) {
            echo json_encode($deleteResult);
            exit;
        }

        echo json_encode([
            'success' => true,
            'deleted' => $deleteResult['deleted'] ?? 0,
            'proceedToGenerate' => true,
            'message' => 'Demo data deleted. Ready to generate new dataset.',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
} catch (Throwable $e) {
    error_log('[manage-demo-data] error=' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error while managing demo data: ' . $e->getMessage(),
    ]);
}
