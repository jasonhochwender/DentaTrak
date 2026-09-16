/**
 * Comment timestamp end-to-end regression test.
 *
 * Root cause covered: case_comments.created_at is a timezone-less MySQL
 * DATETIME. The API returned it as a bare 'Y-m-d H:i:s' string, which
 * browsers parse as *browser-local* time. On a server storing UTC (prod),
 * a browser in US Eastern rendered a 4-hour-old comment as "Just now" —
 * and the shift reversed sign across DST. The API now emits ISO-8601 UTC
 * via UNIX_TIMESTAMP() (interpreted in the DB session timezone), the
 * client validates dates, and invalid/missing values show
 * "Time unavailable" instead of "Just now".
 *
 * Also verifies: adding a comment bumps cases_cache.last_update_date
 * (case Updated timestamp / sort), and the exact timestamp tooltip.
 *
 * Run: node tests/comment-timestamps-e2e-test.js
 *      DT_BROWSER=firefox|webkit for other engines.
 */
'use strict';

const { chromium, firefox, webkit } = require('playwright');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const TEST_MARKER = 'DentaTrakTest-CommentTS';
const ISO_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/;

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

async function createCase(page) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const data = {
    patientFirstName: TEST_MARKER,
    patientLastName: 'TsCase',
    patientDOB: '1990-01-01',
    patientGender: 'Female',
    dentistName: 'Dr. TsTest',
    caseType: 'Veneer',
    material: 'Zirconia',
    dueDate: '2026-12-15',
    status: 'Originated',
    notes: TEST_MARKER + ' seeded case',
    assignedTo: EMAIL,
    csrf_token: csrf,
  };
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body).toString(),
    });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body: data });
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

