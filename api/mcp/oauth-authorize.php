<?php
/**
 * Minimal OAuth2 authorization endpoint for the private DentaTrak MCP
 * connector. Requires an authenticated DentaTrak session AND superuser
 * - the connector can only ever be authorized to the admin's account.
 * GET ?client_id&redirect_uri&state&code_challenge&code_challenge_method
 * -> auto-consent for the logged-in superuser -> 302 redirect with code.
 */
require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../practice-security.php';
require_once __DIR__ . '/../dev-tools-access.php';
global $pdo;

function mcpAuthFail(int $c, string $m): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode(['error'=>$m]); exit; }

if (empty($_SESSION['db_user_id'])) { header('Location: /login.php'); exit; }
$userId = (int)$_SESSION['db_user_id'];
if (!isSuperUser((string)($_SESSION['user_email'] ?? ''))) mcpAuthFail(403, 'forbidden');

$clientId = (string)($_GET['client_id'] ?? '');
$redirect = (string)($_GET['redirect_uri'] ?? '');
$state = (string)($_GET['state'] ?? '');
$challenge = (string)($_GET['code_challenge'] ?? '');
if ($clientId === '' || $redirect === '' || $challenge === '') mcpAuthFail(400, 'invalid_request');
// Registered clients only (RFC 7591 DCR or manually provisioned), exact
// redirect_uri match against the registered set.
$cl = $pdo->prepare("SELECT redirect_uris FROM mcp_clients WHERE client_id=:c LIMIT 1");
$cl->execute([':c'=>$clientId]);
$row = $cl->fetch(PDO::FETCH_ASSOC);
if (!$row || !in_array($redirect, json_decode($row['redirect_uris'], true) ?: [], true)) {
    mcpAuthFail(400, 'unregistered client or redirect_uri');
}

$code = bin2hex(random_bytes(24));
$id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
$pdo->prepare("INSERT INTO mcp_auth_codes (id,user_id,client_id,redirect_uri,code_hash,pkce_challenge,pkce_method,expires_at)
    VALUES (:id,:u,:c,:r,:h,:p,'S256',DATE_ADD(NOW(),INTERVAL 10 MINUTE))")
    ->execute([':id'=>$id,':u'=>$userId,':c'=>$clientId,':r'=>$redirect,':h'=>hash('sha256',$code),':p'=>$challenge]);
$sep = strpos($redirect,'?')===false ? '?' : '&';
header('Location: '.$redirect.$sep.'code='.$code.'&state='.rawurlencode($state));
exit;
