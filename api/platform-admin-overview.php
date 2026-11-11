<?php
/**
 * Platform Administration - Overview metrics
 *
 * Aggregate, read-only snapshot for the administration landing page.
 * Uses the same definitions as the Practice Usage Report
 * (api/admin-practices.php?action=usage_report) and Practice
 * Administration list:
 *
 * - practices        = practices rows inside the caller's reporting
 *                      scope (non-hidden by default; include_hidden=1
 *                      reports every practice). Hidden-for-admin is a
 *                      per-admin view preference, not a practice status.
 * - active/inactive  = practices.is_active
 * - demo/test        = practices with at least one demo_generation_runs
 *                      row (same marker the usage report uses)
 * - subscriptions    = owner-level `subscriptions` rows grouped by
 *                      status, restricted to owners of in-scope
 *                      practices so shared owner subscriptions are
 *                      counted once and out-of-scope orgs excluded;
 *                      "trials" counts rows whose trial_ends_at is
 *                      still in the future
 * - cases            = cases_cache rows (canonical deduplicated case
 *                      record): archived = 0 / archived = 1
 * - storage          = cached practice_storage_usage rows only - never
 *                      computed here; coverage = measured / total
 *
 * Access: same rule as the administration pages - super user, or the
 * local development environment. Read-only; no case or patient data.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/dev-tools-access.php';
require_once __DIR__ . '/admin-practice-scope.php';
header('Content-Type: application/json');

if (empty($_SESSION['db_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit;
}

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
if (!isSuperUser($appConfig, $userEmail) && !$isDev) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$adminUserId = (int)$_SESSION['db_user_id'];
$includeHidden = paIncludeHiddenParam($_GET['include_hidden'] ?? '');

$out = [
    'success' => true,
    'metrics' => [],
    'scope' => [],
    'generated_at' => date('Y-m-d H:i:s'),
];

try {
    [$scopeFrag, $scopeParams] = paPracticeScopeSql('p.id', $includeHidden, $adminUserId);
    $scopeCounts = paPracticeScopeCounts($pdo, $adminUserId);
    $out['scope'] = [
        'include_hidden' => $includeHidden,
        'visible' => $includeHidden ? $scopeCounts['total'] : $scopeCounts['visible'],
        'hidden' => $scopeCounts['hidden'],
    ];

    // ---- Practices ----
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(is_active = 1) AS active,
               SUM(is_active = 0) AS inactive
        FROM practices p
        WHERE 1 = 1" . $scopeFrag . "
    ");
    $stmt->execute($scopeParams);
    $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['metrics']['practices_total'] = (int)($p['total'] ?? 0);
    $out['metrics']['practices_active'] = (int)($p['active'] ?? 0);
    $out['metrics']['practices_inactive'] = (int)($p['inactive'] ?? 0);

    // Demo/test practices: presence of a demo generation run (guarded).
    $out['metrics']['practices_demo'] = 0;
    try {
        $tbl = $pdo->query("SHOW TABLES LIKE 'demo_generation_runs'");
        if ($tbl && $tbl->fetchColumn() !== false) {
            [$demoFrag, $demoParams] = paPracticeScopeSql('practice_id', $includeHidden, $adminUserId);
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT practice_id) FROM demo_generation_runs
                WHERE 1 = 1" . $demoFrag . "
            ");
            $stmt->execute($demoParams);
            $out['metrics']['practices_demo'] = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) { /* table absent - no demo practices */ }

    // Hidden-for-this-admin count (admin_hidden_practices is per-admin).
    $out['metrics']['practices_hidden_for_admin'] = $scopeCounts['hidden'];

    // ---- Subscriptions (owner-level, authoritative) ----
    // Scoped to owners of in-scope practices: a subscription shared by an
    // organization counts once, and subscriptions whose owner has no
    // in-scope practice are excluded.
    $out['metrics']['subscriptions'] = [];
    $out['metrics']['subscriptions_trials_active'] = 0;
    $out['metrics']['subscriptions_trials_ending_14d'] = 0;
    try {
        $ownerIds = paOwnerUserIdsForScope($pdo, $includeHidden, $adminUserId);
        $ownerScope = '';
        $ownerParams = [];
        if (!$includeHidden || true) {
            if (empty($ownerIds)) {
                $ownerScope = ' AND 1 = 0';
            } else {
                $ownerScope = ' AND owner_user_id IN (' . implode(',', array_fill(0, count($ownerIds), '?')) . ')';
                $ownerParams = $ownerIds;
            }
        }
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM subscriptions WHERE 1 = 1" . $ownerScope . " GROUP BY status");
        $stmt->execute($ownerParams);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $n) {
            $out['metrics']['subscriptions'][(string)$status] = (int)$n;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM subscriptions
            WHERE trial_ends_at IS NOT NULL AND trial_ends_at >= CURDATE()" . $ownerScope . "
        ");
        $stmt->execute($ownerParams);
        $out['metrics']['subscriptions_trials_active'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM subscriptions
            WHERE trial_ends_at IS NOT NULL
              AND trial_ends_at >= CURDATE()
              AND trial_ends_at <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)" . $ownerScope . "
        ");
        $stmt->execute($ownerParams);
        $out['metrics']['subscriptions_trials_ending_14d'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { /* subscriptions table absent in old snapshots */ }

    // ---- Cases (canonical cases_cache record) ----
    [$caseFrag, $caseParams] = paPracticeScopeSql('c.practice_id', $includeHidden, $adminUserId);
    $stmt = $pdo->prepare("
        SELECT SUM(archived = 0) AS active, SUM(archived = 1) AS archived
        FROM cases_cache c
        WHERE 1 = 1" . $caseFrag . "
    ");
    $stmt->execute($caseParams);
    $c = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['metrics']['cases_active'] = (int)($c['active'] ?? 0);
    $out['metrics']['cases_archived'] = (int)($c['archived'] ?? 0);

    // ---- Storage (cached measurements only) ----
    $out['metrics']['storage'] = ['measured' => 0, 'partial' => 0, 'uncalculated' => 0,
        'refreshing' => 0, 'error' => 0, 'idle' => 0, 'total_bytes' => 0, 'practices' => 0];
    try {
        $tbl = $pdo->query("SHOW TABLES LIKE 'practice_storage_usage'");
        if ($tbl && $tbl->fetchColumn() !== false) {
            [$storFrag, $storParams] = paPracticeScopeSql('su.practice_id', $includeHidden, $adminUserId);
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) AS n, COALESCE(SUM(total_bytes), 0) AS bytes
                FROM practice_storage_usage su
                WHERE 1 = 1" . $storFrag . "
                GROUP BY status
            ");
            $stmt->execute($storParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $st = (string)$row['status'];
                if (isset($out['metrics']['storage'][$st])) {
                    $out['metrics']['storage'][$st] = (int)$row['n'];
                } else {
                    $out['metrics']['storage']['uncalculated'] += (int)$row['n'];
                }
                $out['metrics']['storage']['total_bytes'] += (int)$row['bytes'];
                $out['metrics']['storage']['practices'] += (int)$row['n'];
            }
        }
    } catch (Throwable $e) { /* optional table */ }

    // ---- Needs-attention items (actionable, evidence-based only) ----
    $out['metrics']['attention'] = [];
    if ($out['metrics']['subscriptions_trials_ending_14d'] > 0) {
        $out['metrics']['attention'][] = [
            'kind' => 'trials_ending',
            'count' => $out['metrics']['subscriptions_trials_ending_14d'],
            'link' => 'admin-practices.php',
        ];
    }
    if ($out['metrics']['storage']['error'] > 0) {
        $out['metrics']['attention'][] = [
            'kind' => 'storage_error',
            'count' => $out['metrics']['storage']['error'],
            'link' => 'practice-usage-report.php',
        ];
    }

    // Back-compat alerts array (same items, legacy shape).
    $out['metrics']['alerts'] = $out['metrics']['attention'];
} catch (Throwable $e) {
    error_log('[platform-admin-overview] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'overview_failed']);
    exit;
}

echo json_encode($out);
