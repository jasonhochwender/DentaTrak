<?php
/**
 * Scale Add-On Subscription Helpers
 *
 * Small helpers for keeping a Scale subscription's "additional practice"
 * add-on line item quantity in sync with the number of practices an owner
 * actually has. The base Scale price includes 5 practices; each owned
 * practice beyond that requires one add-on unit.
 *
 * This file is loaded only where needed (api/accept-baa.php) to avoid
 * touching unrelated code paths.
 */

require_once __DIR__ . '/stripe-price-map.php';

/**
 * Resolve the active Stripe subscription for an owner. Returns null if
 * there is no subscription row, no Stripe subscription id, or the
 * subscription is not in a manageable state.
 */
function getActiveStripeSubscriptionForOwner(PDO $pdo, int $ownerUserId): ?array {
    $stmt = $pdo->prepare("
        SELECT stripe_subscription_id, billing_interval, plan, status
        FROM subscriptions
        WHERE owner_user_id = :owner_user_id
        LIMIT 1
    ");
    $stmt->execute(['owner_user_id' => $ownerUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $manageableStatuses = ['active', 'trialing', 'past_due', 'unpaid'];
    if (empty($row['stripe_subscription_id']) || !in_array($row['status'], $manageableStatuses, true)) {
        return null;
    }
    return $row;
}

/**
 * Update the owner's active Scale subscription so the add-on line item
 * quantity equals the number of owned practices beyond the included 5.
 *
 * - If no active Scale subscription exists, this is a no-op and returns null.
 * - If the add-on item already exists, its quantity is set to the required
 *   absolute value.
 * - If it does not exist and one is required, a new add-on item is created.
 *
 * Returns the previous add-on quantity (or 0 when there was none) so the
 * caller can attempt to restore it if the local DB transaction later fails.
 *
 * @throws RuntimeException on Stripe/API errors
 */
function syncScaleAddOnQuantity(
    PDO $pdo,
    int $ownerUserId,
    int $ownedPracticeCount,
    array $appConfig
): ?int {
    $sub = getActiveStripeSubscriptionForOwner($pdo, $ownerUserId);
    if (!$sub || $sub['plan'] !== 'scale') {
        return null;
    }

    $secretKey = $appConfig['stripe']['secret_key'] ?? null;
    if (empty($secretKey)) {
        throw new RuntimeException('Stripe secret key not configured');
    }
    \Stripe\Stripe::setApiKey($secretKey);

    $requiredQty = max(0, $ownedPracticeCount - 5);
    $subId = $sub['stripe_subscription_id'];
    $interval = $sub['billing_interval'] ?? 'month';
    $addOnPriceId = getScaleAdditionalPriceId($interval, $appConfig);
    if ($addOnPriceId === null) {
        throw new RuntimeException("Scale add-on Price ID not configured for interval {$interval}");
    }

    $items = \Stripe\SubscriptionItem::all(['subscription' => $subId, 'limit' => 100]);
    $previousQty = 0;
    $matchingItemId = null;
    foreach ($items->data as $item) {
        if (($item->price->id ?? null) === $addOnPriceId) {
            $matchingItemId = $item->id;
            $previousQty = (int)$item->quantity;
            break;
        }
    }

    if ($requiredQty === 0) {
        // Nothing extra to bill. We intentionally leave an existing add-on
        // alone here to avoid deleting a customer's line item during a
        // downgrade path that is outside this flow's scope.
        return $previousQty;
    }

    if ($matchingItemId !== null) {
        \Stripe\SubscriptionItem::update($matchingItemId, ['quantity' => $requiredQty]);
    } else {
        \Stripe\SubscriptionItem::create([
            'subscription' => $subId,
            'price'        => $addOnPriceId,
            'quantity'     => $requiredQty,
        ]);
    }

    return $previousQty;
}

/**
 * Attempt to restore the Scale add-on quantity to a previous value. This
 * is a compensation call used when the DB transaction that triggered the
 * add-on change failed to commit.
 *
 * If the previous quantity was 0, any add-on line item is removed; if it
 * was >0, the item's quantity is set back to that value.
 *
 * Errors are logged, not thrown, to avoid shadowing the original failure.
 */
function restoreScaleAddOnQuantity(
    PDO $pdo,
    int $ownerUserId,
    int $previousQuantity,
    array $appConfig
): void {
    $sub = getActiveStripeSubscriptionForOwner($pdo, $ownerUserId);
    if (!$sub || $sub['plan'] !== 'scale') {
        return;
    }

    $secretKey = $appConfig['stripe']['secret_key'] ?? null;
    if (empty($secretKey)) {
        error_log('[scale-subscription-addons] Cannot restore add-on: no secret key');
        return;
    }
    \Stripe\Stripe::setApiKey($secretKey);

    $subId = $sub['stripe_subscription_id'];
    $interval = $sub['billing_interval'] ?? 'month';
    $addOnPriceId = getScaleAdditionalPriceId($interval, $appConfig);
    if ($addOnPriceId === null) {
        error_log('[scale-subscription-addons] Cannot restore add-on: missing add-on Price ID');
        return;
    }

    $items = \Stripe\SubscriptionItem::all(['subscription' => $subId, 'limit' => 100]);
    $matchingItemId = null;
    foreach ($items->data as $item) {
        if (($item->price->id ?? null) === $addOnPriceId) {
            $matchingItemId = $item->id;
            break;
        }
    }

    if ($matchingItemId === null) {
        error_log('[scale-subscription-addons] No add-on item found to restore for subscription ' . $subId);
        return;
    }

    try {
        if ($previousQuantity <= 0) {
            \Stripe\SubscriptionItem::delete($matchingItemId);
        } else {
            \Stripe\SubscriptionItem::update($matchingItemId, ['quantity' => $previousQuantity]);
        }
    } catch (\Exception $e) {
        error_log('[scale-subscription-addons] Restore failed for owner ' . $ownerUserId . ': ' . $e->getMessage());
    }
}
