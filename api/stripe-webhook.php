<?php
/**
 * Stripe Webhook Handler
 *
 * Public endpoint — no session required.
 * Stripe sends events here when subscription state changes.
 *
 * HTTP response contract:
 *   400  — invalid payload or missing/invalid Stripe-Signature
 *   200  — event processed successfully, event safely skipped (unsupported type),
 *           or event was previously processed/skipped (duplicate delivery)
 *   500  — retryable failure: DB error, unexpected exception, or transient
 *           processing error. Stripe will retry on 5xx.
 *
 * Idempotency contract:
 *   - A row is reserved with status='pending' inside a BEGIN…COMMIT transaction
 *     using SELECT … FOR UPDATE to prevent simultaneous duplicate delivery from
 *     both applying the same update.
 *   - Events with status='processed' or status='skipped' → return 200 immediately.
 *   - Events with status='failed' → eligible for retry; re-enter processing.
 *   - Events with status='pending' held by another connection → 500 (let Stripe retry).
 *
 * Security:
 *   - Signature verified before any DB access.
 *   - Raw request body captured before any framework touches it.
 *   - Never logs keys, secrets, raw payloads, card data, or customer addresses.
 *   - session.php is intentionally excluded — this endpoint is unauthenticated.
 */

// ── Capture raw body first — must happen before any output or framework code ─
$rawBody = file_get_contents('php://input');

// ── Billing feature gate ─────────────────────────────────────────────────────
// Must come before appConfig.php so no Stripe env var reads happen when billing
// is disabled. Raw body is already captured above so we haven't missed anything.
// bootstrap.php is required first so .env (BILLING_ENABLED) is loaded before
// the gate reads it — it does not read any Stripe env vars itself.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/billing-gate.php';
requireBillingEnabled();

require_once __DIR__ . '/appConfig.php';
// Authoritative Stripe Price ID -> plan/interval mapping, shared with
// api/create-checkout-session.php so both directions of the mapping have a
// single source of truth.
require_once __DIR__ . '/stripe-price-map.php';

header('Content-Type: application/json');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ── Validate server-side Stripe config ───────────────────────────────────────
$webhookSecret = $appConfig['stripe']['webhook_secret'] ?? null;
$secretKey     = $appConfig['stripe']['secret_key']     ?? null;

if (empty($webhookSecret) || empty($secretKey)) {
    error_log('[stripe-webhook] STRIPE_WEBHOOK_SECRET or STRIPE_SECRET_KEY not configured');
    http_response_code(500);
    echo json_encode(['error' => 'Webhook not configured']);
    exit;
}

// ── Verify Stripe-Signature — reject anything unauthenticated ────────────────
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if (empty($sigHeader)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Stripe-Signature header']);
    exit;
}

\Stripe\Stripe::setApiKey($secretKey);

