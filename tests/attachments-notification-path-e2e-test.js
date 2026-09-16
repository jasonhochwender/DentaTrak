/**
 * Attachments-on-notification-open + Download All alignment regression test.
 *
 * Issue 1 (fixed): the Download All button jumped left when the status line
 * appeared — .attachment-download-all was a column flexbox with
 * align-items:flex-start, so the wider status row widened the column and
 * left-aligned the button inside it. Children are now right-aligned and the
 * container hugs the right edge in every state.
 *
 * Issue 2 (fixed): opening a case via a comment notification (openCaseById,
 * view=core + async heavy fetch) rendered attachments through the legacy
 * displayExistingFiles(), which groups by lowercase file.type keys and targets
 * a #documents-files container that does not exist — real attachments carry
 * API types like 'Photos', so nothing ever rendered. The heavy path now calls
 * the shared renderExistingAttachments() used by the board/list path.
 *
 * Run: node tests/attachments-notification-path-e2e-test.js
 *      DT_BROWSER=firefox|webkit for other engines.
 */
'use strict';

const { chromium, firefox, webkit } = require('playwright');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const TEST_MARKER = 'DentaTrakTest-AttachPath';

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

async function createCase(page, lastName) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(body).toString(),
    });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body: {
    patientFirstName: TEST_MARKER, patientLastName: lastName, patientDOB: '1990-01-01',
    patientGender: 'Female', dentistName: 'Dr. T', caseType: 'Veneer', material: 'Zirconia',
    dueDate: '2026-12-15', status: 'Originated', notes: TEST_MARKER + ' ' + lastName,
    assignedTo: EMAIL, csrf_token: csrf,
  }});
  const c = res.caseData || res.case || res;
  return c.id || c.caseId || c.case_id;
}

async function testHelper(page, body) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/test-helpers.php`, body: Object.assign({ csrf_token: csrf }, body) });
}

async function postComment(page, caseId, text) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/case-comments.php`, body: { action: 'create', case_id: caseId, text, csrf_token: csrf } });
}

async function openCaseById(page, caseId, opts) {
  const opened = await page.evaluate(({ id, o }) => window.openCaseById(id, o), { id: caseId, o: opts || { tab: 'comments' } });
  if (!opened) throw new Error('openCaseById returned false for ' + caseId);
  await page.waitForFunction(() => {
    const f = document.getElementById('patientFirstName');
    return f && f.value.length > 0;
  }, undefined, { timeout: 15000 });
}

async function closeModal(page) {
  await page.evaluate(() => {
    if (typeof window.clearCaseComments === 'function') window.clearCaseComments();
    const modal = document.getElementById('createCaseModal');
    if (modal) { modal.classList.remove('active'); modal.style.display = 'none'; }
  });
  await page.waitForTimeout(150);
}

async function switchToDetails(page) {
  await page.click('.case-tab[data-tab="details"]');
  await page.waitForTimeout(150);
}

function renderedAttachmentInfo(page) {
  return page.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('#createCaseForm .selected-file.existing-file'));
    return {
      count: rows.length,
      names: rows.map(r => (r.querySelector('.file-name, a, span') || r).textContent.trim()),
      groups: rows.map(r => r.closest('.selected-files').id),
      hasView: rows.every(r => r.querySelector('.attachment-view-link')),
      hasDownload: rows.every(r => r.querySelector('.attachment-download-link')),
    };
  });
}

function buttonRightDelta(page) {
  return page.evaluate(() => {
    const btn = document.getElementById('downloadAllAttachmentsBtn');
    const header = document.querySelector('.attachments-section-header');
    if (!btn || !header) return null;
    const b = btn.getBoundingClientRect();
    const h = header.getBoundingClientRect();
    return { btnRight: b.right, headerRight: h.right, gap: h.right - b.right };
  });
}

const ATTACHMENTS = [
  { fileName: 'photo.png', type: 'Photos', storageType: 'gcs', storagePath: 'cases/1/x/photo.png', size: 512 },
  { fileName: 'scan.stl', type: 'IntraoralScans', storageType: 'gcs', storagePath: 'cases/1/x/scan.stl', size: 2048 },
  { fileName: 'face.png', type: 'FacialScans', storageType: 'gcs', storagePath: 'cases/1/x/face.png', size: 1024 },
];

