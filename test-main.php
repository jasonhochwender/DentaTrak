<?php
/**
 * Local browser test entry point. Sets a fake authenticated session and
 * renders main.php for direct, cache-free Practice/Lab Insights testing.
 */
putenv('SHOW_BILLING=true');
$_ENV['SHOW_BILLING'] = 'true';

$_COOKIE['PHPSESSID'] = 'dt-insights-browser-test';
require_once __DIR__ . '/api/appConfig.php';

$_SESSION['db_user_id'] = 21;
$_SESSION['user'] = [
    'id' => 21,
    'name' => 'Test Admin',
    'email' => 'pacoletstudios@gmail.com',
    'picture' => ''
];
$_SESSION['current_practice_id'] = 9;
$_SESSION['last_activity'] = time();
$_SESSION['last_regeneration'] = time();

require __DIR__ . '/main.php';
