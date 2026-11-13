// Marketing Outreach CRM - UX regression e2e (local dev).
// Covers the post-deployment fixes: bulk bar hidden/disabled at zero
// selection, Add to Campaign opens the modal from both the bulk bar and
// the prospect drawer, localized empty states, select arrow styling,
// and table structure. No email is sent.
// Run: node tests/marketing-crm-ux-e2e-test.js
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

  // Localized empty-state string is wired into the CRM config (not a raw key).
  const html = await (await ctx.request.get(BASE + '/marketing-outreach.php')).text();
  const m = html.match(/noCampaigns:\s*"((?:[^"\\]|\\.)*)"/);
  check('noCampaigns localized in config', !!m && m[1] !== 'noCampaigns' && m[1].length > 3, m && m[1]);

  await page.goto(BASE + '/marketing-outreach.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#moTab-prospects', { state: 'attached', timeout: 15000 });
  await page.click('.mo-tab[data-tab="prospects"]');
  await page.waitForSelector('.moCrmSel', { timeout: 20000 });

  // Table structure: colgroup present for stable column widths.
  check('prospect table has colgroup', await page.locator('.mo-crm-table colgroup').count() === 1);

  // Bulk bar hidden at zero selection (the .mo-hidden vs .mo-bulkbar bug).
  const barDisplay = await page.locator('#moBulkBar').evaluate(el => getComputedStyle(el).display);
  check('bulk bar hidden at 0 selected', barDisplay === 'none', barDisplay);

  // Select one prospect -> bar appears, controls enabled.
  await page.locator('.moCrmSel').first().check();
  await page.waitForTimeout(200);
  const barDisplay2 = await page.locator('#moBulkBar').evaluate(el => getComputedStyle(el).display);
  check('bulk bar visible with selection', barDisplay2 !== 'none', barDisplay2);
  const disabledStates = await page.evaluate(() =>
    ['moBulkPriority','moBulkOwner','moBulkTags','moBulkCampaign','moBulkExport','moBulkClear']
      .map(id => document.getElementById(id).disabled));
  check('bulk controls enabled with selection', disabledStates.every(d => d === false), disabledStates.join(','));

  // Selection persists across pagination.
  const hasNext = await page.locator('#moPgNext').isEnabled().catch(() => false);
  if (hasNext) {
    await page.click('#moPgNext');
    await page.waitForSelector('.moCrmSel', { timeout: 15000 });
    const count1 = await page.locator('#moBulkCount').textContent();
    check('selection persists across pages', /1\b/.test(count1 || ''), (count1 || '').trim());
    await page.click('#moPgPrev');
    await page.waitForSelector('.moCrmSel', { timeout: 15000 });
  } else {
    check('selection persists across pages', true, 'single page');
  }

  // Add to Campaign from the bulk bar opens the modal (regression: onclick
  // used to receive the Event object and silently return).
  await page.click('#moBulkCampaign');
  await page.waitForSelector('#caCampaign', { timeout: 8000 });
  check('add-to-campaign modal opens from bulk bar', await page.locator('#caCampaign').count() === 1);
  await page.click('#moModal [data-close-btn], #moModal .mo-modal-x').catch(() => page.keyboard.press('Escape'));
  await page.waitForTimeout(300);

  // Drawer path: open a prospect and use its Add to Campaign button.
  await page.locator('tr.clickable').first().click();
  await page.waitForSelector('#moDrawerCampaign', { timeout: 8000 });
  check('drawer has add-to-campaign button', true);
  await page.click('#moDrawerCampaign');
  await page.waitForSelector('#caCampaign', { timeout: 8000 });
  const note = await page.locator('#moModal .mo-modal-body').textContent();
  check('drawer add-to-campaign targets the prospect', /1\b/.test(note || ''), (note || '').slice(0, 60));
  await page.click('#moModal [data-close-btn], #moModal .mo-modal-x').catch(() => page.keyboard.press('Escape'));
  await page.waitForTimeout(300);

  // Select styling: computed appearance is none with right padding for the chevron.
  const selStyle = await page.locator('#moCrmStatus').evaluate(el => {
    const cs = getComputedStyle(el);
    return { appearance: cs.appearance || cs.webkitAppearance, padRight: parseFloat(cs.paddingRight) };
  });
  check('select has custom arrow spacing', selStyle.padRight >= 28, JSON.stringify(selStyle));

  // Clear selection resets the bar.
  await page.click('#moDrawerClose').catch(() => {});
  await page.waitForTimeout(200);
  await page.click('#moBulkClear');
  await page.waitForTimeout(300);
  const barDisplay3 = await page.locator('#moBulkBar').evaluate(el => getComputedStyle(el).display);
  check('clear selection hides bulk bar', barDisplay3 === 'none', barDisplay3);

  check('no page errors', pageErrors.length === 0, pageErrors.slice(0, 3).join(' | '));
  await browser.close();

  const fails = results.filter(r => !r.pass).length;
  console.log(`\n${results.length - fails} passed, ${fails} failed`);
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
