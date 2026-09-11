<?php
/**
 * DevTools Practice Administration > Settings parity tests.
 *
 * Exercises api/admin-practices.php?action=settings and action=save_settings
 * against the local dev server and verifies the editable Display & Behavior
 * settings match the main Settings surface (same persisted keys, ranges, and
 * defaults), including cross-practice isolation and partial-save preservation.
 */

$base = __DIR__ . '/..';
$port = 18301;
$docRoot = realpath($base);

$serverCmd = "php -S 127.0.0.1:{$port} -t \"{$docRoot}\"";
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($serverCmd, $descriptors, $pipes);
if (!$process) {
    die("FAIL: Could not start dev server\n");
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
register_shutdown_function(function () use ($process, $pipes) {
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($process);
    proc_close($process);
});

$ready = false;
for ($i = 0; $i < 20; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $ready = true; break; }
    usleep(200000);
}
if (!$ready) die("FAIL: Dev server did not start\n");

$passed = 0;
$failed = 0;

function assertTrue(string $name, bool $condition, string $context = ''): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: {$name}\n"; }
    else { $failed++; echo "FAIL: {$name}" . ($context ? " ({$context})" : '') . "\n"; }
}

function req(string $method, string $url, array $body = [], string $cookieJar = ''): array {
    $ch = curl_init($url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    curl_setopt($ch, CURLOPT_HEADER, true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = $r === false ? '' : substr($r, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function getCsrf(string $baseUrl, string $cookieJar): ?string {
    $r = req('GET', "{$baseUrl}/admin-practices.php", [], $cookieJar);
    return preg_match('/<meta name="csrf-token" content="([^"]+)">/', $r['body'], $m) ? $m[1] : null;
}

$baseUrl = "http://127.0.0.1:{$port}";

// Two independent practices owned by different users.
$setupA = json_decode(req('POST', "{$baseUrl}/api/test-helpers.php", [
    'action' => 'setup_test_user',
    'email' => 'dtsetparity-a-' . uniqid() . '@example.com',
    'password' => 'TestPass123!',
    'firstName' => 'Parity', 'lastName' => 'Alpha',
    'practiceName' => 'Parity Practice A'
])['body'], true);
$practiceA = (int)($setupA['practice_id'] ?? 0);
$emailA = $setupA['email'] ?? '';
assertTrue('setup practice A', ($setupA['success'] ?? false) && $practiceA > 0, json_encode($setupA));

$setupB = json_decode(req('POST', "{$baseUrl}/api/test-helpers.php", [
    'action' => 'setup_test_user',
    'email' => 'dtsetparity-b-' . uniqid() . '@example.com',
    'password' => 'TestPass123!',
    'firstName' => 'Parity', 'lastName' => 'Beta',
    'practiceName' => 'Parity Practice B'
])['body'], true);
$practiceB = (int)($setupB['practice_id'] ?? 0);
assertTrue('setup practice B', ($setupB['success'] ?? false) && $practiceB > 0, json_encode($setupB));

// Log in as practice A's owner (dev env grants DevTools access).
$cookieA = tempnam(sys_get_temp_dir(), 'parity_cookie_');
$login = json_decode(req('POST', "{$baseUrl}/api/auth-email.php", [
    'action' => 'login', 'email' => $emailA, 'password' => 'TestPass123!'
], $cookieA)['body'], true);
assertTrue('login as practice A owner', $login['success'] ?? false, json_encode($login));

$csrfA = getCsrf($baseUrl, $cookieA);
assertTrue('csrf token obtained', !empty($csrfA));

// Owners/admins must accept the current Terms before admin mutations.
$terms = req('POST', "{$baseUrl}/api/accept-terms.php", [
    'accepted' => true,
    'terms_version' => '2026-09-01',
    'csrf_token' => $csrfA
], $cookieA);
assertTrue('terms accepted', $terms['code'] === 200, "code={$terms['code']} body=" . substr($terms['body'], 0, 150));

// ---------------------------------------------------------------------------
// Settings payload shape - every Display & Behavior key present
// ---------------------------------------------------------------------------
$getA = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=settings&practice_id={$practiceA}", [], $cookieA)['body'], true);
assertTrue('settings GET succeeds', $getA['success'] ?? false);
$s = $getA['settings'] ?? [];
assertTrue('has allow_archiving_individual_cases', array_key_exists('allow_archiving_individual_cases', $s['case_management'] ?? []));
assertTrue('has delivered_hide_days', array_key_exists('delivered_hide_days', $s['case_management'] ?? []));
assertTrue('has highlight_past_due', array_key_exists('highlight_past_due', $s['due_date_highlighting'] ?? []));
assertTrue('has past_due_days', array_key_exists('past_due_days', $s['due_date_highlighting'] ?? []));
assertTrue('has highlight_coming_due', array_key_exists('highlight_coming_due', $s['due_date_highlighting'] ?? []));
assertTrue('has coming_due_days', array_key_exists('coming_due_days', $s['due_date_highlighting'] ?? []));
assertTrue('has highlight_appointment_risk', array_key_exists('highlight_appointment_risk', $s['due_date_highlighting'] ?? []));
assertTrue('has appointment_risk_days', array_key_exists('appointment_risk_days', $s['due_date_highlighting'] ?? []));
assertTrue('has case_review_tracking_enabled', array_key_exists('case_review_tracking_enabled', $s));
assertTrue('has google_drive_backup', array_key_exists('google_drive_backup', $s));

// ---------------------------------------------------------------------------
// CSRF required for save
// ---------------------------------------------------------------------------
$noCsrf = req('POST', "{$baseUrl}/api/admin-practices.php?action=save_settings", [
    'practice_id' => $practiceA,
    'settings' => ['highlight_past_due' => false]
], $cookieA);
assertTrue('save without CSRF is rejected', $noCsrf['code'] === 403, "code={$noCsrf['code']}");

// ---------------------------------------------------------------------------
// Full save round-trip, then verify persistence + parity with get-settings.php
// ---------------------------------------------------------------------------
$payload = [
    'allow_card_delete' => false,            // explicit false must persist
    'delivered_hide_days' => 0,              // explicit zero must persist (auto-archive off)
    'highlight_past_due' => false,
    'past_due_days' => 7,
    'highlight_coming_due' => true,
    'coming_due_days' => 14,
    'highlight_appointment_risk' => false,
    'appointment_risk_days' => 0,            // 0 is a valid risk threshold
    'case_review_tracking_enabled' => true,
    'google_drive_backup' => true
];
$save = json_decode(req('POST', "{$baseUrl}/api/admin-practices.php?action=save_settings", [
    'practice_id' => $practiceA,
    'settings' => $payload,
    'csrf_token' => $csrfA
], $cookieA)['body'], true);
assertTrue('save_settings succeeds', $save['success'] ?? false, json_encode($save));

$getA2 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=settings&practice_id={$practiceA}", [], $cookieA)['body'], true)['settings'] ?? [];
$cm = $getA2['case_management'] ?? [];
$hl = $getA2['due_date_highlighting'] ?? [];
assertTrue('allow_card_delete persisted false', ($cm['allow_archiving_individual_cases'] ?? null) === false);
assertTrue('delivered_hide_days persisted 0', ($cm['delivered_hide_days'] ?? null) === 0);
assertTrue('auto_archive reflects delivered_hide_days=0', ($cm['auto_archive_delivered_cases'] ?? null) === false);
assertTrue('highlight_past_due persisted false', ($hl['highlight_past_due'] ?? null) === false);
assertTrue('past_due_days persisted 7', ($hl['past_due_days'] ?? null) === 7);
assertTrue('highlight_coming_due persisted true', ($hl['highlight_coming_due'] ?? null) === true);
assertTrue('coming_due_days persisted 14', ($hl['coming_due_days'] ?? null) === 14);
assertTrue('highlight_appointment_risk persisted false', ($hl['highlight_appointment_risk'] ?? null) === false);
assertTrue('appointment_risk_days persisted 0', ($hl['appointment_risk_days'] ?? null) === 0);
assertTrue('case_review_tracking persisted true', ($getA2['case_review_tracking_enabled'] ?? null) === true);
assertTrue('google_drive_backup persisted true', ($getA2['google_drive_backup'] ?? null) === true);

// Same values must be visible to the main Settings UI (same user_preferences row).
$mainSettings = json_decode(req('GET', "{$baseUrl}/api/get-settings.php", [], $cookieA)['body'], true);
$prefs = $mainSettings['preferences'] ?? [];
assertTrue('main settings sees allow_card_delete=false', ($prefs['allow_card_delete'] ?? null) === false);
assertTrue('main settings sees delivered_hide_days=0', (int)($prefs['delivered_hide_days'] ?? -1) === 0);
assertTrue('main settings sees coming_due_days=14', (int)($prefs['coming_due_days'] ?? -1) === 14);
assertTrue('main settings sees highlight_appointment_risk=false', ($prefs['highlight_appointment_risk'] ?? null) === false);
assertTrue('main settings sees case_review_tracking_enabled=true', ($prefs['case_review_tracking_enabled'] ?? null) === true);

// ---------------------------------------------------------------------------
// Partial save preserves untouched keys
// ---------------------------------------------------------------------------
$partial = json_decode(req('POST', "{$baseUrl}/api/admin-practices.php?action=save_settings", [
    'practice_id' => $practiceA,
    'settings' => ['past_due_days' => 42],
    'csrf_token' => $csrfA
], $cookieA)['body'], true);
assertTrue('partial save succeeds', $partial['success'] ?? false, json_encode($partial));
$hlP = $partial['settings']['due_date_highlighting'] ?? [];
$cmP = $partial['settings']['case_management'] ?? [];
assertTrue('partial save updated past_due_days', ($hlP['past_due_days'] ?? null) === 42);
assertTrue('partial save kept highlight_past_due=false', ($hlP['highlight_past_due'] ?? null) === false);
assertTrue('partial save kept coming_due_days=14', ($hlP['coming_due_days'] ?? null) === 14);
assertTrue('partial save kept allow_card_delete=false', ($cmP['allow_archiving_individual_cases'] ?? null) === false);
assertTrue('partial save kept case_review_tracking=true', ($partial['settings']['case_review_tracking_enabled'] ?? null) === true);

// ---------------------------------------------------------------------------
// Cross-practice isolation: edit B, confirm A unchanged
// ---------------------------------------------------------------------------
$saveB = json_decode(req('POST', "{$baseUrl}/api/admin-practices.php?action=save_settings", [
    'practice_id' => $practiceB,
    'settings' => ['highlight_coming_due' => true, 'coming_due_days' => 30, 'case_review_tracking_enabled' => false],
    'csrf_token' => $csrfA
], $cookieA)['body'], true);
assertTrue('practice B save succeeds', $saveB['success'] ?? false, json_encode($saveB));
$getB = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=settings&practice_id={$practiceB}", [], $cookieA)['body'], true)['settings'] ?? [];
assertTrue('practice B coming_due_days=30', ($getB['due_date_highlighting']['coming_due_days'] ?? null) === 30);
$getA3 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=settings&practice_id={$practiceA}", [], $cookieA)['body'], true)['settings'] ?? [];
assertTrue('practice A coming_due_days still 14 (no leak)', ($getA3['due_date_highlighting']['coming_due_days'] ?? null) === 14);
assertTrue('practice A review tracking still on (no leak)', ($getA3['case_review_tracking_enabled'] ?? null) === true);

// ---------------------------------------------------------------------------
// Reverse direction: main save-settings.php -> DevTools read
// ---------------------------------------------------------------------------
$mainSave = json_decode(req('POST', "{$baseUrl}/api/save-settings.php", [
    'allowCardDelete' => true,
    'deliveredHideDays' => 99,
    'highlightPastDue' => true,
    'pastDueDays' => 9,
    'highlightComingDue' => false,
    'comingDueDays' => 5,
    'highlightAppointmentRisk' => true,
    'appointmentRiskDays' => 2,
    'caseReviewTrackingEnabled' => false,
    'googleDriveBackup' => false,
    'csrf_token' => $csrfA
], $cookieA)['body'], true);
assertTrue('main save-settings succeeds', $mainSave['success'] ?? false, json_encode($mainSave));

$getA4 = json_decode(req('GET', "{$baseUrl}/api/admin-practices.php?action=settings&practice_id={$practiceA}", [], $cookieA)['body'], true)['settings'] ?? [];
assertTrue('devtools sees delivered_hide_days=99', ($getA4['case_management']['delivered_hide_days'] ?? null) === 99);
assertTrue('devtools sees past_due_days=9', ($getA4['due_date_highlighting']['past_due_days'] ?? null) === 9);
assertTrue('devtools sees appointment_risk_days=2', ($getA4['due_date_highlighting']['appointment_risk_days'] ?? null) === 2);
assertTrue('devtools sees case_review_tracking off', ($getA4['case_review_tracking_enabled'] ?? null) === false);

// ---------------------------------------------------------------------------
// Range clamping matches save-settings.php
// ---------------------------------------------------------------------------
$clamp = json_decode(req('POST', "{$baseUrl}/api/admin-practices.php?action=save_settings", [
    'practice_id' => $practiceA,
    'settings' => ['past_due_days' => 999, 'delivered_hide_days' => 9999, 'appointment_risk_days' => -5],
    'csrf_token' => $csrfA
], $cookieA)['body'], true)['settings'] ?? [];
assertTrue('past_due_days clamped to 99', ($clamp['due_date_highlighting']['past_due_days'] ?? null) === 99);
assertTrue('delivered_hide_days clamped to 365', ($clamp['case_management']['delivered_hide_days'] ?? null) === 365);
assertTrue('appointment_risk_days clamped to 0', ($clamp['due_date_highlighting']['appointment_risk_days'] ?? null) === 0);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
