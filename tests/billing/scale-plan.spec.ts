import { test, expect, request as playwrightRequest } from '@playwright/test';
import { loginAsOwner, loginAndGoTo, getUrl, BASE_URL } from '../helpers/login';

/**
 * Scale Plan - Stripe Configuration, Checkout, Billing UI, and Entitlement
 *
 * Scale is $999/month or $9,990/year and includes 5 practices.
 * Additional practices are $99/month or $990/year each.
 *
 * Plan identification is always derived from the configured Stripe Price ID
 * for the RUNNING environment (api/stripe-price-map.php), never from a
 * client-supplied plan name, and there is deliberately no cross-environment
 * fallback: a plan whose Price IDs are unset in an environment cannot be
 * bought there.
 */

// The TEST-mode Scale Price IDs. Production Scale prices do not exist yet;
// they are supplied per-environment via STRIPE_SCALE_MONTHLY_PRICE_ID /
// STRIPE_SCALE_ANNUAL_PRICE_ID, so this test only asserts test-mode wiring.
const SCALE_MONTHLY_PRICE_ID            = 'price_1U47zpQk34photKQl1fgTslf';
const SCALE_ANNUAL_PRICE_ID             = 'price_1U480VQk34photKQpIpT1Kwy';
const SCALE_ADDITIONAL_MONTHLY_PRICE_ID = 'price_1U5WTLQk34photKQqjjQ9QbE';
const SCALE_ADDITIONAL_ANNUAL_PRICE_ID  = 'price_1U5WVWQk34photKQOIOIKUYW';

const PASSWORD = 'D3n7@Tr@k!9Zf#Qm2xL8V';

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

async function previewScaleCheckout(ctx: any, email: string, interval: string) {
  const res = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
    data: { action: 'preview_scale_checkout', email, interval },
  });
  const json = await res.json().catch(() => ({}));
  expect(json.success, `preview_scale_checkout failed: ${JSON.stringify(json)}`).toBeTruthy();
  return json;
}

async function openBillingModal(page: any) {
  await page.locator('#userMenuToggle').click();
  await page.locator('#billingMenuItem').click();
  const modal = page.locator('#billingPortalModal');
  await expect(modal).toBeVisible();
  // Wait for the async billing-portal.php load to finish rendering.
  await expect(modal.locator('.bp-loading')).toHaveCount(0);
  return modal;
}

test.describe('Scale - Stripe price configuration', () => {
  test('both Scale test Price IDs resolve to plan=scale with the correct interval', async () => {
    const ctx = await playwrightRequest.newContext();

    const monthly = await describePlanConfig(ctx, SCALE_MONTHLY_PRICE_ID);
    expect(monthly.success).toBeTruthy();
    expect(monthly.stripe_environment).toBe('test');
    expect(monthly.resolved).toEqual({ plan: 'scale', interval: 'month' });

    const annual = await describePlanConfig(ctx, SCALE_ANNUAL_PRICE_ID);
    expect(annual.resolved).toEqual({ plan: 'scale', interval: 'year' });

    await ctx.dispose();
  });

  test('Scale is configured for purchase alongside Operate and Control, with $999 / $9,990 display pricing', async () => {
    const ctx = await playwrightRequest.newContext();
    const config = await describePlanConfig(ctx);

    expect(config.configured_plans).toEqual(expect.arrayContaining(['operate', 'control', 'scale']));

    // Price IDs wired to the exact test IDs supplied for this environment.
    expect(config.plans.scale.price_ids.month).toBe(SCALE_MONTHLY_PRICE_ID);
    expect(config.plans.scale.price_ids.year).toBe(SCALE_ANNUAL_PRICE_ID);

    // Display pricing in cents: $999/mo, $9,990/yr (2 months free).
    expect(config.plans.scale.display_prices).toEqual({ month: 99900, year: 999000 });

    // Existing plans are untouched.
    expect(config.plans.operate.display_prices).toEqual({ month: 24900, year: 249000 });
    expect(config.plans.control.display_prices).toEqual({ month: 49900, year: 499000 });

    await ctx.dispose();
  });

  test('entitlement + capability: Scale is uncapped, is the top tier, and satisfies Control-level feature checks', async () => {
    const ctx = await playwrightRequest.newContext();
    const config = await describePlanConfig(ctx);

    expect(config.plans.operate.max_practices).toBe(1);
    expect(config.plans.control.max_practices).toBe(2);
    expect(config.plans.scale.max_practices).toBeNull();

    // Upgrade progression Operate -> Control -> Scale, and no tier above Scale.
    expect(config.plans.operate.upgrade_target).toBe('control');
    expect(config.plans.control.upgrade_target).toBe('scale');
    expect(config.plans.scale.upgrade_target).toBeNull();

    // Scale includes everything in Control, so it must pass Control-only
    // feature gates (Smart Recommendations, advanced Insights, ...).
    expect(config.plans.operate.meets_control).toBe(false);
    expect(config.plans.control.meets_control).toBe(true);
    expect(config.plans.scale.meets_control).toBe(true);

    await ctx.dispose();
  });

  test('Scale additional-practice add-on Price IDs resolve to unknown, not scale', async () => {
    const ctx = await playwrightRequest.newContext();

    const addOnMonthly = await describePlanConfig(ctx, SCALE_ADDITIONAL_MONTHLY_PRICE_ID);
    expect(addOnMonthly.resolved).toEqual({ plan: 'unknown', interval: null });

    const addOnAnnual = await describePlanConfig(ctx, SCALE_ADDITIONAL_ANNUAL_PRICE_ID);
    expect(addOnAnnual.resolved).toEqual({ plan: 'unknown', interval: null });

    // The add-on IDs are present in the Scale price_id map alongside base IDs.
    const config = await describePlanConfig(ctx);
    expect(config.plans.scale.price_ids.additional_month).toBe(SCALE_ADDITIONAL_MONTHLY_PRICE_ID);
    expect(config.plans.scale.price_ids.additional_year).toBe(SCALE_ADDITIONAL_ANNUAL_PRICE_ID);

    await ctx.dispose();
  });

  test('an unrecognized Price ID resolves to unknown rather than guessing a plan', async () => {
    const ctx = await playwrightRequest.newContext();
    const result = await describePlanConfig(ctx, 'price_definitely_not_configured_anywhere');
    expect(result.resolved).toEqual({ plan: 'unknown', interval: null });
    await ctx.dispose();
  });
});

