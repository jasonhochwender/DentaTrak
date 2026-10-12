/**
 * Archived-case comments e2e: comments are read-only on archived cases.
 *
 * Verifies server-side rejection of every comment mutation path (create,
 * delete, comment-image signed URL) against the case's CURRENT archived
 * flag, plus the UI contract: composer hidden behind the read-only notice,
 * thread still viewable, mid-edit archive preserves the draft for copying,
 * and restore re-enables comment actions.
 *
 * Uses synthetic DentaTrakTest fixtures, removed at the end.
 * Run: node tests/archived-case-comments-e2e-test.js
 */
'use strict';

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const OWNER = { email: 'e2e-session-test@example.com', password: 'TestPass123!' };
const MARKER = 'DentaTrakTest';

const failures = [];
function check(name, cond, detail) {
  console.log((cond ? 'PASS ' : 'FAIL ') + name + (detail !== undefined ? '  ' + JSON.stringify(detail).slice(0, 300) : ''));
  if (!cond) failures.push(name);
}

async function login(context, user) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: user.email, password: user.password },
    headers: { 'Content-Type': 'application/json' }
  });
  const body = await res.json().catch(() => ({}));
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
  const page = await context.newPage();
  await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
  if (/accept-terms\.php/.test(page.url())) {
    await page.check('#termsAccepted'); await page.click('#acceptBtn');
    await page.waitForURL(/main\.php/, { timeout: 15000 });
  }
  await page.close();
}

async function csrf(page) { return page.$eval('meta[name="csrf-token"]', el => el.content); }

async function postJson(page, url, body) {
  const token = await csrf(page);
  return page.evaluate(async ({ url, body, token }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
    let json = null; try { json = await r.json(); } catch (e) {}
    return { status: r.status, json };
  }, { url, body, token });
}

async function createCase(page, lastName) {
  const body = new URLSearchParams({
    patientFirstName: MARKER, patientLastName: lastName, patientDOB: '1990-01-01',
    patientGender: 'Male', dentistName: 'Dr. Test', caseType: 'Bite Rim',
    status: 'Originated', dueDate: '2026-12-01', notes: MARKER + ' archived comments',
    csrf_token: await csrf(page)
  }).toString();
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body });
  const id = (res.caseData && (res.caseData.id || res.caseData.caseId)) || (res.case && res.case.id) || res.caseId;
  if (!id) throw new Error('create-case failed: ' + JSON.stringify(res).slice(0, 300));
  return String(id);
}

const addComment = (page, caseId, text) =>
  postJson(page, `${BASE}/api/case-comments.php`, { action: 'create', case_id: caseId, text });
const deleteComment = (page, commentId) =>
  postJson(page, `${BASE}/api/case-comments.php`, { action: 'delete', comment_id: commentId });
const getComments = (page, caseId) => page.evaluate(async url => {
  const r = await fetch(url, { credentials: 'same-origin' });
  return { status: r.status, json: await r.json().catch(() => null) };
}, `${BASE}/api/case-comments.php?case_id=${encodeURIComponent(caseId)}`);

function commentsUiState(page) {
  return page.evaluate(() => {
    const wrapper = document.querySelector('#caseCommentsPanel .case-comment-input-wrapper');
    const notice = document.getElementById('caseCommentsArchivedNotice');
    const input = document.getElementById('caseCommentInput');
    return {
      wrapperHidden: wrapper ? (wrapper.hidden || getComputedStyle(wrapper).display === 'none') : null,
      noticeVisible: notice ? (!notice.hidden && getComputedStyle(notice).display !== 'none') : null,
      noticeText: notice ? notice.textContent.trim() : null,
      inputReadOnly: input ? input.readOnly : null,
      inputValue: input ? input.value : null,
      listText: document.getElementById('caseCommentsList')
        ? document.getElementById('caseCommentsList').textContent.trim() : null,
    };
  });
}

async function openArchivedCase(page, caseId) {
  await page.evaluate(id => window.viewArchivedCase(id), caseId);
  await page.waitForFunction(() => getComputedStyle(document.getElementById('createCaseModal')).display === 'block');
  await page.waitForTimeout(600);
  await page.click('.case-tab[data-tab="comments"]');
  await page.waitForTimeout(500);
}

