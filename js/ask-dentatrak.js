/**
 * Ask DentaTrak - read-only product & data assistant panel.
 *
 * The panel is a user-controlled surface: it opens from the profile menu or
 * the Ctrl+/ (⌘+/ on macOS) shortcut, closes via the header control, Escape,
 * or an outside click, and preserves its in-memory conversation across
 * close/reopen within the same page session. Nothing is persisted client-side beyond the live
 * DOM; a bounded sanitized transcript is sent with each request so
 * follow-up questions have context.
 */
(function() {
  'use strict';

  var floatingContainer = null;
  var panel = null;
  var closeButton = null;
  var messagesContainer = null;
  var chipsContainer = null;
  var askInput = null;
  var askSubmit = null;
  var isLoading = false;
  var lastTrigger = null;

  // Bounded in-memory transcript for follow-up context (never persisted).
  var history = [];
  var MAX_HISTORY = 8;
  var MAX_HISTORY_CHARS = 800;

  function lt(key, fallback) {
    // i18n.js exposes t(); fall back to English if it is not ready.
    if (typeof t === 'function') {
      var v = t(key);
      if (v) return v;
    }
    return fallback;
  }

  function initAskDentatrak() {
    floatingContainer = document.getElementById('askDentatrakFloating');
    panel = document.getElementById('askDentatrakPanel');
    closeButton = document.getElementById('askDentatrakClose');
    messagesContainer = document.getElementById('askDentatrakMessages');
    chipsContainer = document.getElementById('askDentatrakChips');
    askInput = document.getElementById('askDentatrakInput');
    askSubmit = document.getElementById('askDentatrakSubmit');

    // Populate every menu shortcut hint from the shared registry - one
    // source of truth, platform-aware, nothing hardcoded per item. Runs even
    // when the panel itself is feature-flagged off (other items still have
    // hints to fill).
    var kbdHints = document.querySelectorAll('.user-menu-kbd[data-shortcut]');
    for (var i = 0; i < kbdHints.length; i++) {
      kbdHints[i].textContent = shortcutHint(kbdHints[i].dataset.shortcut);
    }

    if (!floatingContainer || !panel) return;

    if (closeButton) {
      closeButton.addEventListener('click', function(e) {
        e.stopPropagation();
        closePanel();
      });
    }

    // Profile-menu entry point ("Ask DentaTrak") + platform-aware key hint.
    var menuItem = document.getElementById('askDentatrakMenuItem');
    if (menuItem) {
      menuItem.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        lastTrigger = menuItem;
        if (window.closeUserMenu) window.closeUserMenu();
        openPanel();
      });
    }

    if (askSubmit) {
      askSubmit.addEventListener('click', handleAskSubmit);
    }

    if (askInput) {
      askInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          handleAskSubmit();
        } else if (e.key === 'Escape') {
          e.stopPropagation();
          closePanel();
        }
      });
    }

    // Example prompt chips: fill and submit.
    if (chipsContainer) {
      chipsContainer.addEventListener('click', function(e) {
        var chip = e.target.closest('.ask-example-chip');
        if (!chip || !askInput) return;
        askInput.value = chip.textContent.trim();
        handleAskSubmit();
      });
    }

    // Escape closes the panel when it is open.
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && isOpen()) {
        closePanel();
      }
    });

    // Close panel when clicking outside.
    document.addEventListener('click', function(e) {
      if (isOpen() && !floatingContainer.contains(e.target)) {
        closePanel();
      }
    });
  }

  /**
   * Platform helpers shared with any surface that displays keyboard hints.
   * Key combos come from api/keyboard-shortcuts.php via
   * window.dtKeyboardShortcuts (single source of truth).
   */
  function isMacPlatform() {
    if (navigator.userAgentData && navigator.userAgentData.platform) {
      return /macOS/i.test(navigator.userAgentData.platform);
    }
    return /Mac|iPhone|iPad|iPod/.test(navigator.platform || '');
  }

  function shortcutHint(id) {
    var list = window.dtKeyboardShortcuts || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id && list[i].enabled) {
        return isMacPlatform() ? list[i].mac : list[i].win;
      }
    }
    return '';
  }

  function isOpen() {
    return !!(floatingContainer && floatingContainer.classList.contains('open'));
  }

  /**
   * Opening the assistant closes competing header surfaces so only one
   * popover/dropdown is ever open at a time.
   */
  function closeCompetingMenus() {
    if (window.closeUserMenu) window.closeUserMenu();
    if (window.closeNotificationDropdown) window.closeNotificationDropdown();
    if (window.closeLanguageSelector) window.closeLanguageSelector();
    if (window.closePracticeSwitcher) window.closePracticeSwitcher();
  }

  function openPanel() {
    if (!floatingContainer) return;
    if (isOpen()) {
      // Already open (e.g. shortcut pressed again): just focus the input.
      if (askInput) askInput.focus();
      return;
    }
    closeCompetingMenus();
    floatingContainer.classList.add('open');
    if (askInput) {
      setTimeout(function() { askInput.focus(); }, 100);
    }
  }

  function closePanel() {
    if (!floatingContainer || !isOpen()) return;
    floatingContainer.classList.remove('open');
    if (lastTrigger && document.contains(lastTrigger)) {
      lastTrigger.focus();
    }
  }

  function togglePanel() {
    if (isOpen()) {
      closePanel();
    } else {
      openPanel();
    }
  }

  function handleAskSubmit() {
    if (isLoading || !askInput) return;

    var query = askInput.value.trim();
    if (!query) return;

    addMessage(query, 'user');
    askInput.value = '';

    // Hide example chips once the conversation has started.
    if (chipsContainer) {
      chipsContainer.classList.add('hidden');
    }

    showLoading();
    sendQuery(query);
  }

  function addMessage(content, type, usageId) {
    if (!messagesContainer) return;

    var messageDiv = document.createElement('div');
    messageDiv.className = 'ask-message ' + type;

    if (type === 'user') {
      messageDiv.textContent = content;
    } else {
      // Assistant HTML is allowlist-sanitized server-side (p/strong/em/
      // ul/ol/li/br/code only); strip again defensively before injecting.
      messageDiv.innerHTML = sanitizeHtml(content);
      if (usageId) {
        messageDiv.appendChild(buildFeedbackBar(usageId));
      }
    }

    messagesContainer.appendChild(messageDiv);
    scrollToBottom();

    history.push({ role: type === 'user' ? 'user' : 'assistant', content: content });
    if (history.length > MAX_HISTORY) {
      history = history.slice(-MAX_HISTORY);
    }
  }

  /**
   * Optional thumbs feedback on one assistant response. Only the
   * telemetry row id and the up/down value are sent - never content.
   */
  var THUMB_UP = '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M6.5 15h6a1 1 0 0 0 .97-.76l1.2-5A1 1 0 0 0 13.7 8H10l.6-2.9A1.6 1.6 0 0 0 9 3.2L6.5 7H4.5v8h2zm-4-8h1a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z"/></svg>';
  var THUMB_DOWN = '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M9.5 1h-6a1 1 0 0 0-.97.76l-1.2 5A1 1 0 0 0 2.3 8H6l-.6 2.9A1.6 1.6 0 0 0 7 12.8L9.5 9h2V1h-2zm4 8h-1a1 1 0 0 1-1-1V1a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1z"/></svg>';

  function buildFeedbackBar(usageId) {
    var bar = document.createElement('div');
    bar.className = 'ask-feedback';
    [['up', THUMB_UP, 'ask_dentatrak.feedback.helpful', 'Helpful'],
     ['down', THUMB_DOWN, 'ask_dentatrak.feedback.not_helpful', 'Not helpful']
    ].forEach(function(cfg) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ask-feedback-btn';
      btn.dataset.value = cfg[0];
      btn.innerHTML = cfg[1];
      var label = lt(cfg[2], cfg[3]);
      btn.title = label;
      btn.setAttribute('aria-label', label);
      bar.appendChild(btn);
    });
    bar.addEventListener('click', function(e) {
      var btn = e.target.closest('.ask-feedback-btn');
      if (!btn) return;
      var siblings = bar.querySelectorAll('.ask-feedback-btn');
      var wasSelected = btn.classList.contains('selected');
      siblings.forEach(function(b) { b.classList.remove('selected'); });
      var value = wasSelected ? 'none' : btn.dataset.value;
      if (!wasSelected) btn.classList.add('selected');
      sendFeedback(usageId, value);
    });
    return bar;
  }

  function sendFeedback(usageId, value) {
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';
    fetch('api/ask-dentatrak-feedback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ usage_id: usageId, value: value }),
      credentials: 'same-origin'
    }).then(function(r) { return r.json(); }).then(function(data) {
      if (data && data.success === false) {
        // Revert the optimistic selection on failure.
        var btn = messagesContainer && messagesContainer.querySelector('.ask-feedback-btn.selected');
        if (btn) btn.classList.remove('selected');
      }
    }).catch(function() { /* feedback is best-effort; never surface */ });
  }

  function scrollToBottom() {
    var body = messagesContainer ? messagesContainer.parentElement : null;
    if (body) {
      body.scrollTop = body.scrollHeight;
    }
  }

  function showLoading() {
    isLoading = true;
    if (askSubmit) askSubmit.disabled = true;

    if (!messagesContainer) return;
    var loadingDiv = document.createElement('div');
    loadingDiv.className = 'ask-message assistant loading';
    loadingDiv.id = 'askLoadingMessage';
    loadingDiv.innerHTML = '<div class="ask-loading-dots"><span></span><span></span><span></span></div>';
    messagesContainer.appendChild(loadingDiv);
    scrollToBottom();
  }

  function removeLoading() {
    var loadingMsg = document.getElementById('askLoadingMessage');
    if (loadingMsg) loadingMsg.remove();
    isLoading = false;
    if (askSubmit) askSubmit.disabled = false;
    if (askInput && isOpen()) askInput.focus();
  }

  function sendQuery(query) {
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/ask-dentatrak.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        query: query,
        history: history.slice(0, -1) // server gets the prior turns only
      }),
      credentials: 'same-origin'
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      removeLoading();

      if (data.success && data.response) {
        addMessage(data.response, 'assistant', data.usage_id || null);
      } else if (data.error) {
        addMessage('<p>' + escapeHtml(data.error) + '</p>', 'assistant');
      } else {
        addMessage('<p>' + escapeHtml(lt('ask_dentatrak.errors.no_answer',
          'I couldn\'t find an answer to that. Try asking how to use a feature, or about the cases you can access.')) + '</p>', 'assistant');
      }
    })
    .catch(function() {
      removeLoading();
      addMessage('<p>' + escapeHtml(lt('ask_dentatrak.errors.generic',
        'Sorry, something went wrong. Please try again.')) + '</p>', 'assistant');
    });
  }

  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Defensive mirror of the server-side allowlist sanitizer for assistant
   * messages rendered as HTML.
   */
  function sanitizeHtml(html) {
    if (!html) return '';
    var allowed = { P: 1, STRONG: 1, EM: 1, UL: 1, OL: 1, LI: 1, BR: 1, CODE: 1 };
    var template = document.createElement('template');
    template.innerHTML = html;
    var walker = document.createTreeWalker(template.content, NodeFilter.SHOW_ELEMENT);
    var toStrip = [];
    while (walker.nextNode()) {
      var el = walker.currentNode;
      if (!allowed[el.tagName]) {
        toStrip.push(el);
      } else {
        while (el.attributes.length) {
          el.removeAttribute(el.attributes[0].name);
        }
      }
    }
    toStrip.forEach(function(el) {
      var parent = el.parentNode;
      while (el.firstChild) parent.insertBefore(el.firstChild, el);
      parent.removeChild(el);
    });
    return template.innerHTML;
  }

  // Public API for menus/other surfaces.
  window.askDentatrak = {
    open: function(trigger) { lastTrigger = trigger || lastTrigger; openPanel(); },
    close: closePanel,
    toggle: togglePanel,
    isOpen: isOpen
  };
  window.dtShortcutHint = shortcutHint;
  window.dtIsMacPlatform = isMacPlatform;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAskDentatrak);
  } else {
    initAskDentatrak();
  }

})();
