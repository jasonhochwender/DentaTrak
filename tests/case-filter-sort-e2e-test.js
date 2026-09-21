'use strict';
const assert = require('node:assert/strict');
const { chromium, firefox, webkit } = require('playwright');
const BASE = 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';
const marker = 'DentaTrakTest-Sort-' + Date.now();
const browserType = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'];
let count = 0;
function check(name, condition) { assert.ok(condition, name); count++; console.log('PASS ' + name); }
async function login(context) {
  const r = await context.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email: EMAIL, password: PASSWORD } });
  assert.equal((await r.json()).success, true, 'local test login');
}
async function open(page) {
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
  await page.waitForFunction(() => !!window.caseFilterSort && !!window.caseListView);
}
async function prefs(context) {
  for (let i = 0; i < 3; i++) {
    const r = await context.request.get(BASE + '/api/case-view-preferences.php');
    if (r.status() === 503) { await new Promise(resolve => setTimeout(resolve, 500)); continue; }
    const d = await r.json(); assert.equal(d.success, true); return d;
  }
  throw new Error('Local preference storage unavailable');
}
async function save(context, page, data, overrides = {}) {
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return context.request.post(BASE + '/api/case-view-preferences.php', { headers: { 'X-CSRF-Token': token }, data: Object.assign(data, overrides) });
}
async function flushed(page) { assert.equal(await page.evaluate(() => window.caseFilterSort.flush()), true, 'preference save'); }
async function boardIds(page, ids, status = 'Originated') {
  return page.locator('.kanban-column[data-status="' + status + '"] .kanban-card').evaluateAll((cards, ids) => cards.map(c => c.dataset.caseId).filter(id => ids.includes(id)), ids);
}
(async function () {
  const browser = await browserType.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const ids = [], errors = [];
  let original;
  let writes = 0, caseLoads = 0;
  page.on('request', r => { if (r.url().includes('api/list-cases.php')) caseLoads++; });
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => { if (r.url().includes('case-view-preferences.php') && r.method() === 'POST') writes++; });
  try {
    await login(context);
    await open(page);
    original = await prefs(context);
    check('initialization does not save preferences', writes === 0);
    await save(context, page, { userId: original.userId, practiceId: original.practiceId, preferences: { filters: {}, sort: [] } });
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    for (const [patientLastName, caseType, dueDate] of [['Zulu', 'Veneer', '2027-03-01'], ['Alpha', 'Veneer', '2027-01-01'], ['Beta', 'Denture', '2027-02-01']]) {
      const r = await context.request.post(BASE + '/api/create-case.php', { form: {
        patientFirstName: marker, patientLastName, patientDOB: '1990-01-01', patientGender: 'Female', dentistName: 'Dr Sort',
        caseType, dueDate, status: patientLastName === 'Beta' ? 'Designed' : 'Originated', notes: marker, csrf_token: token,
        // Veneer requires Material (canonical case-type rule in case-types.php).
        material: caseType === 'Veneer' ? 'Zirconia' : '',
      } });
      const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d));
      const c = d.caseData || d.case || d; ids.push(String(c.id || c.caseId));
    }
    await open(page);
    await page.click('#kanbanFilterToggle');
    check('renamed accessible panel control', (await page.locator('#kanbanFilterToggle').textContent()).includes('Filter & Sort'));
    check('one initial sort row', await page.locator('.case-sort-row').count() === 1);
    const baseline = await boardIds(page, ids);
    await page.selectOption('#caseSortField0', 'type');
    await page.click('#addCaseSort');
    await page.selectOption('#caseSortField1', 'due');
    await page.selectOption('#caseSortDirection1', 'desc');
    await page.click('#addCaseSort');
    await page.selectOption('#caseSortField2', 'patient');
    await flushed(page);
    check('maximum three criteria', await page.locator('#addCaseSort').isDisabled());
    check('duplicate fields unavailable', await page.locator('#caseSortField1 option[value="type"]').isDisabled());
    assert.deepEqual(await boardIds(page, ids), [ids[0], ids[1]]);
    assert.deepEqual(await boardIds(page, ids, 'Designed'), [ids[2]]);
    check('board three-priority ordering stays within workflow columns', true);
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row');
    const listIds = await page.locator('.case-list-row').evaluateAll((rows, ids) => rows.map(r => r.dataset.caseId).filter(id => ids.includes(id)), ids);
    assert.deepEqual(listIds, [ids[2], ids[0], ids[1]]); check('list matches shared board criteria', true);
    check('multi-sort does not misuse aria-sort', await page.locator('.cl-th[aria-sort]').count() === 0);
    check('header shows priority', (await page.locator('[data-sort-key="due"]').textContent()).includes('2'));
    await page.locator('.case-list-row[data-case-id="' + ids[0] + '"] .case-list-expand').click();
    const expanded = await page.locator('.case-list-detail-row[data-case-id="' + ids[0] + '"]').elementHandle();
    await page.locator('[data-sort-key="due"]').click();
    check('header replaces multi-sort with default ascending', await page.evaluate(() => JSON.stringify(window.caseFilterSort.getSort()) === JSON.stringify([{ field: 'due', direction: 'asc' }])));
    check('expanded detail node survives sorting', await expanded.evaluate(el => el.isConnected));
    check('controls synchronize after header click', await page.locator('#caseSortField0').inputValue() === 'due' && await page.locator('.case-sort-row').count() === 1);
    await page.locator('[data-sort-key="due"]').click();
    check('single header reverses direction', await page.locator('#caseSortDirection0').inputValue() === 'desc');
    await page.locator('.case-list-row[data-case-id="' + ids[0] + '"] .case-actions-toggle').click();
    await page.evaluate(() => window.caseFilterSort.setSort([{ field: 'patient', direction: 'asc' }]));
    check('sort does not orphan or close open case menu', await page.locator('#caseActionsMenu.open').count() === 1 && await page.locator('.case-list-row[data-case-id="' + ids[0] + '"] .case-actions-toggle').getAttribute('aria-expanded') === 'true');
    await page.keyboard.press('Escape');
    await Promise.all([
      page.waitForResponse(r => r.url().includes('api/list-cases.php?') && r.url().includes(marker)),
      page.fill('#patientSearch', marker),
    ]);
    await flushed(page);
    await page.waitForTimeout(400);
    const idleCaseLoads = caseLoads;
    await page.waitForTimeout(900);
    check('filtered renders do not trigger recursive refetches', caseLoads === idleCaseLoads);
    const persisted = await prefs(context);
    assert.deepEqual([persisted.preferences.filters.patientSearch, persisted.preferences.sort[0].field], [marker, 'patient']);
    check('server stores filters and sort', true);
    await open(page);
    check('page refresh restores filters and sort', await page.locator('#patientSearch').inputValue() === marker && await page.evaluate(() => window.caseFilterSort.getSort()[0].field) === 'patient');
    const second = await browser.newContext();
    await login(second);
    const secondPage = await second.newPage(); await open(secondPage);
    check('new authenticated session restores server-backed state', await secondPage.locator('#patientSearch').inputValue() === marker && await secondPage.evaluate(() => window.caseFilterSort.getSort()[0].field) === 'patient');
    await second.close();
    await page.click('#kanbanFilterToggle');
    await page.click('#resetCaseSort');
    await flushed(page);
    check('reset sort preserves filter', await page.locator('#patientSearch').inputValue() === marker && (await prefs(context)).preferences.sort.length === 0);
    await page.click('#clearFiltersBtn');
    await flushed(page);
    await page.waitForTimeout(800);
    await page.click('#boardViewToggle');
    await page.waitForTimeout(200);
    assert.deepEqual(await boardIds(page, ids), baseline); check('reset restores existing board insertion order', true);
    await page.selectOption('#caseSortField0', 'due');
    await flushed(page);
    let statusPosts = 0;
    page.on('request', r => { if (r.url().includes('update-case-status.php')) statusPosts++; });
    page.on('response', async r => {
      if (!r.url().includes('update-case-status.php')) return;
      const result = await r.json().catch(() => ({}));
      console.log('STATUS MOVE', r.request().postDataJSON().caseId, r.status(), result.success, result.message || '');
    });
    const card = '.kanban-card[data-case-id="' + ids[0] + '"]';
    await page.locator(card).dragTo(page.locator('.kanban-column[data-status="Originated"] .kanban-column-body'));
    check('within-column drop cannot override sort or save status', statusPosts === 0);
    await page.locator(card).scrollIntoViewIfNeeded();
    await page.locator(card).dragTo(page.locator('.kanban-column[data-status="Designed"] .kanban-column-body'));
    await page.waitForFunction(id => {
      const c = document.querySelector('.kanban-column[data-status="Designed"] .kanban-card[data-case-id="' + id + '"]');
      return c && JSON.parse(c.dataset.caseJson).status === 'Designed';
    }, ids[0]);
    await page.waitForTimeout(200);
    assert.deepEqual(await boardIds(page, ids, 'Designed'), [ids[2], ids[0]]);
    check('cross-column drag persists status and lands in sorted destination position', statusPosts === 1);
    const forbidden = await save(context, page, { userId: original.userId + 1, practiceId: original.practiceId, preferences: persisted.preferences });
    check('another user cannot be targeted', forbidden.status() === 409);
    const stale = await save(context, page, { userId: original.userId, practiceId: original.practiceId + 1, preferences: persisted.preferences });
    check('stale practice save rejected', stale.status() === 409);
    const missingCsrf = await context.request.post(BASE + '/api/case-view-preferences.php', { data: { userId: original.userId, practiceId: original.practiceId, preferences: persisted.preferences } });
    check('CSRF enforced', missingCsrf.status() === 403);
    const bad = await save(context, page, { userId: original.userId, practiceId: original.practiceId, preferences: { sort: [{ field: 'type', direction: 'asc' }, { field: 'type', direction: 'desc' }] } });
    check('duplicate sort rejected by live API', bad.status() === 400);
    const anonymous = await browser.newContext();
    check('unauthenticated preferences denied', (await anonymous.request.get(BASE + '/api/case-view-preferences.php')).status() === 401); await anonymous.close();
    await page.route('**/api/case-view-preferences.php', route => route.fulfill({ status: 503, contentType: 'application/json', body: '{"success":false}' }));
    await page.selectOption('#caseSortDirection0', 'desc');
    check('failed save does not break board', !(await page.evaluate(() => window.caseFilterSort.flush())) && await page.locator('#caseViewSaveError').isVisible());
    await page.unroute('**/api/case-view-preferences.php');
    await page.click('#retryCaseViewSave'); await flushed(page);
    check('failed save can be retried', await page.locator('#caseViewSaveError').isHidden());
    await page.setViewportSize({ width: 375, height: 812 });
    check('mobile panel stays within viewport', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    await page.selectOption('#mobileKanbanSelect', 'Originated');
    await page.locator('.kanban-card[data-case-id="' + ids[1] + '"] .case-actions-toggle').click();
    await page.selectOption('#caseActionsMenu .case-actions-move-select', 'Designed');
    await page.waitForFunction(id => {
      const c = document.querySelector('.kanban-card[data-case-id="' + id + '"]');
      return c && JSON.parse(c.dataset.caseJson).status === 'Designed';
    }, ids[1]);
    await page.waitForTimeout(200);
    assert.deepEqual(await boardIds(page, ids, 'Designed'), [ids[0], ids[2], ids[1]]);
    check('mobile Move to preserves sorted destination order', true);
    await page.click('#listViewToggle');
    await page.locator('.case-list-row[data-case-id="' + ids[1] + '"] .case-list-open').click();
    check('mobile Open case still works', await page.locator('#createCaseModal').isVisible());
    check('no runtime errors', errors.length === 0);
    console.log(count + ' actual browser/API checks passed (' + (process.env.DT_BROWSER || 'chromium') + ').');
  } finally {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    if (original) {
      await page.evaluate(() => window.caseFilterSort.flush()).catch(() => {});
      await save(context, page, { userId: original.userId, practiceId: original.practiceId, preferences: original.preferences });
    }
    if (ids.length) {
      const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      await context.request.post(BASE + '/api/test-helpers.php', { data: { action: 'delete_test_cases', case_ids: ids, csrf_token: token } });
    }
    await browser.close();
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
