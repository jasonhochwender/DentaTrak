<?php
/**
 * Administrative Plan Overrides
 *
 * Platform administrators (super users - see api/dev-tools-access.php) can
 * change an organization's EFFECTIVE plan from the Practice Administration
 * screen. An override changes feature entitlement and plan quotas only; it
 * is not a Stripe change - the customer's `subscriptions` row, invoices,
 * charges, and billing cycle are never touched.
 *
 * Storage: `admin_plan_overrides`, keyed by the subscription OWNER's user id
 * (the organization boundary - see api/organization-access.php). Because the
 * row hangs off the owner, one override consistently covers every practice
 * the owner owns; there is no per-practice plan state to conflict.
 *
 * Read path: getEffectivePlanForOwner() wraps resolveEffectivePlan()
 * (api/plan-entitlements.php) so every entitlement check that asks "what
 * plan governs this owner" sees the override. Write path: only
 * api/admin-practices.php?action=set_plan_override, which enforces CSRF,
 * super-user access, and writes to admin_audit_log via logAdminAction().
 */

require_once __DIR__ . '/plan-entitlements.php';

/**
 * Create the override table when absent. Follows the same lazy
 * CREATE TABLE IF NOT EXISTS convention as logAdminAction()'s
 * admin_audit_log so no migration step is required on deploy.
 */
function ensurePlanOverridesSchema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_plan_overrides (
            owner_user_id BIGINT UNSIGNED PRIMARY KEY,
            plan VARCHAR(20) NOT NULL,
            reason TEXT NULL,
            set_by_user_id BIGINT UNSIGNED NOT NULL,
            set_by_email VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ensured = true;
    } catch (PDOException $e) {
        error_log('[plan-overrides] Schema ensure failed: ' . $e->getMessage());
    }
}

/**
 * The active override plan for a subscription owner, or null when the
 * organization follows its purchased plan. Only known plan slugs are
 * returned; anything else in storage is ignored (fail closed to the
 * purchased plan).
 *
 * @return string|null 'operate'|'control'|'scale' or null
 */
function getPlanOverrideForOwner(PDO $pdo, int $ownerUserId): ?string {
    if ($ownerUserId <= 0) {
        return null;
    }
    ensurePlanOverridesSchema($pdo);
    try {
        $stmt = $pdo->prepare("SELECT plan FROM admin_plan_overrides WHERE owner_user_id = :owner LIMIT 1");
        $stmt->execute(['owner' => $ownerUserId]);
        $plan = $stmt->fetchColumn();
        if ($plan === false || $plan === null || $plan === '') {
            return null;
        }
        return isKnownPlan((string)$plan) ? (string)$plan : null;
    } catch (PDOException $e) {
        error_log('[plan-overrides] Override lookup failed for owner ' . $ownerUserId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Full override record for a subscription owner (for admin display/audit),
 * or null when no override exists.
 */
function getPlanOverrideInfoForOwner(PDO $pdo, int $ownerUserId): ?array {
    if ($ownerUserId <= 0) {
        return null;
    }
    ensurePlanOverridesSchema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT owner_user_id, plan, reason, set_by_user_id, set_by_email, created_at, updated_at
            FROM admin_plan_overrides
            WHERE owner_user_id = :owner LIMIT 1
        ");
        $stmt->execute(['owner' => $ownerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isKnownPlan((string)$row['plan'])) {
            return null;
        }
        return $row;
    } catch (PDOException $e) {
        error_log('[plan-overrides] Override info lookup failed for owner ' . $ownerUserId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Write or clear an owner's plan override. Pass null for $plan to remove the
 * override and return the organization to its purchased plan. Caller is
 * responsible for authorization, CSRF, and audit logging.
 */
function setPlanOverrideForOwner(PDO $pdo, int $ownerUserId, ?string $plan, int $adminUserId, ?string $adminEmail, string $reason): void {
    if ($ownerUserId <= 0) {
        throw new InvalidArgumentException('owner_user_id is required');
    }
    ensurePlanOverridesSchema($pdo);

    if ($plan === null) {
        $stmt = $pdo->prepare("DELETE FROM admin_plan_overrides WHERE owner_user_id = :owner");
        $stmt->execute(['owner' => $ownerUserId]);
        return;
    }

    if (!isKnownPlan($plan)) {
        throw new InvalidArgumentException('Unknown plan: ' . $plan);
    }

    $stmt = $pdo->prepare("
        INSERT INTO admin_plan_overrides (owner_user_id, plan, reason, set_by_user_id, set_by_email)
        VALUES (:owner, :plan, :reason, :admin_id, :admin_email)
        ON DUPLICATE KEY UPDATE
            plan = VALUES(plan),
            reason = VALUES(reason),
            set_by_user_id = VALUES(set_by_user_id),
            set_by_email = VALUES(set_by_email)
    ");
    $stmt->execute([
        'owner'       => $ownerUserId,
        'plan'        => $plan,
        'reason'      => $reason !== '' ? $reason : null,
        'admin_id'    => $adminUserId,
        'admin_email' => $adminEmail,
    ]);
}

/**
 * The plan that actually governs an owner's entitlements: an admin override
 * when present, otherwise resolveEffectivePlan()'s purchased-plan/bypass/
 * trial-default resolution. This is THE resolver every plan-dependent gate
 * and quota should funnel through.
 */
function getEffectivePlanForOwner(PDO $pdo, int $ownerUserId, ?string $storedPlan, bool $isBypass = false): string {
    $override = getPlanOverrideForOwner($pdo, $ownerUserId);
    if ($override !== null) {
        return $override;
    }
    return resolveEffectivePlan($storedPlan, $isBypass);
}
