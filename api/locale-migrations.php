<?php
/**
 * Locale infrastructure migrations
 *
 * Adds `locale` columns non-destructively. Safe to call once per request.
 */

/**
 * Ensure all locale-related columns exist.
 *
 * @return void
 */
function ensureLocaleColumns(): void {
    global $pdo;

    if (!$pdo) {
        return;
    }

    $tables = [
        [
            'table' => 'users',
            'column' => 'locale',
            'definition' => 'VARCHAR(35) DEFAULT NULL',
            'add' => "ALTER TABLE users ADD COLUMN locale VARCHAR(35) DEFAULT NULL",
        ],
        [
            'table' => 'practices',
            'column' => 'default_locale',
            'definition' => "VARCHAR(35) DEFAULT 'en-US' NOT NULL",
            'add' => "ALTER TABLE practices ADD COLUMN default_locale VARCHAR(35) DEFAULT 'en-US' NOT NULL",
        ],
        // user_preferences.locale is retained for backward compatibility but is not authoritative.
        [
            'table' => 'user_preferences',
            'column' => 'locale',
            'definition' => 'VARCHAR(35) DEFAULT NULL',
            'add' => "ALTER TABLE user_preferences ADD COLUMN locale VARCHAR(35) DEFAULT NULL",
        ],
    ];

    foreach ($tables as $migration) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM {$migration['table']} LIKE '{$migration['column']}'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($migration['add']);
            }
        } catch (PDOException $e) {
            error_log('[locale-migrations] Error ensuring ' . $migration['table'] . '.' . $migration['column'] . ': ' . $e->getMessage());
        }
    }
}
