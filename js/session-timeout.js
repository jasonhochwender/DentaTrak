/**
 * Session Timeout Handler
 * Monitors session activity and warns users before timeout
 */

(function() {
  'use strict';
  
  // Configuration (will be updated from server)
  var sessionTimeout = 30 * 60 * 1000;  // 30 minutes in ms
  var warningTime = 5 * 60 * 1000;      // 5 minutes warning in ms
  var checkInterval = 60 * 1000;         // Check every minute
  
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
    
    // Start periodic session check
    setInterval(checkSessionStatus, checkInterval);
    
    // Initial check
    setTimeout(checkSessionStatus, 5000);
  }
  
  /**
   * Track user activity to reset the timeout
   */
  var activityPingTimeout = null;
  var lastServerPing = 0;
  var ACTIVITY_PING_INTERVAL = 60 * 1000; // Ping server at most once per minute
  
  function trackActivity() {
    var activityEvents = ['mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
    
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
    fetch('/api/session-status.php', {
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
    fetch('/api/session-status.php', {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (!data.success || !data.loggedIn) {
        // Session expired or not logged in
        handleSessionExpired();
        return;
      }
      
      // Update configuration from server
      sessionTimeout = data.timeout * 1000;
      warningTime = data.warningTime * 1000;
      
      var timeRemaining = data.timeRemaining * 1000;
      
      if (data.showWarning && !isWarningShown) {
        showWarningModal(timeRemaining);
      } else if (!data.showWarning && isWarningShown) {
        hideWarningModal();
      }
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
      '<h2 style="margin: 0 0 12px; font-size: 1.4rem; color: #1f2937;">Session Expiring Soon</h2>' +
      '<p style="margin: 0 0 8px; color: #6b7280; font-size: 1rem;">' +
        'Your session will expire in:' +
      '</p>' +
      '<div id="sessionCountdown" style="font-size: 2rem; font-weight: 700; color: #d97706; margin: 16px 0;"></div>' +
      '<p style="margin: 0 0 24px; color: #9ca3af; font-size: 0.9rem;">' +
        'Click below to continue working, or you\'ll be logged out automatically.' +
      '</p>' +
      '<div style="display: flex; gap: 12px; justify-content: center;">' +
        '<button id="sessionLogoutBtn" style="padding: 12px 24px; border: 1px solid #d1d5db; background: white; border-radius: 8px; font-size: 0.95rem; cursor: pointer; color: #374151;">Log Out Now</button>' +
        '<button id="sessionExtendBtn" style="padding: 12px 24px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 8px; font-size: 0.95rem; cursor: pointer; font-weight: 500;">Stay Logged In</button>' +
      '</div>';
    
    warningModal.appendChild(content);
    document.body.appendChild(warningModal);
    
    // Start countdown
    startCountdown(timeRemaining);
    
    // Button handlers
    document.getElementById('sessionExtendBtn').addEventListener('click', extendSession);
    document.getElementById('sessionLogoutBtn').addEventListener('click', function() {
      window.location.href = '/api/logout.php';
    });
  }
  
  /**
   * Start the countdown timer
   */
  function startCountdown(timeRemaining) {
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
        clearInterval(countdownInterval);
        handleSessionExpired();
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
    fetch('/api/session-status.php', {
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
          showToast('Session extended. You can continue working.', 'success');
        }
      }
    })
    .catch(function(error) {
      console.error('Failed to extend session:', error);
    });
  }
  
  /**
   * Handle session expiration
   */
  function handleSessionExpired() {
    hideWarningModal();
    
    // Redirect to login with timeout message
    window.location.href = '/login.php?timeout=1';
  }
  
  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
