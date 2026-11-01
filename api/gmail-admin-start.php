<?php
/**
 * One-time Gmail send authorization - admin setup only.
 * Requires a logged-in SUPER USER session; issues a one-shot state nonce
 * and redirects to Google consent (gmail.send, offline -> durable
 * refresh token; requires consent screen in Production mode).
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/dev-tools-access.php';
if (empty($_SESSION['db_user_id'])) { http_response_code(403); exit('forbidden'); }
global $pdo;
$u = $pdo->prepare('SELECT email FROM users WHERE id=?'); $u->execute([$_SESSION['db_user_id']]);
$email = (string)$u->fetchColumn();
if ($email === '' || !isSuperUser($appConfig, $email)) { http_response_code(403); exit('forbidden'); }
$nonce = bin2hex(random_bytes(24));
@file_put_contents(sys_get_temp_dir() . '/gmail_setup_nonce', $nonce, LOCK_EX);
$params = [
    'client_id'     => $appConfig['google_client_id'],
    'redirect_uri'  => rtrim($appConfig['baseUrl'], '/') . '/api/google-drive-callback.php',
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/gmail.send',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
    'state'         => 'gmail_setup_' . $nonce,
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;
