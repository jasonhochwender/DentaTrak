import { test, expect, request as playwrightRequest } from '@playwright/test';
import * as crypto from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { BASE_URL } from '../helpers/login';

/**
 * Scale Plan - Stripe Webhook Handling
 *
 * Drives api/stripe-webhook.php with genuinely Stripe-signed events (signed
 * locally with the same STRIPE_WEBHOOK_SECRET the endpoint verifies against)
 * and asserts what lands in the OWNER-level `subscriptions` row.
 *
 * The key property under test: plan and billing interval are derived from
 * the authoritative Stripe Price ID via api/stripe-price-map.php - never
 * from event metadata, which these tests deliberately populate with WRONG
 * plan names to prove metadata is ignored.
 */

const SCALE_MONTHLY_PRICE_ID            = 'price_1U47zpQk34photKQl1fgTslf';
const SCALE_ANNUAL_PRICE_ID             = 'price_1U480VQk34photKQpIpT1Kwy';
const SCALE_ADDITIONAL_MONTHLY_PRICE_ID = 'price_1U5WTLQk34photKQqjjQ9QbE';
const SCALE_ADDITIONAL_ANNUAL_PRICE_ID  = 'price_1U5WVWQk34photKQOIOIKUYW';
const CONTROL_MONTHLY_PRICE_ID          = 'price_1U3JUuQk34photKQrbz2tVed';

const PASSWORD = 'D3n7@Tr@k!9Zf#Qm2xL8V';

/** Read STRIPE_WEBHOOK_SECRET from .env so events can be signed exactly as Stripe would. */
function getWebhookSecret(which: 'primary' | 'test' = 'primary'): string | null {
  const envPath = path.resolve(__dirname, '../../.env');
  const contents = fs.readFileSync(envPath, 'utf8');
  const varName = which === 'test' ? 'STRIPE_WEBHOOK_SECRET_TEST' : 'STRIPE_WEBHOOK_SECRET';
  const match = contents.match(new RegExp(`^${varName}=(.+)$`, 'm'));
  if (!match) {
    if (which === 'primary') throw new Error('STRIPE_WEBHOOK_SECRET not found in .env');
    return null;
  }
  return match[1].trim();
}

async function testHelper(ctx: any, data: Record<string, unknown>) {
  const res = await ctx.post(`${BASE_URL}/api/test-helpers.php`, { data });
  const json = await res.json().catch(() => ({}));
  expect(json.success, `test-helper ${String(data.action)} failed: ${JSON.stringify(json)}`).toBeTruthy();
  return json;
}

async function setupOwner(ctx: any, email: string, practiceName: string) {
  return testHelper(ctx, {
    action: 'setup_test_user',
    email,
    password: PASSWORD,
    practiceName,
    firstName: 'E2E',
    lastName: 'Owner',
  });
}

async function describePlanConfig(ctx: any, priceId?: string) {
  const res = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
    data: { action: 'describe_plan_config', ...(priceId ? { price_id: priceId } : {}) },
  });
  return res.json();
}

/** POST a Stripe-signed event to the webhook endpoint. */
async function sendWebhookEvent(ctx: any, event: Record<string, any>, secret?: string) {
  const payload   = JSON.stringify(event);
  const timestamp = Math.floor(Date.now() / 1000);
  const signature = crypto
    .createHmac('sha256', secret ?? getWebhookSecret())
    .update(`${timestamp}.${payload}`)
    .digest('hex');

  const res = await ctx.post(`${BASE_URL}/api/stripe-webhook.php`, {
    headers: {
      'Content-Type': 'application/json',
      'Stripe-Signature': `t=${timestamp},v1=${signature}`,
    },
    data: payload,
  });
  return { status: res.status(), body: await res.json().catch(() => ({})) };
}

/**
 * Build a customer.subscription.updated event.
 *
 * `metadataPlan` is intentionally settable to a WRONG value so tests can
 * prove the handler ignores metadata and trusts only the Price ID.
 */