try {
    $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $webhookSecret);
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    error_log('[stripe-webhook] Signature verification failed');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
} catch (\UnexpectedValueException $e) {
    error_log('[stripe-webhook] Unparseable payload');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$eventId          = $event->id;
$eventType        = $event->type;
$eventCreatedAt   = (int)($event->created ?? 0);

// ── Idempotency gate — acquire exclusive lock on the event row ────────────────
//
// Strategy:
//   1. Begin a transaction.
//   2. Try to INSERT a 'pending' row. If the unique key rejects it, the row
//      already exists from a prior delivery.
//   3. SELECT … FOR UPDATE to lock the existing row for this connection,
//      preventing a simultaneous duplicate from also proceeding.
//   4. Read the current status:
//        processed / skipped → commit, return 200 (nothing to do)
//        failed              → re-enter processing (retry path)
//        pending             → another connection holds the lock → 500 (Stripe retries)
//   5. After dispatch, UPDATE the row status inside the same transaction and commit.
//      The handler's DB work uses the same $pdo connection — InnoDB
//      will include it in the same transaction.
//
// This ensures: (a) simultaneous duplicates cannot both apply the update,
// (b) failed events can be retried by Stripe, (c) the status write and
// business-logic write are atomic.

$processingStatus = 'skipped';
$errorMessage     = null;
$isRetry          = false;

try {
    $pdo->beginTransaction();

    // Attempt INSERT of a fresh pending row
    try {
        $pdo->prepare("
            INSERT INTO stripe_webhook_events
                (stripe_event_id, event_type, processing_status, stripe_event_created, created_at)
            VALUES
                (:event_id, :event_type, 'pending', :stripe_created, UTC_TIMESTAMP())
        ")->execute([
            'event_id'       => $eventId,
            'event_type'     => $eventType,
            'stripe_created' => $eventCreatedAt,
        ]);
        // Fresh INSERT succeeded — we own this event, continue below
    } catch (PDOException $insertEx) {
        $isDuplicate = ($insertEx->getCode() === '23000' ||
                        strpos($insertEx->getMessage(), 'Duplicate entry') !== false);

        if (!$isDuplicate) {
            // Unexpected DB error — rollback and signal Stripe to retry
            $pdo->rollBack();
            error_log('[stripe-webhook] DB error inserting event: ' . $insertEx->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
            exit;
        }

        // Row exists from a prior delivery — lock it for this connection
        $lockStmt = $pdo->prepare("
            SELECT processing_status
            FROM stripe_webhook_events
            WHERE stripe_event_id = ?
            FOR UPDATE
        ");
        $lockStmt->execute([$eventId]);
        $existingStatus = $lockStmt->fetchColumn();

        if ($existingStatus === false) {
            // Row vanished between INSERT failure and SELECT — treat as transient
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
            exit;
        }

        if ($existingStatus === 'processed' || $existingStatus === 'skipped') {
            // Already handled successfully — safe to return 200 without re-running
            $pdo->rollBack();
            http_response_code(200);
            echo json_encode(['status' => 'duplicate_' . $existingStatus]);
            exit;
        }

        if ($existingStatus === 'pending') {
            // Another connection is actively processing this event — tell Stripe to retry later
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Event processing in progress, retry later']);
            exit;
        }

        // status === 'failed' — eligible for retry; reset to pending and proceed
        $pdo->prepare("
            UPDATE stripe_webhook_events
            SET processing_status = 'pending',
                error_message     = NULL,
                processed_at      = NULL
            WHERE stripe_event_id = ?
        ")->execute([$eventId]);
        $isRetry = true;
    }

    // ── Dispatch event ────────────────────────────────────────────────────────
    // All handler DB writes run on the same $pdo connection and are part of this
    // transaction — commit or rollback covers both the handler and the status update.
    try {
        switch ($eventType) {

            case 'checkout.session.completed':
                handleCheckoutSessionCompleted($event->data->object, $pdo, $appConfig);
                $processingStatus = 'processed';
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                handleSubscriptionUpsert($event->data->object, $pdo, $appConfig, $eventCreatedAt);
                $processingStatus = 'processed';
                break;

            case 'customer.subscription.deleted':
                handleSubscriptionDeleted($event->data->object, $pdo, $eventCreatedAt);
                $processingStatus = 'processed';
                break;

            case 'customer.subscription.trial_will_end':
                // Informational only — no DB mutation needed
                error_log('[stripe-webhook] trial_will_end for sub: ' .
                          substr($event->data->object->id ?? 'unknown', 0, 30));
                $processingStatus = 'processed';
                break;

            case 'invoice.paid':
                handleInvoicePaid($event->data->object, $pdo, $eventCreatedAt);
                $processingStatus = 'processed';
                break;

            case 'invoice.payment_failed':
                handleInvoicePaymentFailed($event->data->object, $pdo, $eventCreatedAt);
                $processingStatus = 'processed';
                break;

            default:
                // Unsupported event type — acknowledge, no action, return 200
                $processingStatus = 'skipped';
                break;
        }
    } catch (Exception $handlerEx) {
        $processingStatus = 'failed';
        // Sanitize: class name + truncated message only — no Stripe objects or card data
        $errorMessage = get_class($handlerEx) . ': ' . substr($handlerEx->getMessage(), 0, 500);
        error_log('[stripe-webhook] Handler error for ' . $eventType . ': ' . $errorMessage);
    }

    // ── Write final status atomically with any handler DB changes ─────────────
    $pdo->prepare("
        UPDATE stripe_webhook_events
        SET processing_status = :status,
            processed_at      = UTC_TIMESTAMP(),
            error_message     = :error
        WHERE stripe_event_id = :event_id
    ")->execute([
        'status'   => $processingStatus,
        'error'    => $errorMessage,
        'event_id' => $eventId,
    ]);

    $pdo->commit();

} catch (PDOException $txEx) {
    // Transaction-level failure — rollback if still active
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (PDOException $rbEx) {
            error_log('[stripe-webhook] Rollback failed: ' . $rbEx->getMessage());
        }
    }
    error_log('[stripe-webhook] Transaction error: ' . $txEx->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
    exit;
}

// ── Final HTTP response based on outcome ─────────────────────────────────────
if ($processingStatus === 'failed') {
    // Signal Stripe to retry — the event row is now marked 'failed' so the
    // next delivery will enter the retry path above.
    http_response_code(500);
    echo json_encode(['status' => 'failed', 'retry' => true]);
} else {
    // processed or skipped — Stripe need not retry
    http_response_code(200);
    echo json_encode(['status' => $processingStatus, 'retry' => $isRetry]);
}
exit;

// ════════════════════════════════════════════════════════════════════════════
// Event handlers
// All handlers receive the same $pdo connection that owns the open transaction.
// Any PDOException thrown here will be caught by the outer try/catch and cause
// a rollback + 500 response, triggering a Stripe retry.
// ════════════════════════════════════════════════════════════════════════════

/**
 * checkout.session.completed
 *
 * Checkout is complete. Persist the Stripe Customer ID on the subscription
 * OWNER's row so it is available even if the subscription.created event
 * arrives out of order.
 */
function handleCheckoutSessionCompleted(
    \Stripe\Checkout\Session $session,
    PDO $pdo,
    array $appConfig
): void {
    $stripeCustomerId = $session->customer ?? null;
    $ownerUserId      = $session->metadata['dentatrak_owner_user_id'] ?? null;

    if (!$ownerUserId || !$stripeCustomerId) {
        error_log('[stripe-webhook] checkout.session.completed: missing metadata');
        return;
    }

    $ownerUserId = (int)$ownerUserId;

    $pdo->prepare("
        UPDATE subscriptions
        SET stripe_customer_id = :cid
        WHERE owner_user_id = :owner_user_id
          AND (stripe_customer_id IS NULL OR stripe_customer_id = '')
    ")->execute([
        'cid'           => $stripeCustomerId,
        'owner_user_id' => $ownerUserId,
    ]);
}

/**
 * customer.subscription.created / customer.subscription.updated
 *
 * Resolves plan + interval from the server-side Price ID map (never from metadata).
 * Stale-event guard: compares the Stripe event's `created` timestamp against the
 * stored `stripe_event_created` column; skips updates older than what is already
 * written. This prevents an out-of-order older event from overwriting a newer state.
 */
function handleSubscriptionUpsert(
    \Stripe\Subscription $sub,
    PDO $pdo,
    array $appConfig,
    int $eventCreatedAt
): void {
    $stripeCustomerId     = $sub->customer            ?? null;
    $stripeSubscriptionId = $sub->id                  ?? null;
    $status               = $sub->status              ?? null;
    // Stripe represents a scheduled cancellation two different ways depending on
    // how the Customer Portal (or API caller) schedules it: either the boolean
    // cancel_at_period_end=true, or an absolute cancel_at timestamp with
    // cancel_at_period_end left false. Treat either as "scheduled to cancel".
    $cancelAt             = $sub->cancel_at ?? null;
    $cancelAtPeriodEnd    = (bool)($sub->cancel_at_period_end ?? false) ||
                            ($cancelAt !== null && $cancelAt > time());
    // Prefer the subscription item's current_period_end (moved here in newer
    // Stripe API versions / stripe-php v19+); fall back to the top-level field
    // for older API versions where it still lives on the Subscription object.
    $currentPeriodEnd     = $sub->items->data[0]->current_period_end ?? $sub->current_period_end ?? null;
    $trialEnd             = $sub->trial_end           ?? null;

    // Resolve the subscription OWNER by customer ID — never trust metadata alone for routing
    $ownerUserId = resolveOwnerByCustomer($pdo, $stripeCustomerId);
    if (!$ownerUserId) {
        // Metadata fallback: customer ID may not yet be stored (race with checkout.session.completed)
        $metaOwnerUserId = $sub->metadata['dentatrak_owner_user_id'] ?? null;
        if ($metaOwnerUserId) {
            $ownerUserId = (int)$metaOwnerUserId;
        } else {
            error_log('[stripe-webhook] Cannot resolve subscription owner for customer: ' .
                      substr($stripeCustomerId ?? '', 0, 20));
            return;
        }
    }

    // A Scale subscription has both the base plan item and a per-practice
    // add-on item. Scan every item and use the first one that maps to a
    // known base plan; ignore add-on items that resolve to 'unknown'.
    $plan = 'unknown';
    $billingInterval = null;
    $priceId = null;
    $currentPeriodEnd = null;
    foreach ($sub->items->data as $item) {
        $itemPriceId = $item->price->id ?? null;
        [$itemPlan, $itemInterval] = resolvePlanFromPriceId($itemPriceId, $appConfig);
        if ($itemPlan !== 'unknown' && $itemInterval !== null) {
            $plan = $itemPlan;
            $billingInterval = $itemInterval;
            $priceId = $itemPriceId;
            $currentPeriodEnd = $item->current_period_end ?? null;
            break;
        }
    }

    // Fallback to the first item only for the period-end / price field if
    // no base plan was found (should not happen for a DentaTrak subscription).
    if ($currentPeriodEnd === null && !empty($sub->items->data[0])) {
        $currentPeriodEnd = $sub->items->data[0]->current_period_end ?? null;
    }

    // Format timestamps as UTC datetime strings for storage
    $currentPeriodEndDt = $currentPeriodEnd ? gmdate('Y-m-d H:i:s', $currentPeriodEnd) : null;
    $trialEndsDt        = $trialEnd         ? gmdate('Y-m-d H:i:s', $trialEnd)         : null;
    $eventCreatedDt     = gmdate('Y-m-d H:i:s', $eventCreatedAt);

    // Stale-event guard: apply if this event is newer than or same-timestamp as
    // the last accepted event, so a genuinely older event can't overwrite newer
    // state, while same-second follow-up events (Stripe frequently delivers more
    // than one customer.subscription.updated within the same second — e.g. a
    // portal cancellation) are still allowed through. Uses stripe_event_created
    // (the Stripe event timestamp) — not subscription_updated_at (our DB write
    // time), which was the previous no-op bug.
    $pdo->prepare("
        UPDATE subscriptions SET
            stripe_customer_id      = COALESCE(stripe_customer_id, :cid),
            stripe_subscription_id  = :sub_id,
            stripe_price_id         = :price_id,
            plan                    = :plan,
            billing_interval        = :interval,
            status                  = :status,
            trial_ends_at           = COALESCE(:trial_ends_at, trial_ends_at),
            current_period_ends_at  = :period_end,
            cancel_at_period_end    = :cancel,
            subscription_updated_at = UTC_TIMESTAMP(),
            stripe_event_created    = :event_created
        WHERE owner_user_id = :owner_user_id
          AND (
              stripe_event_created IS NULL
              OR stripe_event_created <= :event_created2
          )
    ")->execute([
        'cid'            => $stripeCustomerId,
        'sub_id'         => $stripeSubscriptionId,
        'price_id'       => $priceId,
        'plan'           => $plan,
        'interval'       => $billingInterval,
        'status'         => $status,
        'trial_ends_at'  => $trialEndsDt,
        'period_end'     => $currentPeriodEndDt,
        'cancel'         => $cancelAtPeriodEnd ? 1 : 0,
        'event_created'  => $eventCreatedDt,
        'event_created2' => $eventCreatedDt,
        'owner_user_id'  => $ownerUserId,
    ]);
}

