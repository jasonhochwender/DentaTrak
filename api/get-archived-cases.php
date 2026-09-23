<?php
/**
 * Get Archived Cases API Endpoint
 * Returns paginated list of archived cases with search and filtering
 *
 * PII columns (patient names, dentist) are AES-256-CBC encrypted with a
 * random IV, so they cannot be searched or sorted in SQL. The endpoint
 * therefore has two paths:
 *
 *   - SQL path: default view (no search, no dentist filter, archived-date
 *     sort). Count + page entirely in SQL so normal archive opening never
 *     reads the whole archive.
 *   - PHP path: search / dentist filter / non-date sorts. Rows pre-filtered
 *     in SQL by every non-PII criterion are decrypted and finished in PHP
 *     (same pattern as list-cases.php and get-dentist-suggestions.php).
 */

require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/case-types.php';
require_once __DIR__ . '/workflow-stages.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Match the local calendar-day semantics used elsewhere (list-cases.php)
date_default_timezone_set('America/New_York');

// Set header to JSON
header('Content-Type: application/json');

// SECURITY: Require valid practice context before accessing any data
$currentPracticeId = requireValidPracticeContext();
$userId = $_SESSION['db_user_id'];

// Sortable columns -> internal key. Only whitelisted values may reach
// query construction; anything else falls back to the default sort.
$ARCHIVE_SORTS = ['patient', 'dentist', 'case_type', 'status', 'created', 'archived'];

/**
 * Resolve a date filter into [fromYmd, toYmd] bounds.
 * $preset is a day count (7/30/90/365); 'custom' uses explicit from/to.
 * Returns ['error'=>true] on invalid input so the caller can 400.
 */
