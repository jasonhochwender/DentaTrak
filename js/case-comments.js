/**
 * Case Comments Module
 * Handles internal comment threads with @mentions for cases
 */

(function() {
  'use strict';

  // State
  var currentCaseId = null;
  var practiceUsers = [];
  var mentionAutocompleteOpen = false;
  var mentionSearchTerm = '';
  var mentionStartPos = -1;
  var selectedMentionIndex = 0;
  var activeLoadId = 0;
  var selectedMentions = []; // { user_id, token, name }

  /**
   * Initialize comments for a case
   */
  window.initCaseComments = function(caseId) {
    currentCaseId = caseId;
    selectedMentions = [];
    closeMentionAutocomplete();
    loadComments(caseId);
    loadPracticeUsers();
    setupCommentInput();
  };

  /**
   * Load comments for a case
   */
  function loadComments(caseId) {
    var list = document.getElementById('caseCommentsList');
    if (!list) return;

    var loadId = ++activeLoadId;
    fetch('api/case-comments.php?case_id=' + encodeURIComponent(caseId), {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success && loadId === activeLoadId) {
        renderComments(data.comments);
        updateCommentCount(data.comments.length);

        // Mark notifications for this case as read
        markCaseNotificationsRead(caseId);
      }
    })
    .catch(function(error) {
      console.error('Error loading comments:', error);
    });
  }

  /**
   * Render comments list
   */
  function renderComments(comments) {
    var list = document.getElementById('caseCommentsList');
    if (!list) return;

    if (!comments || comments.length === 0) {
      list.innerHTML = '<div class="case-comments-empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
        '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>' +
        '</svg>' +
        '<div>' + t('cases.comments.empty') + '</div>' +
        '<div style="font-size: 0.75rem; margin-top: 4px;">' + t('cases.comments.mentions_hint') + '</div>' +
        '</div>';
      return;
    }

    var html = comments.map(function(comment) {
      var initials = getInitials(comment.user_name);
      var timeAgo = formatTimeAgo(comment.created_at);
      var textHtml = comment.is_deleted 
        ? '<span class="deleted-text">' + escapeHtml(comment.text) + '</span>'
        : highlightMentions(escapeHtml(comment.text));
      
      return '<div class="case-comment' + (comment.is_deleted ? ' is-deleted' : '') + '" data-comment-id="' + comment.id + '">' +
        '<div class="case-comment-avatar">' + initials + '</div>' +
        '<div class="case-comment-content">' +
        '<div class="case-comment-header">' +
        '<span class="case-comment-author">' + escapeHtml(comment.user_name) + '</span>' +
        '<span class="case-comment-time">' + timeAgo + '</span>' +
        '</div>' +
        '<div class="case-comment-text">' + textHtml + '</div>' +
        '</div>' +
        '</div>';
    }).join('');

    list.innerHTML = html;
    
    // Scroll to bottom
    list.scrollTop = list.scrollHeight;
  }

  /**
   * Update comment count badge
   */
  function updateCommentCount(count) {
    var countEl = document.getElementById('caseCommentsCount');
    if (countEl) {
      countEl.textContent = count;
      countEl.style.display = count > 0 ? '' : 'none';
    }
  }

  /**
   * Load practice users for @mention autocomplete
   */
  function loadPracticeUsers() {
    var practiceUsersUrl = 'api/get-practice-users.php';
    if (currentCaseId) {
      practiceUsersUrl += '?case_id=' + encodeURIComponent(currentCaseId);
    }
    fetch(practiceUsersUrl, {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success && data.users) {
        practiceUsers = data.users;
      }
    })
    .catch(function() {
      // Silently fail - users will be loaded on demand
    });
  }

  /**
   * Setup comment input with @mention support
   */
  function setupCommentInput() {
    var input = document.getElementById('caseCommentInput');
    var submitBtn = document.getElementById('caseCommentSubmit');
    
    if (!input) return;

    // Input event for @mention detection
    input.addEventListener('input', function(e) {
      checkForMention(input);
      updateSubmitButton(input, submitBtn);
    });

    // Keydown for autocomplete navigation
    input.addEventListener('keydown', function(e) {
      if (mentionAutocompleteOpen) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          navigateMention(1);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          navigateMention(-1);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
          e.preventDefault();
          selectCurrentMention(input);
        } else if (e.key === 'Escape') {
          closeMentionAutocomplete();
        }
      } else if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitComment();
      }
    });

    // Submit button click
    if (submitBtn) {
      submitBtn.addEventListener('click', submitComment);
    }
  }

  /**
   * Remove any selected mention whose display token is no longer present in the
   * comment text, for example because the user backspaced part of it.
   */
  function syncSelectedMentions(input) {
    if (selectedMentions.length === 0) {
      return;
    }
    var text = input.value || '';
    selectedMentions = selectedMentions.filter(function(mention) {
      var token = mention.token;
      if (!token) {
        return false;
      }
      var pattern = new RegExp('@' + escapeRegExp(token) + '(?![a-zA-Z0-9._-])', 'g');
      return pattern.test(text);
    });
  }

  /**
   * Escape a string for use inside a RegExp.
   */
  function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  /**
   * Check if user is typing a @mention
   */
  function checkForMention(input) {
    syncSelectedMentions(input);

    var text = input.value;
    var cursorPos = input.selectionStart;
    
    // Find @ before cursor
    var beforeCursor = text.substring(0, cursorPos);
    var atIndex = beforeCursor.lastIndexOf('@');
    
    if (atIndex === -1) {
      closeMentionAutocomplete();
      return;
    }
    
    // Check if @ is at start or after whitespace
    if (atIndex > 0 && !/\s/.test(beforeCursor[atIndex - 1])) {
      closeMentionAutocomplete();
      return;
    }
    
    // Get search term after @
    var searchTerm = beforeCursor.substring(atIndex + 1);
    
    // If there's a space after the search term, close autocomplete
    if (/\s/.test(searchTerm)) {
      closeMentionAutocomplete();
      return;
    }
    
    mentionStartPos = atIndex;
    mentionSearchTerm = searchTerm.toLowerCase();
    showMentionAutocomplete(searchTerm);
  }

  /**
   * Show mention autocomplete dropdown
   */
  function showMentionAutocomplete(searchTerm) {
    var dropdown = document.getElementById('mentionAutocomplete');
    if (!dropdown) return;

    // If users haven't loaded yet, try loading them
    if (practiceUsers.length === 0) {
      dropdown.innerHTML = '<div class="mention-autocomplete-empty">' + t('comments.mentions_loading') + '</div>';
      dropdown.classList.add('open');
      mentionAutocompleteOpen = true;
      
      // Try to load users and then show autocomplete
      var retryUrl = 'api/get-practice-users.php';
      if (currentCaseId) {
        retryUrl += '?case_id=' + encodeURIComponent(currentCaseId);
      }
      fetch(retryUrl, { credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success && data.users) {
            practiceUsers = data.users;
            showMentionAutocomplete(searchTerm); // Retry with loaded users
          } else {
            dropdown.innerHTML = '<div class="mention-autocomplete-empty">' + t('comments.mentions_no_users') + '</div>';
          }
        })
        .catch(function() {
          dropdown.innerHTML = '<div class="mention-autocomplete-empty">' + t('comments.mentions_load_error') + '</div>';
        });
      return;
    }

    var filtered = practiceUsers.filter(function(user) {
      var name = (user.name || '').toLowerCase();
      var email = (user.email || '').toLowerCase();
      var search = searchTerm.toLowerCase();
      return name.indexOf(search) !== -1 || email.indexOf(search) !== -1;
    }).slice(0, 5);

    if (filtered.length === 0) {
      dropdown.innerHTML = '<div class="mention-autocomplete-empty">' + t('comments.mentions_no_users') + '</div>';
    } else {
      dropdown.innerHTML = filtered.map(function(user, index) {
        var initials = getInitials(user.name || user.email);
        return '<div class="mention-autocomplete-item' + (index === selectedMentionIndex ? ' selected' : '') + '" data-user-id="' + user.id + '" data-user-name="' + escapeHtml(user.name || user.email) + '" data-user-email="' + escapeHtml(user.email) + '">' +
          '<div class="mention-autocomplete-avatar">' + initials + '</div>' +
          '<div class="mention-autocomplete-info">' +
          '<div class="mention-autocomplete-name">' + escapeHtml(user.name || t('common.unknown')) + '</div>' +
          '<div class="mention-autocomplete-email">' + escapeHtml(user.email) + '</div>' +
          '</div>' +
          '</div>';
      }).join('');

      // Add click handlers
      dropdown.querySelectorAll('.mention-autocomplete-item').forEach(function(item, index) {
        item.addEventListener('click', function() {
          selectedMentionIndex = index;
          selectCurrentMention(document.getElementById('caseCommentInput'));
        });
      });
    }

    dropdown.classList.add('open');
    mentionAutocompleteOpen = true;
    selectedMentionIndex = 0;
  }

  /**
   * Close mention autocomplete
   */
  function closeMentionAutocomplete() {
    var dropdown = document.getElementById('mentionAutocomplete');
    if (dropdown) {
      dropdown.classList.remove('open');
    }
    mentionAutocompleteOpen = false;
    mentionStartPos = -1;
    mentionSearchTerm = '';
    selectedMentionIndex = 0;
  }

  /**
   * Navigate mention autocomplete
   */
  function navigateMention(direction) {
    var items = document.querySelectorAll('.mention-autocomplete-item');
    if (items.length === 0) return;

    items[selectedMentionIndex].classList.remove('selected');
    selectedMentionIndex = (selectedMentionIndex + direction + items.length) % items.length;
    items[selectedMentionIndex].classList.add('selected');
  }

  /**
   * Select current mention from autocomplete
   */
  function selectCurrentMention(input) {
    var items = document.querySelectorAll('.mention-autocomplete-item');
    if (items.length === 0 || selectedMentionIndex >= items.length) {
      closeMentionAutocomplete();
      return;
    }

    var selectedItem = items[selectedMentionIndex];
    var userId = parseInt(selectedItem.getAttribute('data-user-id'), 10);
    var userName = selectedItem.getAttribute('data-user-name');
    var userEmail = selectedItem.getAttribute('data-user-email') || '';
    
    // Replace @searchTerm with a display token derived from the selected user's name.
    var text = input.value;
    var beforeMention = text.substring(0, mentionStartPos);
    var afterMention = text.substring(mentionStartPos + 1 + mentionSearchTerm.length);
    
    // Use the user's full name with spaces and punctuation stripped; the regex
    // in case-comments.php and highlightMentions allows alphanumerics, dots,
    // underscores and hyphens.
    var displayName = (userName || userEmail).replace(/[^a-zA-Z0-9._-]/g, '');
    if (!displayName) {
      displayName = 'user' + userId;
    }
    
    input.value = beforeMention + '@' + displayName + ' ' + afterMention;
    
    // Record the exact user ID selected.  Server-side validation will reverify
    // active membership and case access, so the client-supplied ID is not trusted.
    selectedMentions.push({
      user_id: userId,
      token: displayName,
      name: userName
    });
    
    // Set cursor after mention
    var newCursorPos = mentionStartPos + displayName.length + 2;
    input.setSelectionRange(newCursorPos, newCursorPos);
    input.focus();

    closeMentionAutocomplete();
    updateSubmitButton(input, document.getElementById('caseCommentSubmit'));
  }

  /**
   * Update submit button state
   */
  function updateSubmitButton(input, submitBtn) {
    if (submitBtn) {
      submitBtn.disabled = !input.value.trim();
    }
  }

  /**
   * Submit a new comment
   */
  function submitComment() {
    var input = document.getElementById('caseCommentInput');
    var submitBtn = document.getElementById('caseCommentSubmit');
    
    if (!input || !currentCaseId) return;
    
    var text = input.value.trim();
    if (!text) return;

    // Disable while submitting
    if (submitBtn) submitBtn.disabled = true;
    input.disabled = true;

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    fetch('api/case-comments.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        action: 'create',
        case_id: currentCaseId,
        text: text,
        mentions: selectedMentions
      })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        input.value = '';
        selectedMentions = [];
        loadComments(currentCaseId);
        
        // Show success feedback
        if (typeof showToast === 'function') {
          showToast(t('comments.toast_added'), 'success');
        }
      } else {
        if (typeof showToast === 'function') {
          showToast(data.message || t('comments.toast_add_error'), 'error');
        }
      }
    })
    .catch(function(error) {
      console.error('Error submitting comment:', error);
      if (typeof NetworkErrorHandler !== 'undefined') {
        NetworkErrorHandler.handle(error, 'adding comment');
      } else if (typeof showToast === 'function') {
        showToast(t('comments.toast_add_error_retry'), 'error');
      }
    })
    .finally(function() {
      input.disabled = false;
      if (submitBtn) submitBtn.disabled = false;
      input.focus();
    });
  }

  /**
   * Mark notifications for a case as read
   */
  function markCaseNotificationsRead(caseId) {
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
        action: 'mark_case_read',
        case_id: caseId
      })
    })
    .then(function() {
      // Refresh notification count
      if (typeof window.refreshNotificationCount === 'function') {
        window.refreshNotificationCount();
      }
    })
    .catch(function(error) {
      console.error('Error marking notifications read:', error);
    });
  }

  /**
   * Highlight @mentions in text
   */
  function highlightMentions(text) {
    return text.replace(/@([a-zA-Z0-9._-]+)/g, '<span class="mention">@$1</span>');
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

    if (diffMins < 1) return t('comments.time_ago.just_now');
    if (diffMins < 60) return t('comments.time_ago.minutes', {count: diffMins});
    if (diffHours < 24) return t('comments.time_ago.hours', {count: diffHours});
    if (diffDays < 7) return t('comments.time_ago.days', {count: diffDays});
    
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

  /**
   * Clear comments when modal closes
   */
  window.clearCaseComments = function() {
    currentCaseId = null;
    var list = document.getElementById('caseCommentsList');
    if (list) list.innerHTML = '';
    var input = document.getElementById('caseCommentInput');
    if (input) input.value = '';
    closeMentionAutocomplete();
  };

})();
