/**
 * GA panel + Send-to-Open-Dental browser e2e.
 *
 * Exercises the real UI end-to-end against the local OD stub
 * (tests/mock-open-dental.php) behind a php -S app server with
 * OPEN_DENTAL_BASE_URL pointed at the stub:
 *
 *   - Integrations card renders unlocked for a fully-entitled practice.
 *   - Outbound block appears only for an ACTIVE connection; shows the
 *     lifecycle map, timezone picker, lab default, and health rows.
 *   - Enable runs verify-on-enable (stub /securityperms ApiAllOthers) and
 *     persists enabled + create_enabled + the approved default field map.
 *   - "Send to Open Dental" menu item appears only while
 *     window.pmsSync.odCreateEnabled; dialog pickers resolve patient,
 *     provider, and laboratory against the stub; send queues an event.
 *   - The real CLI worker drains the queue -> real POST /labcases against
 *     the stub -> the case maps to the returned LabCaseNum; reopening the
 *     dialog shows the linked state (no duplicate create).
 *   - Disable leaves inbound sync_enabled intact; re-enable is idempotent.
 *   - Mobile viewport renders the send dialog; dark theme and a
 *     non-English locale still render the new strings.
 *
 * Requires: php -S localhost:80 -t C:\MAMP\htdocs with env
 *   SHOW_PMS_INTEGRATIONS=true PMS_OUTBOUND_SYNC=1
 *   OPEN_DENTAL_BASE_URL=http://127.0.0.1:18700/api/v1
 * plus a stub server: php -S 127.0.0.1:18700 -t tests tests/mock-open-dental.php
 * with OD_STUB_STATE=tests/.stub-state-e2e.json
 *
 * Run: node tests/pms-ga-panel-e2e-test.js
 *      DT_BROWSER=firefox|webkit for other engines.
 */
'use strict';

const { chromium, firefox, webkit } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BROWSER = { chromium, firefox, webkit }[process.env.DT_BROWSER || 'chromium'] || chromium;

const BASE = 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';
const STUB_BASE = 'http://127.0.0.1:18700/api/v1';
const STUB_STATE = path.join(__dirname, '.stub-state-e2e.json');
const TEST_MARKER = 'GaE2E';

const results = [];
function check(name, cond, extra) {
  results.push({ name, pass: !!cond });
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + name + (extra ? '  [' + extra + ']' : ''));
}

async function login(context) {
  const res = await context.request.post(`${BASE}/api/auth-email.php`, {
    data: JSON.stringify({ action: 'login', email: EMAIL, password: PASSWORD }),
    headers: { 'Content-Type': 'application/json' },
  });
  const body = await res.json();
  if (!body.success) throw new Error('Login failed: ' + JSON.stringify(body));
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

const integrationsPost = (page, body) => postJson(page, `${BASE}/api/integrations.php`, body);
const integrationsList = async (page) => {
  const r = await page.evaluate(async url =>
    (await (await fetch(url, { credentials: 'same-origin' })).json()),
    `${BASE}/api/integrations.php?action=list`);
  return (r.connections || []).find(c => c.provider === 'open_dental') || null;
};

function drainWorker() {
  execFileSync('php', ['api/integrations/process-events.php', '--limit=25'], {
    cwd: path.join(__dirname, '..'),
    env: Object.assign({}, process.env, {
      OPEN_DENTAL_BASE_URL: STUB_BASE,
      PMS_OUTBOUND_SYNC: '1',
      SHOW_PMS_INTEGRATIONS: 'true',
    }),
    timeout: 60000,
    stdio: 'pipe',
  });
}
const stubState = () => JSON.parse(fs.readFileSync(STUB_STATE, 'utf8'));

async function openSettings(page) {
  await page.click('#userMenuToggle');
  await page.click('#settingsMenuItem');
  await page.waitForFunction(() => {
    const m = document.getElementById('settingsBillingModal');
    return m && m.style.display === 'block';
  }, null, { timeout: 8000 });
}
async function goToIntegrations(page) {
  const nav = page.locator('.settings-nav-item[data-nav-target="integrations"]');
  await nav.waitFor({ state: 'visible', timeout: 8000 });
  await Promise.all([
    page.waitForResponse(r => r.url().includes('integrations.php'), { timeout: 10000 }).catch(() => null),
    nav.click(),
  ]);
  await page.waitForSelector('#integrationCard-open_dental', { timeout: 8000 });
  await page.waitForTimeout(250);
}
async function closeSettings(page) {
  const x = page.locator('#settingsBillingModal .modal-close, #settingsBillingModal [data-close]').first();
  if (await x.count()) { await x.click().catch(() => {}); }
  await page.evaluate(() => {
    const m = document.getElementById('settingsBillingModal');
    if (m) m.style.display = 'none';
  });
}
const cardSel = id => `.kanban-card[data-case-id="${id}"]`;
async function openCardMenu(page, id) {
  await page.click(`${cardSel(id)} .case-actions-toggle`);
  await page.waitForSelector('#caseActionsMenu.open', { state: 'attached', timeout: 5000 });
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
    patientFirstName: 'Candi', patientLastName: 'Copay', patientDOB: '1985-06-15',
    patientGender: 'Female', dentistName: 'Dr. T', caseType: 'Veneer', material: 'Zirconia',
    dueDate: '2026-12-15', status: 'Originated', notes: `${TEST_MARKER} ${lastName}`,
    assignedTo: EMAIL, csrf_token: csrf,
  }});
  const c = res.caseData || res.case || res;
  return String(c.id || c.caseId || c.case_id);
}

