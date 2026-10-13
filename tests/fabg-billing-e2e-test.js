/**
 * FABG pilot billing benefit e2e.
 *
 * Verifies, against the running local site (offer CONFIGURED + VERIFIED in
 * the local Stripe test account — objects provisioned by
 * scripts/provision-fabg-stripe.php):
 *
 *   - billing-portal returns fabg.pilot + verified offer state with the
 *     FABG25 code for pilot practices, and omits it for ordinary practices
 *   - FABG display prices are 75% monthly / 75% of 12 monthly annual
 *   - Checkout creates real sessions with the server-attached promotion
 *     code, the correct Price (standard monthly / dedicated 12x FABG
 *     annual — never the two-months-free annual), and the existing
 *     trial_end carried through
 *   - non-FABG checkout sessions carry no promotion code
 *   - the Billing modal renders the benefit panel with the required
 *     wording, actual trial countdown, code + copy control, member prices
 *     with standard struck through, enabled plan buttons, and no
 *     "Save 2 months" promotion
 *   - trial data (trial_days_remaining / status) is untouched
 *
 * Run: node tests/fabg-billing-e2e-test.js  (requires the local MAMP site)
 */
'use strict';

const { chromium } = require('playwright');

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e.fabg-billing@dentatrak.com';
const PASSWORD = 'TestPass123!';
const TEST_MARKER = 'DentaTrakTest';

let passed = 0;
let failed = 0;
function check(name, cond, extra = '') {
  if (cond) { passed++; console.log(`PASS: ${name}`); }
  else { failed++; console.log(`FAIL: ${name} ${extra}`); }
}

async function api(requester, path, { method = 'GET', csrf = '', body = null } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const res = await requester.fetch(`${BASE}/${path}`, {
    method,
    headers,
    data: body === null ? undefined : body,
  });
  const text = await res.text();
  try { return { status: res.status(), body: JSON.parse(text) }; }
  catch (e) { return { status: res.status(), raw: text }; }
}

async function login(requester, email, password) {
  const res = await requester.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email, password },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json();
  if (!body.success) throw new Error(`Login failed for ${email}: ${JSON.stringify(body)}`);
}

async function acceptTermsIfNeeded(requester) {
  const termsPage = await requester.get(`${BASE}/accept-terms.php`);
  if (termsPage.status() >= 300) return;
  const text = await termsPage.text();
  const csrfMatch = text.match(/<meta name="csrf-token" content="([^"]+)">/);
  const versionMatch = text.match(/<meta name="terms-version" content="([^"]+)">/);
  if (!csrfMatch || !versionMatch) return;
  await requester.post(`${BASE}/api/accept-terms.php`, {
    data: { accepted: true, terms_version: versionMatch[1] },
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfMatch[1] },
  });
}

function blockBackgroundPolls(page) {
  page.route(/\/(session-timeout|realtime-updates)\.js.*/, async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
  });
  page.route(/.*\/(realtime-updates|session-status|notifications|ai-recommendations)\.php.*/, async (route) => {
    const url = route.request().url();
    if (url.includes('ai-recommendations')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, recommendations: [] }) });
    } else if (url.includes('notifications.php?action=count')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, count: 0, unread: 0 }) });
    } else {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, ok: true }) });
    }
  });
}

async function safeGoto(page, url, maxAttempts = 3) {
  for (let i = 0; i < maxAttempts; i++) {
    try {
      const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      if (res && res.status() >= 500) throw new Error(`HTTP ${res.status()} for ${url}`);
      await page.waitForFunction(() => {
        return !!document.querySelector('.main-tab-pane, #cases-tab, #insights-tab');
      }, {}, { timeout: 25000, polling: 100 });
      return;
    } catch (e) {
      console.log('Goto attempt', i + 1, 'failed:', e.message);
    }
    await page.waitForTimeout(2500);
  }
  throw new Error(`safeGoto failed for ${url}`);
}

async function callTestHelper(requester, action, extra = {}) {
  const res = await requester.post(`${BASE}/api/test-helpers.php`, {
    data: { action, ...extra },
    headers: { 'Content-Type': 'application/json' },
  });
  const text = await res.text();
  try { return { status: res.status(), body: JSON.parse(text) }; }
  catch (e) { return { status: res.status(), raw: text }; }
}

async function getCsrf(page) {
  return page.evaluate(() => {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') : '';
  });
}

const modalVisible = (page) => page.evaluate(() => {
  const m = document.getElementById('billingPortalModal');
  return !!m && m.style.display === 'block';
});

