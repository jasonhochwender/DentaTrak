'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'js/app.js'), 'utf8');
const notifications = fs.readFileSync(path.join(root, 'js/notifications.js'), 'utf8');

function slice(start, end) {
  const from = app.indexOf(start);
  const to = app.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, `Missing app.js extraction boundary: ${start} / ${end}`);
  return app.slice(from, to);
}

const appFunctions = [
  slice('  var createBtn = document.querySelector', '  function renderCaseRevisionHistory('),
  slice('  function resetCreateCaseFormToNew()', '  function populateCreateCaseForm('),
  slice('  var openingCaseById =', '  function openCaseModalForView('),
  slice('  if (caseViewRetry) caseViewRetry.addEventListener', '  if (caseViewErrorClose)')
].join('\n');

const tests = [];
function test(name, run) { tests.push({ name, run }); }
function notification(type, extra = {}) {
  return { id: 1, case_id: 'A', type, is_read: false, from_user_name: 'Test User', created_at: new Date().toISOString(), ...extra };
}

async function harness(browser, options = {}) {
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const page = await context.newPage();
  page.setDefaultTimeout(3000);
  const errors = [];
  const network = [];
  page.on('pageerror', error => errors.push(error.message));
  await context.route('**/*', route => {
    network.push(route.request().url());
    return route.abort('blockedbyclient');
  });
  await page.setContent(`<style>
    .hidden { display:none!important } #createCaseModal { display:none } .notification-item { padding:12px }
    #createCaseModal .modal-body { max-height:400px; overflow:auto } .case-tab-panel { display:none }
    .attachments-section-header { margin-top:600px } #notificationDropdown { display:block }
  </style><meta name="csrf-token" content="isolated-test-token">
  <button id="notificationBell">Notifications</button><span id="notificationBadge" class="hidden"></span>
  <div id="notificationDropdown"><button id="notificationDropdownClose">Close notifications</button>
  <button class="notification-mark-all">Mark all read</button><div id="notificationList"></div></div>
  <div id="createCaseModal"><h2 class="modal-title"></h2><button id="createCaseClose">Close</button>
  <div class="modal-body"><div id="caseViewTabs"><button class="case-tab" data-tab="details">Details</button>
  ${options.comments === false ? '' : '<button class="case-tab" data-tab="comments">Comments</button>'}
  <button class="case-tab" data-tab="history">History</button></div>
  <div id="caseViewLoading" style="display:none">Loading</div>
  <div id="caseViewError" style="display:none">Case unavailable<button id="caseViewRetry">Retry</button><button id="caseViewErrorClose">Close error</button></div>
  <form id="createCaseForm"><input id="patientFirstName" name="patientFirstName"><input id="notes" name="notes">
  <div class="attachments-section-header"><h3 class="attachments-title">Attachments</h3></div>
  <div class="attachments-grid"><div id="photos-files"></div><input type="file" class="attachment-input"></div>
  <button type="button" id="createCaseSubmit">Save</button><button type="button" id="createCaseCancel">Cancel</button></form>
  ${options.comments === false ? '' : '<div id="caseCommentsPanel" class="case-tab-panel"><div id="caseCommentsList"></div><textarea id="caseCommentInput"></textarea></div>'}
  <div id="caseRevisionHistoryPanel" class="case-tab-panel"></div></div></div>`);
  await page.evaluate(options => {
    window.fixture = {
      rows: options.rows || [], requests: [], mutations: [], opened: [], calls: [], errors: [],
      pending: [], plans: options.plans || {}, readFailure: options.readFailure || false,
      holdRead: options.holdRead || false, readResolvers: [], scrolls: [], results: {}
    };
    window.t = key => key;
    window.showToast = (message, kind) => fixture.errors.push({ message, kind });
    window.featureFlags = { SHOW_NOTIFICATIONS: false, SHOW_COMMENTS: options.comments !== false };
    window.NetworkErrorHandler = { isNetworkError: () => true };
    const nativeScroll = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function(...args) {
      fixture.scrolls.push({ id: this.id, className: this.className });
      return nativeScroll.apply(this, args);
    };
    window.fetch = async (url, init = {}) => {
      const parsed = new URL(String(url), 'https://isolated.invalid/');
      const body = init.body ? JSON.parse(init.body) : null;
      fixture.requests.push({ url: parsed.pathname + parsed.search, method: init.method || 'GET', body });
      const response = data => ({ ok: true, status: 200, json: async () => data });
      if (parsed.pathname === '/api/get-case.php') {
        const id = parsed.searchParams.get('id');
        const plans = fixture.plans[id] || [];
        const plan = plans.shift() || {};
        const data = plan.data || { success: true, can_edit: true, case: { id, case_id: id, patientFirstName: `Patient ${id}`, notes: `Notes ${id}`, is_archived: false, files: [{ id: `file-${id}`, name: `File ${id}` }] } };
        if (plan.hold) return new Promise((resolve, reject) => fixture.pending.push({ id, resolve: () => resolve(response(data)), reject }));
        if (plan.reject) throw new TypeError('Simulated offline');
        return response(data);
      }
      if (parsed.pathname === '/api/notifications.php') {
        if (body) {
          fixture.mutations.push(body);
          if (fixture.holdRead && body.action === 'mark_read') await new Promise(resolve => fixture.readResolvers.push(resolve));
          if (fixture.readFailure && body.action === 'mark_read') return response({ success: false, message: 'Read update rejected' });
          const row = fixture.rows.find(row => String(row.id) === String(body.notification_id));
          if (row && body.action === 'mark_read') row.is_read = true;
          if (body.action === 'dismiss') fixture.rows = fixture.rows.filter(row => String(row.id) !== String(body.notification_id));
          return response({ success: true });
        }
        if (parsed.searchParams.get('action') === 'count') return response({ success: true, count: fixture.rows.filter(row => !row.is_read).length });
        return response({ success: true, notifications: fixture.rows });
      }
      if (parsed.pathname === '/api/notification-destination.php') return response({ success: true, case_id: 'UNEXPECTED-DESTINATION' });
      fixture.errors.push({ message: `Unexpected mocked request: ${url}`, kind: 'harness' });
      throw new Error(`Unexpected mocked request: ${url}`);
    };
  }, options);
  await page.addScriptTag({ content: `
    var pageLoadingOverlay = null, caseModalOpener = null, hasUnsavedChanges = false, originalFormData = null, isSubmitting = false;
    function checkBillingForCaseCreation() { return true; }
    function clearFileSelections() { document.getElementById('photos-files').textContent = ''; }
    function updateTrackingNumberLink() {}
    function trackFormChanges() {}
    function clearCreateCaseErrors() {}
    function loadCaseRevisionHistory(id) {
      currentEditCaseId = id || null;
      setCaseModalActiveTab('details');
      var list = document.getElementById('caseCommentsList');
      if (list) { list.textContent = id ? 'Comments ' + id : ''; list.dataset.caseId = id || ''; }
    }
    function populateFixture(data, mode) {
      var id = data.id || data.case_id;
      var form = document.getElementById('createCaseForm');
      form.dataset.caseId = id;
      document.getElementById('patientFirstName').value = data.patientFirstName || 'Patient ' + id;
      document.getElementById('notes').value = data.notes || 'Notes ' + id;
      document.getElementById('photos-files').textContent = 'File ' + id;
      document.getElementById('patientFirstName').readOnly = mode === 'view';
      fixture.opened.push({ id: String(id), mode });
    }
    function editCaseHandler(data) {
      populateFixture(data, 'edit');
      openCreateCase();
      loadCaseRevisionHistory(data.id || data.case_id);
    }
    window.editCaseHandler = editCaseHandler;
    function openCaseModalForView(data) {
      populateFixture(data, 'view');
      createCaseModal.style.display = 'block';
      resetCaseViewState();
      loadCaseRevisionHistory(data.id || data.case_id);
    }
    ${appFunctions}
    attachCaseModalTabHandlers();
    document.getElementById('createCaseClose').addEventListener('click', closeCreateCase);
    window.testCloseCase = closeCreateCase;
    window.testOpenNew = function() { resetCreateCaseFormToNew(); loadCaseRevisionHistory(null); openCreateCase(); };
    var actualOpenCaseById = window.openCaseById;
    window.openCaseById = function(id, options) {
      var row = document.querySelector('.notification-item[data-case-id="' + id + '"]');
      fixture.calls.push({ id: String(id), options: options || {}, unread: row ? row.classList.contains('unread') : null,
        dot: row ? !!row.querySelector('.notification-unread-dot') : null,
        badge: document.getElementById('notificationBadge').textContent });
      var result = actualOpenCaseById(id, options);
      fixture.lastWasPromise = !!result && typeof result.then === 'function';
      fixture.lastOpenPromise = result;
      return result;
    };
  ` });
  await page.addScriptTag({ content: notifications });
  await page.evaluate(() => { refreshNotificationCount(); openNotificationDropdown(); });
  await page.waitForFunction(() => !document.getElementById('notificationList').textContent.includes('Loading...'));
  return {
    page,
    async finish() {
      await page.waitForTimeout(180);
      assert.deepEqual(errors, [], 'Uncaught browser exceptions');
      assert.deepEqual(network, [], 'No browser network requests may escape the fixture');
      assert.equal(await page.evaluate(() => fixture.errors.some(error => error.kind === 'harness')), false);
      await context.close();
    },
    async dispose() { await context.close(); }
  };
}

