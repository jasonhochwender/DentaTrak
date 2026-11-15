/**
 * Platform announcements - user-facing banner renderer.
 *
 * Polls api/announcements.php for the announcements visible to the
 * authenticated user in the current practice context, renders them below
 * the app header, and persists dismissals server-side (per user, per
 * announcement) so they survive refresh, logout, and devices.
 *
 * Failures are silent: a retrieval error never breaks the application.
 */
(function () {
  'use strict';

  var POLL_INTERVAL_MS = 60000; // lightweight periodic refresh
  var container = null;
  var pollTimer = null;
  var csrfToken = '';
  var i18n = { dismiss: 'Dismiss announcement' };

  var ICONS = {
    info: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>',
    warning: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
    critical: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
    success: '<svg class="dt-announcement-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
  };

  function init() {
    container = document.getElementById('dtAnnouncements');
    if (!container) {
      return;
    }

    var cfgEl = document.getElementById('dtAnnouncementsConfig');
    if (cfgEl) {
      try {
        var cfg = JSON.parse(cfgEl.textContent || '{}');
        csrfToken = cfg.csrfToken || '';
        if (cfg.i18n && cfg.i18n.dismiss) {
          i18n.dismiss = cfg.i18n.dismiss;
        }
      } catch (e) { /* keep defaults */ }
    }

    refresh();
    pollTimer = setInterval(refresh, POLL_INTERVAL_MS);
  }

  function refresh() {
    fetch('api/announcements.php', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      if (!res.ok) {
        throw new Error('http ' + res.status);
      }
      return res.json();
    }).then(function (data) {
      if (data && data.success) {
        render(data.announcements || []);
      }
    }).catch(function () {
      // Silent failure - announcements must never break the app.
    });
  }

  function render(list) {
    if (!container) {
      return;
    }
    // Preserve DOM nodes for announcements that are still present so a
    // poll does not steal focus or replay animations.
    var existing = {};
    Array.prototype.forEach.call(container.children, function (el) {
      if (el.dataset && el.dataset.announcementId) {
        existing[el.dataset.announcementId] = el;
      }
    });

    container.textContent = '';
    // Scheduling validation guarantees at most one live announcement, but
    // legacy/conflicting rows may still exist. Render only the first
    // (highest-priority, earliest-start, lowest-id) entry so two banners
    // can never appear simultaneously; the deterministic server ordering
    // decides which one wins.
    var visible = list.slice(0, 1);
    visible.forEach(function (a) {
      var node = existing[String(a.id)] || buildBanner(a);
      container.appendChild(node);
    });
  }

  function buildBanner(a) {
    var type = ICONS[a.type] ? a.type : 'info';
    var el = document.createElement('div');
    el.className = 'dt-announcement dt-announcement-' + type;
    el.dataset.announcementId = String(a.id);
    el.setAttribute('role', a.type === 'critical' ? 'alert' : 'status');

    var iconWrap = document.createElement('span');
    iconWrap.innerHTML = ICONS[type];
    iconWrap.setAttribute('aria-hidden', 'true');

    var body = document.createElement('div');
    body.className = 'dt-announcement-body';
    if (a.title) {
      var title = document.createElement('p');
      title.className = 'dt-announcement-title';
      title.textContent = a.title;
      body.appendChild(title);
    }
    var msg = document.createElement('p');
    msg.className = 'dt-announcement-message';
    msg.textContent = a.message || '';
    body.appendChild(msg);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'dt-announcement-close';
    close.setAttribute('aria-label', i18n.dismiss);
    close.setAttribute('title', i18n.dismiss);
    close.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    close.addEventListener('click', function () {
      dismiss(a.id, el);
    });

    el.appendChild(iconWrap);
    el.appendChild(body);
    el.appendChild(close);
    return el;
  }

  function dismiss(id, el) {
    // Hide immediately; persist server-side. If persistence fails the
    // banner may reappear on the next poll, which is acceptable.
    if (el && el.parentNode) {
      el.parentNode.removeChild(el);
    }
    fetch('api/announcements.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ action: 'dismiss', announcement_id: id, csrf_token: csrfToken })
    }).catch(function () { /* silent */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
