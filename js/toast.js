/**
 * Toast Notification System
 * Provides elegant toast notifications for the application
 */

// Toast manager
const Toast = {
  // Default configuration
  defaultConfig: {
    duration: 5000,
    closable: true,
    progressBar: true
  },
  
  // Container reference
  container: null,
  
  // Initialize the toast system
  init: function() {
    this.container = document.getElementById('toastContainer');
    if (!this.container) {
      return false;
    }
    return true;
  },
  
  /**
   * Show a toast notification
   * @param {Object} options - Toast configuration options
   * @param {string} options.type - Toast type ('success', 'error', 'info', 'warning')
   * @param {string} options.title - Toast title
   * @param {string} options.message - Toast message
   * @param {number} options.duration - Duration in milliseconds
   * @param {boolean} options.closable - Whether toast can be closed manually
   * @param {boolean} options.progressBar - Whether to show progress bar
   */
  show: function(options) {
    // Check if container exists
    if (!this.container) {
      if (!this.init()) {
        return;
      }
    }
    
    // Merge with default configuration
    const config = { ...this.defaultConfig, ...options };
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${config.type || 'info'}`;
    
    // Create icon based on type
    let iconHtml = '';
    switch (config.type) {
      case 'success':
        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        break;
      case 'error':
        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
        break;
      case 'warning':
        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        break;
      default: // info
        iconHtml = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
        break;
    }
    
    // Build toast HTML
    toast.innerHTML = `
      <div class="toast-icon">${iconHtml}</div>
      <div class="toast-content">
        ${config.title ? `<div class="toast-title">${config.title}</div>` : ''}
        ${config.message ? `<div class="toast-message">${config.message}</div>` : ''}
      </div>
      ${config.closable ? '<button class="toast-close">&times;</button>' : ''}
      ${config.progressBar ? '<div class="toast-progress"></div>' : ''}
    `;
    
    // Add to container
    this.container.appendChild(toast);
    
    // Set up progress bar animation
    if (config.progressBar) {
      const progressBar = toast.querySelector('.toast-progress');
      progressBar.style.transition = `width ${config.duration / 1000}s linear`;
      setTimeout(() => {
        progressBar.style.width = '0%';
      }, 10);
    }
    
    // Show the toast (with a slight delay for animation)
    setTimeout(() => {
      toast.classList.add('show');
    }, 10);
    
    // Set up close button
    if (config.closable) {
      const closeButton = toast.querySelector('.toast-close');
      closeButton.addEventListener('click', () => {
        this.dismiss(toast);
      });
    }
    
    // Auto dismiss after duration
    if (config.duration > 0) {
      setTimeout(() => {
        this.dismiss(toast);
      }, config.duration);
    }
    
    return toast;
  },
  
  /**
   * Success toast shorthand
   */
  success: function(title, message, options = {}) {
    return this.show({
      type: 'success',
      title,
      message,
      ...options
    });
  },
  
  /**
   * Error toast shorthand
   */
  error: function(title, message, options = {}) {
    return this.show({
      type: 'error',
      title,
      message,
      ...options
    });
  },
  
  /**
   * Info toast shorthand
   */
  info: function(title, message, options = {}) {
    return this.show({
      type: 'info',
      title,
      message,
      ...options
    });
  },
  
  /**
   * Warning toast shorthand
   */
  warning: function(title, message, options = {}) {
    return this.show({
      type: 'warning',
      title,
      message,
      ...options
    });
  },
  
  /**
   * Dismiss a toast
   * @param {HTMLElement} toast - Toast element to dismiss
   */
  dismiss: function(toast) {
    toast.classList.remove('show');
    
    // Remove after animation
    setTimeout(() => {
      if (toast.parentNode === this.container) {
        this.container.removeChild(toast);
      }
    }, 300);
  },
  
  /**
   * Clear all toasts
   */
  clear: function() {
    const toasts = this.container.querySelectorAll('.toast');
    toasts.forEach(toast => {
      this.dismiss(toast);
    });
  }
};

// Initialize toast system when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  Toast.init();
});

/**
 * Network Error Handler
 * Provides unified handling for network/fetch errors with user-friendly messages
 */
const NetworkErrorHandler = {
  // Track offline state to avoid duplicate notifications
  _isOffline: false,
  _offlineToast: null,
  
  /**
   * Check if an error is a network-related error
   * @param {Error} error - The error object
   * @returns {boolean}
   */
  isNetworkError: function(error) {
    if (!error) return false;
    
    // Check for common network error indicators
    const networkErrorMessages = [
      'failed to fetch',
      'network error',
      'networkerror',
      'net::err_',
      'load failed',
      'network request failed',
      'the internet connection appears to be offline',
      'a]network error occurred',
      'err_internet_disconnected',
      'err_network_changed'
    ];
    
    const errorMessage = (error.message || '').toLowerCase();
    const errorName = (error.name || '').toLowerCase();
    
    // TypeError with "Failed to fetch" is the most common offline indicator
    if (error.name === 'TypeError' && errorMessage.includes('failed to fetch')) {
      return true;
    }
    
    // Check against known network error patterns
    for (const pattern of networkErrorMessages) {
      if (errorMessage.includes(pattern) || errorName.includes(pattern)) {
        return true;
      }
    }
    
    return false;
  },
  
  /**
   * Get a user-friendly message for the error
   * @param {Error} error - The error object
   * @param {string} context - Optional context (e.g., "saving case", "loading data")
   * @returns {string}
   */
  getUserFriendlyMessage: function(error, context) {
    if (this.isNetworkError(error) || !navigator.onLine) {
      return context 
        ? `Unable to ${context}. Please check your internet connection and try again.`
        : 'Connection lost. Please check your internet connection.';
    }
    
    if (error.name === 'AbortError') {
      return context
        ? `Request timed out while ${context}. Please try again.`
        : 'Request timed out. Please try again.';
    }
    
    // For other errors, return a generic message or the error message if it's user-friendly
    if (error.message && !error.message.includes('fetch') && error.message.length < 100) {
      return error.message;
    }
    
    return context
      ? `An error occurred while ${context}. Please try again.`
      : 'An error occurred. Please try again.';
  },
  
  /**
   * Handle a fetch/network error with appropriate toast notification
   * @param {Error} error - The error object
   * @param {string} context - Optional context for the error message
   * @param {Object} options - Additional options
   * @returns {void}
   */
  handle: function(error, context, options = {}) {
    const message = this.getUserFriendlyMessage(error, context);
    const isNetwork = this.isNetworkError(error) || !navigator.onLine;
    
    // Log the actual error for debugging
    console.error('Network/Fetch Error:', error, 'Context:', context);
    
    // Show toast notification
    if (typeof Toast !== 'undefined' && Toast.container) {
      Toast.error(
        isNetwork ? 'Connection Error' : 'Error',
        message,
        { duration: isNetwork ? 7000 : 5000 }
      );
    } else if (typeof showToast === 'function') {
      showToast(message, 'error');
    }
    
    // Call optional callback
    if (options.onError && typeof options.onError === 'function') {
      options.onError(error, isNetwork);
    }
  },
  
  /**
   * Initialize online/offline event listeners
   */
  init: function() {
    // Listen for online/offline events
    window.addEventListener('offline', () => {
      this._isOffline = true;
      if (typeof Toast !== 'undefined' && Toast.container) {
        this._offlineToast = Toast.warning(
          'You\'re Offline',
          'Your internet connection was lost. Some features may not work.',
          { duration: 0, closable: true } // Persistent until back online
        );
      }
    });
    
    window.addEventListener('online', () => {
      this._isOffline = false;
      // Dismiss the offline toast if it exists
      if (this._offlineToast && typeof Toast !== 'undefined') {
        Toast.dismiss(this._offlineToast);
        this._offlineToast = null;
        
        // Show a brief "back online" message
        Toast.success('Back Online', 'Your internet connection has been restored.', { duration: 3000 });
      }
    });
  }
};

// Initialize network error handler when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  NetworkErrorHandler.init();
});

/**
 * Wrapper for fetch that handles network errors gracefully
 * @param {string} url - The URL to fetch
 * @param {Object} options - Fetch options
 * @param {string} context - Context for error messages (e.g., "saving case")
 * @returns {Promise<Response>}
 */
async function safeFetch(url, options = {}, context = '') {
  try {
    const response = await fetch(url, options);
    return response;
  } catch (error) {
    NetworkErrorHandler.handle(error, context);
    throw error; // Re-throw so caller can handle if needed
  }
}
