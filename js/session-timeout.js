/**
 * Session Timeout Handler
 * Monitors session activity and warns users before timeout
 */

(function() {
  'use strict';

  // Configuration (will be updated from server on each status check)
  var sessionTimeout = 60 * 60 * 1000;  // 60 minutes in ms
  var warningTime = 5 * 60 * 1000;      // 5 minutes warning in ms
  var checkInterval = 60 * 1000;         // Normal check interval
  var warningCheckInterval = 10 * 1000;  // Faster checks while warning is visible
  var initialCheckDelay = 5 * 1000;      // First status check after 5 seconds
  var sessionCheckTimer = null;

  var warningModal = null;
  var countdownInterval = null;
  var lastActivityTime = Date.now();
  var isWarningShown = false;

  /**
   * Initialize session timeout monitoring
   */
  function init() {
    // Track user activity
    trackActivity();

    // Start periodic session check; the first check is sooner so the
    // warning/redirect is shown quickly even if the tab has been suspended.
    scheduleSessionCheck(initialCheckDelay);
  }

  /**
   * Schedule the next session status check.
   */
  function scheduleSessionCheck(initialDelay) {
    if (sessionCheckTimer) {
      clearTimeout(sessionCheckTimer);
    }
    sessionCheckTimer = setTimeout(function runAndReschedule() {
      checkSessionStatus().then(function(data) {
        var nextInterval = (data && data.showWarning) ? warningCheckInterval : checkInterval;
        sessionCheckTimer = setTimeout(runAndReschedule, nextInterval);
      }).catch(function() {
        sessionCheckTimer = setTimeout(runAndReschedule, checkInterval);
      });
    }, initialDelay);
  }

  /**
   * Track user activity to reset the timeout
   */
  var activityPingTimeout = null;
  var lastServerPing = 0;
  var ACTIVITY_PING_INTERVAL = 60 * 1000; // Ping server at most once per minute

  function trackActivity() {
    // Only genuine interaction within the DentaTrak page should reset the
    // inactivity timer. mousemove, pointermove, and focus/visibility changes
    // are intentionally excluded so that automated mouse utilities, screen
    // savers, or returning to a tab cannot silently extend the session.
    var activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'pointerdown'];

    activityEvents.forEach(function(event) {
      document.addEventListener(event, function() {
        lastActivityTime = Date.now();

        // If warning is shown and user is active, extend session immediately
        if (isWarningShown) {
          extendSession();
          return;
        }

        // Debounced ping to server to reset inactivity timer
        // Only ping if we haven't pinged recently
        if (Date.now() - lastServerPing > ACTIVITY_PING_INTERVAL) {
          if (activityPingTimeout) {
            clearTimeout(activityPingTimeout);
          }
          activityPingTimeout = setTimeout(pingServerActivity, 1000);
        }
      }, { passive: true });
    });
  }

  /**
   * Ping server to reset inactivity timer
   */
  function pingServerActivity() {
    lastServerPing = Date.now();
    fetch('api/session-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'activity' })
    }).catch(function() {
      // Silently ignore errors - this is just a keep-alive ping
    });
  }

  /**
   * Check session status with server
   */
  function checkSessionStatus() {
    return fetch('api/session-status.php', {
      credentials: 'same-origin'
    })
    .then(function(response) {
      // A 503 from the session store is a transient lock timeout or store
      // failure, not a logout. Treat it as retryable and continue polling.
      if (response.status === 503) {
        console.warn('Session store temporarily unavailable (503). Will retry.');
        return { retry: true };
      }

      if (response.status === 401) {
        // Server says the session is no longer valid (expired or missing).
        return { loggedIn: false, reason: 'unauthorized' };
      }

      if (!response.ok) {
        console.warn('Session status request failed with status ' + response.status + '. Will retry.');
        return { retry: true };
      }

      return response.json();
    })
    .then(function(data) {
      if (data && data.retry) {
        // Temporary store or network failure; do not log the user out.
        return data;
      }

      // The server is the authoritative source. If it says the session is
      // gone, or if the remaining time has hit zero, redirect now.
      if (!data || !data.loggedIn || data.timeRemaining <= 0) {
        redirectToLogin(data && data.reason === 'inactivity' ? 'timeout=1' : 'session_expired=1');
        return data;
      }

      // Update configuration from server
      sessionTimeout = data.timeout * 1000;
      warningTime = data.warningTime * 1000;

      if (data.showWarning && !isWarningShown) {
        showWarningModal(data.timeRemaining * 1000);
      } else if (!data.showWarning && isWarningShown) {
        hideWarningModal();
      } else if (data.showWarning && isWarningShown) {
        // Resync the countdown if the server reports a different remaining time.
        // This keeps multi-tab behavior and tab suspension correct.
        startCountdown(data.timeRemaining * 1000);
      }

      return data;
    })
    .catch(function(error) {
      console.error('Session check failed:', error);
    });
  }

  /**
   * Show the session timeout warning modal
   */
  function showWarningModal(timeRemaining) {
    if (isWarningShown) return;
    isWarningShown = true;

    // Create modal
    warningModal = document.createElement('div');
    warningModal.id = 'sessionTimeoutModal';
    warningModal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 100000; display: flex; align-items: center; justify-content: center;';

    var content = document.createElement('div');
    content.style.cssText = 'background: white; border-radius: 12px; padding: 32px; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);';

    content.innerHTML =
      '<div style="width: 64px; height: 64px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">' +
        '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<circle cx="12" cy="12" r="10"/>' +
          '<polyline points="12 6 12 12 16 14"/>' +
        '</svg>' +
      '</div>' +
      '<h2 style="margin: 0 0 12px; font-size: 1.4rem; color: #1f2937;">' + t('session.expiring_title') + '</h2>' +
      '<p style="margin: 0 0 8px; color: #6b7280; font-size: 1rem;">' +
        t('session.expiring_text') +
      '</p>' +
      '<div id="sessionCountdown" style="font-size: 2rem; font-weight: 700; color: #d97706; margin: 16px 0;"></div>' +
      '<p style="margin: 0 0 24px; color: #9ca3af; font-size: 0.9rem;">' +
        t('session.expiring_subtext') +
      '</p>' +
      '<div style="display: flex; gap: 12px; justify-content: center;">' +
        '<button id="sessionLogoutBtn" style="padding: 12px 24px; border: 1px solid #d1d5db; background: white; border-radius: 8px; font-size: 0.95rem; cursor: pointer; color: #374151;">' + t('session.logout_now') + '</button>' +
        '<button id="sessionExtendBtn" style="padding: 12px 24px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 8px; font-size: 0.95rem; cursor: pointer; font-weight: 500;">' + t('session.stay_logged_in') + '</button>' +
      '</div>';

    warningModal.appendChild(content);
    document.body.appendChild(warningModal);

    // Start countdown
    startCountdown(timeRemaining);

    // Button handlers
    document.getElementById('sessionExtendBtn').addEventListener('click', extendSession);
    document.getElementById('sessionLogoutBtn').addEventListener('click', function() {
      window.location.href = 'api/logout.php';
    });
  }

  /**
   * Start the countdown timer
   */
  function startCountdown(timeRemaining) {
    if (countdownInterval) {
      clearInterval(countdownInterval);
      countdownInterval = null;
    }

    var endTime = Date.now() + timeRemaining;

    function updateCountdown() {
      var remaining = Math.max(0, endTime - Date.now());
      var minutes = Math.floor(remaining / 60000);
      var seconds = Math.floor((remaining % 60000) / 1000);

      var countdownEl = document.getElementById('sessionCountdown');
      if (countdownEl) {
        countdownEl.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
      }

      if (remaining <= 0) {
        // The client-side countdown reached zero, but the real decision is
        // made by the server. Ask the server immediately instead of assuming
        // the session is gone; this prevents one tab from logging the user out
        // when another tab just extended the session.
        clearInterval(countdownInterval);
        countdownInterval = null;
        checkSessionStatus();
      }
    }

    updateCountdown();
    countdownInterval = setInterval(updateCountdown, 1000);
  }

  /**
   * Hide the warning modal
   */
  function hideWarningModal() {
    isWarningShown = false;

    if (countdownInterval) {
      clearInterval(countdownInterval);
      countdownInterval = null;
    }

    if (warningModal && warningModal.parentNode) {
      warningModal.parentNode.removeChild(warningModal);
      warningModal = null;
    }
  }

  /**
   * Extend the session
   */
  function extendSession() {
    fetch('api/session-status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'same-origin',
      body: JSON.stringify({ action: 'extend' })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        hideWarningModal();
        lastActivityTime = Date.now();

        // Show confirmation toast if available
        if (typeof showToast === 'function') {
          showToast(t('session.extended'), 'success');
        }
      } else {
        // Server rejected the extension (expired or not logged in)
        redirectToLogin('session_expired=1');
      }
    })
    .catch(function(error) {
      console.error('Failed to extend session:', error);
    });
  }

  /**
   * Handle session expiration reported by the server status check.
   */
  function handleSessionExpired() {
    hideWarningModal();
    redirectToLogin('session_expired=1');
  }

  /**
   * Redirect to login with the appropriate query string.
   * timeout=1 is used only for genuine inactivity timeout.
   * session_expired=1 is used when the server reports the session missing
   * or otherwise invalid, so the user sees a generic message.
   */
  function redirectToLogin(params) {
    var url = 'login.php?' + params;
    var notificationId = new URLSearchParams(window.location.search).get('notification_id');
    if (notificationId) {
      url += '&notification_id=' + encodeURIComponent(notificationId);
    }
    window.location.href = url;
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