async function open(page, id, options = {}) {
  const result = await page.evaluate(async ({ id, options }) => {
    const promise = window.openCaseById(id, options);
    const isPromise = !!promise && typeof promise.then === 'function';
    return { isPromise, value: await promise };
  }, { id, options });
  assert.equal(result.isPromise, true, 'openCaseById must return a completion Promise');
  assert.equal(result.value, true, 'Authorized case must resolve true');
}

async function state(page) {
  return page.evaluate(() => ({
    id: document.getElementById('createCaseForm').dataset.caseId || null,
    patient: document.getElementById('patientFirstName').value,
    files: document.getElementById('photos-files').textContent,
    comments: document.getElementById('caseCommentsList')?.textContent || '',
    tab: document.querySelector('.case-tab-active')?.dataset.tab,
    modal: document.getElementById('createCaseModal').style.display,
    error: document.getElementById('caseViewError').style.display,
    opened: fixture.opened,
    calls: fixture.calls
  }));
}

async function withHarness(browser, options, run) {
  const h = await harness(browser, options);
  try { await run(h.page); await h.finish(); } finally { await h.dispose(); }
}

test('editable case uses the board edit handler and explicit Details destination', browser => withHarness(browser, {}, async page => {
  await open(page, 'A', { tab: 'details' });
  const current = await state(page);
  assert.equal(current.id, 'A');
  assert.equal(current.tab, 'details');
  assert.deepEqual(current.opened, [{ id: 'A', mode: 'edit' }]);
  assert.equal(await page.locator('#patientFirstName').evaluate(el => el.readOnly), false);
}));

