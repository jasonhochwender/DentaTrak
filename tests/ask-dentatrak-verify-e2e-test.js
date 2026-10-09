// Ask DentaTrak expansion verification - dev-only.
//   node tests/_ask-verify.js
//
// Layers:
//  1. Product-guide corpus (get_ask_help_text): the exact instructions
//     embedded in the planner prompt, checked against verified UI labels
//     and the corrected add-user order.
//  2. Data tools (run_ask_tool): real tool output for the new
//     filters/aggregates/metrics against the logged-in user's practice.
//  3. Live endpoint (api/ask-dentatrak.php): representative questions
//     through the real planner->tool->composer flow.
//  4. Case deep link: main.php?case=<id> opens the case.
//
// Live-model assertions check for required facts/labels, not exact
// wording (model output is nondeterministic).
const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log((ok ? 'PASS' : 'FAIL') + ': ' + name + (detail ? ' - ' + String(detail).slice(0, 200) : ''));
}

async function helper(request, body) {
  const r = await request.post(BASE + '/api/test-helpers.php', {
    headers: { 'Content-Type': 'application/json' },
    data: body
  });
  return r.json().catch(async () => ({ success: false, raw: (await r.text()).slice(0, 200) }));
}

async function ask(request, csrf, query, extra = {}) {
  const r = await request.post(BASE + '/api/ask-dentatrak.php', {
    headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
    data: { query, ...extra }
  });
  return { status: r.status(), body: await r.json().catch(() => ({})) };
}

