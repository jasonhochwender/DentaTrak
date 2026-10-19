<?php
/**
 * Practice-Level Display & Behavior Settings
 *
 * Every setting on Settings > Display & Behavior except Appearance (theme)
 * is PRACTICE-WIDE. They are stored on the `practices` row, enforced for
 * every member of the practice, and only writable by practice admins
 * (save-settings.php / admin-practices.php). Appearance (theme) remains a
 * per-user `user_preferences` value and is NOT handled here.
 *
 * Columns on `practices` (all nullable until the practice is migrated):
 *   show_case_history_tab        - Revision History tab visibility (default OFF;
 *                                  history keeps recording regardless)
 *   allow_card_delete            - allow individual case archiving (default ON)
 *   delivered_hide_days          - auto-hide delivered cases after N days
 *                                  (default 0 = never; conservative - the
 *                                  migration NEVER newly activates auto-hiding)
 *   highlight_past_due / past_due_days
 *   highlight_coming_due / coming_due_days
 *   highlight_appointment_risk / appointment_risk_days
 *   display_settings_migrated    - migration sentinel (NOT NULL DEFAULT 0)
 *
 * MIGRATION RULES (deterministic, idempotent - never "whoever saves next"):
 *   1. Authoritative source: the `user_preferences` row of the practice
 *      creator (practices.created_by) - the account whose choices configured
 *      the practice's effective behavior historically.
 *   2. NULL/missing creator values fall back to the column defaults below.
 *      For delivered_hide_days the default is 0 (never auto-hide), so a
 *      practice with no recorded preference keeps auto-archiving OFF.
 *   3. show_case_history_tab: creator 0/1 is honored; NULL or missing -> 0
 *      (OFF), preserving the deliberately-conservative default.
 *   4. Conflict reporting: if OTHER members' stored values differ from the
 *      chosen practice value, the divergence is counted and written to the
 *      PHP error log once per practice per migration. Those values are then
 *      ignored - members' legacy rows are kept intact (they are simply no
 *      longer read for practice-scoped behavior).
 *   5. `display_settings_migrated` is set to 1 after the copy, so the
 *      backfill runs at most once per practice and a later admin save is
 *      never overwritten by a re-run.
 *
 * The legacy `user_preferences` copies of these columns are intentionally
 * left in place (not dropped, not cleared) - they are simply no longer the
 * source of truth for practice-wide behavior.
 */

// Column => [type, default]. Defaults mirror the documented product
// behavior BEFORE this migration; delivered_hide_days defaults to 0 (off).
function practiceDisplaySettingColumns() {
    return [
        'show_case_history_tab'      => ['type' => 'TINYINT(1)', 'default' => 0,    'bool' => true],
        'allow_card_delete'          => ['type' => 'TINYINT(1)', 'default' => 1,    'bool' => true],
        'delivered_hide_days'        => ['type' => 'INT(11)',    'default' => 0,    'bool' => false],
        'highlight_past_due'         => ['type' => 'TINYINT(1)', 'default' => 1,    'bool' => true],
        'past_due_days'              => ['type' => 'INT(11)',    'default' => 1,    'bool' => false],
        'highlight_coming_due'       => ['type' => 'TINYINT(1)', 'default' => 0,    'bool' => true],
        'coming_due_days'            => ['type' => 'INT(11)',    'default' => 5,    'bool' => false],
        'highlight_appointment_risk' => ['type' => 'TINYINT(1)', 'default' => 1,    'bool' => true],
        'appointment_risk_days'      => ['type' => 'INT(11)',    'default' => 3,    'bool' => false],
    ];
}

/**
 * Self-healing schema: add the practices columns + migration sentinel.
 * Follows the same SHOW COLUMNS/ALTER convention used throughout the API.
 */
function ensurePracticeDisplaySettingsSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    foreach (practiceDisplaySettingColumns() as $col => $meta) {
        $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE '{$col}'");
        if (!$stmt || $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE practices ADD COLUMN `{$col}` {$meta['type']} NULL DEFAULT NULL");
        }
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM practices LIKE 'display_settings_migrated'");
    if (!$stmt || $stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE practices ADD COLUMN display_settings_migrated TINYINT(1) NOT NULL DEFAULT 0");
    }

    $done = true;
}

/**
 * One-time per-practice backfill. Deterministic: copies the practice
 * CREATOR's user_preferences values; conflicts with other members' values
 * are reported to the error log and ignored.
 */
function migratePracticeDisplaySettingsIfNeeded(PDO $pdo, int $practiceId): void {
    if ($practiceId <= 0) return;

    $stmt = $pdo->prepare("SELECT created_by, display_settings_migrated FROM practices WHERE id = :id");
    $stmt->execute(['id' => $practiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['display_settings_migrated'] === 1) {
        return;
    }

    $defaults = [];
    foreach (practiceDisplaySettingColumns() as $col => $meta) {
        $defaults[$col] = $meta['default'];
    }

    // Authoritative source: practice creator's stored user_preferences row.
    $creatorValues = $defaults;
    $creatorId = (int)($row['created_by'] ?? 0);
    if ($creatorId > 0) {
        try {
            $prefStmt = $pdo->prepare("
                SELECT show_case_history_tab, allow_card_delete, delivered_hide_days,
                       highlight_past_due, past_due_days, highlight_coming_due,
                       coming_due_days, highlight_appointment_risk, appointment_risk_days
                FROM user_preferences WHERE user_id = :uid
            ");
            $prefStmt->execute(['uid' => $creatorId]);
            $pref = $prefStmt->fetch(PDO::FETCH_ASSOC);
            if ($pref) {
                foreach ($creatorValues as $col => $v) {
                    if (array_key_exists($col, $pref) && $pref[$col] !== null) {
                        $creatorValues[$col] = (int)$pref[$col];
                    }
                }
            }
        } catch (PDOException $e) {
            // Column may not exist yet on older schemas - fall back to defaults.
            error_log('[display-settings-migration] practice ' . $practiceId . ': creator preference read failed, using defaults: ' . $e->getMessage());
        }
    }

    // Conflict reporting: count members whose stored values diverge from the
    // chosen practice value. Reported once (migration runs once); those
    // values are ignored and never overwrite the practice configuration.
    try {
        $membersStmt = $pdo->prepare("
            SELECT up.user_id, up.show_case_history_tab, up.allow_card_delete, up.delivered_hide_days,
                   up.highlight_past_due, up.past_due_days, up.highlight_coming_due,
                   up.coming_due_days, up.highlight_appointment_risk, up.appointment_risk_days
            FROM practice_users pu
            JOIN user_preferences up ON up.user_id = pu.user_id
            WHERE pu.practice_id = :pid AND pu.user_id != :creator
        ");
        $membersStmt->execute(['pid' => $practiceId, 'creator' => $creatorId]);
        $conflicts = [];
        $conflictingUsers = 0;
        while ($m = $membersStmt->fetch(PDO::FETCH_ASSOC)) {
            $userConflicted = false;
            foreach ($creatorValues as $col => $chosen) {
                if (array_key_exists($col, $m) && $m[$col] !== null && (int)$m[$col] !== (int)$chosen) {
                    $conflicts[$col] = ($conflicts[$col] ?? 0) + 1;
                    $userConflicted = true;
                }
            }
            if ($userConflicted) $conflictingUsers++;
        }
        if (!empty($conflicts)) {
            error_log('[display-settings-migration] practice ' . $practiceId
                . ': ' . $conflictingUsers . ' member(s) had divergent personal values; using creator values. Divergences: '
                . json_encode($conflicts));
        }
    } catch (PDOException $e) {
        error_log('[display-settings-migration] practice ' . $practiceId . ': conflict scan failed: ' . $e->getMessage());
    }

    // Persist: concrete values + sentinel. Conditional UPDATE makes a racing
    // second request a no-op (it recomputes the same deterministic values
    // anyway, but avoids a redundant write).
    $set = [];
    $params = [':id' => $practiceId];
    foreach ($creatorValues as $col => $val) {
        $set[] = "`{$col}` = :{$col}";
        $params[":{$col}"] = $val;
    }
    $sql = "UPDATE practices SET " . implode(', ', $set) . ", display_settings_migrated = 1
            WHERE id = :id AND display_settings_migrated = 0";
    $pdo->prepare($sql)->execute($params);
}

/**
 * Resolved display settings for a practice (post-migration). Always returns
 * concrete values; NULL columns fall back to documented defaults.
 */
function getPracticeDisplaySettings(PDO $pdo, int $practiceId): array {
    ensurePracticeDisplaySettingsSchema($pdo);
    migratePracticeDisplaySettingsIfNeeded($pdo, $practiceId);

    $cols = array_keys(practiceDisplaySettingColumns());
    $stmt = $pdo->prepare("SELECT " . implode(', ', $cols) . " FROM practices WHERE id = :id");
    $stmt->execute(['id' => $practiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach (practiceDisplaySettingColumns() as $col => $meta) {
        $val = (array_key_exists($col, $row) && $row[$col] !== null) ? (int)$row[$col] : $meta['default'];
        $out[$col] = $meta['bool'] ? (bool)$val : $val;
    }
    return $out;
}

/**
 * Admin-only write path. Only keys PRESENT in $submitted are written, so a
 * partial save (or a hidden section) can never reset other settings.
 * Maps client camelCase names to practices columns.
 */
function updatePracticeDisplaySettings(PDO $pdo, int $practiceId, array $submitted): void {
    ensurePracticeDisplaySettingsSchema($pdo);
    // Run the deterministic backfill BEFORE applying the explicit write so
    // keys not present in this payload still receive their migrated values
    // (a partial save must not starve un-submitted keys of migration).
    migratePracticeDisplaySettingsIfNeeded($pdo, $practiceId);

    $boolMap = [
        'showCaseHistoryTab' => 'show_case_history_tab',
        'allowCardDelete' => 'allow_card_delete',
        'highlightPastDue' => 'highlight_past_due',
        'highlightComingDue' => 'highlight_coming_due',
        'highlightAppointmentRisk' => 'highlight_appointment_risk',
    ];
    $intMap = [
        'deliveredHideDays' => ['col' => 'delivered_hide_days', 'min' => 0, 'max' => 365],
        'pastDueDays' => ['col' => 'past_due_days', 'min' => 1, 'max' => 99],
        'comingDueDays' => ['col' => 'coming_due_days', 'min' => 1, 'max' => 99],
        'appointmentRiskDays' => ['col' => 'appointment_risk_days', 'min' => 0, 'max' => 99],
    ];

    $set = [];
    $params = [':id' => $practiceId];
    foreach ($boolMap as $key => $col) {
        if (array_key_exists($key, $submitted)) {
            $set[] = "`{$col}` = :{$col}";
            $params[":{$col}"] = !empty($submitted[$key]) ? 1 : 0;
        }
    }
    foreach ($intMap as $key => $meta) {
        if (array_key_exists($key, $submitted)) {
            $col = $meta['col'];
            $v = (int)$submitted[$key];
            $v = max($meta['min'], min($meta['max'], $v));
            $set[] = "`{$col}` = :{$col}";
            $params[":{$col}"] = $v;
        }
    }

    if (empty($set)) return;

    // Any explicit admin write counts as the authoritative value - ensure
    // the migration sentinel is set so a later lazy migration cannot
    // overwrite it with legacy creator defaults.
    $sql = "UPDATE practices SET " . implode(', ', $set) . ", display_settings_migrated = 1 WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}
