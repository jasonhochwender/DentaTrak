<?php
/**
 * Workflow draft JS logic tests.
 *
 * These tests exercise WorkflowDraft.isDefaultWorkflow and resetToDefaults
 * in a headless Node context with no database.
 */

$js = <<<'JS'
global.window = global;
global.t = function (k, p) { return k; };
require('./js/workflow-draft.js');

var M = global.WorkflowDraft;
var tests = [];
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

function makeColumn(id, label, pos) {
  return {
    id: id,
    label: label || id,
    display: label || id,
    position: pos,
    colorKey: 0,
    archived: false,
    isFirst: false,
    isLast: false,
    isNew: false,
    tempId: null
  };
}

var DEFAULTS = M.DEFAULT_COLUMN_ORDER;

// Default state
var defaultSnapshot = {
  fingerprint: '',
  active: DEFAULTS.map(function (id, i) { return makeColumn(id, id, i); }),
  archived: []
};
var defaultDraft = M.createDraft(defaultSnapshot);
assert('Default workflow detected', M.isDefaultWorkflow(defaultDraft) === true);

// Renamed canonical column
var renamedSnapshot = {
  fingerprint: '',
  active: DEFAULTS.map(function (id, i) { return makeColumn(id, id === 'Designed' ? 'Custom Name' : id, i); }),
  archived: []
};
assert('Renamed canonical column is non-default', M.isDefaultWorkflow(M.createDraft(renamedSnapshot)) === false);

// Reordered columns
var reorderedSnapshot = {
  fingerprint: '',
  active: [
    makeColumn('Originated', 'Originated', 0),
    makeColumn('Designed', 'Designed', 1),
    makeColumn('Sent To External Lab', 'Sent To External Lab', 2),
    makeColumn('Manufactured', 'Manufactured', 3),
    makeColumn('Received From External Lab', 'Received From External Lab', 4),
    makeColumn('Delivered', 'Delivered', 5)
  ],
  archived: []
};
assert('Reordered columns are non-default', M.isDefaultWorkflow(M.createDraft(reorderedSnapshot)) === false);

// Active custom column
var customActiveSnapshot = {
  fingerprint: '',
  active: DEFAULTS.map(function (id, i) { return makeColumn(id, id, i); }).concat(makeColumn('Custom-123', 'Review', 6)),
  archived: []
};
assert('Active custom column is non-default', M.isDefaultWorkflow(M.createDraft(customActiveSnapshot)) === false);

// Archived canonical column (mixed canonical active/archived)
var canonicalArchivedSnapshot = {
  fingerprint: '',
  active: DEFAULTS.filter(function (id) { return id !== 'Designed'; }).map(function (id, i) { return makeColumn(id, id, i); }),
  archived: [makeColumn('Designed', 'Designed', 0)]
};
assert('Canonical archived but active non-default', M.isDefaultWorkflow(M.createDraft(canonicalArchivedSnapshot)) === false);

// Custom archived only
var customArchivedSnapshot = {
  fingerprint: '',
  active: DEFAULTS.map(function (id, i) { return makeColumn(id, id, i); }),
  archived: [makeColumn('Custom-456', 'Old Review', 0)]
};
assert('Custom archived only is still default', M.isDefaultWorkflow(M.createDraft(customArchivedSnapshot)) === true);

