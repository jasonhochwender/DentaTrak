<?php
/**
 * PHI Access Audit report endpoint (practice administrators only).
 *
 * Returns the practice-scoped phi_access_log with server-side filtering,
 * sorting, and pagination, plus a CSV export of the same filtered set.
 *
 * GET  /api/phi-access-log.php?meta=1
 *   -> { users, actions, resourceTypes } for building the filter controls.
 *
 * GET  /api/phi-access-log.php?<filters>
 *   Filters: preset (7|30|90|all|custom), from, to (Y-m-d, custom only),
 *   user_id, action, resource_type, case_id (contains), sort, dir,
 *   page, page_size.
 *
 * POST /api/phi-access-log.php?action=export   (CSRF required)
 *   Same filters -> CSV download. The export itself is recorded as an
 *   export_audit_report event - one row per export, non-recursive.
 *
 * Security: admin-only, practice-scoped via requireValidPracticeContext().
 * The report intentionally exposes audit metadata only - actor identity,
 * action, resource type/identifier, timestamp - never PHI payloads.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/hipaa-compliance.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';

setApiSecurityHeaders();
header('Content-Type: application/json');

$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// The PHI access audit report is a practice-administration surface:
// owners and practice admins only, never ordinary members or lab
// collaborators, regardless of any client-supplied parameters.
if (!isPracticeAdmin($currentPracticeId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('phiAudit.access_denied')]);
    exit;
}
requireNotLabCollaborator($currentPracticeId, t('phiAudit.access_denied'));

ensureHIPAASchema();

// Whitelists - only these values may reach query construction.
const PHI_AUDIT_RESOURCE_TYPES = ['case', 'attachment', 'comment_image', 'attachment_zip', 'practice_export', 'audit_report'];
const PHI_AUDIT_SORTS = ['accessed_at', 'user_name', 'access_type', 'case_id', 'resource_type'];
const PHI_AUDIT_CSV_LIMIT = 10000;
const PHI_AUDIT_DEFAULT_DAYS = 30;

$isExport = ($_GET['action'] ?? '') === 'export';

if ($isExport) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }
    requireCsrfToken();
    $params = $_POST;
} else {
    $params = $_GET;
}

if (!$isExport && !empty($params['meta'])) {
    echo json_encode(buildPhiAuditMeta($currentPracticeId));
    exit;
}

[$where, $bind] = buildPhiAuditFilters($params, $currentPracticeId);

$sort = in_array($params['sort'] ?? '', PHI_AUDIT_SORTS, true) ? $params['sort'] : 'accessed_at';
$dir = strtolower($params['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
// user_name sorts on the resolved display name expression, not a raw column.
$sortExpr = $sort === 'user_name'
    ? "COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), pal.user_email)"
    : 'pal.' . $sort;

$baseFrom = "
    FROM phi_access_log pal
    LEFT JOIN users u ON u.id = pal.user_id
    {$where}
";

try {
    if ($isExport) {
        $stmt = $pdo->prepare("
            SELECT pal.accessed_at, pal.user_email,
                   COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), pal.user_email) AS user_name,
                   pal.access_type, pal.resource_type, pal.resource_id, pal.case_id, pal.meta_json, pal.ip_address
            {$baseFrom}
            ORDER BY {$sortExpr} {$dir}, pal.id {$dir}
            LIMIT " . PHI_AUDIT_CSV_LIMIT
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Record the export itself as a single audit event (non-recursive -
        // exporting the report does not generate further writes).
        logPHIAccess(PHI_ACTION_AUDIT_REPORT_EXPORT, null, [
            'file_count' => count($rows),
        ], 'audit_report', null);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="phi-access-audit-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        // Excel/Latin-1 friendliness is not needed here; UTF-8 + standard
        // escaping is sufficient and avoids a BOM altering the first header.
        fputcsv($out, ['accessed_at', 'user', 'email', 'action', 'resource_type', 'resource_id', 'case_id', 'metadata', 'ip_address']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['accessed_at'],
                $row['user_name'],
                $row['user_email'],
                $row['access_type'],
                $row['resource_type'],
                // resource_id may be a storage path - safe metadata, never file
                // contents. The basename keeps the CSV readable without
                // exposing the internal object layout.
                $row['resource_id'] !== null ? basename((string)$row['resource_id']) : null,
                $row['case_id'],
                $row['meta_json'],
                $row['ip_address'],
            ]);
        }
        fclose($out);
        exit;
    }

    $page = max(1, (int)($params['page'] ?? 1));
    $pageSize = min(100, max(5, (int)($params['page_size'] ?? 25)));
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->prepare("SELECT COUNT(*) {$baseFrom}");
    $countStmt->execute($bind);
    $total = (int)$countStmt->fetchColumn();

    // If filters shrank the result set below the current page, step back to
    // the last populated page rather than showing an empty tail page.
    $totalPages = max(1, (int)ceil($total / $pageSize));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $pageSize;
    }

    $stmt = $pdo->prepare("
        SELECT pal.id, pal.accessed_at, pal.user_id, pal.user_email,
               COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), pal.user_email) AS user_name,
               pal.access_type, pal.resource_type, pal.resource_id, pal.case_id, pal.meta_json, pal.ip_address
        {$baseFrom}
        ORDER BY {$sortExpr} {$dir}, pal.id {$dir}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($bind as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'entries' => $entries,
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => $totalPages,
        'showingFrom' => $total === 0 ? 0 : $offset + 1,
        'showingTo' => min($offset + $pageSize, $total),
    ]);
} catch (PDOException $e) {
    error_log('[PHI Audit] Query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('phiAudit.load_failed')]);
}
exit;

/**
 * Build the practice-scoped WHERE clause and bound parameters from the
 * request. Only allowlisted fields/values can influence the query.
 *
 * @return array [string $whereSql, array $bind]
 */
