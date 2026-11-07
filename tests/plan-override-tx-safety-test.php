<?php
/**
 * Transaction-safety regression test for lazy schema helpers.
 *
 * Bug (production, 2026-10-20 / 11-03 / 11-07): accept-baa.php opened a
 * transaction and then evaluatePracticeCreationEntitlement() ->
 * getEffectivePlanForOwner() -> getPlanOverrideForOwner() ->
 * ensurePlanOverridesSchema() ran CREATE TABLE IF NOT EXISTS inside that
 * transaction. MySQL implicitly commits on DDL, so the transaction was
 * silently committed mid-request: every INSERT autocommitted, the final
 * commit() threw "There is no active transaction" (HTTP 500), and the
 * post-commit organization_type UPDATEs never ran - leaving new practices
 * and users with organization_type = NULL despite a "successful" BAA.
 *
 * This test MUST run in a fresh process where ensurePlanOverridesSchema's
 * static $ensured flag has not already been set by an earlier call, so the
 * in-transaction path is the first one exercised.
 *
 * Requires the local development database (per AGENTS.md: development
 * environment, MySQL on port 3308).
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

require_once __DIR__ . '/../api/appConfig.php';
require_once __DIR__ . '/../api/plan-overrides.php';

global $pdo, $appConfig;

$pass = 0;
$fail = 0;

function assertTrue(string $message, $condition): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$message}\n"; }
    else { $fail++; echo "FAIL: {$message}\n"; }
}

$env = $appConfig['current_environment'] ?? 'production';
if (!isset($pdo) || !($pdo instanceof PDO) || $env === 'production') {
    echo "SKIP: requires the local development database\n";
    exit(0);
}

// Sanity: no transaction may be open before we begin.
assertTrue('no transaction open before test', !$pdo->inTransaction());

$pdo->beginTransaction();
assertTrue('transaction open after beginTransaction', $pdo->inTransaction());

// First-ever call inside a transaction: must not run DDL (implicit commit).
ensurePlanOverridesSchema($pdo);
assertTrue('transaction survives ensurePlanOverridesSchema inside tx', $pdo->inTransaction());

// The full read path used by evaluatePracticeCreationEntitlement().
getEffectivePlanForOwner($pdo, 0, 'operate', false);
assertTrue('transaction survives getEffectivePlanForOwner inside tx', $pdo->inTransaction());

$pdo->rollBack();
assertTrue('rollBack clean after guarded path', !$pdo->inTransaction());

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
