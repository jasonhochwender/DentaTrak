<?php
/**
 * "Show Revision History tab" practice-wide display setting test.
 *
 * The setting moved from user_preferences (personal) to the practices row
 * (practice-wide, admin-controlled, default OFF). Verifies:
 *  - practice-display-settings.php adds practices.show_case_history_tab and
 *    resolves it OFF by default (NULL -> 0)
 *  - deterministic migration copies the practice creator's legacy
 *    user_preferences value exactly once (sentinel prevents re-runs)
 *  - conflicting member values never overwrite the migrated value
 *  - updatePracticeDisplaySettings() writes only submitted keys (absent
 *    keys preserve stored values)
 *  - save-settings.php routes the setting through the practice-wide write
 *    path and keeps the non-admin personal path free of it
 *  - main.php renders the control inside the practice-admin gate
 *  - app.js applies the practice value, guards tab activation, and sends
 *    the value in the settings payload
 *  - locale keys exist in every supported locale
 *
 * Run: php tests/case-history-tab-preference-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-display-settings.php';

$environment = $appConfig['current_environment'] ?? $appConfig['environment'] ?? 'production';
if ($environment === 'production') {
    fwrite(STDERR, "Refusing to run case-history-tab-preference-test.php in production.\n");
    exit(1);
}

if (!$pdo) {
    fwrite(STDERR, "No database connection available.\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function assertTrue($condition, $message) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}

$run = 'histab-' . substr(bin2hex(random_bytes(4)), 0, 8);
$creatorUserId = null;
$memberUserId = null;
$practiceId = null;

try {
    // --- Schema: practices column exists via self-heal -----------------
    ensurePracticeDisplaySettingsSchema($pdo);
    $colStmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'show_case_history_tab'");
    $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
    assertTrue($col !== false, 'practices.show_case_history_tab column exists');
    $sentStmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'display_settings_migrated'");
    assertTrue($sentStmt && $sentStmt->rowCount() > 0, 'practices.display_settings_migrated sentinel exists');

    // --- Fixtures: creator (explicit ON) + member (conflicting OFF) ----
    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'Hist', 'Creator', 'user', 1, 1, NOW())")
        ->execute(['e' => "{$run}-creator@example.test"]);
    $creatorUserId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (email, password_hash, auth_method, first_name, last_name, role, is_active, email_verified, created_at)
                   VALUES (:e, '', 'email', 'Hist', 'Member', 'user', 1, 1, NOW())")
        ->execute(['e' => "{$run}-member@example.test"]);
    $memberUserId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
                   VALUES (UUID(), :n, :cb, 1, NOW())")
        ->execute(['n' => "History Tab {$run}", 'cb' => $creatorUserId]);
    $practiceId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'admin', 1)")
        ->execute(['p' => $practiceId, 'u' => $creatorUserId]);
    $pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner) VALUES (:p, :u, 'user', 0)")
        ->execute(['p' => $practiceId, 'u' => $memberUserId]);

    // Legacy per-user values: creator ON, member OFF (conflict).
    $upCols = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'show_case_history_tab'");
    if (!$upCols || $upCols->rowCount() === 0) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN show_case_history_tab TINYINT(1) NULL DEFAULT NULL");
    }
    $pdo->prepare("INSERT INTO user_preferences (user_id, show_case_history_tab) VALUES (:u, 1)")
        ->execute(['u' => $creatorUserId]);
    $pdo->prepare("INSERT INTO user_preferences (user_id, show_case_history_tab) VALUES (:u, 0)")
        ->execute(['u' => $memberUserId]);

    // --- Migration: creator's value wins, runs once --------------------
    $resolved = getPracticeDisplaySettings($pdo, $practiceId);
    assertTrue($resolved['show_case_history_tab'] === true,
        'migration copies practice creator value (ON)');

    $sent = $pdo->prepare("SELECT display_settings_migrated FROM practices WHERE id = :p");
    $sent->execute(['p' => $practiceId]);
    assertTrue((int)$sent->fetchColumn() === 1, 'migration sentinel set');

    // Re-run is a no-op even if we dirty the legacy source.
    $pdo->prepare("UPDATE user_preferences SET show_case_history_tab = 0 WHERE user_id = :u")
        ->execute(['u' => $creatorUserId]);
    $resolved2 = getPracticeDisplaySettings($pdo, $practiceId);
    assertTrue($resolved2['show_case_history_tab'] === true,
        'migration does not re-run (sentinel guards re-copy)');

    // Member's conflicting legacy value never touched the practice value.
    $memberCheck = $pdo->prepare("SELECT show_case_history_tab FROM user_preferences WHERE user_id = :u");
    $memberCheck->execute(['u' => $memberUserId]);
    assertTrue((int)$memberCheck->fetchColumn() === 0,
        'member legacy row preserved untouched (conflict ignored, not synced)');

    // --- Default OFF for a never-configured practice --------------------
    $pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
                   VALUES (UUID(), :n, :cb, 1, NOW())")
        ->execute(['n' => "History Default {$run}", 'cb' => $creatorUserId]);
    $defaultPracticeId = (int)$pdo->lastInsertId();
    $resolvedDefault = getPracticeDisplaySettings($pdo, $defaultPracticeId);
    assertTrue($resolvedDefault['show_case_history_tab'] === false,
        'no creator value -> Revision History defaults OFF');
    assertTrue($resolvedDefault['delivered_hide_days'] === 0,
        'auto-archive defaults to never (0) - migration never activates it');

    // --- Partial write preserves stored values ---------------------------
    updatePracticeDisplaySettings($pdo, $practiceId, ['showCaseHistoryTab' => false]);
    $afterOff = getPracticeDisplaySettings($pdo, $practiceId);
    assertTrue($afterOff['show_case_history_tab'] === false, 'admin OFF write persists');

    updatePracticeDisplaySettings($pdo, $practiceId, ['pastDueDays' => 7]);
    $afterPartial = getPracticeDisplaySettings($pdo, $practiceId);
    assertTrue($afterPartial['show_case_history_tab'] === false,
        'absent key in partial write preserves stored OFF');
    assertTrue((int)$afterPartial['past_due_days'] === 7, 'partial write applies submitted key');

    // --- Static wiring checks ------------------------------------------
    $getSettings = file_get_contents(__DIR__ . '/../api/get-settings.php');
    assertTrue(strpos($getSettings, "require_once __DIR__ . '/practice-display-settings.php'") !== false,
        'get-settings.php includes the practice display helper');
    assertTrue(strpos($getSettings, 'getPracticeDisplaySettings(') !== false,
        'get-settings.php overlays practice-scoped values');
    assertTrue(strpos($getSettings, "2026-10-18 12:00:00") === false,
        'obsolete per-user backfill migration removed from get-settings.php');

    $saveSettings = file_get_contents(__DIR__ . '/../api/save-settings.php');
    assertTrue(strpos($saveSettings, 'updatePracticeDisplaySettings(') !== false,
        'save-settings.php routes display settings through the practice-wide write');
    assertTrue(strpos($saveSettings, 'COALESCE(VALUES(show_case_history_tab)') === false,
        'save-settings.php no longer upserts the per-user column');
    assertTrue(strpos($saveSettings, 'personalAllowedKeys') !== false,
        'save-settings.php whitelists the non-admin personal path');

    $main = file_get_contents(__DIR__ . '/../main.php');
    assertTrue(strpos($main, 'id="showCaseHistoryTab"') !== false,
        'main.php renders the preference control');
    // Practice-wide: the control must sit inside the practice-admin gate.
    $rowHtml = '';
    if (preg_match('/<div class="option-row[^"]*">\s*<label for="showCaseHistoryTab">.*?<\/div>/s', $main, $m)) {
        $rowHtml = $m[0];
    }
    assertTrue($rowHtml !== '', 'history-tab option row found');
    // The row must appear after an $isCurrentUserPracticeAdmin gate.
    $rowPos = strpos($main, 'for="showCaseHistoryTab"');
    $adminGatePos = strrpos(substr($main, 0, $rowPos), 'if ($isCurrentUserPracticeAdmin)');
    assertTrue($adminGatePos !== false, 'history-tab control is inside the practice-admin gate');

    $app = file_get_contents(__DIR__ . '/../js/app.js');
    assertTrue(strpos($app, 'function applyShowCaseHistoryTab') !== false,
        'app.js defines applyShowCaseHistoryTab');
    assertTrue(strpos($app, 'window.applyShowCaseHistoryTab') !== false,
        'app.js exposes the apply function for post-save calls');
    assertTrue(strpos($app, "toBoolean(preferences.show_case_history_tab, false)") !== false,
        'app.js reads the value with OFF default');
    assertTrue(strpos($app, "tabName === 'history' && window.showCaseHistoryTab === false") !== false,
        'setCaseModalActiveTab falls back to Details when History is hidden');
    assertTrue(strpos($app, 'showCaseHistoryTab: showCaseHistoryTab') !== false,
        'settings payload includes showCaseHistoryTab');
    assertTrue(strpos($app, "querySelector('.case-tab[data-tab=\"history\"]')") !== false,
        'app.js targets the existing History tab button');

    // Locales: required keys in every supported locale.
    $localeOk = true;
    foreach (glob(__DIR__ . '/../locales/*.json') as $lf) {
        $lj = json_decode(file_get_contents($lf), true);
        foreach (['label', 'description', 'tooltip'] as $k) {
            if (!isset($lj['settings']['display']['show_history_tab'][$k])) {
                $localeOk = false;
                echo "MISSING: " . basename($lf) . " settings.display.show_history_tab.{$k}\n";
            }
        }
    }
    assertTrue($localeOk, 'show_history_tab keys present in every locale');

} finally {
    if ($pdo) {
        try {
            if ($practiceId) {
                $pdo->prepare("DELETE FROM practice_users WHERE practice_id = :p")->execute(['p' => $practiceId]);
                $pdo->prepare("DELETE FROM practices WHERE id = :p")->execute(['p' => $practiceId]);
            }
            if (isset($defaultPracticeId) && $defaultPracticeId) {
                $pdo->prepare("DELETE FROM practice_users WHERE practice_id = :p")->execute(['p' => $defaultPracticeId]);
                $pdo->prepare("DELETE FROM practices WHERE id = :p")->execute(['p' => $defaultPracticeId]);
            }
            if ($creatorUserId) {
                $pdo->prepare("DELETE FROM user_preferences WHERE user_id = :u")->execute(['u' => $creatorUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = :u")->execute(['u' => $creatorUserId]);
            }
            if ($memberUserId) {
                $pdo->prepare("DELETE FROM user_preferences WHERE user_id = :u")->execute(['u' => $memberUserId]);
                $pdo->prepare("DELETE FROM users WHERE id = :u")->execute(['u' => $memberUserId]);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup error: " . $e->getMessage() . "\n");
        }
    }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