function buildPhiAuditFilters(array $params, int $practiceId): array {
    $clauses = ['pal.practice_id = :practice_id'];
    $bind = ['practice_id' => $practiceId];

    // Date range: presets bound to NOW(); custom uses inclusive whole days so
    // "To" includes everything up to end-of-day in the server's timezone
    // (the same timezone NOW() writes accessed_at in).
    $preset = $params['preset'] ?? (string)PHI_AUDIT_DEFAULT_DAYS;
    if ($preset === 'custom') {
        $from = $params['from'] ?? '';
        $to = $params['to'] ?? '';
        $fromOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && strtotime($from) !== false;
        $toOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && strtotime($to) !== false;
        if ($fromOk && $toOk && strtotime($from) <= strtotime($to)) {
            $clauses[] = 'pal.accessed_at >= :date_from';
            $clauses[] = 'pal.accessed_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $bind['date_from'] = $from . ' 00:00:00';
            $bind['date_to'] = $to;
        } else {
            // Invalid custom range: fall back to the default window rather
            // than erroring or returning the unfiltered log.
            $clauses[] = 'pal.accessed_at >= DATE_SUB(NOW(), INTERVAL ' . PHI_AUDIT_DEFAULT_DAYS . ' DAY)';
        }
    } elseif ($preset === 'all') {
        // No date bound.
    } else {
        $days = in_array((int)$preset, [7, 30, 90], true) ? (int)$preset : PHI_AUDIT_DEFAULT_DAYS;
        $clauses[] = 'pal.accessed_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';
    }

    $filterUserId = (int)($params['user_id'] ?? 0);
    if ($filterUserId > 0) {
        $clauses[] = 'pal.user_id = :filter_user_id';
        $bind['filter_user_id'] = $filterUserId;
    }

    $action = $params['action'] ?? '';
    if ($action !== '' && in_array($action, getPHIAccessActions(), true)) {
        $clauses[] = 'pal.access_type = :filter_action';
        $bind['filter_action'] = $action;
    }

    $resourceType = $params['resource_type'] ?? '';
    if ($resourceType !== '' && in_array($resourceType, PHI_AUDIT_RESOURCE_TYPES, true)) {
        $clauses[] = 'pal.resource_type = :filter_resource';
        $bind['filter_resource'] = $resourceType;
    }

    $caseIdFilter = trim((string)($params['case_id'] ?? ''));
    if ($caseIdFilter !== '') {
        // Contains-match on the internal case ID (also matches tracking-style
        // IDs). LIKE wildcards in user input are escaped.
        $escaped = strtr($caseIdFilter, ['%' => '\\%', '_' => '\\_', '\\' => '\\\\']);
        $clauses[] = "pal.case_id LIKE :filter_case_id ESCAPE '\\\\'";
        $bind['filter_case_id'] = '%' . $escaped . '%';
    }

    return ['WHERE ' . implode(' AND ', $clauses), $bind];
}

/**
 * Filter-dropdown metadata: all practice members (so the admin can filter
 * for users with no events yet), the allowlisted actions, and the resource
 * types actually present in this practice's log.
 */
function buildPhiAuditMeta(int $practiceId): array {
    global $pdo;

    $users = [];
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS full_name
            FROM practice_users pu
            JOIN users u ON u.id = pu.user_id
            WHERE pu.practice_id = :practice_id AND u.is_active = 1
            ORDER BY u.email ASC
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[PHI Audit] Meta users query failed: ' . $e->getMessage());
    }

    $resourceTypes = [];
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT resource_type FROM phi_access_log
            WHERE practice_id = :practice_id AND resource_type IS NOT NULL
            ORDER BY resource_type ASC
        ");
        $stmt->execute(['practice_id' => $practiceId]);
        $resourceTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log('[PHI Audit] Meta resource types query failed: ' . $e->getMessage());
    }

    return [
        'success' => true,
        'users' => $users,
        'actions' => getPHIAccessActions(),
        'resourceTypes' => $resourceTypes,
    ];
}