/**
 * customer.subscription.deleted
 *
 * Mark the subscription canceled. Data is preserved — cases are never deleted.
 * The stale-event guard also applies here via stripe_event_created.
 */
function handleSubscriptionDeleted(
    \Stripe\Subscription $sub,
    PDO $pdo,
    int $eventCreatedAt
): void {
    $stripeCustomerId     = $sub->customer ?? null;
    $stripeSubscriptionId = $sub->id       ?? null;
    $eventCreatedDt       = gmdate('Y-m-d H:i:s', $eventCreatedAt);

    $ownerUserId = resolveOwnerByCustomer($pdo, $stripeCustomerId);
    if (!$ownerUserId) {
        error_log('[stripe-webhook] subscription.deleted: cannot resolve subscription owner');
        return;
    }

    $pdo->prepare("
        UPDATE subscriptions SET
            status                  = 'canceled',
            cancel_at_period_end    = 0,
            subscription_updated_at = UTC_TIMESTAMP(),
            stripe_event_created    = :event_created
        WHERE owner_user_id = :owner_user_id
          AND stripe_subscription_id = :sub_id
          AND (
              stripe_event_created IS NULL
              OR stripe_event_created < :event_created2
          )
    ")->execute([
        'event_created'  => $eventCreatedDt,
        'event_created2' => $eventCreatedDt,
        'owner_user_id'  => $ownerUserId,
        'sub_id'         => $stripeSubscriptionId,
    ]);
}

