/**
 * "Case actions" three-dot menu e2e test.
 *
 * Covers the shared menu (js/case-actions-menu.js) that replaced the
 * standalone Edit / Print / Archive buttons on board cards and added the
 * same menu to list rows:
 *
 *   - Board card + list row both render one .case-actions-toggle with
 *     aria-label "Case actions"; menu items render in order
 *     Edit, Print, <separator>, Archive.
 *   - Only one menu open at a time; outside click, Escape, and action
 *     selection all close it; Escape returns focus to the trigger.
 *   - Arrow keys move focus between items; Enter activates.
 *   - Toggle/menu clicks never open the case modal or start a card drag.
 *   - Edit routes to window.editCaseHandler, Print to window.printCase,
 *     Archive to the existing showDeleteConfirmation + deleteCase flow.
 *   - The allow_card_delete preference (the .allow-card-delete container
 *     class) hides Archive AND its separator in both views; server-side
 *     authorization in api/delete-case.php is unchanged.
 *   - A failed archive keeps the card/row and shows the error toast.
 *   - Re-rendering (cardsUpdated) closes the menu without orphaning it.
 *   - Menu is position:fixed on <body>, clamped inside the viewport, and
 *     never clipped by scrollable board/list containers.
 *
 * Run: node tests/case-actions-menu-e2e-test.js
 *      DT_BROWSER=firefox|webkit for other engines.
 */
'use strict';

const { chromium, firefox, webkit } = require('playwright');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = 'e2e_test_browser2@dentatrak.com';
const PASSWORD = 'TestPass123!';
const TEST_MARKER = 'DentaTrakTest-CaseActions';

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
  return String(c.id || c.caseId || c.case_id);
}

async function postJson(page, url, body) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  return page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, { url, body: Object.assign({ csrf_token: csrf }, body) });
}

async function getSettings(page) {
  return page.evaluate(async url => (await (await fetch(url, { credentials: 'same-origin' })).json()),
    `${BASE}/api/get-settings.php`);
}

async function saveAllowCardDelete(page, allow) {
  const s = await getSettings(page);
  const p = s.preferences || {};
  return postJson(page, `${BASE}/api/save-settings.php`, {
    theme: p.theme || 'light',
    allowCardDelete: allow,
    highlightPastDue: p.highlight_past_due !== false,
    pastDueDays: p.past_due_days || 1,
    highlightComingDue: p.highlight_coming_due === true,
    comingDueDays: p.coming_due_days || 5,
    highlightAppointmentRisk: p.highlight_appointment_risk !== false,
    appointmentRiskDays: p.appointment_risk_days || 3,
    deliveredHideDays: typeof p.delivered_hide_days === 'number' ? p.delivered_hide_days : 120,
  });
}

const cardSel = id => `.kanban-card[data-case-id="${id}"]`;

async function openCardMenu(page, id) {
  await page.click(`${cardSel(id)} .case-actions-toggle`);
  await page.waitForSelector('#caseActionsMenu.open', { state: 'attached', timeout: 5000 });
}

function menuSnapshot(page) {
  return page.evaluate(() => {
    const m = document.getElementById('caseActionsMenu');
    if (!m || !m.classList.contains('open')) return { open: false };
    const items = Array.from(m.children).map(el =>
      el.classList.contains('case-actions-separator') ? '|'
      : (el.dataset.action || el.className));
    return {
      open: true,
      items,
      rect: (r => ({ top: r.top, left: r.left, right: r.right, bottom: r.bottom, w: r.width }))(m.getBoundingClientRect()),
      position: getComputedStyle(m).position,
      parent: m.parentElement === document.body ? 'body' : 'other',
      vw: window.innerWidth, vh: window.innerHeight,
      focused: document.activeElement ? (document.activeElement.dataset.action || document.activeElement.className) : null,
    };
  });
}

async function closeCaseModal(page) {
  await page.evaluate(() => {
    const btn = document.getElementById('createCaseClose');
    if (btn) btn.click();
  });
  await page.waitForTimeout(200);
  const discard = page.locator('#close-btn');
  if (await discard.isVisible().catch(() => false)) await discard.click();
  await page.waitForTimeout(200);
}

