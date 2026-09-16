/**
 * Download All (case attachments ZIP) end-to-end verification.
 *
 * Regression coverage for the reported failure where the Download All button
 * activated but no download was produced. Root cause: main.php's CSP
 * `frame-src` lacked 'self', so the browser blocked the hidden same-origin
 * iframe the download form posts into — the request never reached the server.
 *
 * This test intercepts the two download endpoints with Playwright routes so
 * it needs no GCS access, while still exercising the real client code path:
 * updateDownloadAllButton -> downloadCaseAttachmentsZip -> preflight fetch ->
 * hidden iframe form POST -> handoff cookie / error document handling.
 * If the page CSP still blocked same-origin frames, the download route would
 * never be hit and the download event would never fire.
 *
 * Run: node tests/download-all-e2e-test.js
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium, firefox, webkit } = require('playwright');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const TEST_MARKER = 'DentaTrakTest-DownloadAll';

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

async function createCase(page, overrides) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const data = Object.assign({
    patientFirstName: TEST_MARKER,
    patientLastName: 'ZipCase',
    patientDOB: '1990-01-01',
    patientGender: 'Female',
    dentistName: 'Dr. ZipTest',
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

async function openCaseById(page, caseId) {
  const opened = await page.evaluate(({ id }) => window.openCaseById(id, { tab: 'details' }), { id: caseId });
  if (!opened) throw new Error('openCaseById returned false for case ' + caseId);
  await page.waitForFunction(() => {
    const f = document.getElementById('patientFirstName');
    return f && f.value.length > 0;
  }, undefined, { timeout: 15000 });
}

// Build a real stored ZIP on disk with known file contents.
function buildFixtureZip(tmpDir) {
  const srcDir = path.join(tmpDir, 'zipsrc');
  fs.mkdirSync(srcDir, { recursive: true });
  const contents = {
    'scan.stl': 'solid scan ' + 'A'.repeat(2048),
    'notes.pdf': '%PDF-1.4 fake ' + 'B'.repeat(1024),
    'photo.png': '\x89PNG fake ' + 'C'.repeat(512),
  };
  for (const [name, data] of Object.entries(contents)) {
    fs.writeFileSync(path.join(srcDir, name), data, 'binary');
  }
  const zipPath = path.join(tmpDir, 'fixture.zip');
  // Relative paths: bsdtar parses drive letters as remote host syntax.
  execFileSync('tar', ['-a', '-c', '-f', 'fixture.zip', '-C', 'zipsrc', ...Object.keys(contents)], { cwd: tmpDir });
  return { zipBytes: fs.readFileSync(zipPath), contents };
}

function unzipToList(zipName, cwd) {
  return execFileSync('tar', ['-tf', zipName], { cwd }).toString().trim().split(/\r?\n/).sort();
}
function unzipExtract(zipName, name, cwd) {
  return execFileSync('tar', ['-xOf', zipName, name], { cwd }).toString('binary');
}

let fatalError = null;
(async () => {
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'dt-zip-test-'));
  const { zipBytes, contents } = buildFixtureZip(tmpDir);
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

    // Clean stray marker cases from previous runs.
    const stray = await testHelper(page, { action: 'list_test_cases' });
    const strayIds = (stray.case_ids || []).filter(async id => true);
    if (strayIds.length) await deleteTestCases(page, strayIds).catch(() => {});

    const c = await createCase(page, {});
    const caseId = c.id || c.caseId || c.case_id;
    seededIds.push(caseId);

    const setRes = await testHelper(page, {
      action: 'set_case_attachments',
      case_id: caseId,
      attachments: [
        { fileName: 'scan.stl', storageType: 'gcs', storagePath: `cases/1/${caseId}/docs/aaaa-scan.stl`, size: 2048 },
        { fileName: 'notes.pdf', storageType: 'gcs', storagePath: `cases/1/${caseId}/docs/bbbb-notes.pdf`, size: 1024 },
        { fileName: 'photo.png', storageType: 'gcs', storagePath: `cases/1/${caseId}/docs/cccc-photo.png`, size: 512 },
      ],
    });
    check('fixture: set_case_attachments succeeded', setRes && setRes.success === true, JSON.stringify(setRes).slice(0, 120));

    /* ---------- Scenario A: happy path, handoff via cookie ---------- */
    let preflightCount = 0;
    let downloadCount = 0;
    let sawDownloadToken = false;

    await page.route('**/api/preflight-download-case-attachments-zip.php', route => {
      preflightCount++;
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, file_count: 3, total_size: 3584, zip_filename: 'case-test-attachments.zip' }),
      });
    });
    await page.route('**/api/download-case-attachments-zip.php', route => {
      downloadCount++;
      const post = route.request().postData() || '';
      const m = post.match(/download_token=([A-Za-z0-9_-]+)/);
      if (m) sawDownloadToken = true;
      const headers = {
        'Content-Type': 'application/zip',
        'Content-Disposition': 'attachment; filename="case-test-attachments.zip"',
      };
      if (m) headers['Set-Cookie'] = `dt_zip_dl_${m[1]}=1; Path=/`;
      route.fulfill({ status: 200, headers, body: zipBytes });
    });

    await openCaseById(page, caseId);
    await page.waitForSelector('#attachmentDownloadAll', { state: 'visible', timeout: 15000 });
    const btnText = await page.$eval('#downloadAllAttachmentsBtn', el => el.textContent.trim());
    check('button visible with count', /Download All \(3\)/.test(btnText), btnText);

    const downloadP = page.waitForEvent('download', { timeout: 20000 }).catch(() => null);
    await page.click('#downloadAllAttachmentsBtn');
    const download = await downloadP;
    if (download) {
      check('browser download event fired (request reached endpoint)', true, download.suggestedFilename());
    } else if (process.env.DT_BROWSER === 'webkit') {
      // Playwright-WebKit does not emit 'download' for iframe-initiated
      // downloads. Verify handoff via the status element instead: it only
      // shows the handoff message after the server-set cookie is observed.
      const handedOff = await page.waitForFunction(
        () => /handed to your browser/i.test(
          (document.getElementById('downloadAllAttachmentsStatus') || {}).textContent || ''),
        undefined, { timeout: 15000 }
      ).then(() => true).catch(() => false);
      check('handoff confirmed via status (webkit has no download event)', handedOff);
    } else {
      check('browser download event fired (request reached endpoint)', false, 'no download event');
    }
    check('download endpoint received download_token field', sawDownloadToken);
    check('preflight called exactly once', preflightCount === 1, 'count=' + preflightCount);
    check('download endpoint called exactly once', downloadCount === 1, 'count=' + downloadCount);

    if (download) {
      const savedPath = path.join(tmpDir, 'downloaded.zip');
      await download.saveAs(savedPath);
      const listing = unzipToList('downloaded.zip', tmpDir);
      check('ZIP is valid and lists expected files', ['notes.pdf', 'photo.png', 'scan.stl'].every(f => listing.includes(f)), listing.join(','));
      let contentsOk = true;
      for (const [name, data] of Object.entries(contents)) {
        if (unzipExtract('downloaded.zip', name, tmpDir) !== data) contentsOk = false;
      }
      check('ZIP member contents intact', contentsOk);
    }

    await page.waitForFunction(() => {
      const el = document.getElementById('downloadAllAttachmentsStatus');
      return el && el.style.display !== 'none' && /handed to your browser/i.test(el.textContent);
    }, undefined, { timeout: 15000 });
    check('status shows honest handoff message with download-location guidance', true);
    const statusText = await page.$eval('#downloadAllAttachmentsStatus', el => el.textContent);
    check('status mentions browser download location', /configured download location/i.test(statusText), statusText.slice(0, 120));

    await page.waitForFunction(() => !document.getElementById('downloadAllAttachmentsBtn').disabled, undefined, { timeout: 10000 });
    const labelAfter = await page.$eval('#downloadAllAttachmentsBtn .download-all-label', el => el.textContent.trim());
    check('button restored after handoff', labelAfter === 'Download All', labelAfter);

    /* ---------- Scenario B: duplicate clicks start no duplicate jobs ---------- */
    // Dispatch two click events synchronously: the first starts the job and
    // sets the in-flight flag before returning, so the second must be a no-op.
    const dl2P = page.waitForEvent('download', { timeout: 20000 }).catch(() => null);
    await page.evaluate(() => {
      const b = document.getElementById('downloadAllAttachmentsBtn');
      b.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      b.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      // Also invoke the handler directly while disabled.
      if (typeof b.onclick === 'function') b.onclick({ preventDefault() {} });
    });
    const download2 = await dl2P;
    if (download2) {
      await download2.saveAs(path.join(tmpDir, 'downloaded2.zip'));
    } else {
      // WebKit: wait for handoff via cookie instead of the download event.
      await page.waitForFunction(() => !document.getElementById('downloadAllAttachmentsBtn').disabled,
        undefined, { timeout: 20000 }).catch(() => {});
    }
    await page.waitForTimeout(1500);
    check('rapid second click did not duplicate the job', preflightCount === 2 && downloadCount === 2,
      `preflight=${preflightCount} download=${downloadCount}`);

    /* ---------- Scenario C: endpoint JSON error surfaces in the iframe ---------- */
    await page.unroute('**/api/download-case-attachments-zip.php');
    await page.route('**/api/download-case-attachments-zip.php', route => {
      downloadCount++;
      route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: 'Test-forbidden: zip denied' }),
      });
    });

    await page.click('#downloadAllAttachmentsBtn');
    await page.waitForFunction(() => {
      const el = document.getElementById('downloadAllAttachmentsStatus');
      return el && /Test-forbidden: zip denied/.test(el.textContent);
    }, undefined, { timeout: 15000 });
    check('server JSON error inside iframe is surfaced to the user', true);
    await page.waitForFunction(() => !document.getElementById('downloadAllAttachmentsBtn').disabled, undefined, { timeout: 10000 });
    check('button restored after endpoint failure', true);

    /* ---------- Scenario D: preflight failure blocks the download POST ---------- */
    const downloadsBefore = downloadCount;
    await page.unroute('**/api/preflight-download-case-attachments-zip.php');
    await page.route('**/api/preflight-download-case-attachments-zip.php', route => {
      route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: 'Test-preflight: not eligible' }),
      });
    });
    await page.click('#downloadAllAttachmentsBtn');
    await page.waitForFunction(() => {
      const el = document.getElementById('downloadAllAttachmentsStatus');
      return el && /Test-preflight: not eligible/.test(el.textContent);
    }, undefined, { timeout: 15000 });
    await page.waitForTimeout(800);
    check('preflight failure shows error and never posts download', downloadCount === downloadsBefore,
      'downloadCount=' + downloadCount);
    await page.waitForFunction(() => !document.getElementById('downloadAllAttachmentsBtn').disabled, undefined, { timeout: 10000 });

    /* ---------- Scenario E: case with no attachments hides the button ---------- */
    const empty = await createCase(page, { patientLastName: 'NoFiles' });
    const emptyId = empty.id || empty.caseId || empty.case_id;
    seededIds.push(emptyId);
    await openCaseById(page, emptyId);
    await page.waitForTimeout(500);
    const containerHidden = await page.evaluate(() => {
      const c = document.getElementById('attachmentDownloadAll');
      return !c || getComputedStyle(c).display === 'none';
    });
    check('empty case keeps Download All hidden', containerHidden);

  } catch (e) {
    fatalError = e;
  } finally {
    const failed = results.filter(r => !r.pass);
    if (fatalError) console.log('FATAL: ' + (fatalError.stack || fatalError.message));
    console.log('\n' + results.length + ' checks, ' + failed.length + ' failed');
    if (consoleErrors.length) {
      console.log('console errors:'); consoleErrors.slice(0, 10).forEach(e => console.log('  ' + e.slice(0, 200)));
    }
    if (seededIds.length) {
      try { await deleteTestCases(page, seededIds); } catch (e) { console.log('cleanup failed: ' + e.message); }
    }
    await browser.close();
    fs.rmSync(tmpDir, { recursive: true, force: true });
    process.exit((failed.length || fatalError) ? 1 : 0);
  }
})().catch(e => { fatalError = e; });
