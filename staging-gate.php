<?php
/**
 * Staging access gate - candidate environment only.
 *
 * Active ONLY when the STAGING_GATE_KEY environment variable is set
 * (dtk-app-candidate Cloud Run service). Production never sets it, so this
 * endpoint is inert there and always returns 404.
 *
 * Flow: an authorized tester opens /staging-gate.php, submits the key via
 * POST (the key never appears in a URL, query string, or referrer), and
 * receives a Secure+HttpOnly SameSite=Lax cookie holding a derived token
 * (HMAC of the gate key - the cookie does not expose the master key).
 * Cloud Armor on the candidate backend only passes requests carrying
 * either the X-Dt-Staging-Key header (scripts/CI) or the dt_gate cookie
 * (browsers); everything else gets a 404 before reaching the app.
 */

function stagingGateDeny(): void {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

$gateKey = getenv('STAGING_GATE_KEY');
if ($gateKey === false) {
    $gateKey = $_ENV['STAGING_GATE_KEY'] ?? '';
}

if (!is_string($gateKey) || $gateKey === '') {
    stagingGateDeny();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Staging access</title>'
       . '<form method="post"><input type="password" name="key" placeholder="Staging key" autofocus>'
       . '<button type="submit">Enter</button></form>';
    exit;
}

$provided = $_POST['key'] ?? '';
if (!is_string($provided) || !hash_equals($gateKey, $provided)) {
    stagingGateDeny();
}

$cookieToken = hash_hmac('sha256', 'dt-gate-cookie', $gateKey);
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

setcookie('dt_gate', $cookieToken, [
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
