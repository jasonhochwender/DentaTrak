/**
 * Upload failure-handling e2e: every known failure stage must surface an
 * accurate, localized message - never a blanket "check your internet
 * connection" - and permanent failures must be reported to
 * api/log-upload-error.php with sanitized, request-correlated details.
 *
 * Local dev has no GCS bucket, so the storage boundary is intercepted:
 * upload-signed-url.php and the GCS PUT are route-fulfilled with
 * programmable success/failure modes. update-case.php is fulfilled with
 * the case card's own data so the happy path can complete end-to-end.
 *
 * Run: node tests/upload-failure-e2e-test.js
 */
'use strict';

const zlib = require('zlib');
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e-test@example.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'D3n7@Tr@k!9Zf#Qm2xL8V';
const TEST_MARKER = 'DentaTrakTest';

let checks = 0;
const failures = [];
function check(name, cond, detail) {
  checks++;
  console.log((cond ? 'PASS ' : 'FAIL ') + name + (detail !== undefined ? '  ' + JSON.stringify(detail).slice(0, 300) : ''));
  if (!cond) failures.push(name);
}

// Minimal valid PNG + STL buffers
function crc32(buf) {
  let c, crc = 0xffffffff;
  for (let n = 0; n < buf.length; n++) {
    c = (crc ^ buf[n]) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function makePng(w, h, rgb) {
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) { raw[y * (w * 3 + 1)] = 0; for (let x = 0; x < w; x++) { const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = rgb[0]; raw[o + 1] = rgb[1]; raw[o + 2] = rgb[2]; } }
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const PNG_BYTES = makePng(64, 40, [30, 120, 60]);
const STL_BYTES = Buffer.concat([Buffer.alloc(80), Buffer.alloc(4)]); // header + 0 triangles
// Real ASCII STL - 7-bit text starting with "solid", which is why OS/browser
// MIME detection reports it as text/plain.
const ASCII_STL = Buffer.from(
  'solid ascii_scan\n' +
  '  facet normal 0 0 0\n' +
  '    outer loop\n' +
  '      vertex 0 0 0\n' +
  '      vertex 1 0 0\n' +
  '      vertex 0 1 0\n' +
  '    endloop\n' +
  '  endfacet\n' +
  'endsolid ascii_scan\n', 'ascii');

async function login(context) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: EMAIL, password: PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json().catch(() => ({}));
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
}

async function createCase(page, lastName) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const body = new URLSearchParams({
    patientFirstName: TEST_MARKER + '-Upl', patientLastName: lastName, patientDOB: '1990-01-01', patientGender: 'Male',
    dentistName: 'Dr. Test', caseType: 'Bite Rim', status: 'Originated', notes: TEST_MARKER + ' upload failure case',
    csrf_token: csrf,
  }).toString();
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body });
  const id = (res.caseData && (res.caseData.id || res.caseData.caseId)) || (res.case && res.case.id) || res.caseId;
  if (!id) throw new Error('create-case failed: ' + JSON.stringify(res).slice(0, 300));
  return String(id);
}

const toastText = page => page.evaluate(() =>
  Array.from(document.querySelectorAll('#toastContainer .toast')).map(el => el.textContent).join(' | '));

async function clearToasts(page) {
  await page.evaluate(() => {
    document.querySelectorAll('#toastContainer .toast').forEach(el => el.remove());
  });
}

/**
 * Programmable route interception:
 *   state.signedUrlMode: 'ok' | '401' | '403' | '500' | '400type' | 'html'
 *   state.putMode:       'ok' | 'fail500' | 'fail403once'
 *   state.putFailFiles:  { '<substr>': status } per-filename PUT status
 */