(async () => {
  const browser = await BROWSER.launch();
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();

  try {
    await login(context);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('meta[name="csrf-token"]', { state: 'attached', timeout: 15000 });
    await page.waitForFunction(() => window.isPracticeAdmin === true, null, { timeout: 15000 });
    await page.waitForTimeout(500);

    // --- Connection: ensure the OD connection is ACTIVE ---------------
    let conn = await integrationsList(page);
    check('open_dental connection exists', !!conn);
    if (conn && conn.status !== 'active') {
      const tc = await integrationsPost(page, { action: 'test_connection', connection_id: conn.id });
      check('test_connection activates against stub', tc.status === 200 && tc.json && tc.json.success === true,
        JSON.stringify(tc.json || {}).slice(0, 200));
      conn = await integrationsList(page);
    }
    check('connection status active', conn && conn.status === 'active', conn && conn.status);
    check('outbound not enabled before admin opt-in', !conn.outbound || conn.outbound.enabled !== true,
      JSON.stringify(conn && conn.outbound || null));

    // --- Settings > Integrations > outbound block ----------------------
    await openSettings(page);
    await goToIntegrations(page);
    check('card is NOT plan-locked (GA)', await page.$eval('#integrationCard-open_dental',
      el => el.getAttribute('data-locked') !== 'true'));
    const obVisible = await page.$eval('#integrationOutbound-open_dental',
      el => (el.style.display || getComputedStyle(el).display) !== 'none');
    check('outbound block visible for active connection', obVisible);
    check('outbound state starts OFF', await page.$eval('#integrationOutboundState-open_dental',
      el => el.textContent === window.t('settings.integrations.outbound.state_off')));
    const tzOpts = await page.$$eval('#integrationOutboundTz-open_dental option', os => os.length);
    check('timezone picker populated with IANA zones', tzOpts > 20, String(tzOpts));

    // Lab default select loads from the stub directory.
    await page.waitForFunction(() => {
      const s = document.getElementById('integrationOutboundLab-open_dental');
      return s && s.options.length > 1;
    }, null, { timeout: 10000 });
    await page.selectOption('#integrationOutboundLab-open_dental', '1');
    await page.click('#integrationOutboundLabSave-open_dental');
    await page.waitForFunction(() => {
      const m = document.getElementById('integrationOutboundMsg-open_dental');
      return m && m.style.display !== 'none' && m.textContent.length > 0;
    }, null, { timeout: 8000 });
    conn = await integrationsList(page);
    check('practice lab default persisted', conn.outbound && String(conn.outbound.laboratory_num) === '1',
      JSON.stringify(conn.outbound && conn.outbound.laboratory_num));

    // Health block renders capability + counts + last-sync row.
    const healthRows = await page.$$eval('#integrationOutboundHealth-open_dental .integration-meta-row',
      rows => rows.map(r => r.textContent));
    check('health block has capability row', healthRows.length >= 3, JSON.stringify(healthRows));

    // --- Enable two-way sync via the real button -----------------------
    await page.click('#integrationOutboundToggle-open_dental');
    await page.waitForFunction(() => {
      const b = document.getElementById('integrationOutboundState-open_dental');
      return b && b.textContent === window.t('settings.integrations.outbound.state_on');
    }, null, { timeout: 15000 });
    conn = await integrationsList(page);
    check('enable: outbound.enabled true', conn.outbound && conn.outbound.enabled === true);
    check('enable: create_enabled true', conn.outbound && conn.outbound.create_enabled === true);
    check('enable: timezone persisted', !!(conn.outbound && conn.outbound.timezone),
      conn.outbound && conn.outbound.timezone);
    const fm = conn.outbound && conn.outbound.field_map ? Object.keys(conn.outbound.field_map) : [];
    check('enable: approved default field map (2 entries, no Checked)', fm.length === 2 &&
      Object.values(conn.outbound.field_map).every(v => v !== 'DateTimeChecked'), JSON.stringify(conn.outbound.field_map));
    check('enable: capability recorded', !!(conn.outbound.capability && conn.outbound.capability.has_permission === true),
      JSON.stringify(conn.outbound.capability));
    check('enable: inbound sync_enabled untouched', conn.sync_enabled === true || conn.sync_enabled === 1,
      JSON.stringify(conn.sync_enabled));

    // --- Send to Open Dental dialog ------------------------------------
    await closeSettings(page);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.isPracticeAdmin === true, null, { timeout: 15000 });
    check('pmsSync.odCreateEnabled bootstrap true', await page.evaluate(() =>
      window.pmsSync && window.pmsSync.odCreateEnabled === true));

    const caseId = await createCase(page, 'case-one');
    check('fixture case created', caseId && caseId !== 'undefined' && caseId !== 'null', caseId);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(cardSel(caseId), { timeout: 15000 });
    await openCardMenu(page, caseId);
    const sendItem = page.locator('#caseActionsMenu [data-action="send_to_od"]');
    check('send_to_od menu item rendered', await sendItem.count() === 1);
    await sendItem.click();
    await page.waitForSelector('#odSendCaseModal.show', { timeout: 8000 });
    check('send dialog opens', await page.$eval('#odSendCaseModal', el => el.style.display === 'block'));

    // Wait for the check response to render pickers. Identity mappings may
    // already exist from a previous run, so each picker is conditional; the
    // lab picker must ALWAYS render (explicit user confirmation required).
    await page.waitForFunction(() => {
      const s = document.getElementById('odSendCaseSummary');
      return s && s.textContent.length > 0;
    }, null, { timeout: 10000 });
    await page.waitForTimeout(300);
    check('lab picker shown (explicit confirm required)', await page.$eval('#odSendLabPicker',
      el => el.style.display !== 'none'));
    // Admin default lab is pre-selected as a suggestion.
    const labPre = await page.$eval('#odSendLabSelect', el => el.value);
    check('lab picker suggests practice default', labPre === '1', labPre);
    // A pre-selected suggestion may already satisfy the gate on reruns;
    // clearing the lab must re-gate Confirm.
    await page.selectOption('#odSendLabSelect', '');
    await page.waitForTimeout(100);
    check('confirm gated until all picks made', await page.$eval('#odSendCaseConfirm', el => el.disabled === true));

    // Fill whichever pickers are shown (patient/provider may already be
    // resolved via learned identity mappings on reruns).
    const patShown = await page.$eval('#odSendPatientPicker', el => el.style.display !== 'none');
    check('patient picker shown or already resolved via learned mapping', true, 'shown=' + patShown);
    if (patShown) {
      await page.fill('#odSendPatLName', 'Copay');
      await page.click('#odSendPatSearch');
      await page.waitForSelector('.od-send-candidate', { timeout: 8000 });
      await page.click('.od-send-candidate');
    }
    if (await page.$eval('#odSendProviderPicker', el => el.style.display !== 'none')) {
      await page.selectOption('#odSendProviderSelect', '1');
    }
    await page.selectOption('#odSendLabSelect', '1');
    check('confirm enabled after all picks', await page.$eval('#odSendCaseConfirm', el => el.disabled === false));
    await page.click('#odSendCaseConfirm');
    await page.waitForFunction(() => {
      const s = document.getElementById('odSendCaseStatus');
      return s && s.className.includes('pending');
    }, null, { timeout: 8000 });
    check('send queued (pending state shown)', true);

    // Double-click guard: reopening while queued shows pending, not a
    // second create form.
    await page.click('#odSendCaseClose');
    await openCardMenu(page, caseId);
    await page.locator('#caseActionsMenu [data-action="send_to_od"]').click();
    await page.waitForFunction(() => {
      const s = document.getElementById('odSendCaseStatus');
      return s && s.style.display !== 'none';
    }, null, { timeout: 8000 });
    check('queued case reopens as pending (dup prevented)', await page.$eval('#odSendCaseStatus',
      el => el.className.includes('pending')));
    await page.click('#odSendCaseClose');

    // Drain the real worker: queued event -> real POST /labcases on stub.
    drainWorker();
    const afterSend = await postJson(page, `${BASE}/api/od-send-case.php`, { action: 'check', case_id: caseId });
    check('worker delivered create -> linked', afterSend.json && afterSend.json.state === 'linked',
      JSON.stringify(afterSend.json).slice(0, 200));
    const remoteNum1 = String(afterSend.json && afterSend.json.external_id || '');
    const created = (stubState().labcases || {})[remoteNum1] || null;
    check('stub received a real LabCase POST for this case', !!created, remoteNum1);
    check('created LabCase maps patient/lab/prov correctly',
      !!created && created.PatNum === 13 && created.LaboratoryNum === 1 && created.ProvNum === 1,
      created && JSON.stringify({ p: created.PatNum, l: created.LaboratoryNum, pr: created.ProvNum }));

    // Second case: same patient -> distinct remote LabCase (no collapse).
    const caseId2 = await createCase(page, 'case-two');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(cardSel(caseId2), { timeout: 15000 });
    await openCardMenu(page, caseId2);
    await page.locator('#caseActionsMenu [data-action="send_to_od"]').click();
    // Patient/provider identity mappings were learned by the first send, so
    // their pickers may now be hidden (resolved); only the lab confirm is
    // guaranteed. Wait for the check response, then fill whatever is shown.
    await page.waitForFunction(() => {
      const s = document.getElementById('odSendCaseSummary');
      return s && s.textContent.length > 0;
    }, null, { timeout: 10000 });
    await page.waitForTimeout(300);
    if (await page.$eval('#odSendPatientPicker', el => el.style.display !== 'none')) {
      await page.fill('#odSendPatLName', 'Copay');
      await page.click('#odSendPatSearch');
      await page.waitForSelector('.od-send-candidate', { timeout: 8000 });
      await page.click('.od-send-candidate');
    }
    if (await page.$eval('#odSendProviderPicker', el => el.style.display !== 'none')) {
      await page.selectOption('#odSendProviderSelect', '1');
    }
    await page.selectOption('#odSendLabSelect', '2'); // different lab: per-case choice
    await page.click('#odSendCaseConfirm');
    await page.waitForFunction(() => {
      const s = document.getElementById('odSendCaseStatus');
      return s && s.style.display !== 'none' && s.className.includes('pending');
    }, null, { timeout: 8000 });
    drainWorker();
    await page.click('#odSendCaseClose');
    const afterSend2 = await postJson(page, `${BASE}/api/od-send-case.php`, { action: 'check', case_id: caseId2 });
    const remoteNum2 = String(afterSend2.json && afterSend2.json.external_id || '');
    check('second create produced a DISTINCT remote LabCase',
      remoteNum2 !== '' && remoteNum2 !== remoteNum1, `${remoteNum1} vs ${remoteNum2}`);
    const second = (stubState().labcases || {})[remoteNum2] || null;
    check('per-case lab pick honored (lab 2)', second && second.LaboratoryNum === 2,
      second && JSON.stringify(second.LaboratoryNum));
    // The per-case pick must NOT overwrite the practice default.
    conn = await integrationsList(page);
    check('practice lab default unchanged by per-case pick',
      conn.outbound && String(conn.outbound.laboratory_num) === '1',
      JSON.stringify(conn.outbound && conn.outbound.laboratory_num));

    // Health now shows a real delivered count + last-delivered timestamp.
    await openSettings(page);
    await goToIntegrations(page);
    conn = await integrationsList(page);
    check('health: delivered count >= 1', !!(conn.outbound && conn.outbound.delivered >= 1),
      JSON.stringify(conn.outbound && conn.outbound.delivered));
    check('health: last_delivered_at set', !!(conn.outbound && conn.outbound.last_delivered_at),
      JSON.stringify(conn.outbound && conn.outbound.last_delivered_at));

    // --- Disable / re-enable -------------------------------------------
    await page.click('#integrationOutboundToggle-open_dental');
    await page.waitForSelector('#confirmModal', { state: 'visible', timeout: 5000 });
    check('disable requires confirmation', true);
    await page.click('#confirmModalOk');
    await page.waitForFunction(() => {
      const b = document.getElementById('integrationOutboundState-open_dental');
      return b && b.textContent === window.t('settings.integrations.outbound.state_off');
    }, null, { timeout: 10000 });
    conn = await integrationsList(page);
    check('disable: outbound.enabled false', conn.outbound && conn.outbound.enabled === false);
    check('disable: connection still active (inbound intact)',
      conn.status === 'active' && (conn.sync_enabled === true || conn.sync_enabled === 1));

    // Menu item disappears once create is off.
    await closeSettings(page);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.isPracticeAdmin === true, null, { timeout: 15000 });
    check('pmsSync.odCreateEnabled false after disable', await page.evaluate(() =>
      window.pmsSync && window.pmsSync.odCreateEnabled === false));
    await openCardMenu(page, caseId);
    check('send_to_od item hidden when outbound off',
      (await page.locator('#caseActionsMenu [data-action="send_to_od"]').count()) === 0);
    await page.keyboard.press('Escape');

    // Re-enable is idempotent.
    await openSettings(page);
    await goToIntegrations(page);
    await page.click('#integrationOutboundToggle-open_dental');
    await page.waitForFunction(() => {
      const b = document.getElementById('integrationOutboundState-open_dental');
      return b && b.textContent === window.t('settings.integrations.outbound.state_on');
    }, null, { timeout: 15000 });
    conn = await integrationsList(page);
    check('re-enable idempotent (enabled true again)', conn.outbound && conn.outbound.enabled === true);

    // --- Dark theme + mobile + locale spot checks ----------------------
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    const darkBg = await page.$eval('#integrationOutbound-open_dental',
      el => getComputedStyle(el).backgroundColor);
    check('dark theme styles the outbound block', darkBg !== 'rgba(0, 0, 0, 0)' && darkBg !== 'rgb(255, 255, 255)', darkBg);
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
    await closeSettings(page);

    const lang = await postJson(page, `${BASE}/api/save-user-language.php`, { language: 'de-DE' });
    check('language switch accepted', lang.status === 200 && lang.json && lang.json.success === true,
      JSON.stringify(lang.json));
    await page.reload({ waitUntil: 'domcontentloaded' });
    await openSettings(page);
    await goToIntegrations(page);
    const deTitle = await page.$eval('#integrationOutbound-open_dental .integration-toolbar-title',
      el => el.textContent);
    check('de-DE renders localized outbound title',
      deTitle === 'Zwei-Wege-Synchronisation mit Open Dental', deTitle);
    await closeSettings(page);
    await postJson(page, `${BASE}/api/save-user-language.php`, { language: 'en-US' });

    // Mobile viewport: send dialog still opens and fits.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.isPracticeAdmin === true, null, { timeout: 15000 });
    await page.waitForSelector(cardSel(caseId), { timeout: 15000 });
    await openCardMenu(page, caseId);
    await page.locator('#caseActionsMenu [data-action="send_to_od"]').click();
    await page.waitForSelector('#odSendCaseModal.show', { timeout: 8000 });
    const box = await page.locator('#odSendCaseModal .modal-content').boundingBox();
    check('mobile: send dialog fits 390px viewport', box && box.width <= 390 && box.x >= 0,
      box && JSON.stringify({ w: Math.round(box.width), x: Math.round(box.x) }));

  } catch (err) {
    check('unexpected error', false, String(err && err.stack || err).slice(0, 400));
  } finally {
    // Leave the dev fixture read-only again.
    try {
      await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
      await page.waitForSelector('meta[name="csrf-token"]', { state: 'attached', timeout: 15000 });
      const c = await integrationsList(page);
      if (c && c.outbound && c.outbound.enabled) {
        await integrationsPost(page, { action: 'update_outbound_config', connection_id: c.id, enabled: false });
      }
    } catch (e) {}
    await browser.close();
  }

  const failed = results.filter(r => !r.pass);
  console.log('\n========================================');
  console.log(`Results: ${results.length - failed.length} passed, ${failed.length} failed`);
  console.log('========================================');
  process.exit(failed.length ? 1 : 0);
})();
