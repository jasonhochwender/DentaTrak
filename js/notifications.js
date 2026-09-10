/**
 * Notifications Module
 * Handles notification bell, dropdown, and unread counts
 */

(function() {
  'use strict';

  var notificationDropdownOpen = false;
  var pollInterval = null;
  var notificationsLoading = false;
  var lastNotificationsLoad = 0;
  var notificationCacheTtl = 30000; // 30 seconds
  var bodyOverflowBeforeNotifications = null;
  var currentNotificationFilter = 'all';

  /**
   * Initialize notifications
   */
  window.initNotifications = function() {
    var flags = window.featureFlags || {};
    if (!flags.SHOW_NOTIFICATIONS && !window.notificationsEnabled) {
      return;
    }

    setupNotificationBell();
    refreshNotificationCount();
    processPendingNotification();

    // Preload the notification list after the page is usable so the panel
    // opens with cached data and only refreshes in the background if stale.
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      setTimeout(function() { loadNotifications(); }, 100);
    } else {
      document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() { loadNotifications(); }, 100);
      });
    }

    // Poll for new notifications every 60 seconds
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(refreshNotificationCount, 60000);
  };

  /**
   * Setup notification bell click handler
   */
  function setupNotificationBell() {
    var bell = document.getElementById('notificationBell');
    if (!bell) return;

    bell.addEventListener('click', function(e) {
      e.stopPropagation();
      toggleNotificationDropdown();
    });

    var closeBtn = document.getElementById('notificationDropdownClose');
    if (closeBtn) {
      closeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        closeNotificationDropdown();
      });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      var dropdown = document.getElementById('notificationDropdown');
      if (dropdown && notificationDropdownOpen && !dropdown.contains(e.target)) {
        closeNotificationDropdown();
      }
    });
  }

  /**
   * Toggle notification dropdown
   */
  function toggleNotificationDropdown() {
    if (notificationDropdownOpen) {
      closeNotificationDropdown();
    } else {
      openNotificationDropdown();
    }
  }

  /**
   * Open notification dropdown
   */
  function openNotificationDropdown() {
    var dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;

    // Only one header dropdown/panel should be open at a time
    if (window.closeUserMenu) window.closeUserMenu();
    if (window.closePracticeSwitcher) window.closePracticeSwitcher();
    if (window.closeSettingsBillingModal) window.closeSettingsBillingModal(true);

    dropdown.classList.add('open');
    notificationDropdownOpen = true;

    // Prevent background scrolling on the mobile side sheet, but do not change
    // the desktop dropdown behavior.
    if (window.innerWidth <= 480) {
      bodyOverflowBeforeNotifications = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
    }

    // If we already have rendered rows and the cache is fresh, do not refetch.
    var list = document.getElementById('notificationList');
    var hasData = list && list.querySelectorAll('.notification-item').length > 0;
    var isFresh = (Date.now() - lastNotificationsLoad) < notificationCacheTtl;
    if (hasData && isFresh) {
      updateMarkAllReadState();
      return;
    }

    loadNotifications();
  }

  /**
   * Close notification dropdown
   */
  function closeNotificationDropdown() {
    var dropdown = document.getElementById('notificationDropdown');
    if (dropdown) {
      dropdown.classList.remove('open');
    }
    notificationDropdownOpen = false;

    if (bodyOverflowBeforeNotifications !== null) {
      document.body.style.overflow = bodyOverflowBeforeNotifications || '';
      bodyOverflowBeforeNotifications = null;
    }
  }

  /**
   * Refresh notification count
   */
  window.refreshNotificationCount = function() {
    return fetch('api/notifications.php?action=count', {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        updateNotificationBadge(data.count);
      }
    })
    .catch(function(error) {
      console.error('Error fetching notification count:', error);
    });
  };

  /**
   * Update notification badge
   */
  function updateNotificationBadge(count) {
    var badge = document.getElementById('notificationBadge');
    if (!badge) return;

    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.classList.remove('hidden');
    } else {
      badge.textContent = '';
      badge.classList.add('hidden');
    }
  }

  /**
   * Load notifications list
   */
  function loadNotifications() {
    var list = document.getElementById('notificationList');
    if (!list) return;
    if (notificationsLoading) return;
    notificationsLoading = true;

    list.innerHTML = '<div class="notification-dropdown-empty">Loading...</div>';

    var requestStart = performance.now();
    var url = 'api/notifications.php?limit=20';
    if (currentNotificationFilter === 'unread') {
      url += '&unread_only=true';
    }
    fetch(url, {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      notificationsLoading = false;
      if (data.success) {
        lastNotificationsLoad = Date.now();
        renderNotifications(data.notifications);
      }
    })
    .catch(function(error) {
      notificationsLoading = false;
      console.error('Error loading notifications:', error);
      var errorMsg = (typeof NetworkErrorHandler !== 'undefined' && NetworkErrorHandler.isNetworkError(error))
        ? 'Connection lost. Check your internet.'
        : 'Error loading notifications';
      list.innerHTML = '<div class="notification-dropdown-empty">' + errorMsg + '</div>';
    });
  }

  /**
   * Translate a notification type into a concise, non-PHI description.
   */
  function getNotificationText(n) {
    var name = n.from_user_name || 'Unknown';
    var params = { from: name };

    var type = n.type || 'mention';
    var hasMultiple = Array.isArray(n.categories) && n.categories.length > 1;

    if (hasMultiple && type !== 'mention') {
      return t('notifications.case_details_changed', params);
    }

    var key = 'notifications.' + type;
    if (n.type === 'mention') {
      return t('notifications.mention', params);
    }

    return t(key, params) || t('notifications.case_details_changed', params);
  }

  /**
   * Parse a notification metadata value, which may be an object or a
   * JSON-encoded string from legacy or third-party sources.
   */
  function parseNotificationMetadata(n) {
    if (!n.metadata) {
      return null;
    }
    if (typeof n.metadata === 'string') {
      try {
        return JSON.parse(n.metadata);
      } catch (e) {
        return null;
      }
    }
    if (typeof n.metadata === 'object' && n.metadata !== null) {
      return n.metadata;
    }
    return null;
  }

  /**
   * Resolve the most relevant case-modal tab for a notification.
   * Explicit categories take precedence; if they are absent, fall back to
   * the notification type. Anything unknown defaults to Details.
   */
  function resolveNotificationTab(type, categories) {
    type = (type || '').toString();
    categories = Array.isArray(categories) ? categories : [];

    if (categories.indexOf('details') !== -1) {
      return 'details';
    }
    if (categories.indexOf('comments') !== -1 || categories.indexOf('mention') !== -1) {
      return 'comments';
    }
    if (categories.indexOf('files') !== -1) {
      return 'files';
    }

    if (type === 'mention' || type === 'comment' || type === 'new_comment' || type === 'comment_reply') {
      return 'comments';
    }
    if (type === 'file_added' || type === 'file_deleted' || type === 'file_changed' || type === 'attachment_added') {
      return 'files';
    }

    return 'details';
  }

  /**
   * Resolve a specific comment identifier for the notification, preferring
   * the top-level row and falling back to metadata.
   */
  function resolveNotificationCommentId(n) {
    if (n.comment_id) {
      return String(n.comment_id);
    }
    var metadata = parseNotificationMetadata(n);
    if (metadata && (metadata.comment_id || metadata.commentId)) {
      return String(metadata.comment_id || metadata.commentId);
    }
    return null;
  }

  /**
   * Render notifications list
   */
  function renderNotifications(notifications) {
    var list = document.getElementById('notificationList');
    if (!list) return;

    if (!notifications || notifications.length === 0) {
      var emptyMessage = currentNotificationFilter === 'unread'
        ? (t('notifications.no_unread') || 'No unread notifications')
        : (t('notifications.empty') || 'No notifications');
      list.innerHTML = '<div class="notification-dropdown-empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
        '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>' +
        '<path d="M13.73 21a2 2 0 0 1-3.46 0"></path>' +
        '</svg>' +
        '<div>' + emptyMessage + '</div>' +
        '</div>';
      updateMarkAllReadState();
      return;
    }

    var html = notifications.map(function(n) {
      var initials = getInitials(n.from_user_name);
      var timeAgo = formatTimeAgo(n.created_at);
      var text = getNotificationText(n);
      var dismissLabel = t('notifications.dismiss') || 'Dismiss';
      var tab = resolveNotificationTab(n.type, n.categories);
      var commentId = resolveNotificationCommentId(n);
      var tabAttr = 'data-tab="' + escapeHtml(tab) + '" ';
      var commentAttr = commentId ? 'data-comment-id="' + escapeHtml(commentId) + '" ' : '';

      return '<div class="notification-item' + (n.is_read ? '' : ' unread') + '" ' +
        'data-notification-id="' + n.id + '" ' +
        'data-case-id="' + escapeHtml(n.case_id || '') + '" ' +
        tabAttr +
        commentAttr +
        'onclick="window.handleNotificationClick(this)">' +
        '<div class="notification-item-avatar">' + initials + '</div>' +
        '<div class="notification-item-content">' +
        '<div class="notification-item-text">' + escapeHtml(text) + '</div>' +
        '<div class="notification-item-meta">' +
        '<span>' + timeAgo + '</span>' +
        '<span class="notification-item-case">' + t('notifications.view_case') + '</span>' +
        '<button type="button" class="notification-item-dismiss" ' +
        'aria-label="' + escapeHtml(dismissLabel) + '" ' +
        'data-notification-id="' + n.id + '" ' +
        'onclick="event.stopPropagation(); window.dismissNotification(' + n.id + ')">' +
        escapeHtml(dismissLabel) + '</button>' +
        '</div>' +
        '</div>' +
        (n.is_read ? '' : '<div class="notification-unread-dot"></div>') +
        '</div>';
    }).join('');

    list.innerHTML = html;
    updateMarkAllReadState();
  }

  /**
   * Open a notification destination securely.
   * Verifies the notification belongs to the user and they still have case
   * access before opening the case modal or archived-case view.
   */
  var openingNotificationDestination = false;

  function openNotificationDestination(notificationId) {
    if (openingNotificationDestination) return;
    openingNotificationDestination = true;

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/notification-destination.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        notification_id: notificationId
      })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      openingNotificationDestination = false;
      if (data.success) {
        var options = {
          tab: resolveNotificationTab(data.type, data.categories),
          commentId: data.comment_id ? String(data.comment_id) : null
        };
        if (data.is_archived && typeof window.viewArchivedCase === 'function') {
          window.viewArchivedCase(data.case_id);
        } else if (typeof window.openCaseById === 'function') {
          window.openCaseById(data.case_id, options);
        }
      } else if (data.code === 'practice_mismatch') {
        // Save the intended destination and switch practice safely.
        try {
          sessionStorage.setItem('pendingNotification', JSON.stringify({
            notification_id: data.notification_id
          }));
        } catch (e) {}
        window.location.href = 'api/select-practice.php?practice_id=' + encodeURIComponent(data.practice_id) + '&redirect=1';
      } else if (data.code === 'logged_out') {
        try {
          sessionStorage.setItem('pendingNotification', JSON.stringify({
            notification_id: notificationId
          }));
        } catch (e) {}
        window.location.href = 'login.php';
      } else {
        if (typeof showToast === 'function') {
          showToast(t('notifications.unavailable'), 'error');
        }
      }
    })
    .catch(function(error) {
      openingNotificationDestination = false;
      if (typeof showToast === 'function') {
        showToast(t('notifications.unavailable'), 'error');
      }
    });
  }

  /**
   * Resume a pending notification destination after login or practice switch.
   */
  function processPendingNotification() {
    try {
      var pending = sessionStorage.getItem('pendingNotification');
      if (!pending) return;
      var parsed = JSON.parse(pending);
      sessionStorage.removeItem('pendingNotification');
      if (parsed && parsed.notification_id) {
        openNotificationDestination(parsed.notification_id);
      }
    } catch (e) {}
  }

  /**
   * Handle notification click
   */
  window.handleNotificationClick = function(element) {
    var notificationId = element.getAttribute('data-notification-id');
    var caseId = element.getAttribute('data-case-id');
    var tab = element.getAttribute('data-tab') || 'details';
    var commentId = element.getAttribute('data-comment-id') || null;
    var options = { tab: tab };
    if (commentId) {
      options.commentId = commentId;
    }

    // Close dropdown
    closeNotificationDropdown();

    // Start navigation immediately. get-case.php and the destination endpoint
    // continue to enforce practice membership and case access. Read-state
    // persistence is best-effort and must not block opening the case.
    // In-app rows carry the resolved tab/comment hint; email/logged-out deep
    // links rely on the secure notification-destination API.
    if (caseId && !element.hasAttribute('data-require-destination')) {
      if (typeof window.openCaseById === 'function') {
        window.openCaseById(caseId, options);
      }
    } else if (notificationId && element.hasAttribute('data-require-destination')) {
      openNotificationDestination(notificationId);
    }

    // Mark as read in the background; the UI is only updated when the server
    // confirms. If the request fails, the case has already opened and the
    // notification stays unread.
    markNotificationRead(notificationId);
  };

  /**
   * Mark notification as read
   */
  function markNotificationRead(notificationId) {
    if (!notificationId) {
      return Promise.resolve();
    }

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    return fetch('api/notifications.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'mark_read',
        notification_id: notificationId
      })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (!data || data.success === false) {
        if (typeof showToast === 'function') {
          showToast(data && data.message ? data.message : t('notifications.mark_read_error'), 'error');
        }
        lastNotificationsLoad = 0;
        return refreshNotificationCount();
      }

      // Update the rendered row immediately so the panel feels responsive.
      var row = document.querySelector('#notificationList .notification-item[data-notification-id="' + notificationId + '"]');
      if (row) {
        row.classList.remove('unread');
        var dot = row.querySelector('.notification-unread-dot');
        if (dot) {
          dot.remove();
        }
      }

      updateMarkAllReadState();

      // Invalidate the cached list so the next panel open reflects the read state.
      lastNotificationsLoad = 0;
      return refreshNotificationCount();
    })
    .catch(function(error) {
      console.error('Error marking notification read:', error);
      if (typeof showToast === 'function') {
        showToast(t('notifications.mark_read_error_retry'), 'error');
      }
    });
  }

  /**
   * Mark all notifications as read
   */
  window.markAllNotificationsRead = function() {
    var btn = document.querySelector('.notification-mark-all');
    if (btn && (btn.disabled || btn.getAttribute('aria-disabled') === 'true')) {
      return;
    }

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/notifications.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'mark_read',
        mark_all: true
      })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        // Optimistically update the already-rendered rows before refetching.
        var rows = document.querySelectorAll('#notificationList .notification-item.unread');
        rows.forEach(function(row) {
          row.classList.remove('unread');
          var dot = row.querySelector('.notification-unread-dot');
          if (dot) dot.remove();
        });
        refreshNotificationCount();
        updateNotificationBadge(0);
        updateMarkAllReadState();
        loadNotifications();
      } else {
        console.error('Mark all read failed:', data.message);
      }
    })
    .catch(function(error) {
      console.error('Error marking all notifications read:', error);
    });
  };

  /**
   * Dismiss a single notification from the current user's panel.
   */
  window.dismissNotification = function(notificationId) {
    if (!notificationId) return;

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    var row = document.querySelector('#notificationList .notification-item[data-notification-id="' + notificationId + '"]');
    if (row) {
      row.style.opacity = '0.5';
      row.setAttribute('aria-busy', 'true');
    }

    fetch('api/notifications.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'dismiss',
        notification_id: notificationId
      })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        if (row) {
          row.remove();
        }
        // If the row was unread, refresh the badge count.
        if (row && row.classList.contains('unread')) {
          refreshNotificationCount();
        }
        // Re-render empty state if the list is now empty.
        var list = document.getElementById('notificationList');
        if (list && !list.querySelector('.notification-item')) {
          renderNotifications([]);
        }
        updateMarkAllReadState();
      } else {
        if (row) {
          row.style.opacity = '';
          row.removeAttribute('aria-busy');
        }
        console.error('Dismiss notification failed:', data.message);
      }
    })
    .catch(function(error) {
      if (row) {
        row.style.opacity = '';
        row.removeAttribute('aria-busy');
      }
      console.error('Error dismissing notification:', error);
    });
  };

  /**
   * Switch the notification list filter between All and Unread.
   */
  window.switchNotificationFilter = function(filter) {
    if (filter !== 'all' && filter !== 'unread') return;
    currentNotificationFilter = filter;

    var buttons = document.querySelectorAll('.notification-filter-btn');
    buttons.forEach(function(btn) {
      var isActive = btn.getAttribute('data-filter') === filter;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    lastNotificationsLoad = 0;
    loadNotifications();
  };

  /**
   * Enable or disable "Mark all as read" based on unread rows.
   */
  function updateMarkAllReadState() {
    var btn = document.querySelector('.notification-mark-all');
    if (!btn) return;
    var unread = document.querySelectorAll('#notificationList .notification-item.unread').length;
    btn.disabled = unread === 0;
    btn.setAttribute('aria-disabled', unread === 0 ? 'true' : 'false');
  }

  /**
   * Get initials from name
   */
  function getInitials(name) {
    if (!name) return '?';
    var parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.substring(0, 2).toUpperCase();
  }

  /**
   * Format time ago
   */
  function formatTimeAgo(dateString) {
    var date = new Date(dateString);
    var now = new Date();
    var diffMs = now - date;
    var diffMins = Math.floor(diffMs / 60000);
    var diffHours = Math.floor(diffMs / 3600000);
    var diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return diffMins + 'm ago';
    if (diffHours < 24) return diffHours + 'h ago';
    if (diffDays < 7) return diffDays + 'd ago';
    
    return date.toLocaleDateString();
  }

  /**
   * Escape HTML
   */
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Initialize on DOM ready if the feature is enabled or explicitly enabled
  var flags = window.featureFlags || {};
  var enabled = flags.SHOW_NOTIFICATIONS || window.notificationsEnabled;

  if (enabled) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', window.initNotifications);
    } else {
      window.initNotifications();
    }
  }

  // Expose deep-link helpers for the main.php resume path
  window.openNotificationDestination = openNotificationDestination;
  window.processPendingNotification = processPendingNotification;
  window.closeNotificationDropdown = closeNotificationDropdown;
  window.openNotificationDropdown = openNotificationDropdown;

})();
