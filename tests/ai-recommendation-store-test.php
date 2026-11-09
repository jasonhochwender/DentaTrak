<?php
/**
 * Unit tests for api/ai-recommendation-store.php pure helpers:
 * slug/key stability, signature sensitivity, and payload normalization
 * (fact_keys validation, action_target whitelist, field sanitization).
 * No database required.
 */

require_once __DIR__ . '/../api/ai-recommendation-store.php';

$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// --- aiRecSlug / aiRecKey -------------------------------------------------
ok(aiRecSlug('Reduce Overdue Case Backlog!') === 'reduce overdue case backlog', 'slug normalizes punctuation/case');
ok(aiRecSlug('  Multiple   Spaces  ') === 'multiple spaces', 'slug collapses whitespace');
ok(aiRecKey('efficiency', 'Triage Bottleneck') === aiRecKey('efficiency', 'triage bottleneck'), 'rec_key case-insensitive stable');
ok(aiRecKey('efficiency', 'A') !== aiRecKey('quality', 'A'), 'rec_key differs by category');
ok(strlen(aiRecKey('workload', str_repeat('x', 300))) <= 160, 'rec_key length capped');

// --- aiRecSignature --------------------------------------------------------
$d1 = ['overdue_cases' => 5, 'total_active_cases' => 20];
$d2 = ['overdue_cases' => 6, 'total_active_cases' => 20];
ok(aiRecSignature($d1, 'en-US') === aiRecSignature($d1, 'en-US'), 'signature deterministic');
ok(aiRecSignature($d1, 'en-US') !== aiRecSignature($d2, 'en-US'), 'signature changes with data');
ok(aiRecSignature($d1, 'en-US') !== aiRecSignature($d1, 'de-DE'), 'signature changes with locale');

// --- aiRecNormalize ---------------------------------------------------------
$validFacts = ['overdue_share', 'unassigned_cases'];
$raw = [
    ['title' => 'Triage <b>Delay</b>', 'description' => 'Desc <script>x</script>', 'priority' => 'high',
     'category' => 'efficiency', 'fact_keys' => ['overdue_share', 'bogus', 'overdue_share'], 'action_target' => 'overdue'],
    ['title' => '', 'description' => 'skip me', 'priority' => 'low', 'category' => 'quality'],
    ['title' => 'Bad prio', 'description' => 'd', 'priority' => 'urgent', 'category' => 'nope',
     'fact_keys' => 'not-array', 'action_target' => 'hack'],
];
$out = aiRecNormalize($raw, $validFacts);
ok(count($out) === 2, 'normalize drops invalid entries, keeps valid');
ok($out[0]['title'] === 'Triage Delay', 'normalize strips tags from title');
ok($out[0]['description'] === 'Desc x', 'normalize strips tags from description');
ok($out[0]['fact_keys'] === ['overdue_share'], 'fact_keys validated + deduped');
ok($out[0]['action_target'] === 'overdue', 'valid action_target kept');
ok($out[0]['rec_key'] !== '', 'rec_key generated');
ok($out[1]['priority'] === 'medium' && $out[1]['category'] === 'efficiency', 'invalid priority/category defaulted');
ok($out[1]['fact_keys'] === [] && $out[1]['action_target'] === 'none', 'invalid fact_keys/action_target sanitized');

// Cap at 5
$many = [];
for ($i = 0; $i < 8; $i++) $many[] = ['title' => "Rec $i", 'description' => "d$i"];
ok(count(aiRecNormalize($many, [])) === 5, 'normalize caps at 5 recommendations');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
