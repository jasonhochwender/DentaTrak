<?php
/**
 * Runtime test: initWorkflowColumnsManager executes without throwing.
 *
 * This test loads js/workflow-draft-ui.js in a minimal Node/DOM-like context
 * and actually calls initWorkflowColumnsManager multiple times. It fails on
 * any ReferenceError or runtime exception during initialization.
 */

$js = <<<'JS'
global.window = global;
global.document = {
  getElementById: function (id) {
    // Return a generic element stub that satisfies the manager's usage.
    return {
      id: id,
      style: {},
      dataset: {},
      innerHTML: '',
      textContent: '',
      hidden: false,
      addEventListener: function () {},
      insertAdjacentHTML: function () {},
      appendChild: function () {},
      querySelectorAll: function () { return []; },
      closest: function () { return null; },
      focus: function () {},
      scrollIntoView: function () {},
      setAttribute: function () {},
      getAttribute: function () { return ''; },
      remove: function () {}
    };
  },
  querySelector: function (sel) {
    if (sel === 'meta[name="csrf-token"]') return { getAttribute: function () { return 'token'; } };
    return null;
  },
  querySelectorAll: function () { return []; }
};
global.isPracticeAdmin = true;
global.currentPracticeId = 1234;
global.t = function (k, p) { return k; };
// Load the draft module; it exposes window.WorkflowDraft.
require('./js/workflow-draft.js');

// Load the UI module. Any runtime error here should fail the test.
require('./js/workflow-draft-ui.js');

// Provide a realistic snapshot.
global.workflowColumnsSnapshot = {
  practiceId: 1234,
  active: [
    { id: 'Originated', label: 'Originated', position: 0 },
    { id: 'Sent To External Lab', label: 'Sent To External Lab', position: 1 },
    { id: 'Designed', label: 'Designed', position: 2 },
    { id: 'Manufactured', label: 'Manufactured', position: 3 },
    { id: 'Received From External Lab', label: 'Received From External Lab', position: 4 },
    { id: 'Delivered', label: 'Delivered', position: 5 }
  ],
  archived: []
};

var passed = 0;
var failed = 0;

function run(name, fn) {
  try {
    fn();
    passed++;
    console.log('PASS: ' + name);
  } catch (e) {
    failed++;
    console.log('FAIL: ' + name + ' - ' + e.name + ': ' + e.message);
    console.log(e.stack);
  }
}

run('initWorkflowColumnsManager first call', function () {
  global.initWorkflowColumnsManager();
});

run('initWorkflowColumnsManager second call', function () {
  global.initWorkflowColumnsManager();
});

run('getWorkflowColumnsDraft returns a draft', function () {
  var draft = global.getWorkflowColumnsDraft();
  if (!draft) throw new Error('No draft');
  if (!draft.active || draft.active.length !== 6) throw new Error('Unexpected active count');
});

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
JS;

$tmp = __DIR__ . '/../tmp_workflow_init_test.js';
file_put_contents($tmp, $js);
$exit = 0;
passthru('node ' . escapeshellarg($tmp), $exit);
unlink($tmp);
exit($exit);
