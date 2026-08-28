<?php
/**
 * Workflow draft Restore logic tests.
 */

$js = <<<'JS'
global.window = global;
global.t = function (k, p) { return k; };
require('./js/workflow-draft.js');

var M = global.WorkflowDraft;
var passed = 0;
var failed = 0;

function assert(name, condition) {
  if (condition) {
    passed++;
    console.log('PASS: ' + name);
  } else {
    failed++;
    console.log('FAIL: ' + name);
  }
}

function makeColumn(id, label, pos, archived) {
  return {
    id: id,
    label: label || id,
    display: label || id,
    position: pos,
    colorKey: 0,
    archived: !!archived,
    isFirst: false,
    isLast: false,
    isNew: false,
    tempId: null
  };
}

var DEFAULTS = M.DEFAULT_COLUMN_ORDER;
var active = [
  makeColumn('Originated', 'Originated', 0),
  makeColumn('Sent To External Lab', 'Sent To External Lab', 1),
  makeColumn('Designed', 'Designed', 2),
  makeColumn('Manufactured', 'Manufactured', 3),
  makeColumn('Received From External Lab', 'Received From External Lab', 4),
  makeColumn('Delivered', 'Delivered', 5)
];
var archived = [makeColumn('Custom-Arc', 'Archived Custom', 0, true)];
var draft = M.createDraft({ fingerprint: '', active: active, archived: archived });

var res = M.restoreColumn(draft, 'Custom-Arc');
assert('Restore succeeds', res.success === true);
assert('Active count is 7', res.draft.active.length === 7);
assert('Restored column is before Delivered', res.draft.active[5].id === 'Custom-Arc');
assert('Delivered is still last', res.draft.active[6].id === 'Delivered');
assert('Archived count is 0', res.draft.archived.length === 0);

// Duplicate name
var draft2 = M.createDraft({
  fingerprint: '',
  active: active,
  archived: [makeColumn('Dup', 'Designed', 0, true)]
});
var res2 = M.restoreColumn(draft2, 'Dup');
assert('Restore blocked by duplicate name', res2.success === false);

// Max active
var bigActive = [];
for (var i = 0; i < 10; i++) {
  bigActive.push(makeColumn('Col-' + i, 'Col ' + i, i));
}
var draft3 = M.createDraft({ fingerprint: '', active: bigActive, archived: [makeColumn('Arc', 'Archived', 0, true)] });
var res3 = M.restoreColumn(draft3, 'Arc');
assert('Restore blocked by max active', res3.success === false);

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
JS;

$tmp = __DIR__ . '/../tmp_workflow_restore_test.js';
file_put_contents($tmp, $js);
$exit = 0;
passthru('node ' . escapeshellarg($tmp), $exit);
unlink($tmp);
exit($exit);
