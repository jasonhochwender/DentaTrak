/**
 * Case modal "Save All Changes" end-to-end verification.
 *
 * Covers Dr. Mike's report: edits made on Case Details and a comment draft on
 * the Comments tab must save together regardless of which control is used.
 *
 * Covers: real Board / List / Notification entry points, combined save from
 * the footer and the Comments-tab button, comment-only save, details-only
 * save, validation failure (nothing posts), case-ok/comment-fail partial
 * failure, case-fail/comment-ok partial failure, duplicate-click guard, and a
 * 409 optimistic-lock conflict followed by retry.
 *
 * Run: node tests/case-save-all-e2e-test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const MEMBER_EMAIL = 'e2e_test_member@dentatrak.com';
const SCREEN_DIR = path.join(__dirname, '..', 'screenshots');
const TEST_MARKER = 'DentaTrakTest-SaveAll';

async function login(context, email, password) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: email || EMAIL, password: password || PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json();
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
}

async function createCase(page, overrides) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const data = Object.assign({
    patientFirstName: TEST_MARKER,
    patientLastName: 'Alpha',
    patientDOB: '1990-01-01',
    patientGender: 'Female',
    dentistName: 'Dr. SaveTest',
    caseType: 'Veneer',
    material: 'Zirconia',
    dueDate: '2026-12-15',
    status: 'Originated',
    notes: TEST_MARKER + ' seeded case',
    assignedTo: EMAIL,
    csrf_token: csrf,
  }, overrides);
  const body = new URLSearchParams(data).toString();
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body });
  return res.caseData || res.case || res;
}

async function testHelper(page, body) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/test-helpers.php`, body: Object.assign({ csrf_token: csrf }, body) });
}

async function deleteTestCases(page, ids) {
  await testHelper(page, { action: 'delete_test_cases', case_ids: ids });
}

async function listTestCaseIds(page) {
  const res = await testHelper(page, { action: 'list_test_cases' });
  return res.success ? res.case_ids : [];
}

async function openCaseById(page, caseId, tab) {
  const opened = await page.evaluate(({ id, tab }) => window.openCaseById(id, { tab: tab || 'details' }), { id: caseId, tab });
  if (!opened) throw new Error('openCaseById returned false for case ' + caseId);
  await page.waitForFunction(() => {
    const f = document.getElementById('patientFirstName');
    return f && f.value.length > 0;
  }, undefined, { timeout: 15000 });
}

async function modalClosed(page) {
  await page.waitForFunction(() => {
    const m = document.getElementById('createCaseModal');
    return !m || getComputedStyle(m).display !== 'block';
  }, undefined, { timeout: 15000 });
}

async function modalOpen(page) {
  return page.evaluate(() => {
    const m = document.getElementById('createCaseModal');
    return m && getComputedStyle(m).display === 'block';
  });
}

async function switchTab(page, tab) {
  await page.click(`.case-tab[data-tab="${tab}"]`);
}

async function waitForComment(page, text) {
  await page.waitForFunction((marker) => {
    return (document.getElementById('caseCommentsList') || {}).textContent.includes(marker);
  }, text, { timeout: 10000 });
}

// Route helpers: count calls to the two save endpoints, optionally failing or
// delaying them. Counters live in the returned object.
function interceptSaves(page) {
  const counters = { casePosts: 0, commentPosts: 0, failNextCase: false, failComments: false, delayCaseMs: 0, conflictOnce: null, responses: [] };

  page.route('**/api/update-case.php', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    counters.casePosts++;
    if (counters.conflictOnce) {
      const payload = counters.conflictOnce;
      counters.conflictOnce = null;
      counters.responses.push('update-case:409-injected');
      return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify(payload) });
    }
    if (counters.failNextCase) {
      counters.failNextCase = false;
      counters.responses.push('update-case:500-injected');
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Simulated server failure' }) });
    }
    if (counters.delayCaseMs) {
      await new Promise(r => setTimeout(r, counters.delayCaseMs));
    }
    const response = await route.fetch();
    counters.responses.push('update-case:' + response.status() + ' ' + (await response.text()).slice(0, 150));
    return route.fulfill({ response });
  });

  page.route('**/api/case-comments.php', (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    counters.commentPosts++;
    if (counters.failComments) {
      counters.responses.push('case-comments:500-injected');
      return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Simulated comment failure' }) });
    }
    counters.responses.push('case-comments:pass');
    return route.continue();
  });

  return counters;
}

