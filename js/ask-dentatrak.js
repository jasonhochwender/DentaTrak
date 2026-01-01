/**
 * Ask DentaTrak - Floating AI Chat
 * Provides app-wide AI assistance via a floating chat panel
 */

(function() {
  'use strict';

  var floatingContainer = null;
  var fabButton = null;
  var panel = null;
  var messagesContainer = null;
  var askInput = null;
  var askSubmit = null;
  var isLoading = false;

  /**
   * Initialize Ask DentaTrak functionality
   */
  function initAskDentatrak() {
    floatingContainer = document.getElementById('askDentatrakFloating');
    fabButton = document.getElementById('askDentatrakFab');
    panel = document.getElementById('askDentatrakPanel');
    messagesContainer = document.getElementById('askDentatrakMessages');
    askInput = document.getElementById('askDentatrakInput');
    askSubmit = document.getElementById('askDentatrakSubmit');

    if (!fabButton || !panel) return;

    // Toggle panel on FAB click
    fabButton.addEventListener('click', togglePanel);

    // Handle submit button click
    if (askSubmit) {
      askSubmit.addEventListener('click', handleAskSubmit);
    }

    // Handle Enter key in input
    if (askInput) {
      askInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          handleAskSubmit();
        }
      });
    }

    // Close panel when clicking outside
    document.addEventListener('click', function(e) {
      if (floatingContainer && 
          floatingContainer.classList.contains('open') && 
          !floatingContainer.contains(e.target)) {
        closePanel();
      }
    });
  }

  /**
   * Toggle panel open/closed
   */
  function togglePanel() {
    if (floatingContainer) {
      floatingContainer.classList.toggle('open');
      if (floatingContainer.classList.contains('open') && askInput) {
        setTimeout(function() { askInput.focus(); }, 100);
      }
    }
  }

  /**
   * Close panel
   */
  function closePanel() {
    if (floatingContainer) {
      floatingContainer.classList.remove('open');
    }
  }

  /**
   * Handle ask submission
   */
  function handleAskSubmit() {
    if (isLoading || !askInput) return;

    var query = askInput.value.trim();
    if (!query) return;

    // Add user message to chat
    addMessage(query, 'user');
    
    // Clear input
    askInput.value = '';

    // Show loading
    showLoading();

    // Send query to AI endpoint
    sendQuery(query);
  }

  /**
   * Add message to chat
   */
  function addMessage(content, type) {
    if (!messagesContainer) return;

    var messageDiv = document.createElement('div');
    messageDiv.className = 'ask-message ' + type;
    
    if (type === 'user') {
      messageDiv.textContent = content;
    } else {
      messageDiv.innerHTML = content;
    }
    
    messagesContainer.appendChild(messageDiv);
    
    // Scroll to bottom
    var body = messagesContainer.parentElement;
    if (body) {
      body.scrollTop = body.scrollHeight;
    }
  }

  /**
   * Show loading indicator
   */
  function showLoading() {
    isLoading = true;
    
    if (!messagesContainer) return;

    var loadingDiv = document.createElement('div');
    loadingDiv.className = 'ask-message assistant loading';
    loadingDiv.id = 'askLoadingMessage';
    loadingDiv.innerHTML = '<div class="ask-loading-dots"><span></span><span></span><span></span></div>';
    
    messagesContainer.appendChild(loadingDiv);
    
    // Scroll to bottom
    var body = messagesContainer.parentElement;
    if (body) {
      body.scrollTop = body.scrollHeight;
    }
  }

  /**
   * Remove loading indicator
   */
  function removeLoading() {
    var loadingMsg = document.getElementById('askLoadingMessage');
    if (loadingMsg) {
      loadingMsg.remove();
    }
    isLoading = false;
  }

  /**
   * Send query to AI recommendations endpoint
   */
  function sendQuery(query) {
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/ai-recommendations.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        query: query,
        type: 'ask'
      }),
      credentials: 'same-origin'
    })
    .then(function(response) {
      return response.json();
    })
    .then(function(data) {
      removeLoading();
      
      if (data.success && data.response) {
        addMessage(data.response, 'assistant');
      } else if (data.error) {
        addMessage('<p>Sorry, I couldn\'t process that request. ' + escapeHtml(data.error) + '</p>', 'assistant');
      } else {
        addMessage('<p>Sorry, I couldn\'t find an answer to that question. Try asking about case status, how to use features, or team performance.</p>', 'assistant');
      }
    })
    .catch(function(error) {
      removeLoading();
      console.error('Ask DentaTrak error:', error);
      addMessage('<p>Sorry, something went wrong. Please try again.</p>', 'assistant');
    });
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAskDentatrak);
  } else {
    initAskDentatrak();
  }

})();
