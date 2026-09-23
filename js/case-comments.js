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
  var commentSubmitting = false;
  var commentInFlightPromise = null;

  // Draft images staged in the composer: { id, file, previewUrl }.
  // Files upload only when the comment is posted, so an abandoned draft can
  // never leave orphaned objects in storage.
  var pendingCommentImages = [];
  var pendingImageSeq = 0;
  // storagePath -> Promise<objectUrl> cache for rendered thread thumbnails;
  // cleared when the case's comments are reset.
  var commentImageUrlCache = new Map();

  // V1 cap mirrors appConfig['comments']['max_images'], injected by main.php.
  var COMMENT_IMAGE_MAX_COUNT =
    (typeof window.commentImageMaxCount === 'number' && window.commentImageMaxCount > 0)
      ? window.commentImageMaxCount : 6;
  var COMMENT_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'svg'];

  /**
   * Initialize comments for a case
   */
  window.initCaseComments = function(caseId) {
    currentCaseId = caseId;
    selectedMentions = [];
    resetPendingImages();
    revokeCommentImageUrlCache();
    closeMentionAutocomplete();
    // Drop the previous case's list and count before this case's data arrives.
    var list = document.getElementById('caseCommentsList');
    if (list) list.innerHTML = '';
    updateCommentCount(0);
    loadComments(caseId);
    loadPracticeUsers();
    setupCommentInput();
    commentSubmitting = false;
    updateSubmitButton(
      document.getElementById('caseCommentInput'),
      document.getElementById('caseCommentSubmit')
    );
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
      var exactTime = formatCommentTimestamp(comment.created_at);
      var commentDate = new Date(comment.created_at);
      var tsAttr = comment.created_at && !isNaN(commentDate.getTime())
        ? ' data-ts="' + commentDate.getTime() + '"'
        : '';
      var textHtml = comment.is_deleted
        ? '<span class="deleted-text">' + escapeHtml(comment.text) + '</span>'
        : highlightMentions(escapeHtml(comment.text));

      var imagesHtml = '';
      if (!comment.is_deleted && Array.isArray(comment.images) && comment.images.length > 0) {
        imagesHtml = '<div class="case-comment-images">' + comment.images.map(function(image, imageIndex) {
          var viewLabel = t('comments.images.view_image', { name: image.fileName || '' });
          return '<button type="button" class="case-comment-image" ' +
            'data-comment-id="' + comment.id + '" data-image-index="' + imageIndex + '" ' +
            'aria-label="' + escapeHtml(viewLabel) + '" title="' + escapeHtml(image.fileName || '') + '">' +
            '<span class="case-comment-image-loading" aria-hidden="true"></span>' +
            '</button>';
        }).join('') + '</div>';
      }

      return '<div class="case-comment' + (comment.is_deleted ? ' is-deleted' : '') + '" data-comment-id="' + comment.id + '">' +
        '<div class="case-comment-avatar">' + initials + '</div>' +
        '<div class="case-comment-content">' +
        '<div class="case-comment-header">' +
        '<span class="case-comment-author">' + escapeHtml(comment.user_name) + '</span>' +
        '<span class="case-comment-time"' + tsAttr + ' title="' + escapeHtml(exactTime) + '">' + timeAgo + '</span>' +
        '</div>' +
        '<div class="case-comment-text">' + textHtml + '</div>' +
        imagesHtml +
        '</div>' +
        '</div>';
    }).join('');

    list.innerHTML = html;
    hydrateCommentImageThumbs(list, comments);
    startCommentTimeRefresh();

    applyPendingCommentFocus();

    // Scroll to bottom when a notification is not driving focus.
    if (!pendingCommentFocus) {
      list.scrollTop = list.scrollHeight;
    }
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

  /* ------------------------------------------------------------------ *
   * Comment image attachments                                          *
   * ------------------------------------------------------------------ */

  /**
   * Load each rendered comment-image thumbnail through the authorized
   * attachment-content endpoint and open the shared attachment viewer on
   * click. `comments` is the same array renderComments just rendered.
   */
  function hydrateCommentImageThumbs(list, comments) {
    var thumbs = list.querySelectorAll('.case-comment-image');
    if (!thumbs.length) return;

    var commentsById = {};
    comments.forEach(function(comment) { commentsById[String(comment.id)] = comment; });

    thumbs.forEach(function(thumb) {
      var comment = commentsById[thumb.getAttribute('data-comment-id')];
      var imageIndex = parseInt(thumb.getAttribute('data-image-index'), 10);
      var image = comment && Array.isArray(comment.images) ? comment.images[imageIndex] : null;
      if (!image || !image.storagePath) return;

      thumb.addEventListener('click', function() {
        if (typeof window.openAttachmentViewer === 'function') {
          // Passing the comment's image list enables Previous/Next within
          // just this comment's attachments.
          window.openAttachmentViewer(image.storagePath, image.fileName, image.fileType, comment.images);
        }
      });

      if (typeof window.getAttachmentObjectUrl !== 'function') {
        markCommentImageBroken(thumb);
        return;
      }

      getCommentImageObjectUrl(image.storagePath)
        .then(function(objectUrl) {
          if (!thumb.isConnected) return;
          var img = document.createElement('img');
          img.src = objectUrl;
          img.alt = '';
          img.loading = 'lazy';
          thumb.textContent = '';
          thumb.appendChild(img);
        })
        .catch(function() {
          if (thumb.isConnected) markCommentImageBroken(thumb);
        });
    });
  }

  /**
   * Blob object URL for a stored comment image, cached per storage path so a
   * re-render does not re-fetch bytes. The promise is cached so concurrent
   * renders share one request.
   */
  function getCommentImageObjectUrl(storagePath) {
    var cached = commentImageUrlCache.get(storagePath);
    if (!cached) {
      cached = window.getAttachmentObjectUrl(storagePath).catch(function(err) {
        commentImageUrlCache.delete(storagePath);
        throw err;
      });
      commentImageUrlCache.set(storagePath, cached);
    }
    return cached;
  }

  function revokeCommentImageUrlCache() {
    commentImageUrlCache.forEach(function(promise) {
      promise.then(function(url) { URL.revokeObjectURL(url); }).catch(function() {});
    });
    commentImageUrlCache.clear();
  }

  /**
   * Swap a thumbnail that failed to load for an in-place error placeholder -
   * the rest of the thread keeps working.
   */
  function markCommentImageBroken(thumb) {
    thumb.classList.add('case-comment-image-broken');
    thumb.disabled = false; // still opens the viewer, which has its own error state + download
    thumb.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line>' +
      '<line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
      '<span>' + escapeHtml(t('comments.images.preview_failed')) + '</span>';
  }

  /**
   * True when a picked file is a supported comment image (extension and MIME
   * both checked - MIME alone trusts the browser, extension alone trusts the
   * name; the server re-verifies both against the stored object anyway).
   */
  function isSupportedCommentImage(file) {
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    if (COMMENT_IMAGE_EXTENSIONS.indexOf(ext) === -1) return false;
    return (file.type || '').indexOf('image/') === 0;
  }

  /**
   * Stage picked files into the composer draft, reporting per-file failures
   * so the user can remove or fix the offending selection.
   */
  function addPendingImages(files) {
    var input = document.getElementById('caseCommentInput');
    var submitBtn = document.getElementById('caseCommentSubmit');
    var rejected = [];

    Array.prototype.forEach.call(files, function(file) {
      if (pendingCommentImages.length >= COMMENT_IMAGE_MAX_COUNT) {
        rejected.push(t('comments.images.too_many', { max: COMMENT_IMAGE_MAX_COUNT }));
        return false; // report once
      }
      if (!isSupportedCommentImage(file)) {
        rejected.push(t('comments.images.unsupported_type', { name: file.name }));
        return;
      }
      var maxSize = window.GCSUpload && window.GCSUpload.getMaxSizeForFile
        ? window.GCSUpload.getMaxSizeForFile(file.name)
        : 25 * 1024 * 1024;
      if (file.size > maxSize) {
        rejected.push(t('comments.images.too_large', {
          name: file.name,
          limit: Math.round(maxSize / 1024 / 1024)
        }));
        return;
      }
      pendingCommentImages.push({
        id: 'cimg_' + (++pendingImageSeq),
        file: file,
        previewUrl: URL.createObjectURL(file)
      });
    });

    // Deduplicate identical rejection messages (e.g. the max-count notice).
    rejected.filter(function(msg, i) { return rejected.indexOf(msg) === i; })
      .forEach(function(msg) {
        if (typeof showToast === 'function') showToast(msg, 'error');
      });

    renderPendingImages();
    updateSubmitButton(input, submitBtn);
  }

  function removePendingImage(id) {
    var index = pendingCommentImages.findIndex(function(item) { return item.id === id; });
    if (index === -1) return;
    URL.revokeObjectURL(pendingCommentImages[index].previewUrl);
    pendingCommentImages.splice(index, 1);
    renderPendingImages();
    updateSubmitButton(
      document.getElementById('caseCommentInput'),
      document.getElementById('caseCommentSubmit')
    );
  }

  function resetPendingImages() {
    pendingCommentImages.forEach(function(item) { URL.revokeObjectURL(item.previewUrl); });
    pendingCommentImages = [];
    renderPendingImages();
  }

  /**
   * Render the staged-draft thumbnail strip above the composer actions.
   */
  function renderPendingImages() {
    var strip = document.getElementById('caseCommentImagesPreview');
    if (!strip) return;

    if (pendingCommentImages.length === 0) {
      strip.innerHTML = '';
      strip.hidden = true;
      return;
    }

    strip.innerHTML = '';
    pendingCommentImages.forEach(function(item) {
      var wrap = document.createElement('div');
      wrap.className = 'case-comment-preview';

      var img = document.createElement('img');
      img.src = item.previewUrl;
      img.alt = item.file.name;
      wrap.appendChild(img);

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'case-comment-preview-remove';
      removeBtn.setAttribute('aria-label', t('comments.images.remove_image') + ': ' + item.file.name);
      removeBtn.title = t('comments.images.remove_image');
      removeBtn.textContent = '×';
      removeBtn.addEventListener('click', function() { removePendingImage(item.id); });
      wrap.appendChild(removeBtn);

      strip.appendChild(wrap);
    });
    strip.hidden = false;
  }

  /**
   * Upload every staged image through the existing signed-URL pipeline, then
   * return the metadata array the comments endpoint expects. Rejects with a
   * per-file message when any upload fails so the caller can keep the draft.
   */
  function uploadPendingCommentImages(csrfToken) {
    if (pendingCommentImages.length === 0) return Promise.resolve([]);
    if (!window.GCSUpload || typeof window.GCSUpload.uploadSingleFile !== 'function') {
      var unavailable = new Error(t('comments.images.upload_unavailable'));
      unavailable.isCommentImageError = true;
      return Promise.reject(unavailable);
    }
    return Promise.all(pendingCommentImages.map(function(item) {
      return window.GCSUpload.uploadSingleFile({
        file: item.file,
        fileId: item.id,
        fileName: item.file.name,
        contentType: item.file.type || 'image/jpeg',
        fileSize: item.file.size,
        uploadType: 'comments'
      }, currentCaseId, csrfToken).catch(function(err) {
        var wrapped = new Error(t('comments.images.upload_failed', { name: item.file.name }));
        wrapped.isCommentImageError = true;
        throw wrapped;
      });
    }));
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

    // The input and submit button are static markup shared by every case, so
    // bind their listeners once; re-binding on each opened case would stack
    // handlers and submit the same comment multiple times.
    if (input.dataset.commentListenersBound) return;
    input.dataset.commentListenersBound = '1';

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

    // Image attachment controls
    var attachBtn = document.getElementById('caseCommentAttachBtn');
    var imageInput = document.getElementById('caseCommentImageInput');
    if (attachBtn && imageInput) {
      attachBtn.addEventListener('click', function() { imageInput.click(); });
      imageInput.addEventListener('change', function() {
        if (imageInput.files && imageInput.files.length > 0) {
          addPendingImages(imageInput.files);
        }
        // Reset so picking the same file twice still fires change.
        imageInput.value = '';
      });
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
    if (!submitBtn) return;
    var hasDraft = (input && input.value.trim()) || pendingCommentImages.length > 0;
    submitBtn.disabled = commentSubmitting || !hasDraft;
    // When case-detail edits are pending, this button saves those too - say so.
    var caseDirty = typeof window.caseFormHasUnsavedChanges === 'function' &&
      window.caseFormHasUnsavedChanges();
    submitBtn.textContent = caseDirty
      ? t('cases.save_all_changes')
      : t('cases.comments.add_comment');
  }

  /**
   * Refresh the comment submit button from app.js (form dirtiness changes,
   * tab switches) without needing access to the input element.
   */
  window.updateCaseCommentSubmitState = function() {
    updateSubmitButton(
      document.getElementById('caseCommentInput'),
      document.getElementById('caseCommentSubmit')
    );
  };

  /**
   * True while a comment POST is in flight - callers can skip re-submitting.
   */
  window.caseCommentSubmitting = function() {
    return commentSubmitting;
  };

  /**
   * The in-flight comment POST promise, or null. Lets the shared save wait
   * for the running request instead of silently dropping a save triggered
   * while the comment is still submitting.
   */
  window.caseCommentInFlight = function() {
    return commentInFlightPromise;
  };

  /**
   * True when the comment box holds an unsubmitted draft.
   */
  window.caseCommentHasDraft = function() {
    var input = document.getElementById('caseCommentInput');
    return !!(input && input.value.trim()) || pendingCommentImages.length > 0;
  };

  /**
   * Submit a new comment. Returns a Promise resolving to true when the draft
   * was posted (or there was nothing to post) and false on failure - the
   * draft is left intact on failure so it can be retried without duplicates.
   */
  function postComment() {
    var input = document.getElementById('caseCommentInput');
    var submitBtn = document.getElementById('caseCommentSubmit');

    if (!input || !currentCaseId) return Promise.resolve(false);

    var text = input.value.trim();
    if (!text && pendingCommentImages.length === 0) return Promise.resolve(true);
    if (commentSubmitting) return commentInFlightPromise || Promise.resolve(false);

    // Disable while submitting
    commentSubmitting = true;
    if (submitBtn) submitBtn.disabled = true;
    input.disabled = true;
    var attachBtn = document.getElementById('caseCommentAttachBtn');
    if (attachBtn) attachBtn.disabled = true;

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    // Upload staged images first so the comment row is only created once its
    // attachments actually exist in storage. A failed upload rejects before
    // the POST, leaving the draft text and staged images intact for retry.
    commentInFlightPromise = uploadPendingCommentImages(csrfToken)
    .then(function(uploadedImages) {
      return fetch('api/case-comments.php', {
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
          mentions: selectedMentions,
          images: uploadedImages
        })
      });
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success) {
        input.value = '';
        selectedMentions = [];
        resetPendingImages();
        loadComments(currentCaseId);

        // Show success feedback
        if (typeof showToast === 'function') {
          showToast(t('comments.toast_added'), 'success');
        }
        return true;
      }
      if (typeof showToast === 'function') {
        showToast(data.message || t('comments.toast_add_error'), 'error');
      }
      return false;
    })
    .catch(function(error) {
      console.error('Error submitting comment:', error);
      if (error && error.isCommentImageError) {
        // Upload-level failure already carries a localized, file-specific
        // message; the draft stays intact so the user can retry or remove
        // the failing image.
        if (typeof showToast === 'function') {
          showToast(error.message, 'error');
        }
      } else if (typeof NetworkErrorHandler !== 'undefined') {
        NetworkErrorHandler.handle(error, 'adding comment');
      } else if (typeof showToast === 'function') {
        showToast(t('comments.toast_add_error_retry'), 'error');
      }
      return false;
    })
    .finally(function() {
      commentSubmitting = false;
      commentInFlightPromise = null;
      input.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
      updateSubmitButton(input, submitBtn);
      input.focus();
    });

    return commentInFlightPromise;
  }

  // Public poster used by the shared "Save All Changes" flow.
  window.postCaseComment = postComment;

  /**
   * Comment submit entry point (button click / Enter). Routes through the
   * shared save so pending case-detail edits are saved together with the
   * comment instead of being left behind.
   */
  function submitComment() {
    if (typeof window.saveAllCaseChanges === 'function') {
      window.saveAllCaseChanges();
      return;
    }
    postComment();
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
   * Format time ago. Returns an honest fallback for missing/invalid input —
   * never "Just now", which would misrepresent the comment's real age.
   */
  function formatTimeAgo(dateString) {
    var date = new Date(dateString);
    if (!dateString || isNaN(date.getTime())) {
      return t('comments.time_ago.unavailable');
    }
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
   * Exact local date+time for the timestamp tooltip.
   */
  function formatCommentTimestamp(dateString) {
    var date = new Date(dateString);
    if (!dateString || isNaN(date.getTime())) {
      return t('comments.time_ago.unavailable');
    }
    if (window.I18n && typeof I18n.formatDate === 'function') {
      return I18n.formatDate(date, { style: 'short', timeStyle: 'short' });
    }
    return date.toLocaleString();
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

  var pendingCommentFocus = null;
  var commentTimeRefreshTimer = null;

  /**
   * Request that a specific comment be highlighted once the current case's
   * comments have loaded. This is used by notification deep links.
   */
  window.focusCaseComment = function(caseId, commentId) {
    pendingCommentFocus = {
      caseId: String(caseId || ''),
      commentId: String(commentId || '')
    };
  };

  /**
   * Clear the pending comment focus request.
   */
  function clearPendingCommentFocus() {
    pendingCommentFocus = null;
  }

  /**
   * Refresh relative-time labels while the modal stays open.
   * One interval only — startCommentTimeRefresh() clears any previous timer
   * so re-renders never stack duplicates.
   */
  function startCommentTimeRefresh() {
    stopCommentTimeRefresh();
    commentTimeRefreshTimer = setInterval(function() {
      var modal = document.getElementById('createCaseModal');
      var list = document.getElementById('caseCommentsList');
      // Stop when the modal is hidden by ANY path - including ones that skip
      // clearCaseComments() (e.g. the "Back to Archived Cases" button or the
      // generic closeModals() when another modal overlays this one).
      if (!modal || modal.style.display !== 'block' ||
          !list || !list.querySelector('.case-comment-time[data-ts]')) {
        stopCommentTimeRefresh();
        return;
      }
      list.querySelectorAll('.case-comment-time[data-ts]').forEach(function(el) {
        el.textContent = formatTimeAgo(new Date(parseInt(el.getAttribute('data-ts'), 10)));
      });
    }, 60000);
  }

  function stopCommentTimeRefresh() {
    if (commentTimeRefreshTimer) {
      clearInterval(commentTimeRefreshTimer);
      commentTimeRefreshTimer = null;
    }
  }

  /**
   * Highlight and scroll to a specific comment in the rendered list.
   */
  function applyPendingCommentFocus() {
    if (!pendingCommentFocus) return;
    if (String(currentCaseId) !== pendingCommentFocus.caseId) {
      clearPendingCommentFocus();
      return;
    }

    var list = document.getElementById('caseCommentsList');
    if (!list) {
      clearPendingCommentFocus();
      return;
    }

    var commentId = pendingCommentFocus.commentId;
    var selector = '.case-comment[data-comment-id="' + commentId.replace(/"/g, '\\"') + '"]';
    var el = list.querySelector(selector);
    if (el) {
      el.classList.add('case-comment-focused');
      el.scrollIntoView({ block: 'center', behavior: 'auto' });
    }

    clearPendingCommentFocus();
  }

  /**
   * Clear comments when modal closes
   */
  window.clearCaseComments = function() {
    currentCaseId = null;
    activeLoadId++; // invalidate any in-flight load for the previous case
    stopCommentTimeRefresh();
    var list = document.getElementById('caseCommentsList');
    if (list) list.innerHTML = '';
    updateCommentCount(0);
    clearPendingCommentFocus();
    var input = document.getElementById('caseCommentInput');
    if (input) input.value = '';
    resetPendingImages();
    revokeCommentImageUrlCache();
    closeMentionAutocomplete();
  };

})();
