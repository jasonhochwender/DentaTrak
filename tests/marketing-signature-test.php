<?php
/**
 * MarketingSignature + final-email composeParts tests against the LOCAL
 * development database.
 *
 * composeParts is the single assembly path used by BOTH the send pipeline
 * (buildMime) and the preview_message endpoint, so parity is structural.
 * Uses a placeholder unsubscribe URL - no tokens, no Gmail, no sends.
 *
 * Run: php tests/marketing-signature-test.php
 */

if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/marketing/MarketingMailer.php';
require_once __DIR__ . '/../api/marketing/MarketingSignature.php';

global $pdo;
$envName = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $envName === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(1);
}
try {
    $pdo->query('SELECT sk FROM marketing_settings LIMIT 0');
} catch (Throwable $e) {
    echo "SKIP: marketing signature migration not applied to dev DB\n";
    exit(1);
}

$pass = 0; $fail = 0;
function ok(string $msg, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $msg\n"; }
    else { $fail++; echo "FAIL: $msg\n"; }
}

$PREV = $pdo->prepare('SELECT sval FROM marketing_settings WHERE sk=?');
$PREV->execute([MarketingSignature::KEY]);
$priorRow = $PREV->fetchColumn();
$restore = static function () use ($pdo, $priorRow) {
    if ($priorRow === false) {
        $pdo->prepare('DELETE FROM marketing_settings WHERE sk=?')->execute([MarketingSignature::KEY]);
    } else {
        $pdo->prepare('INSERT INTO marketing_settings (sk,sval) VALUES (?,?)
                        ON DUPLICATE KEY UPDATE sval=VALUES(sval)')
            ->execute([MarketingSignature::KEY, $priorRow]);
    }
};

$mailer = new MarketingMailer($pdo, 'https://dentatrak.test', ['env' => static fn() => null]);
$UNSUB = 'https://dentatrak.test/unsubscribe.php?t=preview';

// ---- defaults ---------------------------------------------------------------
$sig = MarketingSignature::load($pdo);
ok('defaults when unset', $sig['name'] !== '' && $sig['title'] !== '');

// ---- save/load roundtrip ------------------------------------------------------
$saved = MarketingSignature::save($pdo, ['name' => 'Test Sender', 'title' => 'VP Testing', 'detail' => 'test@example.com']);
$sig = MarketingSignature::load($pdo);
ok('save/load roundtrip', $sig['name'] === 'Test Sender' && $sig['title'] === 'VP Testing' && $sig['detail'] === 'test@example.com');
ok('fields capped', strlen(MarketingSignature::save($pdo, ['name' => str_repeat('x', 500)])['name']) <= 120);
$saved = MarketingSignature::save($pdo, ['name' => 'Test Sender', 'title' => 'VP Testing', 'detail' => 'test@example.com']);

// ---- html/text render ---------------------------------------------------------
$h = MarketingSignature::html($saved);
$t = MarketingSignature::text($saved);
ok('html has name+title escaped', strpos($h, 'Test Sender') !== false && strpos($h, 'VP Testing') !== false
    && strpos($h, '<strong>') !== false);
ok('html escapes markup', strpos(MarketingSignature::html(['name' => '<b>x</b>', 'title' => 't', 'detail' => '']) , '<b>x</b>') === false);
ok('text has lines', strpos($t, 'Test Sender') !== false && strpos($t, 'VP Testing') !== false);

// ---- dedup --------------------------------------------------------------------
$bodyWith = '<p>Hi</p><p>Test Sender<br>VP Testing</p>';
ok('contains detects existing signature', MarketingSignature::contains($bodyWith, $saved));
ok('contains false for plain body', !MarketingSignature::contains('<p>Hello there</p>', $saved));
ok('applyToHtml appends', strpos(MarketingSignature::applyToHtml('<p>Hello</p>', $saved), 'Test Sender') !== false);
ok('applyToHtml dedups', MarketingSignature::applyToHtml($bodyWith, $saved) === $bodyWith);

// ---- composeParts: preview/send parity ----------------------------------------
$parts = $mailer->composeParts('<p>Hello &amp; welcome</p>', $UNSUB);
ok('html part has body+signature+footer+placeholder',
    strpos($parts['html'], 'Hello') !== false
    && strpos($parts['html'], 'Test Sender') !== false
    && strpos($parts['html'], 'PO Box 1') !== false
    && strpos($parts['html'], 'Tryon, NC 28782') !== false
    && strpos($parts['html'], 'unsubscribe here') !== false
    && strpos($parts['html'], 't=preview') !== false);
ok('signature before footer', strpos($parts['html'], 'Test Sender') < strpos($parts['html'], 'PO Box 1'));
ok('text part has body+signature+footer+placeholder',
    strpos($parts['text'], 'Hello') !== false
    && strpos($parts['text'], 'Test Sender') !== false
    && strpos($parts['text'], 'VP Testing') !== false
    && strpos($parts['text'], 'Tryon, NC 28782') !== false
    && strpos($parts['text'], $UNSUB) !== false);
ok('text signature before footer', strpos($parts['text'], 'Test Sender') < strpos($parts['text'], 'PO Box 1'));

// ---- dedup end-to-end through composeParts ------------------------------------
$dup = $mailer->composeParts('<p>Body</p><p>Test Sender<br>VP Testing</p>', $UNSUB);
ok('composeParts does not double-sign', substr_count($dup['html'], 'Test Sender') === 1);

// ---- fingerprint feeds approval hash ------------------------------------------
$fp1 = MarketingSignature::fingerprint($saved);
MarketingSignature::save($pdo, ['name' => 'Other', 'title' => 'CTO', 'detail' => '']);
$fp2 = MarketingSignature::fingerprint(MarketingSignature::load($pdo));
ok('fingerprint changes with signature', $fp1 !== $fp2);

// ---- static wiring checks ------------------------------------------------------
$api = file_get_contents(__DIR__ . '/../api/marketing-admin.php');
ok('preview_message action exists', strpos($api, "case 'preview_message'") !== false);
ok('save_signature action exists', strpos($api, "case 'save_signature'") !== false);
ok('content hash binds signature', strpos($api, 'MarketingSignature::fingerprint') !== false);
ok('content hash binds footer template', strpos($api, 'MarketingFooter::html') !== false);
ok('hash covers thread refs', strpos($api, 'thread_references') !== false && strpos($api, 'gmail_thread_id') !== false);
ok('preview uses shared composeParts', strpos($api, 'composeParts') !== false);
$pv = substr($api, strpos($api, "case 'preview_message'"), 4000);
ok('preview block has no token issuance', strpos($pv, 'issueToken') === false);

$restore();
echo "\nmarketing-signature-test: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