test.describe('Scale - Billing UI', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.scale.billingui.${RUN_ID}@dentatrak.com`;

  // The Billing menu item is CSS-hidden on narrow viewports for every user
  // (see css/mobile.css's @media (max-width: 720px) rule), so it cannot be
  // opened here. Same convention as practice/settings.spec.ts and
  // practice/user-management.spec.ts.
  test.beforeEach(({}, testInfo) => {
    if (testInfo.project.name === 'mobile-chrome') {
      testInfo.skip(true, 'Billing menu item is hidden on narrow viewports');
    }
  });

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Scale Billing UI Practice');
    await ctx.dispose();
  });

  test('all three plans render with correct annual pricing and practice allowances', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);
    const modal = await openBillingModal(page);

    const cards = modal.locator('.bp-plan-card');
    await expect(cards).toHaveCount(3);

    // Annual is the default selection.
    await expect(modal.locator('.bp-interval-btn.active')).toHaveText('Annual');

    const operate = modal.locator('[data-plan-card="operate"]');
    const control = modal.locator('[data-plan-card="control"]');
    const scale   = modal.locator('[data-plan-card="scale"]');

    await expect(operate.locator('.bp-plan-title')).toHaveText('Operate');
    await expect(operate.locator('.bp-plan-price')).toContainText('$2,490');
    await expect(operate.locator('.bp-plan-practices')).toHaveText('1 practice');

    await expect(control.locator('.bp-plan-title')).toHaveText('Control');
    await expect(control.locator('.bp-plan-price')).toContainText('$4,990');
    await expect(control.locator('.bp-plan-practices')).toHaveText('Up to 2 practices');

    await expect(scale.locator('.bp-plan-title')).toHaveText('Scale');
    await expect(scale.locator('.bp-plan-price')).toContainText('$9,990');
    await expect(scale.locator('.bp-plan-practices')).toHaveText('5 practices included');

    // Existing annual value-proposition treatment applies to every plan.
    await expect(scale.locator('.bp-plan-savings')).toHaveText('Save 2 months');
    await expect(scale.locator('.bp-plan-additional')).toHaveText('Additional practices: $990/year each');
    await expect(scale.locator('.bp-plan-select-btn')).toHaveText('Choose Scale');
  });

  test('monthly pricing is correct for all three plans', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);
    const modal = await openBillingModal(page);

    await modal.locator('.bp-interval-btn[data-interval="month"]').click();
    await expect(modal.locator('.bp-interval-btn.active')).toHaveText('Monthly');

    await expect(modal.locator('[data-plan-card="operate"] .bp-plan-price')).toContainText('$249');
    await expect(modal.locator('[data-plan-card="control"] .bp-plan-price')).toContainText('$499');
    await expect(modal.locator('[data-plan-card="scale"] .bp-plan-price')).toContainText('$999');

    await expect(modal.locator('[data-plan-card="scale"] .bp-plan-additional')).toHaveText('Additional practices: $99/month each');

    // Monthly never advertises the annual savings badge.
    await expect(modal.locator('.bp-plan-savings')).toHaveCount(0);
  });

  test('Scale is described as "everything in Control" with 5 included practices and additional-practice pricing', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);
    const modal = await openBillingModal(page);

    const scale = modal.locator('[data-plan-card="scale"]');
    await expect(scale.locator('.bp-plan-practices')).toHaveText('5 practices included');
    await expect(scale.locator('.bp-plan-benefits')).toContainText(
      'Everything in Control, plus support for additional practices as your organization grows'
    );

    const scaleText = ((await scale.textContent()) || '').toLowerCase();
    expect(scaleText).toContain('5 practices included');
    expect(scaleText).toContain('additional practices');
  });
});

test.describe('Scale - Checkout', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.scale.checkout.${RUN_ID}@dentatrak.com`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Scale Checkout Practice');
    await ctx.dispose();
  });

  /**
   * Starts a real Stripe TEST-mode Checkout Session through the app's own
   * endpoint and returns the parsed response. Nothing is paid - the session
   * is only created, which is what proves the correct Price ID was used.
   */
  async function startCheckout(page: any, plan: string, interval: string) {
    return page.evaluate(
      async ({ plan, interval }: { plan: string; interval: string }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const res = await fetch('api/create-checkout-session.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
          body: JSON.stringify({ plan, interval }),
        });
        return { status: res.status, body: await res.json() };
      },
      { plan, interval }
    );
  }

  test('Scale monthly and annual are both purchasable through the existing Checkout flow', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);

    const monthly = await startCheckout(page, 'scale', 'month');
    expect(monthly.status, JSON.stringify(monthly.body)).toBe(200);
    expect(monthly.body.checkout_url).toContain('checkout.stripe.com');

    const annual = await startCheckout(page, 'scale', 'year');
    expect(annual.status, JSON.stringify(annual.body)).toBe(200);
    expect(annual.body.checkout_url).toContain('checkout.stripe.com');
  });

  test('the same owner-level Stripe customer is reused across checkout attempts and practices', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);

    // First checkout creates and persists the owner's Stripe customer.
    const first = await startCheckout(page, 'scale', 'month');
    expect(first.status).toBe(200);

    const ctx = await playwrightRequest.newContext();
    const after = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    await ctx.dispose();

    expect(after.subscription.stripe_customer_id).toBeTruthy();
    const customerId = after.subscription.stripe_customer_id;

    // A second checkout (different interval) must not create a second customer.
    const second = await startCheckout(page, 'scale', 'year');
    expect(second.status).toBe(200);

    const ctx2 = await playwrightRequest.newContext();
    const after2 = await testHelper(ctx2, { action: 'get_subscription_state', email: OWNER_EMAIL });
    await ctx2.dispose();

    expect(after2.subscription.stripe_customer_id).toBe(customerId);
    expect(after2.owned_practice_count).toBe(1);
  });

  test('an unknown plan name is rejected by the server', async ({ page }) => {
    await loginAsOwner(page, OWNER_EMAIL, PASSWORD);

    const result = await startCheckout(page, 'enterprise', 'month');
    expect(result.status).toBe(400);
    expect(result.body.error).toContain('Invalid plan');
  });
});