async function run() {
  if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await login(context);

  const page = await context.newPage();
  const failures = [];
  const seededIds = [];
  const consoleErrors = [];
  page.on('pageerror', err => consoleErrors.push('pageerror: ' + err.message));
  page.on('console', msg => {
    if (msg.type() === 'error') consoleErrors.push('console: ' + msg.text());

  });

  const counters = interceptSaves(page);

  try {
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

    const preExisting = await listTestCaseIds(page);
    if (preExisting.length > 0) await deleteTestCases(page, preExisting);

    // Remove any leftover debug seed cases (outside the DentaTrakTest marker).
    const strayIds = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('.kanban-card')).map(c => {
        try {
          const d = JSON.parse(c.dataset.caseJson || '{}');
          return String(d.patientFirstName || '').startsWith('DentaTrakDebug') ? (d.id || d.case_id) : null;
        } catch (e) { return null; }
      }).filter(Boolean);
    });
    if (strayIds.length) await deleteTestCases(page, strayIds).catch(() => {});

    const a = await createCase(page, { patientLastName: 'Alpha' });
    const aId = a.id || a.caseId || a.case_id;
    seededIds.push(aId);

    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });
    // Cards live in the DOM even when List View is the persisted preference.
    await page.waitForSelector('.kanban-card', { state: 'attached', timeout: 15000 });

    /* ---------- 1. Board entry: real card click, combined save ---------- */
    console.log('step 1 - board click entry');
    const cardHandle = await page.evaluateHandle((id) => {
      return Array.from(document.querySelectorAll('.kanban-card')).find(c => {
        try {
          const d = JSON.parse(c.dataset.caseJson || '{}');
          return String(d.id || d.case_id) === String(id);
        } catch (e) { return false; }
      }) || null;
    }, aId);
    const cardEl = cardHandle.asElement();
    if (!cardEl) throw new Error('seeded card not found on board');
    const editBtn = await cardEl.$('.kanban-card-edit');
    if (!editBtn) throw new Error('card edit button not found');
    await editBtn.click();
    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, undefined, { timeout: 15000 });

    const footerLabel = await page.$eval('#createCaseSubmit', el => el.textContent.trim());
    if (footerLabel !== 'Save All Changes') {
      failures.push('edit-mode footer button should read "Save All Changes", got: ' + footerLabel);
    }

    await page.fill('#notes', TEST_MARKER + ' board notes');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' board reply');
    await page.click('#createCaseSubmit'); // footer stays visible on every tab
    await modalClosed(page);

    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' board reply');
    const boardCheck = await page.evaluate(() => ({
      notes: document.getElementById('notes').value,
      comments: document.getElementById('caseCommentsList').textContent,
    }));
    if (!boardCheck.comments.includes(TEST_MARKER + ' board reply')) {
      failures.push('board entry: comment not posted by footer Save All');
    }
    if (!boardCheck.notes.includes('board notes')) {
      failures.push('board entry: case edit not saved by footer Save All');
    }
    // Close the verification modal (nothing pending) before the bell click.
    await page.click('#createCaseCancel');
    await modalClosed(page);

    /* ---------- 2. Notification entry: real bell + item click ---------- */
    console.log('step 2 - notification click entry');
    // Create a second practice member and have them @mention the test user,
    // which produces a real notification for the test user.
    const memberSetup = await testHelper(page, {
      action: 'setup_practice_member',
      email: MEMBER_EMAIL,
      password: 'TestPass123!',
      firstName: 'E2E',
      lastName: 'Member',
      adminEmail: EMAIL,
    });
    if (!memberSetup.success) failures.push('member setup failed: ' + JSON.stringify(memberSetup));

    const adminUser = await page.evaluate(async () => {
      const r = await fetch('api/get-practice-users.php', { credentials: 'same-origin' });
      const d = await r.json();
      return (d.users || []).find(u => (u.email || '').toLowerCase() === 'e2e_test_browser2@dentatrak.com') || null;
    });
    if (!adminUser) failures.push('could not resolve test user for mention');

    const memberCtx = await browser.newContext();
    await login(memberCtx, MEMBER_EMAIL, 'TestPass123!');
    // The member session needs an active practice before API calls work.
    const practicesRes = await memberCtx.request.get(`${BASE}/api/get-user-practices.php`);
    const practices = await practicesRes.json();
    const memberPracticeId = (practices.practices && practices.practices[0] && (practices.practices[0].id || practices.practices[0].practice_id)) || null;
    if (memberPracticeId) {
      await memberCtx.request.get(`${BASE}/api/select-practice.php?practice_id=${memberPracticeId}`);
    } else {
      failures.push('member has no practice to select: ' + JSON.stringify(practices).slice(0, 200));
    }
    const memberPage = await memberCtx.newPage();
    await memberPage.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });
    const memberCsrf = await memberPage.$eval('meta[name="csrf-token"]', el => el.content);
    const mentionToken = ((adminUser.name || 'user').replace(/[^a-zA-Z0-9._-]/g, '')) || 'user';
    const commentRes = await memberPage.evaluate(async ({ url, body }) => {
      const r = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.csrf },
        body: JSON.stringify(body.payload),
      });
      return r.json();
    }, {
      url: `${BASE}/api/case-comments.php`,
      body: {
        csrf: memberCsrf,
        payload: {
          action: 'create',
          case_id: aId,
          text: '@' + mentionToken + ' can you check this case?',
          mentions: [{ user_id: adminUser.id, token: mentionToken, name: adminUser.name }],
        },
      },
    });
    if (!commentRes.success) failures.push('member comment failed: ' + JSON.stringify(commentRes));
    await memberCtx.close();

    // Reload so the notification dropdown does not serve its cached list -
    // openNotificationDropdown() skips refetching when the cache is fresh.
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.kanban-card', { state: 'attached', timeout: 15000 });

    // Open the notifications dropdown and click the real notification item.
    await page.click('#notificationBell');
    const notifSel = `.notification-item[data-case-id="${aId}"]`;
    await page.waitForSelector(notifSel, { timeout: 15000 });
    await page.click(notifSel);

    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, undefined, { timeout: 15000 });
    const notifTab = await page.evaluate(() => ({
      commentsActive: document.querySelector('.case-tab[data-tab="comments"]').classList.contains('case-tab-active'),
      modalOpen: getComputedStyle(document.getElementById('createCaseModal')).display === 'block',
    }));
    if (!notifTab.modalOpen) failures.push('notification click did not open the case modal');
    if (!notifTab.commentsActive) failures.push('notification click did not land on Comments tab');

    // From the notification-opened modal: edit details + draft reply, save all.
    await page.fill('#caseCommentInput', TEST_MARKER + ' notif reply');
    await switchTab(page, 'details');
    await page.fill('#notes', TEST_MARKER + ' notif notes');
    await switchTab(page, 'comments');
    const notifBtnLabel = await page.$eval('#caseCommentSubmit', el => el.textContent.trim());
    if (notifBtnLabel !== 'Save All Changes') {
      failures.push('comments button should read "Save All Changes" when edits pending, got: ' + notifBtnLabel);
    }
    await page.click('#caseCommentSubmit');
    await modalClosed(page);

    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' notif reply');
    const notifCheck = await page.evaluate(() => document.getElementById('notes').value);
    if (!notifCheck.includes('notif notes')) failures.push('notification entry: case edit not saved with comment');

    /* ---------- 3. List entry: real row click, combined save ---------- */
    console.log('step 3 - list row click entry');
    await page.click('#createCaseCancel');
    await modalClosed(page).catch(() => {});
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row', { timeout: 10000 });
    await page.click(`.case-list-row[data-case-id="${aId}"] .case-list-open`);
    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, undefined, { timeout: 15000 });

    await page.fill('#toothShade', 'A3');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' list reply');
    await page.click('#caseCommentSubmit');
    await modalClosed(page);
    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' list reply');
    const listShade = await page.evaluate(() => document.getElementById('toothShade').value);
    if (listShade !== 'A3') failures.push('list entry: case edit not saved with comment, shade=' + listShade);

    /* ---------- 4. Validation failure: NOTHING posts ---------- */
    console.log('step 4 - validation blocks both saves');
    const postsBefore = { c: counters.casePosts, m: counters.commentPosts };
    await switchTab(page, 'details');
    await page.fill('#patientFirstName', ''); // clear a required field
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' blocked reply');
    await page.click('#caseCommentSubmit');
    await page.waitForTimeout(1500);

    const afterInvalid = await page.evaluate(() => ({
      modalOpen: getComputedStyle(document.getElementById('createCaseModal')).display === 'block',
      detailsActive: document.querySelector('.case-tab[data-tab="details"]').classList.contains('case-tab-active'),
      fieldError: !!document.querySelector('#patientFirstName.field-error'),
      draft: (document.getElementById('caseCommentInput') || {}).value,
    }));
    if (!afterInvalid.modalOpen) failures.push('validation fail: modal should stay open');
    if (!afterInvalid.detailsActive) failures.push('validation fail: should switch to Details tab');
    if (!afterInvalid.fieldError) failures.push('validation fail: required field should show error');
    if (afterInvalid.draft !== TEST_MARKER + ' blocked reply') failures.push('validation fail: comment draft should be preserved');
    if (counters.casePosts !== postsBefore.c) failures.push('validation fail: update-case.php should not be called');
    if (counters.commentPosts !== postsBefore.m) failures.push('validation fail: case-comments.php should not be called');

    // Restore the field and complete the save.
    await page.fill('#patientFirstName', TEST_MARKER);
    await page.click('#createCaseSubmit');
    await modalClosed(page);
    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' blocked reply');

    /* ---------- 5. Case saved, comment failed: draft survives, retry posts only comment ---------- */
    console.log('step 5 - comment fails after case save');
    counters.failComments = true;
    const postsBefore5 = { c: counters.casePosts };
    await switchTab(page, 'details');
    await page.fill('#toothShade', 'B2');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' retry me');
    await page.click('#caseCommentSubmit');
    await page.waitForTimeout(2500); // case save + failed comment attempt

    const after5 = await page.evaluate(() => ({
      modalOpen: getComputedStyle(document.getElementById('createCaseModal')).display === 'block',
      draft: (document.getElementById('caseCommentInput') || {}).value,
    }));
    if (!after5.modalOpen) failures.push('comment-fail: modal should stay open');
    if (after5.draft !== TEST_MARKER + ' retry me') failures.push('comment-fail: draft should be preserved');
    counters.failComments = false;
    const casePostsAfterFirstSave = counters.casePosts;

    await page.click('#caseCommentSubmit'); // retry - posts comment only
    await page.waitForTimeout(2000);
    await waitForComment(page, TEST_MARKER + ' retry me');
    if (counters.casePosts !== casePostsAfterFirstSave) failures.push('comment-fail retry: update-case.php was called again');

    /* ---------- 6. Case fails server-side: comment posts once, edits survive ---------- */
    console.log('step 6 - case save fails server-side');
    counters.failNextCase = true;
    const postsBefore6 = { m: counters.commentPosts };
    await switchTab(page, 'details');
    await page.fill('#toothShade', 'C1');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' casefail reply');
    await page.click('#caseCommentSubmit');
    await page.waitForTimeout(2500);

    const after6 = await page.evaluate(() => ({
      modalOpen: getComputedStyle(document.getElementById('createCaseModal')).display === 'block',
      shade: document.getElementById('toothShade').value,
      draft: (document.getElementById('caseCommentInput') || {}).value,
    }));
    if (!after6.modalOpen) failures.push('case-fail: modal should stay open');
    if (after6.shade !== 'C1') failures.push('case-fail: unsaved edit should survive, got: ' + after6.shade);
    if (after6.draft !== '') failures.push('case-fail: comment should have posted once (draft cleared)');

    // Retry - the case saves; the comment must NOT be re-posted. The comment
    // post bumps the case version server-side, so the retry may surface the
    // 409 conflict dialog - resolve it by keeping our version.
    await page.click('#createCaseSubmit');
    const conflictBtn = await page.waitForSelector('.conflict-cancel-btn', { timeout: 4000 }).catch(() => null);
    if (conflictBtn) await conflictBtn.click();
    try {
      await modalClosed(page);
    } catch (e) {
      const dump = await page.evaluate(() => ({
        modalOpen: getComputedStyle(document.getElementById('createCaseModal')).display === 'block',
        submitHtml: (document.getElementById('createCaseSubmit') || {}).innerHTML.slice(0, 80),
        conflictOverlay: !!document.querySelector('.conflict-modal-overlay'),
        warnDialog: !!document.getElementById('stay-btn'),
        draft: (document.getElementById('caseCommentInput') || {}).value,
        shade: (document.getElementById('toothShade') || {}).value,
        fieldErrors: Array.from(document.querySelectorAll('#createCaseForm .field-error')).map(el => el.id || el.name),
        errorTexts: Array.from(document.querySelectorAll('#createCaseForm .error-message')).map(el => el.textContent.slice(0, 80)),
        detailsActive: document.querySelector('.case-tab[data-tab="details"]').classList.contains('case-tab-active'),
      }));
      console.log('step6 dump:', JSON.stringify(dump));
      console.log('responses:', JSON.stringify(counters.responses));
      throw e;
    }
    if (counters.commentPosts !== postsBefore6.m + 1) {
      failures.push('case-fail retry: comment was re-posted (count=' + counters.commentPosts + ')');
    }
    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' casefail reply');
    const shadeAfter6 = await page.evaluate(() => document.getElementById('toothShade').value);
    if (shadeAfter6 !== 'C1') failures.push('case-fail retry: edit did not save, shade=' + shadeAfter6);

    /* ---------- 7. Repeated clicks during a combined save: exactly one of each ---------- */
    console.log('step 7 - duplicate click guard');
    counters.delayCaseMs = 1200;
    const postsBefore7 = { c: counters.casePosts, m: counters.commentPosts };
    await switchTab(page, 'details');
    await page.fill('#toothShade', 'D4');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' dedupe reply');
    // Fire rapid repeat triggers: footer click x3, comment button click, Enter in input.
    await page.dispatchEvent('#createCaseSubmit', 'click');
    await page.dispatchEvent('#createCaseSubmit', 'click');
    await page.dispatchEvent('#createCaseSubmit', 'click');
    await page.dispatchEvent('#caseCommentSubmit', 'click');
    await page.focus('#caseCommentInput');
    await page.keyboard.press('Enter');
    await modalClosed(page);
    await page.waitForTimeout(800);
    if (counters.casePosts - postsBefore7.c !== 1) {
      failures.push('dedupe: update-case.php called ' + (counters.casePosts - postsBefore7.c) + ' times');
    }
    if (counters.commentPosts - postsBefore7.m !== 1) {
      failures.push('dedupe: case-comments.php called ' + (counters.commentPosts - postsBefore7.m) + ' times');
    }
    counters.delayCaseMs = 0;

    /* ---------- 8. Optimistic-lock conflict then retry: each change saves once ---------- */
    console.log('step 8 - 409 conflict then retry');
    await openCaseById(page, aId, 'details');
    await page.fill('#toothShade', 'E5');
    await switchTab(page, 'comments');
    await page.fill('#caseCommentInput', TEST_MARKER + ' conflict reply');
    // Get the real current version so the keep-mine retry actually succeeds.
    const realVersion = await page.evaluate(async (id) => {
      const r = await fetch('api/get-case.php?id=' + encodeURIComponent(id) + '&view=core', { credentials: 'same-origin' });
      const d = await r.json();
      return d.case && d.case.version ? d.case.version : null;
    }, aId);
    // Build a 409 payload with one real divergence so the conflict dialog
    // appears; keep-mine updates the version and auto-retries the save.
    const liveValues = await page.evaluate((v) => ({
      patientFirstName: document.getElementById('patientFirstName').value,
      patientLastName: document.getElementById('patientLastName').value,
      status: document.getElementById('status').value,
      dentistName: document.getElementById('dentistName').value,
      caseType: document.getElementById('caseType').value,
      toothShade: document.getElementById('toothShade').value,
      material: document.getElementById('material').value,
      dueDate: document.getElementById('dueDate').value,
      patientAppointmentDate: (document.getElementById('patientAppointmentDate') || {}).value || '',
      notes: 'server-side concurrent edit',
      version: v,
    }), realVersion);
    const postsBefore8 = { m: counters.commentPosts };
    counters.conflictOnce = { success: false, conflict: true, message: 'modified by another user', currentData: liveValues, currentVersion: realVersion };
    await page.click('#caseCommentSubmit');
    // Conflict dialog -> "Keep My Version" -> auto-retry saves the case once.
    const conflictBtn8 = await page.waitForSelector('.conflict-cancel-btn', { timeout: 6000 }).catch(() => null);
    if (!conflictBtn8) failures.push('conflict dialog did not appear for 409');
    else await conflictBtn8.click();
    await modalClosed(page);
    await page.waitForTimeout(800);
    // Conflict path: comment posted once on the failed attempt; the auto-retry
    // saves the case without re-posting the comment.
    if (counters.commentPosts - postsBefore8.m !== 1) {
      failures.push('conflict: comment posted ' + (counters.commentPosts - postsBefore8.m) + ' times');
    }
    await openCaseById(page, aId, 'comments');
    await waitForComment(page, TEST_MARKER + ' conflict reply');
    const conflictShade = await page.evaluate(() => document.getElementById('toothShade').value);
    if (conflictShade !== 'E5') failures.push('conflict retry: edit did not save, shade=' + conflictShade);

    /* ---------- 9. Unsaved-work warning on close with comment draft ---------- */
    console.log('step 9 - close warning');
    await page.fill('#caseCommentInput', TEST_MARKER + ' unsaved draft');
    await page.click('#createCaseCancel');
    await page.waitForSelector('#stay-btn', { timeout: 5000 });
    await page.click('#stay-btn');
    if (!(await modalOpen(page))) failures.push('modal should stay open after choosing Stay');
    const stillDraft = await page.$eval('#caseCommentInput', el => el.value);
    if (stillDraft !== TEST_MARKER + ' unsaved draft') failures.push('comment draft lost after Stay');

    await page.click('#createCaseCancel');
    await page.waitForSelector('#close-btn', { timeout: 5000 });
    await page.click('#close-btn');
    await modalClosed(page);

  } catch (e) {
    failures.push('exception: ' + e.message);
    try { await page.screenshot({ path: path.join(SCREEN_DIR, 'case-save-all-fail.png') }); } catch (e2) {}
  } finally {
    await deleteTestCases(page, seededIds).catch(() => {});
    await browser.close();
  }

  console.log('save endpoint log:', JSON.stringify(counters.responses, null, 0));
  if (consoleErrors.length) {
    console.log('console errors:', consoleErrors.slice(0, 40));
  }
  failures.forEach(f => console.log('FAIL: ' + f));
  console.log(failures.length === 0 ? 'ALL CHECKS PASSED' : failures.length + ' failure(s)');
  process.exit(failures.length ? 1 : 0);
}

run().catch(e => { console.error(e); process.exit(1); });
