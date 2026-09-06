'use strict';
const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');
const { chromium } = require('playwright');

const BASE = 'http://localhost/DentaTrak';
const TEST_MARKER = 'DentaTrakTest';
const PASSWORD = randomBytes(24).toString('hex');

async function apiCall(requester, method, path, data = null, csrf = null) {
  const url = `${BASE}${path}`;
  const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  let res;
  if (method === 'get') {
    res = await requester.get(url, { maxRedirects: 0 });
  } else {
    res = await requester.post(url, { data, headers });
  }
  const text = await res.text().catch(() => '');
  let body = null;
  try { body = JSON.parse(text); } catch {}
  return { status: res.status(), body, text };
}

async function helper(requester, action, data) {
  const result = await apiCall(requester, 'post', '/api/test-helpers.php', { action, ...data });
  assert.equal(result.body?.success, true, `${action}: status=${result.status} body=${result.text}`);
  return result.body;
}

async function login(requester, email) {
  const result = await apiCall(requester, 'post', '/api/auth-email.php', { action: 'login', email, password: PASSWORD });
  assert.equal(result.body?.success, true, `login: status=${result.status} body=${result.text}`);
  return result.body;
}

async function acceptTerms(requester) {
  const res = await requester.get(`${BASE}/accept-terms.php`, { maxRedirects: 0 });
  if (res.status() !== 200) return;
  const text = await res.text();
  const token = text.match(/<meta name="csrf-token" content="([^"]+)"/);
  const version = text.match(/<meta name="terms-version" content="([^"]+)"/);
  if (token && version) {
    const result = await apiCall(requester, 'post', '/api/accept-terms.php', { accepted: true, terms_version: version[1] }, token[1]);
    assert.equal(result.body?.success, true, `terms: status=${result.status} body=${result.text}`);
  }
}

async function getCsrf(requester) {
  const res = await requester.get(`${BASE}/practice-setup.php`, { maxRedirects: 0 });
  const text = await res.text();
  const match = text.match(/<meta name="csrf-token" content="([^"]+)"/);
  return match ? match[1] : '';
}

function blockBackgroundPolls(page) {
  page.route(/\/(session-timeout|realtime-updates)\.js.*/, async route => route.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
  page.route(/.*\/(realtime-updates|session-status|notifications|ai-recommendations)\.php.*/, async route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: [] }) }));
  page.route(/https:\/\/www\.google-analytics\.com\/.*/, route => route.abort('aborted'));
  page.route(/https:\/\/www\.googletagmanager\.com\/.*/, route => route.abort('aborted'));
}

