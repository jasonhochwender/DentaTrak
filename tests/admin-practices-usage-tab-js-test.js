/**
 * Unit tests for the Usage & Adoption tab loading/error/race behavior.
 *
 * Extracts the main inline script from admin-practices.php and runs it in a
 * sandbox with mocked fetch, document, and related functions. This lets us
 * verify the loading state, empty state, error state, retry button, and the
 * stale-request guard without a browser.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pagePath = path.join(__dirname, '..', 'admin-practices.php');
const pageSource = fs.readFileSync(pagePath, 'utf8');

// Extract the main inline script block (the one that defines loadTab / renderAdoptionTab).
const scriptMatch = pageSource.match(/<script>([\s\S]*?)<\/script>/g);
if (!scriptMatch || scriptMatch.length < 2) {
    console.error('Could not locate main inline script in admin-practices.php');
    process.exit(1);
}

let mainScript = '';
for (const block of scriptMatch) {
    const inner = block.replace(/^<script>/, '').replace(/<\/script>$/, '');
    if (inner.includes('function loadTab')) {
        mainScript = inner;
        break;
    }
}

// Stub PHP echo output inside the script so it is syntactically valid JS.
mainScript = mainScript.replace(/<\?(?:php|=|\s)[\s\S]*?\?>/g, "''");

let results = [];
function pass(name) { results.push({ name, ok: true }); }
function fail(name, detail) { results.push({ name, ok: false, detail }); }

// Simple Thenable to mock fetch without relying on real Promises or async.
class Thenable {
    constructor() { this._state = 'pending'; this._value = undefined; this._handlers = []; }
    then(onFulfilled, onRejected) {
        const next = new Thenable();
        const handler = () => {
            try {
                if (this._state === 'rejected') {
                    if (onRejected) {
                        const result = onRejected(this._value);
                        if (result && typeof result.then === 'function') {
                            result.then(v => next.resolve(v), e => next.reject(e));
                        } else {
                            next.resolve(result);
                        }
                    } else {
                        next.reject(this._value);
                    }
                } else {
                    const result = onFulfilled ? onFulfilled(this._value) : this._value;
                    if (result && typeof result.then === 'function') {
                        result.then(v => next.resolve(v), e => next.reject(e));
                    } else {
                        next.resolve(result);
                    }
                }
            } catch (e) {
                next.reject(e);
            }
        };
        if (this._state === 'pending') {
            this._handlers.push(handler);
        } else {
            handler();
        }
        return next;
    }
    catch(onRejected) { return this.then(undefined, onRejected); }
    resolve(v) { if (this._state !== 'pending') return; this._state = 'fulfilled'; this._value = v; this._handlers.forEach(fn => fn()); }
    reject(e) { if (this._state !== 'pending') return; this._state = 'rejected'; this._value = e; this._handlers.forEach(fn => fn()); }
}

function createSandbox() {
    const elements = {};
    const fetchCalls = [];
    let lastFetchController = null;

    function makeElement(tag) {
        const el = {
            tagName: tag,
            _listeners: {},
            children: [],
            addEventListener(event, handler) { this._listeners[event] = this._listeners[event] || []; this._listeners[event].push(handler); },
            appendChild(child) { this.children.push(child); }
        };
        // Simulate a DOM element whose textContent and innerHTML are coupled,
        // which is what escapeHtml() in admin-practices.php relies on.
        Object.defineProperty(el, 'textContent', {
            get() { return el._text || ''; },
            set(v) {
                el._text = String(v);
                el.innerHTML = String(v)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            }
        });
        Object.defineProperty(el, 'innerHTML', {
            get() { return el._html || ''; },
            set(v) { el._html = String(v); }
        });
        return el;
    }

    const detailContent = makeElement('div');
    detailContent.id = 'detailContent';
    elements['detailContent'] = detailContent;

    const document = {
        getElementById(id) { return elements[id] || null; },
        createElement(tag) { return makeElement(tag); },
        querySelectorAll() { return []; },
        addEventListener() {}
    };

    function fakeFetch(url) {
        fetchCalls.push(url);
        const thenable = new Thenable();
        lastFetchController = thenable;
        return thenable;
    }

    const sandbox = {
        console: { log() {}, error(msg) { sandbox._lastConsoleError = String(msg); } },
        document,
        window: { __i18n: {}, csrfToken: 'tok', addEventListener() {} },
        fetch: fakeFetch,
        t: (key) => key,
        escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
        formatRelativeTimestamp: (d, fallback) => d ? 'a moment ago' : fallback,
        formatDate: (d) => d || '-',
        formatDateTime: (d) => d || '-',
        yesNo: (v) => v ? 'Yes' : 'No',
        alert: (msg) => { sandbox._lastAlert = msg; },
        _lastConsoleError: null,
        _lastAlert: null,
        _fetchCalls: fetchCalls,
        _getLastFetch() { return lastFetchController; },
        _elements: elements,
    };

    return sandbox;
}

// Run the extracted inline script in the sandbox.
const sandbox = createSandbox();
vm.createContext(sandbox);
vm.runInContext(mainScript, sandbox);

// Seed the shared state through a tiny script so the lexical bindings are updated.
vm.runInContext("selectedPracticeId = 100; practices = [{ id: 100, practice_name: 'First', subscription: { plan_display: 'Evaluate', status_display: 'Active Trial', is_trialing: true, trial_display: '14 days remaining' } }, { id: 200, practice_name: 'Second', subscription: { plan_display: 'Control', status_display: 'Active', is_trialing: false } }];", sandbox);

const detailHtml = () => sandbox._elements['detailContent'].innerHTML;

// ---------------------------------------------------------------------------
// 1. Successful load and rendering
// ---------------------------------------------------------------------------
sandbox.loadAdoptionTab(100);
const fetch1 = sandbox._getLastFetch();
const successData = {
    success: true,
    adoption: {
        total_users: 3,
        users_with_login: 2,
        users_without_login: 1,
        most_recent_login: '2026-08-29 12:00:00',
        active_cases: 5,
        created_last_30_days: 2,
        delivered_last_30_days: 1,
        terminal_status: 'Delivered',
        terminal_label: 'Delivered',
        demo_case_count: 0,
        last_case_activity: '2026-08-29 12:00:00',
        last_activity: '2026-08-29 12:00:00',
        summary: 'Recent case activity'
    }
};
fetch1.resolve({ ok: true, json: () => successData });
pass('successful adoption load runs to completion');
if (!detailHtml().includes('Recent case activity')) {
    fail('render includes summary', detailHtml());
} else {
    pass('rendered summary is present');
}
if (!detailHtml().includes('Total Users')) {
    fail('render includes Total Users metric', detailHtml());
} else {
    pass('rendered Total Users metric is present');
}

// ---------------------------------------------------------------------------
// 2. Empty usage data
// ---------------------------------------------------------------------------
sandbox.loadAdoptionTab(100);
const fetch2 = sandbox._getLastFetch();
const emptyData = {
    success: true,
    adoption: {
        total_users: 0,
        users_with_login: 0,
        users_without_login: 0,
        most_recent_login: null,
        active_cases: 0,
        created_last_30_days: 0,
        delivered_last_30_days: 0,
        terminal_status: 'Delivered',
        terminal_label: 'Delivered',
        demo_case_count: 0,
        last_case_activity: null,
        last_activity: null,
        summary: 'No recorded case activity'
    }
};
fetch2.resolve({ ok: true, json: () => emptyData });
if (!detailHtml().includes('No usage or adoption data has been recorded')) {
    fail('empty state banner is shown', detailHtml());
} else {
    pass('empty usage data shows empty-state banner');
}

// ---------------------------------------------------------------------------
// 3. API failure (success: false)
// ---------------------------------------------------------------------------
sandbox.loadAdoptionTab(100);
const fetch3 = sandbox._getLastFetch();
fetch3.resolve({ ok: true, json: () => ({ success: false, message: 'Adoption query failed' }) });
if (!detailHtml().includes('Error loading usage')) {
    fail('API error state is shown', detailHtml());
} else {
    pass('API success=false shows error state');
}
if (!detailHtml().includes('Retry</button>') && !detailHtml().includes('"Retry"')) {
    fail('error state has Retry button', detailHtml());
} else {
    pass('error state includes Retry button');
}

// ---------------------------------------------------------------------------
// 4. Malformed / non-JSON response
// ---------------------------------------------------------------------------
sandbox.loadAdoptionTab(100);
const fetch4 = sandbox._getLastFetch();
fetch4.resolve({ ok: true, json: () => { throw new Error('Unexpected token <'); } });
if (!detailHtml().includes('Failed to load usage') || !detailHtml().includes('Unexpected token')) {
    fail('malformed JSON shows failure state', detailHtml());
} else {
    pass('malformed JSON response shows failure state');
}

// ---------------------------------------------------------------------------
// 5. Authorization / non-ok HTTP
// ---------------------------------------------------------------------------
sandbox.loadAdoptionTab(100);
const fetch5 = sandbox._getLastFetch();
fetch5.resolve({ ok: false, status: 401, json: () => ({}) });
if (!detailHtml().includes('Failed to load usage') || !detailHtml().includes('HTTP 401')) {
    fail('non-ok HTTP shows failure state', detailHtml());
} else {
    pass('non-ok HTTP response (401) shows failure state');
}

// ---------------------------------------------------------------------------
// 6. Race: switching practices while a request is pending
// ---------------------------------------------------------------------------
vm.runInContext("selectedPracticeId = 100;", sandbox);
sandbox.loadAdoptionTab(100);
const slowFetch = sandbox._getLastFetch();
// While that request is still pending, switch to practice 200.
vm.runInContext("selectedPracticeId = 200;", sandbox);
sandbox.loadAdoptionTab(200);
const newFetch = sandbox._getLastFetch();
newFetch.resolve({ ok: true, json: () => ({ success: true, adoption: emptyData.adoption }) });
// Now the old slow response arrives.
slowFetch.resolve({ ok: true, json: () => ({ success: true, adoption: successData.adoption }) });
// The detailContent should NOT contain the old successData metrics.
if (detailHtml().includes('Recent case activity')) {
    fail('stale request for practice 100 overwrote practice 200 view', detailHtml());
} else {
    pass('stale request for previous practice does not overwrite current view');
}

// ---------------------------------------------------------------------------
// 7. Retry after failure
// ---------------------------------------------------------------------------
vm.runInContext("selectedPracticeId = 100;", sandbox);
sandbox.loadAdoptionTab(100);
const failFetch = sandbox._getLastFetch();
failFetch.resolve({ ok: true, json: () => ({ success: false, message: 'Try again' }) });
// Simulate clicking Retry by calling loadAdoptionTab again.
sandbox.loadAdoptionTab(100);
const retryFetch = sandbox._getLastFetch();
retryFetch.resolve({ ok: true, json: () => successData });
if (!detailHtml().includes('Recent case activity')) {
    fail('retry after failure re-renders successfully', detailHtml());
} else {
    pass('retry after failure re-renders successfully');
}

// ---------------------------------------------------------------------------
// 8. No indefinite Loading state after failure
// ---------------------------------------------------------------------------
if (detailHtml().includes('loading') || detailHtml().includes('Loading')) {
    fail('loading indicator is not stuck after failure/retry', detailHtml());
} else {
    pass('loading indicator is replaced after failure and retry');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
let passed = 0, failed = 0;
results.forEach(r => {
    if (r.ok) {
        passed++;
        console.log('PASS: ' + r.name);
    } else {
        failed++;
        console.log('FAIL: ' + r.name + (r.detail ? ' | ' + r.detail.substring(0, 120) : ''));
    }
});
console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    process.exit(1);
}
