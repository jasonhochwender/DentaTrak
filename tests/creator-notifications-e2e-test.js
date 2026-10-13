/**
 * Creator + comment notification e2e (live local app).
 *
 * Verifies the implemented recipient model end-to-end:
 *   - creator stays notified after assigning to someone else
 *   - ordinary comments notify creator + current assignee(s)
 *   - mentioned users get exactly one notification (mention wins, no dup)
 *   - assignment labels expand to mapped active members
 *   - unassigned cases still notify the creator
 *   - actor is never notified of their own action
 *   - removed-from-practice and limited-visibility users are filtered out
 *   - queue worker keeps creator emails alive across reassignment while
 *     cancelling assignee emails; comment_added email preference honored
 *
 * Dev-only fixtures used: setup_test_user, setup_practice_member,
 * get_user_notifications, ensure_assignment_label, remove_practice_member,
 * delete_test_users, delete_test_cases, clear_test_email_log,
 * get_all_app_emails.
 *
 * Run: node tests/creator-notifications-e2e-test.js
 */
'use strict';

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const PASSWORD = 'D3n7@Tr@k!Notify42';
const WORKER_TOKEN = process.env.QUEUE_WORKER_TOKEN || 'dentatrak-test-worker-token';
const RUN = Date.now().toString(36);

const A = { email: `e2e-cn-a-${RUN}@dentatrak.test`, name: 'Alice Admin' };     // admin / case creator
const B = { email: `e2e-cn-b-${RUN}@dentatrak.test`, name: 'Bob Build' };       // assignee
const C = { email: `e2e-cn-c-${RUN}@dentatrak.test`, name: 'Cara Check' };      // commenter
const D = { email: `e2e-cn-d-${RUN}@dentatrak.test`, name: 'Dan Lab' };         // label-mapped member
const R = { email: `e2e-cn-r-${RUN}@dentatrak.test`, name: 'Rita Remove' };     // creator removed from practice
const L = { email: `e2e-cn-l-${RUN}@dentatrak.test`, name: 'Lea Limited' };     // creator turned limited-visibility
const LABEL = `E2ENotifyLab-${RUN}`;

let checks = 0;
const failures = [];
function check(name, cond, detail) {
  checks++;
  console.log((cond ? 'PASS ' : 'FAIL ') + name + (detail !== undefined ? '  ' + JSON.stringify(detail).slice(0, 300) : ''));
  if (!cond) failures.push(name);
}

const ctxs = {};
async function userPage(browser, u) {
  if (ctxs[u.email]) return ctxs[u.email];
  const ctx = await browser.newContext();
  const res = await ctx.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: u.email, password: PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json().catch(() => ({}));
  if (!body.success) throw new Error(`login failed for ${u.email}: ${JSON.stringify(body).slice(0, 200)}`);
  const page = await ctx.newPage();
  await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  u.csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  if (u.practiceId) {
    await ctx.request.post(`${BASE}/api/switch-practice.php`, {
      data: { practice_id: u.practiceId, csrf_token: u.csrf },
      headers: { 'Content-Type': 'application/json' },
    });
  }
  ctxs[u.email] = { ctx, page };
  return ctxs[u.email];
}

