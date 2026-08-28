<?php
/**
 * Verify that Bite Rim is wired up in every hard-coded case type list
 * and that analytics/lab insights derive case types dynamically.
 */

declare(strict_types=1);

$results = [];

// PHP translation/normalization map
require_once __DIR__ . '/../api/i18n.php';
$map = getCaseTypeMap();
$results[] = 'Map contains Bite Rim: ' . (
    ($map['Bite Rim'] ?? null) === 'bite_rim' ? 'PASS' : 'FAIL'
);

// Locale
$locale = json_decode(file_get_contents(__DIR__ . '/../locales/en-US.json'), true);
$results[] = 'Locale contains Bite Rim: ' . (
    ($locale['case_types']['bite_rim'] ?? null) === 'Bite Rim' ? 'PASS' : 'FAIL'
);

// main.php selectors
$main = file_get_contents(__DIR__ . '/../main.php');
$results[] = 'main.php case creation select contains Bite Rim: ' . (
    (strpos($main, '<option value="Bite Rim">') !== false) ? 'PASS' : 'FAIL'
);
$results[] = 'main.php kanban filter contains Bite Rim: ' . (
    (preg_match('/<select id="filterCaseType"[^>]*>.*?<option value="Bite Rim">/s', $main) === 1) ? 'PASS' : 'FAIL'
);
$results[] = 'main.php archived filter contains Bite Rim: ' . (
    (preg_match('/<select id="archivedCaseType"[^>]*>.*?<option value="Bite Rim">/s', $main) === 1) ? 'PASS' : 'FAIL'
);
$results[] = 'main.php devCaseType contains Bite Rim: ' . (
    (preg_match('/<select id="devCaseType"[^>]*>.*?<option value="Bite Rim">/s', $main) === 1) ? 'PASS' : 'FAIL'
);

// Demo / fake data generators
$fake = file_get_contents(__DIR__ . '/../api/generate-fake-cases.php');
$results[] = 'generate-fake-cases.php $caseTypes contains Bite Rim: ' . (
    (strpos($fake, "'Bite Rim'") !== false) ? 'PASS' : 'FAIL'
);

$demo = file_get_contents(__DIR__ . '/../api/generate-dental-practice-demo-data.php');
$results[] = 'generate-dental-practice-demo-data.php caseTypeWeights contains Bite Rim: ' . (
    (strpos($demo, "'Bite Rim' =>") !== false) ? 'PASS' : 'FAIL'
);
$results[] = 'generate-dental-practice-demo-data.php $caseTypesHistorical contains Bite Rim: ' . (
    (strpos($demo, "'Bite Rim'") !== false) ? 'PASS' : 'FAIL'
);
$results[] = 'generate-dental-practice-demo-data.php $turnaroundByType contains Bite Rim: ' . (
    (strpos($demo, "'Bite Rim' => [7, 14]") !== false) ? 'PASS' : 'FAIL'
);

// Analytics and lab insights should not hard-code case type enumeration
$analytics = file_get_contents(__DIR__ . '/../api/get-analytics.php');
$results[] = 'get-analytics.php groups by case_type (not hard-coded list): ' . (
    (strpos($analytics, 'GROUP BY case_type') !== false) ? 'PASS' : 'FAIL'
);

$lab = file_get_contents(__DIR__ . '/../api/get-lab-insights.php');
$results[] = 'get-lab-insights.php does not enumerate case types: ' . (
    (strpos($lab, 'case_type') !== false && !preg_match('/IN\s*\([^)]*Crown/', $lab)) ? 'PASS' : 'FAIL'
);

// JS material list unchanged
$js = file_get_contents(__DIR__ . '/../js/app.js');
$results[] = 'Bite Rim not in JS material-requiring list: ' . (
    strpos($js, '"Bite Rim"') === false ? 'PASS' : 'FAIL'
);

// Case creation/update do not reject unknown case types
create:
$create = file_get_contents(__DIR__ . '/../api/create-case.php');
$results[] = 'create-case.php has no hard-coded allowed case type list: ' . (
    (strpos($create, '$caseTypeClinicalFields') !== false && strpos($create, "'Bite Rim'") === false) ? 'PASS' : 'FAIL'
);

$update = file_get_contents(__DIR__ . '/../api/update-case.php');
$results[] = 'update-case.php has no hard-coded allowed case type list: ' . (
    (strpos($update, '$caseTypeClinicalFields') !== false && strpos($update, "'Bite Rim'") === false) ? 'PASS' : 'FAIL'
);

header('Content-Type: text/plain');
echo implode("\n", $results);