function archiveDateBounds($preset, $from, $to) {
    $preset = trim((string)$preset);
    if ($preset !== '' && $preset !== 'custom') {
        $days = (int)$preset;
        if ($days <= 0 || $days > 3650) {
            return ['error' => true];
        }
        return [
            'from' => date('Y-m-d', strtotime('-' . $days . ' days')),
            'to' => date('Y-m-d'),
        ];
    }
    if ($preset !== 'custom' && $from === '' && $to === '') {
        return ['from' => null, 'to' => null];
    }
    $validate = function ($value) {
        if ($value === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : false;
    };
    $fromV = $validate($from);
    $toV = $validate($to);
    if ($fromV === false || $toV === false || ($fromV === null && $toV === null)) {
        return ['error' => true];
    }
    if ($fromV !== null && $toV !== null && $fromV > $toV) {
        return ['error' => true];
    }
    return ['from' => $fromV, 'to' => $toV];
}

try {
    // Pagination and filter parameters
    $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
    $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 25;
    $pageSize = min(200, max(1, $pageSize));
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $caseType = isset($_GET['caseType']) ? trim($_GET['caseType']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $dentist = isset($_GET['dentist']) ? trim($_GET['dentist']) : '';
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], $ARCHIVE_SORTS, true) ? $_GET['sort'] : 'archived';
    $dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

    // Shared WHERE: practice isolation + limited-visibility scoping + the
    // filters that are safe on non-PII columns.
    $whereConditions = ['cc.archived = 1'];
    $params = [];

    if ($currentPracticeId) {
        $whereConditions[] = 'cc.practice_id = :practice_id';
        $params['practice_id'] = $currentPracticeId;
    }

    // SECURITY: Assigned Only (limited_visibility) users may only see
    // archived cases assigned to them (same rule as active cases).
    if (hasLimitedVisibility($currentPracticeId)) {
        $whereConditions[] = 'LOWER(cc.assigned_to) = :assigned_email';
        $params['assigned_email'] = getCurrentUserEmail() ?? '';
    }

    // Dentist list for the filter dropdown (distinct decrypted names among
    // archived cases). Same decrypt-then-dedupe pattern as
    // get-dentist-suggestions.php.
    if (isset($_GET['meta'])) {
        $metaSql = "SELECT cc.dentist_name FROM cases_cache cc WHERE " . implode(' AND ', $whereConditions)
                 . " AND cc.dentist_name IS NOT NULL AND cc.dentist_name != ''";
        $metaStmt = $pdo->prepare($metaSql);
        $metaStmt->execute($params);
        $dentists = [];
        foreach ($metaStmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            $name = $raw;
            try {
                $name = PIIEncryption::decrypt($raw);
            } catch (Throwable $e) {
                // Legacy unencrypted value - use as-is
            }
            $name = trim((string)$name);
            if ($name !== '') {
                $dentists[strtolower($name)] = $name;
            }
        }
        $dentists = array_values($dentists);
        usort($dentists, 'strcasecmp');
        echo json_encode(['success' => true, 'dentists' => $dentists]);
        exit;
    }

    // Case type filter - slug-aware so stored legacy aliases ('Mixed Case
    // Type' vs 'Mixed') match through the shared slug, consistent with
    // list-cases.php.
    if ($caseType !== '') {
        $slug = normalizeCaseType($caseType);
        $stored = [];
        foreach (getCaseTypeMap() as $storedValue => $storedSlug) {
            if ($storedSlug === $slug) {
                $stored[] = $storedValue;
            }
        }
        $stored[] = $caseType;
        $stored = array_values(array_unique($stored));
        $placeholders = [];
        foreach ($stored as $i => $value) {
            $key = 'case_type_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $whereConditions[] = 'cc.case_type IN (' . implode(',', $placeholders) . ')';
    }

    // Status filter - stored column id, including custom workflow columns.
    if ($status !== '') {
        $whereConditions[] = 'cc.status = :status';
        $params['status'] = $status;
    }

    // Date filters. Both columns are VARCHAR ISO-ish strings, so compare
    // the first 10 chars (YYYY-MM-DD) which is format-agnostic.
    $archivedBounds = archiveDateBounds($_GET['archivedDays'] ?? '', $_GET['archivedFrom'] ?? '', $_GET['archivedTo'] ?? '');
    $createdBounds = archiveDateBounds($_GET['createdDays'] ?? '', $_GET['createdFrom'] ?? '', $_GET['createdTo'] ?? '');
    if (!empty($archivedBounds['error']) || !empty($createdBounds['error'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'code' => 'invalid_date_range']);
        exit;
    }
    if ($archivedBounds['from'] !== null) {
        $whereConditions[] = 'LEFT(cc.archived_date, 10) >= :archived_from';
        $params['archived_from'] = $archivedBounds['from'];
    }
    if ($archivedBounds['to'] !== null) {
        $whereConditions[] = 'LEFT(cc.archived_date, 10) <= :archived_to';
        $params['archived_to'] = $archivedBounds['to'];
    }
    if ($createdBounds['from'] !== null) {
        $whereConditions[] = 'LEFT(cc.creation_date, 10) >= :created_from';
        $params['created_from'] = $createdBounds['from'];
    }
    if ($createdBounds['to'] !== null) {
        $whereConditions[] = 'LEFT(cc.creation_date, 10) <= :created_to';
        $params['created_to'] = $createdBounds['to'];
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    $selectCols = "
        cc.case_id as id,
        cc.patient_first_name,
        cc.patient_last_name,
        cc.dentist_name,
        cc.case_type,
        cc.status,
        cc.creation_date,
        cc.archived_date,
        cc.tracking_number,
        cc.drive_folder_id as driveFolderId
    ";

    // The default view (no search, no dentist filter, archived-date sort)
    // never touches PII columns for matching, so it stays fully in SQL and
    // only decrypts the current page.
    $useSqlPath = ($search === '' && $dentist === '' && $sort === 'archived');

    if ($useSqlPath) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache cc $whereClause");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $sql = "SELECT $selectCols FROM cases_cache cc $whereClause
                ORDER BY (cc.archived_date IS NULL OR cc.archived_date = '') ASC,
                         cc.archived_date $dir, cc.case_id ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params + ['limit' => $pageSize, 'offset' => ($page - 1) * $pageSize]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // PHP path: pre-filter in SQL, decrypt, finish search/dentist/sort
        // and count in PHP. Ordered fetch keeps output deterministic before
        // the PHP sort runs.
        $stmt = $pdo->prepare("SELECT $selectCols FROM cases_cache cc $whereClause ORDER BY cc.archived_date DESC, cc.case_id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalCount = null; // computed after PHP filtering
    }

    // Decrypt PII fields for display/matching. decrypt() throws on
    // plaintext, so catch and keep the raw value for legacy rows.
    $decryptField = function ($value) {
        if ($value === null || $value === '') return '';
        try {
            return PIIEncryption::decrypt($value);
        } catch (Throwable $e) {
            return $value;
        }
    };

    $cases = array_map(function ($row) use ($decryptField) {
        return [
            'id' => $row['id'],
            'patient_first_name' => $decryptField($row['patient_first_name']),
            'patient_last_name' => $decryptField($row['patient_last_name']),
            'dentist_name' => $decryptField($row['dentist_name']),
            'case_type' => $row['case_type'],
            'status' => $row['status'],
            'creation_date' => $row['creation_date'],
            'archived_date' => $row['archived_date'],
            'tracking_number' => $row['tracking_number'],
            'driveFolderId' => $row['driveFolderId']
        ];
    }, $rows);

    if (!$useSqlPath) {
        // One search box matches patient (either name order, partial),
        // dentist, case type (stored value, slug, or display label) and
        // tracking number - all case-insensitive contains.
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $cases = array_values(array_filter($cases, function ($case) use ($needle) {
                $first = mb_strtolower($case['patient_first_name']);
                $last = mb_strtolower($case['patient_last_name']);
                if (strpos($first, $needle) !== false || strpos($last, $needle) !== false) return true;
                if (strpos(trim($first . ' ' . $last), $needle) !== false) return true;
                if (strpos(trim($last . ' ' . $first), $needle) !== false) return true;
                if (strpos(mb_strtolower($case['dentist_name']), $needle) !== false) return true;
                $caseType = (string)$case['case_type'];
                if (strpos(mb_strtolower($caseType), $needle) !== false) return true;
                if (strpos(normalizeCaseType($caseType), str_replace(' ', '_', $needle)) !== false) return true;
                if (strpos(mb_strtolower(getCaseTypeDisplayLabel($caseType)), $needle) !== false) return true;
                if (strpos(mb_strtolower((string)$case['tracking_number']), $needle) !== false) return true;
                return false;
            }));
        }

        if ($dentist !== '') {
            $cases = array_values(array_filter($cases, function ($case) use ($dentist) {
                return mb_strtolower($case['dentist_name']) === mb_strtolower($dentist);
            }));
        }

        $totalCount = count($cases);

        // Sort the full matching set before pagination. Empty values always
        // sort last regardless of direction.
        $stageLabels = getResolvedWorkflowStageLabelsForPractice($currentPracticeId);
        foreach (getWorkflowColumnsForPractice($currentPracticeId) as $column) {
            if (!empty($column['archived']) && !isset($stageLabels[$column['id']])) {
                $stageLabels[$column['id']] = $column['label'];
            }
        }
        $sortKey = function ($case) use ($sort, $stageLabels) {
            switch ($sort) {
                case 'patient':
                    return ['s', mb_strtolower(trim($case['patient_first_name'] . ' ' . $case['patient_last_name']))];
                case 'dentist':
                    return ['s', mb_strtolower($case['dentist_name'])];
                case 'case_type':
                    return ['s', mb_strtolower(getCaseTypeDisplayLabel((string)$case['case_type']))];
                case 'status':
                    $status = (string)$case['status'];
                    return ['s', mb_strtolower($stageLabels[$status] ?? $status)];
                case 'created':
                    return ['d', strtotime((string)$case['creation_date']) ?: null];
                default:
                    return ['d', strtotime((string)$case['archived_date']) ?: null];
            }
        };
        $direction = $dir === 'ASC' ? 1 : -1;
        usort($cases, function ($a, $b) use ($sortKey, $direction) {
            $ka = $sortKey($a);
            $kb = $sortKey($b);
            $emptyA = ($ka[1] === null || $ka[1] === '');
            $emptyB = ($kb[1] === null || $kb[1] === '');
            if ($emptyA !== $emptyB) return $emptyA ? 1 : -1;
            if ($emptyA && $emptyB) return strcmp((string)$a['id'], (string)$b['id']);
            $cmp = $ka[0] === 'd' ? ($ka[1] <=> $kb[1]) : strcmp($ka[1], $kb[1]);
            if ($cmp === 0) return strcmp((string)$a['id'], (string)$b['id']);
            return $cmp * $direction;
        });

        $cases = array_slice($cases, ($page - 1) * $pageSize, $pageSize);
    }

    // Unfiltered practice archive total for the badge/count context.
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM cases_cache cc WHERE cc.archived = 1 AND cc.practice_id = :practice_id");
    $totalStmt->execute(['practice_id' => $currentPracticeId]);
    $totalArchived = (int)$totalStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'cases' => $cases,
        'totalCount' => $totalCount,
        'totalArchived' => $totalArchived,
        'page' => $page,
        'pageSize' => $pageSize
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve archived cases'
    ]);

    error_log('Error getting archived cases: ' . $e->getMessage());
}
?>