function subscriptionEvent(opts: {
  customerId: string;
  subscriptionId: string;
  priceId: string;
  status?: string;
  eventType?: string;
  metadataPlan?: string;
  cancelAtPeriodEnd?: boolean;
  createdOffsetSeconds?: number;
  additionalPriceId?: string;
  livemode?: boolean;
}) {
  const nowSec = Math.floor(Date.now() / 1000);
  const created = nowSec + (opts.createdOffsetSeconds ?? 0);
  const items: any[] = [
    {
      id: `si_test_${crypto.randomBytes(8).toString('hex')}`,
      object: 'subscription_item',
      current_period_end: nowSec + 30 * 24 * 60 * 60,
      price: { id: opts.priceId, object: 'price' },
    },
  ];
  if (opts.additionalPriceId) {
    items.push({
      id: `si_test_${crypto.randomBytes(8).toString('hex')}`,
      object: 'subscription_item',
      current_period_end: nowSec + 30 * 24 * 60 * 60,
      price: { id: opts.additionalPriceId, object: 'price' },
    });
  }
  return {
    id: `evt_test_${crypto.randomBytes(12).toString('hex')}`,
    object: 'event',
    api_version: '2024-06-20',
    created,
    livemode: opts.livemode ?? false,
    type: opts.eventType ?? 'customer.subscription.updated',
    data: {
      object: {
        id: opts.subscriptionId,
        object: 'subscription',
        customer: opts.customerId,
        status: opts.status ?? 'active',
        cancel_at: null,
        cancel_at_period_end: opts.cancelAtPeriodEnd ?? false,
        trial_end: null,
        metadata: {
          dentatrak_owner_user_id: '',
          // Deliberately misleading - must be ignored.
          plan: opts.metadataPlan ?? 'operate',
          interval: 'month',
        },
        items: {
          object: 'list',
          data: items,
        },
      },
    },
  };
}

test.describe('Scale - add-on Price ID mapping', () => {
  test('add-on Price IDs resolve to unknown, not scale', async () => {
    const ctx = await playwrightRequest.newContext();

    const monthly = await describePlanConfig(ctx, SCALE_ADDITIONAL_MONTHLY_PRICE_ID);
    expect(monthly.resolved).toEqual({ plan: 'unknown', interval: null });

    const annual = await describePlanConfig(ctx, SCALE_ADDITIONAL_ANNUAL_PRICE_ID);
    expect(annual.resolved).toEqual({ plan: 'unknown', interval: null });

    await ctx.dispose();
  });
});

