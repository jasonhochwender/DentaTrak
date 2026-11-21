<?php
/**
 * Platform settings store + Seasonal Themes seed.
 *
 * platform_settings is a small key/value table for platform-wide
 * configuration that must change without a deployment (same pattern as
 * marketing_settings). 'seasonal_themes' holds the JSON config consumed
 * by api/seasonal-themes.php.
 *
 * Additive and repeatable: guarded by information_schema checks.
 */
require_once __DIR__ . '/../api/appConfig.php';

global $pdo;

$pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (
    sk VARCHAR(64) NOT NULL PRIMARY KEY,
    sval MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "platform_settings ensured\n";

// Seed default seasonal config only when absent - admins can then manage
// it entirely from Platform Administration > Seasonal Themes.
$has = $pdo->prepare("SELECT COUNT(*) FROM platform_settings WHERE sk='seasonal_themes'");
$has->execute();
if ((int)$has->fetchColumn() === 0) {
    require_once __DIR__ . '/../api/seasonal-themes.php';
    $defaults = seasonalThemesDefaultConfig();
    $ins = $pdo->prepare("INSERT INTO platform_settings (sk, sval) VALUES ('seasonal_themes', ?)");
    $ins->execute([json_encode($defaults)]);
    echo "seasonal_themes seeded\n";
} else {
    echo "seasonal_themes already present\n";
}

echo "seasonal themes migration complete\n";
