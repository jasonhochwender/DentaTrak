<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../api/case-view-preferences-store.php';
$count = 0;
function checkPreference($name, $condition) {
    global $count;
    if (!$condition) throw new RuntimeException($name);
    $count++;
    echo "PASS $name\n";
}
$default = normalizeCaseViewPreferences([]);
checkPreference('defaults have no explicit sort', $default['sort'] === []);
checkPreference('all nine existing filter controls supported', count($default['filters']) === 9);
$valid = ['filters' => ['patientSearch' => 'Amy', 'filterLateCases' => true], 'sort' => [['field' => 'type', 'direction' => 'asc'], ['field' => 'assigned', 'direction' => 'desc'], ['field' => 'due', 'direction' => 'asc']]];
checkPreference('three criteria accepted', count(normalizeCaseViewPreferences($valid, true)['sort']) === 3);
foreach ([
    [['field' => 'sql injection', 'direction' => 'asc']],
    [['field' => 'type', 'direction' => 'bad']],
    [['field' => 'type', 'direction' => 'asc'], ['field' => 'type', 'direction' => 'desc']],
    array_fill(0, 4, ['field' => 'due', 'direction' => 'asc']),
] as $i => $bad) {
    $rejected = false;
    try { normalizeCaseViewPreferences(['sort' => $bad], true); } catch (InvalidArgumentException $e) { $rejected = true; }
    checkPreference('invalid sort rejected ' . $i, $rejected);
}
foreach (['filterLateCases' => 'false', 'patientSearch' => [], 'filterCarrier' => 'obsolete'] as $key => $bad) {
    $rejected = false;
    try { normalizeCaseViewPreferences(['filters' => [$key => $bad]], true); } catch (InvalidArgumentException $e) { $rejected = true; }
    checkPreference('invalid filter rejected ' . $key, $rejected);
}
checkPreference('obsolete saved criteria discarded', normalizeCaseViewPreferences(['sort' => [['field' => 'retired', 'direction' => 'asc']]])['sort'] === []);
checkPreference('reset sort preserves filters', normalizeCaseViewPreferences(['filters' => $valid['filters'], 'sort' => []])['filters']['patientSearch'] === 'Amy');
checkPreference('removed assignee retained as exact saved selection', normalizeCaseViewPreferences(['filters' => ['filterAssignedTo' => 'former@example.test']])['filters']['filterAssignedTo'] === 'former@example.test');
// Regression: every case type the filter select offers must survive strict
// save validation - a stale hardcoded allowlist rejected 'Implant Crown'
// and four other offered values, producing the intermittent save toast.
require_once __DIR__ . '/../api/case-types.php';
foreach (getFilterableCaseTypes() as $caseType) {
    $accepted = true;
    try { normalizeCaseViewPreferences(['filters' => ['filterCaseType' => $caseType]], true); }
    catch (InvalidArgumentException $e) { $accepted = false; }
    checkPreference('filterable case type accepted: ' . $caseType, $accepted);
}
$rejected = false;
try { normalizeCaseViewPreferences(['filters' => ['filterCaseType' => 'not a real type']], true); } catch (InvalidArgumentException $e) { $rejected = true; }
checkPreference('unknown case type still rejected', $rejected);
echo "$count validation checks passed (no database).\n";