async function closeModal(page) {
  await page.evaluate(() => document.getElementById('createCaseClose').click());
  await page.waitForTimeout(150);
  const discard = page.locator('#close-btn');
  if (await discard.isVisible().catch(() => false)) await discard.click();
  await page.waitForFunction(() => getComputedStyle(document.getElementById('createCaseModal')).display === 'none', null, { timeout: 5000 }).catch(() => {});
}

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const created = [];
  try {
    await login(context, OWNER);
    const page = await context.newPage();
    await page.goto(`${BASE}/main.php`, { waitUntil: 'networkidle' });

    const flag = await page.evaluate(() => !!(window.featureFlags && window.featureFlags.SHOW_COMMENTS));
    check('SHOW_COMMENTS enabled (precondition)', flag);
    if (!flag) { await context.close(); await browser.close(); return; }

    // ── Active case: comments work ──────────────────────────────────────
    const caseId = await createCase(page, 'ArchComments-' + Date.now());
    created.push(caseId);
    const seed = await addComment(page, caseId, MARKER + ' active comment');
    check('comment on ACTIVE case succeeds', seed.status === 200 && seed.json && seed.json.success === true, seed);
    const seededCommentId = seed.json && seed.json.comment && seed.json.comment.id;

    // ── Archive it ──────────────────────────────────────────────────────
    const arch = await postJson(page, `${BASE}/api/delete-case.php`, { caseId });
    check('case archived', arch.json && arch.json.success === true, arch);

    // ── Direct API mutations on the archived case are rejected ──────────
    const addBlocked = await addComment(page, caseId, MARKER + ' should fail');
    check('create on archived case rejected', addBlocked.json && addBlocked.json.success === false, addBlocked);
    check('create rejection carries archived flag + message',
      addBlocked.json && addBlocked.json.archived === true && /archiv/i.test(addBlocked.json.message || ''), addBlocked);

    const delBlocked = await deleteComment(page, seededCommentId);
    check('delete on archived case rejected',
      delBlocked.json && delBlocked.json.success === false && delBlocked.json.archived === true, delBlocked);

    const upBlocked = await postJson(page, `${BASE}/api/upload-signed-url.php`, {
      filename: 'note.png', content_type: 'image/png', file_size: 1000,
      case_id: caseId, upload_type: 'comments'
    });
    check('comment-image signed URL on archived case rejected',
      upBlocked.json && upBlocked.json.success === false && upBlocked.json.archived === true, upBlocked);

    const getArchived = await getComments(page, caseId);
    check('GET still returns the thread on archived case',
      getArchived.json && getArchived.json.success === true
        && getArchived.json.comments.some(c => (c.text || '').includes('active comment')),
      { status: getArchived.status, count: getArchived.json && getArchived.json.comments && getArchived.json.comments.length });

    // ── Archived UI: composer hidden behind the notice, thread visible ──
    await openArchivedCase(page, caseId);
    let ui = await commentsUiState(page);
    check('archived view: composer hidden', ui.wrapperHidden === true, ui);
    check('archived view: read-only notice shown', ui.noticeVisible === true && ui.noticeText.length > 0, ui.noticeText);
    check('archived view: existing comments still rendered', (ui.listText || '').includes('active comment'));
    await closeModal(page);

    // ── Switching back to an active case restores the composer ───────────
    const caseB = await createCase(page, 'ArchSwitch-' + Date.now());
    created.push(caseB);
    await page.waitForSelector(`.kanban-card[data-case-id="${caseB}"]`, { state: 'visible', timeout: 15000 });
    await page.evaluate(id => window.openCaseById(id, { tab: 'comments' }), caseB);
    await page.waitForFunction(() => getComputedStyle(document.getElementById('createCaseModal')).display === 'block');
    await page.waitForTimeout(800);
    ui = await commentsUiState(page);
    check('active view after archived: composer visible, notice hidden',
      ui.wrapperHidden === false && ui.noticeVisible === false, ui);

    // ── Mid-edit archive: draft survives a save that the server rejects ──
    await page.fill('#caseCommentInput', MARKER + ' unsaved draft text');
    await postJson(page, `${BASE}/api/delete-case.php`, { caseId: caseB }); // archive while composer holds a draft
    await page.click('#caseCommentSubmit');
    await page.waitForFunction(() => {
      const n = document.getElementById('caseCommentsArchivedNotice');
      return n && !n.hidden;
    }, null, { timeout: 8000 }).catch(() => {});
    ui = await commentsUiState(page);
    check('mid-edit archive: read-only notice appears', ui.noticeVisible === true, ui);
    check('mid-edit archive: draft text preserved and copyable',
      (ui.inputValue || '').includes('unsaved draft text') && ui.inputReadOnly === true, ui);
    const getB = await getComments(page, caseB);
    check('mid-edit archive: rejected comment never stored',
      getB.json && getB.json.success === true && !getB.json.comments.some(c => (c.text || '').includes('unsaved draft')));
    await closeModal(page);

    // ── Restore: comment actions come back ───────────────────────────────
    const restored = await postJson(page, `${BASE}/api/restore-case.php`, { caseId });
    check('case restored', restored.json && restored.json.success === true, restored);
    const addAfterRestore = await addComment(page, caseId, MARKER + ' post-restore comment');
    check('comment on RESTORED case succeeds', addAfterRestore.json && addAfterRestore.json.success === true, addAfterRestore);
    const delAfterRestore = await deleteComment(page, seededCommentId);
    check('admin delete works again after restore', delAfterRestore.json && delAfterRestore.json.success === true, delAfterRestore);

    await context.close();
  } finally {
    try {
      const ctx2 = await browser.newContext();
      await login(ctx2, OWNER);
      const p2 = await ctx2.newPage();
      await p2.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
      await p2.evaluate(async ({ url, ids }) => {
        const t = document.querySelector('meta[name="csrf-token"]').content;
        await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete_test_cases', case_ids: ids, csrf_token: t }) });
      }, { url: `${BASE}/api/test-helpers.php`, ids: created });
      await ctx2.close();
    } catch (e) { console.log('cleanup failed', String(e)); }
    await browser.close();
  }

  console.log(`\n${failures.length === 0 ? 'ALL PASS' : 'FAILURES'} — ${failures.length} failed`);
  failures.forEach(f => console.log('  FAIL: ' + f));
  process.exit(failures.length ? 1 : 0);
})();
