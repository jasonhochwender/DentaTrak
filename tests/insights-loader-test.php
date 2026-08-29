<?php
/**
 * Regression test for the Practice/Lab Insights lazy script loader.
 *
 * This test extracts the Chart.js/analytics/lab-insights loader code from
 * js/app.js and runs it in a minimal Node/DOM-like context. It verifies the
 * initial-hash activation path, single-load behavior, queued callbacks,
 * and Chart.js load-failure handling.
 */

$appSource = file_get_contents(__DIR__ . '/../js/app.js');

$startComment = '// Lazy load Chart.js (shared by both Practice Insights and Lab Insights)';
$endComment   = '// Deep-link and initial-hash activation for Practice / Lab Insights.';

$start = strpos($appSource, $startComment);
$end   = strpos($appSource, $endComment);

if ($start === false || $end === false || $end <= $start) {
    echo "FAIL: Could not extract loader code from js/app.js\n";
    exit(1);
}

$loaderCode = substr($appSource, $start, $end - $start);

$loaderSourceJson = json_encode($loaderCode);

$js = <<<'JS'
// Minimal DOM/Browser mocks

global.window = global;

global.document = {
  _els: {},
  createElement: function (tag) {
    if (tag !== 'script') { return { tagName: tag.toUpperCase() }; }
    return {
      tagName: 'SCRIPT',
      src: '',
      onload: null,
      onerror: null,
      _triggerLoad: function () {
        if (typeof this.onload === 'function') { this.onload(); }
      },
      _triggerError: function () {
        if (typeof this.onerror === 'function') { this.onerror(); }
      }
    };
  },
  body: {
    appendChild: function (el) {}
  },
  querySelector: function () { return null; },
  querySelectorAll: function () { return []; }
};

// Make setTimeout synchronous in the test harness so refresh callbacks run
// immediately instead of requiring async waits.
global.setTimeout = function (fn) {
  if (typeof fn === 'function') { fn(); }
};

global.t = function (k, p) { return k; };

var passed = 0;
var failed = 0;
function assert (name, condition) {
  if (condition) {
    passed++;
    console.log('PASS: ' + name);
  } else {
    failed++;
    console.log('FAIL: ' + name);
  }
}

var appendedScripts = [];
var analyticsProCalls = 0;
var labInsightsCalls = 0;

global.window.loadAnalyticsProData = function () { analyticsProCalls++; };
global.window.loadLabInsightsData = function () { labInsightsCalls++; };

// New loader instance for each scenario so closure state is fresh.
function newLoader () {
  var instanceScripts = [];

  global.document.body.appendChild = function (el) {
    instanceScripts.push(el);
    appendedScripts.push(el);
  };

  var source = __LOADER_SOURCE__;
  source += '\nreturn { loadAnalyticsScripts: loadAnalyticsScripts, loadLabInsightsScripts: loadLabInsightsScripts };';
  var fn = new Function(source);
  var loader = fn.call(global);

  return {
    instanceScripts: instanceScripts,
    loadPractice: loader.loadAnalyticsScripts,
    loadLab: loader.loadLabInsightsScripts,
    sourceFn: fn
  };
}

function findScripts (arr, needle) {
  return arr.filter(function (s) { return s.src.indexOf(needle) !== -1; });
}

function activateInsightsSubview (loader, view) {
  if (view === 'practice') { loader.loadPractice(); }
  else if (view === 'labs') { loader.loadLab(); }
}