async function postComment(page, caseId, text) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/case-comments.php`, body: { action: 'create', case_id: caseId, text, csrf_token: csrf } });
}

async function getComments(page, caseId) {
  return page.evaluate(async (url) => {
    const r = await fetch(url, { credentials: 'same-origin' });
    return r.json();
  }, `${BASE}/api/case-comments.php?case_id=${encodeURIComponent(caseId)}`);
}

async function getCaseLastUpdate(page, caseId) {
  const list = await page.evaluate(async (url) => {
    const r = await fetch(url, { credentials: 'same-origin' });
    return r.json();
  }, `${BASE}/api/list-cases.php`);
  const found = (list.cases || []).find(c => (c.id || c.case_id) === caseId);
  return found ? found.lastUpdateDate : null;
}

async function openCaseById(page, caseId) {
  const opened = await page.evaluate(({ id }) => window.openCaseById(id, { tab: 'comments' }), { id: caseId });
  if (!opened) throw new Error('openCaseById returned false for ' + caseId);
  await page.waitForFunction(() => {
    const f = document.getElementById('patientFirstName');
    return f && f.value.length > 0;
  }, undefined, { timeout: 15000 });
}

async function commentTimeLabel(page) {
  return page.evaluate(() => {
    const el = document.querySelector('#caseCommentsList .case-comment-time');
    return el ? { text: el.textContent, title: el.getAttribute('title'), ts: el.getAttribute('data-ts') } : null;
  });
}

async function closeCaseModal(page) {
  await page.evaluate(() => {
    if (typeof window.clearCaseComments === 'function') window.clearCaseComments();
    const modal = document.getElementById('createCaseModal');
    if (modal) { modal.classList.remove('active'); modal.style.display = 'none'; }
  });
}

let fatalError = null;
(async () => {
  const seededIds = [];
  const browser = await BROWSER.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => consoleErrors.push('PAGEERROR: ' + e.message));

  // Probe setInterval/clearInterval before page scripts run so the comment
  // timestamp refresh timer can be observed deterministically (no 60s waits).
  await page.addInitScript(() => {
    const probe = { created: [], cleared: new Set() };
    const origSet = window.setInterval.bind(window);
    const origClear = window.clearInterval.bind(window);
    window.setInterval = function(cb, delay, ...args) {
      const id = origSet(cb, delay, ...args);
      probe.created.push({ id, commentTimer: String(cb).includes('caseCommentsList'), cb });
      return id;
    };
    window.clearInterval = function(id) { probe.cleared.add(id); return origClear(id); };
    window.__timerProbe = {
      commentTimerId: () => {
        const m = probe.created.filter(c => c.commentTimer);
        return m.length ? m[m.length - 1].id : null;
      },
      isCleared: (id) => probe.cleared.has(id),
      tick: (id) => {
        const e = probe.created.find(c => c.id === id);
        if (e) e.cb();
      },
    };
  });

  try {
    await login(context);
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

    // Clean stray marker cases.
    const stray = await testHelper(page, { action: 'list_test_cases' });
    if ((stray.case_ids || []).length) {
      await testHelper(page, { action: 'delete_test_cases', case_ids: stray.case_ids }).catch(() => {});
    }

    const c = await createCase(page);
    const caseId = c.id || c.caseId || c.case_id;
    seededIds.push(caseId);
    console.log('  case: ' + caseId);

    // --- API: POST returns ISO-8601 UTC matching the stored instant ---
    const beforeUpdate = await getCaseLastUpdate(page, caseId);
    const post = await postComment(page, caseId, 'Timestamp regression comment');
    check('POST comment succeeded', post && post.success === true, JSON.stringify(post).slice(0, 140));
    const commentId = post.comment && post.comment.id;
    const postCreated = post.comment && post.comment.created_at;
    check('POST created_at is ISO-8601 UTC', ISO_RE.test(postCreated || ''), postCreated);

    // POST response must equal what GET returns for the persisted row.
    const get1 = await getComments(page, caseId);
    const stored = (get1.comments || []).find(x => x.id === commentId);
    check('GET created_at is ISO-8601 UTC', stored && ISO_RE.test(stored.created_at || ''), stored && stored.created_at);
    check('POST and GET timestamps agree', stored && stored.created_at === postCreated,
      `post=${postCreated} get=${stored && stored.created_at}`);

    // --- Case Updated timestamp bumped by comment ---
    const afterUpdate = await getCaseLastUpdate(page, caseId);
    check('comment bumped lastUpdateDate',
      !!afterUpdate && afterUpdate !== beforeUpdate,
      `before=${beforeUpdate} after=${afterUpdate}`);

    // --- New comment renders "Just now" immediately (notification/ID open path) ---
    await openCaseById(page, caseId);
    await page.waitForSelector('#caseCommentsList .case-comment', { timeout: 15000 });
    const fresh = await commentTimeLabel(page);
    check('new comment shows "Just now"', fresh && /just now/i.test(fresh.text), fresh && fresh.text);
    check('exact timestamp tooltip present', fresh && !!fresh.title && fresh.title.length > 4, fresh && fresh.title);
    check('data-ts epoch present', fresh && /^\d{10,}$/.test(fresh.ts || ''), fresh && fresh.ts);
    await closeCaseModal(page);

    // --- Backdate to 4h ago, full reload, reopen: must NOT say "Just now" ---
    const back = await testHelper(page, { action: 'set_comment_created_at', comment_id: commentId, minutes_ago: 240 });
    check('fixture: comment backdated', back && back.success === true, JSON.stringify(back).slice(0, 120));

    await page.reload({ waitUntil: 'networkidle' });
    await openCaseById(page, caseId);
    await page.waitForSelector('#caseCommentsList .case-comment', { timeout: 15000 });
    const old = await commentTimeLabel(page);
    check('4h-old comment does NOT show "Just now"', old && !/just now/i.test(old.text), old && old.text);
    check('4h-old comment shows hours-ago label', old && /4\s*(hours?|h)\b/i.test(old.text), old && old.text);
    check('old comment keeps exact-time tooltip', old && !!old.title && old.title.length > 4, old && old.title);
    await closeCaseModal(page);

    // --- Board entry path renders the same correct label ---
    const cardSelector = `.kanban-card[data-case-id="${caseId}"]`;
    const hasCard = await page.$(cardSelector);
    check('board card exists for test case', !!hasCard);
    if (hasCard) {
      // Board cards open the modal through their edit affordance.
      await page.click(`${cardSelector} .case-actions-toggle`);
      await page.click('#caseActionsMenu [data-action="edit"]');
      // Modal opens on the details tab — the comments panel is hidden but
      // still populated, so wait for attached (not visible).
      await page.waitForSelector('#caseCommentsList .case-comment', { state: 'attached', timeout: 15000 });
      const boardLabel = await commentTimeLabel(page);
      check('board-open label matches', boardLabel && boardLabel.text === old.text,
        `board=${boardLabel && boardLabel.text}`);
      await closeCaseModal(page);
    }

    // --- List view entry path renders the same correct label ---
    const hasToggle = await page.$('#listViewToggle');
    check('list view toggle exists', !!hasToggle);
    if (hasToggle) {
      await page.click('#listViewToggle');
      await page.waitForSelector('.case-list-row', { state: 'attached', timeout: 15000 });
      const openBtn = await page.$(`.case-list-open[data-case-id="${caseId}"]`);
      check('list view row exists for test case', !!openBtn);
      if (openBtn) {
        await openBtn.click();
        await page.waitForSelector('#caseCommentsList .case-comment', { state: 'attached', timeout: 15000 });
        const listLabel = await commentTimeLabel(page);
        check('list-open label matches', listLabel && listLabel.text === old.text,
          `list=${listLabel && listLabel.text}`);
        await closeCaseModal(page);
      }
      // Restore board view via the explicit board button (idempotent) and
      // wait for the card to be visible again before moving on.
      await page.click('#boardViewToggle').catch(() => {});
      await page.waitForSelector(cardSelector, { state: 'visible', timeout: 15000 });
    }

    // --- 60s refresh timer: starts with comments, stops on every close path ---
    // (a) X button -> closeCreateCaseWithCheck -> clearCaseComments
    await page.click(`${cardSelector} .case-actions-toggle`);
      await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForSelector('#caseCommentsList .case-comment', { state: 'attached', timeout: 15000 });
    const timerA = await page.evaluate(() => window.__timerProbe.commentTimerId());
    check('refresh timer started with comments', timerA !== null, String(timerA));
    await page.click('#createCaseClose');
    await page.waitForFunction(() => document.getElementById('createCaseModal').style.display !== 'block');
    const clearedA = timerA !== null &&
      await page.evaluate((id) => window.__timerProbe.isCleared(id), timerA);
    check('timer cleared on X-button close', clearedA);

    // (b) Escape -> closeCreateCaseWithCheck -> clearCaseComments
    await page.click(`${cardSelector} .case-actions-toggle`);
      await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForSelector('#caseCommentsList .case-comment', { state: 'attached', timeout: 15000 });
    const timerB = await page.evaluate(() => window.__timerProbe.commentTimerId());
    check('refresh timer restarted on reopen', timerB !== null && timerB !== timerA, String(timerB));
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.getElementById('createCaseModal').style.display !== 'block');
    const clearedB = timerB !== null &&
      await page.evaluate((id) => window.__timerProbe.isCleared(id), timerB);
    check('timer cleared on Escape close', clearedB);

    // (c) Bypass path: modal hidden without cleanup (e.g. "Back to Archived
    // Cases" or closeModals() while another modal overlays) -> the next tick
    // must self-stop.
    await page.click(`${cardSelector} .case-actions-toggle`);
      await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForSelector('#caseCommentsList .case-comment', { state: 'attached', timeout: 15000 });
    const timerC = await page.evaluate(() => window.__timerProbe.commentTimerId());
    await page.evaluate(() => { document.getElementById('createCaseModal').style.display = 'none'; });
    await page.evaluate((id) => window.__timerProbe.tick(id), timerC);
    const clearedC = timerC !== null &&
      await page.evaluate((id) => window.__timerProbe.isCleared(id), timerC);
    check('timer self-stops on hidden modal (bypass close)', clearedC);

    // --- Invalid/missing timestamps must not read "Just now" ---
    await page.route('**/api/case-comments.php*', route => {
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, comments: [
          { id: 900001, user_name: 'T U', text: 'null ts', created_at: null, is_deleted: false },
          { id: 900002, user_name: 'T U', text: 'garbage ts', created_at: 'not-a-date', is_deleted: false },
        ] }),
      });
    });
    await openCaseById(page, caseId);
    await page.waitForSelector('#caseCommentsList .case-comment', { timeout: 15000 });
    const labels = await page.$$eval('#caseCommentsList .case-comment-time', els => els.map(e => e.textContent));
    check('invalid timestamps never show "Just now"',
      labels.length === 2 && labels.every(l => !/just now/i.test(l)), JSON.stringify(labels));
    check('invalid timestamps show "Time unavailable"',
      labels.every(l => /unavailable/i.test(l)), JSON.stringify(labels));
    await closeCaseModal(page);
    await page.unroute('**/api/case-comments.php*');

    // The realtime-updates and notification-count pollers' in-flight fetches
    // are aborted by page.reload() — unrelated background noise, not a
    // comment-timestamp error.
    const realErrors = consoleErrors.filter(e =>
      !/favicon|404|checkForUpdates|realtime-updates|notification count/.test(e));
    check('no page console errors', realErrors.length === 0, realErrors.slice(0, 3).join(' | '));
  } catch (err) {
    fatalError = err;
    console.log('FATAL: ' + (err && err.stack || err));
  } finally {
    if (seededIds.length) {
      await testHelper(page, { action: 'delete_test_cases', case_ids: seededIds }).catch(() => {});
    }
    await browser.close();
  }

  const passed = results.filter(r => r.pass).length;
  console.log(`\n${passed}/${results.length} checks passed`);
  process.exit(fatalError || passed !== results.length ? 1 : 0);
})();
