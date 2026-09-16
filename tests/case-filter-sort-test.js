'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/case-filter-sort.js'), 'utf8');
const sandbox = {
  Intl, URLSearchParams, Set, Map, WeakMap, Date, Number, AbortController, console, setTimeout, clearTimeout,
  document: { documentElement: { lang: 'en-US' }, getElementById: () => null, addEventListener() {}, querySelectorAll: () => [] },
};
sandbox.window = sandbox;
vm.runInNewContext(source, sandbox);
const model = sandbox.caseFilterSort;
let count = 0;
function check(name, run) { run(); count++; console.log('PASS ' + name); }
const sort = (...pairs) => pairs.map(([field, direction]) => ({ field, direction }));
function order(cases, criteria) { return cases.slice().sort((a, b) => model.compare(a, b, criteria)).map(c => c.id); }
const cases = [
  { id: '3', caseType: 'Crown', assignedTo: 'zoe@example.test', dueDate: '2027-01-01' },
  { id: '2', caseType: 'Crown', assignedTo: 'amy@example.test', dueDate: '2027-02-01' },
  { id: '1', caseType: 'Crown', assignedTo: 'amy@example.test', dueDate: '2027-01-01' },
  { id: '4', caseType: 'Bridge', assignedTo: null, dueDate: '' },
];
check('one criterion and stable ID ties', () => assert.deepEqual(order(cases, sort(['type', 'asc'])), ['4', '1', '2', '3']));
check('two criteria priority', () => assert.deepEqual(order(cases, sort(['type', 'asc'], ['assigned', 'desc'])), ['4', '3', '1', '2']));
check('three criteria priority', () => assert.deepEqual(order(cases, sort(['type', 'desc'], ['assigned', 'asc'], ['due', 'desc'])), ['2', '1', '3', '4']));
for (const field of ['type', 'assigned', 'patient', 'dentist', 'due', 'appointment', 'updated']) {
  for (const direction of ['asc', 'desc']) {
    check(field + ' missing last ' + direction, () => {
      const present = { id: '1', caseType: 'Bridge', assignedTo: 'amy@example.test', patientFirstName: 'Amy', dentistName: 'Dr Amy', dueDate: '2027-01-01', patientAppointmentDate: '2027-01-01', lastUpdateDate: '2027-01-01T00:00:00Z' };
      assert.deepEqual(order([{ id: '0' }, present], sort([field, direction])), ['1', '0']);
    });
  }
}
check('dates compare actual instants with timezone offsets', () => assert.deepEqual(order([
  { id: '1', lastUpdateDate: '2027-01-01T01:00:00+03:00' },
  { id: '2', lastUpdateDate: '2026-12-31T23:00:00Z' },
], sort(['updated', 'asc'])), ['1', '2']));
check('invalid dates last descending', () => assert.deepEqual(order([{ id: '1', dueDate: 'invalid' }, { id: '2', dueDate: '2027-01-01' }], sort(['due', 'desc'])), ['2', '1']));
check('multi-assignee deterministic displayed-name ordering', () => assert.equal(model.assignedDisplay(['zoe@example.test', 'amy@example.test']), 'amy, zoe'));
check('stable tie independent of input ordering', () => assert.deepEqual(order(cases.slice().reverse(), sort(['type', 'asc'])), order(cases, sort(['type', 'asc']))));
check('stale load tokens rejected', () => { const first = model.nextRequest(); const second = model.nextRequest(); assert.equal(model.currentRequest(first), false); assert.equal(model.currentRequest(second), true); sandbox.switchPracticeInProgress = true; assert.equal(model.currentRequest(second), false); });
(async function () {
  sandbox.document.body = { classList: { contains: () => false } };
  sandbox.document.querySelector = () => null;
  sandbox.CustomEvent = function () {};
  sandbox.dispatchEvent = () => {};
  const requests = [];
  sandbox.fetch = (url, options) => new Promise(resolve => requests.push({ resolve, data: JSON.parse(options.body) }));
  model.setSort([{ field: 'type', direction: 'asc' }]);
  const first = model.flush();
  model.setSort([{ field: 'due', direction: 'desc' }]);
  const second = model.flush();
  check('concurrent flush callers await the same drain', () => assert.equal(first, second));
  let finished = false;
  second.then(() => { finished = true; });
  requests[0].resolve({ ok: true, json: async () => ({ success: true }) });
  await new Promise(resolve => setImmediate(resolve));
  check('flush waits for newer queued save', () => { assert.equal(finished, false); assert.equal(requests.length, 2); assert.equal(requests[1].data.preferences.sort[0].field, 'due'); });
  requests[1].resolve({ ok: true, json: async () => ({ success: true }) });
  assert.equal(await second, true);
  console.log(count + ' comparator/state checks passed (mock DOM/network, no browser or database).');
})().catch(e => { console.error(e); process.exitCode = 1; });
