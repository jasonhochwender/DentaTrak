/**
 * Insights usage tracking e2e test.
 *
 * Covers the internal Practice Admin "Insights last viewed" feature:
 *
 *   - Opening the Practice Insights screen records
 *     practice_users.practice_insights_viewed_at for the session user in the
 *     active practice only (X-Insights-Visit header on the data GET).
 *   - Opening the Lab Insights subview records lab_insights_viewed_at.
 *   - Each screen activation is a visit: re-activating advances the
 *     timestamp; refresh/filter/settings refetches (no header) do not.
 *   - A failed data load does not record a visit.
 *   - A direct API call without the header never records a visit.
 *   - The Practice Admin Users tab exposes both timestamps (ISO 8601 UTC)
 *     plus lab_insights_available, and renders them.
 *
 * Run: node tests/insights-usage-tracking-e2e-test.js
 *      DT_BROWSER=firefox|webkit for other engines.
 */
'use strict';

const { chromium, firefox, webkit } = require('playwright');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';

const results = [];
function check(name, cond, extra) {
  results.push({ name, pass: !!cond });
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + name + (extra ? '  [' + extra + ']' : ''));
}

async function login(context) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: EMAIL, password: PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json();
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
}

async function getPracticeId(page) {
  return page.evaluate(async () => {
    const r = await fetch('api/get-user-practices.php', { credentials: 'same-origin' });
    const d = await r.json();
    const cur = (d.practices || []).find(p => p.is_current) || (d.practices || [])[0];
    return cur ? String(cur.id || cur.practice_id) : null;
  });
}

async function getUserRow(page, practiceId) {
  // The local session store occasionally returns a transient 503; retry a
  // few times so the assertion targets tracking behavior, not store flakes.
  for (let attempt = 0; attempt < 4; attempt++) {
    const res = await page.evaluate(async ({ url, email }) => {
      const r = await fetch(url, { credentials: 'same-origin' });
      const d = await r.json().catch(() => null);
      if (!d || !d.success) return { success: false };
      const user = (d.users || []).find(u => u.email === email);
      return { user, labAvailable: d.lab_insights_available, success: true };
    }, { url: `${BASE}/api/admin-practices.php?action=users&practice_id=${practiceId}`, email: EMAIL });
    if (res.success && res.user) return res;
    await page.waitForTimeout(700);
  }
  return { success: false };
}

function parseUtc(v) {
  if (!v) return null;
  return new Date(v).getTime();
}

