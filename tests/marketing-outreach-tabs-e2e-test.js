// Marketing Outreach admin page - navigation regression test (local dev).
// Verifies the page renders without fatal errors, all six tabs switch and
// load their data through api/marketing-admin.php, and API failures surface
// a visible error. Run: node tests/marketing-outreach-tabs-e2e-test.js
'use strict';
const { chromium } = require('playwright');
const BASE = 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';

const results = [];
function check(name, cond, extra) {
  results.push({ name, pass: !!cond });
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + name + (extra ? '  [' + extra + ']' : ''));
}

const TAB_API = {
  overview: 'overview',
  prospects: 'prospects',
  campaigns: 'campaigns',
  followups: 'followups',
  templates: 'templates',
  history: 'history',
};

async function callHelper(page, action, extra = {}) {
  return page.evaluate(async ({ url, payload }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const text = await r.text();
    try { return { status: r.status, body: JSON.parse(text) }; }
    catch (e) { return { status: r.status, raw: text }; }
  }, { url: `${BASE}/api/test-helpers.php`, payload: { action, ...extra } });
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const login = await ctx.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: EMAIL, password: PASSWORD },
  });
  check('login', login.ok());
  const page = await ctx.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(e.message));

  // Enable the admin flag for this session (dev/test session override only).
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const flag = await callHelper(page, 'set_session_flag', { flag: 'MARKETING_OUTREACH', value: true });
  check('MARKETING_OUTREACH session flag set', !!(flag.body && flag.body.success), JSON.stringify(flag.body || flag.raw).slice(0, 120));

  // Track API calls per action.
  const apiCalls = [];
  page.on('request', r => {
    const u = r.url();
    if (u.includes('marketing-admin.php')) {
      const m = u.match(/action=([a-z_]+)/);
      apiCalls.push(m ? m[1] : u);
    }
  });

  const resp = await page.goto(BASE + '/marketing-outreach.php', { waitUntil: 'domcontentloaded' });
  check('page returns 200 (no fatal/redirect)', resp && resp.status() === 200, resp && resp.status());
  await page.waitForTimeout(1500);

  // Extract inline scripts and syntax-check them.
  const scripts = await page.evaluate(() =>
    Array.from(document.querySelectorAll('script:not([src])')).map(s => s.textContent));
  let syntaxOk = true, syntaxErr = '';
  for (const code of scripts) {
    try { new Function(code); } catch (e) { syntaxOk = false; syntaxErr = e.message; break; }
  }
  check('inline scripts parse without syntax errors', syntaxOk, syntaxErr);
  check('no uncaught page errors during load', pageErrors.length === 0, pageErrors.slice(0, 2).join(' | '));

  // Overview auto-loads on init.
  await page.waitForFunction(() => document.querySelectorAll('#moOvMetrics .pa-metric').length > 0, { timeout: 10000 }).catch(() => {});
  check('overview loaded data via API', apiCalls.includes('overview'));

  // Each tab: click, pane visible, API action fired.
  for (const tab of Object.keys(TAB_API)) {
    apiCalls.length = 0;
    await page.click(`.mo-tab[data-tab="${tab}"]`);
    await page.waitForTimeout(900);
    const paneVisible = await page.evaluate(name => {
      const p = document.getElementById('moTab-' + name);
      return !!p && !p.classList.contains('mo-hidden');
    }, tab);
    const active = await page.evaluate(name => {
      const b = document.querySelector(`.mo-tab[data-tab="${name}"]`);
      return b && b.classList.contains('is-active');
    }, tab);
    check(`tab ${tab}: pane visible + active`, paneVisible && active);
    check(`tab ${tab}: API action '${TAB_API[tab]}' requested`, apiCalls.includes(TAB_API[tab]), apiCalls.join(','));
  }

  // API failure surfaces a visible error.
  await page.route('**/api/marketing-admin.php*', r => r.fulfill({
    status: 500, contentType: 'application/json',
    body: JSON.stringify({ success: false, error: 'simulated failure' }),
  }));
  apiCalls.length = 0;
  await page.click('.mo-tab[data-tab="prospects"]');
  await page.waitForTimeout(1200);
  const errShown = await page.evaluate(() => {
    const e = document.getElementById('moGlobalError');
    return !!e && !e.classList.contains('mo-hidden') && e.textContent.trim().length > 0;
  });
  check('API failure shows visible error message', errShown);
  await page.unroute('**/api/marketing-admin.php*');

  // Sending safeguards still displayed as disabled.
  const banner = await page.evaluate(() => {
    const b = document.getElementById('moSendingBanner');
    return b ? b.textContent.trim() : '';
  });
  check('sending banner present', banner.length > 0, banner.slice(0, 80));

  await callHelper(page, 'set_session_flag', { flag: 'MARKETING_OUTREACH', value: false }).catch(() => {});
  await browser.close();
  const failed = results.filter(r => !r.pass);
  console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`);
  process.exit(failed.length ? 1 : 0);
})();
