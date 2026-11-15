<?php
/**
 * Announcement role targeting.
 *
 * Adds announcements.visible_to ('all' | 'admin') so platform admins can
 * restrict a notice to users with the administrator role in the currently
 * selected practice. Independent of `audience` (all practices / FABG
 * practices), so the two dimensions combine freely.
 *
 * Existing rows default to 'all', preserving their current visibility.
 * Idempotent: safe to run multiple times.
 */
if (PHP_SAPI !== 'cli') exit("cli only\n");
require_once __DIR__ . '/../api/appConfig.php';
global $pdo;

$tbl = $pdo->query("SHOW TABLES LIKE 'announcements'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo "announcements table missing - run 2026_11_25_announcements.php first\n";
    exit(1);
}
$check = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'visible_to'");
if ($check && $check->rowCount() === 0) {
    $pdo->exec("ALTER TABLE announcements
        ADD COLUMN visible_to VARCHAR(20) NOT NULL DEFAULT 'all' AFTER audience");
    echo "visible_to column added\n";
} else {
    echo "visible_to column already present\n";
}
echo "done\n";