async function api(u, url, body) {
  const { page } = await userPage(browser0, u);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/${url}`, body: { ...body, csrf_token: u.csrf } });
}

let reqCtx; // sessionless fixture channel
async function helper(body) {
  const res = await reqCtx.post(`${BASE}/api/test-helpers.php`, {
    data: body, headers: { 'Content-Type': 'application/json' },
  });
  return res.json().catch(() => ({ success: false }));
}
async function sessionHelper(u, body) {
  const { ctx } = await userPage(browser0, u);
  const res = await ctx.request.post(`${BASE}/api/test-helpers.php`, {
    data: { ...body, csrf_token: u.csrf }, headers: { 'Content-Type': 'application/json' },
  });
  return res.json().catch(() => ({ success: false }));
}

async function notifs(email, caseId) {
  const res = await helper({ action: 'get_user_notifications', email });
  const rows = (res.notifications || []).filter(n => String(n.case_id) === String(caseId));
  return rows.map(n => n.event_type || n.notification_type);
}
const countType = (list, type) => list.filter(t => t === type).length;

async function createCase(u, lastName, assignedTo) {
  const { page } = await userPage(browser0, u);
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body).toString(),
    });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body: {
    patientFirstName: `DentaTrakTest-CN`, patientLastName: lastName,
    patientDOB: '1990-01-01', patientGender: 'Female',
    dentistName: 'Dr. T', caseType: 'Veneer', material: 'Zirconia', status: 'Originated',
    ...(assignedTo ? { assignedTo } : {}),
    csrf_token: u.csrf,
  }});
  const c = res.caseData || res.case || res;
  const id = c.id || c.caseId || c.case_id;
  if (!id) throw new Error(`create-case failed for ${u.email}: ${JSON.stringify(res).slice(0, 300)}`);
  return String(id);
}
const comment = (u, caseId, text, mentions) =>
  api(u, 'case-comments.php', { action: 'create', case_id: caseId, text, ...(mentions ? { mentions } : {}) });

let browser0;

(async () => {
  const browser = await chromium.launch();
  browser0 = browser;
  reqCtx = await browser.newContext().then(c => c.request);

  const createdCases = [];
  try {
    // ── fixtures ─────────────────────────────────────────────────────────
    const setup = await helper({ action: 'setup_test_user', email: A.email, password: PASSWORD, firstName: 'DentaTrakTest', lastName: 'CN-Admin', practiceName: 'E2E Notify Practice' });
    check('admin fixture created', setup.success === true, setup);
    A.practiceId = setup.practice_id;
    for (const u of [B, C, D, R, L]) u.practiceId = setup.practice_id;

    for (const [u, extra] of [[B, { canEditCases: true }], [C, { canEditCases: true }], [D, {}], [R, { canEditCases: true }], [L, { canEditCases: true }]]) {
      const r = await helper({ action: 'setup_practice_member', email: u.email, password: PASSWORD, firstName: 'DentaTrakTest', lastName: u.name, adminEmail: A.email, ...extra });
      check(`member fixture ${u.name}`, r.success === true, r);
      u.id = r.user_id;
    }
    const aRec = await helper({ action: 'get_test_user_record', email: A.email });
    A.id = (aRec.user || aRec.user_record || aRec).id;

    // ── 1. case create assigned to B ─────────────────────────────────────
    const case1 = await createCase(A, `CN1-${RUN}`, B.email);
    createdCases.push(case1);
    check('S1: assignee B notified of case_created', countType(await notifs(B.email, case1), 'case_created') === 1);
    check('S1: creator A is the actor - no self-notification', (await notifs(A.email, case1)).length === 0);

    // ── 2. ordinary comment by C ─────────────────────────────────────────
    const cmt = await comment(C, case1, 'ordinary comment on case one');
    check('S2: comment posted', cmt.success === true, cmt);
    check('S2: creator A notified of ordinary comment', countType(await notifs(A.email, case1), 'comment_added') === 1);
    check('S2: assignee B notified of ordinary comment', countType(await notifs(B.email, case1), 'comment_added') === 1);
    check('S2: commenter C excluded (own action)', (await notifs(C.email, case1)).length === 0);

    // ── 3. comment with mention of C by B ────────────────────────────────
    const mCmt = await comment(B, case1, 'pinging @Cara', [{ user_id: C.id, mention: 'CaraCheck' }]);
    check('S3: mention comment posted', mCmt.success === true, mCmt);
    const cAll = await notifs(C.email, case1);
    check('S3: mentioned C gets exactly one notification - mention wins', cAll.length === 1 && cAll[0] === 'mention', cAll);
    check('S3: creator A still gets comment_added (2 total)', countType(await notifs(A.email, case1), 'comment_added') === 2);
    check('S3: author B no new rows (own action)', countType(await notifs(B.email, case1), 'comment_added') === 1);

    // ── 4. status change by assignee B ───────────────────────────────────
    const st = await api(B, 'update-case-status.php', { caseId: case1, status: 'Designed' });
    check('S4: status update succeeded', st.success === true, st);
    check('S4: creator A notified of status change', countType(await notifs(A.email, case1), 'status_changed') === 1);
    check('S4: actor B not notified', countType(await notifs(B.email, case1), 'status_changed') === 0);

    // ── 5. reassignment B -> C (B is actor) ──────────────────────────────
    const ra = await api(B, 'update-case-assignment.php', { caseId: case1, assignedTo: C.email });
    check('S5: reassignment succeeded', ra.success === true, ra);
    check('S5: creator A notified of reassignment', countType(await notifs(A.email, case1), 'assignment_changed') === 1);
    check('S5: new assignee C notified', countType(await notifs(C.email, case1), 'assignment_changed') === 1);
    check('S5: actor B not notified', countType(await notifs(B.email, case1), 'assignment_changed') === 0);

    // ── 6. unassignment by C ─────────────────────────────────────────────
    const un = await api(C, 'update-case-assignment.php', { caseId: case1, assignedTo: '' });
    check('S6: unassignment succeeded', un.success === true, un);
    check('S6: creator A notified of unassignment', countType(await notifs(A.email, case1), 'assignment_changed') === 2);
    check('S6: actor C no new assignment row', countType(await notifs(C.email, case1), 'assignment_changed') === 1);

    // ── 7. comment on now-unassigned case still reaches creator ──────────
    const uc = await comment(B, case1, 'case unassigned but creator should still hear');
    check('S7: comment on unassigned case posted', uc.success === true, uc);
    check('S7: creator A notified despite no assignee', countType(await notifs(A.email, case1), 'comment_added') === 3);
    check('S7: unassigned case produces no assignee-side rows for B', countType(await notifs(B.email, case1), 'comment_added') === 1);

    // ── 8. assignment label expansion ────────────────────────────────────
    const lbl = await sessionHelper(A, { action: 'ensure_assignment_label', label: LABEL });
    check('S8: label fixture created', lbl.success === true && lbl.label_id > 0, lbl);
    const dMap = await helper({ action: 'setup_practice_member', email: D.email, password: PASSWORD, firstName: 'DentaTrakTest', lastName: D.name, adminEmail: A.email, labelIds: [lbl.label_id] });
    check('S8: D mapped to label', dMap.success === true, dMap);
    const case2 = await createCase(A, `CN2-${RUN}`, LABEL);
    createdCases.push(case2);
    check('S8: label-mapped D notified of case_created', countType(await notifs(D.email, case2), 'case_created') === 1);
    check('S8: unmapped C not notified', (await notifs(C.email, case2)).length === 0);
    const lc = await comment(C, case2, 'comment on label case');
    check('S8: comment on label case posted', lc.success === true, lc);
    check('S8: creator A + label member D get comment_added',
      countType(await notifs(A.email, case2), 'comment_added') === 1 &&
      countType(await notifs(D.email, case2), 'comment_added') === 1);
    check('S8: exactly one row per recipient on label case',
      (await notifs(D.email, case2)).length === 2); // case_created + comment_added

    // ── 9. member removed from practice loses notifications ──────────────
    const case3 = await createCase(R, `CN3-${RUN}`, B.email);
    createdCases.push(case3);
    check('S9: assignee B notified of R-created case', countType(await notifs(B.email, case3), 'case_created') === 1);
    await comment(B, case3, 'first comment while R is a member');
    check('S9: creator R notified while a member', countType(await notifs(R.email, case3), 'comment_added') === 1);
    const rm = await sessionHelper(A, { action: 'remove_practice_member', email: R.email });
    check('S9: R removed from practice', rm.success === true && rm.removed === true, rm);
    const rmC = await comment(B, case3, 'second comment after R removed');
    check('S9: post-removal comment posted', rmC.success === true, rmC);
    check('S9: removed creator R gets nothing more - no permanent access',
      countType(await notifs(R.email, case3), 'comment_added') === 1);

    // ── 10. limited-visibility creator loses access after flip ───────────
    const case4 = await createCase(L, `CN4-${RUN}`, B.email);
    createdCases.push(case4);
    await comment(B, case4, 'comment while L is a normal member');
    check('S10: creator L notified while authorized', countType(await notifs(L.email, case4), 'comment_added') === 1);
    const flip = await helper({ action: 'setup_practice_member', email: L.email, password: PASSWORD, firstName: 'DentaTrakTest', lastName: L.name, adminEmail: A.email, limitedVisibility: true, canEditCases: true });
    check('S10: L flipped to limited visibility', flip.success === true, flip);
    await comment(B, case4, 'comment after L became limited');
    check('S10: limited creator L unauthorized - gets nothing more',
      countType(await notifs(L.email, case4), 'comment_added') === 1);

    // ── 11. email queue: creator exemption, cancellation, preferences ────
    // A background worker may drain the queue on this machine, so assert on
    // queue-row terminal states (deterministic) rather than send timing.
    async function runWorker() {
      const r = await reqCtx.post(`${BASE}/api/notification-queue-worker.php`, {
        headers: { 'X-Queue-Worker-Token': WORKER_TOKEN, 'Content-Type': 'application/json' }, data: {},
      });
      return r.json().catch(() => ({}));
    }
    async function waitQueue(caseId, timeoutMs = 25000) {
      const end = Date.now() + timeoutMs;
      let q = [];
      while (Date.now() < end) {
        q = (await helper({ action: 'get_email_queue', case_id: caseId })).queue || [];
        if (q.length && q.every(r => ['sent', 'cancelled', 'failed'].includes(r.status))) return q;
        await runWorker();
        await new Promise(r => setTimeout(r, 400));
      }
      return q;
    }

    await helper({ action: 'clear_test_email_log' });
    const case5 = await createCase(A, `CN5-${RUN}`, B.email);
    createdCases.push(case5);
    await comment(C, case5, 'comment before reassignment');
    // Reassign B -> C: B's still-pending emails must cancel (no longer
    // assigned, not creator); A's creator email must survive either way.
    await api(A, 'update-case-assignment.php', { caseId: case5, assignedTo: C.email });
    await runWorker();

    const q5 = await waitQueue(case5);
    const qFor = (email, type) => q5.filter(r => r.email === email && r.event_type === type);
    check('S11: creator A comment email sent despite reassignment (exemption)',
      qFor(A.email, 'comment_added').length === 1 && qFor(A.email, 'comment_added')[0].status === 'sent',
      qFor(A.email, 'comment_added'));
    const bRows = q5.filter(r => r.email === B.email);
    check('S11: B rows sent before, or cancelled by, the reassignment - never case_unassigned',
      bRows.length >= 1 && bRows.every(r =>
        r.status === 'sent' ||
        (r.status === 'cancelled' && /assignment_or_label_revoked/.test(r.error_message || ''))),
      bRows);
    check('S11: new assignee C assignment email sent',
      qFor(C.email, 'assignment_changed').length === 1 && qFor(C.email, 'assignment_changed')[0].status === 'sent',
      qFor(C.email, 'assignment_changed'));

    // Privacy: no patient name inside any recorded notification email.
    const sentEmails = (await helper({ action: 'get_all_app_emails' })).emails || [];
    check('S11: no patient name in any notification email',
      sentEmails.length > 0 && sentEmails.every(e =>
        !(e.html || '').includes(`CN5-${RUN}`) && !(e.text || '').includes(`CN5-${RUN}`) &&
        !(e.html || '').includes('DentaTrakTest-CN') && !(e.text || '').includes('DentaTrakTest-CN')),
      sentEmails.map(e => e.subject));

    // Preference: disable comment_added email for A; in-app still arrives.
    const prefSave = await api(A, 'save-notification-preferences.php', {
      preferences: [{ event_type: 'comment_added', enabled: false }],
    });
    check('S11: comment_added preference disabled for A', prefSave.success === true, prefSave);
    await comment(B, case5, 'comment while A has comment email off');
    await waitQueue(case5).then(q => { // drain so the row count below is stable
      const aCommentRows = q.filter(r => r.email === A.email && r.event_type === 'comment_added');
      check('S11: no new email queue row for A while comment_added disabled', aCommentRows.length === 1, aCommentRows);
    });
    check('S11: A still gets the in-app comment_added row', countType(await notifs(A.email, case5), 'comment_added') === 2);

    // Mention still emails A via the mention preference, once (dedup).
    const mm = await comment(B, case5, 'ping @Admin', [{ user_id: A.id, mention: 'AliceAdmin' }]);
    check('S11: mention comment posted', mm.success === true, mm);
    const q5b = await waitQueue(case5);
    const aMention = q5b.filter(r => r.email === A.email && r.event_type === 'mention');
    const aCommentRows2 = q5b.filter(r => r.email === A.email && r.event_type === 'comment_added');
    check('S11: exactly one mention queue row for A - sent via mention preference',
      aMention.length === 1 && aMention[0].status === 'sent', aMention);
    check('S11: no duplicate comment_added queue row for the mentioned comment',
      aCommentRows2.length === 1, aCommentRows2);
    const aTypes = await notifs(A.email, case5);
    check('S11: mentioned A gets mention row not comment_added', aTypes.filter(t => t === 'mention').length === 1, aTypes);

  } finally {
    // ── cleanup ──────────────────────────────────────────────────────────
    try {
      if (createdCases.length) await helper({ action: 'delete_test_cases', practice_id: A.practiceId, case_ids: createdCases });
      await helper({ action: 'delete_test_users', marker: 'DentaTrakTest', emails: [B.email, C.email, D.email, R.email, L.email] });
      await helper({ action: 'cleanup_test_user', email: A.email });
    } catch (e) { /* best effort */ }
    for (const k of Object.keys(ctxs)) { try { await ctxs[k].ctx.close(); } catch (e) {} }
    await browser.close();
  }

  console.log(`\n${checks - failures.length} passed, ${failures.length} failed`);
  if (failures.length) { console.log('FAILED:', failures.join(' | ')); process.exit(1); }
})().catch(e => { console.error('FATAL', e); process.exit(1); });
