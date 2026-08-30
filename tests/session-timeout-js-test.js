/**
 * Clock-controlled JavaScript tests for js/session-timeout.js.
 * No real-time waits; all timers and the Date clock are mocked.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const sourcePath = path.join(__dirname, '..', 'js', 'session-timeout.js');
const source = fs.readFileSync(sourcePath, 'utf8');

let results = [];

function assertEqual(actual, expected, msg) {
  if (actual !== expected) {
    throw new Error(`${msg}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

function assertTrue(value, msg) {
  if (!value) {
    throw new Error(`${msg}: expected truthy, got ${JSON.stringify(value)}`);
  }
}

function assertFalse(value, msg) {
  if (value) {
    throw new Error(`${msg}: expected falsy, got ${JSON.stringify(value)}`);
  }
}

// Simple thenable for mocking fetch without Promises or real async.
class Thenable {
  constructor() {
    this._state = 'pending';
    this._value = undefined;
    this._onFulfilled = [];
    this._onRejected = [];
  }
  then(onFulfilled, onRejected) {
    const next = new Thenable();
    const handler = () => {
      try {
        const result = onFulfilled ? onFulfilled(this._value) : this._value;
        if (result && typeof result.then === 'function') {
          result.then(
            (v) => next.resolve(v),
            (e) => next.reject(e)
          );
        } else {
          next.resolve(result);
        }
      } catch (e) {
        next.reject(e);
      }
    };
    if (this._state === 'fulfilled') {
      handler();
    } else if (this._state === 'rejected') {
      if (onRejected) onRejected(this._value);
    } else {
      this._onFulfilled.push(handler);
    }
    return next;
  }
  catch(onRejected) {
    return this.then(undefined, onRejected);
  }
  resolve(value) {
    if (this._state !== 'pending') return this;
    this._state = 'fulfilled';
    this._value = value;
    this._onFulfilled.forEach((fn) => fn());
    return this;
  }
  reject(reason) {
    if (this._state !== 'pending') return this;
    this._state = 'rejected';
    this._value = reason;
    this._onRejected.forEach((fn) => fn());
    return this;
  }
}

function createSandbox() {
  let now = 70000;
  let nextTimerId = 1;
  const timers = [];
  const fetchCalls = [];
  const listeners = { document: {}, window: {} };
  const elementsById = {};

  // Pre-create modal elements used by showWarningModal
  function makeElement(tag) {
    const el = {
      tagName: tag,
      id: '',
      style: {},
      children: [],
      parentNode: null,
      textContent: '',
      _listeners: {},
      setAttribute(name, value) { el[name] = value; },
      getAttribute(name) { return el[name] || null; },
      appendChild(child) {
        child.parentNode = el;
        el.children.push(child);
        if (child.id) elementsById[child.id] = child;
      },
      addEventListener(event, handler) {
        el._listeners[event] = el._listeners[event] || [];
        el._listeners[event].push(handler);
      },
    };
    return el;
  }

  const countdownEl = makeElement('div');
  const extendBtn = makeElement('button');
  const logoutBtn = makeElement('button');
  elementsById['sessionCountdown'] = countdownEl;
  elementsById['sessionExtendBtn'] = extendBtn;
  elementsById['sessionLogoutBtn'] = logoutBtn;

  const body = makeElement('body');

  const document = {
    readyState: 'complete',
    body: body,
    _listeners: listeners.document,
    addEventListener(event, handler, _opts) {
      this._listeners[event] = this._listeners[event] || [];
      this._listeners[event].push(handler);
    },
    createElement(tag) {
      return makeElement(tag);
    },
    getElementById(id) {
      return elementsById[id] || null;
    },
  };

  const window = {
    location: { href: '', search: '' },
    _listeners: listeners.window,
    addEventListener(event, handler) {
      this._listeners[event] = this._listeners[event] || [];
      this._listeners[event].push(handler);
    },
  };

  const sandbox = {
    console: {
      log() {},
      warn() {},
      error() {},
    },
    document,
    window,
    setTimeout(callback, ms) {
      const id = nextTimerId++;
      timers.push({ id, type: 'timeout', callback, due: now + (ms || 0) });
      return id;
    },
    setInterval(callback, ms) {
      const id = nextTimerId++;
      timers.push({ id, type: 'interval', callback, due: now + (ms || 0), period: ms || 0 });
      return id;
    },
    clearTimeout(id) {
      for (let i = 0; i < timers.length; i++) {
        if (timers[i].id === id) { timers.splice(i, 1); return; }
      }
    },
    clearInterval(id) {
      for (let i = 0; i < timers.length; i++) {
        if (timers[i].id === id) { timers.splice(i, 1); return; }
      }
    },
    Date: Object.setPrototypeOf(function () {}, Date.prototype),
    fetch(url, options) {
      const promise = new Thenable();
      fetchCalls.push({ url, options, promise });
      return promise;
    },
    t(key, params) { return key + (params ? ' ' + JSON.stringify(params) : ''); },
    showToast() {},
    Math,
    JSON,
    encodeURIComponent,
    decodeURIComponent,
    URLSearchParams,
    // Expose internals for test driving
    _test: {
      get now() { return now; },
      tick(ms) {
        now += ms;
        // Repeatedly fire all timers whose due time has passed.
        let changed = true;
        while (changed) {
          changed = false;
          for (let i = 0; i < timers.length; i++) {
            const t = timers[i];
            if (t.due <= now) {
              changed = true;
              t.callback();
              if (t.type === 'timeout') {
                timers.splice(i, 1);
                i--;
              } else {
                t.due += t.period;
              }
            }
          }
        }
      },
      fetchCalls,
      listeners,
      elementsById,
      triggerDocumentEvent(event) {
        const handlers = (listeners.document[event] || []).slice();
        for (const h of handlers) h({ type: event });
      },
      triggerWindowEvent(event) {
        const handlers = (listeners.window[event] || []).slice();
        for (const h of handlers) h({ type: event });
      },
      resolveLastFetch(response) {
        if (fetchCalls.length === 0) throw new Error('No fetch calls to resolve');
        fetchCalls[fetchCalls.length - 1].promise.resolve(response);
      },
      resolveFetchAt(index, response) {
        if (!fetchCalls[index]) throw new Error('No fetch call at index ' + index);
        fetchCalls[index].promise.resolve(response);
      },
    },
  };

  sandbox.Date.now = function () { return now; };

  return sandbox;
}

function runInSandbox() {
  const sandbox = createSandbox();
  const context = vm.createContext(sandbox);
  vm.runInContext(source, context, { filename: 'session-timeout.js' });
  return sandbox;
}

function makeJsonResponse(data) {
  return {
    status: 200,
    ok: true,
    json: () => {
      const t = new Thenable();
      t.resolve(data);
      return t;
    },
  };
}

function makeEmptyResponse(status) {
  return {
    status: status,
    ok: status >= 200 && status < 300,
    json: () => {
      const t = new Thenable();
      t.resolve({});
      return t;
    },
  };
}

function test(name, fn) {
  try {
    fn();
    results.push(`${name}: PASS`);
  } catch (e) {
    results.push(`${name}: FAIL - ${e.message}`);
  }
}

// ---- Tests ----

test('Page initialization does not immediately ping the server', () => {
  const s = runInSandbox();
  const activityPings = s._test.fetchCalls.filter(c => {
    if (!c.options || c.options.method !== 'POST' || !c.options.body) return false;
    try {
      const body = JSON.parse(c.options.body);
      return body.action === 'activity';
    } catch (e) { return false; }
  });
  assertEqual(activityPings.length, 0, 'activity pings at init');
});

test('User activity events include click, keydown, scroll, touchstart, pointerdown', () => {
  const s = runInSandbox();
  const expected = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'pointerdown'];
  for (const ev of expected) {
    assertTrue(Array.isArray(s._test.listeners.document[ev]) && s._test.listeners.document[ev].length > 0, `${ev} listener registered`);
  }
});

function testActivityPing(eventName) {
  test(`Genuine ${eventName} triggers an activity ping`, () => {
    const s = runInSandbox();
    // For the first user event, 60s have elapsed since lastServerPing (0).
    s._test.triggerDocumentEvent(eventName);
    s._test.tick(1000); // let the 1s debounce fire
    const pings = s._test.fetchCalls.filter(c => {
      if (c.options && c.options.method === 'POST' && c.options.body) {
        const body = JSON.parse(c.options.body);
        return body.action === 'activity';
      }
      return false;
    });
    assertEqual(pings.length, 1, `one activity ping after ${eventName}`);
    assertEqual(pings[0].url, 'api/session-status.php', `activity ping target after ${eventName}`);
  });
}

['mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'pointerdown'].forEach(testActivityPing);

test('Window focus does not reset the timeout', () => {
  const s = runInSandbox();
  assertFalse(s._test.listeners.window['focus'] && s._test.listeners.window['focus'].length > 0, 'no window focus listener');
  assertFalse(s._test.listeners.document['focus'] && s._test.listeners.document['focus'].length > 0, 'no document focus listener');
});

test('visibilitychange alone does not reset the timeout', () => {
  const s = runInSandbox();
  assertFalse(s._test.listeners.document['visibilitychange'] && s._test.listeners.document['visibilitychange'].length > 0, 'no visibilitychange listener in session-timeout.js');
});

test('mousemove is not a user-activity event in session-timeout.js', () => {
  const s = runInSandbox();
  assertFalse(s._test.listeners.document['mousemove'] && s._test.listeners.document['mousemove'].length > 0, 'no mousemove listener in session-timeout.js');
});

test('Automated session-status polling is a GET, not an activity ping', () => {
  const s = runInSandbox();
  // After init the first check fires at 5s. Tick 60s to trigger it.
  s._test.tick(60000);
  const last = s._test.fetchCalls[s._test.fetchCalls.length - 1];
  assertTrue(last && last.url === 'api/session-status.php' && (!last.options || last.options.method !== 'POST'), 'automated status check is a GET');
  const activityPings = s._test.fetchCalls.filter(c => {
    if (c.options && c.options.method === 'POST' && c.options.body) {
      try {
        return JSON.parse(c.options.body).action === 'activity';
      } catch (e) { return false; }
    }
    return false;
  });
  assertEqual(activityPings.length, 0, 'no activity ping from automated polling');
});

test('Warning modal appears with approximately 300 seconds remaining', () => {
  const s = runInSandbox();
  s._test.tick(60000); // trigger the initial scheduled checkSessionStatus
  const response = makeJsonResponse({
    success: true,
    loggedIn: true,
    timeRemaining: 300,
    timeout: 3600,
    warningTime: 300,
    showWarning: true,
  });
  s._test.resolveLastFetch(response);
  assertTrue(s._test.elementsById['sessionTimeoutModal'], 'warning modal was created');
  const countdown = s._test.elementsById['sessionCountdown'];
  assertTrue(countdown, 'countdown element exists');
  assertEqual(countdown.textContent, '5:00', 'countdown starts around 300 seconds');
});

test('Countdown reaches zero, asks the server, then redirects to timeout URL', () => {
  const s = runInSandbox();
  s._test.tick(60000);
  const response = makeJsonResponse({
    success: true,
    loggedIn: true,
    timeRemaining: 300,
    timeout: 3600,
    warningTime: 300,
    showWarning: true,
  });
  s._test.resolveLastFetch(response);
  // Advance the full 300-second warning countdown.
  s._test.tick(300000);
  // The countdown asked the server; resolve that server call as an inactivity timeout.
  const expiredResponse = makeJsonResponse({
    success: false,
    loggedIn: false,
    reason: 'inactivity',
    timeRemaining: 0,
    timeout: 3600,
    warningTime: 300,
  });
  s._test.resolveLastFetch(expiredResponse);
  assertEqual(s.window.location.href, 'login.php?timeout=1', 'redirects to timeout login after server confirms');
});

test('Server 401 (unrelated reason) redirects to generic session expired', () => {
  const s = runInSandbox();
  s._test.tick(60000);
  const response = makeJsonResponse({
    success: false,
    loggedIn: false,
    reason: null,
    timeRemaining: 0,
    timeout: 3600,
    warningTime: 300,
  });
  s._test.resolveLastFetch(response);
  assertEqual(s.window.location.href, 'login.php?session_expired=1', 'redirects to generic expired login');
});

test('timeRemaining of 0 on a still-loggedIn-looking response redirects', () => {
  const s = runInSandbox();
  s._test.tick(60000);
  const response = makeJsonResponse({
    success: true,
    loggedIn: true,
    timeRemaining: 0,
    timeout: 3600,
    warningTime: 300,
    showWarning: false,
  });
  s._test.resolveLastFetch(response);
  assertEqual(s.window.location.href, 'login.php?session_expired=1', 'redirects when server reports zero time');
});

console.log(results.join('\n'));
process.exit(results.some(r => r.startsWith('FAIL')) ? 1 : 0);