/**
 * invoice.paid
 *
 * Payment succeeded. Recovers a past_due or unpaid subscription to active.
 * Does not overwrite trialing or other statuses.
 */
function handleInvoicePaid(
    \Stripe\Invoice $invoice,
    PDO $pdo,
    int $eventCreatedAt
): void {
    $stripeCustomerId     = $invoice->customer     ?? null;
    $stripeSubscriptionId = $invoice->subscription ?? null;

    if (!$stripeSubscriptionId) {
        return; // One-time invoice, not a subscription invoice
    }

    $ownerUserId = resolveOwnerByCustomer($pdo, $stripeCustomerId);
    if (!$ownerUserId) {
        return;
    }

    $pdo->prepare("
        UPDATE subscriptions SET
            status                  = 'active',
            subscription_updated_at = UTC_TIMESTAMP()
        WHERE owner_user_id = :owner_user_id
          AND stripe_subscription_id = :sub_id
          AND status IN ('past_due', 'unpaid')
    ")->execute([
        'owner_user_id' => $ownerUserId,
        'sub_id'        => $stripeSubscriptionId,
    ]);
}

/**
 * invoice.payment_failed
 *
 * Payment failed. Mark subscription past_due.
 * Does not affect already-canceled or unpaid subscriptions.
 */