(async () => {
  const browser = await BROWSER.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const created = [];
  let originalAllow = true;

  page.on('console', msg => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    const url = (msg.location() && msg.location().url) || '';
    // Expected failures: the test deliberately triggers 4xx/5xx on these
    // endpoints (archive-disabled 403, simulated archive failure 500).
    if (/favicon|checkForUpdates|notification count/i.test(text)) return;
    if (/delete-case\.php|save-settings\.php/.test(url)) return;
    check('no page console errors', false, (text + ' @ ' + url).slice(0, 160));
  });

  try {
    await login(context);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('meta[name="csrf-token"]', { state: 'attached' });
    await postJson(page, `${BASE}/api/accept-terms.php`, { accepted: true, terms_version: '2026-09-01' }).catch(() => {});

    const initial = await getSettings(page);
    originalAllow = initial.preferences.allow_card_delete !== false;
    if (!originalAllow) await saveAllowCardDelete(page, true);

    const caseA = await createCase(page, 'ActionsA');
    const caseB = await createCase(page, 'ActionsB');
    created.push(caseA, caseB);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.kanban-card .case-actions-toggle', { state: 'attached', timeout: 20000 });
    await page.waitForTimeout(800);

    /* ---------- 1. Board card: trigger + menu contents/order ---------- */
    const toggleInfo = await page.evaluate((id) => {
      const t = document.querySelector(`.kanban-card[data-case-id="${id}"] .case-actions-toggle`);
      return t ? {
        label: t.getAttribute('aria-label'),
        haspopup: t.getAttribute('aria-haspopup'),
        expanded: t.getAttribute('aria-expanded'),
        // no standalone action buttons left on the card
        strayEdit: !!document.querySelector(`.kanban-card[data-case-id="${id}"] .kanban-card-edit`),
        strayPrint: !!document.querySelector(`.kanban-card[data-case-id="${id}"] .kanban-card-print`),
        strayDelete: !!document.querySelector(`.kanban-card[data-case-id="${id}"] .card-delete-btn`),
      } : null;
    }, caseA);
    check('card toggle aria-label is "Case actions"', toggleInfo && toggleInfo.label === 'Case actions', toggleInfo && toggleInfo.label);
    check('card toggle has aria-haspopup=menu, collapsed', toggleInfo && toggleInfo.haspopup === 'menu' && toggleInfo.expanded === 'false');
    check('no standalone edit/print/archive buttons remain on card', toggleInfo && !toggleInfo.strayEdit && !toggleInfo.strayPrint && !toggleInfo.strayDelete, JSON.stringify(toggleInfo));

    await openCardMenu(page, caseA);
    const snap = await menuSnapshot(page);
    check('menu opens with Edit/Print/divider/Archive order', snap.open && snap.items.join(',') === 'edit,print,|,archive', snap.items && snap.items.join(','));
    check('menu is position:fixed appended to body (no container clipping)', snap.position === 'fixed' && snap.parent === 'body', snap.position + '/' + snap.parent);
    check('focus moved into the menu on open', snap.focused === 'edit', snap.focused);
    check('menu inside viewport', snap.rect && snap.rect.left >= 0 && snap.rect.right <= snap.vw + 1 && snap.rect.top >= 0 && snap.rect.bottom <= snap.vh + 1, JSON.stringify(snap.rect));

    const expanded = await page.getAttribute(`${cardSel(caseA)} .case-actions-toggle`, 'aria-expanded');
    check('trigger aria-expanded=true while open', expanded === 'true', expanded);

    /* ---------- 2. Toggle click does not open the case modal ---------- */
    const modalAfterToggle = await page.evaluate(() =>
      getComputedStyle(document.getElementById('createCaseModal')).display);
    check('opening menu did not open case modal', modalAfterToggle !== 'block', modalAfterToggle);

    /* ---------- 3. Keyboard: arrows move focus, Escape closes + refocus ---------- */
    await page.keyboard.press('ArrowDown');
    const afterDown = await menuSnapshot(page);
    check('ArrowDown moves focus to Print', afterDown.focused === 'print', afterDown.focused);
    await page.keyboard.press('ArrowDown');
    const afterDown2 = await menuSnapshot(page);
    check('second ArrowDown wraps to Archive', afterDown2.focused === 'archive', afterDown2.focused);
    await page.keyboard.press('ArrowUp');
    const afterUp = await menuSnapshot(page);
    check('ArrowUp moves back to Print', afterUp.focused === 'print', afterUp.focused);
    await page.keyboard.press('Escape');
    const afterEsc = await page.evaluate((id) => ({
      open: window.caseActionsMenu.isOpen(),
      activeIsToggle: document.activeElement &&
        document.activeElement.classList.contains('case-actions-toggle') &&
        document.activeElement.getAttribute('data-case-id') === id,
    }), caseA);
    check('Escape closes menu and returns focus to trigger', !afterEsc.open && afterEsc.activeIsToggle, JSON.stringify(afterEsc));

    /* ---------- 4. Outside click closes ---------- */
    await openCardMenu(page, caseA);
    await page.mouse.click(40, 850); // empty page area, outside menu and card
    await page.waitForTimeout(150);
    check('outside click closes menu', !(await page.evaluate(() => window.caseActionsMenu.isOpen())));

    /* ---------- 5. Only one menu at a time ---------- */
    await openCardMenu(page, caseA);
    await page.click(`${cardSel(caseB)} .case-actions-toggle`);
    await page.waitForTimeout(150);
    const single = await page.evaluate(() => ({
      menus: document.querySelectorAll('#caseActionsMenu').length,
      openMenus: document.querySelectorAll('.case-actions-menu.open').length,
      expandedToggles: document.querySelectorAll('.case-actions-toggle[aria-expanded="true"]').length,
    }));
    check('single shared menu element, one open, one expanded trigger', single.menus === 1 && single.openMenus === 1 && single.expandedToggles === 1, JSON.stringify(single));
    await page.keyboard.press('Escape');

    /* ---------- 6. Menu interactions never start a drag ---------- */
    const dragCheck = await page.evaluate((id) => {
      const card = document.querySelector(`.kanban-card[data-case-id="${id}"]`);
      const toggle = card.querySelector('.case-actions-toggle');
      let dragOnCard = false;
      card.addEventListener('dragstart', () => { dragOnCard = true; });
      toggle.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true }));
      return { dragOnCard };
    }, caseA);
    check('dragstart on toggle does not reach the card', !dragCheck.dragOnCard, JSON.stringify(dragCheck));

    /* ---------- 7. Edit (board) routes to editCaseHandler ---------- */
    await page.evaluate(() => {
      window.__editedWith = null;
      const orig = window.editCaseHandler;
      window.editCaseHandler = function (d) { window.__editedWith = d && d.id; return orig.apply(this, arguments); };
    });
    await openCardMenu(page, caseA);
    await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, undefined, { timeout: 15000 });
    const edited = await page.evaluate(() => window.__editedWith);
    check('board Edit opened the case via editCaseHandler', String(edited) === String(caseA), String(edited));
    check('menu closed after Edit selection', !(await page.evaluate(() => window.caseActionsMenu.isOpen())));
    await closeCaseModal(page);

    /* ---------- 8. Print (board) routes to printCase ---------- */
    await page.evaluate(() => {
      window.__printedWith = null;
      window.printCase = function (d) { window.__printedWith = d && d.id; };
    });
    await openCardMenu(page, caseA);
    await page.click('#caseActionsMenu [data-action="print"]');
    await page.waitForTimeout(200);
    const printed = await page.evaluate(() => window.__printedWith);
    check('board Print called printCase with the card payload', String(printed) === String(caseA), String(printed));
    check('menu closed after Print selection', !(await page.evaluate(() => window.caseActionsMenu.isOpen())));

    /* ---------- 9. Archive failure keeps the card + shows error ---------- */
    await page.route('**/api/delete-case.php', route => route.fulfill({
      status: 500, contentType: 'application/json',
      body: JSON.stringify({ success: false, message: 'Simulated failure' }),
    }));
    await openCardMenu(page, caseB);
    await page.click('#caseActionsMenu [data-action="archive"]');
    await page.waitForSelector('#confirmBtn', { state: 'visible', timeout: 5000 });
    check('archive shows existing confirmation modal', true);
    await page.click('#confirmBtn');
    await page.waitForTimeout(700);
    const failState = await page.evaluate((id) => ({
      cardPresent: !!document.querySelector(`.kanban-card[data-case-id="${id}"]`),
      toast: Array.from(document.querySelectorAll('.toast, .toast-error, [class*="toast"]'))
        .map(t => t.textContent).join(' '),
    }), caseB);
    check('failed archive keeps the card on the board', failState.cardPresent);
    check('failed archive shows error feedback', /error|fail|unable|simulated/i.test(failState.toast), failState.toast.slice(0, 120));
    await page.unroute('**/api/delete-case.php');

    /* ---------- 10. Archive success removes card + decrements count ---------- */
    const beforeCount = await page.evaluate(() => {
      const c = document.querySelector('[data-status="Originated"] .kanban-column-count');
      return c ? parseInt(c.textContent, 10) : null;
    });
    await openCardMenu(page, caseB);
    await page.click('#caseActionsMenu [data-action="archive"]');
    await page.waitForSelector('#confirmBtn', { state: 'visible', timeout: 5000 });
    const respPromise = page.waitForResponse(r => r.url().includes('delete-case.php') && r.status() === 200, { timeout: 10000 });
    await page.click('#confirmBtn');
    await respPromise;
    await page.waitForTimeout(800);
    const afterArchive = await page.evaluate(() => ({
      cardGone: !document.querySelector(`.kanban-card[data-case-id]` + ''),
      count: (c => c ? parseInt(c.textContent, 10) : null)(
        document.querySelector('[data-status="Originated"] .kanban-column-count')),
    }));
    const cardBGone = await page.evaluate((id) =>
      !document.querySelector(`.kanban-card[data-case-id="${id}"]`), caseB);
    check('archived card removed from active board', cardBGone);
    check('column count decremented', beforeCount !== null && afterArchive.count === beforeCount - 1, `${beforeCount} -> ${afterArchive.count}`);

    /* ---------- 11. Re-render safety: no orphaned/duplicate menus ---------- */
    await page.evaluate(() => window.triggerCardsUpdated && window.triggerCardsUpdated());
    await page.waitForTimeout(400);
    const afterRerender = await page.evaluate(() => ({
      menus: document.querySelectorAll('#caseActionsMenu').length,
      openMenus: document.querySelectorAll('.case-actions-menu.open').length,
    }));
    check('cardsUpdated leaves at most one menu, none orphaned/open', afterRerender.menus <= 1 && afterRerender.openMenus === 0, JSON.stringify(afterRerender));

    /* ---------- 12. Edge positioning: card scrolled to right edge ---------- */
    await page.evaluate((id) => {
      const card = document.querySelector(`.kanban-card[data-case-id="${id}"]`);
      if (card) card.scrollIntoView({ block: 'center' });
      const board = document.getElementById('kanbanBoard');
      if (board) board.scrollLeft = board.scrollWidth; // hard right
    }, caseA);
    await page.waitForTimeout(200);
    // use the last visible card's toggle — nearest the viewport edge
    const edge = await page.evaluate(() => {
      const toggles = Array.from(document.querySelectorAll('.kanban-card .case-actions-toggle'))
        .filter(t => t.offsetParent !== null);
      const t = toggles[toggles.length - 1];
      t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      t.click();
      const m = document.getElementById('caseActionsMenu');
      const r = m.getBoundingClientRect();
      return { right: r.right, left: r.left, vw: window.innerWidth, open: m.classList.contains('open') };
    });
    check('menu clamped inside viewport at board edge', edge.open && edge.right <= edge.vw + 1 && edge.left >= 0, JSON.stringify(edge));
    await page.keyboard.press('Escape');

    /* ---------- 13. List view: row toggle + menu + Edit ---------- */
    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row .case-actions-toggle', { state: 'attached', timeout: 15000 });
    const rowInfo = await page.evaluate((id) => {
      const row = document.querySelector(`.case-list-row[data-case-id="${id}"]`);
      const t = row && row.querySelector('.case-actions-toggle');
      return {
        rowExists: !!row,
        toggleLabel: t && t.getAttribute('aria-label'),
        inLastCell: !!(t && t.closest('td.cl-actions')),
        thExists: !!document.querySelector('.cl-actions-th'),
      };
    }, caseA);
    check('list row has Case actions toggle in actions cell + header col', rowInfo.rowExists && rowInfo.toggleLabel === 'Case actions' && rowInfo.inLastCell && rowInfo.thExists, JSON.stringify(rowInfo));

    await page.click(`.case-list-row[data-case-id="${caseA}"] .case-actions-toggle`);
    await page.waitForSelector('#caseActionsMenu.open', { state: 'attached', timeout: 5000 });
    const rowSnap = await menuSnapshot(page);
    check('list menu order Edit/Print/divider/Archive', rowSnap.items.join(',') === 'edit,print,|,archive', rowSnap.items.join(','));

    await page.click('#caseActionsMenu [data-action="edit"]');
    await page.waitForFunction(() => {
      const f = document.getElementById('patientFirstName');
      return f && f.value.length > 0;
    }, undefined, { timeout: 15000 });
    const editedFromList = await page.evaluate(() => window.__editedWith);
    check('list Edit opened the case via editCaseHandler', String(editedFromList) === String(caseA), String(editedFromList));
    await closeCaseModal(page);

    /* ---------- 14. Menu click on row does not trigger row-open ---------- */
    await page.click(`.case-list-row[data-case-id="${caseA}"] .case-actions-toggle`);
    await page.waitForSelector('#caseActionsMenu.open', { state: 'attached', timeout: 5000 });
    const modalAfterRowToggle = await page.evaluate(() =>
      getComputedStyle(document.getElementById('createCaseModal')).display);
    check('row toggle click did not open the case modal', modalAfterRowToggle !== 'block', modalAfterRowToggle);
    await page.keyboard.press('Escape');

    /* ---------- 15. allow_card_delete=false hides Archive + divider, both views ---------- */
    await saveAllowCardDelete(page, false);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.kanban-card .case-actions-toggle', { state: 'attached', timeout: 20000 });
    // The view preference persists — restore the board view explicitly.
    await page.click('#boardViewToggle').catch(() => {});
    await page.waitForSelector(`${cardSel(caseA)} .case-actions-toggle`, { state: 'visible', timeout: 15000 });
    await page.waitForTimeout(800);

    await openCardMenu(page, caseA);
    const offSnap = await menuSnapshot(page);
    check('archive OFF (board): only Edit,Print — no divider', offSnap.items.join(',') === 'edit,print', offSnap.items.join(','));
    await page.keyboard.press('Escape');

    await page.click('#listViewToggle');
    await page.waitForSelector('.case-list-row .case-actions-toggle', { state: 'attached', timeout: 15000 });
    await page.click(`.case-list-row[data-case-id="${caseA}"] .case-actions-toggle`);
    await page.waitForSelector('#caseActionsMenu.open', { state: 'attached', timeout: 5000 });
    const offListSnap = await menuSnapshot(page);
    check('archive OFF (list): only Edit,Print — no divider', offListSnap.items.join(',') === 'edit,print', offListSnap.items.join(','));
    await page.keyboard.press('Escape');

    // Server still enforces even if the menu were bypassed
    const forbidden = await postJson(page, `${BASE}/api/delete-case.php`, { caseId: caseA });
    check('delete-case.php rejects 403 while setting disabled', forbidden.status === 403 && forbidden.json && forbidden.json.success === false, JSON.stringify(forbidden));

    // Restore the setting for cleanup + leave environment as found
    await saveAllowCardDelete(page, originalAllow);

  } catch (e) {
    check('no uncaught exception', false, (e && e.message) || String(e));
  } finally {
    // Cleanup: archive the seeded test cases (best effort).
    try {
      if (originalAllow) {
        for (const id of created) {
          await postJson(page, `${BASE}/api/delete-case.php`, { caseId: id }).catch(() => {});
        }
      }
    } catch (e2) { /* ignore */ }
    await browser.close();
  }

  const failed = results.filter(r => !r.pass);
  console.log('\n' + results.length + ' checks, ' + failed.length + ' failures');
  if (failed.length) { failed.forEach(f => console.log('  FAIL: ' + f.name)); process.exit(1); }
})();
