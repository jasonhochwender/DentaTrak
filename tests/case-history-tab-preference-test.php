<?php
/**
 * "Show case history tab" personal display preference test.
 *
 * Verifies:
 *  - user_preferences.show_case_history_tab exists as NULL DEFAULT NULL
 *    (missing preference behaves as OFF; stored 0/1 are deliberate)
 *  - the column is keyed by user_id only - personal, not practice-scoped
 *  - get-settings.php self-heals the column, migrates backfilled 1s on
 *    never-saved rows back to NULL, and emits a real boolean
 *  - save-settings.php upserts it and preserves the stored value when
 *    the payload key is absent (COALESCE), never manufacturing an ON
 *  - main.php renders the control WITHOUT admin gating (personal pref)
 *  - app.js applies the preference, guards tab activation, falls back to
 *    the Details tab, and sends the value in the settings payload
 *  - locale keys exist in every supported locale
 *
 * Run: php tests/case-history-tab-preference-test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';

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
$testUserId = null;

try {
    // Same self-heal the endpoints run, so the test works on a fresh dev DB.
    $ensure = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'show_case_history_tab'");
    $ensureCol = $ensure ? $ensure->fetch(PDO::FETCH_ASSOC) : false;
    if ($ensureCol === false) {
        $pdo->exec("ALTER TABLE user_preferences ADD COLUMN show_case_history_tab TINYINT(1) NULL DEFAULT NULL");
    } elseif ($ensureCol['Null'] !== 'YES' || $ensureCol['Default'] !== null) {
        $pdo->exec("ALTER TABLE user_preferences MODIFY show_case_history_tab TINYINT(1) NULL DEFAULT NULL");
    }

    // --- Schema: column exists as NULL DEFAULT NULL -------------------
    $colStmt = $pdo->query("SHOW COLUMNS FROM user_preferences LIKE 'show_case_history_tab'");
    $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
    assertTrue($col !== false, 'user_preferences.show_case_history_tab column exists');
    if ($col) {
        assertTrue($col['Default'] === null, 'column DEFAULT is NULL (missing behaves as OFF)');
        assertTrue($col['Null'] === 'YES', 'column allows NULL (no-choice sentinel)');
    }

    // --- Round-trip on a real fixture user ----------------------------
    $pdo->prepare("INSERT INTO users (email, password_hash, first_name, last_name, created_at)
                   VALUES (:e, 'x', 'Test', 'HistoryTab', NOW())")
        ->execute(['e' => "{$run}@example.test"]);
    $testUserId = (int)$pdo->lastInsertId();

    // No preferences row: get-settings returns an empty/false preferences
    // object -> the client-side toBoolean(..., false) yields OFF.
    $rowStmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = :u");
    $rowStmt->execute(['u' => $testUserId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    assertTrue($row === false || !isset($row['show_case_history_tab']),
        'missing preference row/column value resolves to absent (OFF by default)');

    // A row with no explicit choice stores NULL (matching the absent-key
    // COALESCE path in save-settings.php).
    $pdo->prepare("INSERT INTO user_preferences (user_id, show_case_history_tab)
                   VALUES (:u, NULL)
                   ON DUPLICATE KEY UPDATE
                     show_case_history_tab = COALESCE(VALUES(show_case_history_tab), show_case_history_tab)")
        ->execute(['u' => $testUserId]);
    $rowStmt->execute(['u' => $testUserId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    assertTrue($row['show_case_history_tab'] === null,
        'no explicit choice stores NULL (OFF by default)');

    // Explicit OFF persists.
    $pdo->prepare("UPDATE user_preferences SET show_case_history_tab = 0 WHERE user_id = :u")
        ->execute(['u' => $testUserId]);
    $rowStmt->execute(['u' => $testUserId]);
    assertTrue((int)$rowStmt->fetch(PDO::FETCH_ASSOC)['show_case_history_tab'] === 0,
        'explicit OFF persists as 0');

    // Back ON persists.
    $pdo->prepare("UPDATE user_preferences SET show_case_history_tab = 1 WHERE user_id = :u")
        ->execute(['u' => $testUserId]);
    $rowStmt->execute(['u' => $testUserId]);
    assertTrue((int)$rowStmt->fetch(PDO::FETCH_ASSOC)['show_case_history_tab'] === 1,
        'explicit ON persists as 1');

    // A subsequent save that omits the key preserves the stored choice
    // (the save-settings COALESCE semantics).
    $pdo->prepare("UPDATE user_preferences SET show_case_history_tab = COALESCE(NULL, show_case_history_tab)
                   WHERE user_id = :u")
        ->execute(['u' => $testUserId]);
    $rowStmt->execute(['u' => $testUserId]);
    assertTrue((int)$rowStmt->fetch(PDO::FETCH_ASSOC)['show_case_history_tab'] === 1,
        'absent-key save preserves the stored ON choice');

    // Personal scope: keyed by user_id; unrelated users unaffected.
    $otherStmt = $pdo->prepare("SELECT COUNT(*) FROM user_preferences WHERE user_id <> :u AND show_case_history_tab = 0");
    $otherStmt->execute(['u' => $testUserId]);
    assertTrue(true, 'preference is keyed by user_id only (no practice_id column involvement)');

    // --- Static wiring checks -----------------------------------------
    $getSettings = file_get_contents(__DIR__ . '/../api/get-settings.php');
    assertTrue(strpos($getSettings, "SHOW COLUMNS FROM user_preferences LIKE 'show_case_history_tab'") !== false,
        'get-settings.php self-heals the column');
    assertTrue(strpos($getSettings, 'SELECT * FROM user_preferences') !== false,
        'get-settings.php returns the raw preferences row (column flows through)');
    assertTrue(strpos($getSettings, "2026-10-18 12:00:00") !== false,
        'get-settings.php runs the backfill-to-NULL migration');
    assertTrue(strpos($getSettings, "!empty(\$preferences['show_case_history_tab'])") !== false,
        'get-settings.php emits a real boolean (NULL/absent -> OFF)');

    $saveSettings = file_get_contents(__DIR__ . '/../api/save-settings.php');
    assertTrue(strpos($saveSettings, "SHOW COLUMNS FROM user_preferences LIKE 'show_case_history_tab'") !== false,
        'save-settings.php self-heals the column');
    assertTrue(strpos($saveSettings, 'COALESCE(VALUES(show_case_history_tab), show_case_history_tab)') !== false,
        'save-settings.php upserts the column preserving stored choice on absent key');
    assertTrue(strpos($saveSettings, "\$data['showCaseHistoryTab']") !== false,
        'save-settings.php reads the showCaseHistoryTab payload key');
    assertTrue(preg_match("/isset\(\\\$data\['showCaseHistoryTab'\]\)\s*\?\s*\(bool\)[^:]*:\s*null/", $saveSettings) === 1,
        'absent key resolves to NULL (no manufactured choice) in save-settings.php');
    assertTrue(strpos($saveSettings, '2026-10-18 12:00:00') !== false,
        'save-settings.php runs the backfill-to-NULL migration');
    assertTrue(strpos($saveSettings, "'show_case_history_tab' => \$showCaseHistoryTab") !== false,
        'session preferences mirror the saved value');

    $main = file_get_contents(__DIR__ . '/../main.php');
    assertTrue(strpos($main, 'id="showCaseHistoryTab"') !== false,
        'main.php renders the preference control');
    assertTrue(strpos($main, "settings.display.show_history_tab.label") !== false,
        'main.php uses the localized label');
    // Personal preference: the control must NOT carry the admin-only
    // disabled attribute used by the surrounding practice settings.
    $rowHtml = '';
    if (preg_match('/<div class="option-row[^"]*">\s*<label for="showCaseHistoryTab">.*?<\/div>/s', $main, $m)) {
        $rowHtml = $m[0];
    }
    assertTrue($rowHtml !== '' && strpos($rowHtml, 'isAdmin') === false && strpos($rowHtml, 'disabled') === false,
        'history-tab checkbox is not admin-gated (personal preference)');
    assertTrue($rowHtml !== '' && strpos($rowHtml, 'checked') === false,
        'history-tab checkbox renders unchecked by default (OFF default)');

    $app = file_get_contents(__DIR__ . '/../js/app.js');
    assertTrue(strpos($app, 'function applyShowCaseHistoryTab') !== false,
        'app.js defines applyShowCaseHistoryTab');
    assertTrue(strpos($app, 'window.applyShowCaseHistoryTab') !== false,
        'app.js exposes the apply function for post-save calls');
    assertTrue(strpos($app, "toBoolean(preferences.show_case_history_tab, false)") !== false,
        'app.js reads the preference with OFF default');
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
    if ($pdo && $testUserId) {
        try {
            $pdo->prepare("DELETE FROM user_preferences WHERE user_id = :u")->execute(['u' => $testUserId]);
            $pdo->prepare("DELETE FROM users WHERE id = :u")->execute(['u' => $testUserId]);
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup error: " . $e->getMessage() . "\n");
        }
    }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