const destinations = [
  ['mention', 'comments', { comment_id: 'comment-7' }],
  ['comment', 'comments'],
  ['new_comment', 'comments'],
  ['comment_reply', 'comments', { metadata: { comment_id: 'comment-8' } }],
  ['mention', 'comments', { metadata: JSON.stringify({ comment_id: 'comment-9' }) }],
  ['file_added', 'files'], ['file_deleted', 'files'], ['attachment_added', 'files'], ['file_changed', 'files'],
  ['case_details_changed', 'details'], ['due_date_changed', 'details'], ['appointment_date_changed', 'details'],
  ['assignment_changed', 'details'], ['status_changed', 'details'], ['unknown_event', 'details'],
  ['file_added', 'details', { categories: ['files', 'details'] }],
  ['mention', 'details', { categories: ['comments', 'details'] }]
];

for (const [type, destination, extra = {}] of destinations) {
  test(`rendered ${type} row routes to ${destination} (${JSON.stringify(extra)})`, browser => withHarness(browser, { rows: [notification(type, extra)] }, async page => {
    await page.locator('.notification-item-text').click();
    await page.waitForFunction(() => fixture.calls.length === 1);
    await page.evaluate(() => fixture.lastOpenPromise);
    const current = await state(page);
    assert.equal(current.calls[0].options.tab, destination, 'Rendered row must pass the semantic destination');
    const metadata = typeof extra.metadata === 'string' ? JSON.parse(extra.metadata) : extra.metadata;
    const commentId = extra.comment_id || metadata?.comment_id;
    if (commentId) assert.equal(String(current.calls[0].options.commentId), commentId);
    assert.equal(current.id, 'A');
    assert.equal(current.tab, destination === 'comments' ? 'comments' : 'details');

    // Read-state persistence is decoupled from navigation: the case opens
    // before the server confirms the mark_read mutation.
    assert.equal(current.calls[0].unread, true, 'Read state is still unread at navigation time');
    assert.equal(current.calls[0].dot, true, 'Unread dot is still present at navigation time');
    assert.equal(current.calls[0].badge, '1', 'Badge is unchanged at navigation time');

    // Once the mark_read response is received, the UI updates without a list reload.
    await page.waitForFunction(() => !document.querySelector('.notification-item.unread'));
    assert.equal(await page.locator('.notification-mark-all').isDisabled(), true);
    assert.equal(await page.locator('#notificationBadge').textContent(), '', 'Badge clears after read is confirmed');
    assert.equal(await page.locator('.case-tab[data-tab="files"]').count(), 0, 'Files belongs to Attachments within Details');
    if (destination === 'files') {
      await page.waitForFunction(() => fixture.scrolls.some(item => /attachments/.test(item.className)));
      const target = await page.locator('.attachments-section-header').boundingBox();
      const body = await page.locator('#createCaseModal .modal-body').boundingBox();
      assert.ok(target.y >= body.y - 2 && target.y < body.y + body.height, 'Attachments heading is scrolled into view');
    }
  }));
}

