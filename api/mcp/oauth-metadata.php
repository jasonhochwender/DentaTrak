<?php
/** OAuth discovery metadata: RFC 9728 protected-resource + RFC 8414 AS. */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');
$base = 'https://dentatrak.com';
if (($_GET['doc'] ?? '') === 'as') {
    echo json_encode([
        'issuer' => $base,
        'authorization_endpoint' => $base . '/api/mcp/oauth-authorize.php',
        'token_endpoint' => $base . '/api/mcp/oauth-token.php',
        'registration_endpoint' => $base . '/api/mcp/oauth-register.php',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
        'scopes_supported' => ['marketing', 'offline_access'],
    ]);
} else {
    echo json_encode([
        'resource' => $base . '/api/mcp/server.php',
        'authorization_servers' => [$base],
        'scopes_supported' => ['marketing'],
    ]);
}
