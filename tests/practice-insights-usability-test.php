<?php
// Static regression tests for the Practice Insights usability pass:
// segmented primary nav, stronger secondary tabs, attention-queue
// identification + inline expansion + Case Board action, and
// recommendation quality gating.
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

$main = file_get_contents("$root/main.php");
$js = file_get_contents("$root/js/analytics-pro.js");
$css = file_get_contents("$root/css/analytics-pro.css");
$mobileCss = file_get_contents("$root/css/mobile.css");
$recApi = file_get_contents("$root/api/ai-recommendations.php");
$recStore = file_get_contents("$root/api/ai-recommendation-store.php");
$appConfig = file_get_contents("$root/api/appConfig.php");

// ── 1. Primary Practice/Labs nav (shared underline-tab chrome) ──────────
ok(strpos($main, 'id="insightsSubtabs"') !== false && strpos($main, 'role="tablist"') !== false, 'subtabs tablist markup');
ok(strpos($css, '.insights-subtabs') !== false, 'subtabs container styled');
ok(strpos($css, '.insights-subtab.active') !== false
    && strpos($css, 'border-bottom: 2px solid transparent') !== false
    && strpos($css, 'border-bottom-color: var(--ap-accent-primary') !== false,
    'subtabs use shared underline active state');
ok((bool) preg_match('/\.insights-subtab \{[^}]*\}/', $css, $m)
    && strpos($m[0], 'border-radius') === false
    && strpos($m[0], 'box-shadow') === false,
    'subtab has no pill/segmented treatment');
ok(strpos($css, 'min-height: 40px') !== false, 'subtab click target >= 40px');
ok(strpos($css, ':focus-visible') !== false, 'subtab focus-visible style');
ok(strpos($mobileCss, '.insights-subtabs') !== false, 'mobile subtab override present');

// ── 2. Secondary Overview/Operations/Trends tab bar ─────────────────────
ok(strpos($main, 'id="apViewTabs"') !== false && strpos($main, 'data-ap-view="overview"') !== false, 'view tabs markup');
ok(strpos($js, 'data-ap-view') !== false || strpos($js, 'apViewTabs') !== false, 'view tabs wired in JS');
ok(strpos($css, '.ap-view-tab') !== false && strpos($css, 'border-bottom: 2px solid transparent') !== false, 'view-tab underline matches shared chrome');
ok(strpos($css, 'min-height: 40px') !== false, 'view-tab click target');

// ── 3. Attention Required identification ────────────────────────────────
ok(strpos($main, 'id="apAttentionBody"') !== false, 'attention tbody');
ok(strpos($main, 'id="apAttentionShowAll"') !== false, 'Show All button present');
ok(strpos($main, 'id="apAttentionBoard"') !== false, 'Open in Case Board button present');
ok(strpos($main, 'apAttentionViewAll') === false, 'old View All id removed');
ok(strpos($js, 'attentionState.expanded') !== false, 'expanded state tracked');
ok(strpos($js, 'ATTENTION_PREVIEW_COUNT = 5') !== false, 'five-row preview preserved');
ok(strpos($js, 'ap-attn-patient-link') !== false, 'patient name is the click target');
ok(strpos($js, 'data-attn-open') !== false, 'case-open data attribute');
ok(strpos($js, 'ap-attn-case-type') !== false, 'case type shown under patient name');
ok(strpos($js, 'ap-attn-case-link') === false, 'redundant case-id column gone');
ok(strpos($js, 'insights.attention.show_all') !== false, 'dynamic show-all label');
ok(strpos($js, 'insights.attention.show_fewer') !== false, 'show-fewer label');
ok(strpos($main, "insights.attention.open_in_board") !== false, 'open-in-board label');
ok(strpos($js, 'navigateToFilteredCases') !== false, 'board navigation reuses existing helper');
ok(strpos($js, 'attentionState.expanded = false') !== false, 'filter change resets expansion');