async function installRoutes(page, state) {
  await page.route('**/api/upload-signed-url.php', route => {
    let req = {};
    try { req = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
    state.signedUrlRequests.push({ filename: req.filename, mode: state.signedUrlMode, content_type: req.content_type });
    const rid = 'f' + (++state.reqCounter).toString(16).padStart(15, '0');
    const storagePath = `cases/1/${state.caseId || 'new'}/${req.upload_type || 'photos'}/abcd1234-${req.filename || 'file'}`;
    switch (state.signedUrlMode) {
      case '401':
        return route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Authentication required' }) });
      case '403':
        return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Access denied. This case is not assigned to you.' }) });
      case '500':
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'Failed to generate upload URL. Please try again.', error_code: 'signed_url_failed', request_id: rid }) });
      case '400type':
        return route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'File extension not allowed', error_code: 'unsupported_type', ext: 'stl', request_id: rid }) });
      case 'html':
        return route.fulfill({ status: 502, contentType: 'text/html', body: '<html><body>Bad Gateway</body></html>' });
      default:
        return route.fulfill({
          status: 200, contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            signed_url: `${BASE}/__fake-gcs-put?path=${encodeURIComponent(storagePath)}`,
            storage_path: storagePath,
            expires_at: new Date(Date.now() + 900000).toISOString(),
            // Mirror the real endpoint: the response echoes the content type
            // the URL was signed for, and the PUT must send that value.
            content_type: req.content_type || 'application/octet-stream',
            request_id: rid,
          }),
        });
    }
  });

  await page.route('**/__fake-gcs-put*', route => {
    const url = route.request().url();
    const path = decodeURIComponent((url.match(/path=([^&]*)/) || [])[1] || '');
    state.putRequests.push(path);
    state.putHeaders.push({ path: path, contentType: route.request().headers()['content-type'] || '' });
    const file = (path.split('-').pop() || '');
    for (const key of Object.keys(state.putFailFiles || {})) {
      if (path.indexOf(key) !== -1) {
        return route.fulfill({ status: state.putFailFiles[key], body: '' });
      }
    }
    if (state.putMode === 'fail500') {
      return route.fulfill({ status: 500, body: '' });
    }
    if (state.putMode === 'fail403once') {
      state.put403Count = (state.put403Count || 0) + 1;
      if (state.put403Count === 1) {
        return route.fulfill({ status: 403, body: '' });
      }
    }
    return route.fulfill({ status: 200, body: '' });
  });

  await page.route('**/api/log-upload-error.php', route => {
    let body = {};
    try { body = JSON.parse(route.request().postData() || '{}'); } catch (e) {}
    state.reports.push(body);
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
  });

  await page.route('**/api/update-case.php', route => {
    state.updatePosts.push(route.request().postData() || '');
    return route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ success: true, caseData: state.caseData }),
    });
  });
}

async function openCaseEdit(page, caseId) {
  const modalOpen = await page.evaluate(() => {
    const m = document.getElementById('createCaseModal');
    return !!m && getComputedStyle(m).display !== 'none';
  });
  if (modalOpen) return;
  await page.waitForSelector(`.kanban-card[data-case-id="${caseId}"]`, { state: 'attached', timeout: 20000 });
  await page.evaluate((cid) => {
    const card = document.querySelector(`.kanban-card[data-case-id="${cid}"]`);
    window.__cardJson = JSON.parse(card.dataset.caseJson);
    window.editCaseHandler(window.__cardJson);
  }, caseId);
  await page.waitForSelector('#createCaseModal', { state: 'visible', timeout: 10000 });
  await page.waitForTimeout(300);
}

async function clearStagedFiles(page) {
  await page.evaluate(() => {
    document.querySelectorAll('.attachment-input').forEach(i => {
      i._accumulatedFiles = [];
      try { i.value = ''; } catch (e) {}
    });
    document.querySelectorAll('.selected-file').forEach(el => el.remove());
  });
}

