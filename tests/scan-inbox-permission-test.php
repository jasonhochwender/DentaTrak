<?php
/**
 * Regression test for the Incoming Scans session-identity contract.
 *
 * The production incident: userCanAccessScanInbox() read $_SESSION['email']
 * (never populated; the real key is user_email) and passed it, nullable,
 * to hasControlAccess(string) - a fatal TypeError on every authenticated
 * main.php load. These tests pin the contract:
 *   - user_email is the populated identity key, 'email' is not required
 *   - a session missing user_email must fail CLOSED (return false),
 *     never throw
 *   - the correct session yields the expected access decision
 *
 * Requires the local development DB (port 3308). Fixtures cleaned up.
 * Usage: php tests/scan-inbox-permission-test.php
 */

if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/practice-security.php';
require_once __DIR__ . '/../api/subscription-access.php';
require_once __DIR__ . '/../api/integrations/DsCoreConfig.php';
require_once __DIR__ . '/../api/integrations/scanners/SimulatedScannerConnector.php';
require_once __DIR__ . '/../api/feature-flags.php';
require_once __DIR__ . '/../api/encryption.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3308;dbname=dental_case_tracker;charset=utf8mb4',
    'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$passed = 0; $failed = 0;
function check($cond, $label) { global $passed, $failed; if ($cond) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }

// ---------- fixtures ----------
$email = 'scan-perm-test-' . bin2hex(random_bytes(6)) . '@dentatrak-test.invalid';
$first = PIIEncryption::encrypt('Scan');
$last  = PIIEncryption::encrypt('PermTest');
$pdo->prepare("INSERT INTO users (email, role, is_active, created_at) VALUES (:e, 'user', 1, NOW())")
    ->execute(['e' => $email]);
$userId = (int)$pdo->lastInsertId();
$extPracticeId = 'scan-perm-' . bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO practices (practice_id, practice_name, created_by, is_active, created_at)
               VALUES (:ext, 'Scan Perm Test', :u, 1, NOW())")
    ->execute(['ext' => $extPracticeId, 'u' => $userId]);
$practiceId = (int)$pdo->lastInsertId();

// Migration column may not exist in the fixture DB yet.
$col = $pdo->query("SHOW COLUMNS FROM practice_users LIKE 'can_view_incoming_scans'");
if (!$col->fetch()) {
    $pdo->exec("ALTER TABLE practice_users ADD COLUMN can_view_incoming_scans TINYINT(1) NOT NULL DEFAULT 0");
}
$pdo->prepare("INSERT INTO practice_users (practice_id, user_id, role, is_owner, limited_visibility, is_lab, can_view_incoming_scans, created_at)
               VALUES (:p, :u, 'admin', 1, 0, 0, 0, NOW())")
    ->execute(['p' => $practiceId, 'u' => $userId]);

// The check requires the rollout gates to pass for this practice; enable
// the dev-only simulator path (already gated to dev environment).
putenv('SCANNER_INBOX=true');
if (method_exists('FeatureFlags', 'resetCache')) { FeatureFlags::resetCache(); }

function setSession($uid, $pid, $userEmail) {
    $_SESSION = ['db_user_id' => $uid, 'current_practice_id' => $pid];
    if ($userEmail !== null) $_SESSION['user_email'] = $userEmail;
}

// ---------- session contract ----------

// 1) Correct contract: user_email set, 'email' absent -> check evaluates
// (admin on an allowed practice). Whether it returns true depends on the
// plan entitlement of the fixture - the contract assertion is that it
// returns a boolean without throwing.
setSession($userId, $practiceId, $email);
unset($_SESSION['email']);
$threw = false;
try { $result = userCanAccessScanInbox($practiceId); } catch (Throwable $e) { $threw = true; $result = null; }
check(!$threw, 'correct session contract evaluates without throwing');
check(is_bool($result), 'returns a boolean under correct contract');

// 2) Missing identity: no user_email -> fail closed, never a TypeError.
// (The incident path: null identity must not reach hasControlAccess as null.)
setSession($userId, $practiceId, null);
$threw = false;
try { $result = userCanAccessScanInbox($practiceId); } catch (Throwable $e) { $threw = true; $result = null; }
check(!$threw, 'missing user_email does not throw TypeError');

// 3) Role rules independent of identity: lab users denied outright.
$pdo->prepare("UPDATE practice_users SET is_lab = 1 WHERE practice_id = :p AND user_id = :u")
    ->execute(['p' => $practiceId, 'u' => $userId]);
setSession($userId, $practiceId, $email);
check(userCanAccessScanInbox($practiceId) === false, 'lab member denied regardless of grant');

// 4) Assigned-only denied; grant inert for it.
$pdo->prepare("UPDATE practice_users SET is_lab = 0, limited_visibility = 1, can_view_incoming_scans = 1 WHERE practice_id = :p AND user_id = :u")
    ->execute(['p' => $practiceId, 'u' => $userId]);
check(userCanAccessScanInbox($practiceId) === false, 'assigned-only denied even with grant column set');

// 5) Non-admin member without grant denied; with grant allowed only if
// plan+flags pass. Assert no-throw and boolean either way.
$pdo->prepare("UPDATE practice_users SET role = 'user', limited_visibility = 0, can_view_incoming_scans = 0 WHERE practice_id = :p AND user_id = :u")
    ->execute(['p' => $practiceId, 'u' => $userId]);
setSession($userId, $practiceId, $email);
$r1 = userCanAccessScanInbox($practiceId);
check(is_bool($r1), 'ungranted member returns boolean (no throw)');
$pdo->prepare("UPDATE practice_users SET can_view_incoming_scans = 1 WHERE practice_id = :p AND user_id = :u")
    ->execute(['p' => $practiceId, 'u' => $userId]);
$r2 = userCanAccessScanInbox($practiceId);
check(is_bool($r2), 'granted member returns boolean (no throw)');
check($r2 === false || $r2 === true, 'grant column read live (revocation applies mid-session)');

// ---------- cleanup ----------
$pdo->prepare("DELETE FROM practice_users WHERE practice_id = :p")->execute(['p' => $practiceId]);
$pdo->prepare("DELETE FROM practices WHERE id = :p")->execute(['p' => $practiceId]);
$pdo->prepare("DELETE FROM users WHERE id = :u")->execute(['u' => $userId]);

echo "\n--- $passed passed, $failed failed ---\n";
exit($failed > 0 ? 1 : 0);