test('case navigation starts before read mutation settles, without waiting for a list reload', browser => withHarness(browser, { rows: [notification('comment')], holdRead: true }, async page => {
  await page.locator('.notification-item-text').click();
  // Navigation is decoupled from mark_read: the case opens immediately.
  await page.waitForFunction(() => fixture.calls.length === 1);
  assert.equal(await page.evaluate(() => fixture.readResolvers.length), 1);
  assert.equal(await page.locator('.notification-item').evaluate(el => el.classList.contains('unread')), true);
  await page.evaluate(() => fixture.readResolvers.shift()());
  await page.waitForFunction(() => !document.querySelector('.notification-item.unread'));
  assert.equal((await state(page)).calls[0].unread, true, 'Read state did not change before navigation');
  assert.equal(await page.evaluate(() => fixture.requests.filter(request => request.url === '/api/notifications.php?limit=20').length), 1);
}));

test('failed read mutation preserves unread state and reports error but still opens case', browser => withHarness(browser, { rows: [notification('comment')], readFailure: true }, async page => {
  await page.locator('.notification-item-text').click();
  await page.waitForFunction(() => fixture.calls.length === 1);
  await page.evaluate(() => fixture.lastOpenPromise);
  const current = await state(page);
  assert.equal(current.id, 'A');
  assert.equal(current.calls[0].unread, true);
  assert.equal(current.calls[0].dot, true);
  assert.equal(await page.locator('#notificationBadge').textContent(), '1');
  assert.ok(await page.evaluate(() => fixture.errors.some(error => error.kind === 'error')), 'A visible error must be surfaced');
}));

test('dismiss button removes a rendered row without opening its case', browser => withHarness(browser, { rows: [notification('mention')] }, async page => {
  await page.locator('.notification-item-dismiss').click();
  await page.waitForFunction(() => !document.querySelector('.notification-item'));
  assert.deepEqual((await state(page)).calls, []);
  assert.deepEqual(await page.evaluate(() => fixture.mutations.map(item => item.action)), ['dismiss']);
}));

test('notification without case_id can be read but never navigates', browser => withHarness(browser, { rows: [notification('comment', { case_id: null })] }, async page => {
  await page.locator('.notification-item-text').click();
  await page.waitForFunction(() => fixture.mutations.length === 1);
  await page.waitForTimeout(50);
  assert.deepEqual((await state(page)).calls, []);
  assert.equal(await page.evaluate(() => fixture.requests.some(request => /get-case|notification-destination/.test(request.url))), false);
}));

test('sequential A then B and reopening Details never retain prior case/tab content', browser => withHarness(browser, {}, async page => {
  await open(page, 'A', { tab: 'comments', commentId: 'a-comment' });
  assert.equal((await state(page)).tab, 'comments');
  await open(page, 'B', { tab: 'files' });
  let current = await state(page);
  assert.equal(current.id, 'B');
  assert.equal(current.patient, 'Patient B');
  assert.equal(current.comments, 'Comments B');
  assert.equal(current.files, 'File B');
  assert.equal(current.tab, 'details');
  await page.locator('#createCaseClose').click();
  await open(page, 'A', { tab: 'details' });
  await page.waitForTimeout(200);
  current = await state(page);
  assert.equal(current.id, 'A');
  assert.equal(current.tab, 'details');
  await page.evaluate(() => testCloseCase());
  await page.evaluate(() => testOpenNew());
  current = await state(page);
  assert.equal(current.id, null);
  assert.equal(current.patient, '');
  assert.equal(current.files, '');
  assert.equal(current.comments, '');
}));

test('hidden or absent board card does not prevent authorized fetch', browser => withHarness(browser, {}, async page => {
  assert.equal(await page.locator('[data-case-id]').count(), 0);
  await open(page, 'NOT-ON-BOARD', { tab: 'comments' });
  assert.equal((await state(page)).id, 'NOT-ON-BOARD');
  assert.equal(await page.evaluate(() => fixture.requests.filter(request => request.url.includes('get-case.php?id=NOT-ON-BOARD')).length), 1);
}));

