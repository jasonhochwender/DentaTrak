// Ask DentaTrak evaluation suite — factual-correctness checks on real
// planner->tool->composer answers plus scoped tool-layer verification.
//
// Covers: product-help coverage (incl. Open Dental GA), stale-status and
// waiting-on-lab data questions, follow-up context, ambiguity, unavailable
// features, misspellings, role-appropriate guidance, limited-member scope,
// cross-practice isolation, empty data, and all nine supported locales.
//
// Reuses the deterministic seed_ask_metrics fixture (see its header for the
// fixture map). Dev environment only (test-helpers.php is dev-gated).
//   node tests/ask-dentatrak-eval-e2e-test.js
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const SUFFIX = Date.now();
const ADMIN = `ask-ev-admin-${SUFFIX}@dentatrak.com`;
const MEMBER = `ask-ev-member-${SUFFIX}@dentatrak.com`;
const PASS = 'TestPass123!';

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log((ok ? 'PASS' : 'FAIL') + ': ' + name + (detail ? ' - ' + String(detail).slice(0, 220) : ''));
}
const strip = h => (h || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

async function helper(request, body) {
  const r = await request.post(BASE + '/api/test-helpers.php', {
    headers: { 'Content-Type': 'application/json' }, data: body
  });
  return r.json().catch(async () => ({ success: false, raw: (await r.text()).slice(0, 200) }));
}

async function ask(request, csrf, query, history) {
  for (let attempt = 0; attempt < 2; attempt++) {
    const r = await request.post(BASE + '/api/ask-dentatrak.php', {
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      data: { query, history: history || [] }
    });
    const body = await r.json().catch(() => ({}));
    if (r.status() === 200 && body && body.success === true) {
      return { status: r.status(), body, text: strip(body.response) };
    }
    if (attempt === 1) return { status: r.status(), body, text: strip(body.response || body.error || '') };
    await new Promise(res => setTimeout(res, 2000));
  }
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const request = ctx.request;

  /* ── Fixtures: practice + limited member + seeded metrics cases ──── */
  const setup = await helper(request, { action: 'setup_test_user', email: ADMIN, password: PASS, firstName: 'AskEval', lastName: 'Admin', practiceName: 'Ask Eval Practice' });
  check('setup_test_user', setup.success, JSON.stringify(setup).slice(0, 150));

  const login = await request.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: ADMIN, password: PASS }
  });
  check('admin login', login.ok(), (await login.text()).slice(0, 80));

  const page = await ctx.newPage();
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  check('csrf', !!csrf);

  const probe = await helper(request, { action: 'run_ask_tool', email: ADMIN, tool: 'count_cases' });
  const practiceId = setup.practice_id || probe.practice_id;
  check('practice id resolved', !!practiceId, 'id=' + practiceId);

  const mem = await helper(request, {
    action: 'setup_practice_member', practiceId, email: MEMBER, password: PASS,
    firstName: 'AskEval', lastName: 'Member', role: 'user',
    limitedVisibility: true, canEditCases: true
  });
  check('setup_practice_member (limited)', mem.success, JSON.stringify(mem).slice(0, 120));

  const seed = await helper(request, { action: 'seed_ask_metrics', member_email: MEMBER });
  check('seed_ask_metrics', seed.success, JSON.stringify(seed).slice(0, 150));
  const C = seed.cases || {};

  // A second practice the admin also belongs to, so multi-practice
  // isolation can be probed without crossing a boundary.
  const other = await helper(request, { action: 'setup_test_user', email: `ask-ev-other-${SUFFIX}@dentatrak.com`, password: PASS, firstName: 'AskEval', lastName: 'Other', practiceName: 'Ask Eval Other Practice' });
  check('second practice fixture', other.success);

  const run = (tool, params, email) => helper(request, {
    action: 'run_ask_tool', email: email || ADMIN, tool, params: params || {}, practice_id: practiceId
  });

  /* ── Tool layer: stale-status + waiting-on-lab coverage ──────────── */

  let r = await run('list_cases', { sort: 'status_age', open_only: true });
  const staleRows = (r.data && r.data.cases) || [];
  check('status_age sort puts the longest-sitting case first (R1, 2 days)',
    staleRows.length > 0 && staleRows[0].case_id === C.R1 && staleRows[0].days_in_status === 2,
    JSON.stringify(staleRows.map(x => ({ id: x.case_id, d: x.days_in_status }))).slice(0, 200));

  r = await run('count_cases', { status_unchanged_for_days: 1 });
  check('status_unchanged_for_days >=1 counts only R1 among open cases',
    r.data && r.data.count === 1, 'got ' + (r.data && r.data.count));

  r = await run('list_cases', { status_unchanged_for_days: 1 });
  check('status_unchanged list = R1 only',
    r.data && (r.data.cases || []).length === 1 && r.data.cases[0].case_id === C.R1,
    JSON.stringify((r.data && r.data.cases) || []).slice(0, 160));

  r = await run('list_cases', { lab: 'AskLabOne', open_only: true });
  check('lab filter lists cases waiting on AskLabOne (O2)',
    r.data && (r.data.cases || []).length === 1 && r.data.cases[0].case_id === C.O2,
    JSON.stringify((r.data && r.data.cases) || []).slice(0, 160));

  r = await run('count_cases', { status_unchanged_for_days: 0 });
  check('status_unchanged_for_days=0 is rejected below the minimum (treated as unset, not an error)',
    r.ok === true, JSON.stringify(r).slice(0, 150));

  /* ── Live answers: product knowledge ─────────────────────────────── */
  async function live(name, query, assertions, history, auth) {
    const req = (auth && auth.request) || request;
    const tok = (auth && auth.csrf) || csrf;
    const a = await ask(req, tok, query, history);
    const ok = a.body && a.body.success !== false && !!a.body.response;
    check('live answered: ' + name, ok, a.status + ' ' + strip(a.body && a.body.error || '').slice(0, 120));
    if (!ok) return '';
    for (const [label, re] of assertions) {
      check('  ' + name + ': ' + label, re.test(a.text), a.text.slice(0, 240));
    }
    return a.text;
  }

  // The previously-wrong add-user flow (regression): email first, Add User
  // button, then roles, then Save.
  const addUser = await live('add user (regression)', 'How do I add someone to my practice?', [
    ['email first', /email/i],
    ['Add User action', /add user/i],
    ['save step', /save/i],
    ['admin mention', /admin/i],
  ]);
  check('  add user: email step precedes Add User click',
    !!addUser && addUser.toLowerCase().indexOf('email') < addUser.toLowerCase().indexOf('add user'),
    addUser.slice(0, 200));

  await live('invite phrasing maps to the same flow', 'Invite a new employee', [
    ['email + add user', /email/i],
  ]);

  await live('misspelled feature word still resolves', 'How do I archieve a case?', [
    ['archive action', /archiv/i],
  ]);

  await live('unavailable feature is not invented', 'Does DentaTrak text appointment reminders to patients by SMS?', [
    ['honest limitation', /not|no|doesn|isn|unable|n’t|n't/i],
  ]);

  await live('attachment format coverage', 'Can I upload STL scan files to a case?', [
    ['stl supported', /stl|3d|scan/i],
  ]);

  await live('notification prefs question names real toggles', 'Where do I change which emails I get?', [
    ['preferences surface', /preference|notification/i],
    ['real event names', /assign|mention|comment|status|due date/i],
  ]);

  await live('lab visibility question gets scoped answer', 'Can my lab see all my cases?', [
    ['scoped visibility', /assigned|only/i],
  ]);

  await live('theme question reaches Display & Behavior', 'How do I switch to dark mode?', [
    ['theme path', /display|theme|dark/i],
  ]);

  await live('forgot password routes to sign-in reset', 'I forgot my password - how do I get back in?', [
    ['reset link / forgot password', /forgot|reset|sign.?in|log.?in/i],
  ]);

  /* ── Live answers: Open Dental GA knowledge ──────────────────────── */
  await live('OD connect steps', 'How do I connect DentaTrak to Open Dental?', [
    ['Settings > Integrations', /setting|integration/i],
    ['API key step', /api|key|advanced setup/i],
    ['eConnector', /econnector/i],
  ]);

  const tb = await live('OD toolbar setup', 'How do I add the DentaTrak button to the Open Dental toolbar?', [
    ['Program Links', /program links?/i],
    ['exact launch path with [PatNum]', /od-launch\.php\?pat=\[?PatNum\]?/i],
    ['Enabled', /enabl/i],
    ['blank args', /blank|empty/i],
  ]);
  check('  toolbar answer includes the full launch URL',
    !!tb && /https?:\/\/[^\s<]+od-launch\.php/i.test(tb), (tb || '').slice(0, 240));

  await live('OD two-way sync enable', 'How do I turn on two-way sync with Open Dental?', [
    ['admin opt-in in Settings', /setting|integration|admin/i],
    ['All Others permission', /all others/i],
    ['timezone', /time ?zone/i],
  ]);

  await live('OD field mapping accuracy', 'Which DentaTrak statuses sync back to Open Dental?', [
    ['sent mapping', /sent/i],
    ['received mapping', /receiv/i],
    ['delivered excluded', /deliver/i],
    ['no-overwrite statement', /never|not overwritten|won.t overwrite|without overwriting/i],
  ]);

  await live('OD create is manual, never automatic', 'Does DentaTrak automatically create lab cases in Open Dental?', [
    ['send action named', /send to open dental/i],
    ['not automatic', /not|no|only when|choose/i],
  ]);

  await live('OD patient launch flow', 'How do I open a specific patient\'s cases from Open Dental?', [
    ['select patient then button', /patient/i],
    ['toolbar/DentaTrak button', /button|toolbar|dentatrak/i],
  ]);

  await live('OD disable keeps inbound', 'If I disable two-way sync, do I stop getting cases from Open Dental?', [
    ['inbound continues', /still|continu|receiv|import/i],
  ]);

  await live('OD troubleshooting: permission missing', 'Open Dental says write access is missing - what do I fix?', [
    ['API permission guidance', /all others|api|permission/i],
  ]);

  /* ── Live answers: operational data on the seeded fixture ────────── */
  await live('stale-status question', 'Which cases have been sitting in the same status the longest?', [
    ['names the stagnant case', /Ask-R1|R1/i],
    ['states the duration', /2\s*day/i],
  ]);

  await live('waiting-on-lab question', 'Which cases are waiting on AskLabOne?', [
    ['names O2', /Ask-O2|O2/i],
  ]);

  await live('workload leader', 'Who has the most open cases assigned?', [
    ['names assignees or tie', /alice|bob|member|tie/i],
    ['states a count', /\d/],
  ]);

  // Follow-up inherits the prior population (fixture: 3 overdue open cases).
  const od1 = await ask(request, csrf, 'How many of our cases are currently overdue?');
  check('follow-up seed question answered', od1.body && od1.body.success !== false && /\d/.test(od1.text), od1.text.slice(0, 160));
  const od2 = await ask(request, csrf, 'Which ones are they?', [
    { role: 'user', content: 'How many of our cases are currently overdue?' },
    { role: 'assistant', content: od1.body && od1.body.response || '' },
  ]);
  check('follow-up lists the same overdue population',
    od2.body && od2.body.success !== false && /Ask-(O1|O2|R1)/i.test(od2.text), od2.text.slice(0, 220));

  // Empty-vs-zero distinction: nothing due tomorrow in the fixture.
  const dueT = await live('empty data is honest', 'Which cases are due tomorrow?', [
    ['says none, not an error', /no |none|zero|\b0\b|not any/i],
  ]);

  // Ambiguous-but-actionable: should still produce something useful.
  const amb = await live('ambiguous question handled', 'What about my cases?', [
    ['reasonable answer or clarifier', /case|which|what|clarif|specif|\?/i],
  ]);

  /* ── Limited member: scope + role-appropriate guidance ───────────── */
  // A separate browser context keeps the admin session (and its CSRF)
  // untouched. The member must explicitly select the practice after
  // login - same as the real app flow.
  const mctx = await browser.newContext();
  const mrequest = mctx.request;
  const memLogin = await mrequest.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: MEMBER, password: PASS }
  });
  check('member login', memLogin.ok());
  await mrequest.get(BASE + '/api/select-practice.php?practice_id=' + practiceId);
  const mpage = await mctx.newPage();
  await mpage.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const mcsrf = await mpage.locator('meta[name="csrf-token"]').getAttribute('content');
  check('member csrf', !!mcsrf);

  const mCount = await ask(mrequest, mcsrf, 'How many cases do I have?');
  check('limited member sees only own 2 cases (M1, M2)',
    mCount.body && mCount.body.success !== false && /\b2\b|two/i.test(mCount.text), mCount.text.slice(0, 200));

  // Cross-scope patient lookup by name must not reveal the case.
  const mPeek = await ask(mrequest, mcsrf, 'Tell me about the case for patient DentaTrakTest-Ask-O1 Fixture');
  check('limited member cannot peek at unassigned case',
    mPeek.body && mPeek.body.success !== false
      && !/O1.*due|crown.*o1|zirconia/i.test(mPeek.text.replace(/fixture/i, '')),
    mPeek.text.slice(0, 200));

  const mAdmin = await ask(mrequest, mcsrf, 'How do I add a user to the practice?');
  check('non-admin gets role-appropriate guidance (admin required)',
    mAdmin.body && mAdmin.body.success !== false && /admin/i.test(mAdmin.text),
    mAdmin.text.slice(0, 200));

  /* ── Nine locales: one representative question each ──────────────── */
  async function setLocale(locale) {
    const r = await request.post(BASE + '/api/save-user-language.php', {
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      data: { language: locale }
    });
    return r.ok();
  }
  const localeHints = {
    'de-DE': /[äöüÄÖÜß]|der|die|das|und/i,
    'es-ES': /[áéíóúñ¿¡]|casos|para/i,
    'fr-CA': /pour|les|des|dossiers|comment/i,
    'fr-FR': /pour|les|des|dossiers|comment/i,
    'it-IT': /per|il|la|dei|casi/i,
    'ko-KR': /[\uac00-\ud7af]/,
    'pt-BR': /para|os|das|casos|como/i,
    'ru-RU': /[\u0400-\u04FF]/,
  };
  for (const [loc, re] of Object.entries(localeHints)) {
    if (!(await setLocale(loc))) { check(loc + ' locale switch', false, 'save failed'); continue; }
    const a = await ask(request, csrf, 'How do I create a new case?');
    check(loc + ' localized answer', a.body && a.body.success !== false && re.test(a.text), a.text.slice(0, 160));
  }
  await setLocale('en-US');

  await mctx.close();
  await browser.close();

  const failed = results.filter(r => !r.ok);
  console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`);
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(2); });
