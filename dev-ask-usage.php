<?php
/**
 * Dev Tools > Ask DentaTrak Usage
 *
 * Internal-only product-usage dashboard for Ask DentaTrak telemetry.
 * Shows sanitized metadata only - never question text, model answers,
 * patient or case content (none of those are stored by design).
 *
 * Access: same model as admin-practices.php - super users, or the local
 * development environment. Server-side gated; normal users are redirected.
 */

require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/csrf.php';
require_once __DIR__ . '/api/security-headers.php';
setSecurityHeaders();

if (empty($_SESSION['db_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/api/dev-tools-access.php';
require_once __DIR__ . '/api/feature-flags.php';
require_once __DIR__ . '/api/ask-dentatrak-telemetry.php';

$userEmail = $_SESSION['user_email'] ?? '';
$isDev = ($appConfig['current_environment'] ?? '') === 'development';
$canAccess = isSuperUser($appConfig, $userEmail) || $isDev;

if (!$canAccess) {
    header('Location: main.php');
    exit;
}

function h($v) { return htmlspecialchars((string)$v); }
function u($k, $f = '') { return t('ask_usage.' . $k) ?: $f; }

/* ---- Date range ---- */
$range = $_GET['range'] ?? '30';
$customFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$customTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : null;
if ($range === 'custom' && (!$customFrom || !$customTo)) {
    $range = '30';
}
$rangeDays = ['7' => 7, '30' => 30, '90' => 90][$range] ?? null;

$where = '1=1';
$bind = [];
if ($rangeDays !== null) {
    $where = 'u.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
    $bind[':days'] = $rangeDays;
} elseif ($range === 'custom') {
    $where = 'u.created_at >= :from AND u.created_at < DATE_ADD(:to, INTERVAL 1 DAY)';
    $bind[':from'] = $customFrom;
    $bind[':to'] = $customTo;
}

$tableMissing = false;
$queryFailed = false;
$summary = ['total' => 0, 'users' => 0, 'practices' => 0, 'answered' => 0,
            'clarify' => 0, 'errors' => 0, 'redirects' => 0, 'avg_latency' => null,
            'fb_up' => 0, 'fb_down' => 0];
$top = ['category' => [], 'topic' => [], 'tool' => [], 'not_supported' => [],
        'clarify' => [], 'locale' => []];
$events = [];
$totalEvents = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

try {
    $baseSql = "FROM ask_dentatrak_usage u WHERE {$where}";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) total, COUNT(DISTINCT user_id) users, COUNT(DISTINCT practice_id) practices,
               SUM(outcome='answered') answered, SUM(outcome='clarification_requested') clarify,
               SUM(outcome IN ('tool_error','model_error','authorization_denied')) errors,
               SUM(outcome='insights_redirect') redirects, AVG(latency_ms) avg_latency,
               SUM(feedback=1) fb_up, SUM(feedback=-1) fb_down
        {$baseSql}");
    $stmt->execute($bind);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        foreach (['total','users','practices','answered','clarify','errors','redirects','fb_up','fb_down'] as $k) {
            $summary[$k] = (int)($row[$k] ?? 0);
        }
        $summary['avg_latency'] = $row['avg_latency'] !== null ? round((float)$row['avg_latency']) : null;
    }

    $topQueries = [
        'category' => "SELECT category label, COUNT(*) n {$baseSql} GROUP BY category ORDER BY n DESC LIMIT 15",
        'topic'    => "SELECT normalized_topic label, COUNT(*) n {$baseSql} AND normalized_topic IS NOT NULL GROUP BY normalized_topic ORDER BY n DESC LIMIT 15",
        'tool'     => "SELECT tool_used label, COUNT(*) n {$baseSql} AND tool_used IS NOT NULL GROUP BY tool_used ORDER BY n DESC LIMIT 15",
        'not_supported' => "SELECT normalized_topic label, COUNT(*) n {$baseSql} AND outcome='not_supported' AND normalized_topic IS NOT NULL GROUP BY normalized_topic ORDER BY n DESC LIMIT 15",
        'clarify'  => "SELECT normalized_topic label, COUNT(*) n {$baseSql} AND outcome='clarification_requested' AND normalized_topic IS NOT NULL GROUP BY normalized_topic ORDER BY n DESC LIMIT 15",
        'locale'   => "SELECT locale label, COUNT(*) n {$baseSql} AND locale IS NOT NULL GROUP BY locale ORDER BY n DESC LIMIT 15",
    ];
    foreach ($topQueries as $key => $sql) {
        $s = $pdo->prepare($sql);
        $s->execute($bind);
        $top[$key] = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    $totalEvents = $summary['total'];
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("
        SELECT u.created_at, u.user_id, u.practice_id, u.locale, u.category, u.intent, u.normalized_topic,
               u.tool_used, u.outcome, u.latency_ms, u.feedback,
               usr.email user_email, usr.first_name, usr.last_name,
               p.practice_name
        FROM ask_dentatrak_usage u
        LEFT JOIN users usr ON usr.id = u.user_id
        LEFT JOIN practices p ON p.id = u.practice_id
        WHERE {$where}
        ORDER BY u.id DESC
        LIMIT :lim OFFSET :off");
    foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($e instanceof PDOException && $e->getCode() === '42S02') {
        $tableMissing = true;
    } else {
        $queryFailed = true;
    }
    error_log('[dev-ask-usage] query failed: ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($totalEvents / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$pageQs = function ($p) use ($range, $customFrom, $customTo) {
    $q = ['range' => $range, 'page' => $p];
    if ($range === 'custom') { $q['from'] = $customFrom; $q['to'] = $customTo; }
    return '?' . http_build_query($q);
};

$successRate = $summary['total'] > 0 ? round(100 * $summary['answered'] / $summary['total'], 1) : 0;
?>
<!DOCTYPE html>
<html lang="<?php echo getHtmlLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo h(u('title', 'Ask DentaTrak Usage')); ?> - <?php echo h($appConfig['appName']); ?></title>
    <link rel="stylesheet" href="css/app.css">
    <style>
        body { font-family: 'Poppins','Inter',sans-serif; background: #f3f4f6; margin: 0; padding: 20px; }
        .admin-container { max-width: 1400px; margin: 0 auto; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .admin-header h1 { font-size: 1.5rem; margin: 0; }
        .back-link { color: #6366f1; text-decoration: none; font-size: 0.9rem; }
        .filter-bar { background: #fff; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-bar select, .filter-bar input { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.85rem; }
        .filter-bar button { padding: 7px 14px; background: #6366f1; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .metric-card { background: #fff; border-radius: 8px; padding: 14px; }
        .metric-card .m-val { font-size: 1.4rem; font-weight: 600; }
        .metric-card .m-label { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }
        .panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .panel { background: #fff; border-radius: 8px; padding: 16px; }
        .panel h3 { margin: 0 0 10px; font-size: 0.95rem; }
        .panel table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        .panel td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
        .panel td:last-child { text-align: right; font-variant-numeric: tabular-nums; color: #374151; }
        .events-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; background: #fff; border-radius: 8px; overflow: hidden; }
        .events-table th { text-align: left; padding: 10px 12px; background: #f9fafb; font-weight: 600; color: #374151; white-space: nowrap; }
        .events-table td { padding: 8px 12px; border-top: 1px solid #f3f4f6; white-space: nowrap; }
        .outcome-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 0.75rem; background: #eef2ff; color: #4338ca; }
        .outcome-pill.err { background: #fef2f2; color: #b91c1c; }
        .outcome-pill.warn { background: #fffbeb; color: #b45309; }
        .pagination { display: flex; gap: 12px; align-items: center; margin-top: 14px; font-size: 0.85rem; }
        .pagination a { color: #6366f1; text-decoration: none; }
        .empty-note { background: #fff; border-radius: 8px; padding: 24px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="admin-header">
        <h1><?php echo h(u('title', 'Ask DentaTrak Usage')); ?></h1>
        <a class="back-link" href="main.php">&larr; <?php echo h(u('back', 'Back to app')); ?></a>
    </div>

    <form class="filter-bar" method="get" action="dev-ask-usage.php">
        <label for="range"><?php echo h(u('range', 'Range')); ?></label>
        <select name="range" id="range">
            <option value="7" <?php echo $range==='7'?'selected':''; ?>><?php echo h(u('range_7', 'Last 7 days')); ?></option>
            <option value="30" <?php echo $range==='30'?'selected':''; ?>><?php echo h(u('range_30', 'Last 30 days')); ?></option>
            <option value="90" <?php echo $range==='90'?'selected':''; ?>><?php echo h(u('range_90', 'Last 90 days')); ?></option>
            <option value="all" <?php echo $range==='all'?'selected':''; ?>><?php echo h(u('range_all', 'All time')); ?></option>
            <option value="custom" <?php echo $range==='custom'?'selected':''; ?>><?php echo h(u('range_custom', 'Custom')); ?></option>
        </select>
        <input type="date" name="from" value="<?php echo h($customFrom ?? ''); ?>" aria-label="<?php echo h(u('from', 'From')); ?>">
        <input type="date" name="to" value="<?php echo h($customTo ?? ''); ?>" aria-label="<?php echo h(u('to', 'To')); ?>">
        <button type="submit"><?php echo h(u('apply', 'Apply')); ?></button>
    </form>

    <?php if ($tableMissing): ?>
        <div class="empty-note"><?php echo h(u('no_table', 'Telemetry table not present - run the migration first.')); ?></div>
    <?php elseif ($queryFailed): ?>
        <div class="empty-note"><?php echo h(u('query_failed', 'Usage data could not be loaded. See the application error log.')); ?></div>
    <?php elseif ($summary['total'] === 0): ?>
        <div class="empty-note"><?php echo h(u('no_data', 'No usage events in this range.')); ?></div>
    <?php else: ?>

    <div class="metric-grid">
        <div class="metric-card"><div class="m-val"><?php echo $summary['total']; ?></div><div class="m-label"><?php echo h(u('m_questions', 'Total questions')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['users']; ?></div><div class="m-label"><?php echo h(u('m_users', 'Unique users')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['practices']; ?></div><div class="m-label"><?php echo h(u('m_practices', 'Unique practices')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $successRate; ?>%</div><div class="m-label"><?php echo h(u('m_answered', 'Answered rate')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['clarify']; ?></div><div class="m-label"><?php echo h(u('m_clarify', 'Clarifications')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['errors']; ?></div><div class="m-label"><?php echo h(u('m_errors', 'Errors')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['redirects']; ?></div><div class="m-label"><?php echo h(u('m_redirects', 'Insights redirects')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['avg_latency'] !== null ? $summary['avg_latency'].'ms' : '–'; ?></div><div class="m-label"><?php echo h(u('m_latency', 'Avg latency')); ?></div></div>
        <div class="metric-card"><div class="m-val"><?php echo $summary['fb_up']; ?> / <?php echo $summary['fb_down']; ?></div><div class="m-label"><?php echo h(u('m_feedback', 'Feedback (+ / -)')); ?></div></div>
    </div>

    <div class="panels">
        <?php
        $panelDefs = [
            'category' => u('top_categories', 'Top categories'),
            'topic' => u('top_topics', 'Top topics'),
            'tool' => u('top_tools', 'Data tools used'),
            'not_supported' => u('top_not_supported', 'Not-supported topics'),
            'clarify' => u('top_clarify', 'Clarification topics'),
            'locale' => u('top_locales', 'Usage by language'),
        ];
        foreach ($panelDefs as $key => $title):
            if (!$top[$key]) continue; ?>
        <div class="panel">
            <h3><?php echo h($title); ?></h3>
            <table>
                <?php foreach ($top[$key] as $r): ?>
                <tr><td><?php echo h($r['label'] ?? '—'); ?></td><td><?php echo (int)$r['n']; ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <table class="events-table">
        <thead>
            <tr>
                <th><?php echo h(u('c_time', 'Time')); ?></th>
                <th><?php echo h(u('c_practice', 'Practice')); ?></th>
                <th><?php echo h(u('c_user', 'User')); ?></th>
                <th><?php echo h(u('c_locale', 'Locale')); ?></th>
                <th><?php echo h(u('c_category', 'Category')); ?></th>
                <th><?php echo h(u('c_topic', 'Topic')); ?></th>
                <th><?php echo h(u('c_tool', 'Tool')); ?></th>
                <th><?php echo h(u('c_outcome', 'Outcome')); ?></th>
                <th><?php echo h(u('c_latency', 'Latency')); ?></th>
                <th><?php echo h(u('c_feedback', 'FB')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev):
                $errOutcomes = ['tool_error','model_error','authorization_denied'];
                $warnOutcomes = ['clarification_requested','not_supported','insights_redirect','refused_private'];
                $pill = in_array($ev['outcome'], $errOutcomes, true) ? 'err'
                      : (in_array($ev['outcome'], $warnOutcomes, true) ? 'warn' : '');
                $userLabel = trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? ''));
                if ($userLabel === '') { $userLabel = $ev['user_email'] ?? '—'; }
            ?>
            <tr>
                <td><?php echo h($ev['created_at']); ?></td>
                <td><?php echo h($ev['practice_name'] ?? '#' . $ev['practice_id'] ?? ''); ?></td>
                <td><?php echo h($userLabel); ?></td>
                <td><?php echo h($ev['locale'] ?? ''); ?></td>
                <td><?php echo h($ev['category']); ?></td>
                <td><?php echo h($ev['normalized_topic'] ?? '—'); ?></td>
                <td><?php echo h($ev['tool_used'] ?? '—'); ?></td>
                <td><span class="outcome-pill <?php echo $pill; ?>"><?php echo h($ev['outcome']); ?></span></td>
                <td><?php echo $ev['latency_ms'] !== null ? (int)$ev['latency_ms'].'ms' : '—'; ?></td>
                <td><?php echo $ev['feedback'] === null ? '—' : ($ev['feedback'] > 0 ? '👍' : '👎'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php if ($page > 1): ?><a href="<?php echo h($pageQs($page - 1)); ?>">&larr; <?php echo h(u('prev', 'Previous')); ?></a><?php endif; ?>
        <span><?php echo h(u('page_of', 'Page')) . ' ' . $page . ' / ' . $totalPages; ?></span>
        <?php if ($page < $totalPages): ?><a href="<?php echo h($pageQs($page + 1)); ?>"><?php echo h(u('next', 'Next')); ?> &rarr;</a><?php endif; ?>
    </div>

    <?php endif; ?>
</div>
</body>
</html>
