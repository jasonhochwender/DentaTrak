<?php
/**
 * Gated Gmail marketing send. X-Outreach-Token auth (fail closed).
 * POST {to, subject, html, text?, campaign, thread_id?}
 * - eligibility check immediately before send (email + org domain)
 * - per-recipient unsubscribe token + branded footer + RFC 8058 headers
 * - marketing_sends ledger: UNIQUE(campaign,email) = idempotent
 * - delivery requires MARKETING_SENDING=true and an allowlisted recipient
 *   (503 {"status":"sending_disabled"|"recipient_not_allowlisted"} otherwise)
 * - an unknown provider outcome returns 202 {"status":"uncertain"}; it is
 *   reconciled against Gmail before that recipient can be sent again
 *
 * All send mechanics live in api/marketing/MarketingMailer.php, shared
 * with the Platform Administration campaign sender. This endpoint keeps
 * its original response contract for the MCP connector.
 *
 * LIMITATION: only guards sends through THIS endpoint; a manual Gmail
 * "Send" cannot be intercepted - drafts prepared elsewhere must be
 * sent via this path or not at all.
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/marketing/SuppressionService.php';
require_once __DIR__ . '/marketing/MarketingMailer.php';
header('Content-Type: application/json');
$expected = getEnvVar('OUTREACH_API_TOKEN');
if (!$expected || !hash_equals($expected, (string)($_SERVER['HTTP_X_OUTREACH_TOKEN'] ?? ''))) { http_response_code(403); exit('{}'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
global $pdo;
$in  = json_decode(file_get_contents('php://input'), true) ?? [];

$mailer = new MarketingMailer($pdo, (string)($appConfig['baseUrl'] ?? ''));
$res = $mailer->send($in);

// Delivery control (MARKETING_SENDING / MARKETING_SEND_ALLOWLIST) is
// enforced inside MarketingMailer for every caller, this endpoint
// included. A blocked send wrote nothing to the ledger and reached no
// provider; say so explicitly rather than as a generic failure.
if (in_array($res['status'] ?? '', ['sending_disabled', 'recipient_not_allowlisted'], true)) {
    http_response_code(503);
    echo json_encode(['status' => $res['status'], 'error' => $res['status']]);
    exit;
}
// Outcome unknown or another request owns this send: the caller must not
// retry blindly. The ledger row is reconciled before any resend.
if (in_array($res['status'] ?? '', ['uncertain', 'in_progress'], true)) {
    http_response_code(202);
    echo json_encode(['status' => 'uncertain', 'error' => $res['error'] ?? $res['status']]);
    exit;
}

// Preserve the original endpoint's response contract.
if (($res['error'] ?? '') === 'campaign required') {
    http_response_code(400); echo json_encode(['error' => 'campaign required']); exit;
}
if (($res['error'] ?? '') === 'gmail creds unset') {
    http_response_code(500); exit('{"error":"gmail creds unset"}');
}
if (($res['error'] ?? '') === 'oauth failed') {
    http_response_code(502); exit('{"error":"oauth failed"}');
}
if (($res['status'] ?? '') === 'failed') {
    http_response_code($res['http'] ?? 502);
    echo json_encode(['status' => 'failed', 'error' => $res['error'] ?? 'send failed']);
    exit;
}
echo json_encode($res);
