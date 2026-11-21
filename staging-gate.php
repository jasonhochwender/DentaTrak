<?php
/**
 * Staging access gate - candidate environment only.
 *
 * Active ONLY when the STAGING_GATE_KEY environment variable is set
 * (dtk-app-candidate Cloud Run service). Production never sets it, so this
 * endpoint is inert there and always returns 404.
 *
 * Flow: an authorized tester visits /staging-gate.php?key=<STAGING_GATE_KEY>.
 * On a match this sets a Secure+HttpOnly SameSite=Lax cookie and redirects
 * to /. Cloud Armor on the candidate backend only passes requests that
 * carry either the X-Dt-Staging-Key header (scripts/CI) or the dt_gate
 * cookie (browsers); everything else gets a 404 before reaching the app.
 */

$gateKey = getenv('STAGING_GATE_KEY');
if ($gateKey === false) {
    $gateKey = $_ENV['STAGING_GATE_KEY'] ?? '';
}

http_response_code(404);
header('Content-Type: text/plain');

if (!is_string($gateKey) || $gateKey === '') {
    echo 'Not found';
    exit;
}

$provided = $_GET['key'] ?? '';
if (!is_string($provided) || !hash_equals($gateKey, $provided)) {
    echo 'Not found';
    exit;
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

setcookie('dt_gate', $gateKey, [
    'expires'  => time() + 12 * 3600,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><title>Staging access granted</title>'
   . '<meta http-equiv="refresh" content="0; url=/">'
   . '<p>Staging access granted. <a href="/">Continue</a></p>';
exit;