async function openBilling(page) {
  await page.evaluate(() => window.openBillingPortal());
  await page.waitForSelector('#billingPortalBody .bp-plan-card', { timeout: 15000 });
}

async function run() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  blockBackgroundPolls(page);
  page.on('pageerror', err => console.log('[PAGE ERROR]', err.message));

  let practiceId = null;
  try {
    // ── Fixture: fresh practice ──────────────────────────────────────────
    const setup = await callTestHelper(page.request, 'setup_test_user', {
      email: EMAIL, password: PASSWORD,
      firstName: TEST_MARKER, lastName: 'FabgBilling',
      practiceName: 'FABG Billing E2E Practice',
    });
    if (!setup.body || !setup.body.success) throw new Error('setup_test_user failed: ' + JSON.stringify(setup.body || setup.raw));
    practiceId = setup.body.practice_id;

    await login(page.request, EMAIL, PASSWORD);
    await acceptTermsIfNeeded(page.request);
    await safeGoto(page, `${BASE}/main.php`);

    // ── 1. Ordinary (non-FABG) billing: unchanged ───────────────────────
    const plain = await api(page.request, 'api/billing-portal.php');
    check('non-FABG billing-portal returns fabg=null', plain.body && plain.body.fabg === null, JSON.stringify(plain.body && plain.body.fabg));
    check('non-FABG display_prices are standard',
      plain.body && plain.body.display_prices &&
      plain.body.display_prices.operate.year === 249000);

    await openBilling(page);
    check('ordinary view shows "Save 2 months" promotion',
      await page.$eval('#billingPortalBody', el => el.innerHTML).then(h => h.includes('bp-plan-savings')));
    const savingsTexts = await page.$$eval('#billingPortalBody .bp-plan-savings', els => els.map(e => e.textContent.trim()));
    check('annual savings chip present on ordinary cards', savingsTexts.length > 0, JSON.stringify(savingsTexts));
    check('no FABG panel on ordinary view', (await page.$('#billingPortalBody .bp-fabg-panel')) === null);
    const ordinaryBtns = await page.$$eval('#billingPortalBody .bp-plan-select-btn', els => els.map(e => e.disabled));
    check('ordinary plan buttons enabled', ordinaryBtns.length === 3 && ordinaryBtns.every(d => d === false));
    await page.keyboard.press('Escape');

    // ── 2. Flag the practice as FABG pilot ──────────────────────────────
    const flag = await callTestHelper(page.request, 'set_fabg_pilot', { fabg_pilot: 1, practice_id: practiceId });
    check('set_fabg_pilot accepted', flag.body && flag.body.success === true, JSON.stringify(flag.body || flag.raw));

    // ── 3. FABG billing-portal payload (offer configured + verified) ────
    const fabgRes = await api(page.request, 'api/billing-portal.php');
    const fabg = fabgRes.body && fabgRes.body.fabg;
    check('FABG practice gets fabg payload', !!fabg && fabg.pilot === true);
    check('offer reports configured=true', fabg && fabg.configured === true, JSON.stringify(fabg && fabg.configured));
    check('offer verified against Stripe', fabg && fabg.verified === true, JSON.stringify(fabg && fabg.verified));
    check('code exposed once verified', fabg && fabg.code === 'FABG25', JSON.stringify(fabg && fabg.code));
    check('all FABG annual prices resolved', fabg && fabg.annual_prices &&
      fabg.annual_prices.operate === true && fabg.annual_prices.control === true && fabg.annual_prices.scale === true);
    check('FABG operate monthly display = $186.75', fabg && fabg.display_prices && fabg.display_prices.operate.month === 18675);
    check('FABG operate annual display = $2,241', fabg && fabg.display_prices && fabg.display_prices.operate.year === 224100);
    check('FABG control annual display = $4,491', fabg && fabg.display_prices && fabg.display_prices.control.year === 449100);
    check('FABG scale annual display = $8,991', fabg && fabg.display_prices && fabg.display_prices.scale.year === 899100);
    check('trial status preserved', fabgRes.body && fabgRes.body.access && fabgRes.body.access.status === 'trialing',
      fabgRes.body && fabgRes.body.access && fabgRes.body.access.status);
    check('trial days remaining ~90', fabgRes.body && fabgRes.body.access && fabgRes.body.access.trial_days_remaining >= 89,
      String(fabgRes.body && fabgRes.body.access && fabgRes.body.access.trial_days_remaining));

    // ── 4. Checkout creates discounted sessions (server-side verified) ──
    const csrf = await getCsrf(page);

    // (trial_end is passed via subscription_data at create time but is not
    // echoed on the open session in current API versions — it is verified on
    // the resulting subscription in the live checkout verification.)
    const coMonth = await api(page.request, 'api/create-checkout-session.php', {
      method: 'POST', csrf, body: { plan: 'operate', interval: 'month' },
    });
    check('monthly checkout allowed once verified', coMonth.status === 200 && !!coMonth.body.checkout_url,
      `status=${coMonth.status} ${JSON.stringify(coMonth.body).slice(0, 120)}`);
    const monthSessionId = (coMonth.body.checkout_url || '').match(/cs_test_[A-Za-z0-9]+/);
    check('monthly checkout session id present', !!monthSessionId);
    if (monthSessionId) {
      const sess = await callTestHelper(page.request, 'get_checkout_session', { session_id: monthSessionId[0] });
      check('monthly session attaches FABG25 promotion code',
        sess.body && sess.body.promotion_codes && sess.body.promotion_codes.length === 1,
        JSON.stringify(sess.body && sess.body.promotion_codes));
      const mItem = sess.body && sess.body.line_items && sess.body.line_items[0];
      check('monthly session uses standard monthly price ($249/mo)',
        mItem && mItem.unit_amount === 24900 && mItem.interval === 'month',
        JSON.stringify(mItem));
      check('monthly session metadata marks fabg_pilot',
        sess.body && sess.body.metadata && sess.body.metadata.fabg_pilot === '1');
    }

    const coYear = await api(page.request, 'api/create-checkout-session.php', {
      method: 'POST', csrf, body: { plan: 'control', interval: 'year' },
    });
    check('annual checkout allowed once verified', coYear.status === 200 && !!coYear.body.checkout_url,
      `status=${coYear.status} ${JSON.stringify(coYear.body).slice(0, 120)}`);
    const yearSessionId = (coYear.body.checkout_url || '').match(/cs_test_[A-Za-z0-9]+/);
    check('annual checkout session id present', !!yearSessionId);
    if (yearSessionId) {
      const sess = await callTestHelper(page.request, 'get_checkout_session', { session_id: yearSessionId[0] });
      check('annual session attaches FABG25 promotion code',
        sess.body && sess.body.promotion_codes && sess.body.promotion_codes.length === 1,
        JSON.stringify(sess.body && sess.body.promotion_codes));
      // The annual session must bill the dedicated 12x FABG annual Price —
      // $5,988 undiscounted for Control, never the $4,990 two-months-free one.
      const yItem = sess.body && sess.body.line_items && sess.body.line_items[0];
      check('annual session uses dedicated 12x FABG annual price ($5,988/yr)',
        yItem && yItem.unit_amount === 598800 && yItem.interval === 'year',
        JSON.stringify(yItem) + ' (499000 would mean stacked/discounted annual)');
    }

    // ── 5. FABG billing modal UI (verified offer — code + member prices) ─
    await openBilling(page);
    check('FABG benefit panel rendered', (await page.$('#billingPortalBody .bp-fabg-panel')) !== null);
    const panelBody = await page.$eval('#billingPortalBody .bp-fabg-panel-body', el => el.textContent.trim());
    check('panel carries required benefit wording',
      panelBody.includes('Save 25% on any paid plan') && panelBody.includes('Cannot be combined with annual billing savings'),
      panelBody.slice(0, 80));
    const trialLine = await page.$('#billingPortalBody .bp-fabg-panel-trial');
    check('panel shows actual remaining trial time', !!trialLine);
    if (trialLine) {
      const trialText = await trialLine.textContent();
      check('trial line shows day count', /\d+\s+day/.test(trialText), trialText.trim());
    }
    const codeEl = await page.$('#billingPortalBody .bp-fabg-code');
    check('code element shown once verified', codeEl !== null);
    if (codeEl) {
      check('code is FABG25', (await codeEl.textContent()).trim() === 'FABG25');
    }
    check('copy control shown once verified', (await page.$('#billingPortalBody .bp-fabg-copy')) !== null);
    check('pending notice hidden once verified', (await page.$('#billingPortalBody .bp-fabg-pending')) === null);

    const savingsChips = await page.$$eval('#billingPortalBody .bp-plan-savings', els => els.map(e => e.textContent.trim()));
    check('no "Save 2 months" promotion for FABG', savingsChips.every(s => !/2 months/i.test(s)), JSON.stringify(savingsChips));
    const memberChip = await page.$('#billingPortalBody .bp-plan-savings--fabg');
    check('member-price chip replaces annual savings', memberChip !== null);

    const enabledBtns = await page.$$eval('#billingPortalBody .bp-plan-select-btn', els => els.map(e => e.disabled));
    check('all plan buttons enabled once verified', enabledBtns.length === 3 && enabledBtns.every(d => d === false),
      JSON.stringify(enabledBtns));

    // Annual cards show the FABG member price (75% of 12 monthly) with the
    // standard price struck through — $2,241/yr for Operate, not $2,490.
    const firstPrice = await page.$eval('#billingPortalBody .bp-plan-card .bp-plan-price', el => el.textContent.trim());
    check('annual card shows FABG member price', /2,241|2241/.test(firstPrice), firstPrice);
    const wasPrice = await page.$eval('#billingPortalBody .bp-plan-card .bp-plan-price-was', el => el.textContent.trim());
    check('standard price struck through', /2,490|2490/.test(wasPrice), wasPrice);

    // Monthly interval keeps panel + member prices ($186.75 vs $249).
    await page.click('.bp-interval-btn[data-interval="month"]');
    await page.waitForSelector('.bp-interval-btn[data-interval="month"].active', { timeout: 5000 });
    check('panel persists on monthly interval', (await page.$('#billingPortalBody .bp-fabg-panel')) !== null);
    const monthlyBtns = await page.$$eval('#billingPortalBody .bp-plan-select-btn', els => els.map(e => e.disabled));
    check('monthly buttons enabled once verified', monthlyBtns.every(d => d === false));
    const monthlyPrice = await page.$eval('#billingPortalBody .bp-plan-card .bp-plan-price', el => el.textContent.trim());
    check('monthly card shows FABG member price $186.75', /186\.75/.test(monthlyPrice), monthlyPrice);
    // Scale add-on line should reflect the member price too ($74.25/mo).
    const addonText = await page.$eval('#billingPortalBody .bp-plan-additional', el => el.textContent.trim());
    check('scale add-on shows member price $74.25', /74\.25/.test(addonText), addonText);
    await page.keyboard.press('Escape');

    // ── 6. Removing the flag restores ordinary billing ──────────────────
    await callTestHelper(page.request, 'set_fabg_pilot', { fabg_pilot: 0, practice_id: practiceId });
    const restored = await api(page.request, 'api/billing-portal.php');
    check('unflagging restores fabg=null', restored.body && restored.body.fabg === null);
    await openBilling(page);
    check('ordinary view restored after unflag', (await page.$('#billingPortalBody .bp-fabg-panel')) === null
      && (await page.$('#billingPortalBody .bp-plan-savings')) !== null);
    await page.keyboard.press('Escape');

    // Non-FABG checkout gets NO promotion code — the discount cannot leak
    // to ordinary practices even when configured.
    const plainCo = await api(page.request, 'api/create-checkout-session.php', {
      method: 'POST', csrf, body: { plan: 'operate', interval: 'month' },
    });
    check('non-FABG checkout creates session', plainCo.status === 200 && !!plainCo.body.checkout_url,
      `status=${plainCo.status} ${JSON.stringify(plainCo.body).slice(0, 120)}`);
    const plainSessId = (plainCo.body.checkout_url || '').match(/cs_test_[A-Za-z0-9]+/);
    if (plainSessId) {
      const sess = await callTestHelper(page.request, 'get_checkout_session', { session_id: plainSessId[0] });
      check('non-FABG session has no promotion code',
        sess.body && (!sess.body.promotion_codes || sess.body.promotion_codes.length === 0),
        JSON.stringify(sess.body && sess.body.promotion_codes));
      check('non-FABG session metadata unflagged',
        sess.body && sess.body.metadata && sess.body.metadata.fabg_pilot === '0');
    }

  } catch (e) {
    check('run completed without exception', false, e.message);
  } finally {
    // ── Cleanup ─────────────────────────────────────────────────────────
    try {
      await callTestHelper(page.request, 'set_fabg_pilot', { fabg_pilot: 0, practice_id: practiceId });
      await callTestHelper(page.request, 'cleanup_test_user', { email: EMAIL });
    } catch (e) { console.log('cleanup warning:', e.message); }
    await browser.close();
  }

  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
}

run();