async function waitSubmitSettled(page, timeoutMs) {
  // Failure path: 'error' class shows, button re-enables after ~2s.
  await page.waitForFunction(() => {
    const b = document.getElementById('createCaseSubmit');
    return b && !b.disabled && !b.classList.contains('submitting');
  }, null, { timeout: timeoutMs || 20000 }).catch(() => {});
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const consoleErrors = [];
  page.on('pageerror', e => consoleErrors.push('pageerror: ' + e.message));
  const created = [];

  try {
    await login(context);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('meta[name="csrf-token"]', { state: 'attached' });
    await page.waitForTimeout(600);

    const caseId = await createCase(page, 'UploadFail');
    created.push(caseId);

    const state = {
      signedUrlRequests: [], putRequests: [], putHeaders: [], reports: [], updatePosts: [],
      signedUrlMode: 'ok', putMode: 'ok', putFailFiles: {}, reqCounter: 0,
      caseId, caseData: null,
    };
    await installRoutes(page, state);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`.kanban-card[data-case-id="${caseId}"]`, { state: 'attached', timeout: 20000 });
    await page.waitForTimeout(800);
    state.caseData = await page.evaluate((cid) => {
      const card = document.querySelector(`.kanban-card[data-case-id="${cid}"]`);
      return card ? JSON.parse(card.dataset.caseJson) : null;
    }, caseId);
    check('case card data captured for update fulfillment', !!state.caseData);

    // ══ 1. Happy path: small file uploads, update carries gcs_files ══
    await openCaseEdit(page, caseId);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'smile-photo.png', mimeType: 'image/png', buffer: PNG_BYTES });
    await page.click('#createCaseSubmit');
    await page.waitForResponse(r => r.url().includes('update-case.php'), { timeout: 15000 });
    await page.waitForSelector('#createCaseModal', { state: 'hidden', timeout: 15000 }).catch(() => {});
    check('happy: signed URL requested', state.signedUrlRequests.length === 1, state.signedUrlRequests);
    check('happy: GCS PUT executed', state.putRequests.length === 1, state.putRequests);
    const gcsPayload = state.updatePosts[0] || '';
    check('happy: update payload carries gcs_files metadata', /gcs_files/.test(gcsPayload) && /storage_path/.test(gcsPayload), gcsPayload.slice(0, 200));
    check('happy: no failure report posted', state.reports.length === 0, state.reports);

    // ══ 2. Signed-URL 401 -> session-expired message, not "internet" ══
    state.signedUrlMode = '401';
    await openCaseEdit(page, caseId);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'sess-photo.png', mimeType: 'image/png', buffer: PNG_BYTES });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    let toast = await toastText(page);
    check('401: toast mentions session expiry', /session/i.test(toast), toast);
    check('401: toast does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    await waitSubmitSettled(page);
    check('401: report posted with session_expired class',
      state.reports.some(r => r.error_class === 'session_expired' && r.stage === 'signed-url'),
      state.reports);

    // ══ 3. Signed-URL 403 -> access message (server-provided) ══
    state.signedUrlMode = '403';
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'denied-photo.png', mimeType: 'image/png', buffer: PNG_BYTES });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    toast = await toastText(page);
    check('403: toast shows access/denied reason', /access|denied|assigned/i.test(toast), toast);
    check('403: toast does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    await waitSubmitSettled(page);
    check('403: report posted with access_denied class',
      state.reports.some(r => r.error_class === 'access_denied'),
      state.reports);

    // ══ 4. Signed-URL 500 -> retryable server-error message ══
    state.signedUrlMode = '500';
    state.signedUrlRequests = [];
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'serverfail.stl', mimeType: 'application/sla', buffer: STL_BYTES });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    // 1 attempt + 2 retries (3s + 10s backoff) -> allow generous wait
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 30000 });
    toast = await toastText(page);
    check('500: toast shows server-error reason, not connection blame',
      /error|status|try again/i.test(toast) && !/internet connection/i.test(toast), toast);
    check('500: signed URL re-requested on retry (fresh URL)',
      state.signedUrlRequests.filter(r => r.filename === 'serverfail.stl').length === 3,
      state.signedUrlRequests.length);
    await waitSubmitSettled(page, 30000);
    check('500: report posted with server_error class + request_id',
      state.reports.some(r => r.error_class === 'server_error' && r.request_id),
      state.reports.slice(-3));

    // ══ 5. Signed-URL 400 with error_code -> localized server message ══
    state.signedUrlMode = '400type';
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'badext.stl', mimeType: 'application/sla', buffer: STL_BYTES });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    toast = await toastText(page);
    check('400-type: toast maps error_code to localized text', /can'?t be uploaded|type/i.test(toast), toast);
    check('400-type: toast does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    await waitSubmitSettled(page);
    check('400-type: report carries server request_id for correlation',
      state.reports.some(r => r.http_status === 400 && r.request_id && /^[a-f0-9]{8,32}$/.test(r.request_id)),
      state.reports.slice(-2));

    // ══ 6. Client-side unsupported extension -> never reaches server ══
    state.signedUrlMode = 'ok';
    const signedBefore = state.signedUrlRequests.length;
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="photos"]',
      { name: 'payload.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('MZ') });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    toast = await toastText(page);
    check('exe: unsupported type rejected client-side', /unsupported file type/i.test(toast), toast);
    check('exe: no signed-URL request made', state.signedUrlRequests.length === signedBefore,
      state.signedUrlRequests.length);
    check('exe: client-validation report posted',
      state.reports.some(r => r.stage === 'client-validation' && r.error_class === 'unsupported_type' && r.ext === 'exe'),
      state.reports.slice(-2));
    await waitSubmitSettled(page);

    // ══ 7. PUT 403 (expired signed URL) -> retried with fresh URL ══
    state.signedUrlMode = 'ok';
    state.putMode = 'fail403once';
    state.put403Count = 0;
    state.signedUrlRequests = [];
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="intraoralScans"]',
      { name: 'retry-scan.stl', mimeType: 'application/sla', buffer: STL_BYTES });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForResponse(r => r.url().includes('update-case.php'), { timeout: 20000 });
    check('put403: retry requested a FRESH signed URL',
      state.signedUrlRequests.filter(r => r.filename === 'retry-scan.stl').length === 2,
      state.signedUrlRequests);
    check('put403: PUT attempted twice then succeeded',
      state.putRequests.filter(p => /retry-scan\.stl/.test(p)).length === 2,
      state.putRequests);
    await page.waitForSelector('#createCaseModal', { state: 'hidden', timeout: 15000 }).catch(() => {});
    state.putMode = 'ok';

    // ══ 7b. ASCII STL misdetected as text/plain -> normalized, uploads ══
    state.signedUrlMode = 'ok';
    const stlReqStart = state.signedUrlRequests.length;
    await openCaseEdit(page, caseId);
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="intraoralScans"]', [
      { name: 'ascii-scan.stl', mimeType: 'text/plain', buffer: ASCII_STL },
      { name: 'binary-scan.stl', mimeType: 'application/octet-stream', buffer: STL_BYTES },
      { name: 'freedesktop-scan.stl', mimeType: 'model/x.stl-ascii', buffer: ASCII_STL },
    ]);
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    const stlUpdate = await page.waitForResponse(r => r.url().includes('update-case.php'), { timeout: 20000 })
      .catch(() => null);
    await page.waitForSelector('#createCaseModal', { state: 'hidden', timeout: 15000 }).catch(() => {});
    check('stl: update-case submitted (all STLs uploaded)', !!stlUpdate, state.putRequests.slice(-6));
    // Browser detection artifact must be normalized BEFORE the signed-URL
    // request - the server never sees text/plain for an .stl from this client.
    const stlReqs = state.signedUrlRequests.slice(stlReqStart);
    check('stl: ascii-scan sent as octet-stream, not text/plain',
      stlReqs.some(r => r.filename === 'ascii-scan.stl' && r.content_type === 'application/octet-stream'),
      stlReqs);
    check('stl: binary-scan unchanged (octet-stream)',
      stlReqs.some(r => r.filename === 'binary-scan.stl' && r.content_type === 'application/octet-stream'),
      stlReqs);
    check('stl: freedesktop model/x.stl-ascii normalized to octet-stream',
      stlReqs.some(r => r.filename === 'freedesktop-scan.stl' && r.content_type === 'application/octet-stream'),
      stlReqs);
    check('stl: all three PUT to GCS',
      state.putRequests.filter(p => /ascii-scan\.stl|binary-scan\.stl|freedesktop-scan\.stl/.test(p)).length === 3,
      state.putRequests.slice(-6));
    // The PUT Content-Type must equal the value the URL was signed for
    // (the echoed response field) or V4 rejects with SignatureDoesNotMatch.
    const stlPuts = state.putHeaders.filter(h => /ascii-scan\.stl|binary-scan\.stl|freedesktop-scan\.stl/.test(h.path));
    check('stl: PUT content-type matches the signed type for every scan',
      stlPuts.length === 3 && stlPuts.every(h => h.contentType === 'application/octet-stream'),
      stlPuts);
    const stlPost = state.updatePosts.find(p => /ascii-scan\.stl/.test(p)) || '';
    check('stl: metadata carried both files', /ascii-scan\.stl/.test(stlPost) && /binary-scan\.stl/.test(stlPost),
      stlPost.slice(0, 200));

    // ══ 7c. Zero-byte file -> client-side empty_file, never hits server ══
    const emptyReqBefore = state.signedUrlRequests.length;
    await openCaseEdit(page, caseId);
    await clearStagedFiles(page);
    await page.setInputFiles('.attachment-input[data-type="intraoralScans"]',
      { name: 'empty-scan.stl', mimeType: 'application/octet-stream', buffer: Buffer.alloc(0) });
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    toast = await toastText(page);
    check('empty: toast names the unreadable/empty file', /empty-scan\.stl/.test(toast) && /empty|read|re-select/i.test(toast), toast);
    check('empty: toast does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    check('empty: no signed-URL request made', state.signedUrlRequests.length === emptyReqBefore,
      state.signedUrlRequests.length);
    check('empty: client-validation report posted with empty_file class',
      state.reports.some(r => r.stage === 'client-validation' && r.error_class === 'empty_file' && r.ext === 'stl'),
      state.reports.slice(-2));
    await waitSubmitSettled(page);
    // Rejected client-side but still staged - clear so later sections start clean.
    await clearStagedFiles(page);
    await clearToasts(page);

    // ══ 8. Partial success -> retry skips already-uploaded file ══
    state.signedUrlRequests = []; state.putRequests = []; state.reports = [];
    state.putFailFiles = { 'fail-b.stl': 500 };
    await openCaseEdit(page, caseId);
    await page.setInputFiles('.attachment-input[data-type="intraoralScans"]', [
      { name: 'ok-a.stl', mimeType: 'application/sla', buffer: STL_BYTES },
      { name: 'fail-b.stl', mimeType: 'application/sla', buffer: STL_BYTES },
    ]);
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    // First submit: both attempted; b fails permanently after retries
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 40000 });
    toast = await toastText(page);
    check('partial: error names the failing file', /fail-b\.stl/.test(toast), toast);
    check('partial: error does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    await waitSubmitSettled(page, 40000);
    const aAfterFirst = state.putRequests.filter(p => /ok-a\.stl/.test(p)).length;
    check('partial: ok-a uploaded once in first submit', aAfterFirst === 1, aAfterFirst);

    // Resubmit - ok-a must NOT re-upload
    await clearToasts(page);
    await page.click('#createCaseSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 40000 });
    const aAfterRetry = state.putRequests.filter(p => /ok-a\.stl/.test(p)).length;
    check('partial: ok-a NOT re-uploaded on retry', aAfterRetry === 1, aAfterRetry);
    check('partial: fail-b retried (fresh signed URL + PUTs)',
      state.putRequests.filter(p => /fail-b\.stl/.test(p)).length > 3,
      state.putRequests.length);
    await waitSubmitSettled(page, 40000);
    state.putFailFiles = {};

    // ══ 9. Comment flow: upload failure keeps draft + shows reason ══
    await openCaseEdit(page, caseId);
    await page.click('.case-tab[data-tab="comments"]');
    await page.waitForSelector('#caseCommentInput', { state: 'visible', timeout: 10000 });
    await page.setInputFiles('#caseCommentImageInput',
      { name: 'comment-pic.png', mimeType: 'image/png', buffer: PNG_BYTES });
    await page.waitForSelector('.case-comment-preview', { state: 'visible' });
    await page.fill('#caseCommentInput', 'draft that must survive');
    state.signedUrlMode = '500';
    state.reports = [];
    await clearToasts(page);
    await page.click('#caseCommentSubmit');
    await page.waitForFunction(() =>
      Array.from(document.querySelectorAll('#toastContainer .toast')).length > 0,
      null, { timeout: 15000 });
    toast = await toastText(page);
    check('comment: failure shows the real reason', /error|status/i.test(toast), toast);
    check('comment: toast does NOT blame internet connection', !/internet connection/i.test(toast), toast);
    check('comment: draft text preserved after failure',
      (await page.inputValue('#caseCommentInput')) === 'draft that must survive');
    check('comment: staged image preserved after failure',
      await page.locator('.case-comment-preview').count() === 1);
    check('comment: controls re-enabled after failure',
      !(await page.locator('#caseCommentSubmit').isDisabled()));
    check('comment: report carries upload_type=comments',
      state.reports.some(r => r.upload_type === 'comments'),
      state.reports);

    // ══ 10. Report payloads are sanitized ══
    const badReport = state.reports.find(r =>
      typeof r.filename !== 'undefined' || typeof r.message !== 'undefined' ||
      (r.request_id && !/^[a-f0-9]{8,32}$/.test(r.request_id)));
    check('reports: no filename/message fields, request_id hex-only', !badReport, badReport);

    const jsErrors = consoleErrors.filter(e => !/favicon|net::|Failed to load resource/i.test(e));
    check('no unexpected page errors', jsErrors.length === 0, jsErrors.slice(0, 5));

  } finally {
    // Cleanup fixtures
    try {
      const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
      for (const id of created) {
        await page.evaluate(async ({ id, csrf }) => {
          const r = await fetch('api/delete-case.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ case_id: id }),
          });
          await r.json().catch(() => ({}));
        }, { id, csrf });
      }
    } catch (e) { /* best effort */ }
    await browser.close();
  }

  console.log(`\n=== Results: ${checks} checks, ${failures.length} failures ===`);
  if (failures.length) { console.log('FAILURES:', failures.join(' | ')); process.exit(1); }
})();