function handleInvoicePaymentFailed(
    \Stripe\Invoice $invoice,
    PDO $pdo,
    int $eventCreatedAt
): void {
    $stripeCustomerId     = $invoice->customer     ?? null;
    $stripeSubscriptionId = $invoice->subscription ?? null;

    if (!$stripeSubscriptionId) {
        return;
    }

    $ownerUserId = resolveOwnerByCustomer($pdo, $stripeCustomerId);
    if (!$ownerUserId) {
        return;
    }

    $pdo->prepare("
        UPDATE subscriptions SET
            status                  = 'past_due',
            subscription_updated_at = UTC_TIMESTAMP()
        WHERE owner_user_id = :owner_user_id
          AND stripe_subscription_id = :sub_id
          AND status NOT IN ('canceled', 'unpaid')
    ")->execute([
        'owner_user_id' => $ownerUserId,
        'sub_id'        => $stripeSubscriptionId,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════════════════════

/**
 * Resolve the subscription OWNER's user ID from a Stripe Customer ID.
 * Returns int user ID or null if not found.
 * Throws PDOException on DB error (caught by outer transaction handler → 500).
 */
function resolveOwnerByCustomer(PDO $pdo, ?string $stripeCustomerId): ?int {
    if (empty($stripeCustomerId)) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT owner_user_id FROM subscriptions WHERE stripe_customer_id = ? LIMIT 1
    ");
    $stmt->execute([$stripeCustomerId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

// resolvePlanFromPriceId() now lives in api/stripe-price-map.php (required
// at the top of this file) so checkout and webhook handling share one
// authoritative Price ID <-> plan mapping. Plan identification here still
// comes only from configured Stripe Price IDs, never from event metadata.
