<?php
/** RFC 7591 dynamic client registration, restricted to ChatGPT hosts. */
require_once __DIR__ . '/../appConfig.php';
global $pdo;
header('Content-Type: application/json');
$in = json_decode((string)file_get_contents('php://input'), true);
$uris = $in['redirect_uris'] ?? [];
$uris = array_values(array_filter(array_map('strval', $uris), function ($u) {
    return preg_match('#^https://(chatgpt\.com|chat\.openai\.com)/#', $u);
}));
if (!$uris) { http_response_code(400); echo json_encode(['error'=>'invalid_redirect_uris']); exit; }
$clientId = 'mcp-' . bin2hex(random_bytes(12));
$secret = bin2hex(random_bytes(24));
$pdo->prepare("INSERT INTO mcp_clients (client_id,secret_hash,redirect_uris,created_by) VALUES (:c,:s,:r,0)")
    ->execute([':c'=>$clientId,':s'=>hash('sha256',$secret),':r'=>json_encode($uris)]);
echo json_encode([
    'client_id' => $clientId, 'client_secret' => $secret,
    'client_id_issued_at' => time(), 'client_secret_expires_at' => 0,
    'redirect_uris' => $uris, 'token_endpoint_auth_method' => 'client_secret_post',
    'grant_types' => ['authorization_code','refresh_token'], 'response_types' => ['code'],
]);