for (const [name, data] of [
  ['non-editable', { success: true, can_edit: false, case: { id: 'R', is_archived: false } }],
  ['archived', { success: true, can_edit: true, case: { id: 'R', is_archived: true } }]
]) {
  test(`${name} case uses read-only view rather than board editing`, browser => withHarness(browser, { plans: { R: [{ data }] } }, async page => {
    await open(page, 'R', { tab: 'details' });
    assert.deepEqual((await state(page)).opened, [{ id: 'R', mode: 'view' }]);
    assert.equal(await page.locator('#patientFirstName').evaluate(el => el.readOnly), true);
  }));
}

test('API access denial returns false and clears prior case rather than exposing stale data', browser => withHarness(browser, { plans: { DENIED: [{ data: { success: false, message: 'Access denied' } }] } }, async page => {
  await open(page, 'A', { tab: 'comments' });
  const result = await page.evaluate(() => openCaseById('DENIED', { tab: 'details' }));
  assert.equal(result, false);
  const current = await state(page);
  assert.equal(current.error, 'block');
  assert.equal(current.id, null);
  assert.equal(current.patient, '');
  assert.equal(current.files, '');
  assert.equal(current.comments, '');
  assert.deepEqual(current.opened, [{ id: 'A', mode: 'edit' }]);
  assert.ok(await page.evaluate(() => fixture.errors.length > 0));
}));

test('closing an in-flight case request prevents later reopening', browser => withHarness(browser, { plans: { A: [{ hold: true }] } }, async page => {
  await page.evaluate(() => { fixture.results.A = openCaseById('A', { tab: 'comments' }); });
  await page.waitForFunction(() => fixture.pending.length === 1);
  await page.locator('#createCaseClose').click();
  await page.evaluate(() => fixture.pending.shift().resolve());
  assert.equal(await page.evaluate(() => fixture.results.A), false);
  await page.waitForTimeout(200);
  const current = await state(page);
  assert.equal(current.modal, 'none');
  assert.equal(current.id, null);
  assert.deepEqual(current.opened, []);
}));

test('overlapping A and B requests are latest-wins even when A resolves last', browser => withHarness(browser, { plans: { A: [{ hold: true }], B: [{ hold: true }] } }, async page => {
  await page.evaluate(() => {
    fixture.results.A = openCaseById('A', { tab: 'comments', commentId: 'old-comment' });
    fixture.results.B = openCaseById('B', { tab: 'details' });
  });
  await page.waitForFunction(() => fixture.pending.length === 2);
  await page.evaluate(() => fixture.pending.find(item => item.id === 'B').resolve());
  assert.equal(await page.evaluate(() => fixture.results.B), true);
  await page.evaluate(() => fixture.pending.find(item => item.id === 'A').resolve());
  assert.equal(await page.evaluate(() => fixture.results.A), false);
  await page.waitForTimeout(200);
  const current = await state(page);
  assert.equal(current.id, 'B');
  assert.equal(current.tab, 'details');
  assert.equal(current.comments, 'Comments B');
  assert.deepEqual(current.opened, [{ id: 'B', mode: 'edit' }]);
}));

test('retry button retains original comment destination options after fetch failure', browser => withHarness(browser, { plans: { A: [{ reject: true }, {}] } }, async page => {
  assert.equal(await page.evaluate(() => openCaseById('A', { tab: 'comments', commentId: 'retry-comment' })), false);
  await page.locator('#caseViewRetry').click();
  await page.waitForFunction(() => fixture.calls.length === 2);
  await page.evaluate(() => fixture.lastOpenPromise);
  const current = await state(page);
  assert.equal(current.id, 'A');
  assert.equal(current.tab, 'comments');
  assert.equal(current.calls[1].options.tab, 'comments');
  assert.equal(current.calls[1].options.commentId, 'retry-comment');
}));

test('missing Comments feature falls back to Details', browser => withHarness(browser, { comments: false, rows: [notification('mention')] }, async page => {
  await page.locator('.notification-item-text').click();
  await page.waitForFunction(() => fixture.calls.length === 1);
  await page.evaluate(() => fixture.lastOpenPromise);
  assert.equal((await state(page)).tab, 'details');
  assert.equal(await page.locator('#createCaseForm').isVisible(), true);
}));

(async () => {
  const browser = await chromium.launch({ headless: true });
  let failed = 0;
  try {
    for (const entry of tests) {
      try { await entry.run(browser); console.log(`PASS ${entry.name}`); }
      catch (error) { failed++; console.error(`FAIL ${entry.name}\n${error.stack}`); }
    }
  } finally { await browser.close(); }
  console.log(`${tests.length - failed}/${tests.length} isolated notification navigation tests passed`);
  process.exitCode = failed ? 1 : 0;
})().catch(error => { console.error(error); process.exitCode = 1; });
