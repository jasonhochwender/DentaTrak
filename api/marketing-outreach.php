<?php
/**
 * Marketing outreach support API (for the Gmail-draft workflow).
 * Header auth: X-Outreach-Token vs env OUTREACH_API_TOKEN (fail closed).
 *
 *   POST ?action=eligibility  {emails:[...200]}  -> per-address {eligible,reason}
 *   POST ?action=token        {email,campaign?}  -> {unsubscribe_url,already_suppressed}
 *   POST ?action=import_dnc   {emails:[...]}     -> reconciles sheet Do Not Contact into suppression
 */
require_once __DIR__ . '/appConfig.php';
require_once __DIR__ . '/marketing/SuppressionService.php';

header('Content-Type: application/json');
$expected = getEnvVar('OUTREACH_API_TOKEN');
if (!$expected || !hash_equals($expected, (string) ($_SERVER['HTTP_X_OUTREACH_TOKEN'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'forbidden']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$in  = json_decode(file_get_contents('php://input'), true) ?? [];
$svc = new SuppressionService($pdo);
$act = $_GET['action'] ?? '';

switch ($act) {
    case 'eligibility':
        echo json_encode(['results' => $svc->eligibility($in['emails'] ?? [])]); break;
    case 'token':
        $e = SuppressionService::normalizeEmail((string) ($in['email'] ?? ''));
        $supp = $svc->isSuppressed($e);
        $tok = $supp ? null : $svc->issueToken($e, $in['campaign'] ?? null);
        echo json_encode([
            'email' => $e,
            'already_suppressed' => $supp,
            'unsubscribe_url' => $tok ? rtrim($appConfig['baseUrl'], '/') . '/unsubscribe.php?t=' . $tok : null,
        ]); break;
    case 'import_dnc':
        $n = 0;
        foreach (array_slice($in['emails'] ?? [], 0, 500) as $e) {
            if (!$svc->isSuppressed((string) $e)) { $svc->suppress((string) $e, 'sheet_do_not_contact'); $n++; }
        }
        echo json_encode(['imported' => $n]); break;
    default:
        http_response_code(400); echo json_encode(['error' => 'unknown action']);
}
