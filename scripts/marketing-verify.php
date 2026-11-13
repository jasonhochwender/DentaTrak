<?php
/**
 * CLI: production smoke + timing probe for the marketing CRM read path.
 * Runs inside the dtk-marketing-import Cloud Run job (or any environment
 * with DB access). Prints counts and millisecond timings only - no
 * prospect PII is emitted.
 *
 *   php scripts/marketing-verify.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/ProspectCrm.php';
require_once __DIR__ . '/../api/marketing/ProspectDirectory.php';
require_once __DIR__ . '/../api/marketing/OutreachEligibility.php';
require_once __DIR__ . '/../api/marketing/SuppressionService.php';

global $pdo, $appConfig;

echo 'environment: ' . ($appConfig['current_environment'] ?? 'unknown') . "\n";
echo 'database:    ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

$crm = new ProspectCrm($pdo);
$ms = function (callable $fn) { $t = microtime(true); $r = $fn(); return [round((microtime(true) - $t) * 1000), $r]; };

[$t, $r] = $ms(fn() => $crm->search(['page' => 1, 'per' => 50]));
echo "search p1 (cold):        {$t} ms  total=" . ($r['total'] ?? '?') . " rows=" . count($r['rows'] ?? []) . "\n";
[$t, $r] = $ms(fn() => $crm->search(['page' => 1, 'per' => 50]));
echo "search p1 (warm):        {$t} ms\n";
[$t, $r] = $ms(fn() => $crm->search(['q' => 'dental', 'page' => 1, 'per' => 50]));
echo "search q=dental:         {$t} ms  total=" . ($r['total'] ?? '?') . "\n";
[$t, $r] = $ms(fn() => $crm->search(['page' => 3, 'per' => 50, 'sort' => 'org', 'dir' => 'desc']));
echo "search sort+page3:       {$t} ms\n";
[$t, $r] = $ms(fn() => $crm->search(['review' => 1, 'page' => 1, 'per' => 50]));
echo "search review=1:         {$t} ms  total=" . ($r['total'] ?? '?') . "\n";
[$t, $r] = $ms(fn() => $crm->search(['eligible' => 'initial', 'page' => 1, 'per' => 50]));
echo "search eligible=initial: {$t} ms  total=" . ($r['total'] ?? '?') . "\n";

// Detail lookups on the first three result ids.
$ids = [];
foreach (array_slice($r['rows'] ?? [], 0, 3) as $it) { if (!empty($it['id'])) $ids[] = (int)$it['id']; }
foreach ($ids as $id) {
    [$t] = $ms(fn() => $crm->findById($id));
    echo "findById #$id:           {$t} ms\n";
}
[$t] = $ms(fn() => $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.campaign_id=c.id) recipients FROM marketing_campaigns c ORDER BY c.created_at DESC LIMIT 100")->fetchAll());
echo "campaign list:           {$t} ms\n";
[$t] = $ms(fn() => $pdo->query("SELECT COUNT(*) FROM marketing_contacts")->fetchColumn());
echo "contacts count:          {$t} ms\n";

// Flag + send-policy sanity (no secrets printed).
echo 'MARKETING_CRM_READS env: ' . (getenv('MARKETING_CRM_READS') ?: '(unset)') . "\n";
echo 'MARKETING_SENDING env:   ' . (getenv('MARKETING_SENDING') ?: '(unset)') . "\n";