// .serial: these tests share one owner/ctx and each webhook event builds on
// the previous one's persisted state (e.g. the "Control -> Scale upgrade"
// event must land before the "state is written to the owner row" check
// reads it back), so they must run in declared order, in one worker - the
// config's fullyParallel:true would otherwise be free to interleave or
// reorder them across workers.
test.describe.serial('Scale - webhook plan recognition (owner-level subscription row)', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL     = `e2e.scale.webhook.${RUN_ID}@dentatrak.com`;
  const CUSTOMER_ID     = `cus_test_scale_${RUN_ID}`;
  const SUBSCRIPTION_ID = `sub_test_scale_${RUN_ID}`;

  let ctx: any;
  let trialEndsAtBefore: string | null = null;

  test.beforeAll(async () => {
    ctx = await playwrightRequest.newContext();
    await testHelper(ctx, {
      action: 'setup_test_user',
      email: OWNER_EMAIL,
      password: PASSWORD,
      practiceName: 'Scale Webhook Practice',
      firstName: 'E2E',
      lastName: 'Owner',
    });
    // Creates the owner's subscriptions row (and its trial) and gives it the
    // Stripe customer ID the webhook events will be routed by.
    await testHelper(ctx, { action: 'set_subscription_plan', email: OWNER_EMAIL, plan: 'control' });
    await testHelper(ctx, {
      action: 'set_subscription_plan',
      email: OWNER_EMAIL,
      plan: 'control',
      stripe_customer_id: CUSTOMER_ID,
    });

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    trialEndsAtBefore = state.subscription?.trial_ends_at ?? null;
  });

  test.afterAll(async () => {
    await ctx?.dispose();
  });

  test('Scale MONTHLY is recognized from the Price ID, not from event metadata', async () => {
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_MONTHLY_PRICE_ID,
        metadataPlan: 'operate', // wrong on purpose
      })
    );
    expect(res.status).toBe(200);
    expect(res.body.status).toBe('processed');

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('scale');
    expect(state.subscription.billing_interval).toBe('month');
    expect(state.subscription.stripe_price_id).toBe(SCALE_MONTHLY_PRICE_ID);
    expect(state.subscription.stripe_subscription_id).toBe(SUBSCRIPTION_ID);
    expect(state.subscription.status).toBe('active');
  });

  test('Scale ANNUAL (billing interval change) is recognized', async () => {
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_ANNUAL_PRICE_ID,
        createdOffsetSeconds: 10,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('scale');
    expect(state.subscription.billing_interval).toBe('year');
    expect(state.subscription.stripe_price_id).toBe(SCALE_ANNUAL_PRICE_ID);
  });

  test('Scale -> Control downgrade is recognized and destroys nothing', async () => {
    const practicesBefore = (await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL }))
      .owned_practice_count;

    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: CONTROL_MONTHLY_PRICE_ID,
        metadataPlan: 'scale', // wrong on purpose
        createdOffsetSeconds: 20,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('control');
    expect(state.subscription.billing_interval).toBe('month');
    // Downgrades never delete practices or memberships.
    expect(state.owned_practice_count).toBe(practicesBefore);
  });

  test('Control -> Scale upgrade is recognized', async () => {
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_MONTHLY_PRICE_ID,
        createdOffsetSeconds: 30,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('scale');
    expect(state.subscription.billing_interval).toBe('month');
  });

  test('upgrading to Scale never resets or extends the owner-level trial', async () => {
    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    // Same trial_ends_at as before any of the Scale events above.
    expect(state.subscription.trial_ends_at).toBe(trialEndsAtBefore);
  });

  test('Scale state is written to the owner subscription row, not the deprecated practice columns', async () => {
    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('scale');

    // The per-practice billing columns are deprecated: the webhook must not
    // be using them as a source of truth for the new plan.
    for (const row of state.legacy_practice_billing_rows || []) {
      expect(row.subscription_plan).not.toBe('scale');
      expect(row.stripe_subscription_id).not.toBe(SUBSCRIPTION_ID);
    }
  });

  test('a scheduled cancellation on a Scale subscription is recorded without changing the plan', async () => {
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_MONTHLY_PRICE_ID,
        cancelAtPeriodEnd: true,
        createdOffsetSeconds: 40,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.cancel_at_period_end).toBe(true);
    expect(state.subscription.plan).toBe('scale');
  });

  test('subscription deletion cancels the Scale subscription and preserves all practices', async () => {
    const before = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });

    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_MONTHLY_PRICE_ID,
        status: 'canceled',
        eventType: 'customer.subscription.deleted',
        createdOffsetSeconds: 50,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.status).toBe('canceled');
    expect(state.owned_practice_count).toBe(before.owned_practice_count);
  });

  test('webhook with base + add-on items resolves the base Scale plan', async () => {
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_ANNUAL_PRICE_ID,
        additionalPriceId: SCALE_ADDITIONAL_ANNUAL_PRICE_ID,
        createdOffsetSeconds: 60,
      })
    );
    expect(res.status).toBe(200);

    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    expect(state.subscription.plan).toBe('scale');
    expect(state.subscription.billing_interval).toBe('year');
    expect(state.subscription.stripe_price_id).toBe(SCALE_ANNUAL_PRICE_ID);
  });
});