let fatalError = null;
(async () => {
  const seededIds = [];
  const browser = await BROWSER.launch();
  const context = await browser.newContext({ acceptDownloads: true });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', e => consoleErrors.push('PAGEERROR: ' + e.message));

  try {
    await login(context);
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

    const stray = await testHelper(page, { action: 'list_test_cases' });
    if ((stray.case_ids || []).length) {
      await testHelper(page, { action: 'delete_test_cases', case_ids: stray.case_ids }).catch(() => {});
    }

    const caseA = await createCase(page, 'WithFiles');
    const caseB = await createCase(page, 'NoFiles');
    seededIds.push(caseA, caseB);
    console.log('  cases:', caseA, caseB);

    const setRes = await testHelper(page, { action: 'set_case_attachments', case_id: caseA, attachments: ATTACHMENTS });
    check('fixture: attachments set', setRes && setRes.success === true, JSON.stringify(setRes).slice(0, 120));

    // A comment on case A drives the notification-focus routing check.
    const cmt = await postComment(page, caseA, 'notification target comment');
    const commentId = cmt.comment && cmt.comment.id;
    check('fixture: comment posted', !!commentId, JSON.stringify(cmt).slice(0, 100));

    await page.reload({ waitUntil: 'networkidle' });

    /* --- 1. Notification path (openCaseById → core + async heavy) --- */
    await openCaseById(page, caseA, { tab: 'comments', commentId });
    check('notification path lands on comments tab', await page.$eval(
      '.case-tab[data-tab="comments"]', el => el.classList.contains('case-tab-active')));

    // Comment routing to the specific comment is preserved.
    const focused = await page.waitForSelector('.case-comment-focused', { timeout: 8000 }).catch(() => null);
    check('target comment highlighted (notification routing preserved)', !!focused);

    await switchToDetails(page);
    const info = await page.waitForFunction(() => {
      return document.querySelectorAll('#createCaseForm .selected-file.existing-file').length;
    }, undefined, { timeout: 15000 }).then(() => renderedAttachmentInfo(page));
    check('notification path renders all 3 attachments', info.count === 3, JSON.stringify(info.names));
    check('attachments grouped into correct containers',
      info.groups.includes('photos-files') && info.groups.includes('intraoralScans-files') && info.groups.includes('facialScans-files'),
      info.groups.join(','));
    check('View + Download actions present', info.hasView && info.hasDownload);
    check('no stale loading indicator after success', await page.$eval(
      '#attachmentsLoadStatus', el => el.style.display === 'none'));
    await closeModal(page);

    /* --- 2. Delayed heavy response: loading state then attachments --- */
    await page.route('**/api/get-case.php*view=heavy*', async route => {
      await new Promise(r => setTimeout(r, 900));
      return route.continue();
    });
    await openCaseById(page, caseA, { tab: 'comments' });
    const loadingShown = await page.waitForFunction(() => {
      const el = document.getElementById('attachmentsLoadStatus');
      return el && el.style.display !== 'none' && /loading attachments/i.test(el.textContent);
    }, undefined, { timeout: 5000 }).then(() => true).catch(() => false);
    check('loading state shown while heavy fetch in flight', loadingShown);
    const infoDelayed = await page.waitForFunction(() => {
      return document.querySelectorAll('#createCaseForm .selected-file.existing-file').length === 3;
    }, undefined, { timeout: 15000 }).then(() => renderedAttachmentInfo(page));
    check('attachments render after delayed heavy response', infoDelayed.count === 3, JSON.stringify(infoDelayed.names));
    await closeModal(page);
    await page.unroute('**/api/get-case.php*view=heavy*');

    /* --- 3. Stale heavy response cannot populate the wrong case --- */
    // Delay A's heavy fetch, open A, then immediately open B (no attachments).
    // A's late response must be discarded — B must show zero existing files.
    let heavyCalls = 0;
    await page.route('**/api/get-case.php*view=heavy*', async route => {
      heavyCalls++;
      const url = route.request().url();
      const delay = url.includes(encodeURIComponent(caseA)) ? 1500 : 100;
      await new Promise(r => setTimeout(r, delay));
      return route.continue();
    });
    await openCaseById(page, caseA, { tab: 'comments' });
    await openCaseById(page, caseB, { tab: 'comments' }); // bumps requestId
    await page.waitForTimeout(2500); // let A's late response arrive
    const staleInfo = await renderedAttachmentInfo(page);
    check('late heavy response discarded — wrong-case attachments not shown',
      staleInfo.count === 0, `count=${staleInfo.count} names=${JSON.stringify(staleInfo.names)}`);
    check('both heavy requests actually fired', heavyCalls >= 2, 'calls=' + heavyCalls);
    await closeModal(page);
    await page.unroute('**/api/get-case.php*view=heavy*');

    /* --- 4. Heavy failure: error state, not silent empty --- */
    await page.route('**/api/get-case.php*view=heavy*', route => {
      route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'simulated' }) });
    });
    await openCaseById(page, caseA, { tab: 'comments' });
    const errorShown = await page.waitForFunction(() => {
      const el = document.getElementById('attachmentsLoadStatus');
      return el && el.style.display !== 'none' && /unable to load attachments/i.test(el.textContent);
    }, undefined, { timeout: 8000 }).then(() => true).catch(() => false);
    check('failed heavy load shows honest error (not silent empty)', errorShown);
    const failInfo = await renderedAttachmentInfo(page);
    check('failed load renders no phantom attachments', failInfo.count === 0);
    await closeModal(page);
    await page.unroute('**/api/get-case.php*view=heavy*');

    /* --- 5. Board path still renders attachments --- */
    const cardSelector = `.kanban-card[data-case-id="${caseA}"]`;
    await page.click(`${cardSelector} .case-actions-toggle`);
    await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForSelector('#createCaseForm .selected-file.existing-file', { state: 'attached', timeout: 15000 });
    const boardInfo = await renderedAttachmentInfo(page);
    check('board path renders all 3 attachments', boardInfo.count === 3, JSON.stringify(boardInfo.names));

    /* --- 6. Download All button stays right-aligned through every state --- */
    // Button right edge must keep a constant gap to the header's right edge.
    const idle = await buttonRightDelta(page);
    check('button hugs right edge at idle', idle && idle.gap >= -1 && idle.gap <= 2, idle && ('gap=' + idle.gap.toFixed(1)));

    await page.route('**/api/preflight-download-case-attachments-zip.php', route => {
      route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true, file_count: 3, total_size: 3584, zip_filename: 'case.zip' }) });
    });
    await page.route('**/api/download-case-attachments-zip.php', async route => {
      await new Promise(r => setTimeout(r, 700)); // keep "Preparing…" visible
      const m = (route.request().postData() || '').match(/download_token=([A-Za-z0-9_-]+)/);
      const headers = { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="case.zip"' };
      if (m) headers['Set-Cookie'] = `dt_zip_dl_${m[1]}=1; Path=/`;
      route.fulfill({ status: 200, headers, body: Buffer.from('PK\x05\x06' + '\x00'.repeat(18), 'binary') });
    });

    const dlP = page.waitForEvent('download', { timeout: 15000 }).catch(() => null);
    await page.click('#downloadAllAttachmentsBtn');
    await page.waitForFunction(() => {
      const el = document.getElementById('downloadAllAttachmentsStatus');
      return el && el.style.display !== 'none' && /preparing/i.test(el.textContent);
    }, undefined, { timeout: 8000 });
    const preparing = await buttonRightDelta(page);
    check('button stays right-aligned while preparing', preparing && Math.abs(preparing.gap - idle.gap) <= 2,
      `idle=${idle.gap.toFixed(1)} preparing=${preparing.gap.toFixed(1)}`);

    await dlP; // handoff (or webkit: fall through)
    await page.waitForFunction(() => {
      const el = document.getElementById('downloadAllAttachmentsStatus');
      return el && el.style.display !== 'none' && /handed to your browser/i.test(el.textContent);
    }, undefined, { timeout: 15000 });
    const handedOff = await buttonRightDelta(page);
    check('button stays right-aligned with long handoff status', handedOff && Math.abs(handedOff.gap - idle.gap) <= 2,
      `idle=${idle.gap.toFixed(1)} handoff=${handedOff.gap.toFixed(1)}`);
    const wraps = await page.evaluate(() => {
      const st = document.getElementById('downloadAllAttachmentsStatus');
      const header = document.querySelector('.attachments-section-header');
      return st.getBoundingClientRect().right <= header.getBoundingClientRect().right + 2;
    });
    check('status text wraps inside header width (no overflow)', wraps);
    await closeModal(page);
    await page.unroute('**/api/preflight-download-case-attachments-zip.php');
    await page.unroute('**/api/download-case-attachments-zip.php');

    /* --- 7. List view path still renders attachments --- */
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row', { state: 'attached', timeout: 15000 });
    await page.click(`.case-list-open[data-case-id="${caseA}"]`);
    await page.waitForSelector('#createCaseForm .selected-file.existing-file', { state: 'attached', timeout: 15000 });
    const listInfo = await renderedAttachmentInfo(page);
    check('list path renders all 3 attachments', listInfo.count === 3, JSON.stringify(listInfo.names));
    await closeModal(page);

    const realErrors = consoleErrors.filter(e => !/favicon|404|checkForUpdates|realtime-updates|Failed to load resource.*500/.test(e));
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