(async () => {
  const browser = await BROWSER.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });

  try {
    await login(context);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.main-tab[data-tab="insights"]', { timeout: 15000 });

    const practiceId = await getPracticeId(page);
    check('practice context resolved', !!practiceId, practiceId);

    const canAnalytics = await page.evaluate(() => !!window.userCanViewAnalytics);
    check('test user has analytics permission', canAnalytics);

    const labFlagOn = await page.evaluate(async () => {
      const r = await fetch('api/get-lab-insights.php?range=12', { credentials: 'same-origin' });
      return r.status !== 404;
    });
    console.log('INFO  lab insights feature flag: ' + (labFlagOn ? 'on' : 'off'));

    const before = await getUserRow(page, practiceId);
    check('admin users action succeeds', before.success === true);
    check('admin users exposes lab_insights_available', typeof before.labAvailable === 'boolean');

    // ── 1. Direct API GET without the visit header must NOT record ──────
    await page.evaluate(async () => {
      await fetch('api/get-analytics.php?team_period=12&team_filter=both&volume_period=12&status_period=active&type_period=active&duration_period=active',
        { credentials: 'same-origin' });
    });
    const afterDirect = await getUserRow(page, practiceId);
    check('GET without X-Insights-Visit does not record (practice)',
      parseUtc(afterDirect.user?.practice_insights_viewed_at) === parseUtc(before.user?.practice_insights_viewed_at),
      `${before.user?.practice_insights_viewed_at} -> ${afterDirect.user?.practice_insights_viewed_at}`);

    // ── 2. Opening the Insights tab records a Practice Insights visit ───
    await page.click('.main-tab[data-tab="insights"]');
    await page.waitForResponse(r =>
      r.url().includes('api/get-analytics.php') && r.status() === 200, { timeout: 20000 });
    await page.waitForTimeout(400);

    const afterPractice = await getUserRow(page, practiceId);
    const pTs1 = parseUtc(afterPractice.user?.practice_insights_viewed_at);
    check('Practice Insights visit recorded', !!pTs1, afterPractice.user?.practice_insights_viewed_at);
    check('practice timestamp is ISO 8601 UTC-qualified',
      /T\d{2}:\d{2}:\d{2}\+00:00$/.test(afterPractice.user?.practice_insights_viewed_at || ''),
      afterPractice.user?.practice_insights_viewed_at);
    check('lab timestamp unchanged by practice visit',
      parseUtc(afterPractice.user?.lab_insights_viewed_at) === parseUtc(before.user?.lab_insights_viewed_at));

    // ── 3. Manual data refresh does NOT advance the timestamp ───────────
    await page.click('#apRefreshData');
    await page.waitForResponse(r =>
      r.url().includes('api/get-analytics.php') && r.status() === 200, { timeout: 20000 });
    await page.waitForTimeout(400);
    const afterRefresh = await getUserRow(page, practiceId);
    check('refresh button does not count as a new visit',
      parseUtc(afterRefresh.user?.practice_insights_viewed_at) === pTs1,
      afterRefresh.user?.practice_insights_viewed_at);

    // ── 4. Re-activating the screen IS a new visit ──────────────────────
    await page.click('.main-tab[data-tab="cases"]');
    await page.waitForTimeout(300);
    await page.waitForTimeout(1100); // ensure the next UTC second differs
    await page.click('.main-tab[data-tab="insights"]');
    // The Insights tab restores the last-used subview; force Practice so the
    // waitForResponse below always matches a get-analytics fetch.
    await page.click('.insights-subtab[data-insights-subtab="practice"]:visible');
    await page.waitForResponse(r =>
      r.url().includes('api/get-analytics.php') && r.status() === 200, { timeout: 20000 });
    await page.waitForTimeout(400);
    const afterRevisit = await getUserRow(page, practiceId);
    const pTs2 = parseUtc(afterRevisit.user?.practice_insights_viewed_at);
    check('re-opening the screen advances the timestamp',
      pTs2 > pTs1, `${afterPractice.user?.practice_insights_viewed_at} -> ${afterRevisit.user?.practice_insights_viewed_at}`);

    // ── 5. Lab Insights subview records only the lab timestamp ──────────
    const labsTab = await page.$('.insights-subtab[data-insights-subtab="labs"]:visible');
    if (labsTab && labFlagOn) {
      await labsTab.click();
      await page.waitForResponse(r =>
        r.url().includes('api/get-lab-insights.php') && r.status() === 200, { timeout: 20000 });
      await page.waitForTimeout(400);
      const afterLab = await getUserRow(page, practiceId);
      const lTs = parseUtc(afterLab.user?.lab_insights_viewed_at);
      check('Lab Insights visit recorded',
        !!lTs && lTs > (parseUtc(before.user?.lab_insights_viewed_at) || 0),
        `${before.user?.lab_insights_viewed_at} -> ${afterLab.user?.lab_insights_viewed_at}`);
      check('practice timestamp unchanged by lab visit',
        parseUtc(afterLab.user?.practice_insights_viewed_at) === pTs2);
      check('admin reports lab_insights_available=true', afterLab.labAvailable === true);

      // Lab refresh button must not re-record
      await page.waitForSelector('#liRefreshData', { timeout: 5000 });
      await page.click('#liRefreshData');
      await page.waitForResponse(r =>
        r.url().includes('api/get-lab-insights.php') && r.status() === 200, { timeout: 20000 });
      await page.waitForTimeout(400);
      const afterLabRefresh = await getUserRow(page, practiceId);
      check('lab refresh does not count as a new visit',
        parseUtc(afterLabRefresh.user?.lab_insights_viewed_at) === lTs);
    } else {
      console.log('SKIP  Lab Insights subview not available (flag off or subtab hidden)');
      const row = await getUserRow(page, practiceId);
      check('lab_insights_available=false when feature unavailable', row.labAvailable === false);
    }

    // ── 6. Failed load does not record ──────────────────────────────────
    const tsBeforeFail = (await getUserRow(page, practiceId)).user;
    await page.route('**/api/get-analytics.php*', route => route.abort());
    await page.click('.main-tab[data-tab="cases"]');
    await page.waitForTimeout(300);
    await page.click('.main-tab[data-tab="insights"]');
    await page.click('.insights-subtab[data-insights-subtab="practice"]:visible');
    await page.waitForTimeout(1500);
    await page.unroute('**/api/get-analytics.php*');
    const afterFail = await getUserRow(page, practiceId);
    check('failed Practice Insights load does not record a visit',
      parseUtc(afterFail.user?.practice_insights_viewed_at) === parseUtc(tsBeforeFail.practice_insights_viewed_at));

    // Pending flag stays set after failure: the NEXT successful activation
    // (this one) still records.
    await page.waitForTimeout(1100);
    await page.click('.main-tab[data-tab="cases"]');
    await page.waitForTimeout(300);
    await page.click('.main-tab[data-tab="insights"]');
    await page.click('.insights-subtab[data-insights-subtab="practice"]:visible');
    await page.waitForResponse(r =>
      r.url().includes('api/get-analytics.php') && r.status() === 200, { timeout: 20000 });
    await page.waitForTimeout(400);
    const afterRecover = await getUserRow(page, practiceId);
    check('successful load after a failure records the visit',
      parseUtc(afterRecover.user?.practice_insights_viewed_at) > parseUtc(tsBeforeFail.practice_insights_viewed_at));

    // ── 7. Practice Admin Users tab renders the columns ─────────────────
    await page.goto(`${BASE}/admin-practices.php`, { waitUntil: 'domcontentloaded' });
    // Open the practice detail → Users tab through the real UI path.
    await page.waitForSelector(`.practice-row[data-practice-id="${practiceId}"]`, { timeout: 20000 });
    await page.click(`.practice-row[data-practice-id="${practiceId}"]`);
    await page.click('button.detail-tab[onclick*="\'users\'"]');
    const opened = await page.waitForSelector('#usersTable', { timeout: 15000 }).then(() => true).catch(() => false);
    if (opened) {
      await page.waitForSelector('#usersTable', { timeout: 5000 });
      const headerTexts = await page.$$eval('#usersTable thead th', ths => ths.map(t => t.textContent.trim()));
      check('Users tab has Practice Insights column', headerTexts.some(h => /Practice Insights/.test(h)));
      check('Users tab has Lab Insights column', headerTexts.some(h => /Lab Insights/.test(h)));
      const rowText = await page.evaluate((email) => {
        const rows = Array.from(document.querySelectorAll('#usersTable tbody tr'));
        const row = rows.find(r => r.textContent.includes(email));
        return row ? row.textContent : '';
      }, EMAIL);
      check('Users tab shows recorded visit for test user', !/No visit recorded/.test(rowText.split('Last seen environment')[1] || rowText), rowText.slice(0, 120));
      const footnote = await page.evaluate(() => document.getElementById('detailContent').textContent);
      check('footnote explains tracking start', /recorded only from when this tracking was introduced/.test(footnote));
    } else {
      check('Users tab renders via renderUsersTab', false, 'users payload empty');
    }

    // Noise filter: intentional route.abort produces a console error.
    const realErrors = consoleErrors.filter(t =>
      !/net::ERR_FAILED|Failed to load resource|get-analytics|fetch|checking for updates|HTTP 503|Failed to load analytics data|Failed to load lab insights/i.test(t));
    check('no unexpected console errors', realErrors.length === 0, realErrors.slice(0, 3).join(' | '));

  } catch (e) {
    check('test completed without exception', false, e.message);
  } finally {
    const passed = results.filter(r => r.pass).length;
    console.log(`\n${passed}/${results.length} checks passed`);
    await browser.close();
    process.exit(passed === results.length ? 0 : 1);
  }
})();
