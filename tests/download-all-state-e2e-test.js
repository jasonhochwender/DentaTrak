'use strict';
// Download All button state regression checks:
//  - visible only while the open case actually has eligible attachments
//  - reset on case switch in both directions (active <-> archived)
//  - stale async responses (heavy load / archived fetch) cannot repaint state
//  - click always targets the currently open case id
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { randomUUID } = require('node:crypto');

const BASE = 'http://localhost/DentaTrak';
const suffix = randomUUID().slice(0, 8);
const email = `e2e-dlall-${suffix}@example.test`;
const password = 'LocalTest-' + randomUUID() + '!';
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; console.log('PASS ' + name); }

const dlDisplay = `getComputedStyle(document.getElementById('attachmentDownloadAll')).display`;
const dlHidden = `${dlDisplay} === 'none'`;
const dlVisible = `${dlDisplay} === 'flex'`;
const openCaseId = `document.getElementById('createCaseForm').dataset.caseId`;
const heavySettled = `(() => { const el = document.getElementById('attachmentsLoadStatus'); return el && (el.style.display === 'none' || el.classList.contains('is-error')); })()`;

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  async function helper(data) {
    const r = await ctx.request.post(BASE + '/api/test-helpers.php', { data });
    const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d)); return d;
  }
  await helper({ action: 'setup_test_user', email, password, firstName: 'DL', lastName: 'All', practiceName: 'DL Test Practice' });
  await ctx.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email, password } });

  const mainResp = await ctx.request.get(BASE + '/main.php');
  const csrf = (await mainResp.text()).match(/name="csrf-token" content="([^"]+)"/)[1];
  const hdrs = { 'X-CSRF-Token': csrf };
  await ctx.request.post(BASE + '/api/accept-terms.php', {
    headers: { ...hdrs, 'Content-Type': 'application/json' }, data: { accepted: true, terms_version: '2026-09-01' }
  });

  const base = {
    patientFirstName: 'DentaTrakTest', patientLastName: 'DLAll', patientDOB: '1990-01-01',
    patientGender: 'female', dentistName: 'Dr. Mike', caseType: 'Denture', dueDate: '2026-12-01'
  };
  async function createCase(last) {
    const fd = new URLSearchParams({ ...base, patientLastName: last });
    const r = await ctx.request.post(BASE + '/api/create-case.php', {
      headers: { ...hdrs, 'Content-Type': 'application/x-www-form-urlencoded' }, data: fd.toString()
    });
    const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d)); return d.caseData.id;
  }
  async function archiveCase(id) {
    const r = await ctx.request.post(BASE + '/api/delete-case.php', {
      headers: { ...hdrs, 'Content-Type': 'application/json' }, data: { caseId: id }
    });
    const d = await r.json(); assert.equal(d.success, true, JSON.stringify(d));
  }
  const twoFiles = [
    { fileName: 'scan1.stl', storageType: 'gcs', storagePath: 'fixtures/scan1.stl', size: 1000, type: 'IntraoralScans' },
    { fileName: 'scan2.stl', storageType: 'gcs', storagePath: 'fixtures/scan2.stl', size: 2000, type: 'IntraoralScans' }
  ];

  const caseA = await createCase('WithFiles');          // active, 2 gcs attachments
  await helper({ action: 'set_case_attachments', case_id: caseA, attachments: twoFiles });
  const caseB = await createCase('ArchNoFiles');        // archived, no attachments
  await archiveCase(caseB);
  const caseB2 = await createCase('ArchTwo');           // second archived for race test
  await archiveCase(caseB2);
  const caseC = await createCase('ActiveNoFiles');      // active, no attachments
  const caseD = await createCase('ArchWithFiles');      // archived WITH attachments
  await helper({ action: 'set_case_attachments', case_id: caseD, attachments: twoFiles });
  await archiveCase(caseD);

  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  if (page.url().includes('accept-terms.php')) {
    await page.check('#termsAccepted'); await page.click('#acceptBtn');
    await page.waitForURL('**/main.php');
  }
  await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });

  async function openById(id) {
    await page.evaluate(cid => window.openCaseById(cid), id);
    await page.waitForFunction(cid => String(document.getElementById('createCaseForm').dataset.caseId) === String(cid), id, { timeout: 15000 });
    await page.waitForFunction(heavySettled, { timeout: 15000 });
  }
  async function openArchived(id) {
    await page.evaluate(cid => window.viewArchivedCase(cid), id);
    await page.waitForFunction(cid => String(document.getElementById('createCaseForm').dataset.caseId) === String(cid), id, { timeout: 15000 });
  }
  async function closeCase() {
    await page.click('#createCaseCancel');
    await page.waitForFunction(`document.getElementById('createCaseModal').style.display === 'none'`, { timeout: 5000 });
  }

  // 1. Active case with attachments -> visible
  await openById(caseA);
  await page.waitForFunction(dlVisible, { timeout: 10000 });
  check('active case with attachments: Download All visible', true);
  const rowCount = await page.locator('#createCaseForm .existing-file').count();
  check('attachment rows rendered', rowCount >= 2);

  // 2. Close -> archived case without attachments -> hidden (the reported bug)
  await closeCase();
  await openArchived(caseB);
  check('archived case without attachments: Download All hidden', await page.evaluate(dlHidden));
  check('no stale attachment rows from previous case', (await page.locator('#createCaseForm .existing-file').count()) === 0);

  // 3. Reverse direction: active no-attachments -> hidden, then back to A -> visible
  await closeCase();
  await openById(caseC);
  check('active case with no attachments: Download All hidden', await page.evaluate(dlHidden));
  await closeCase();
  await openById(caseA);
  await page.waitForFunction(dlVisible, { timeout: 10000 });
  check('switch back to attachments case: visible again', true);

  // 4. Archived case WITH attachments -> visible (attachments are still downloadable)
  await closeCase();
  await openArchived(caseD);
  await page.waitForFunction(dlVisible, { timeout: 10000 });
  check('archived case WITH attachments: Download All visible', true);
  check('archived case attachment rows rendered', (await page.locator('#createCaseForm .existing-file').count()) >= 2);

  // 5. Click targets the currently open case id
  let preflightCaseId = null;
  await page.route('**/api/preflight-download-case-attachments-zip.php', route => {
    try { preflightCaseId = JSON.parse(route.request().postData() || '{}').case_id || null; } catch (e) {}
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'stop' }) });
  });
  await page.click('#downloadAllAttachmentsBtn');
  await page.waitForFunction(`document.getElementById('downloadAllAttachmentsStatus').textContent.length > 0`, { timeout: 8000 });
  check('Download All request targets open case id', String(preflightCaseId) === String(caseD));
  await page.unroute('**/api/preflight-download-case-attachments-zip.php');
  await closeCase();

  // 6. Race: open case A (with files), delay its heavy response, switch to
  //    archived case B before it lands -> A's late response must be discarded.
  let delayedOnce = false;
  await page.route('**/api/get-case.php*view=heavy*', async route => {
    if (!delayedOnce) { delayedOnce = true; await new Promise(r => setTimeout(r, 1500)); }
    route.continue();
  });
  await page.evaluate(cid => { window.openCaseById(cid); }, caseA);
  await page.waitForFunction(cid => String(document.getElementById('createCaseForm').dataset.caseId) === String(cid), caseA, { timeout: 15000 });
  await page.evaluate(cid => { window.viewArchivedCase(cid); }, caseB);
  await page.waitForFunction(cid => String(document.getElementById('createCaseForm').dataset.caseId) === String(cid), caseB, { timeout: 15000 });
  await page.waitForTimeout(2000); // let A's delayed heavy response land
  check('late heavy response for previous case discarded (still hidden)', await page.evaluate(dlHidden));
  check('modal still shows case B', (await page.evaluate(openCaseId)) === String(caseB));
  await page.unroute('**/api/get-case.php*view=heavy*');
  await closeCase();

  // 7. Race: two rapid archived-case opens -> last one wins
  await page.route(`**/api/get-case.php?id=${caseB}`, async route => {
    if (!route.request().url().includes('view=')) {
      await new Promise(r => setTimeout(r, 1500));
    }
    route.continue();
  });
  await page.evaluate(cid => { window.viewArchivedCase(cid); }, caseB);
  await page.evaluate(cid => { window.viewArchivedCase(cid); }, caseB2);
  await page.waitForFunction(cid => String(document.getElementById('createCaseForm').dataset.caseId) === String(cid), caseB2, { timeout: 15000 });
  await page.waitForTimeout(2000); // let B's delayed response land
  check('delayed response for superseded archived open discarded', (await page.evaluate(openCaseId)) === String(caseB2));
  await page.unroute(`**/api/get-case.php?id=${caseB}`);
  await closeCase();

  // 8. Add/remove attachments updates visibility on next open
  await helper({ action: 'set_case_attachments', case_id: caseC, attachments: twoFiles });
  await openById(caseC);
  await page.waitForFunction(dlVisible, { timeout: 10000 });
  check('attachments added: Download All becomes visible', true);
  await closeCase();
  await helper({ action: 'set_case_attachments', case_id: caseC, attachments: twoFiles.slice(0, 1) });
  await openById(caseC);
  check('attachments removed below 2: Download All hidden', await page.evaluate(dlHidden));
  await closeCase();

  // cleanup
  await helper({ action: 'delete_test_cases', case_ids: [caseA, caseB, caseB2, caseC, caseD] });
  await helper({ action: 'cleanup_test_user', email });

  await browser.close();
  console.log(`\n${checks} passed, 0 failed`);
})().catch(async e => { console.error('FAIL', e); process.exit(1); });
