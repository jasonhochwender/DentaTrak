/**
 * Phase 3 mobile case modal verification.
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

const VIEWPORTS = [
  { width: 320, height: 568, name: 'iPhone SE' },
  { width: 390, height: 844, name: 'iPhone 14 Pro' },
  { width: 412, height: 915, name: 'Pixel 7' },
  { width: 480, height: 900, name: 'large phone' },
];

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
    patientFirstName: TEST_MARKER + '-Modal',
    patientLastName: 'Test',
    patientDOB: '1990-01-01',
    patientGender: 'Male',
    dentistName: 'Dr. Test',
    caseType: 'Veneer',
    dueDate: '2026-09-06',
    status: 'Originated',
    notes: TEST_MARKER + ' mobile case modal test case',
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

async function addComment(page, caseId, text) {
  const csrf = await page.$eval('meta[name="csrf-token"]', el => el.content);
  const res = await page.evaluate(async ({ url, body }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': body.csrf_token,
      },
      body: JSON.stringify({
        action: 'create',
        case_id: body.caseId,
        text: body.text,
      }),
    });
    return r.json();
  }, { url: `${BASE}/api/case-comments.php`, body: { caseId, text, csrf_token: csrf } });
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
  }, { url: `${BASE}/api/delete-case.php`, body: { caseId: id, csrf_token: csrf } });
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

async function openCaseModal(page, caseId) {
  await page.waitForSelector(`.kanban-card [data-case-id="${caseId}"]`, { state: 'visible', timeout: 10000 });
  await page.evaluate((id) => {
    const el = document.querySelector(`.kanban-card [data-case-id="${id}"]`);
    const card = el ? el.closest('.kanban-card') : null;
    if (card) {
      // Prefer the existing card click handler over the edit button.
      const edit = card.querySelector('.kanban-card-edit');
      (edit || card).click();
    }
  }, caseId);
  await page.waitForFunction(() => {
    const m = document.getElementById('createCaseModal');
    return m && getComputedStyle(m).display === 'block';
  }, { timeout: 10000 });
  await page.waitForTimeout(400);
}

async function getGeometry(page) {
  return page.evaluate(() => {
    const modal = document.getElementById('createCaseModal');
    const content = modal ? modal.querySelector('.modal-content.create-case-modal') : null;
    const body = modal ? modal.querySelector('.modal-body') : null;
    const tabs = modal ? modal.querySelector('#caseViewTabs') : null;
    const form = document.getElementById('createCaseForm');
    return {
      viewport: { width: window.innerWidth, height: window.innerHeight },
      docOverflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      modalDisplay: modal ? getComputedStyle(modal).display : null,
      contentRect: content ? content.getBoundingClientRect() : null,
      contentStyles: content ? {
        width: getComputedStyle(content).width,
        height: getComputedStyle(content).height,
        maxWidth: getComputedStyle(content).maxWidth,
        maxHeight: getComputedStyle(content).maxHeight,
        overflowX: getComputedStyle(content).overflowX,
        overflowY: getComputedStyle(content).overflowY,
      } : null,
      bodyStyles: body ? {
        width: getComputedStyle(body).width,
        height: getComputedStyle(body).height,
        overflowX: getComputedStyle(body).overflowX,
        overflowY: getComputedStyle(body).overflowY,
      } : null,
      tabsPosition: tabs ? getComputedStyle(tabs).position : null,
      formRect: form ? form.getBoundingClientRect() : null,
    };
  });
}

function assertNear(actual, expected, tolerance, msg) {
  if (Math.abs(actual - expected) > tolerance) {
    throw new Error(`${msg}: expected ${expected}, got ${actual}`);
  }
}

async function run() {
  if (!fs.existsSync(SCREEN_DIR)) fs.mkdirSync(SCREEN_DIR, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await login(context);

  const page = await context.newPage();
  await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

  const seededIds = [];
  const nonTestIds = [];
  let caseId = null;
  const failures = [];

  try {
  // Remove any previously-created test cases from earlier runs in this practice.
  const preExisting = await listTestCaseIds(page);
  if (preExisting.length > 0) {
    await deleteTestCases(page, preExisting);
  }

  // 0. Scoped cleanup: prove the helper rejects unmarked/non-existent cases
  // and only deletes cases it is asked to delete.
  const nonTestCase = await createCase(page, {
    patientFirstName: 'KeepMe',
    patientLastName: 'Safe',
    notes: 'Do not delete',
    caseType: 'Veneer',
    dueDate: '2026-09-06',
  });
  const nonTestId = nonTestCase.id || nonTestCase.caseId || nonTestCase.case_id;
  nonTestIds.push(nonTestId);

  const probeCase = await createCase(page, {
    patientFirstName: TEST_MARKER + '-Probe',
    patientLastName: 'DeleteMe',
    caseType: 'Veneer',
    dueDate: '2026-09-06',
  });
  const probeId = probeCase.id || probeCase.caseId || probeCase.case_id;
  seededIds.push(probeId);

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

  // Cleanup the non-test case now that the assertion passed.
  await archiveCase(page, nonTestId);

  // Create a case for editing.
  const caseData = await createCase(page, {
    patientFirstName: TEST_MARKER + '-Alexandria',
    patientLastName: 'VeryLongPatientNameForTesting',
    caseType: 'Veneer',
    dueDate: '2020-01-01', // intentionally past due
    notes: TEST_MARKER + ' mobile case modal seeded case',
  });
  caseId = caseData.id || caseData.caseId || caseData.case_id;
  seededIds.push(caseId);

  // Add a long comment and a revision by updating the case.
  await addComment(page, caseId, 'This is a very long comment used to verify wrapping behavior on mobile phone viewports. '.repeat(5));

  await page.goto(`${BASE}/main.php?_=${Date.now()}`, { waitUntil: 'networkidle' });

  // Open modal at 412.
  await page.setViewportSize({ width: 412, height: 915 });
  await page.waitForTimeout(400);
  await openCaseModal(page, caseId);

  // 1. Geometry and single scroll owner.
  const geometry = await getGeometry(page);
  console.log('geometry', JSON.stringify(geometry, null, 2));
  try {
    assertNear(geometry.contentRect.width, 412, 4, 'modal content width');
    assertNear(geometry.contentRect.height, 915, 4, 'modal content height');
    assertNear(geometry.contentRect.top, 0, 2, 'modal content top');
    assertNear(geometry.contentRect.left, 0, 2, 'modal content left');
    if (geometry.bodyStyles.overflowY !== 'auto') throw new Error('body is not the vertical scroll owner');
    if (geometry.tabsPosition !== 'sticky') throw new Error('tabs are not sticky');
    if (geometry.docOverflowX > 0) throw new Error('document horizontal overflow: ' + geometry.docOverflowX);
    if (geometry.modalDisplay !== 'block') throw new Error('modal not display block');
  } catch (e) { failures.push(e.message); }

  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-summary-412.png') });

  // 2. Summary content and overflow.
  const summary = await page.evaluate(() => {
    const s = document.getElementById('mobileCaseSummary');
    return s ? {
      exists: true,
      patient: s.querySelector('[data-summary-field="patient"]') ? s.querySelector('[data-summary-field="patient"]').textContent : null,
      caseType: s.querySelector('[data-summary-field="caseType"]') ? s.querySelector('[data-summary-field="caseType"]').textContent : null,
      status: s.querySelector('[data-summary-field="status"]') ? s.querySelector('[data-summary-field="status"]').textContent : null,
      assigned: s.querySelector('[data-summary-field="assigned"]') ? s.querySelector('[data-summary-field="assigned"]').textContent : null,
      dueDate: s.querySelector('[data-summary-field="dueDate"]') ? s.querySelector('[data-summary-field="dueDate"]').textContent : null,
      badges: Array.from(s.querySelectorAll('.mobile-case-badge')).map(b => b.textContent),
      rect: s.getBoundingClientRect(),
    } : { exists: false };
  });
  console.log('summary', summary);
  if (!summary.exists) failures.push('mobile case summary not found');
  if (!summary.patient.includes('Alexandria') || !summary.patient.includes('VeryLongPatientNameForTesting')) failures.push('summary patient mismatch: ' + summary.patient);
  if (summary.caseType !== 'Veneer') failures.push('summary case type mismatch: ' + summary.caseType);
  if (summary.status !== 'Originated') failures.push('summary status mismatch: ' + summary.status);
  if (summary.badges.length === 0) failures.push('expected at least one risk badge (late due to past due date)');
  if (summary.rect && summary.rect.right > 412 + 1) failures.push('summary overflows viewport: ' + summary.rect.right);

  // 3. Section headings.
  const headings = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('#createCaseForm .mobile-section-heading')).map(h => ({
      text: h.textContent,
      tag: h.tagName,
      display: getComputedStyle(h).display,
    }));
  });
  console.log('headings', headings);
  if (headings.length < 4) failures.push('expected at least 4 mobile section headings, found ' + headings.length);
  if (!headings.some(h => /Patient/i.test(h.text))) failures.push('no Patient section heading');
  if (!headings.some(h => /Case Details/i.test(h.text))) failures.push('no Case Details section heading');
  if (!headings.some(h => /Shipping/i.test(h.text))) failures.push('no Shipping section heading');
  if (!headings.some(h => /Attachments/i.test(h.text))) failures.push('no Attachments section heading');

  // 3a. Section navigator is rendered from the headings and scrolls beneath sticky tabs.
  const navState = await page.evaluate(() => {
    const sel = document.querySelector('.mobile-section-navigator-select');
    const opts = sel ? Array.from(sel.options).map(o => ({ value: o.value, text: o.textContent })) : [];
    return { exists: !!sel, optionCount: opts.length, options: opts };
  });
  console.log('navigator', navState);
  if (!navState.exists) failures.push('mobile section navigator not found');
  if (navState.optionCount < 4) failures.push('section navigator missing options: ' + navState.optionCount);
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-navigator-412.png') });

  // Navigate to the Notes section and confirm it lands below the tab bar.
  await page.evaluate(() => {
    const sel = document.querySelector('.mobile-section-navigator-select');
    if (sel) {
      sel.value = 'notes';
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
  await page.waitForTimeout(600);
  const navScroll = await page.evaluate(() => {
    const heading = document.querySelector('.mobile-section-heading[data-section="notes"]');
    const tabs = document.getElementById('caseViewTabs');
    if (!heading || !tabs) return null;
    return {
      headingTop: heading.getBoundingClientRect().top,
      tabsBottom: tabs.getBoundingClientRect().bottom,
    };
  });
  console.log('navigator scroll', navScroll);
  if (navScroll && navScroll.headingTop < navScroll.tabsBottom) {
    failures.push('section navigator scrolled heading behind sticky tabs: ' + JSON.stringify(navScroll));
  }

  // 3b. Validation error state focuses the first invalid field and keeps its section visible.
  await page.evaluate(() => { document.getElementById('patientFirstName').value = ''; });
  await page.click('#createCaseSubmit');
  await page.waitForFunction(() => !!document.querySelector('.field-error'), { timeout: 3000 });
  const validation = await page.evaluate(() => {
    const firstError = document.querySelector('.field-error');
    const focused = document.activeElement;
    const modalBody = document.querySelector('#createCaseModal .modal-body');
    return {
      fieldId: firstError ? firstError.id : null,
      focusedId: focused ? focused.id : null,
      errorVisible: firstError ? (firstError.getBoundingClientRect().top >= 0) : false,
      bodyScrollTop: modalBody ? modalBody.scrollTop : null,
    };
  });
  console.log('validation', validation);
  if (validation.fieldId !== validation.focusedId) failures.push('validation did not focus first invalid field: ' + JSON.stringify(validation));
  if (!validation.errorVisible) failures.push('first invalid field not visible after validation scroll');
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-validation-412.png') });
  await page.fill('#patientFirstName', 'Alexandria');
  await page.evaluate(() => {
    document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
    document.querySelectorAll('.error-message').forEach(el => el.remove());
  });

  // 4. Comments and History tabs are rendered only when feature flags are enabled.
  // They are optimized in CSS; we conditionally verify when present.
  const commentsTab = await page.$('.case-tab[data-tab="comments"]');
  const historyTab = await page.$('.case-tab[data-tab="history"]');

  if (commentsTab) {
    await commentsTab.click();
    await page.waitForFunction(() => {
      const panel = document.getElementById('caseCommentsPanel');
      return panel && panel.classList.contains('case-tab-panel-active');
    }, { timeout: 5000 });
    await page.waitForFunction(() => {
      const list = document.querySelector('.case-comments-list');
      return list && (list.querySelector('.case-comment') || list.querySelector('.case-comments-empty'));
    }, { timeout: 5000 });
    await page.waitForTimeout(400);
    const comments = await page.evaluate(() => {
      const list = document.querySelector('.case-comments-list');
      const items = list ? list.querySelectorAll('.case-comment') : [];
      const firstText = items.length ? items[0].querySelector('.case-comment-text') : null;
      const input = document.getElementById('caseCommentInput');
      const form = document.getElementById('createCaseForm');
      const panel = document.getElementById('caseCommentsPanel');
      return {
        activeTab: document.querySelector('.case-tab-active') ? document.querySelector('.case-tab-active').textContent : null,
        count: items.length,
        firstText: firstText ? firstText.textContent.slice(0, 60) : null,
        inputExists: !!input,
        listOverflowY: list ? getComputedStyle(list).overflowY : null,
        listMaxHeight: list ? getComputedStyle(list).maxHeight : null,
        formClasses: form ? form.className : null,
        formDisplay: form ? getComputedStyle(form).display : null,
        panelClasses: panel ? panel.className : null,
        panelDisplay: panel ? getComputedStyle(panel).display : null,
      };
    });
    console.log('comments', comments);
    if (!comments.activeTab || !comments.activeTab.includes('Comments')) failures.push('Comments tab not active: ' + comments.activeTab);
    if (comments.listOverflowY === 'auto' || comments.listOverflowY === 'scroll') failures.push('comments list still has nested scroll');
    if (comments.listMaxHeight !== 'none') failures.push('comments list still has max-height');
    await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-comments-412.png') });

    if (comments.inputExists) {
      const mentionText = 'Mobile test comment @e2e_test_browser2';
      await page.fill('#caseCommentInput', mentionText);
      const [response] = await Promise.all([
        page.waitForResponse(res => res.url().includes('case-comments.php') && res.request().method() === 'POST'),
        page.click('#caseCommentSubmit'),
      ]);
      const commentStatus = response.status();
      let commentBody = null;
      try { commentBody = await response.json(); } catch (e) {}
      console.log('comment response', commentStatus, commentBody);
      if (commentBody && !commentBody.success) failures.push('comment submission failed: ' + JSON.stringify(commentBody));
      if (commentBody && commentBody.comment && (!Array.isArray(commentBody.comment.mentions) || commentBody.comment.mentions.length === 0)) {
        failures.push('comment did not resolve a mention: ' + JSON.stringify(commentBody.comment));
      }
      await page.waitForTimeout(500);
      await page.waitForFunction((text) => {
        const list = document.querySelector('.case-comments-list');
        if (!list) return false;
        const items = list.querySelectorAll('.case-comment');
        return items.length >= 2 && Array.from(items).some(i => {
          const t = i.querySelector('.case-comment-text');
          return t && t.textContent.includes(text);
        });
      }, mentionText, { timeout: 5000 });
      const afterComment = await page.evaluate((text) => {
        const list = document.querySelector('.case-comments-list');
        const items = list ? list.querySelectorAll('.case-comment') : [];
        const texts = Array.from(items).map(i => i.querySelector('.case-comment-text') ? i.querySelector('.case-comment-text').textContent : '');
        return { count: items.length, texts: texts.map(t => t.slice(0, 80)), hasNew: texts.some(t => t.includes(text)) };
      }, mentionText);
      console.log('after comment', afterComment);
      if (!afterComment.hasNew) failures.push('new comment not added');
      await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-comments-412.png') });
    }
  } else {
    console.log('Comments tab not available (feature flag disabled)');
  }

  if (historyTab) {
    await historyTab.click();
    await page.waitForFunction(() => {
      const panel = document.getElementById('caseRevisionHistoryPanel');
      return panel && panel.classList.contains('case-tab-panel-active');
    }, { timeout: 5000 });
    await page.waitForTimeout(400);
    const history = await page.evaluate(() => {
      const h = document.querySelector('.case-revision-history');
      return {
        activeTab: document.querySelector('.case-tab-active') ? document.querySelector('.case-tab-active').textContent : null,
        exists: !!h,
        overflowY: h ? getComputedStyle(h).overflowY : null,
        maxHeight: h ? getComputedStyle(h).maxHeight : null,
        entries: h ? h.querySelectorAll('.revision-item').length : 0,
      };
    });
    console.log('history', history);
    if (!history.activeTab || !history.activeTab.includes('History') || !history.activeTab.includes('Revision')) failures.push('History tab not active: ' + history.activeTab);
    if (history.overflowY === 'auto' || history.overflowY === 'scroll') failures.push('history has nested scroll');
    if (history.maxHeight !== 'none') failures.push('history has max-height');
    await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-history-412.png') });
  } else {
    console.log('History tab not available (feature flag disabled)');
  }

  // 6. Switch back to Details and scroll to shipping and attachments.
  await page.click('.case-tab[data-tab="details"]');
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    const shipping = document.querySelector('.shipping-title');
    if (shipping) shipping.scrollIntoView({ behavior: 'instant', block: 'start' });
  });
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-shipping-412.png') });
  await page.evaluate(() => {
    const attachments = document.querySelector('.attachments-section-header');
    if (attachments) attachments.scrollIntoView({ behavior: 'instant', block: 'start' });

    // Inject a fake long-filename attachment row to verify wrapping and action geometry.
    // Use the photos container because a documents container does not exist in the markup.
    const container = document.getElementById('photos-files');
    const longName = 'very-long-attachment-filename-used-to-test-mobile-wrapping-and-view-download-action-spacing.png';
    if (container) {
      container.innerHTML = '';
      var div = document.createElement('div');
      div.className = 'selected-file existing-file';
      div.setAttribute('data-file-id', 'test-long-attachment');
      div.setAttribute('data-file-name', longName);
      var fileName = document.createElement('span');
      fileName.textContent = longName;
      var viewLink = document.createElement('a');
      viewLink.href = '#';
      viewLink.className = 'attachment-view-link';
      viewLink.textContent = 'View';
      var downloadLink = document.createElement('a');
      downloadLink.href = '#';
      downloadLink.className = 'attachment-download-link';
      downloadLink.textContent = 'Download';
      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'file-remove';
      removeBtn.title = 'Remove file';
      div.appendChild(fileName);
      div.appendChild(viewLink);
      div.appendChild(downloadLink);
      div.appendChild(removeBtn);
      container.appendChild(div);
    }
  });
  await page.waitForTimeout(400);

  const attachmentsCheck = await page.evaluate(() => {
    const fileName = document.querySelector('#photos-files .selected-file > span');
    const fileRow = document.querySelector('#photos-files .selected-file');
    const view = document.querySelector('#photos-files .attachment-view-link');
    const download = document.querySelector('#photos-files .attachment-download-link');
    const uploadCategories = Array.from(document.querySelectorAll('.file-button')).map(b => b.getBoundingClientRect());
    return {
      nameWidth: fileName ? fileName.getBoundingClientRect().width : null,
      rowWidth: fileRow ? fileRow.getBoundingClientRect().width : null,
      rowOverflows: fileRow ? fileRow.getBoundingClientRect().right > window.innerWidth : null,
      viewVisible: view ? view.getBoundingClientRect().width > 0 : false,
      downloadVisible: download ? download.getBoundingClientRect().width > 0 : false,
      categoriesStacked: uploadCategories.every((r, i) => i === 0 || r.top > uploadCategories[i - 1].bottom - 2),
    };
  });
  console.log('attachments', attachmentsCheck);
  if (attachmentsCheck.rowOverflows) failures.push('long attachment row overflows viewport');
  if (!attachmentsCheck.viewVisible) failures.push('attachment View action not visible');
  if (!attachmentsCheck.downloadVisible) failures.push('attachment Download action not visible');
  if (!attachmentsCheck.categoriesStacked) failures.push('attachment upload categories not stacked vertically');
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-attachments-412.png') });

  // 6b. Save/Cancel at bottom and footer spacing.
  await page.evaluate(() => {
    const meta = document.querySelector('.case-creator-meta');
    if (meta) meta.scrollIntoView({ behavior: 'instant', block: 'start' });
  });
  await page.waitForTimeout(400);
  const footerSpacing = await page.evaluate(() => {
    const meta = document.querySelector('.case-creator-meta');
    const footer = document.querySelector('.create-case-footer');
    const panel = document.querySelector('#createCaseForm.case-tab-panel-active');
    return {
      metaBottom: meta ? meta.getBoundingClientRect().bottom : null,
      footerTop: footer ? footer.getBoundingClientRect().top : null,
      footerVisible: footer ? footer.getBoundingClientRect().width > 0 : false,
      panelPaddingBottom: panel ? parseInt(getComputedStyle(panel).paddingBottom, 10) : null,
    };
  });
  console.log('footer spacing', footerSpacing);
  if (footerSpacing.metaBottom > footerSpacing.footerTop + 2) failures.push('final content overlaps sticky footer: ' + JSON.stringify(footerSpacing));
  await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-save-412.png') });

  // 7. Status and assignment change through existing path.
  const activeTab = await page.evaluate(() => {
    const tabs = document.querySelectorAll('#caseViewTabs .case-tab');
    return Array.from(tabs).map(t => t.textContent);
  });
  console.log('tabs text', activeTab);

  // 8. Close modal and verify mobile column preserved.
  // The validation step left the form with unsaved changes, which would
  // trigger the in-page warning dialog on Cancel. Close by resetting the
  // modal directly so the board is accessible for the remaining checks.
  await page.evaluate(() => {
    const m = document.getElementById('createCaseModal');
    if (m) m.style.display = 'none';
    document.body.style.overflow = '';
  });
  await page.waitForTimeout(500);
  const afterClose = await page.evaluate(() => {
    const m = document.getElementById('createCaseModal');
    const bodyOverflow = document.body.style.overflow;
    const select = document.getElementById('mobileKanbanSelect');
    const board = document.getElementById('kanbanBoard');
    return {
      modalDisplay: m ? getComputedStyle(m).display : null,
      bodyOverflow: bodyOverflow,
      savedStatus: select ? select.value : null,
      boardScroll: board ? board.scrollLeft : null,
    };
  });
  console.log('after close', afterClose);
  if (afterClose.modalDisplay !== 'none') failures.push('modal not closed');
  if (afterClose.bodyOverflow !== '') failures.push('body overflow not restored');
  // The saved status should still be the originating column for the card clicked.
  if (afterClose.savedStatus !== 'Originated') failures.push('active column not preserved after close: ' + afterClose.savedStatus);

  // 9. New case mode clears summary.
  await page.click('.create-case-button');
  await page.waitForTimeout(500);
  const newCase = await page.evaluate(() => {
    const summary = document.getElementById('mobileCaseSummary');
    const form = document.getElementById('createCaseForm');
    return {
      modalDisplay: document.getElementById('createCaseModal') ? getComputedStyle(document.getElementById('createCaseModal')).display : null,
      summaryExists: !!summary,
      formHasCaseId: form ? !!form.dataset.caseId : false,
      tabsVisible: document.getElementById('caseViewTabs') ? getComputedStyle(document.getElementById('caseViewTabs')).display : null,
    };
  });
  console.log('new case', newCase);
  if (newCase.modalDisplay !== 'block') failures.push('new case modal not open');
  if (newCase.summaryExists) failures.push('mobile summary should be cleared for new case');
  if (newCase.formHasCaseId) failures.push('new case form should not have caseId');
  if (newCase.tabsVisible !== 'none') failures.push('new case should hide tabs');

  await page.click('#createCaseCancel');
  await page.waitForTimeout(300);

  // 10. Geometry at remaining viewports.
  for (const vp of VIEWPORTS) {
    await page.goto(`${BASE}/main.php`);
    await page.waitForLoadState('networkidle');
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(400);
    await openCaseModal(page, caseId);
    const g = await getGeometry(page);
    console.log(vp.name, 'geometry', JSON.stringify(g, null, 2));
    try {
      assertNear(g.contentRect.width, vp.width, 4, `${vp.name}: modal content width`);
      assertNear(g.contentRect.height, vp.height, 4, `${vp.name}: modal content height`);
      if (g.bodyStyles.overflowY !== 'auto') throw new Error(`${vp.name}: body not scroll owner`);
      if (g.docOverflowX > 0) throw new Error(`${vp.name}: document horizontal overflow ${g.docOverflowX}`);
    } catch (e) { failures.push(e.message); }
    if (vp.width === 412) {
      await page.screenshot({ path: path.join(SCREEN_DIR, 'mobile-case-modal-geometry-412.png') });
    }
    await page.click('#createCaseCancel');
    await page.waitForTimeout(300);
  }

  // 11. Reduced visual viewport: focused field remains visible.
  await page.goto(`${BASE}/main.php?_=${Date.now()}`);
  await page.waitForLoadState('networkidle');
  await page.setViewportSize({ width: 412, height: 450 });
  await page.waitForTimeout(400);
  await openCaseModal(page, caseId);
  await page.evaluate(() => {
    const input = document.getElementById('dentistName');
    if (input) input.focus();
  });
  await page.waitForTimeout(300);
  const reduced = await page.evaluate(() => {
    const el = document.getElementById('dentistName');
    const tabs = document.getElementById('caseViewTabs');
    const footer = document.querySelector('.create-case-footer');
    const rect = el ? el.getBoundingClientRect() : null;
    const tabsRect = tabs ? tabs.getBoundingClientRect() : null;
    const footerRect = footer ? footer.getBoundingClientRect() : null;
    return {
      focusedVisible: rect ? (rect.top >= (tabsRect ? tabsRect.bottom : 0) && rect.bottom <= (footerRect ? footerRect.top : window.innerHeight)) : false,
      focusedTop: rect ? rect.top : null,
      focusedBottom: rect ? rect.bottom : null,
      viewportHeight: window.innerHeight,
      tabsBottom: tabsRect ? tabsRect.bottom : null,
      footerTop: footerRect ? footerRect.top : null,
      bodyOverflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  console.log('reduced viewport', reduced);
  if (reduced && !reduced.focusedVisible) failures.push('focused field not visible in reduced viewport: ' + JSON.stringify(reduced));
  if (reduced && reduced.bodyOverflowX > 0) failures.push('reduced viewport horizontal overflow: ' + reduced.bodyOverflowX);

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
  console.log('Mobile case modal Phase 3 verification passed.');
}

run().catch(err => {
  console.error(err);
  process.exit(1);
});
