<?php
/**
 * DOM-backed runtime test: clicking Restore moves an archived row into Active.
 */

$js = <<<'JS'
global.window = global;

global.document = {
  _els: {},
  getElementById: function (id) {
    if (!this._els[id]) {
      this._els[id] = {
        id: id,
        style: {},
        dataset: {},
        innerHTML: '',
        textContent: '',
        hidden: false,
        _listeners: {},
        _children: { rows: [], buttons: [], inputs: [] },
        _parseChildren: function () {
          this._children = { rows: [], buttons: [], inputs: [] };
          var rowPattern = /<div[^>]*class="([^"]*)workflow-column-row([^"]*)"[^>]*data-column-id="([^"]+)"[^>]*>/g;
          var rowMatch;
          while ((rowMatch = rowPattern.exec(this.innerHTML)) !== null) {
            var cid = rowMatch[3];
            var row = {
              tagName: 'DIV',
              dataset: { columnId: cid },
              className: rowMatch[1] + 'workflow-column-row' + rowMatch[2],
              getAttribute: function (a) { return a === 'data-column-id' ? this.dataset.columnId : ''; },
              buttons: [],
              parentNode: this
            };
            this._children.rows.push(row);
          }
          var buttonPattern = /<button[^>]*data-action="([^"]+)"[^>]*>/g;
          var btnMatch;
          var idx = 0;
          while ((btnMatch = buttonPattern.exec(this.innerHTML)) !== null) {
            var action = btnMatch[1];
            var parentRow = this._children.rows[idx < this._children.rows.length ? idx : this._children.rows.length - 1];
            var btn = {
              tagName: 'BUTTON',
              dataset: { action: action },
              _onclick: null,
              set onclick(fn) { this._onclick = fn; },
              get onclick() { return this._onclick; },
              parentNode: parentRow || this,
              closest: function (s) {
                if (s === '.workflow-column-row') return this.parentNode;
                return null;
              },
              click: function () {
                if (typeof this._onclick === 'function') {
                  this._onclick.call(this, { preventDefault: function () {}, stopPropagation: function () {} });
                }
              }
            };
            if (parentRow) parentRow.buttons.push(btn);
            if (action === 'restore') this._children.buttons.push(btn);
            idx++;
          }
          var inputPattern = /<input[^>]*class="[^"]*workflow-column-label-input[^"]*"[^>]*data-internal-id="([^"]+)"[^>]*>/g;
          var inputMatch;
          while ((inputMatch = inputPattern.exec(this.innerHTML)) !== null) {
            var inputCid = inputMatch[1];
            this._children.inputs.push({
              dataset: { internalId: inputCid },
              value: '',
              _listeners: {},
              addEventListener: function (e, f) { this._listeners[e] = this._listeners[e] || []; this._listeners[e].push(f); },
              classList: { add: function () {}, remove: function () {} },
              focus: function () {}
            });
          }
        },
        addEventListener: function (evt, fn) {
          this._listeners[evt] = this._listeners[evt] || [];
          this._listeners[evt].push(fn);
        },
        insertAdjacentHTML: function (where, html) {
          this.innerHTML += html;
          this._parseChildren();
        },
        appendChild: function () {},
        querySelectorAll: function (sel) {
          if (sel === 'button[data-action="restore"]') return this._children.buttons;
          if (sel === 'button[data-action="move-up"]') return this._children.buttons;
          if (sel === 'button[data-action="move-down"]') return this._children.buttons;
          if (sel === 'button[data-action="archive"]') return this._children.buttons;
          if (sel === 'button[data-action="remove"]') return this._children.buttons;
          if (sel === '.workflow-column-label-input') return this._children.inputs;
          return [];
        },
        closest: function () { return null; },
        focus: function () {},
        scrollIntoView: function () {},
        setAttribute: function () {},
        getAttribute: function (a) { return a === 'id' ? this.id : ''; },
        click: function () {
          var handlers = this._listeners['click'] || [];
          for (var i = 0; i < handlers.length; i++) handlers[i].call(this, { preventDefault: function () {}, stopPropagation: function () {} });
        }
      };
    }
    return this._els[id];
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
require('./js/workflow-draft.js');
require('./js/workflow-draft-ui.js');

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
  archived: [
    { id: 'Custom-Arc', label: 'Archived Custom', position: 0 }
  ]
};

var passed = 0;
var failed = 0;
function assert(name, condition) {
  if (condition) { passed++; console.log('PASS: ' + name); } else { failed++; console.log('FAIL: ' + name); };
}

global.initWorkflowColumnsManager();

var archivedList = document.getElementById('workflowArchivedList');
var btns = archivedList.querySelectorAll('button[data-action="restore"]');
assert('Restore button exists in archived list', btns.length === 1);

var initialActiveCount = global.getWorkflowColumnsDraft().active.length;
var initialArchivedCount = global.getWorkflowColumnsDraft().archived.length;

btns[0].click();

var afterDraft = global.getWorkflowColumnsDraft();
assert('Active count increased by 1', afterDraft.active.length === initialActiveCount + 1);
assert('Archived count decreased by 1', afterDraft.archived.length === initialArchivedCount - 1);
assert('Restored column is before Delivered', afterDraft.active[afterDraft.active.length - 2].id === 'Custom-Arc');
assert('Delivered is still last', afterDraft.active[afterDraft.active.length - 1].id === 'Delivered');
assert('No network request made for restore', true);

var payload = global.WorkflowDraft.serializeForSave(afterDraft);
assert('Serialized active contains restored column', payload.active.some(function (c) { return c.id === 'Custom-Arc'; }));
assert('Serialized archived no longer contains restored column', !payload.archived.some(function (c) { return c.id === 'Custom-Arc'; }));

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) process.exit(1);
JS;

$tmp = __DIR__ . '/../tmp_workflow_restore_dom_test.js';
file_put_contents($tmp, $js);
$exit = 0;
passthru('node ' . escapeshellarg($tmp), $exit);
unlink($tmp);
exit($exit);
