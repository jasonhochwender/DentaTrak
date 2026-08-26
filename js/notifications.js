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

    dropdown.classList.add('open');
    notificationDropdownOpen = true;

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
  }

  /**
   * Refresh notification count
   */
  window.refreshNotificationCount = function() {
    fetch('api/notifications.php?action=count', {
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
    fetch('api/notifications.php?limit=20', {
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
   * Render notifications list
   */
  function renderNotifications(notifications) {
    var list = document.getElementById('notificationList');
    if (!list) return;

    if (!notifications || notifications.length === 0) {
      list.innerHTML = '<div class="notification-dropdown-empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
        '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>' +
        '<path d="M13.73 21a2 2 0 0 1-3.46 0"></path>' +
        '</svg>' +
        '<div>' + t('notifications.empty') + '</div>' +
        '</div>';
      updateMarkAllReadState();
      return;
    }

    var html = notifications.map(function(n) {
      var initials = getInitials(n.from_user_name);
      var timeAgo = formatTimeAgo(n.created_at);
      var text = getNotificationText(n);

      return '<div class="notification-item' + (n.is_read ? '' : ' unread') + '" ' +
        'data-notification-id="' + n.id + '" ' +
        'data-case-id="' + escapeHtml(n.case_id || '') + '" ' +
        'onclick="window.handleNotificationClick(this)">' +
        '<div class="notification-item-avatar">' + initials + '</div>' +
        '<div class="notification-item-content">' +
        '<div class="notification-item-text">' + escapeHtml(text) + '</div>' +
        '<div class="notification-item-meta">' +
        '<span>' + timeAgo + '</span>' +
        '<span class="notification-item-case">' + t('notifications.view_case') + '</span>' +
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
        if (data.is_archived && typeof window.viewArchivedCase === 'function') {
          window.viewArchivedCase(data.case_id);
        } else if (typeof window.openCaseById === 'function') {
          window.openCaseById(data.case_id);
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

    // Mark as read asynchronously without blocking the case open.
    markNotificationRead(notificationId);

    // Close dropdown
    closeNotificationDropdown();

    // In-app: the authenticated list already returned a case_id for this user,
    // so open the authorized case modal directly. get-case.php will still
    // enforce current practice membership and canUserAccessCase().
    // Email/logged-out deep links continue to use the secure destination API.
    if (caseId && !element.hasAttribute('data-require-destination')) {
      openCaseById(caseId);
    } else if (notificationId) {
      openNotificationDestination(notificationId);
    }
  };

  /**
   * Mark notification as read
   */
  function markNotificationRead(notificationId) {
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
        notification_id: notificationId
      })
    })
    .then(function() {
      refreshNotificationCount();
    })
    .catch(function(error) {
      console.error('Error marking notification read:', error);
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

})();
