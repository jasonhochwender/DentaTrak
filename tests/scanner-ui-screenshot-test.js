'use strict';
// Scanner foundation visual check - logs in locally, triggers the dev-only
// simulator, then captures Settings > Integrations (scanner cards) and the
// Incoming scans inbox across desktop/mobile + light/dark.
// Run: node tests/scanner-ui-screenshot-test.js  (requires SCANNER_INBOX and
// SCANNER_SIMULATOR enabled in local .env, dev MySQL on 3308, Apache running)
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';
const OUT = process.env.SHOT_DIR || (process.env.TEMP || '/tmp') + '/dt-scanner-shots';
fs.mkdirSync(OUT, { recursive: true });

async function login(context) {
  const r = await context.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email: EMAIL, password: PASSWORD } });
  const d = await r.json();
  assert.equal(d.success, true, 'login: ' + JSON.stringify(d));
}

async function openMain(page) {
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
}

async function theme(page, t) {
  await page.evaluate((t) => {
    window.__dtThemePref = t;
    document.documentElement.setAttribute('data-theme', t);
  }, t);
  await page.waitForTimeout(250);
}

(async () => {
  const browser = await chromium.launch(
    process.env.DT_CHROME_EXE ? { executablePath: process.env.DT_CHROME_EXE } : {}
  );
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  try {
    await login(context);
    await openMain(page);

    // Trigger the dev simulator through the real HTTP guard stack.
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const sim = await context.request.post(BASE + '/api/scanner-simulate.php', {
      headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
      data: { scenario: 'success' },
    });
    console.log('simulate:', sim.status(), JSON.stringify(await sim.json()).slice(0, 300));

    // ---- Settings > Integrations: scanner cards ---------------------------
    // Settings is a modal (openSettingsBillingModal); open it, then click the
    // integrations nav item.
    await page.evaluate(() => window.openSettingsBillingModal && window.openSettingsBillingModal());
    await page.waitForTimeout(600);
    const intNav = page.locator('[data-nav-target="integrations"]').first();
    if (await intNav.count() && await intNav.isVisible()) { await intNav.click(); await page.waitForTimeout(400); }
    const twisty = page.locator('[data-twisty-id="integrations"]').first();
    if (await twisty.count()) {
      // Expand the twisty only if collapsed.
      const hdr = twisty.locator('.settings-twisty-header').first();
      if (await hdr.count()) {
        const expanded = await hdr.getAttribute('aria-expanded');
        if (expanded !== 'true') { await hdr.click(); await page.waitForTimeout(300); }
      }
    }
    const scannerList = page.locator('.scanner-integrations-list').first();
    const scannerCount = await page.locator('.integration-card-scanner').count();
    console.log('scanner cards rendered:', scannerCount);
    if (await scannerList.count()) {
      await scannerList.scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
    }

    await theme(page, 'light');
    await page.screenshot({ path: OUT + '/settings-integrations-desktop-light.png', fullPage: false });
    await theme(page, 'dark');
    await page.screenshot({ path: OUT + '/settings-integrations-desktop-dark.png', fullPage: false });

    // Close the settings modal before switching main tabs.
    const closeBtn = page.locator('#settingsBillingClose').first();
    if (await closeBtn.count() && await closeBtn.isVisible().catch(() => false)) {
      await closeBtn.click();
    } else {
      await page.keyboard.press('Escape');
    }
    await page.waitForSelector('#settingsBillingModal', { state: 'hidden', timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(400);

    // ---- Incoming scans inbox --------------------------------------------
    const inboxTab = page.locator('[data-tab="incoming-scans"]').first();
    const inboxVisible = await inboxTab.count() > 0 && await inboxTab.isVisible().catch(() => false);
    console.log('inbox tab present:', await inboxTab.count(), 'visible:', inboxVisible);
    if (await inboxTab.count()) {
      await inboxTab.click();
      await page.waitForTimeout(800);
      await theme(page, 'light');
      await page.screenshot({ path: OUT + '/inbox-desktop-light.png', fullPage: false });
      await theme(page, 'dark');
      await page.screenshot({ path: OUT + '/inbox-desktop-dark.png', fullPage: false });
    }

    // ---- Mobile viewport --------------------------------------------------
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    if (await inboxTab.count()) {
      await theme(page, 'light');
      await page.screenshot({ path: OUT + '/inbox-mobile-light.png', fullPage: true });
      await theme(page, 'dark');
      await page.screenshot({ path: OUT + '/inbox-mobile-dark.png', fullPage: true });
    }
    // Reopen the settings modal for the mobile captures.
    await page.evaluate(() => window.openSettingsBillingModal && window.openSettingsBillingModal());
    await page.waitForTimeout(600);
    if (await intNav.count() && await intNav.isVisible().catch(() => false)) {
      await intNav.click(); await page.waitForTimeout(400);
      if (await scannerList.count()) {
        await scannerList.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
      }
      await theme(page, 'light');
      await page.screenshot({ path: OUT + '/settings-integrations-mobile-light.png', fullPage: true });
      await theme(page, 'dark');
      await page.screenshot({ path: OUT + '/settings-integrations-mobile-dark.png', fullPage: true });
    }
    console.log('screenshots written to', OUT);
  } catch (e) {
    console.log('FAIL', e.message);
    try { await page.screenshot({ path: OUT + '/error.png' }); } catch {}
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
