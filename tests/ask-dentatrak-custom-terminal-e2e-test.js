// Ask DentaTrak custom terminal-stage check - dev-only.
//   node tests/_ask-custom-terminal-verify.js
//
// Renames the practice's FINAL workflow stage to a custom label BEFORE
// seeding, so every "done" fixture lives in a custom terminal stage.
// Metrics must resolve done-ness via the practice's configured terminal
// column, never a hardcoded 'Delivered'.

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const SUFFIX = Date.now();
const ADMIN = `ask-ct-admin-${SUFFIX}@dentatrak.com`;
const PASS = 'TestPass123!';

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok: !!ok });
  console.log((ok ? 'PASS' : 'FAIL') + ': ' + name + (detail ? ' - ' + String(detail).slice(0, 220) : ''));
}

async function helper(request, body) {
  const r = await request.post(BASE + '/api/test-helpers.php', {
    headers: { 'Content-Type': 'application/json' }, data: body
  });
  return r.json().catch(async () => ({ success: false, raw: (await r.text()).slice(0, 200) }));
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const request = ctx.request;

  const setup = await helper(request, { action: 'setup_test_user', email: ADMIN, password: PASS, firstName: 'CT', lastName: 'Admin', practiceName: 'Custom Terminal Practice' });
  check('setup_test_user', setup.success);
  const login = await request.post(BASE + '/api/auth-email.php', {
    data: { action: 'login', email: ADMIN, password: PASS }
  });
  check('login', login.ok());

  // Rename the default terminal stage to a custom label.
  const rn = await helper(request, { action: 'set_workflow_stage_labels', email: ADMIN, overrides: { Delivered: 'QA Passed' } });
  check('terminal stage renamed to "QA Passed"', rn.success, JSON.stringify(rn).slice(0, 160));

  const seed = await helper(request, { action: 'seed_ask_metrics' });
  check('seed_ask_metrics', seed.success, JSON.stringify(seed).slice(0, 120));
  // First/last columns are product-protected: the terminal column is
  // always the last canonical column id ('Delivered'); a rename only
  // changes its display label. Metric done-ness must still resolve.
  check('seed resolves terminal column id (not label)', seed.terminal_status === 'Delivered', seed.terminal_status);
  const C = seed.cases || {};
  const practiceId = seed.practice_id;

  const run = (tool, params) => helper(request, {
    action: 'run_ask_tool', email: ADMIN, tool, params: params || {}, practice_id: practiceId
  });

  const from30 = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
  const today = new Date().toISOString().slice(0, 10);

  let r = await run('count_cases', { open_only: true });
  check('custom terminal: open cases = 9', r.data && r.data.count === 9, 'got ' + (r.data && r.data.count));

  r = await run('case_metrics', { metric: 'late_completions', from: from30, to: today });
  check('custom terminal: cohort 7, late 4/5 = 80%', r.data &&
    r.data.completed_cases === 7 && r.data.completed_late === 4 && r.data.pct === 80,
    JSON.stringify(r.data || {}).slice(0, 220));

  r = await run('case_metrics', { metric: 'turnaround', from: from30, to: today });
  check('custom terminal: avg 11.9 (created-in-terminal CT counted)', r.data &&
    r.data.completed_cases === 7 && r.data.avg_turnaround_days === 11.9 && r.data.min_days === 0,
    JSON.stringify(r.data || {}).slice(0, 200));

  r = await run('case_metrics', { metric: 'remake_rate', from: from30, to: today });
  check('custom terminal: remake rate 2/7 = 28.6%', r.data &&
    r.data.completed_cases_in_range === 7 && r.data.remake_rate_pct === 28.6,
    JSON.stringify(r.data || {}).slice(0, 200));

  const del = await helper(request, { action: 'delete_test_cases', practice_id: practiceId, case_ids: Object.values(C) });
  check('cleanup', del.success !== false);

  const failed = results.filter(x => !x.ok).length;
  console.log(`\n${results.length - failed} passed, ${failed} failed`);
  await browser.close();
  process.exit(failed ? 1 : 0);
})();
