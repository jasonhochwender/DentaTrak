/**
 * FABG pilot billing benefit e2e.
 *
 * Verifies, against the running local site (unconfigured offer state):
 *
 *   - billing-portal returns fabg.pilot + offer state for pilot practices
 *     and omits it for ordinary practices
 *   - FABG display prices are 75% monthly / 75% of 12 monthly annual
 *   - the customer-facing code stays hidden while the offer is unverified
 *   - plan checkout is blocked server-side with fabg_offer_unavailable for
 *     pilot practices (cannot accidentally subscribe at full price)
 *   - the Billing modal renders the benefit panel with the required
 *     wording + actual trial countdown, no code/copy controls, disabled
 *     plan buttons, and no "Save 2 months" promotion
 *   - non-FABG practices see the ordinary billing UI unchanged (savings
 *     badge, enabled buttons, no panel)
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

    // ── 3. FABG billing-portal payload ──────────────────────────────────
    const fabgRes = await api(page.request, 'api/billing-portal.php');
    const fabg = fabgRes.body && fabgRes.body.fabg;
    check('FABG practice gets fabg payload', !!fabg && fabg.pilot === true);
    check('offer reports configured=false while unset', fabg && fabg.configured === false, JSON.stringify(fabg && fabg.configured));
    check('offer reports verified=false while unset', fabg && fabg.verified === false);
    check('code hidden until verified', fabg && fabg.code === null);
    check('FABG operate monthly display = $186.75', fabg && fabg.display_prices && fabg.display_prices.operate.month === 18675);
    check('FABG operate annual display = $2,241', fabg && fabg.display_prices && fabg.display_prices.operate.year === 224100);
    check('FABG control annual display = $4,491', fabg && fabg.display_prices && fabg.display_prices.control.year === 449100);
    check('FABG scale annual display = $8,991', fabg && fabg.display_prices && fabg.display_prices.scale.year === 899100);
    check('trial status preserved', fabgRes.body && fabgRes.body.access && fabgRes.body.access.status === 'trialing',
      fabgRes.body && fabgRes.body.access && fabgRes.body.access.status);
    check('trial days remaining ~90', fabgRes.body && fabgRes.body.access && fabgRes.body.access.trial_days_remaining >= 89,
      String(fabgRes.body && fabgRes.body.access && fabgRes.body.access.trial_days_remaining));

    // ── 4. Checkout blocked at full price (server-side, direct request) ──
    const csrf = await getCsrf(page);
    const coMonth = await api(page.request, 'api/create-checkout-session.php', {
      method: 'POST', csrf, body: { plan: 'operate', interval: 'month' },
    });
    check('monthly checkout blocked for unverified offer', coMonth.status === 503, `status=${coMonth.status}`);
    check('monthly block uses fabg_offer_unavailable', coMonth.body && coMonth.body.error_code === 'fabg_offer_unavailable',
      JSON.stringify(coMonth.body));
    check('monthly block returns localized error text', coMonth.body && typeof coMonth.body.error === 'string' && coMonth.body.error.length > 5);

    const coYear = await api(page.request, 'api/create-checkout-session.php', {
      method: 'POST', csrf, body: { plan: 'control', interval: 'year' },
    });
    check('annual checkout blocked for unverified offer', coYear.status === 503 && coYear.body && coYear.body.error_code === 'fabg_offer_unavailable',
      `status=${coYear.status} ${JSON.stringify(coYear.body)}`);
    check('no checkout_url leaked while blocked', !(coMonth.body && coMonth.body.checkout_url) && !(coYear.body && coYear.body.checkout_url));

    // ── 5. FABG billing modal UI ────────────────────────────────────────
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
    check('no code element while unverified', (await page.$('#billingPortalBody .bp-fabg-code')) === null);
    check('no copy control while unverified', (await page.$('#billingPortalBody .bp-fabg-copy')) === null);
    check('pending explanation shown', (await page.$('#billingPortalBody .bp-fabg-pending')) !== null);

    const savingsChips = await page.$$eval('#billingPortalBody .bp-plan-savings', els => els.map(e => e.textContent.trim()));
    check('no "Save 2 months" promotion for FABG', savingsChips.every(s => !/2 months/i.test(s)), JSON.stringify(savingsChips));
    const disabledBtns = await page.$$eval('#billingPortalBody .bp-plan-select-btn', els => els.map(e => e.disabled));
    check('all plan buttons disabled while offer unverified', disabledBtns.length === 3 && disabledBtns.every(d => d === true),
      JSON.stringify(disabledBtns));

    // Prices on cards remain the standard display while not verified (no
    // misleading discounted price presented as purchasable).
    const firstPrice = await page.$eval('#billingPortalBody .bp-plan-card .bp-plan-price', el => el.textContent.trim());
    check('annual card shows standard price while unverified', /2,490|2490/.test(firstPrice), firstPrice);

    // Interval toggle keeps the panel + paused buttons on monthly view.
    await page.click('.bp-interval-btn[data-interval="month"]');
    await page.waitForSelector('.bp-interval-btn[data-interval="month"].active', { timeout: 5000 });
    check('panel persists on monthly interval', (await page.$('#billingPortalBody .bp-fabg-panel')) !== null);
    const monthlyBtns = await page.$$eval('#billingPortalBody .bp-plan-select-btn', els => els.map(e => e.disabled));
    check('monthly buttons also disabled while unverified', monthlyBtns.every(d => d === true));
    const monthlyPrice = await page.$eval('#billingPortalBody .bp-plan-card .bp-plan-price', el => el.textContent.trim());
    check('monthly card shows standard price while unverified', /249/.test(monthlyPrice), monthlyPrice);
    await page.keyboard.press('Escape');

    // ── 6. Removing the flag restores ordinary billing ──────────────────
    await callTestHelper(page.request, 'set_fabg_pilot', { fabg_pilot: 0, practice_id: practiceId });
    const restored = await api(page.request, 'api/billing-portal.php');
    check('unflagging restores fabg=null', restored.body && restored.body.fabg === null);
    await openBilling(page);
    check('ordinary view restored after unflag', (await page.$('#billingPortalBody .bp-fabg-panel')) === null
      && (await page.$('#billingPortalBody .bp-plan-savings')) !== null);
    await page.keyboard.press('Escape');

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
