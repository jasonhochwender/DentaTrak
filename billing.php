<?php
// Billing page
require_once __DIR__ . '/api/session.php';
require_once __DIR__ . '/api/security-headers.php';
require_once __DIR__ . '/api/practice-security.php';

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

// SECURITY: Billing is an admin-only surface. Non-admins (and anyone
// without a valid practice context) are redirected back to the dashboard
// even if they navigate here directly, matching the hidden Billing links
// in main.php and the admin enforcement already on every billing API
// endpoint (api/billing.php, api/billing-portal.php,
// api/create-checkout-session.php, api/create-portal-session.php).
// getCurrentPracticeId() is used (rather than requireValidPracticeContext())
// because this is an HTML page, not a JSON API endpoint.
$currentPracticeId = getCurrentPracticeId();
if (!$currentPracticeId || !isPracticeAdmin($currentPracticeId)) {
    header('Location: main.php');
    exit;
}

// Practice-wide 2FA enforcement applies to every authenticated practice
// surface, including billing - an unsatisfied session is routed through
// the challenge/enrollment page first.
if (practiceRequires2FA($currentPracticeId) && !session2FASatisfied()) {
    $_SESSION['pending_2fa_practice_id'] = (int)$currentPracticeId;
    unset($_SESSION['current_practice_id']);
    header('Location: 2fa-required.php');
    exit;
}

// ── Billing feature gate ────────────────────────────────────────────────────
// When billing is disabled (the production default until Stripe is fully
// configured), this entire page is unreachable.  Do this before loading
// appConfig.php so no Stripe config is evaluated.
$billingEnabledRaw = getenv('BILLING_ENABLED');
if ($billingEnabledRaw === false) {
    $billingEnabledRaw = $_ENV['BILLING_ENABLED'] ?? '';
}
if (!filter_var($billingEnabledRaw, FILTER_VALIDATE_BOOLEAN)) {
    header('Location: main.php');
    exit;
}

setSecurityHeaders();

// The standalone billing page has been retired in favor of the in-app Billing
// portal modal (js/billing-portal.js on main.php). Keep this file as a
// guarded redirect so existing links, bookmarks, and locale-embedded upgrade
// anchors still land on the real billing experience: the guards above already
// sent unauthorized visitors to main.php, and authorized ones continue into
// the modal via the ?billing=1 deep link.
header('Location: main.php?billing=1', true, 302);
exit;
