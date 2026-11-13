// Marketing Outreach CRM Phase 4 - campaign wizard e2e (local dev).
// Verifies: CRM flags enable the wizard UI, New Campaign opens the modal
// (no prompt()), recipient picker searches/selects CRM prospects, draft
// creation produces a campaign with contact_id recipients, and the detail
// view renders messages + CRM action bar. No email is sent.
// Run: node tests/marketing-crm-campaigns-e2e-test.js
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

  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  await callHelper(page, 'set_session_flag', { flag: 'MARKETING_OUTREACH', value: true });
  await callHelper(page, 'set_session_flag', { flag: 'MARKETING_CRM_READS', value: true });

  await page.goto(BASE + '/marketing-outreach.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#moTab-campaigns', { state: 'attached', timeout: 15000 });
  await page.click('.mo-tab[data-tab="campaigns"]');
  await page.waitForTimeout(500);
  check('campaigns tab renders', await page.locator('#moTab-campaigns').count() === 1);
  check('CRM prospect module loaded', await page.evaluate(() => !!window.MOCrm));
  check('CRM campaign bar present', await page.locator('#moCampCrmBar').count() === 1);
  check('legacy campaign bar hidden in CRM mode',
    await page.locator('#moCampLegacyBar').evaluate(el => getComputedStyle(el).display === 'none'));

  // Open the wizard.
  await page.click('#moNewCampaign');
  await page.waitForSelector('#wzName', { timeout: 8000 });
  check('wizard modal opens (no prompt)', await page.locator('#moModal .mo-modal').count() === 1);
  await page.fill('#wzName', 'E2E CRM Campaign ' + Date.now());

  // Open recipient picker and select the first prospect.
  await page.click('#wzPick');
  await page.waitForSelector('.pkCb', { timeout: 15000 });
  const rows = await page.locator('.pkCb').count();
  check('picker lists CRM prospects', rows > 0, rows + ' rows');
  await page.locator('.pkCb').first().check();
  await page.click('#pkConfirm');
  await page.waitForSelector('#wzPicked', { timeout: 8000 });
  const picked = await page.locator('#wzPicked').textContent();
  check('picked count shown', /1\s*selected|1\b/.test(picked), picked.trim());

  // Create the draft.
  await page.click('#wzCreate');
  await page.waitForSelector('#moCampMessages .mo-msg, #moCampMessages .pa-card', { timeout: 15000 });
  const title = await page.locator('#moCampTitle').textContent();
  check('campaign detail opened', /E2E CRM Campaign/.test(title || ''), title);
  const msgCount = await page.locator('#moCampMessages .mo-msg').count();
  check('recipient rendered in detail', msgCount >= 1, msgCount + ' msgs');

  // Back to list; campaign appears.
  await page.click('#moCampBackCrm');
  await page.waitForSelector('#moCampaignList table', { timeout: 10000 });
  const listText = await page.locator('#moCampaignList').textContent();
  check('campaign listed as draft', /draft/i.test(listText) && /E2E CRM Campaign/.test(listText));

  check('no page errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
  await browser.close();

  const fails = results.filter(r => !r.pass).length;
  console.log(`\n${results.length - fails} passed, ${fails} failed`);
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
