'use strict';
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { randomUUID } = require('node:crypto');
const BASE = 'http://localhost/DentaTrak';
const suffix = randomUUID();
const ownerEmail = 'e2e-sort-' + suffix + '@example.test';
const memberEmail = 'e2e-sort-member-' + suffix + '@example.test';
const password = 'LocalTest-' + randomUUID() + '!';
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; console.log('PASS ' + name); }
(async () => {
  const browser = await chromium.launch();
  const owner = await browser.newContext();
  const member = await browser.newContext();
  const page = await owner.newPage();
  let ownerCreated = false, memberCreated = false;
  async function helper(data) {
    const r = await owner.request.post(BASE + '/api/test-helpers.php', { data });
    const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d)); return d;
  }
  async function login(context, email) {
    const r = await context.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email, password } });
    assert.equal((await r.json()).success, true);
  }
  async function state(context) {
    const r = await context.request.get(BASE + '/api/case-view-preferences.php');
    const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d)); return d;
  }
  async function loaded() {
    await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    if (page.url().includes('accept-terms.php')) {
      await page.check('#termsAccepted'); await page.click('#acceptBtn');
      await page.waitForURL('**/main.php');
    }
    await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
  }
  async function switchApi(practiceId) {
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const r = await owner.request.post(BASE + '/api/switch-practice.php', { headers: { 'X-CSRF-Token': token }, data: { practice_id: practiceId } });
    assert.equal((await r.json()).success, true);
  }
  try {
    const a = await helper({ action: 'setup_test_user', email: ownerEmail, password }); ownerCreated = true;
    await login(owner, ownerEmail);
    await loaded();
    const first = await state(owner);
    await helper({ action: 'setup_practice_member', practiceId: a.practice_id, email: memberEmail, password, role: 'user', canEditCases: true }); memberCreated = true;
    await login(member, memberEmail);
    const memberPage = await member.newPage();
    await memberPage.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    const memberToken = await memberPage.locator('meta[name="csrf-token"]').getAttribute('content');
    const selected = await member.request.post(BASE + '/api/switch-practice.php', { headers: { 'X-CSRF-Token': memberToken }, data: { practice_id: Number(a.practice_id) } });
    assert.equal((await selected.json()).success, true);
    const b = await helper({ action: 'seed_owned_practices', email: ownerEmail, count: 1 });
    const secondPractice = b.practice_ids[0];
    const caseIds = [];
    const ownerToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    for (const [status, dueDate] of [['Designed', '2027-01-01'], ['Originated', '2027-03-01']]) {
      const response = await owner.request.post(BASE + '/api/create-case.php', { form: {
        csrf_token: ownerToken, patientFirstName: 'DentaTrakTest', patientLastName: suffix,
        patientDOB: '1990-01-01', patientGender: 'Female', dentistName: 'Dr Sort', caseType: 'Veneer', status, dueDate,
      } });
      const result = await response.json(); assert.equal(result.success, true, JSON.stringify(result));
      const c = result.caseData || result.case || result; caseIds.push(String(c.id || c.caseId));
    }
    await loaded();
    await page.evaluate(() => window.caseFilterSort.setSort([{ field: 'due', direction: 'asc' }]));
    await page.evaluate(() => window.caseFilterSort.flush());
    await page.waitForTimeout(1100);
    const moved = await member.request.post(BASE + '/api/update-case-status.php', { headers: { 'X-CSRF-Token': memberToken }, data: { caseId: caseIds[1], status: 'Designed' } });
    assert.equal((await moved.json()).success, true);
    await page.waitForFunction(ids => {
      const cards = Array.from(document.querySelectorAll('.kanban-column[data-status="Designed"] .kanban-card'));
      return JSON.stringify(cards.map(c => c.dataset.caseId)) === JSON.stringify(ids);
    }, caseIds, { timeout: 30000 });
    check('real second-user update is polled and placed in sorted destination', true);
    await page.evaluate(() => window.caseFilterSort.setSort([{ field: 'type', direction: 'asc' }]));
    await page.evaluate(() => window.caseFilterSort.flush());
    check('same-practice different user starts with default preferences', (await state(member)).preferences.sort.length === 0);
    const queried = await member.request.get(BASE + '/api/case-view-preferences.php?userId=' + first.userId + '&practiceId=' + secondPractice);
    check('GET ignores target IDs and only returns authenticated membership', (await queried.json()).userId !== first.userId);
    await switchApi(secondPractice);
    await page.evaluate(() => window.caseFilterSort.setSort([{ field: 'dentist', direction: 'desc' }]));
    check('stale page save rejected after practice switch', !(await page.evaluate(() => window.caseFilterSort.flush())));
    check('stale save did not overwrite destination preferences', (await state(owner)).preferences.sort.length === 0);
    await loaded();
    await page.evaluate(() => window.caseFilterSort.setSort([{ field: 'due', direction: 'desc' }]));
    await page.click('#kanbanFilterToggle');
    await page.fill('#patientSearch', 'Independent practice filter');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.evaluate(pid => { switchPractice(pid); switchPractice(pid); }, first.practiceId),
    ]);
    await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
    await switchApi(secondPractice);
    check('switch flushes pending filter/sort saves before navigation', (await state(owner)).preferences.filters.patientSearch === 'Independent practice filter');
    await switchApi(first.practiceId); await loaded();
    check('returning to original practice restores its independent sort', await page.evaluate(() => window.caseFilterSort.getSort()[0].field === 'type'));
    check('practice filters are isolated', await page.locator('#patientSearch').inputValue() === '');
    const before = await state(owner);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const memberState = await state(member);
    const response = await owner.request.post(BASE + '/api/case-view-preferences.php', { headers: { 'X-CSRF-Token': token }, data: { userId: memberState.userId, practiceId: before.practiceId, preferences: before.preferences } });
    check('targeting another real member is rejected', response.status() === 409);
    for (const practiceId of [secondPractice, first.practiceId, secondPractice, first.practiceId]) await switchApi(practiceId);
    check('rapid repeated switches retain correct membership preferences', (await state(owner)).preferences.sort[0].field === 'type');
    check('other member remains unchanged', (await state(member)).preferences.sort.length === 0);
    console.log(checks + ' actual authenticated user/practice isolation checks passed.');
  } finally {
    await page.evaluate(() => window.caseFilterSort && window.caseFilterSort.flush()).catch(() => {});
    if (memberCreated) await helper({ action: 'delete_test_users', marker: 'DentaTrakTest', emails: [memberEmail] });
    if (ownerCreated) await helper({ action: 'cleanup_test_user', email: ownerEmail });
    await browser.close();
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
