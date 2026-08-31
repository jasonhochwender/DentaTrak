/**
 * Phase 2 mobile kanban verification.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const SCREEN_DIR = path.join(__dirname, '..', 'screenshots');
const TEST_MARKER = 'DentaTrakTest';

const STATUSES = [
  'Originated',
  'Sent To External Lab',
  'Designed',
  'Manufactured',
  'Received From External Lab',
  'Delivered'
];

const VIEWPORTS = [
  { width: 320, height: 568, name: 'iPhone SE' },
  { width: 360, height: 780, name: 'iPhone 12/13 mini' },
  { width: 390, height: 844, name: 'iPhone 14 Pro' },
  { width: 412, height: 915, name: 'Pixel 7' },
  { width: 480, height: 932, name: 'large phone' },
];

async function login(context) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email: EMAIL, password: PASSWORD },
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json();
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
}

async function createCase(page, status) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const body = new URLSearchParams({
    patientFirstName: TEST_MARKER + '-Test',
    patientLastName: 'Patient ' + status.replace(/\s+/g, ''),
    patientDOB: '1990-01-01',
    patientGender: 'Male',
    dentistName: 'Dr. Test',
    caseType: 'Bite Rim',
    dueDate: '2026-09-06',
    status: status,
    notes: TEST_MARKER + ' mobile kanban test case',
    assignedTo: EMAIL,
    csrf_token: csrf,
  }).toString();
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
    });
    return r.json();
  }, {
    url: `${BASE}/api/create-case.php`,
    body: body,
  });
  return res;
}

async function archiveCase(page, id) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  await page.evaluate(async ({ url, body }) => {
    await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }, {
    url: `${BASE}/api/delete-case.php`,
    body: { caseId: id, csrf_token: csrf },
  });
}

async function deleteTestCases(page, ids) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  }, { url: `${BASE}/api/test-helpers.php`, body: { action: 'delete_test_cases', case_ids: ids, csrf_token: csrf } });
  console.log('deleteTestCases', res);
  return res;
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
  console.log('listTestCases', res);
  return res.success ? res.case_ids : [];
}

async function getCaseExists(page, id) {
  const res = await page.evaluate(async ({ url }) => {
    const r = await fetch(url, { credentials: 'same-origin' });
    return r.json();
  }, { url: `${BASE}/api/get-case.php?id=${encodeURIComponent(id)}` });
  return res.success === true;
}

async function seed(page) {
  const created = [];
  for (const status of STATUSES) {
    const res = await createCase(page, status);
    if (res.success && res.caseData && res.caseData.id) {
      created.push(res.caseData.id);
    } else if (res.case && res.case.id) {
      created.push(res.case.id);
    } else if (res.caseData && res.caseData.caseId) {
      created.push(res.caseData.caseId);
    } else if (res.caseId) {
      created.push(res.caseId);
    } else {
      console.log('create-case response for', status, JSON.stringify(res).slice(0, 200));
    }
  }
  // Create a few extra Originated cases so the first column's body overflows,
  // enabling vertical-scroll verification.
  for (let i = 0; i < 4; i++) {
    const res = await createCase(page, 'Originated');
    if (res.success && res.caseData && res.caseData.id) {
      created.push(res.caseData.id);
    } else if (res.case && res.case.id) {
      created.push(res.case.id);
    } else if (res.caseData && res.caseData.caseId) {
      created.push(res.caseData.caseId);
    } else if (res.caseId) {
      created.push(res.caseId);
    }
  }
  return created;
}

async function dispatchTouch(page, points) {
  const cdp = await page.context().newCDPSession(page);
  for (let i = 0; i < points.length; i++) {
    const type = i === 0 ? 'touchStart' : (i === points.length - 1 ? 'touchEnd' : 'touchMove');
    const touchPoints = i === points.length - 1 ? [] : [{ x: Math.round(points[i].x), y: Math.round(points[i].y) }];
    await cdp.send('Input.dispatchTouchEvent', { type, touchPoints });
    if (i > 0 && i < points.length - 1) {
      await page.waitForTimeout(16);
    }
  }
}

function getSwipePoints(start, end, steps) {
  const points = [];
  for (let i = 0; i <= steps; i++) {
    points.push({
      x: start.x + (end.x - start.x) * (i / steps),
      y: start.y + (end.y - start.y) * (i / steps),
    });
  }
  return points;
}

async function performHorizontalSwipe(page, direction) {
  const geometry = await page.evaluate(() => {
    const board = document.getElementById('kanbanBoard');
    const boardRect = board ? board.getBoundingClientRect() : null;
    const activeIdx = window.MobileKanban ? window.MobileKanban.getActiveIndex() : 0;
    const columns = board ? Array.from(board.querySelectorAll('.kanban-column')) : [];
    const col = columns[activeIdx] || columns[0];
    const card = col ? col.querySelector('.kanban-card') : null;
    const cardRect = card ? card.getBoundingClientRect() : null;
    return {
      boardRect: boardRect ? { x: boardRect.x, y: boardRect.y, width: boardRect.width, height: boardRect.height } : null,
      cardRect: cardRect ? { x: cardRect.x, y: cardRect.y, width: cardRect.width, height: cardRect.height } : null,
    };
  });

  if (!geometry.cardRect) throw new Error('No card found to start swipe');

  // Long horizontal drag across the active column's card.
  const rect = geometry.cardRect;
  const startX = direction === 'next' ? rect.x + rect.width * 0.95 : rect.x + rect.width * 0.05;
  const endX = direction === 'next' ? rect.x + rect.width * 0.05 : rect.x + rect.width * 0.95;
  const y = rect.y + rect.height * 0.5;
  const points = getSwipePoints({ x: startX, y }, { x: endX, y }, 18);
  await dispatchTouch(page, points);
  // Wait for snap/momentum.
  await page.waitForTimeout(1200);
}

async function performVerticalSwipe(page) {
  const geometry = await page.evaluate(() => {
    const board = document.getElementById('kanbanBoard');
    const col = board ? board.querySelector('.kanban-column') : null;
    const body = col ? col.querySelector('.kanban-column-body') : null;
    const bodyRect = body ? body.getBoundingClientRect() : null;
    return {
      bodyRect: bodyRect ? { x: bodyRect.x, y: bodyRect.y, width: bodyRect.width, height: bodyRect.height } : null,
    };
  });
  if (!geometry.bodyRect) throw new Error('No column body found for vertical swipe');
  const rect = geometry.bodyRect;
  const x = rect.x + rect.width * 0.5;
  const startY = rect.y + rect.height * 0.45;
  const endY = rect.y + rect.height * 0.05;
  const points = getSwipePoints({ x, y: startY }, { x, y: endY }, 14);
  await dispatchTouch(page, points);
  await page.waitForTimeout(800);
}

async function performPinch(page) {
  const cdp = await page.context().newCDPSession(page);
  const boardRect = await page.evaluate(() => {
    const board = document.getElementById('kanbanBoard');
    const r = board ? board.getBoundingClientRect() : null;
    return r ? { x: r.x + r.width / 2, y: r.y + r.height / 2 } : null;
  });
  if (!boardRect) throw new Error('No board found for pinch');

  await page.evaluate(() => {
    window.__pinchEvents = [];
    const board = document.getElementById('kanbanBoard');
    if (board) {
      ['touchstart', 'touchmove', 'touchend', 'touchcancel'].forEach(type => {
        board.addEventListener(type, function(e) {
          window.__pinchEvents.push({
            type: e.type,
            defaultPrevented: e.defaultPrevented,
            touchCount: e.touches.length,
            target: e.target.className,
          });
        }, { passive: true, once: type === 'touchstart' });
      });
    }
  });

  await cdp.send('Input.synthesizePinchGesture', {
    x: Math.round(boardRect.x),
    y: Math.round(boardRect.y),
    scaleFactor: 1.5,
    relativeSpeed: 1,
  });
  await page.waitForTimeout(1000);
}

function getCaseId(card) {
  const idEl = card.querySelector('[data-case-id]');
  return idEl ? idEl.dataset.caseId : null;
}

async function openFirstCardMenu(page) {
  const opened = await page.evaluate(() => {
    const card = document.querySelector('.kanban-card');
    const toggle = card ? card.querySelector('.kanban-card-mobile-menu-toggle') : null;
    if (toggle) {
      toggle.click();
      return true;
    }
    return false;
  });
  if (!opened) return false;
  await page.waitForTimeout(500);
  return true;
}

async function getMenuState(page) {
  return page.evaluate(() => {
    const m = document.getElementById('kanbanCardMobileMenu');
    const select = m ? m.querySelector('.mobile-card-move-select') : null;
    const archive = m ? m.querySelector('.mobile-card-menu-archive') : null;
    return {
      exists: !!m,
      display: m ? getComputedStyle(m).display : null,
      classList: m ? m.className : '',
      rect: m ? m.getBoundingClientRect() : null,
      selectRect: select ? select.getBoundingClientRect() : null,
      archiveText: archive ? archive.textContent : null,
      archiveDisplay: archive ? getComputedStyle(archive).display : null,
    };
  });
}

async function run() {
  if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await login(context);

  const page = await context.newPage();
  await page.goto(`${BASE}/main.php`);
  await page.waitForLoadState('networkidle');

  const failures = [];
  let seededIds = [];
  let nonTestId = null;

  try {
  // Remove any previously-created test cases from earlier runs in this practice.
  const preExisting = await listTestCaseIds(page);
  if (preExisting.length > 0) {
    await deleteTestCases(page, preExisting);
  }

  // 0. Scoped cleanup: prove the helper rejects unmarked cases.
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const unmarkedBody = new URLSearchParams({
    patientFirstName: 'KeepMe',
    patientLastName: 'Safe',
    patientDOB: '1990-01-01',
    patientGender: 'Male',
    dentistName: 'Dr. Test',
    caseType: 'Veneer',
    dueDate: '2026-09-06',
    status: 'Originated',
    notes: 'Do not delete',
    assignedTo: EMAIL,
    csrf_token: csrf,
  }).toString();
  const unmarkedRes = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    return r.json();
  }, { url: `${BASE}/api/create-case.php`, body: unmarkedBody });
  if (!unmarkedRes.success) failures.push('failed to create unmarked case: ' + JSON.stringify(unmarkedRes));
  nonTestId = (unmarkedRes.caseData || unmarkedRes.case || unmarkedRes).id || (unmarkedRes.caseData || unmarkedRes.case || unmarkedRes).caseId || (unmarkedRes.caseData || unmarkedRes.case || unmarkedRes).case_id;

  const probeCase = await createCase(page, 'Originated');
  const probeId = (probeCase.caseData || probeCase.case || probeCase).id || (probeCase.caseData || probeCase.case || probeCase).caseId || (probeCase.caseData || probeCase.case || probeCase).case_id;

  const scopeRes = await deleteTestCases(page, [nonTestId, probeId, 'not-a-real-id-000']);
  if (!scopeRes.success) failures.push('delete_test_cases failed: ' + JSON.stringify(scopeRes));

  const probeResult = scopeRes.results.find(r => r.case_id === probeId);
  if (!probeResult || !probeResult.deleted) failures.push('test case not deleted: ' + JSON.stringify(probeResult));

  const nonTestResult = scopeRes.results.find(r => r.case_id === nonTestId);
  if (!nonTestResult || nonTestResult.deleted || nonTestResult.reason !== 'not_marked') {
    failures.push('non-test case was deleted or wrong reason: ' + JSON.stringify(nonTestResult));
  }

  const fakeResult = scopeRes.results.find(r => r.case_id === 'not-a-real-id-000');
  if (!fakeResult || fakeResult.deleted || fakeResult.reason !== 'not_found') {
    failures.push('fake id not rejected as not_found: ' + JSON.stringify(fakeResult));
  }

  const nonTestStillExists = await getCaseExists(page, nonTestId);
  if (!nonTestStillExists) failures.push('non-test case was removed by cleanup');

  // Archive the non-test case now that the assertion passed.
  await archiveCase(page, nonTestId);

  const ids = await seed(page);
  seededIds = ids;
  console.log('Seeded case IDs:', ids);

  await page.goto(`${BASE}/main.php`);
  await page.waitForLoadState('networkidle');
  await page.setViewportSize({ width: 412, height: 915 });
  await page.waitForTimeout(500);

  // Functional tests at 412 px.
  // 1. Mobile nav and board.
  const nav = await page.$('#mobileKanbanNav');
  if (!nav) failures.push('mobile-kanban-nav not found');

  const boardMetrics = await page.evaluate(() => {
    const board = document.getElementById('kanbanBoard');
    const cols = Array.from(document.querySelectorAll('.kanban-column'));
    return {
      boardDisplay: board ? getComputedStyle(board).display : null,
      overflowX: board ? getComputedStyle(board).overflowX : null,
      touchAction: board ? getComputedStyle(board).touchAction : null,
      scrollWidth: board ? board.scrollWidth : null,
      clientWidth: board ? board.clientWidth : null,
      columns: cols.map(c => ({ status: c.dataset.status, width: c.getBoundingClientRect().width })),
    };
  });
  console.log('board metrics', boardMetrics);
  if (boardMetrics.boardDisplay !== 'flex') failures.push('kanban-board not display:flex on phone');
  if (boardMetrics.overflowX !== 'auto') failures.push('kanban-board not overflow-x:auto');
  if (!boardMetrics.columns.length) failures.push('no columns rendered');
  if (boardMetrics.columns.length && boardMetrics.columns[0].width < 340) failures.push('first column too narrow: ' + boardMetrics.columns[0].width);
  if (boardMetrics.touchAction === 'none' || boardMetrics.touchAction === 'pan-x') {
    failures.push('board touch-action does not permit vertical or zoom: ' + boardMetrics.touchAction);
  }

  // 2. Genuine horizontal touch swipe between columns.
  if (await page.evaluate(() => typeof window.MobileKanban === 'object')) {
    await performHorizontalSwipe(page, 'next');
    const afterNext = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return {
        scrollLeft: board ? board.scrollLeft : null,
        activeIndex: window.MobileKanban ? window.MobileKanban.getActiveIndex() : null,
        selectorValue: document.getElementById('mobileKanbanSelect') ? document.getElementById('mobileKanbanSelect').value : null,
        prevDisabled: document.getElementById('mobileKanbanPrev') ? document.getElementById('mobileKanbanPrev').disabled : null,
        modalOpen: document.getElementById('createCaseModal') ? document.getElementById('createCaseModal').style.display : null,
      };
    });
    console.log('after next swipe', afterNext);
    if (afterNext.activeIndex !== 1) failures.push('swipe to next column did not land on index 1: ' + JSON.stringify(afterNext));
    if (afterNext.selectorValue !== 'Sent To External Lab') failures.push('selector did not update to Sent To External Lab: ' + afterNext.selectorValue);
    if (afterNext.prevDisabled) failures.push('Previous button should be enabled after swipe to second column');
    if (afterNext.modalOpen === 'block') failures.push('swipe opened the case modal; should not');
    if (afterNext.scrollLeft < 120) failures.push('board did not scroll far enough to change column; scrollLeft=' + afterNext.scrollLeft);

    await performHorizontalSwipe(page, 'prev');
    const afterPrev = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return {
        scrollLeft: board ? board.scrollLeft : null,
        activeIndex: window.MobileKanban ? window.MobileKanban.getActiveIndex() : null,
        selectorValue: document.getElementById('mobileKanbanSelect') ? document.getElementById('mobileKanbanSelect').value : null,
        prevDisabled: document.getElementById('mobileKanbanPrev') ? document.getElementById('mobileKanbanPrev').disabled : null,
      };
    });
    console.log('after prev swipe', afterPrev);
    if (afterPrev.activeIndex !== 0) failures.push('swipe back did not land on index 0: ' + JSON.stringify(afterPrev));
    if (afterPrev.selectorValue !== 'Originated') failures.push('selector did not return to Originated: ' + afterPrev.selectorValue);
    if (!afterPrev.prevDisabled) failures.push('Previous button should be disabled on first column');
    if (afterPrev.scrollLeft > 50) failures.push('board did not return to first column; scrollLeft=' + afterPrev.scrollLeft);

    // Vertical swipe in the first column should scroll the page, not the board.
    const beforeVertical = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return { pageScrollY: window.pageYOffset, boardScrollLeft: board ? board.scrollLeft : null };
    });
    await performVerticalSwipe(page);
    const afterVertical = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return {
        pageScrollY: window.pageYOffset,
        boardScrollLeft: board ? board.scrollLeft : null,
        modalOpen: document.getElementById('createCaseModal') ? document.getElementById('createCaseModal').style.display : null,
      };
    });
    console.log('after vertical swipe', afterVertical);
    if (afterVertical.pageScrollY <= beforeVertical.pageScrollY) failures.push('vertical swipe did not scroll the page: before=' + beforeVertical.pageScrollY + ' after=' + afterVertical.pageScrollY);
    if (afterVertical.modalOpen === 'block') failures.push('vertical swipe opened the case modal; should not');
    if (afterVertical.boardScrollLeft > 120) failures.push('vertical swipe moved the board to a different column; scrollLeft=' + afterVertical.boardScrollLeft);
  } else {
    failures.push('window.MobileKanban not exposed');
  }

  // 3. Menu opens and shows Archive (not Delete).
  await page.evaluate(() => { if (window.MobileKanban) window.MobileKanban.goToColumn(0, false); });
  await page.waitForTimeout(300);
  const firstCardId = await page.evaluate(() => {
    const card = document.querySelector('.kanban-card');
    const idEl = card ? card.querySelector('[data-case-id]') : null;
    return idEl ? idEl.dataset.caseId : null;
  });

  if (!firstCardId) failures.push('first card id not found');

  const menuOpened = await openFirstCardMenu(page);
  if (!menuOpened) {
    failures.push('kanban-card-mobile-menu-toggle not found');
  } else {
    const menuState = await getMenuState(page);
    console.log('menu state', menuState);
    if (!menuState.exists || menuState.display !== 'flex') failures.push('mobile card menu did not open');
    if (menuState.archiveText !== 'Archive') failures.push('menu archive text was: ' + menuState.archiveText);
    if (menuState.archiveDisplay !== 'flex') failures.push('archive action not visible for permitted user');
    if (menuState.selectRect && menuState.rect) {
      if (menuState.selectRect.right > menuState.rect.right + 1) {
        failures.push('status selector right edge (' + menuState.selectRect.right + ') exceeds menu right edge (' + menuState.rect.right + ')');
      }
      if (menuState.selectRect.left < menuState.rect.left - 1) {
        failures.push('status selector left edge (' + menuState.selectRect.left + ') outside menu left edge (' + menuState.rect.left + ')');
      }
    }
    await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-kanban-card-menu-412.png') });

    // 4a. Archive via menu and canonical confirmation.
    if (firstCardId) {
      // Snapshot the column count before archiving so the assertion is
      // relative (not dependent on any left-over fixture state).
      const beforeArchive = await page.evaluate(() => {
        const count = document.querySelector('[data-status="Originated"] .kanban-column-count');
        return count ? parseInt(count.textContent, 10) : null;
      });

      await page.click('.mobile-card-menu-archive');

      // Wait for the existing archive confirmation modal and click its Archive button.
      try {
        await page.waitForSelector('#confirmBtn', { state: 'visible', timeout: 2000 });
        const responsePromise = page.waitForResponse(res => res.url().includes('delete-case.php') && res.status() === 200, { timeout: 10000 });
        await page.click('#confirmBtn');
        const response = await responsePromise;
        const body = await response.text();
        console.log('archive response', body.slice(0, 200));
      } catch (e) {
        // If the user has disabled confirmations, the menu callback archives directly.
      }
      await page.waitForTimeout(600);

      const afterArchive = await page.evaluate((expectedId) => {
        const activeCard = document.querySelector('.kanban-card [data-case-id="' + expectedId + '"]');
        const count = document.querySelector('[data-status="Originated"] .kanban-column-count');
        return { stillOnBoard: !!activeCard, originatedCount: count ? count.textContent : null, cardDisplay: activeCard ? getComputedStyle(activeCard.closest('.kanban-card')).display : null };
      }, firstCardId);
      console.log('after archive', afterArchive, 'before', beforeArchive);
      if (afterArchive.stillOnBoard) failures.push('archived case still on active board');
      if (beforeArchive !== null && parseInt(afterArchive.originatedCount, 10) !== beforeArchive - 1) {
        failures.push('Originated column count did not decrease by 1 after archive: before=' + beforeArchive + ' after=' + afterArchive.originatedCount);
      }

      // Verify the case appears in Archived Cases.
      await page.click('#viewArchivedBtn');
      try {
        await page.waitForResponse(res => res.url().includes('get-archived-cases.php') && res.status() === 200, { timeout: 10000 });
      } catch (e) { /* ignore; waitForFunction is the real check */ }
      await page.waitForFunction((expectedId) => {
        const tbody = document.getElementById('archivedCasesTableBody');
        return tbody ? tbody.innerHTML.includes(expectedId) : false;
      }, firstCardId, { timeout: 10000 }).catch(() => {});
      const archivedCase = await page.evaluate((expectedId) => {
        const tbody = document.getElementById('archivedCasesTableBody');
        return tbody ? tbody.innerHTML.includes(expectedId) : false;
      }, firstCardId);
      console.log('archived case found', archivedCase);
      if (!archivedCase) failures.push('archived case not found in Archived Cases modal');
      await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-kanban-archived-cases-412.png') });

      // Close archived modal if a close control exists.
      const archivedClose = await page.$('#archivedCasesModal .close, #archivedCasesModal .close-modal, #closeArchivedCasesBtn');
      if (archivedClose) await archivedClose.click();
      await page.waitForTimeout(300);
    }

    // 4b. Status move still uses the existing updateCardStatus path.
    await page.goto(`${BASE}/main.php`);
    await page.waitForLoadState('networkidle');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.waitForTimeout(500);

    const secondCardId = await page.evaluate(() => {
      const card = document.querySelector('.kanban-card');
      const idEl = card ? card.querySelector('[data-case-id]') : null;
      return idEl ? idEl.dataset.caseId : null;
    });
    if (secondCardId) {
      const moved = await openFirstCardMenu(page);
      if (moved) {
        const targetStatus = 'Designed';
        const responsePromise = page.waitForResponse(res => res.url().includes('update-case-status.php') && res.status() === 200, { timeout: 10000 });
        await page.evaluate((status) => {
          const select = document.querySelector('.kanban-card-mobile-menu .mobile-card-move-select');
          if (select) {
            select.value = status;
            select.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }, targetStatus);
        await responsePromise;
        await page.waitForTimeout(600);
        const afterMove = await page.evaluate(({ expectedId, status }) => {
          const col = document.querySelector('[data-status="' + status + '"] .kanban-column-body');
          const cards = col ? col.querySelectorAll('.kanban-card') : [];
          const found = Array.from(cards).some(c => c.querySelector('[data-case-id="' + expectedId + '"]'));
          return {
            moved: found,
            columnCount: col ? col.parentElement.querySelector('.kanban-column-count')?.textContent : null,
          };
        }, { expectedId: secondCardId, status: targetStatus });
        console.log('after move', afterMove);
        if (!afterMove.moved) failures.push('card did not move to ' + targetStatus + ' via menu');
        await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-kanban-after-move-412.png') });
      } else {
        failures.push('could not open card menu for status move');
      }
    }

    // 4c. Unauthorized users do not receive Archive.
    await page.goto(`${BASE}/main.php`);
    await page.waitForLoadState('networkidle');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.waitForTimeout(500);
    await page.evaluate(() => {
      document.querySelectorAll('.allow-card-delete').forEach(el => el.classList.remove('allow-card-delete'));
    });
    const unauthorizedOpened = await openFirstCardMenu(page);
    if (unauthorizedOpened) {
      const unauthorized = await page.evaluate(() => {
        const m = document.getElementById('kanbanCardMobileMenu');
        const archive = m ? m.querySelector('.mobile-card-menu-archive') : null;
        return archive ? getComputedStyle(archive).display : 'missing';
      });
      console.log('unauthorized archive display', unauthorized);
      if (unauthorized !== 'none') failures.push('archive action visible when not permitted: ' + unauthorized);
    }
  }

  // 5. Card tap opens edit modal.
  await page.evaluate(() => { if (window.MobileKanban) window.MobileKanban.hideMenu(); });
  await page.waitForTimeout(200);
  await page.goto(`${BASE}/main.php`);
  await page.waitForLoadState('networkidle');
  await page.setViewportSize({ width: 412, height: 915 });
  await page.waitForTimeout(500);
  await page.click('.kanban-card');
  await page.waitForTimeout(500);
  const modal = await page.$('#createCaseModal');
  const modalStyle = modal ? await page.evaluate(() => {
    const m = document.getElementById('createCaseModal');
    return m ? getComputedStyle(m).display : null;
  }) : null;
  console.log('edit modal display', modalStyle);
  if (modalStyle !== 'block') failures.push('card tap did not open edit modal');
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-kanban-edit-modal-412.png') });

  // 6. Layout checks at all phone widths.
  await page.goto(`${BASE}/main.php`);
  await page.waitForLoadState('networkidle');
  for (const vp of VIEWPORTS) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(500);

    const overflow = await page.evaluate(() => {
      const doc = document.documentElement;
      return { x: doc.scrollWidth - doc.clientWidth, y: doc.scrollHeight - doc.clientHeight };
    });
    console.log(vp.name, 'overflow', overflow);
    if (overflow.x > 0) failures.push(`${vp.name}: horizontal document overflow (${overflow.x}px)`);

    await openFirstCardMenu(page);
    const layout = await page.evaluate(() => {
      const m = document.getElementById('kanbanCardMobileMenu');
      const s = m ? m.querySelector('.mobile-card-move-select') : null;
      const mr = m ? m.getBoundingClientRect() : null;
      const sr = s ? s.getBoundingClientRect() : null;
      return {
        menu: mr,
        select: sr,
        menuRight: mr ? mr.right : null,
        selectRight: sr ? sr.right : null,
        viewportWidth: window.innerWidth,
        viewportHeight: window.innerHeight,
      };
    });
    console.log(vp.name, 'layout', layout);

    if (layout.menuRight && layout.menuRight > vp.width + 1) {
      failures.push(`${vp.name}: menu right edge (${layout.menuRight}px) exceeds viewport width (${vp.width}px)`);
    }
    if (layout.selectRight && layout.menuRight && layout.selectRight > layout.menuRight + 1) {
      failures.push(`${vp.name}: select right edge (${layout.selectRight}px) exceeds menu right edge (${layout.menuRight}px)`);
    }
    if (layout.selectRight && layout.selectRight > vp.width + 1) {
      failures.push(`${vp.name}: select right edge (${layout.selectRight}px) exceeds viewport width (${vp.width}px)`);
    }
    await page.evaluate(() => { if (window.MobileKanban) window.MobileKanban.hideMenu(); });

    if (vp.width === 412) {
      await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-kanban-action-menu-412.png') });
    } else {
      await page.screenshot({ path: path.join(SCREEN_DIR, `mobile-kanban-action-menu-${vp.width}.png`) });
    }
  }

  // 7. Genuine touch swipes across additional phone widths.
  const SWIPE_VIEWPORTS = VIEWPORTS.filter(vp => vp.width !== 412);
  for (const vp of SWIPE_VIEWPORTS) {
    await page.goto(`${BASE}/main.php`);
    await page.waitForLoadState('networkidle');
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(500);

    await performHorizontalSwipe(page, 'next');
    const afterNextVp = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return {
        scrollLeft: board ? board.scrollLeft : null,
        activeIndex: window.MobileKanban ? window.MobileKanban.getActiveIndex() : null,
        selectorValue: document.getElementById('mobileKanbanSelect') ? document.getElementById('mobileKanbanSelect').value : null,
        prevDisabled: document.getElementById('mobileKanbanPrev') ? document.getElementById('mobileKanbanPrev').disabled : null,
      };
    });
    console.log(vp.name, 'after next swipe', afterNextVp);
    if (afterNextVp.activeIndex !== 1) failures.push(`${vp.name}: swipe to next did not select second column`);
    if (afterNextVp.selectorValue !== 'Sent To External Lab') failures.push(`${vp.name}: selector did not update after next swipe: ${afterNextVp.selectorValue}`);
    if (afterNextVp.prevDisabled) failures.push(`${vp.name}: Previous should be enabled after next swipe`);

    await performHorizontalSwipe(page, 'prev');
    const afterPrevVp = await page.evaluate(() => {
      const board = document.getElementById('kanbanBoard');
      return {
        scrollLeft: board ? board.scrollLeft : null,
        activeIndex: window.MobileKanban ? window.MobileKanban.getActiveIndex() : null,
        selectorValue: document.getElementById('mobileKanbanSelect') ? document.getElementById('mobileKanbanSelect').value : null,
        prevDisabled: document.getElementById('mobileKanbanPrev') ? document.getElementById('mobileKanbanPrev').disabled : null,
      };
    });
    console.log(vp.name, 'after prev swipe', afterPrevVp);
    if (afterPrevVp.activeIndex !== 0) failures.push(`${vp.name}: swipe to prev did not select first column`);
    if (afterPrevVp.selectorValue !== 'Originated') failures.push(`${vp.name}: selector did not return to Originated after prev swipe: ${afterPrevVp.selectorValue}`);
    if (!afterPrevVp.prevDisabled) failures.push(`${vp.name}: Previous should be disabled on first column`);
  }

  // 8. Pinch-to-zoom over the board.
  await page.goto(`${BASE}/main.php`);
  await page.waitForLoadState('networkidle');
  await page.setViewportSize({ width: 412, height: 915 });
  await page.waitForTimeout(500);
  const beforeScale = await page.evaluate(() => { return window.visualViewport ? window.visualViewport.scale : null; });
  await performPinch(page);
  const afterPinch = await page.evaluate(() => {
    return {
      scale: window.visualViewport ? window.visualViewport.scale : null,
      events: window.__pinchEvents || [],
    };
  });
  console.log('after pinch', { beforeScale, afterPinch });
  if (afterPinch.scale === null) {
    failures.push('visualViewport not available to verify pinch-to-zoom');
  } else if (afterPinch.scale <= beforeScale + 0.05) {
    failures.push('pinch-to-zoom did not change visualViewport scale: before=' + beforeScale + ' after=' + afterPinch.scale);
  }
  if (afterPinch.events.some(e => e.defaultPrevented)) {
    failures.push('a pinch touch event was defaultPrevented: ' + JSON.stringify(afterPinch.events.filter(e => e.defaultPrevented)));
  }

  } finally {
    // Always attempt to delete only the test cases this run created.
    try {
      await deleteTestCases(page, seededIds);
    } catch (e) {
      console.error('deleteTestCases in finally failed:', e);
    }
    await browser.close();
  }

  if (failures.length) {
    console.error('FAILURES:', failures);
    process.exit(1);
  }
  console.log('Mobile kanban Phase 2 verification passed across viewports.');
}

run().catch(err => {
  console.error(err);
  process.exit(1);
});