test.describe('Webhook signature fallback', () => {
  const FALLBACK_CUSTOMER = `cus_fallback_test_${crypto.randomBytes(8).toString('hex')}`;
  const FALLBACK_SUBSCRIPTION = `sub_fallback_test_${crypto.randomBytes(8).toString('hex')}`;

  test('events signed with STRIPE_WEBHOOK_SECRET_TEST are accepted when primary secret fails', async () => {
    const testSecret = getWebhookSecret('test');
    if (!testSecret) {
      test.skip(true, 'STRIPE_WEBHOOK_SECRET_TEST not configured; skipping fallback test');
      return;
    }

    const ctx = await playwrightRequest.newContext();
    const event = subscriptionEvent({
      customerId: FALLBACK_CUSTOMER,
      subscriptionId: FALLBACK_SUBSCRIPTION,
      priceId: SCALE_MONTHLY_PRICE_ID,
      status: 'active',
    });
    const res = await sendWebhookEvent(ctx, event, testSecret);
    await ctx.dispose();

    expect(res.status).toBe(200);
    expect(res.body.success ?? true).toBeTruthy();
  });

  test('events with a completely unknown signature are still rejected', async () => {
    const badSecret = 'whsec_' + crypto.randomBytes(24).toString('hex');
    const ctx = await playwrightRequest.newContext();
    const event = subscriptionEvent({
      customerId: FALLBACK_CUSTOMER,
      subscriptionId: FALLBACK_SUBSCRIPTION,
      priceId: SCALE_MONTHLY_PRICE_ID,
      status: 'active',
      createdOffsetSeconds: 1,
    });
    const res = await sendWebhookEvent(ctx, event, badSecret);
    await ctx.dispose();

    expect(res.status).toBe(400);
  });
});

test.describe('Webhook mode guard', () => {
  const OWNER_EMAIL = `e2e.scale.guard.${crypto.randomBytes(4).toString('hex')}@dentatrak.com`;
  const CUSTOMER_ID = `cus_livetest_${crypto.randomBytes(8).toString('hex')}`;
  const SUBSCRIPTION_ID = `sub_livetest_${crypto.randomBytes(8).toString('hex')}`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Webhook Guard Practice');
    await testHelper(ctx, {
      action: 'set_subscription_plan',
      email: OWNER_EMAIL,
      plan: 'scale',
      status: 'active',
      billing_interval: 'month',
      stripe_customer_id: CUSTOMER_ID,
      stripe_subscription_id: SUBSCRIPTION_ID,
    });
    await ctx.dispose();
  });

  test('live-signature + livemode=true event is accepted and processes the subscription', async () => {
    const ctx = await playwrightRequest.newContext();
    const res = await sendWebhookEvent(
      ctx,
      subscriptionEvent({
        customerId: CUSTOMER_ID,
        subscriptionId: SUBSCRIPTION_ID,
        priceId: SCALE_MONTHLY_PRICE_ID,
        livemode: true,
        createdOffsetSeconds: 120,
      })
    );
    await ctx.dispose();

    expect(res.status).toBe(200);
    expect(res.body.processed ?? true).toBeTruthy();

    const stateCtx = await playwrightRequest.newContext();
    const state = await testHelper(stateCtx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    await stateCtx.dispose();

    expect(state.subscription.plan).toBe('scale');
    expect(state.subscription.billing_interval).toBe('month');
  });

  test('test-mode events are blocked from processing in a live environment', async () => {
    const ctx = await playwrightRequest.newContext();
    const liveGuard = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: { action: 'test_webhook_mode_guard', environment: 'live', livemode: false, verified_with_test_secret: true },
    });
    const liveJson = await liveGuard.json().catch(() => ({}));
    await ctx.dispose();

    expect(liveJson.success).toBe(true);
    expect(liveJson.should_process).toBe(false);
  });

  test('live-mode events are allowed to process in a live environment', async () => {
    const ctx = await playwrightRequest.newContext();
    const liveGuard = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: { action: 'test_webhook_mode_guard', environment: 'live', livemode: true, verified_with_test_secret: false },
    });
    const liveJson = await liveGuard.json().catch(() => ({}));
    await ctx.dispose();

    expect(liveJson.success).toBe(true);
    expect(liveJson.should_process).toBe(true);
  });

  test('test-mode events are allowed to process in a test environment', async () => {
    const ctx = await playwrightRequest.newContext();
    const testGuard = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: { action: 'test_webhook_mode_guard', environment: 'test', livemode: false, verified_with_test_secret: true },
    });
    const testJson = await testGuard.json().catch(() => ({}));
    await ctx.dispose();

    expect(testJson.success).toBe(true);
    expect(testJson.should_process).toBe(true);
  });
});