// ── 4. Recommendation quality gating ────────────────────────────────────
ok(strpos($appConfig, 'exactly 3 actionable') === false, 'prompt no longer mandates exactly 3');
ok(strpos($appConfig, 'empty array []') !== false || strpos($appConfig, 'empty array') !== false, 'prompt permits zero recommendations');
ok(strpos($appConfig, '"insight"') !== false, 'prompt requests structured insight');
ok(strpos($recApi, 'overdue_by_status') !== false, 'overdue-by-status fact emitted');
ok(strpos($recApi, 'overdue_by_type') !== false, 'overdue-by-type fact emitted');
ok(strpos($recApi, 'grounded') !== false, 'server-side grounding gate');
ok(strpos($recApi, 'seenTitles') !== false, 'title dedupe');
ok(strpos($recStore, "'insight'") !== false, 'insight survives store normalization');
ok(strpos($js, 'detail_pattern') !== false && strpos($js, 'detail_evidence') !== false
   && strpos($js, 'detail_significance') !== false && strpos($js, 'detail_action') !== false, 'insight sections rendered');
ok(strpos($js, 'recs.length === 0') !== false, 'distinct zero-rec empty state');
ok(strpos($js, 'hasDetails = insightBits.length > 0 || factLines.length > 1') !== false, 'View Details suppressed without substance');

// ── 4b. Recommendation lifecycle correctness ────────────────────────────
ok(strpos($js, "aiRecsFilter === 'active') return state === 'new'") !== false,
   'Active filter shows new only (reviewed excluded)');
ok(strpos($js, 'counts.new + counts.reviewed') === false
   && strpos($js, 'const activeCount = counts.new') !== false,
   'Active count excludes reviewed');
ok(strpos($js, "state === 'reviewed'") !== false
   && strpos($js, "insights.recs.mark_new") !== false,
   'Reviewed offers Mark as new');
ok((bool) preg_match("/state === 'reviewed'\) \{[\s\S]{0,600}data-rec-state=\"new\"[\s\S]{0,600}data-rec-state=\"dismissed\"/", $js),
   'Reviewed actions are Mark as new + Dismiss (no Mark reviewed)');
ok(strpos($js, 'empty_active') !== false && strpos($js, 'empty_active_sub') !== false,
   'dedicated empty-active state rendered');
ok(strpos($js, 'insights.recs.update_failed') !== false,
   'failed status update surfaces an error');
ok(strpos($js, 'aiRecsNotice') !== false, 'lifecycle notice state tracked');
ok(strpos($recApi, 'data_signature') !== false
   && strpos($recApi, 'aiRecSignature(gatherAnalyticsData') !== false,
   'set_state anchors state to current data signature');
ok(strpos($recStore, 'data_signature = COALESCE(VALUES(data_signature), data_signature)') !== false,
   'set_state persists data_signature');
ok(strpos($recStore, "data_signature'] !== null") !== false,
   'dismissed reopen requires a known changed signature');

// ── 5. Locale coverage ──────────────────────────────────────────────────
$locales = ['en-US','de-DE','es-ES','fr-CA','fr-FR','it-IT','ko-KR','pt-BR','ru-RU'];
$needKeys = [
  ['insights','attention','show_all'],
  ['insights','attention','show_fewer'],
  ['insights','attention','open_in_board'],
  ['insights','recs','detail_pattern'],
  ['insights','recs','detail_evidence'],
  ['insights','recs','detail_significance'],
  ['insights','recs','detail_action'],
  ['insights','recs','fact_overdue_by_status'],
  ['insights','recs','fact_overdue_by_type'],
  ['insights','recs','mark_new'],
  ['insights','recs','update_failed'],
  ['insights','recs','empty_active'],
  ['insights','recs','empty_active_sub'],
];
foreach ($locales as $loc) {
  $d = json_decode(file_get_contents("$root/locales/$loc.json"), true);
  foreach ($needKeys as $path) {
    $cur = $d;
    foreach ($path as $p) { $cur = $cur[$p] ?? null; if ($cur === null) break; }
    ok($cur !== null && $cur !== '', "$loc missing " . implode('.', $path));
  }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
