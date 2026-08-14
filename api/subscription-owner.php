<?php
/**
 * Subscription Owner Resolution
 *
 * A DentaTrak subscription belongs to an account (a user), not to any
 * single practice. The subscription owner for a given practice is the user
 * with practice_users.is_owner = 1 for that practice - set exactly once, at
 * practice-creation time (see accept-baa.php, test-helpers.php), and never
 * granted to an invited member (admin, user, or Assigned Only). All
 * practices owned by the same user share that user's single row in the
 * `subscriptions` table (plan, trial, Stripe customer/subscription,
 * billing cycle).
 *
 * See api/plan-entitlements.php for practice-count limit enforcement built
 * on top of these helpers, and api/migrate-subscription-owner.php for the
 * one-time backfill from the legacy per-practice Stripe columns that still
 * live on `practices` (kept in place, deprecated, until the new model is
 * verified).
 */

require_once __DIR__ . '/practice-trial.php';

/**
 * Resolve the subscription-owner user ID for a given practice.
 *
 * @return int|null null if the practice has no is_owner=1 membership row
 *                   (should not happen for any practice created through
 *                   accept-baa.php or test-helpers.php).
 */
function getSubscriptionOwnerUserId(PDO $pdo, int $practiceId): ?int {
    $stmt = $pdo->prepare("
        SELECT user_id FROM practice_users
        WHERE practice_id = :practice_id AND is_owner = 1
        LIMIT 1
    ");
    $stmt->execute(['practice_id' => $practiceId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Plain, read-only fetch of the owner's subscription row. Returns null if
 * the owner has no subscription row yet (e.g. an owner who has never
 * created a practice through the get-or-create path below, or - before
 * migrate-subscription-owner.php has been run - any pre-existing owner).
 */
function getSubscriptionForOwner(PDO $pdo, int $ownerUserId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE owner_user_id = :owner_user_id LIMIT 1");
    $stmt->execute(['owner_user_id' => $ownerUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Resolve a subscription row directly from a Stripe Customer ID. Used by
 * the webhook handler to find which owner a Stripe event belongs to.
 */
function getSubscriptionByStripeCustomerId(PDO $pdo, string $stripeCustomerId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE stripe_customer_id = :cid LIMIT 1");
    $stmt->execute(['cid' => $stripeCustomerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Get-or-create the owner's subscription row, LOCKED for the current
 * transaction (SELECT ... FOR UPDATE). Must be called inside a transaction
 * already started by the caller (see accept-baa.php's create-practice
 * path).
 *
 * On the first call ever for this owner, this creates the row with a fresh
 * 90-day DentaTrak trial - exactly once per owner, never again. Every
 * subsequent call (this owner creating a 2nd, 3rd, ... practice, or simply
 * checking entitlement) returns the SAME row untouched, so the trial is
 * never restarted or extended and no second trial is ever created.
 *
 * The INSERT ... ON DUPLICATE KEY UPDATE id = id pattern guarantees the row
 * exists and is locked after this call, even if two requests race to create
 * an owner's very first practice at the same time - the second request's
 * INSERT becomes a no-op UPDATE that still takes the row lock, serializing
 * the two requests so they cannot both create "practice #1" and silently
 * exceed the Operate limit of 1.
 */
function getOrCreateSubscriptionForOwner(PDO $pdo, int $ownerUserId): array {
    $trialEndsAt = getNewPracticeTrialEndsAt();

    $pdo->prepare("
        INSERT INTO subscriptions (owner_user_id, status, trial_ends_at, subscription_updated_at)
        VALUES (:owner_user_id, 'trialing', :trial_ends_at, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE id = id
    ")->execute([
        'owner_user_id' => $ownerUserId,
        'trial_ends_at' => $trialEndsAt,
    ]);

    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE owner_user_id = :owner_user_id FOR UPDATE");
    $stmt->execute(['owner_user_id' => $ownerUserId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