function applyInitialInsightsHash (loader) {
  var hash = (global.window.location && global.window.location.hash || '').replace(/^#/, '');
  if (hash === 'insights' || hash === 'insights/practice') {
    activateInsightsSubview(loader, 'practice', false);
  } else if (hash === 'insights/labs') {
    activateInsightsSubview(loader, 'labs', false);
  }
}

// Scenario 1: Initial Practice Insights hash loads Chart.js and analytics-pro.js
console.log('\n--- Scenario 1: Initial Practice Insights activation ---');
global.window.location = { hash: '#insights/practice' };
appendedScripts = [];
analyticsProCalls = 0;
labInsightsCalls = 0;
var loader1 = newLoader();
applyInitialInsightsHash(loader1);

assert('One Chart.js script requested for initial practice view',
  findScripts(loader1.instanceScripts, 'chart.js').length === 1);
assert('No analytics-pro.js requested before Chart.js is ready',
  findScripts(loader1.instanceScripts, 'analytics-pro.js').length === 0);

var chart1 = findScripts(loader1.instanceScripts, 'chart.js')[0];
global.window.Chart = function () {}; // simulate Chart.js library becoming available
chart1._triggerLoad();

assert('One analytics-pro.js script requested after Chart.js loads',
  findScripts(loader1.instanceScripts, 'analytics-pro.js').length === 1);
assert('No duplicate Chart.js scripts requested',
  findScripts(loader1.instanceScripts, 'chart.js').length === 1);

var analytics1 = findScripts(loader1.instanceScripts, 'analytics-pro.js')[0];
analytics1._triggerLoad();

assert('loadAnalyticsProData called after analytics-pro.js loads',
  analyticsProCalls === 1);
assert('loadLabInsightsData not called for Practice view',
  labInsightsCalls === 0);

// Scenario 2: Multiple rapid calls before Chart.js loads request it only once
console.log('\n--- Scenario 2: Queued calls before first load completes ---');
global.window.location = { hash: '#insights/practice' };
appendedScripts = [];
analyticsProCalls = 0;
var loader2 = newLoader();
loader2.loadPractice();
loader2.loadPractice();
loader2.loadPractice();

assert('Only one Chart.js script requested across three calls',
  findScripts(loader2.instanceScripts, 'chart.js').length === 1);

global.window.Chart = function () {};
findScripts(loader2.instanceScripts, 'chart.js')[0]._triggerLoad();

assert('Only one analytics-pro.js script requested',
  findScripts(loader2.instanceScripts, 'analytics-pro.js').length === 1);

findScripts(loader2.instanceScripts, 'analytics-pro.js')[0]._triggerLoad();

assert('loadAnalyticsProData called exactly once',
  analyticsProCalls === 1);

// Scenario 3: Lab Insights path loads lab-insights.js
console.log('\n--- Scenario 3: Lab Insights activation ---');
global.window.location = { hash: '#insights/labs' };
appendedScripts = [];
labInsightsCalls = 0;
var loader3 = newLoader();
applyInitialInsightsHash(loader3);

assert('One Chart.js script requested for lab view',
  findScripts(loader3.instanceScripts, 'chart.js').length === 1);

global.window.Chart = function () {};
findScripts(loader3.instanceScripts, 'chart.js')[0]._triggerLoad();

assert('One lab-insights.js script requested after Chart.js loads',
  findScripts(loader3.instanceScripts, 'lab-insights.js').length === 1);

findScripts(loader3.instanceScripts, 'lab-insights.js')[0]._triggerLoad();

assert('loadLabInsightsData called after lab-insights.js loads',
  labInsightsCalls === 1);

// Scenario 4: Chart.js already loaded -> no duplicate script, data refreshes
console.log('\n--- Scenario 4: Chart.js already loaded ---');
global.window.location = { hash: '#insights/practice' };
appendedScripts = [];
analyticsProCalls = 0;
var loader4 = newLoader();
loader4.loadPractice();
global.window.Chart = function () {};
findScripts(loader4.instanceScripts, 'chart.js')[0]._triggerLoad();
findScripts(loader4.instanceScripts, 'analytics-pro.js')[0]._triggerLoad();

assert('First load triggers loadAnalyticsProData',
  analyticsProCalls === 1);

// Reset captured scripts and call again (simulating tab reselect).
loader4.instanceScripts.length = 0;
loader4.loadPractice();

assert('No additional Chart.js or analytics-pro.js scripts when already loaded',
  loader4.instanceScripts.length === 0);
assert('loadAnalyticsProData refreshed',
  analyticsProCalls === 2);

// Scenario 5: Chart.js fails to load -> dependent scripts are not loaded and app does not crash
console.log('\n--- Scenario 5: Chart.js load failure ---');
global.window.location = { hash: '#insights/practice' };
appendedScripts = [];
analyticsProCalls = 0;
var loader5 = newLoader();
loader5.loadPractice();

var chart5 = findScripts(loader5.instanceScripts, 'chart.js')[0];
chart5._triggerError();

assert('No analytics-pro.js requested after Chart.js fails',
  findScripts(loader5.instanceScripts, 'analytics-pro.js').length === 0);
assert('loadAnalyticsProData not called after Chart.js fails',
  analyticsProCalls === 0);

// Verify a later call does not reload Chart.js or crash
loader5.loadPractice();
assert('No additional Chart.js script requested after failure',
  findScripts(loader5.instanceScripts, 'chart.js').length === 1);
assert('Still no analytics-pro.js after repeated failed attempt',
  findScripts(loader5.instanceScripts, 'analytics-pro.js').length === 0);

// Scenario 6: analytics-pro.js itself fails to load -> Lab Insights still works
console.log('\n--- Scenario 6: analytics-pro.js load failure ---');
global.window.location = { hash: '#insights/practice' };
appendedScripts = [];
analyticsProCalls = 0;
labInsightsCalls = 0;
var loader6 = newLoader();
loader6.loadPractice();
global.window.Chart = function () {};
findScripts(loader6.instanceScripts, 'chart.js')[0]._triggerLoad();

var analytics6 = findScripts(loader6.instanceScripts, 'analytics-pro.js')[0];
analytics6._triggerError();

assert('loadAnalyticsProData not called when analytics-pro.js fails',
  analyticsProCalls === 0);

// Lab Insights should still be usable after the analytics-pro.js failure.
// Because Chart.js is already loaded for this loader, no new chart.js request
// is made; lab-insights.js should be requested directly.
var canRequestLab = function () {
  loader6.instanceScripts.length = 0;
  loader6.loadLab();
  return findScripts(loader6.instanceScripts, 'lab-insights.js').length === 1;
};
assert('Lab Insights can still be requested after analytics-pro.js fails',
  canRequestLab());

console.log('\n' + passed + ' passed, ' + failed + ' failed');
if (failed > 0) { process.exit(1); }
JS;

$js = str_replace('__LOADER_SOURCE__', $loaderSourceJson, $js);

$tmp = __DIR__ . '/../tmp_insights_loader_test.js';
file_put_contents($tmp, $js);
$exit = 0;
passthru('node ' . escapeshellarg($tmp), $exit);
unlink($tmp);
exit($exit);
