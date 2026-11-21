<?php
/** OAuth2 token endpoint: authorization_code + PKCE, refresh_token grant.
 *
 * Refresh tokens rotate on every use (OAuth 2.1 BCP): the presented
 * token is revoked and replaced atomically, and all descendants share a
 * family_id rooted at the original grant. Reuse of an already-rotated
 * token after a short leeway window is treated as replay and revokes
 * the entire family (refresh + access tokens). The leeway absorbs
 * benign concurrent refreshes; a stolen token reused later still trips
 * detection. Legacy tokens (family_id NULL -> own id) keep working.
 */
require_once __DIR__ . '/../appConfig.php';
require_once __DIR__ . '/../practice-security.php';
global $pdo;
header('Content-Type: application/json');
function tokErr(string $e): void { http_response_code(400); echo json_encode(['error'=>$e]); exit; }

$uuid = function (): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
};

$issue = function (int $userId, string $clientId, ?string $familyId = null) use ($pdo, $uuid) {
    $at = bin2hex(random_bytes(32)); $rt = bin2hex(random_bytes(32));
    $rid = $uuid();
    // A fresh grant roots its own family at the new refresh-token id.
    $fam = $familyId ?? $rid;
    $aid = $uuid();
    $pdo->prepare("INSERT INTO mcp_access_tokens (id,user_id,client_id,family_id,token_hash,expires_at) VALUES (:id,:u,:c,:f,:h,DATE_ADD(NOW(),INTERVAL 1 HOUR))")
        ->execute([':id'=>$aid,':u'=>$userId,':c'=>$clientId,':f'=>$fam,':h'=>hash('sha256',$at)]);
    $pdo->prepare("INSERT INTO mcp_refresh_tokens (id,user_id,client_id,family_id,token_hash,expires_at) VALUES (:id,:u,:c,:f,:h,DATE_ADD(NOW(),INTERVAL 90 DAY))")
        ->execute([':id'=>$rid,':u'=>$userId,':c'=>$clientId,':f'=>$fam,':h'=>hash('sha256',$rt)]);
    echo json_encode(['access_token'=>$at,'token_type'=>'bearer','expires_in'=>3600,'refresh_token'=>$rt]);
    exit;
};

if ($grant === 'authorization_code') {
    $code = (string)($_POST['code'] ?? '');
    $verifier = (string)($_POST['code_verifier'] ?? '');
    $row = $pdo->prepare("SELECT * FROM mcp_auth_codes WHERE code_hash=:h AND consumed_at IS NULL AND expires_at>NOW() LIMIT 1");
    $row->execute([':h'=>hash('sha256',$code)]);
    $c = $row->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        $postedClient = (string)($_POST['client_id'] ?? '');
        if ($postedClient !== '' && $postedClient !== $c['client_id']) tokErr('invalid_grant');
        $cl = $pdo->prepare("SELECT secret_hash FROM mcp_clients WHERE client_id=:c"); $cl->execute([':c'=>$c['client_id']]);
        $sec = $cl->fetch(PDO::FETCH_ASSOC);
        if ($sec && $sec['secret_hash'] && hash('sha256',(string)($_POST['client_secret'] ?? '')) !== $sec['secret_hash']) tokErr('invalid_client');
    }
    $verifierOk = $c && rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=') === $c['pkce_challenge'];
    $redirectOk = $c && ($c['redirect_uri'] === (string)($_POST['redirect_uri'] ?? $c['redirect_uri']));
    if (!$verifierOk || !$redirectOk) tokErr('invalid_grant');
    $pdo->prepare("UPDATE mcp_auth_codes SET consumed_at=NOW() WHERE id=:id")->execute([':id'=>$c['id']]);
    $issue((int)$c['user_id'], (string)$c['client_id']);
} elseif ($grant === 'refresh_token') {
    $rt = (string)($_POST['refresh_token'] ?? '');
    // Fetch regardless of revocation state: replay detection needs to see
    // already-rotated tokens, not just unknown/expired ones.
    $row = $pdo->prepare("SELECT * FROM mcp_refresh_tokens WHERE token_hash=:h LIMIT 1");
    $row->execute([':h'=>hash('sha256',$rt)]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r || strtotime((string)$r['expires_at']) <= time()) tokErr('invalid_grant');

    if ($r['revoked_at'] !== null) {
        $leeway = (int)(getenv('MCP_REFRESH_REUSE_LEEWAY_SEC') ?: 30);
        $age = time() - strtotime((string)($r['rotated_at'] ?? $r['revoked_at']));
        if (!empty($r['rotated_to']) && $age >= $leeway) {
            // Replay of a rotated token: assume compromise and revoke the
            // whole grant family so no descendant can keep refreshing.
            $fam = (string)($r['family_id'] ?? '') !== '' ? (string)$r['family_id'] : (string)$r['id'];
            $pdo->prepare("UPDATE mcp_refresh_tokens SET revoked_at=NOW() WHERE family_id=? AND revoked_at IS NULL")->execute([$fam]);
            try {
                $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at=NOW() WHERE family_id=? AND revoked_at IS NULL")->execute([$fam]);
            } catch (Throwable $e) { /* family_id column absent on unmigrated installs */ }
            error_log('[mcp-oauth] refresh-token replay detected; family revoked (user_id=' . (int)$r['user_id'] . ')');
        }
        tokErr('invalid_grant');
    }

    // Atomic rotation claim: exactly one concurrent refresh wins.
    $at = bin2hex(random_bytes(32)); $newRt = bin2hex(random_bytes(32));
    $newRid = $uuid();
    $fam = (string)($r['family_id'] ?? '') !== '' ? (string)$r['family_id'] : (string)$r['id'];
    $claim = $pdo->prepare(
        "UPDATE mcp_refresh_tokens SET revoked_at=NOW(), rotated_to=?, rotated_at=NOW()
         WHERE id=? AND revoked_at IS NULL AND expires_at>NOW()"
    );
    $claim->execute([$newRid, $r['id']]);
    if ($claim->rowCount() !== 1) {
        // Lost a concurrent rotation race on the same token; the winner's
        // rotation just happened, so this is inside the leeway window and
        // must not trigger family revocation.
        tokErr('invalid_grant');
    }
    $aid = $uuid();
    $pdo->prepare("INSERT INTO mcp_access_tokens (id,user_id,client_id,family_id,token_hash,expires_at) VALUES (:id,:u,:c,:f,:h,DATE_ADD(NOW(),INTERVAL 1 HOUR))")
        ->execute([':id'=>$aid,':u'=>(int)$r['user_id'],':c'=>(string)$r['client_id'],':f'=>$fam,':h'=>hash('sha256',$at)]);
    $pdo->prepare("INSERT INTO mcp_refresh_tokens (id,user_id,client_id,family_id,token_hash,expires_at) VALUES (:id,:u,:c,:f,:h,DATE_ADD(NOW(),INTERVAL 90 DAY))")
        ->execute([':id'=>$newRid,':u'=>(int)$r['user_id'],':c'=>(string)$r['client_id'],':f'=>$fam,':h'=>hash('sha256',$newRt)]);
    echo json_encode(['access_token'=>$at,'token_type'=>'bearer','expires_in'=>3600,'refresh_token'=>$newRt]);
    exit;
}
tokErr('unsupported_grant_type');