// Reset from a mixed canonical archived/custom state.
var mixedActive = [
  makeColumn('Originated', 'Originated', 0),
  makeColumn('Sent To External Lab', 'Sent To External Lab', 1),
  makeColumn('Received From External Lab', 'Received From External Lab', 2),
  makeColumn('Delivered', 'Delivered', 3),
  makeColumn('Custom-999', 'QA', 4)
];
var mixedArchived = [
  makeColumn('Designed', 'Designed', 0),
  makeColumn('Manufactured', 'Manufactured', 0),
  makeColumn('Custom-Old', 'Old Review', 0)
];
var mixedDraft = M.createDraft({ fingerprint: '', active: mixedActive, archived: mixedArchived });
var resetResult = M.resetToDefaults(mixedDraft, 0, null);
assert('Reset result is successful', resetResult.success === true);
assert('Reset has 6 active columns', resetResult.draft.active.length === 6);
assert('Reset active columns are in default order', resetResult.draft.active.every(function (c, i) { return c.id === DEFAULTS[i]; }));
assert('Reset reactivates canonical from archived', resetResult.draft.active.some(function (c) { return c.id === 'Designed'; }) === true);
assert('No canonical column remains archived', resetResult.draft.archived.every(function (c) { return !M.isCanonical(c.id); }) === true);
assert('Active custom column is archived', resetResult.draft.archived.some(function (c) { return c.id === 'Custom-999'; }) === true);
assert('Pre-existing archived custom remains', resetResult.draft.archived.some(function (c) { return c.id === 'Custom-Old'; }) === true);
assert('No active custom column', resetResult.draft.active.every(function (c) { return M.isCanonical(c.id); }) === true);
assert('No duplicate IDs', (function () {
  var seen = {};
  var all = resetResult.draft.active.concat(resetResult.draft.archived);
  for (var i = 0; i < all.length; i++) {
    if (seen[all[i].id]) return false;
    seen[all[i].id] = true;
  }
  return true;
})() === true);

// Exact scenario from requirements: two canonical archived, two custom active, reset with destination.
var reqActive = [
  makeColumn('Originated', 'Originated', 0),
  makeColumn('Sent To External Lab', 'Sent To External Lab', 1),
  makeColumn('Received From External Lab', 'Received From External Lab', 2),
  makeColumn('Delivered', 'Delivered', 3),
  makeColumn('Custom-A', 'Custom A', 4),
  makeColumn('Custom-B', 'Custom B', 5)
];
var reqArchived = [
  makeColumn('Designed', 'Designed', 0, true),
  makeColumn('Manufactured', 'Manufactured', 0, true)
];
var reqDraft = M.createDraft({ fingerprint: '', active: reqActive, archived: reqArchived });
var reqReset = M.resetToDefaults(reqDraft, 2, 'Originated');
assert('Requirement reset succeeds', reqReset.success === true);
assert('Requirement reset has 6 active', reqReset.draft.active.length === 6);
assert('Requirement reset active are all canonical', reqReset.draft.active.every(function (c, i) { return c.id === DEFAULTS[i]; }));
assert('Requirement reactivates Designed', reqReset.draft.active[2].id === 'Designed');
assert('Requirement reactivates Manufactured', reqReset.draft.active[3].id === 'Manufactured');
assert('Requirement archives active custom', reqReset.draft.archived.some(function (c) { return c.id === 'Custom-A' || c.id === 'Custom-B'; }) === true);
assert('Requirement no duplicate IDs', (function () {
  var seen = {};
  var all = reqReset.draft.active.concat(reqReset.draft.archived);
  for (var i = 0; i < all.length; i++) {
    if (seen[all[i].id]) return false;
    seen[all[i].id] = true;
  }
  return true;
})() === true);

var payload = M.serializeForSave(reqReset.draft);
assert('Requirement serialized active count is 6', payload.active.length === 6);
assert('Requirement serialized active are canonical', payload.active.every(function (c, i) { return c.id === DEFAULTS[i]; }));
assert('Requirement serialized resetDestination is canonical', payload.resetDestination === 'Originated');
assert('Requirement resetDestination is in active', payload.active.some(function (c) { return c.id === payload.resetDestination; }) === true);

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
JS;

$tmp = __DIR__ . '/../tmp_workflow_reset_test.js';
file_put_contents($tmp, $js);
$exit = 0;
passthru('node ' . escapeshellarg($tmp), $exit);
unlink($tmp);
exit($exit);
