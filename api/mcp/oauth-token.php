<?php
/** OAuth2 token endpoint: authorization_code + PKCE, refresh_token grant. */
require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../practice-security.php';
global $pdo;
header('Content-Type: application/json');
function tokErr(string $e): void { http_response_code(400); echo json_encode(['error'=>$e]); exit; }

$grant = (string)($_POST['grant_type'] ?? '');
$issue = function (int $userId, string $clientId) use ($pdo) {
    $at = bin2hex(random_bytes(32)); $rt = bin2hex(random_bytes(32));
    $uid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $pdo->prepare("INSERT INTO mcp_access_tokens (id,user_id,client_id,token_hash,expires_at) VALUES (:id,:u,:c,:h,DATE_ADD(NOW(),INTERVAL 1 HOUR))")
        ->execute([':id'=>$uid,':u'=>$userId,':c'=>$clientId,':h'=>hash('sha256',$at)]);
    $rid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    $pdo->prepare("INSERT INTO mcp_refresh_tokens (id,user_id,client_id,token_hash,expires_at) VALUES (:id,:u,:c,:h,DATE_ADD(NOW(),INTERVAL 90 DAY))")
        ->execute([':id'=>$rid,':u'=>$userId,':c'=>$clientId,':h'=>hash('sha256',$rt)]);
    echo json_encode(['access_token'=>$at,'token_type'=>'bearer','expires_in'=>3600,'refresh_token'=>$rt]);
    exit;
};

if ($grant === 'authorization_code') {
    $code = (string)($_POST['code'] ?? '');
    $verifier = (string)($_POST['code_verifier'] ?? '');
    $row = $pdo->prepare("SELECT * FROM mcp_auth_codes WHERE code_hash=:h AND consumed_at IS NULL AND expires_at>NOW() LIMIT 1");
    $row->execute([':h'=>hash('sha256',$code)]);
    $c = $row->fetch(PDO::FETCH_ASSOC);
    $verifierOk = $c && rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=') === $c['pkce_challenge'];
    $redirectOk = $c && ($c['redirect_uri'] === (string)($_POST['redirect_uri'] ?? $c['redirect_uri']));
    if (!$verifierOk || !$redirectOk) tokErr('invalid_grant');
    $pdo->prepare("UPDATE mcp_auth_codes SET consumed_at=NOW() WHERE id=:id")->execute([':id'=>$c['id']]);
    $issue((int)$c['user_id'], (string)$c['client_id']);
} elseif ($grant === 'refresh_token') {
    $rt = (string)($_POST['refresh_token'] ?? '');
    $row = $pdo->prepare("SELECT * FROM mcp_refresh_tokens WHERE token_hash=:h AND revoked_at IS NULL AND expires_at>NOW() LIMIT 1");
    $row->execute([':h'=>hash('sha256',$rt)]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r) tokErr('invalid_grant');
    $issue((int)$r['user_id'], (string)$r['client_id']);
}
tokErr('unsupported_grant_type');