(async () => {
  const startTime = Date.now();
  function logStep(...args) { console.log(`[${Date.now() - startTime}ms]`, ...args); }

  const globalTimeout = setTimeout(() => {
    console.error('Test exceeded global 90s timeout');
    process.exit(1);
  }, 90000);

  const browser = await chromium.launch();
  try {
    logStep('browser launched');
    const ownerCtx = await browser.newContext();
    const owner = await helper(ownerCtx.request, 'setup_test_user', {
      email: `${TEST_MARKER}.header.owner.${Date.now()}@dentatrak.com`,
      password: PASSWORD,
      firstName: 'Header',
      lastName: 'Owner',
      practiceName: 'Header Owner Practice'
    });
    logStep('owner fixture created', owner.practice_id);

    const otherCtx = await browser.newContext();
    const other = await helper(otherCtx.request, 'setup_test_user', {
      email: `${TEST_MARKER}.header.other.${Date.now()}@dentatrak.com`,
      password: PASSWORD,
      firstName: 'Header',
      lastName: 'Other',
      practiceName: 'Header Shared Practice'
    });
    logStep('other fixture created', other.practice_id);

    await helper(ownerCtx.request, 'setup_practice_member', {
      email: owner.email,
      password: PASSWORD,
      practiceId: other.practice_id,
      role: 'admin',
      canViewAnalytics: 1,
      canEditCases: 1
    });
    logStep('added owner to shared practice as admin');

    await login(ownerCtx.request, owner.email);
    await acceptTerms(ownerCtx.request);
    logStep('owner logged in');
    const csrf = await getCsrf(ownerCtx.request);
    assert.ok(csrf, 'CSRF token available');

    // Select the owned practice so main.php loads with the switcher.
    const selectRes = await apiCall(ownerCtx.request, 'post', '/api/select-practice.php', { practice_id: owner.practice_id, remember_preference: false }, csrf);
    logStep('select-practice response', selectRes.status, selectRes.body);
    assert.equal(selectRes.body?.success, true, `select: status=${selectRes.status} body=${selectRes.text}`);

    const page = await ownerCtx.newPage();
    const errors = [];
    page.on('pageerror', msg => errors.push(msg.message));
    blockBackgroundPolls(page);

    let switchRequests = [];
    page.on('request', req => {
      if (req.url().includes('/api/switch-practice.php')) {
        switchRequests.push({ method: req.method(), headers: req.headers() });
      }
    });

    logStep('navigating to main.php');
    await page.goto(`${BASE}/main.php`, { waitUntil: 'load' });
    logStep('main.php loaded, waiting for app.js');
    await page.waitForFunction(() => typeof window.switchPractice === 'function');
    logStep('app ready', await page.evaluate(() => window.currentPracticeId));

    assert.equal(await page.evaluate(() => Number(window.currentPracticeId)), Number(owner.practice_id), 'starts on owner practice');
    assert.equal(await page.evaluate(() => window.isPracticeAdmin), true, 'owner is admin');

    // Mouse: open the switcher and verify both active practices are listed.
    await page.locator('#practiceSwitcherBtn').click();
    await page.waitForSelector('#practiceSwitcherDropdown.open');
    const items = await page.locator('.practice-switcher-item').all();
    assert.equal(items.length, 2, 'switcher lists two authorized practices');
    assert.ok(await page.locator(`.practice-switcher-item[data-practice-id="${owner.practice_id}"]`).isVisible(), 'owned practice visible');
    assert.ok(await page.locator(`.practice-switcher-item[data-practice-id="${other.practice_id}"]`).isVisible(), 'shared practice visible');

    logStep('clicking shared practice', other.practice_id);
    switchRequests = [];
    await page.locator(`.practice-switcher-item[data-practice-id="${other.practice_id}"]`).click();
    await page.waitForFunction(id => document.readyState === 'complete' && Number(window.currentPracticeId) === id, Number(other.practice_id), { timeout: 10000 });
    logStep('mouse switch complete', await page.evaluate(() => window.currentPracticeId));

    assert.equal(switchRequests.length, 1, 'single POST request fired by mouse switch');
    assert.equal(switchRequests[0].method, 'POST', 'switch uses POST');
    assert.ok(switchRequests[0].headers['x-csrf-token'] || switchRequests[0].headers['X-CSRF-Token'], 'CSRF token included');
    assert.equal(await page.evaluate(() => Number(window.currentPracticeId)), Number(other.practice_id), 'session practice updated to shared practice');
    assert.equal(await page.evaluate(() => window.isPracticeAdmin), true, 'administrator role hydrated');

    // Duplicate-activation guard: a second click on the same target while the
    // first request is in-flight must be ignored. Use a dispatched click event
    // because the element may become detached on reload; only one POST should
    // be emitted and the final state must remain the target practice.
    switchRequests = [];
    await page.locator('#practiceSwitcherBtn').click();
    await page.waitForSelector('#practiceSwitcherDropdown.open');
    const targetBackToOwner = page.locator(`.practice-switcher-item[data-practice-id="${owner.practice_id}"]`);
    await targetBackToOwner.click();
    await targetBackToOwner.evaluate(el => el.click()).catch(() => {});
    await page.waitForFunction(id => document.readyState === 'complete' && Number(window.currentPracticeId) === id, Number(owner.practice_id), { timeout: 10000 });
    logStep('duplicate guard complete', await page.evaluate(() => window.currentPracticeId));

    const postRequests = switchRequests.filter(r => r.method === 'POST');
    assert.equal(postRequests.length, 1, 'rapid duplicate activation produced only one POST');

    // Keyboard: open the switcher with Enter, focus the other item, press Enter.
    await page.locator('#practiceSwitcherBtn').focus();
    await page.keyboard.press('Enter');
    await page.waitForSelector('#practiceSwitcherDropdown.open');
    const keyboardTarget = page.locator('.practice-switcher-item:not(.active)').first();
    const keyboardTargetId = Number(await keyboardTarget.getAttribute('data-practice-id'));
    await keyboardTarget.focus();
    await page.keyboard.press('Enter');
    await page.waitForFunction(id => document.readyState === 'complete' && Number(window.currentPracticeId) === id, keyboardTargetId, { timeout: 10000 });
    logStep('keyboard switch complete', await page.evaluate(() => window.currentPracticeId));
    assert.equal(await page.evaluate(() => Number(window.currentPracticeId)), keyboardTargetId, 'keyboard activation switched practice');

    // Failure path: route switch endpoint to a delayed 403 and click a target.
    logStep('starting failure path');
    await page.goto(`${BASE}/main.php`, { waitUntil: 'load' });
    await page.waitForFunction(() => typeof window.switchPractice === 'function');
    logStep('main ready for failure path');
    blockBackgroundPolls(page);

    const currentId = Number(await page.evaluate(() => window.currentPracticeId));
    const failTargetId = currentId === Number(owner.practice_id) ? Number(other.practice_id) : Number(owner.practice_id);
    const failSelector = `.practice-switcher-item[data-practice-id="${failTargetId}"]`;

    await page.route('**/api/switch-practice.php', route => {
      setTimeout(() => route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'Forbidden practice switch' })
      }), 300);
    });

    await page.locator('#practiceSwitcherBtn').click();
    await page.waitForSelector('#practiceSwitcherDropdown.open');
    const failItem = page.locator(failSelector).first();
    await failItem.click();

    // The selected control is disabled and marked busy while the request is in flight.
    await page.waitForFunction(sel => {
      const el = document.querySelector(sel);
      return el && el.disabled === true && el.getAttribute('aria-busy') === 'true';
    }, failSelector, { timeout: 2000 });
    await page.waitForTimeout(500);
    await page.unroute('**/api/switch-practice.php');
    logStep('failure path complete');

    assert.ok(page.url().includes('/main.php'), 'stays on main.php after failure');
    assert.equal(await page.evaluate(() => Number(window.currentPracticeId)), currentId, 'session practice unchanged after failure');
    assert.equal(await page.evaluate(() => window.isPracticeAdmin), currentId === Number(owner.practice_id) ? true : true, 'role unchanged after failure');
    const toast = await page.locator('.toast.toast-error').first();
    assert.ok(await toast.isVisible().catch(() => false), 'error toast is visible');
    const toastText = await toast.textContent().catch(() => '');
    assert.ok(toastText.toLowerCase().includes('forbidden') || toastText.toLowerCase().includes('switch'), 'error text mentions the failure');

    const focusedAfterFailure = await page.evaluate(() => document.activeElement?.className || '');
    assert.ok(focusedAfterFailure.includes('practice-switcher'), 'focus returned to switcher control');
    assert.equal(await failItem.isEnabled(), true, 'failed item is re-enabled');
    assert.equal(await failItem.getAttribute('aria-busy'), null, 'aria-busy removed after failure');

    // Change membership to user/assigned-only and verify hydration.
    logStep('starting role-change verification');
    await login(otherCtx.request, other.email);
    await acceptTerms(otherCtx.request);
    const otherCsrf = await getCsrf(otherCtx.request);
    assert.ok(otherCsrf, 'other CSRF available');
    const otherSelect = await apiCall(otherCtx.request, 'post', '/api/select-practice.php', { practice_id: other.practice_id, remember_preference: false }, otherCsrf);
    assert.equal(otherSelect.body?.success, true, `other select: status=${otherSelect.status} body=${otherSelect.text}`);

    await helper(otherCtx.request, 'setup_practice_member', {
      email: owner.email,
      password: PASSWORD,
      practiceId: other.practice_id,
      role: 'user',
      limitedVisibility: 1,
      canViewAnalytics: 0,
      canEditCases: 1
    });

    // Switch session to the owner practice first, then load main and switch to shared.
    const mainCsrf = await page.$eval('meta[name="csrf-token"]', el => el.content).catch(() => '');
    assert.ok(mainCsrf, 'main CSRF token available');
    const resetRes = await apiCall(ownerCtx.request, 'post', '/api/switch-practice.php', { practice_id: owner.practice_id }, mainCsrf);
    assert.equal(resetRes.body?.success, true, `reset switch: status=${resetRes.status} body=${resetRes.text}`);

    logStep('reset to owner practice, loading main for role check');
    await page.goto(`${BASE}/main.php`, { waitUntil: 'load' });
    await page.waitForFunction(() => typeof window.switchPractice === 'function');
    blockBackgroundPolls(page);
    await page.locator('#practiceSwitcherBtn').click();
    await page.waitForSelector('#practiceSwitcherDropdown.open');
    await page.locator(`.practice-switcher-item[data-practice-id="${other.practice_id}"]`).click();
    await page.waitForFunction(id => document.readyState === 'complete' && Number(window.currentPracticeId) === id, Number(other.practice_id), { timeout: 10000 });
    logStep('role switch complete', await page.evaluate(() => ({ current: window.currentPracticeId, isAdmin: window.isPracticeAdmin, canView: window.userCanViewAnalytics })));

    assert.equal(await page.evaluate(() => Number(window.currentPracticeId)), Number(other.practice_id), 'switched to assigned-only practice');
    assert.equal(await page.evaluate(() => window.isPracticeAdmin), false, 'user/assigned-only role hydrated as non-admin');
    assert.equal(await page.evaluate(() => window.userCanViewAnalytics), false, 'assigned-only user cannot view analytics');

    // Direct API security checks against the authenticated session.
    const apiCsrf = await page.$eval('meta[name="csrf-token"]', el => el.content).catch(() => '');
    assert.ok(apiCsrf, 'CSRF token for API checks');
    const missingCsrf = await apiCall(ownerCtx.request, 'post', '/api/switch-practice.php', { practice_id: owner.practice_id });
    assert.equal(missingCsrf.status, 403, 'missing CSRF rejected');

    const getMethod = await ownerCtx.request.get(`${BASE}/api/switch-practice.php?practice_id=${owner.practice_id}`, { maxRedirects: 0 });
    assert.equal(getMethod.status(), 405, 'GET rejected');

    const invalidId = await apiCall(ownerCtx.request, 'post', '/api/switch-practice.php', { practice_id: 'abc' }, apiCsrf);
    assert.equal(invalidId.status, 400, 'invalid practice id rejected');

    const inaccessible = await apiCall(ownerCtx.request, 'post', '/api/switch-practice.php', { practice_id: 999999 }, apiCsrf);
    assert.equal(inaccessible.status, 403, 'inaccessible practice rejected');

    assert.deepEqual(errors, []);
    clearTimeout(globalTimeout);
    logStep('All desktop header switcher checks passed.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