const strip = h => (h || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const request = ctx.request;

  const login = await request.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: EMAIL, password: PASSWORD }
  });
  const loginBody = await login.json().catch(() => ({}));
  check('login succeeds', login.ok() && loginBody.success !== false);

  const page = await ctx.newPage();
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  check('CSRF token available', !!csrf);

  /* ── 1. Product-guide corpus ─────────────────────────────────────── */
  const help = await helper(request, { action: 'get_ask_help_text', email: EMAIL });
  check('help corpus returned', help.success && typeof help.helpText === 'string' && help.helpText.length > 500);
  const ht = help.helpText || '';

  // Corrected add-user sequence: email -> Add User -> roles -> Save Settings.
  const iEmail = ht.indexOf('Type the person');
  const iAdd = ht.indexOf('Click "Add User"');
  const iRoles = ht.indexOf('access checkboxes');
  const iSave = ht.indexOf('Click "Save Settings"');
  check('add-user order: email, then Add User, then roles, then Save Settings',
    iEmail > -1 && iAdd > iEmail && iRoles > iAdd && iSave > iRoles,
    `email@${iEmail} add@${iAdd} roles@${iRoles} save@${iSave}`);
  check('add-user states invite is sent on save', /invitation email/.test(ht));
  check('old incorrect order gone (no "Click Add User and enter")',
    !/Click "Add User" and enter/.test(ht));

  // Verified labels present in the corpus.
  const labels = ['Filter & Sort', 'View Archived Cases', 'Workflow Columns',
    'Notification Preferences', 'Move to', 'Record Remake', 'Save Settings',
    'Full', 'Compact', 'List', 'Track package', 'Shared Assignment Labels',
    'Data & Privacy', 'Practice Users & Roles'];
  for (const l of labels) {
    check(`corpus names real control "${l}"`, ht.includes(l));
  }
  check('corpus notes Settings is admin-only', /admin/i.test(ht) && /ONLY for practice administrators/i.test(ht));
  check('corpus notes desktop/tablet requirement', /desktop or tablet/i.test(ht));
  check('corpus lists supported deep links', ht.includes('main.php?case=<case_id>'));

  /* ── 2. Tool layer (no AI call) ──────────────────────────────────── */
  const run = (tool, params) => helper(request, { action: 'run_ask_tool', email: EMAIL, tool, params: params || {} });

  const total = await run('count_cases');
  check('count_cases works', total.success && total.ok && typeof total.data.count === 'number', JSON.stringify(total.data || total).slice(0, 120));
  const N = total.data ? total.data.count : 0;

  const openOnly = await run('count_cases', { open_only: true });
  check('open_only <= total', openOnly.ok && openOnly.data.count <= N);

  const noDue = await run('count_cases', { missing_due_date: true });
  check('missing_due_date <= total', noDue.ok && noDue.data.count <= N);

  const unassigned = await run('count_cases', { unassigned: true });
  check('unassigned <= total', unassigned.ok && unassigned.data.count <= N);

  const stale = await run('count_cases', { not_updated_within_days: 365 });
  check('not_updated_within_days bounded', stale.ok && stale.data.count <= N);

  const completed = await run('count_cases', { completed_from: '2020-01-01', completed_to: '2100-01-01' });
  check('completed_from/to filter executes', completed.ok && typeof completed.data.count === 'number');

  const aggA = await run('aggregate_cases', { group_by: 'assigned_to' });
  const aggSum = (aggA.data.groups || []).reduce((s, g) => s + g.count, 0);
  check('aggregate assigned_to totals = active case count', aggA.ok && aggSum === aggA.data.total_matching);

  const aggC = await run('aggregate_cases', { group_by: 'created_by' });
  check('aggregate created_by runs', aggC.ok && Array.isArray(aggC.data.groups));

  const aggT = await run('aggregate_cases', { group_by: 'case_type' });
  check('aggregate case_type runs', aggT.ok && aggT.data.total_matching >= 0);

  const badAgg = await run('aggregate_cases', { group_by: 'password' });
  check('aggregate rejects bad group_by', badAgg.ok && badAgg.data.error === 'invalid_group_by');

  for (const m of ['open_overdue_share', 'late_completions', 'turnaround', 'volume', 'remake_rate']) {
    const r = await run('case_metrics', { metric: m });
    check(`case_metrics ${m} returns definition`, r.ok && r.data && typeof r.data.definition === 'string' && r.data.definition.length > 20,
      JSON.stringify(r.data || r).slice(0, 140));
  }

  const oos = await run('case_metrics', { metric: 'open_overdue_share' });
  check('open_overdue_share gives numerator/denominator', oos.ok && typeof oos.data.overdue_cases === 'number' && typeof oos.data.open_cases === 'number');

  const list = await run('list_cases', { limit: 5 });
  const noCipher = (list.data.cases || []).every(c => !/^[A-Za-z0-9+/]{30,}={0,2}$/.test((c.patient_name || '').trim()));
  check('list_cases returns decrypted names + linkable case_ids',
    list.ok && noCipher && (list.data.cases || []).every(c => /^[A-Za-z0-9_-]{1,64}$/.test(c.case_id)),
    JSON.stringify((list.data.cases || [])[0] || {}).slice(0, 160));

  const sorted = await run('list_cases', { limit: 5, sort: 'updated', sort_order: 'desc' });
  check('list_cases sort=updated accepted', sorted.ok && sorted.data.sorted_by === 'updated');

  // ── 3. Deep link: main.php?case=<id> opens the case ────────────────
  const firstId = list.data.cases && list.data.cases[0] && list.data.cases[0].case_id;
  if (firstId) {
    await page.goto(BASE + '/main.php?case=' + encodeURIComponent(firstId), { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#createCaseModal', { state: 'visible', timeout: 15000 }).catch(() => {});
    const title = await page.locator('#createCaseModal .modal-title').textContent().catch(() => '');
    check('?case= deep link opens the case modal', /view case|edit case|loading/i.test(title || ''), (title || '').trim());
    await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  } else {
    check('?case= deep link opens the case modal', false, 'no case id available');
  }

  /* ── 4. Live endpoint answers ────────────────────────────────────── */
  const q1 = await ask(request, csrf, 'How do I add a user to my practice?');
  const q1t = strip(q1.body.response).toLowerCase();
  const pos = (s) => q1t.indexOf(s);
  check('live: add-user answer mentions email, Add User, and Save Settings',
    q1.status === 200 && q1.body.success &&
    pos('email') > -1 && pos('add user') > -1 && pos('save settings') > -1 &&
    pos('email') < pos('add user'),
    strip(q1.body.response).slice(0, 220));

  const q2 = await ask(request, csrf, 'How do I archive a case?');
  check('live: archive answer uses actions menu + Archive',
    q2.status === 200 && q2.body.success && /archive/i.test(strip(q2.body.response)) &&
    /action|menu|⋮|dot/i.test(strip(q2.body.response)),
    strip(q2.body.response).slice(0, 200));

  const q3 = await ask(request, csrf, 'Who currently has the most cases assigned?');
  check('live: workload question returns an answer with a count or name',
    q3.status === 200 && q3.body.success && /\d/.test(strip(q3.body.response)),
    strip(q3.body.response).slice(0, 200));

  const q4 = await ask(request, csrf, 'What percentage of my open cases are overdue?');
  const q4t = strip(q4.body.response);
  check('live: overdue share gives a percentage with counts',
    q4.status === 200 && q4.body.success && /%|percent/i.test(q4t) && /\d/.test(q4t),
    q4t.slice(0, 220));

  // Follow-up retains context: establish topic, then narrow it.
  const q5a = await ask(request, csrf, 'How many cases do I have?');
  const q5b = await ask(request, csrf, 'And how many are unassigned?', {
    history: [
      { role: 'user', content: 'How many cases do I have?' },
      { role: 'assistant', content: strip(q5a.body.response) },
    ]
  });
  check('live: follow-up "unassigned" narrows the previous count',
    q5b.status === 200 && q5b.body.success && /\d/.test(strip(q5b.body.response)),
    strip(q5b.body.response).slice(0, 200));

  const q6 = await ask(request, csrf, 'Which cases are due in the next 30 days? Show me them.');
  check('live: case list renders (may include main.php?case= links)',
    q6.status === 200 && q6.body.success && /<li|<p/i.test(q6.body.response || ''),
    strip(q6.body.response).slice(0, 200));

  // ── Summary ─────────────────────────────────────────────────────────
  const failed = results.filter(r => !r.ok);
  console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`);
  await browser.close();
  process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(1); });
