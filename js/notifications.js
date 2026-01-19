/**
 * Notifications Module
 * Handles notification bell, dropdown, and unread counts
 */

(function() {
  'use strict';

  var notificationDropdownOpen = false;
  var pollInterval = null;

  /**
   * Initialize notifications
   */
  window.initNotifications = function() {
    setupNotificationBell();
    refreshNotificationCount();
    
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

    list.innerHTML = '<div class="notification-dropdown-empty">Loading...</div>';

    fetch('api/notifications.php?limit=20', {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        renderNotifications(data.notifications);
      }
    })
    .catch(function(error) {
      console.error('Error loading notifications:', error);
      var errorMsg = (typeof NetworkErrorHandler !== 'undefined' && NetworkErrorHandler.isNetworkError(error))
        ? 'Connection lost. Check your internet.'
        : 'Error loading notifications';
      list.innerHTML = '<div class="notification-dropdown-empty">' + errorMsg + '</div>';
    });
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
        '<div>No notifications</div>' +
        '</div>';
      return;
    }

    var html = notifications.map(function(n) {
      var initials = getInitials(n.from_user_name);
      var timeAgo = formatTimeAgo(n.created_at);
      var caseLabel = n.patient_name ? n.patient_name : 'a case';
      
      return '<div class="notification-item' + (n.is_read ? '' : ' unread') + '" ' +
        'data-notification-id="' + n.id + '" ' +
        'data-case-id="' + (n.case_id || '') + '" ' +
        'onclick="window.handleNotificationClick(this)">' +
        '<div class="notification-item-avatar">' + initials + '</div>' +
        '<div class="notification-item-content">' +
        '<div class="notification-item-text">' +
        '<strong>' + escapeHtml(n.from_user_name) + '</strong> mentioned you in ' + escapeHtml(caseLabel) +
        '</div>' +
        '<div class="notification-item-meta">' +
        '<span>' + timeAgo + '</span>' +
        (n.case_id ? '<span class="notification-item-case">View case →</span>' : '') +
        '</div>' +
        '</div>' +
        (n.is_read ? '' : '<div class="notification-unread-dot"></div>') +
        '</div>';
    }).join('');

    list.innerHTML = html;
  }

  /**
   * Handle notification click
   */
  window.handleNotificationClick = function(element) {
    var notificationId = element.getAttribute('data-notification-id');
    var caseId = element.getAttribute('data-case-id');

    // Mark as read
    markNotificationRead(notificationId);

    // Close dropdown
    closeNotificationDropdown();

    // Open case if we have a case ID
    if (caseId && typeof window.openCaseById === 'function') {
      window.openCaseById(caseId);
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
    .then(function() {
      refreshNotificationCount();
      loadNotifications();
    })
    .catch(function(error) {
      console.error('Error marking all notifications read:', error);
    });
  };

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

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initNotifications);
  } else {
    window.initNotifications();
  }

})();