test.describe('Scale - Checkout line items', () => {
  const RUN_ID = Date.now();
  const FIVE_OWNER_EMAIL = `e2e.scale.five.${RUN_ID}@dentatrak.com`;
  const SIX_OWNER_EMAIL  = `e2e.scale.six.${RUN_ID}@dentatrak.com`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, FIVE_OWNER_EMAIL, 'Scale Five Practice');
    await setupOwner(ctx, SIX_OWNER_EMAIL, 'Scale Six Practice');
    // setupOwner creates the first practice; seed the rest to reach 5 and 6.
    await testHelper(ctx, { action: 'seed_owned_practices', email: FIVE_OWNER_EMAIL, count: 4 });
    await testHelper(ctx, { action: 'seed_owned_practices', email: SIX_OWNER_EMAIL, count: 5 });
    await ctx.dispose();
  });

  test('5 owned practices: only the base Scale price, no add-on', async () => {
    const ctx = await playwrightRequest.newContext();
    const monthly = await previewScaleCheckout(ctx, FIVE_OWNER_EMAIL, 'month');
    expect(monthly.owned_practice_count).toBe(5);
    expect(monthly.additional_quantity).toBe(0);
    expect(monthly.line_items).toHaveLength(1);
    expect(monthly.line_items[0].price).toBe(SCALE_MONTHLY_PRICE_ID);
    expect(monthly.line_items[0].quantity).toBe(1);

    const annual = await previewScaleCheckout(ctx, FIVE_OWNER_EMAIL, 'year');
    expect(annual.line_items).toHaveLength(1);
    expect(annual.line_items[0].price).toBe(SCALE_ANNUAL_PRICE_ID);
    await ctx.dispose();
  });

  test('6 owned practices: base Scale price + one add-on unit', async () => {
    const ctx = await playwrightRequest.newContext();
    const monthly = await previewScaleCheckout(ctx, SIX_OWNER_EMAIL, 'month');
    expect(monthly.owned_practice_count).toBe(6);
    expect(monthly.additional_quantity).toBe(1);
    expect(monthly.line_items).toHaveLength(2);
    expect(monthly.line_items[0].price).toBe(SCALE_MONTHLY_PRICE_ID);
    expect(monthly.line_items[0].quantity).toBe(1);
    expect(monthly.line_items[1].price).toBe(SCALE_ADDITIONAL_MONTHLY_PRICE_ID);
    expect(monthly.line_items[1].quantity).toBe(1);

    const annual = await previewScaleCheckout(ctx, SIX_OWNER_EMAIL, 'year');
    expect(annual.line_items).toHaveLength(2);
    expect(annual.line_items[1].price).toBe(SCALE_ADDITIONAL_ANNUAL_PRICE_ID);
    expect(annual.line_items[1].quantity).toBe(1);
    await ctx.dispose();
  });
});

