<?php
/**
 * Marketing unsubscribe — branded confirm page.
 * GET renders confirmation only (link scanners/previews never unsubscribe).
 * POST commits suppression in the authoritative store.
 */
require_once __DIR__ . '/api/appConfig.php';
require_once __DIR__ . '/api/marketing/SuppressionService.php';

$svc  = new SuppressionService($pdo);
$tok  = $_REQUEST['t'] ?? '';
$email = $svc->resolveToken((string) $tok);

$state = 'confirm'; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $email) {
    $res = $svc->suppress($email, 'email_unsubscribe');
    $done = true; $state = 'done';
    // Persist token use quietly; repeated unsubscribes are idempotent.
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $state = 'invalid';
} elseif (!$email) {
    $state = 'invalid';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unsubscribe — DentaTrak</title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f7f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;max-width:420px;padding:32px;text-align:center}
h1{font-size:1.15rem;color:#1e3a5f;margin:0 0 10px}p{color:#4b5563;font-size:.92rem;line-height:1.5}
.btn{display:inline-block;margin-top:14px;background:#2563eb;color:#fff;border:0;border-radius:9px;padding:11px 26px;font-size:.95rem;font-weight:700;cursor:pointer}
.muted{color:#9ca3af;font-size:.8rem;margin-top:18px}</style></head><body><div class="card">
<?php if ($state === 'done'): ?>
<h1>You're unsubscribed</h1><p><?php echo htmlspecialchars($email); ?> will no longer receive DentaTrak marketing emails.</p>
<?php elseif ($state === 'confirm'): ?>
<h1>Unsubscribe from DentaTrak emails</h1>
<p>Confirm that <strong><?php echo htmlspecialchars($email); ?></strong> should stop receiving marketing emails from DentaTrak.</p>
<form method="POST"><input type="hidden" name="t" value="<?php echo htmlspecialchars($tok); ?>"><button class="btn" type="submit">Confirm unsubscribe</button></form>
<?php else: ?>
<h1>Link unavailable</h1><p>This unsubscribe link is invalid or has expired. If you still receive emails, reply with "unsubscribe" and we'll remove you.</p>
<?php endif; ?>
<p class="muted">DentaTrak · PO Box 1 · Tryon, NC 28782</p>
</div></body></html>
