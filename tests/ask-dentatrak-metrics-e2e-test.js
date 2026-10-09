// Ask DentaTrak metric verification with controlled fixtures - dev-only.
//   node tests/_ask-metrics-verify.js
//
// Seeds a deterministic fixture via test-helpers seed_ask_metrics, then
// checks tool-layer output and LIVE planner->tool->composer answers
// against hand-computed expectations. Fixture semantics (TODAY = run
// date, all ranges inclusive):
//
//   Open cases (9): O1 due -1d (Alice), O2 due -10d (AskLabOne, remade),
//     O3 due +7d (Bob), O4 no due (Bob), O5 no due (unassigned),
//     O6 due +40d (Alice), M1/M2 due +3d (member, created by member),
//     R1 due -15d (Carol; completed -8d then REOPENED -2d; remade).
//   Completed in [today-30, today] (7): L1 on-time, L2 late+remade,
//     L3 no due, L5 late+archived+2 remakes, LU done+unassigned+no due,
//     BD late (completed exactly at range start), CT late
//     (created directly in terminal stage, no status_changed_at).
//   L4 completed -40d: outside the 30d window (previous period only).
//
//   Expected: overdue share 3/9 = 33.3%; late completions 4/5 = 80%;
//   turnaround avg 11.9d (min 0, max 25); labs AskLabOne 14.0 (2),
//   AskLabTwo 5.0 (1); volume created 15 vs 2 (+650%), delivered 7 vs 1
//   (+600%); remake rate 2/7 = 28.6% (5 events in range); assigned_to
//   3-way tie at 2 (Alice/Bob/member) + scope "open"; created_by leader
//   admin 14 vs member 2; unassigned 1 (O5 only - LU excluded by open
//   default); missing due 2 (O4,O5).

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const SUFFIX = Date.now();
const ADMIN = `ask-m-admin-${SUFFIX}@dentatrak.com`;
const MEMBER = `ask-m-member-${SUFFIX}@dentatrak.com`;
const PASS = 'TestPass123!';

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log((ok ? 'PASS' : 'FAIL') + ': ' + name + (detail ? ' - ' + String(detail).slice(0, 220) : ''));
}
const strip = h => (h || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');

async function helper(request, body) {
  const r = await request.post(BASE + '/api/test-helpers.php', {
    headers: { 'Content-Type': 'application/json' }, data: body
  });
  return r.json().catch(async () => ({ success: false, raw: (await r.text()).slice(0, 200) }));
}

async function ask(request, csrf, query, history) {
  const r = await request.post(BASE + '/api/ask-dentatrak.php', {
    headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
    data: { query, history: history || [] }
  });
  const body = await r.json().catch(() => ({}));
  return { status: r.status(), body, text: strip(body.response) };
}

async function loginCtx(browser) {
  const ctx = await browser.newContext();
  const request = ctx.request;
  return { ctx, request };
}

(async () => {
  const browser = await chromium.launch();

  /* ── Setup: fresh practice + limited member + fixture ───────────── */
  const boot = await loginCtx(browser);
  const setup = await helper(boot.request, { action: 'setup_test_user', email: ADMIN, password: PASS, firstName: 'AskAdmin', lastName: 'Test', practiceName: 'Ask Metrics Practice' });
  check('setup_test_user', setup.success, JSON.stringify(setup).slice(0, 150));

  const login = await boot.request.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: ADMIN, password: PASS }
  });
  check('admin login', login.ok(), (await login.text()).slice(0, 80));

  const page = await boot.ctx.newPage();
  await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  check('csrf', !!csrf);

  // Admin's practice_id: run_ask_tool echoes the resolved practice.
  const probe = await helper(boot.request, { action: 'run_ask_tool', email: ADMIN, tool: 'count_cases' });
  const practiceId = setup.practice_id || probe.practice_id;
  check('practice id resolved', !!practiceId, 'id=' + practiceId);

  const mem = await helper(boot.request, {
    action: 'setup_practice_member', practiceId, email: MEMBER, password: PASS,
    firstName: 'AskMember', lastName: 'Test', role: 'user',
    limitedVisibility: true, canEditCases: true
  });
  check('setup_practice_member (limited)', mem.success, JSON.stringify(mem).slice(0, 120));

  const seed = await helper(boot.request, { action: 'seed_ask_metrics', member_email: MEMBER });
  check('seed_ask_metrics', seed.success, JSON.stringify(seed).slice(0, 150));
  const C = seed.cases || {};
  check('seed returned 17 cases', Object.keys(C).length === 17, Object.keys(C).join(','));

  /* ── Tool layer: expected vs actual ─────────────────────────────── */
  const run = (tool, params, email) => helper(boot.request, {
    action: 'run_ask_tool', email: email || ADMIN, tool, params: params || {}, practice_id: practiceId
  });

  let r = await run('count_cases');
  check('total non-archived = 16', r.data && r.data.count === 16, 'got ' + (r.data && r.data.count));

  r = await run('count_cases', { open_only: true });
  check('open cases = 9', r.data && r.data.count === 9, 'got ' + (r.data && r.data.count));

  r = await run('count_cases', { unassigned: true });
  check('unassigned defaults to open = 1 (LU done excluded)', r.data && r.data.count === 1, 'got ' + (r.data && r.data.count));

  r = await run('count_cases', { unassigned: true, include_archived: true, status: 'x-none' });
  // status filter present => open default bypassed; x-none matches nothing.
  check('unassigned + explicit status bypasses open default', r.data && r.data.count === 0, 'got ' + (r.data && r.data.count));

  r = await run('count_cases', { missing_due_date: true });
  check('missing due defaults to open = 2 (L3,LU excluded)', r.data && r.data.count === 2, 'got ' + (r.data && r.data.count));

  r = await run('case_metrics', { metric: 'open_overdue_share' });
  check('overdue share 3/9 = 33.3%', r.data && r.data.open_cases === 9 && r.data.overdue_cases === 3 && r.data.pct === 33.3,
    JSON.stringify({ o: r.data && r.data.open_cases, v: r.data && r.data.overdue_cases, p: r.data && r.data.pct }));

  const from30 = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
  const today = new Date().toISOString().slice(0, 10);

  r = await run('case_metrics', { metric: 'late_completions', from: from30, to: today });
  check('late completions: cohort 7, with_due 5, late 4 = 80%', r.data &&
    r.data.completed_cases === 7 && r.data.completed_with_due_date === 5 &&
    r.data.completed_late === 4 && r.data.pct === 80,
    JSON.stringify(r.data || {}).slice(0, 220));

  r = await run('case_metrics', { metric: 'turnaround', from: from30, to: today });
  check('turnaround: 7 cases avg 11.9 min 0 max 25', r.data &&
    r.data.completed_cases === 7 && r.data.avg_turnaround_days === 11.9 &&
    r.data.min_days === 0 && r.data.max_days === 25,
    JSON.stringify(r.data || {}).slice(0, 200));

  r = await run('case_metrics', { metric: 'turnaround', from: from30, to: today, group_by: 'lab' });
  const labs = (r.data && r.data.labs) || [];
  const l1 = labs.find(l => /AskLabOne/.test(l.lab));
  const l2 = labs.find(l => /AskLabTwo/.test(l.lab));
  check('lab turnaround: LabOne 14.0 x2, LabTwo 5.0 x1',
    l1 && l1.completed_cases === 2 && l1.avg_turnaround_days === 14 &&
    l2 && l2.completed_cases === 1 && l2.avg_turnaround_days === 5,
    JSON.stringify(labs));

  r = await run('case_metrics', { metric: 'volume', from: from30, to: today });
  check('volume: created 15 vs 2 (+650%), delivered 7 vs 1 (+600%)', r.data &&
    r.data.cases_created === 15 && r.data.prev_cases_created === 2 &&
    r.data.created_change_pct === 650 &&
    r.data.cases_delivered === 7 && r.data.prev_cases_delivered === 1 &&
    r.data.delivered_change_pct === 600,
    JSON.stringify(r.data || {}).slice(0, 260));

  // Zero-denominator period: 60-90d ago (only L4/BD live there).
  const from90 = new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10);
  const from60 = new Date(Date.now() - 60 * 864e5).toISOString().slice(0, 10);
  r = await run('case_metrics', { metric: 'volume', from: from90, to: from60 });
  // [-90,-61] has nothing; prev equal window [-120,-91] empty too -> null pct.
  check('volume: empty period -> null percent change, not 0 or NaN', r.data &&
    r.data.cases_created === 0 && r.data.prev_cases_created === 0 &&
    r.data.created_change_pct === null && r.data.delivered_change_pct === null,
    JSON.stringify(r.data || {}).slice(0, 220));

  r = await run('case_metrics', { metric: 'remake_rate', from: from30, to: today });
  check('remake rate: 2 of 7 completed = 28.6%, 5 events reported', r.data &&
    r.data.completed_cases_in_range === 7 && r.data.completed_cases_with_remake === 2 &&
    r.data.remake_rate_pct === 28.6 && r.data.remake_events === 5,
    JSON.stringify(r.data || {}).slice(0, 240));

  r = await run('aggregate_cases', { group_by: 'assigned_to' });
  const gr = (r.data && r.data.groups) || [];
  const tie = (r.data && r.data.tied_at_top) || [];
  check('assigned_to: open scope + 3-way tie at 2',
    r.data && /open/i.test(r.data.scope || '') && r.data.total_matching === 9 &&
    tie.length === 3 && gr.every(g => g.count <= 2),
    JSON.stringify({ scope: r.data && r.data.scope, tie, groups: gr }).slice(0, 240));
  check('assigned_to: no done cases (Carol=1 from R1 only, LU/L* excluded)',
    !gr.some(g => g.count > 2), JSON.stringify(gr).slice(0, 200));

  r = await run('aggregate_cases', { group_by: 'created_by' });
  const cg = (r.data && r.data.groups) || [];
  check('created_by: admin 14 leads, member 2',
    cg[0] && cg[0].count === 14 && cg.some(g => g.count === 2) && !r.data.tied_at_top,
    JSON.stringify(cg).slice(0, 200));

  r = await run('aggregate_cases', { group_by: 'lab' });
  check('lab aggregate: AskLabOne 1 open case (O2)',
    r.data && (r.data.groups || []).length === 1 && r.data.groups[0].count === 1,
    JSON.stringify(r.data || {}).slice(0, 200));

  r = await run('list_cases', { overdue: true });
  const odIds = ((r.data && r.data.cases) || []).map(c => c.case_id);
  check('overdue list = O1,O2,R1', odIds.length === 3 &&
    [C.O1, C.O2, C.R1].every(id => odIds.includes(id)), JSON.stringify(odIds).slice(0, 200));

  r = await run('list_cases', { due_within_days: 60 });
  check('due next 60d = 4 (O3,O6,M1,M2)', r.data && (r.data.cases || []).length === 4,
    'got ' + ((r.data && r.data.cases) || []).length);

  r = await run('list_cases', { unassigned: true });
  check('unassigned list = O5 only', r.data && (r.data.cases || []).length === 1 &&
    r.data.cases[0].case_id === C.O5, JSON.stringify((r.data && r.data.cases) || []).slice(0, 160));

  // Decrypted patient names surface only for authorized rows.
  check('list_cases returns decrypted patient names',
    r.data && /DentaTrakTest-Ask-O5/.test(r.data.cases[0].patient_name || ''),
    r.data && r.data.cases[0].patient_name);

  /* ── Live planner->composer answers (admin) ─────────────────────── */
  async function live(name, query, assertions, history) {
    const a = await ask(boot.request, csrf, query, history);
    const ok = a.body && a.body.success !== false && !!a.body.response;
    check('live answered: ' + name, ok, a.status + ' ' + strip(a.body.error || '').slice(0, 120));
    if (!ok) return '';
    for (const [label, re] of assertions) {
      check('  ' + name + ': ' + label, re.test(a.text), a.text.slice(0, 220));
    }
    return a.text;
  }

  const osAns = await live('overdue share', 'What percentage of my open cases are overdue?', [
    ['33.3%', /33\.3/], ['3 of 9', /3 of (your )?9|three of/i], ['not 0 of 0', /9/],
  ]);
  check('  overdue share not narrowed to self-scope',
    !/assigned to you/i.test(osAns), osAns.slice(0, 200));

  // Scope validation: bare "my cases" = the whole authorized population
  // (9 open for admin); explicit "assigned to me" = the self-scope (0 for
  // admin - the fixture assigns nothing to the admin's email).
  const myOpen = await live('my open cases = authorized scope', 'How many open cases do I have?', [
    ['9 open', /\b9\b|nine/i],
  ]);
  check('  "my open cases" not reported as zero/none',
    myOpen !== '' && !/no open cases|\b0 open|zero open|don't have any/i.test(myOpen), myOpen.slice(0, 200));
  const meAns = await live('explicit assigned-to-me', 'How many open cases are assigned to me?', [
    ['self-scope zero', /no |none|zero|\b0\b/i], ['mentions assignment', /assign/i],
  ]);
  {
    // Follow-up keeps the established self-scope (never silently widens).
    const a = await ask(boot.request, csrf, 'Are any of them overdue?', [
      { role: 'user', content: 'How many open cases are assigned to me?' },
      { role: 'assistant', content: meAns || 'You have no open cases assigned to you.' },
    ]);
    check('follow-up keeps self-scope (does not report the 3 practice-overdue)',
      a.body && a.body.success !== false && !/\b3\b|three/i.test(a.text), a.text.slice(0, 220));
  }

  await live('late completions', 'What percentage of cases completed in the last 30 days were late?', [
    ['80%', /80/], ['4 of 5', /4 of 5|four of five/i],
  ]);

  {
    const a = await ask(boot.request, csrf, 'Who currently has the most cases assigned?');
    check('live answered: assignee tie', a.body && a.body.success !== false && !!a.body.response);
    check('  tie answer states open workload scope', /open|current/i.test(a.text), a.text.slice(0, 220));
    check('  tie answer names Alice and Bob', /Ask Alice/.test(a.text) && /Ask Bob/.test(a.text), a.text.slice(0, 220));
    check('  tie answer does NOT crown a done-case assignee (Carol)',
      !/Carol[^.]{0,60}(most|leads|top|has \d|assigned \d)/i.test(a.text), a.text.slice(0, 220));
  }

  await live('creator leader', 'Who creates the most cases?', [
    ['admin leads', /AskAdmin|14/],
  ]);

  await live('turnaround', 'What is our average turnaround time over the last 30 days?', [
    ['11.9 days', /11\.9/],
  ]);

  const labAns = await live('turnaround by lab', 'How does our turnaround compare across labs in the last 30 days?', [
    ['mentions AskLabOne', /AskLabOne/i], ['mentions AskLabTwo', /AskLabTwo/i],
  ]);

  // Follow-up: "by lab" after a turnaround question.
  const taAns = await live('turnaround (for follow-up)', 'What is our average turnaround time over the last 30 days?', [[/days|turnaround/i, /./]]);
  await live('follow-up "by lab"', 'Break that down by lab.', [
    ['mentions a lab', /AskLabOne|AskLabTwo/i],
  ], [
    { role: 'user', content: 'What is our average turnaround time over the last 30 days?' },
    { role: 'assistant', content: taAns || 'Average turnaround was 11.9 days.' },
  ]);

  const vol = await live('volume vs previous', 'How many cases were created in the last 30 days compared with the previous period?', [
    ['15 created', /15/], ['previous 2', /\b2\b/],
  ]);

  await live('remake rate', 'What is our remake rate over the last 30 days?', [
    ['2 of 7 or 28.6%', /28\.6|2 of 7/],
  ]);

  // Follow-up: "only this month" narrows a due window.
  const dueAns = await live('due next 60 days', 'Which cases are due in the next 60 days?', [
    ['4 cases', /4|\bO3\b|DentaTrakTest-Ask/],
  ]);
  await live('follow-up "only this month"', 'Only this month.', [
    ['3 cases and not O6', /3|three/i],
  ], [
    { role: 'user', content: 'Which cases are due in the next 60 days?' },
    { role: 'assistant', content: dueAns || '' },
  ]);
  {
    const a = await ask(boot.request, csrf, 'Only this month.', [
      { role: 'user', content: 'Which cases are due in the next 60 days?' },
      { role: 'assistant', content: dueAns || '' },
    ]);
    check('  follow-up "only this month" excludes next-month case O6',
      !/Ask-O6/.test(a.text) && /3|three/i.test(a.text), a.text.slice(0, 200));
  }

  // Follow-up: "show me those cases" preserves population.
  const unAns = await live('unassigned question', 'Which cases are unassigned?', [
    ['one case', /1|one|O5/i],
  ]);
  {
    const a = await ask(boot.request, csrf, 'Show me those cases.', [
      { role: 'user', content: 'Which cases are unassigned?' },
      { role: 'assistant', content: unAns || '' },
    ]);
    check('follow-up "show me those cases" lists O5 and only O5',
      /Ask-O5/.test(a.text) && !/Ask-O1|Ask-O2|Ask-O3|Ask-O4|Ask-O6|Ask-M1|Ask-LU/.test(a.text),
      a.text.slice(0, 240));
  }

  /* ── Product-help answers: admin + restricted member ────────────── */
  const helpChecks = [
    ['add user', 'How do I add a user?',
      [/email/i, /Add User/i, /Save Settings/i]],
    ['record remake', 'How do I record a remake?',
      [/Record Remake/i, /actions?|menu|case/i]],
    ['restore archived', 'How do I restore an archived case?',
      [/View Archived Cases/i, /Restore/i]],
    ['change permissions', 'How do I change a user\'s permissions?',
      [/Settings/i, /admin/i]],
  ];
  for (const [name, q, res] of helpChecks) {
    const a = await ask(boot.request, csrf, q);
    for (const re of res) {
      check(`help (admin) ${name}: ${re}`, re.test(a.text), a.text.slice(0, 160));
    }
  }

  // Member session: same help questions must still state admin limits,
  // and operational answers must be restricted to member-visible cases.
  const mctx = await browser.newContext();
  const mreq = mctx.request;
  const mlogin = await mreq.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: MEMBER, password: PASS }
  });
  check('member login', mlogin.ok());
  // Members land on practice-setup until they pick a practice.
  const sel = await mreq.get(BASE + '/api/select-practice.php?practice_id=' + practiceId);
  const selBody = await sel.json().catch(() => ({}));
  check('member practice selected', selBody.success !== false, JSON.stringify(selBody).slice(0, 100));
  const mpage = await mctx.newPage();
  await mpage.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
  const mcsrf = await mpage.locator('meta[name="csrf-token"]').getAttribute('content');

  const ma = await ask(mreq, mcsrf, 'How do I add a user?');
  check('help (member) add user answered', !!ma.body.response, ma.status + ' ' + JSON.stringify(ma.body).slice(0, 140));
  check('help (member) add user still states admin requirement', /admin/i.test(ma.text), ma.text.slice(0, 200));
  const md = await ask(mreq, mcsrf, 'How many cases are currently unassigned?');
  check('member unassigned answer sees only own scope (0 unassigned for them)',
    !/DentaTrakTest-Ask-O5/.test(md.text), md.text.slice(0, 200));

  // Explicit self-scope for the limited member: they ARE the assignee on
  // M1+M2, so "assigned to me" must stay scoped (2), not widen to 9.
  const mSelf = await ask(mreq, mcsrf, 'How many cases are assigned to me?');
  check('member "assigned to me" sees own 2 cases (self-scope honored)',
    mSelf.body && mSelf.body.success !== false && /2|two/i.test(mSelf.text)
      && !/9|nine/i.test(mSelf.text), mSelf.text.slice(0, 220));

  /* ── Restricted-scope tool checks (run_ask_tool switches the caller's
       session user, so these run AFTER all admin-facing activity) ─── */
  r = await run('get_case_summary', { case_id: C.O1 }, MEMBER);
  check('member cannot summarize unassigned-to-them case',
    !r.data || (r.data.matches || []).length === 0 || r.data.error,
    JSON.stringify(r.data || r).slice(0, 160));

  r = await run('count_cases', {}, MEMBER);
  check('limited member sees only own 2 cases', r.data && r.data.count === 2,
    'got ' + (r.data && r.data.count));

  r = await run('case_metrics', { metric: 'open_overdue_share' }, MEMBER);
  check('member metric scoped: 0/2 overdue', r.data && r.data.open_cases === 2 && r.data.overdue_cases === 0,
    JSON.stringify(r.data || {}).slice(0, 160));

  // A member of ANOTHER practice cannot run tools against this one.
  await helper(boot.request, { action: 'setup_test_user', email: `ask-m-other-${SUFFIX}@dentatrak.com`, password: PASS, firstName: 'Other', lastName: 'User' });
  r = await run('count_cases', {}, `ask-m-other-${SUFFIX}@dentatrak.com`);
  check('cross-practice run_ask_tool denied', r.success === false || r.status === 403 || (r.message || '').match(/not a member/i),
    JSON.stringify(r).slice(0, 140));

  /* ── Cleanup (re-login admin first: session user was switched) ──── */
  await boot.request.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: ADMIN, password: PASS }
  });
  const del = await helper(boot.request, {
    action: 'delete_test_cases', practice_id: practiceId, case_ids: Object.values(C)
  });
  check('cleanup test cases', del.success !== false, JSON.stringify(del).slice(0, 120));

  const failed = results.filter(x => !x.ok).length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