test.describe('Scale - practice creation entitlement', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.scale.upgrade.${RUN_ID}@dentatrak.com`;
  const THIRD_PRACTICE_NAME = `Scale Third Practice ${RUN_ID}`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Scale Upgrade Practice');
    // Control owner sitting exactly at their 2-practice limit.
    await testHelper(ctx, { action: 'set_subscription_plan', email: OWNER_EMAIL, plan: 'control' });
    await testHelper(ctx, { action: 'seed_owned_practices', email: OWNER_EMAIL, count: 1 });
    await ctx.dispose();
  });

  test('a Control owner at 2 practices is offered a functional Upgrade to Scale', async ({ page }, testInfo) => {
    // The Billing menu item this test opens is CSS-hidden on narrow
    // viewports for every user (see css/mobile.css's
    // @media (max-width: 720px) rule).
    if (testInfo.project.name === 'mobile-chrome') {
      testInfo.skip(true, 'Billing menu item is hidden on narrow viewports');
    }

    await loginAndGoTo(page, OWNER_EMAIL, PASSWORD, 'baa-acceptance.php?new=1');

    await expect(page.locator('#baaForm')).toHaveCount(0);
    const upgradeBtn = page.locator('#upgradeBtn');
    await expect(upgradeBtn).toHaveText('Upgrade to Scale');

    // The action must land the owner inside the app's existing Billing
    // surface - not a mailto:, not a dead-end page, and not a
    // Scale-specific flow.
    await upgradeBtn.click();
    await page.waitForURL(/main\.php/, { timeout: 15000 });

    const modal = page.locator('#billingPortalModal');
    await expect(modal).toBeVisible({ timeout: 15000 });

    // This owner already has an active paid subscription, so DentaTrak shows
    // the subscription card whose "Manage Billing" button opens the Stripe
    // Customer Portal. That is the SAME mechanism an existing Operate
    // subscriber uses to move to Control - plan changes for an existing paid
    // subscription are made in Stripe, not re-purchased through Checkout.
    await expect(modal.locator('.bp-plan-name')).toHaveText('Control');
    await expect(modal.locator('[data-action="manage-billing"]')).toBeVisible();
  });

  test('a not-yet-subscribed owner sees Scale offered directly in the plan grid', async ({ page }, testInfo) => {
    // The Billing menu item this test opens is CSS-hidden on narrow
    // viewports for every user (see css/mobile.css's
    // @media (max-width: 720px) rule).
    if (testInfo.project.name === 'mobile-chrome') {
      testInfo.skip(true, 'Billing menu item is hidden on narrow viewports');
    }

    const RUN = Date.now();
    const trialEmail = `e2e.scale.trialowner.${RUN}@dentatrak.com`;
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, trialEmail, 'Scale Trial Owner Practice');
    await ctx.dispose();

    await loginAsOwner(page, trialEmail, PASSWORD);
    const modal = await openBillingModal(page);

    // No Stripe subscription yet -> plan selection grid, where Scale can be
    // purchased through the existing Checkout flow.
    await expect(modal.locator('[data-plan-card="scale"]')).toBeVisible();
    await expect(modal.locator('[data-plan-card="scale"] .bp-plan-select-btn')).toHaveText('Choose Scale');
  });

  test('once on Scale, the previously blocked 3rd practice can be created', async ({ page }) => {
    const ctx = await playwrightRequest.newContext();
    // Simulates the post-checkout state the Stripe webhook writes.
    await testHelper(ctx, { action: 'set_subscription_plan', email: OWNER_EMAIL, plan: 'scale' });
    await ctx.dispose();

    await loginAndGoTo(page, OWNER_EMAIL, PASSWORD, 'baa-acceptance.php?new=1');

    await expect(page.locator('#baaForm')).toBeVisible();
    await page.locator('#legalName').fill(THIRD_PRACTICE_NAME);
    await page.locator('#practiceAddress').fill('789 Scale Blvd, Test City, TS 54321');
    await page.locator('#signerName').fill('Jordan Signer');
    await page.locator('#signerTitle').fill('Owner');
    await page.locator('#authorizedToBind').check();
    await page.locator('#acceptBtn').click();

    await page.waitForURL(/main\.php/, { timeout: 15000 });
    await expect(page.locator('.practice-switcher-name')).toHaveText(THIRD_PRACTICE_NAME);
  });
});

test.describe('Scale - add-on billing failure path', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.scale.billingfailure.${RUN_ID}@dentatrak.com`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Scale Billing Failure Practice');
    await testHelper(ctx, {
      action: 'set_subscription_plan',
      email: OWNER_EMAIL,
      plan: 'scale',
      status: 'active',
      billing_interval: 'month',
      stripe_subscription_id: 'sub_test_invalid_for_failure',
    });
    // setupOwner already created one owned practice; seed four more to land at 5.
    await testHelper(ctx, { action: 'seed_owned_practices', email: OWNER_EMAIL, count: 4 });
    await ctx.dispose();
  });

  test('a Scale owner with 5 practices sees BILLING_UPDATE_FAILED when the add-on sync fails', async ({ page }) => {
    await loginAndGoTo(page, OWNER_EMAIL, PASSWORD, 'baa-acceptance.php?new=1');

    const result = await page.evaluate(async (payload: any) => {
      const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content || '';
      const res = await fetch('api/accept-baa.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify(payload),
      });
      return { status: res.status, body: await res.json().catch(() => ({})) };
    }, {
      new: 1,
      legalName: 'Billing Failure Practice',
      practiceAddress: '123 Fail St, Test City, TS 12345',
      signerName: 'Sam Failure',
      signerTitle: 'Owner',
      authorizedToBind: true,
    });

    expect(result.status).toBe(502);
    expect(result.body.success).toBe(false);
    expect(result.body.error_code).toBe('BILLING_UPDATE_FAILED');

    const ctx = await playwrightRequest.newContext();
    const state = await testHelper(ctx, { action: 'get_subscription_state', email: OWNER_EMAIL });
    await ctx.dispose();

    expect(state.owned_practice_count).toBe(5);
  });
});

test.describe('Scale - add-on restore compensation', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.scale.addonrestore.${RUN_ID}@dentatrak.com`;

  test.beforeAll(async () => {
    const ctx = await playwrightRequest.newContext();
    await setupOwner(ctx, OWNER_EMAIL, 'Scale Addon Restore Practice');
    await testHelper(ctx, {
      action: 'set_subscription_plan',
      email: OWNER_EMAIL,
      plan: 'scale',
      status: 'active',
      billing_interval: 'month',
    });
    await ctx.dispose();
  });

  test('the restore helper can sync and restore the Scale add-on quantity on a real test subscription', async () => {
    const ctx = await playwrightRequest.newContext();
    const res = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: { action: 'test_scale_addon_restore', email: OWNER_EMAIL },
    });
    const json = await res.json().catch(() => ({}));
    await ctx.dispose();

    expect(json.success).toBe(true);
    expect(json.restored_quantity).toBe(1);
    expect(json.previous_quantity).toBeGreaterThanOrEqual(0);
  });
});
