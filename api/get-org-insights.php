<?php
/**
 * Organization Insights API endpoint - aggregated metrics for the
 * consolidated "All practices" scope of a Scale organization.
 *
 * SECURITY MODEL
 * - The session practice anchors the organization; the consolidated scope is
 *   the same live resolution the case view uses (orgCaseViewEntitled +
 *   getOrgCaseViewPractices). Recomputed on every request - revoked access
 *   or a lost Scale entitlement immediately narrows or denies the response.
 * - The can_view_analytics permission is evaluated PER PRACTICE: practices
 *   where the user lacks it are excluded from every aggregate and disclosed
 *   in excludedPractices. Nothing widens reporting access to make the tab
 *   render.
 * - Limited-visibility members aggregate only the cases they could open,
 *   via the per-request authorized_case_ids temp table.
 * - No PHI is returned: practices, counts, rates, and lab identities only.
 *
 * RANGE PARAMS (historical sections only; workload is always "as of now")
 *   ?preset=30d|90d|12m|all       (default 90d)
 *   ?start=YYYY-MM-DD&end=YYYY-MM-DD   custom inclusive range (<= 24 months)
 */

require_once __DIR__ . '/practice-security.php';
require_once __DIR__ . '/practice-display-settings.php';
require_once __DIR__ . '/organization-access.php';
require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/org-insights-metrics.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');
date_default_timezone_set(ORG_INSIGHTS_TZ);

$anchorPracticeId = requireValidPracticeContext();

if (!orgCaseViewEntitled($pdo)) {
    unset($_SESSION['org_case_view']);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'ORG_VIEW_UNAVAILABLE',
        'message' => 'Organization insights are not available.'
    ]);
    exit;
}

$authorizedPractices = getOrgCaseViewPractices($pdo);
if (count($authorizedPractices) < 2) {
    unset($_SESSION['org_case_view']);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'ORG_VIEW_UNAVAILABLE',
        'message' => 'Organization insights require access to more than one practice.'
    ]);
    exit;
}

// Keep only practices where the per-practice analytics permission holds.
$included = [];
$excluded = [];
foreach ($authorizedPractices as $ap) {
    if (!empty($ap['can_view_analytics'])) {
        $included[] = ['id' => (int)$ap['id'], 'name' => (string)($ap['practice_name'] ?? '')];
    } else {
        $excluded[] = ['id' => (int)$ap['id'], 'name' => (string)($ap['practice_name'] ?? ''), 'reason' => 'no_analytics_permission'];
    }
}
if (empty($included)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error_code' => 'NO_ANALYTICS_PERMISSION',
        'message' => 'You do not have permission to view analytics in any authorized practice.'
    ]);
    exit;
}

// ── Reporting range ──────────────────────────────────────────────────────
$tz = new DateTimeZone(ORG_INSIGHTS_TZ);
$now = new DateTimeImmutable('now', $tz);
$today = $now->setTime(0, 0, 0);
$rangeStart = null;
$rangeEnd = $today;
$preset = $_GET['preset'] ?? '';
$customStart = trim((string)($_GET['start'] ?? ''));
$customEnd = trim((string)($_GET['end'] ?? ''));

if ($customStart !== '' || $customEnd !== '') {
    $s = DateTimeImmutable::createFromFormat('!Y-m-d', $customStart, $tz);
    $e = DateTimeImmutable::createFromFormat('!Y-m-d', $customEnd, $tz);
    if (!$s || !$e || $s > $e || $e > $today) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date range']);
        exit;
    }
    if ($s->diff($e)->days > 731) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Date range is limited to 24 months']);
        exit;
    }
    $rangeStart = $s;
    $rangeEnd = $e;
    $preset = 'custom';
} else {
    if (!in_array($preset, ['30d', '90d', '12m', 'all'], true)) {
        $preset = '90d';
    }
    if ($preset === '30d') {
        $rangeStart = $today->modify('-29 days');
    } elseif ($preset === '90d') {
        $rangeStart = $today->modify('-89 days');
    } elseif ($preset === '12m') {
        $rangeStart = $today->modify('-11 months')->modify('first day of this month');
    } // 'all' => null = unbounded below
}

// Past-due thresholds are PRACTICE-wide: each included practice's own
// practices.past_due_days drives its overdue metric (same source the
// consolidated case list and the per-practice board use).
$pastDueDaysByPractice = [];
foreach ($included as $inc) {
    $ipid = (int)$inc['id'];
    try {
        $pastDueDaysByPractice[$ipid] = (int)getPracticeDisplaySettings($pdo, $ipid)['past_due_days'];
    } catch (Throwable $e) {
        $pastDueDaysByPractice[$ipid] = 1;
    }
}
// Response label uses the anchor practice's threshold.
$pastDueDays = $pastDueDaysByPractice[(int)$anchorPracticeId] ?? 1;

$result = computeOrgInsights($pdo, $included, [
    'rangeStart'  => $rangeStart,
    'rangeEnd'    => $rangeEnd,
    'now'         => $now,
    'pastDueDays' => $pastDueDays,
    'pastDueDaysByPractice' => $pastDueDaysByPractice,
    'includeLabs' => isFeatureEnabled('SHOW_LAB_INSIGHTS'),
]);

echo json_encode([
    'success'           => true,
    'scope'             => 'organization',
    'anchorPracticeId'  => $anchorPracticeId,
    'asOf'              => $now->format('Y-m-d H:i'),
    'range'             => [
        'preset'    => $preset,
        'start'     => $rangeStart ? $rangeStart->format('Y-m-d') : null,
        'end'       => $rangeEnd->format('Y-m-d'),
        'unbounded' => $rangeStart === null,
    ],
    'pastDueDays'       => $pastDueDays,
    'includedPractices' => $included,
    'excludedPractices' => $excluded,
    'practices'         => $result['practices'],
    'totals'            => $result['totals'],
    'labs'              => $result['labs'],
    'trend'             => $result['trend'],
    'meta'              => $result['meta'],
]);
