/**
 * Case List View end-to-end verification.
 *
 * Covers: Board/List toggle, row rendering from the existing card dataset,
 * column sorting, expand/collapse (without opening the modal), case opening
 * via the shared openCaseById flow, filter/search integration, view
 * persistence across reload, and the mobile stacked-row treatment.
 *
 * Run: node tests/case-list-view-e2e-test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const SCREEN_DIR = path.join(__dirname, '..', 'screenshots');
const TEST_MARKER = 'DentaTrakTest-List';

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
    patientLastName: 'Alpha',
    patientDOB: '1990-01-01',
    patientGender: 'Female',
    dentistName: 'Dr. ListTest',
    caseType: 'Veneer',
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

async function deleteTestCases(page, ids) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  await page.evaluate(async ({ url, body }) => {
    await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }, { url: `${BASE}/api/test-helpers.php`, body: { action: 'delete_test_cases', case_ids: ids, csrf_token: csrf } });
}

async function listTestCaseIds(page) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/test-helpers.php`, body: { action: 'list_test_cases', csrf_token: csrf } });
  return res.success ? res.case_ids : [];
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

  try {
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

    // Clean up leftover test cases, then seed two list-view cases.
    const preExisting = await listTestCaseIds(page);
    if (preExisting.length > 0) await deleteTestCases(page, preExisting);

    const a = await createCase(page, { patientLastName: 'Alpha', dueDate: '2026-12-15' });
    const aId = a.id || a.caseId || a.case_id;
    seededIds.push(aId);
    const b = await createCase(page, { patientLastName: 'Zulu', dueDate: '2027-01-10', status: 'Designed' });
    const bId = b.id || b.caseId || b.case_id;
    seededIds.push(bId);

    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });
    try {
      await page.waitForSelector('.kanban-card', { timeout: 15000 });
    } catch (e) {
      const diag = await page.evaluate(() => {
        const card = document.querySelector('.kanban-card');
        const board = document.getElementById('kanbanBoard');
        return {
          bodyClass: document.body.className,
          cardCount: document.querySelectorAll('.kanban-card').length,
          cardRect: card ? card.getBoundingClientRect() : null,
          boardDisplay: board ? getComputedStyle(board).display : null,
          boardOpacity: board ? getComputedStyle(board).opacity : null,
          tabClass: document.getElementById('cases-tab') ? document.getElementById('cases-tab').className : null,
        };
      });
      console.log('DIAG:', JSON.stringify(diag));
      throw e;
    }

    /* ---------- 1. Board is the default view ---------- */
    const defaultState = await page.evaluate(() => ({
      listClass: document.body.classList.contains('case-view-list'),
      boardVisible: getComputedStyle(document.getElementById('kanbanBoard')).display !== 'none',
      listHidden: document.getElementById('caseListView').hidden,
      boardBtnActive: document.getElementById('boardViewToggle').classList.contains('active'),
    }));
    if (defaultState.listClass) failures.push('default view should be Board');
    if (!defaultState.boardVisible) failures.push('kanban board should be visible by default');
    if (!defaultState.listHidden) failures.push('list view should be hidden by default');
    if (!defaultState.boardBtnActive) failures.push('Board toggle should be active by default');

    /* ---------- 2. Switch to List ---------- */
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row', { timeout: 10000 });

    const listState = await page.evaluate(() => {
      const rows = document.querySelectorAll('.case-list-row');
      const cards = document.querySelectorAll('.kanban-card');
      const headers = Array.from(document.querySelectorAll('.case-list-table thead th'))
        .map(th => th.textContent.trim().replace(/[▲▼]/g, '').trim());
      return {
        listClass: document.body.classList.contains('case-view-list'),
        boardVisible: getComputedStyle(document.getElementById('kanbanBoard')).display !== 'none',
        rowCount: rows.length,
        cardCount: cards.length,
        headers,
        reviewTrackingOn: !document.body.classList.contains('case-review-tracking-off'),
        docOverflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });

    if (!listState.listClass) failures.push('body.case-view-list missing after List toggle');
    if (listState.boardVisible) failures.push('kanban board should be hidden in List view');
    if (listState.rowCount !== listState.cardCount) {
      failures.push(`row count ${listState.rowCount} != card count ${listState.cardCount}`);
    }
    ['Patient', 'Type', 'Status', 'Assigned To', 'Due Date', 'Appointment', 'Dentist', 'Updated'].forEach(h => {
      if (!listState.headers.includes(h)) failures.push('missing column header: ' + h);
    });
    const hasReviewCol = listState.headers.includes('Review Status');
    if (hasReviewCol !== listState.reviewTrackingOn) {
      failures.push('Review column presence does not match tracking flag');
    }
    if (listState.docOverflowX > 0) failures.push('desktop horizontal overflow: ' + listState.docOverflowX);
    await page.screenshot({ path: path.join(SCREEN_DIR, 'case-list-view-desktop.png') });

    /* ---------- 3. Sorting ---------- */
    const patientOrder = () => page.evaluate(() =>
      Array.from(document.querySelectorAll('.case-list-row .case-list-open')).map(b => b.textContent.trim()));

    const before = await patientOrder();
    await page.click('.cl-sort-btn[data-sort-key="patient"]');
    const ascOrder = await patientOrder();
    const sortedAsc = before.slice().sort((x, y) => x.toLowerCase().localeCompare(y.toLowerCase()));
    // patient sorts by last name: last word of display text
    const ascLastNames = ascOrder.map(n => n.split(' ').pop().toLowerCase());
    const expectedAsc = before.map(n => n.split(' ').pop().toLowerCase()).sort();
    if (JSON.stringify(ascLastNames) !== JSON.stringify(expectedAsc)) {
      failures.push('patient ascending sort mismatch: ' + JSON.stringify(ascLastNames));
    }
    const ariaSort = await page.$eval('.cl-sort-btn[data-sort-key="patient"]', b => b.closest('th').getAttribute('aria-sort'));
    if (ariaSort !== 'ascending') failures.push('aria-sort should be ascending after first click, got: ' + ariaSort);
    await page.click('.cl-sort-btn[data-sort-key="patient"]');
    const descAria = await page.$eval('.cl-sort-btn[data-sort-key="patient"]', b => b.closest('th').getAttribute('aria-sort'));
    if (descAria !== 'descending') failures.push('aria-sort should be descending after second click, got: ' + descAria);
    if (sortedAsc.length === 0) failures.push('no patient names rendered');

    /* ---------- 4. Expand / collapse ---------- */
    const expandSel = `.case-list-row[data-case-id="${aId}"] .case-list-expand`;
    await page.click(expandSel);
    await page.waitForSelector(`.case-list-detail-row[data-case-id="${aId}"]`, { timeout: 5000 });
    const detail = await page.evaluate((id) => {
      const row = document.querySelector(`.case-list-detail-row[data-case-id="${id}"]`);
      const btn = document.querySelector(`.case-list-row[data-case-id="${id}"] .case-list-expand`);
      const modal = document.getElementById('createCaseModal');
      return {
        detailText: row ? row.textContent : '',
        ariaExpanded: btn ? btn.getAttribute('aria-expanded') : null,
        modalOpen: modal ? getComputedStyle(modal).display === 'block' : false,
      };
    }, aId);
    if (!detail.detailText.includes('Created')) failures.push('expanded detail missing Created field');
    if (detail.ariaExpanded !== 'true') failures.push('chevron aria-expanded not true');
    if (detail.modalOpen) failures.push('expanding a row must not open the case modal');
    await page.screenshot({ path: path.join(SCREEN_DIR, 'case-list-view-expanded.png') });
    // Collapse
    await page.click(expandSel);
    const collapsed = await page.$(`.case-list-detail-row[data-case-id="${aId}"]`);
    if (collapsed) failures.push('detail row still present after collapse');

    /* ---------- 5. Row opens the shared case modal ---------- */
    await page.click(`.case-list-row[data-case-id="${bId}"] .case-list-open`);
    await page.waitForFunction(() => {
      const m = document.getElementById('createCaseModal');
      return m && getComputedStyle(m).display === 'block';
    }, { timeout: 10000 });
    // The modal shell opens instantly; get-case.php populates the fields async.
    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, { timeout: 10000 });
    const modalTitle = await page.evaluate(() => {
      const f = document.getElementById('patientFirstName');
      return f ? f.value : '';
    });
    if (!modalTitle.includes(TEST_MARKER)) failures.push('modal opened for wrong case: ' + modalTitle);
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => {
      const m = document.getElementById('createCaseModal');
      return !m || getComputedStyle(m).display !== 'block';
    }, { timeout: 5000 }).catch(() => {});

    /* ---------- 6. Search filters the list ---------- */
    // The filters bar is collapsed until the Filters button opens it.
    await page.click('#kanbanFilterToggle');
    await page.waitForSelector('#kanbanFiltersBar.filters-open', { timeout: 5000 });
    await page.fill('#patientSearch', 'Zulu');
    await page.waitForTimeout(700); // debounced filter fetch
    await page.waitForFunction(() => {
      const rows = document.querySelectorAll('.case-list-row');
      return rows.length === 1 && rows[0].getAttribute('data-case-id');
    }, { timeout: 10000 });
    const filteredId = await page.$eval('.case-list-row', r => r.getAttribute('data-case-id'));
    if (filteredId !== bId) failures.push('search filter did not isolate Zulu case, got: ' + filteredId);
    await page.fill('#patientSearch', '');
    await page.waitForTimeout(700);

    /* ---------- 7. View persists across reload ---------- */
    await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });
    // List view persists -> the board is hidden; wait for list rows instead.
    await page.waitForSelector('.case-list-row', { timeout: 15000 });
    await page.waitForTimeout(500);
    const persisted = await page.evaluate(() => ({
      listClass: document.body.classList.contains('case-view-list'),
      listVisible: !document.getElementById('caseListView').hidden,
      rowCount: document.querySelectorAll('.case-list-row').length,
    }));
    if (!persisted.listClass || !persisted.listVisible) failures.push('List view did not persist across reload');
    if (persisted.rowCount === 0) failures.push('List view rendered no rows after reload');

    /* ---------- 8. Switch back to Board ---------- */
    await page.click('#boardViewToggle');
    const backToBoard = await page.evaluate(() => ({
      listClass: document.body.classList.contains('case-view-list'),
      boardVisible: getComputedStyle(document.getElementById('kanbanBoard')).display !== 'none',
      listHidden: document.getElementById('caseListView').hidden,
    }));
    if (backToBoard.listClass) failures.push('case-view-list still set after switching to Board');
    if (!backToBoard.boardVisible) failures.push('kanban board not visible after switching back');
    if (!backToBoard.listHidden) failures.push('list view not hidden after switching back');

    /* ---------- 9. Mobile stacked rows ---------- */
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row', { timeout: 10000 });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForTimeout(400);
    const mobile = await page.evaluate(() => {
      const row = document.querySelector('.case-list-row');
      const thead = document.querySelector('.case-list-table thead');
      const nav = document.getElementById('mobileKanbanNav');
      return {
        rowDisplay: row ? getComputedStyle(row).display : null,
        theadVisible: thead ? getComputedStyle(thead).display !== 'none' : null,
        navVisible: nav ? getComputedStyle(nav).display !== 'none' : null,
        docOverflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    if (mobile.rowDisplay !== 'flex') failures.push('mobile rows should stack (display:flex), got: ' + mobile.rowDisplay);
    if (mobile.theadVisible !== false) failures.push('mobile should hide the table header');
    if (mobile.navVisible) failures.push('mobile kanban nav should be hidden in List view');
    if (mobile.docOverflowX > 0) failures.push('mobile horizontal overflow: ' + mobile.docOverflowX);
    await page.screenshot({ path: path.join(SCREEN_DIR, 'case-list-view-mobile.png') });

  } catch (e) {
    failures.push('exception: ' + e.message);
  } finally {
    await deleteTestCases(page, seededIds).catch(() => {});
    await browser.close();
  }

  if (consoleErrors.length) {
    console.log('console errors:', consoleErrors.slice(0, 10));
  }
  failures.forEach(f => console.log('FAIL: ' + f));
  console.log(failures.length === 0 ? 'ALL CHECKS PASSED' : failures.length + ' failure(s)');
  process.exit(failures.length ? 1 : 0);
}

run().catch(e => { console.error(e); process.exit(1); });
