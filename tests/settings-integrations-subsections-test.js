'use strict';
// Settings > Integrations collapsible-subsection check.
// Verifies: two parent headings, subsections collapsed by default,
// independent expand/collapse, aria-expanded accuracy, OD controls intact,
// config modal + disconnect confirm still reachable, screenshots of
// collapsed and expanded layouts (desktop light/dark + mobile).
// Run: node tests/settings-integrations-subsections-test.js
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';
const OUT = process.env.SHOT_DIR || (process.env.TEMP || '/tmp') + '/dt-integrations-subsections';
fs.mkdirSync(OUT, { recursive: true });
const EXE = process.env.DT_CHROME_EXE || 'C:/Users/jason/AppData/Local/ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-win64/chrome-headless-shell.exe';

let pass = 0, fail = 0;
function check(name, cond) {
  if (cond) { pass++; console.log('PASS ' + name); }
  else { fail++; console.log('FAIL ' + name); }
}
async function login(context) {
  const r = await context.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email: EMAIL, password: PASSWORD } });
  assert.equal((await r.json()).success, true, 'login');
}
async function openSettings(page) {
  await page.evaluate(() => window.openSettingsBillingModal());
  await page.waitForSelector('#settingsBillingModal', { state: 'visible', timeout: 15000 });
  const nav = page.locator('[data-nav-target="integrations"]');
  await nav.click();
  await page.waitForTimeout(400);
}
async function theme(page, t) {
  await page.evaluate((t) => { window.__dtThemePref = t; document.documentElement.setAttribute('data-theme', t); }, t);
  await page.waitForTimeout(200);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const apiCalls = [];
  page.on('request', r => { if (r.url().includes('api/integrations.php')) apiCalls.push(r.method()); });
  try {
    await login(context);
    await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
    // Subsection open state persists per user in localStorage; reset so the
    // "collapsed by default" assertions are deterministic across runs.
    await page.evaluate(() => {
      Object.keys(localStorage)
        .filter(k => k.startsWith('integrationSubsection_'))
        .forEach(k => localStorage.removeItem(k));
    });
    await openSettings(page);

    // --- Structure ----------------------------------------------------------
    const sections = await page.locator('.integration-section-heading').allTextContents();
    check('two parent section headings', sections.length === 2);
    const subs = page.locator('.integration-subsection');
    check('6 subsections rendered (1 PMS + 5 scanner)', await subs.count() === 6);
    check('od subsection exists', await page.locator('[data-subsection="open_dental"]').count() === 1);
    for (const s of ['scanner-itero', 'scanner-trios', 'scanner-primescan', 'scanner-medit', 'scanner-shining3d']) {
      check(`scanner subsection ${s}`, await page.locator(`[data-subsection="${s}"]`).count() === 1);
    }

    // --- Default collapsed + header content visible --------------------------
    const states = await subs.evaluateAll(list => list.map(s => ({
      id: s.getAttribute('data-subsection'),
      expanded: s.querySelector('.integration-subsection-header').getAttribute('aria-expanded'),
      hidden: s.querySelector('.integration-subsection-body').hidden,
      badge: (s.querySelector('.integration-status-badge') || {}).textContent || '',
    })));
    // Error states legitimately auto-expand a subsection (auto-reveal rule);
    // everything else must start collapsed.
    const collapsedOK = states.every(s => s.expanded === 'false' && s.hidden === true
      || (s.expanded === 'true' && s.hidden === false && /error/i.test(s.badge)));
    check('collapsed by default unless erroring', collapsedOK);
    const odInitiallyExpanded = states.find(s => s.id === 'open_dental').expanded === 'true';
    if (odInitiallyExpanded) {
      const metaErr = await page.locator('#integrationMeta-open_dental .integration-meta-error').count();
      check('od auto-expanded only because an error is rendered', metaErr > 0);
    }
    // Per-vendor status: primescan/DS Core is Coming soon (implementation
    // underway); every other scanner vendor is Under evaluation.
    const badgeFor = id => (states.find(s => s.id === id) || {}).badge || '';
    check('primescan badge says Coming soon', /coming soon/i.test(badgeFor('scanner-primescan')));
    check('other scanner badges say Under evaluation',
      ['scanner-itero', 'scanner-trios', 'scanner-medit', 'scanner-shining3d']
        .every(id => /under evaluation/i.test(badgeFor(id))));

    await page.screenshot({ path: OUT + '/collapsed-desktop-light.png' });

    // --- Toggle OD (full header click) ---------------------------------------
    const odHeader = page.locator('#integrationSubsectionHeader-open_dental');
    if (odInitiallyExpanded) {
      // Collapse first so the expand assertions below are deterministic.
      await odHeader.click();
      await page.waitForTimeout(300);
      check('od collapses on click', await odHeader.getAttribute('aria-expanded') === 'false');
    }
    await odHeader.click();
    await page.waitForTimeout(300);
    check('od expands, aria true', await odHeader.getAttribute('aria-expanded') === 'true');
    check('od body visible', !(await page.locator('#integrationSubsectionBody-open_dental').isHidden()));

    // OD controls intact
    const odCard = page.locator('#integrationCard-open_dental');
    check('od meta renders connection info', await odCard.locator('.integration-card-meta .integration-meta-row').count() > 0);
    const visibleBtns = await odCard.locator('.integration-card-actions button:visible').allTextContents();
    console.log('   od buttons:', visibleBtns.map(b => b.trim()).join(' | '));
    check('od has at least one action', visibleBtns.length > 0);

    // Test Connection fires the API without leaving the page
    const testBtn = odCard.locator('.integration-action-test');
    if (await testBtn.isVisible().catch(() => false)) {
      await testBtn.click();
      await page.waitForTimeout(1200);
      check('test_connection POST fired', apiCalls.filter(m => m === 'POST').length > 0);
    } else {
      console.log('   (no test button visible - status none)');
    }

    // Disconnect -> confirm modal -> cancel (no destructive call)
    const discBtn = odCard.locator('.integration-action-disconnect');
    if (await discBtn.isVisible().catch(() => false)) {
      const before = apiCalls.filter(m => m === 'POST').length;
      await discBtn.click();
      await page.waitForTimeout(400);
      const confirm = page.locator('.modal:visible, [class*="confirm"]:visible').first();
      check('disconnect opens confirm dialog', await confirm.count() > 0);
      const cancelBtn = page.locator('button:has-text("Cancel")').last();
      if (await cancelBtn.isVisible().catch(() => false)) await cancelBtn.click();
      else await page.keyboard.press('Escape');
      await page.waitForTimeout(400);
      check('cancel disconnect sends no POST', apiCalls.filter(m => m === 'POST').length === before);
    }

    // Manage/Configure opens the config modal
    const cfgBtn = odCard.locator('.integration-action-configure, .integration-action-connect').first();
    if (await cfgBtn.isVisible().catch(() => false)) {
      await cfgBtn.click();
      await page.waitForTimeout(500);
      check('config modal opens', await page.locator('#integrationConfigModal').isVisible());
      await page.keyboard.press('Escape');
      await page.waitForTimeout(300);
      check('config modal closes', !(await page.locator('#integrationConfigModal').isVisible()));
    }

    // --- Multiple subsections open at once ------------------------------------
    await page.locator('#integrationSubsectionHeader-scanner-itero').click();
    await page.locator('#integrationSubsectionHeader-scanner-medit').click();
    await page.waitForTimeout(300);
    const openCount = await page.locator('.integration-subsection.open').count();
    check('od + itero + medit open simultaneously', openCount === 3);
    check('itero body shows description', (await page.locator('#integrationSubsectionBody-scanner-itero').textContent()).includes('under development'));

    // Keyboard: focus + Enter toggles
    await page.locator('#integrationSubsectionHeader-scanner-trios').focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(300);
    check('keyboard Enter toggles trios', await page.locator('#integrationSubsectionHeader-scanner-trios').getAttribute('aria-expanded') === 'true');

    await page.screenshot({ path: OUT + '/expanded-desktop-light.png' });
    await theme(page, 'dark');
    await page.screenshot({ path: OUT + '/expanded-desktop-dark.png' });
    await theme(page, 'light');

    // Collapse one, keep others
    await odHeader.click();
    check('od collapses while scanners stay open', await odHeader.getAttribute('aria-expanded') === 'false'
      && await page.locator('.integration-subsection.open').count() === 3);

    // --- Mobile ----------------------------------------------------------------
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    await page.screenshot({ path: OUT + '/expanded-mobile-light.png', fullPage: true });
    // collapse all for the mobile collapsed shot
    await page.evaluate(() => {
      document.querySelectorAll('.integration-subsection.open .integration-subsection-header').forEach(h => h.click());
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: OUT + '/collapsed-mobile-light.png', fullPage: true });

    console.log(`\n${pass} passed, ${fail} failed -> ${OUT}`);
    process.exitCode = fail ? 1 : 0;
  } catch (e) {
    console.log('FAIL', e.message);
    try { await page.screenshot({ path: OUT + '/error.png' }); } catch {}
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
