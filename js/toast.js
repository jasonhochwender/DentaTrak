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
