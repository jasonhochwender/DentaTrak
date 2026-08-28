/**
 * App JavaScript
 *
 * Handles modal functionality, Google Sign-In modal experience,
 * and dental case management functionality
 */

// Store the current user's email for admin functionality
var currentUserEmail = document.getElementById('userEmailData') ? document.getElementById('userEmailData').getAttribute('data-email') : '';

// CSRF Token for secure API requests
var csrfToken = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';

/**
 * Convert an internal workflow status value (e.g. 'Received From External
 * Lab') into its corresponding "kanban-card-*" CSS class name (e.g.
 * 'kanban-card-received-from-external-lab'). Centralized so every place
 * that needs the status-color class derives it the same way, from the
 * fixed INTERNAL status value only - never from a column header's visible
 * text, which will become practice-customizable in a later pass while this
 * slug (and the underlying internal status) stays fixed.
 * @param {string} status - internal workflow status value
 * @returns {string} CSS class name
 */
function getWorkflowStatusCssClass(status) {
  return 'kanban-card-' + String(status || '').toLowerCase().replace(/\s+/g, '-');
}
window.getWorkflowStatusCssClass = getWorkflowStatusCssClass;

/**
 * Resolve the practice-specific display label for an internal workflow
 * status. window.workflowStageLabels is populated from get-settings.php's
 * fully-resolved `workflowStageLabels` map (see applyUserSettings()) - it
 * already contains all six statuses, defaulting to the stock label
 * wherever a practice hasn't customized anything, so under normal
 * operation this simply returns that resolved value. Falling back to the
 * raw internal status itself (never inventing a label, never throwing) is
 * only a safety net for settings not having loaded yet or an unrecognized
 * status.
 *
 * Used by renderWorkflowStageLabels() below and available for future
 * secondary-surface work (Insights, archive, history, print/export, etc.)
 * that hasn't been wired in yet.
 * @param {string} internalStatus
 * @returns {string} resolved display label, or internalStatus as fallback
 */
var defaultWorkflowStageLabels = {
  'Originated': 'Originated',
  'Sent To External Lab': 'Sent To External Lab',
  'Designed': 'Designed',
  'Manufactured': 'Manufactured',
  'Received From External Lab': 'Received From External Lab',
  'Delivered': 'Delivered'
};

function getStageLabel(internalStatus) {
  if (window.workflowStageLabels && Object.prototype.hasOwnProperty.call(window.workflowStageLabels, internalStatus)) {
    return window.workflowStageLabels[internalStatus];
  }
  var normalized = String(internalStatus).toLowerCase().replace(/\s+/g, '_');
  var key = 'cases.status.' + normalized;
  var label = t(key);
  if (label && label !== key) {
    return label;
  }
  if (defaultWorkflowStageLabels && Object.prototype.hasOwnProperty.call(defaultWorkflowStageLabels, internalStatus)) {
    return defaultWorkflowStageLabels[internalStatus];
  }
  return '';
}
window.getStageLabel = getStageLabel;

/**
 * Apply the current window.workflowStageLabels to every primary-surface
 * consumer: the Settings > Display & Behavior "Workflow Stage Names"
 * inputs, the six Kanban column headings, and the Create/Edit Case status
 * dropdown's visible option text. Never touches an internal status value -
 * `.kanban-column[data-status]` and `<option value>` are left completely
 * alone, only visible text changes. Safe to call at any time (initial
 * page bootstrap, whenever Settings loads/reopens, and immediately after a
 * successful Settings save) since it always re-derives from the current
 * window.workflowStageLabels map.
 *
 * Secondary surfaces (Insights, Lab Insights, archived cases, revision/
 * activity history, print/export, notifications, AI prompts) are NOT
 * updated here yet - that is a later propagation pass.
 */
function renderWorkflowStageLabels() {
  var labels = window.workflowStageLabels;
  if (!labels || typeof labels !== 'object') return;

  // Settings > Display & Behavior > Workflow Stage Names inputs.
  document.querySelectorAll('.workflow-stage-label-input').forEach(function(input) {
    var status = input.dataset.internalStatus;
    if (status && Object.prototype.hasOwnProperty.call(labels, status)) {
      input.value = labels[status];
    }
  });

  // Kanban column headings - data-status is never touched, only the
  // visible <h2> text.
  document.querySelectorAll('.kanban-column').forEach(function(column) {
    var status = column.dataset.status;
    if (!status || !Object.prototype.hasOwnProperty.call(labels, status)) return;
    var titleEl = column.querySelector('.kanban-column-title');
    if (titleEl) {
      titleEl.textContent = labels[status];
    }
  });

  // Create/Edit Case status dropdown - <option value> is never touched,
  // only the visible option text.
  var statusSelect = document.getElementById('status');
  if (statusSelect) {
    Array.prototype.forEach.call(statusSelect.options, function(option) {
      if (option.value && Object.prototype.hasOwnProperty.call(labels, option.value)) {
        option.textContent = labels[option.value];
      }
    });
  }
}
window.renderWorkflowStageLabels = renderWorkflowStageLabels;

/**
 * Get headers object with CSRF token for fetch requests
 * @param {Object} additionalHeaders - Additional headers to merge
 * @returns {Object} Headers object with CSRF token
 */
function getSecureHeaders(additionalHeaders) {
  var headers = {
    'X-CSRF-Token': csrfToken
  };
  if (additionalHeaders) {
    for (var key in additionalHeaders) {
      headers[key] = additionalHeaders[key];
    }
  }
  return headers;
}

/**
 * Secure fetch wrapper that includes CSRF token
 * @param {string} url - The URL to fetch
 * @param {Object} options - Fetch options
 * @returns {Promise} Fetch promise
 */
function secureFetch(url, options) {
  options = options || {};
  options.headers = options.headers || {};

  // Add CSRF token header for non-GET requests
  if (!options.method || options.method.toUpperCase() !== 'GET') {
    options.headers['X-CSRF-Token'] = csrfToken;
  }

  return fetch(url, options);
}

/**
 * Switch to a different practice
 * Updates session and reloads the page to ensure clean context
 * @param {string|number} practiceId - The practice ID to switch to
 */
async function switchPractice(practiceId) {
  if (!practiceId) return;

  // Show loading indicator
  var loadingOverlay = document.getElementById('pageLoadingOverlay');
  if (loadingOverlay) {
    loadingOverlay.style.display = 'flex';
    loadingOverlay.style.opacity = '1';
  }

  try {
    var response = await secureFetch('api/switch-practice.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ practice_id: parseInt(practiceId, 10) }),
      credentials: 'same-origin'
    });

    var data = await response.json();

    if (data.success) {
      // Reload the page to get fresh context for the new practice
      window.location.reload();
    } else {
      // Hide loading overlay
      if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
      }

      // Show error
      showToast(data.error || t('practice_switcher.switch_failed'), 'error');
    }
  } catch (error) {

    // Hide loading overlay
    if (loadingOverlay) {
      loadingOverlay.style.display = 'none';
    }

    if (typeof NetworkErrorHandler !== 'undefined') {
      NetworkErrorHandler.handle(error, 'switching practice');
    } else {
      showToast(t('practice_switcher.switch_failed'), 'error');
    }
  }
}

// Make switchPractice available globally
window.switchPractice = switchPractice;

// Initialize callbacks array for card loaded events
window.cardLoadedCallbacks = [];

// Function to register callbacks for when cards are loaded
window.addCardLoadedCallback = function(callback) {
  if (typeof callback === 'function') {
    window.cardLoadedCallbacks.push(callback);
  }
};

// Function to trigger card update events
window.triggerCardsUpdated = function() {
  var cardsUpdatedEvent = new CustomEvent('cardsUpdated');
  window.dispatchEvent(cardsUpdatedEvent);
};

// Styled confirmation modal function (replaces browser confirm())
function showConfirmModal(title, message, onConfirm, onCancel, preventBackgroundClose) {
  var modal = document.getElementById('confirmModal');
  var titleEl = document.getElementById('confirmModalTitle');
  var messageEl = document.getElementById('confirmModalMessage');
  var okBtn = document.getElementById('confirmModalOk');
  var cancelBtn = document.getElementById('confirmModalCancel');

  if (!modal || !titleEl || !messageEl || !okBtn || !cancelBtn) {
    // Fallback to browser confirm if modal elements don't exist
    if (confirm(message)) {
      if (onConfirm) onConfirm();
    } else {
      if (onCancel) onCancel();
    }
    return;
  }

  titleEl.textContent = title || t('common.confirm');
  messageEl.textContent = message || t('common.confirm_message');
  modal.style.display = 'block';

  // Clean up old event listeners by cloning buttons
  var newOkBtn = okBtn.cloneNode(true);
  var newCancelBtn = cancelBtn.cloneNode(true);
  okBtn.parentNode.replaceChild(newOkBtn, okBtn);
  cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

  // Add new event listeners
  newOkBtn.addEventListener('click', function() {
    modal.style.display = 'none';
    if (onConfirm) onConfirm();
  });

  newCancelBtn.addEventListener('click', function() {
    modal.style.display = 'none';
    if (onCancel) onCancel();
  });

  // Close on background click (unless prevented)
  if (!preventBackgroundClose) {
    modal.onclick = function(e) {
      if (e.target === modal) {
        modal.style.display = 'none';
        if (onCancel) onCancel();
      }
    };
  } else {
    modal.onclick = null; // Remove background close handler
  }
}

// Product decision: Settings and Billing are desktop/tablet-only
// administrative surfaces. On phones (<=720px) this shows a lightweight
// message instead. Called from a central guard inside openSettingsBillingModal()
// and window.openBillingPortal() so every existing/future call site
// (menu click, Ctrl+, shortcut, etc.) is covered without duplicating the
// check in each handler.
function showMobileRestrictedModal(type) {
  var modal = document.getElementById('mobileRestrictedModal');
  var titleEl = document.getElementById('mobileRestrictedTitle');
  var messageEl = document.getElementById('mobileRestrictedMessage');
  var subtextEl = document.getElementById('mobileRestrictedSubtext');
  if (!modal || !titleEl || !messageEl || !subtextEl) return;

  if (type === 'billing') {
    titleEl.textContent = t('billing.billing');
    messageEl.textContent = t('billing.errors.desktop_tablet_only');
    subtextEl.textContent = t('billing.errors.desktop_tablet_only_subtext');
  } else {
    titleEl.textContent = t('settings.messages.mobile_title');
    messageEl.textContent = t('settings.messages.mobile_message');
    subtextEl.textContent = t('settings.messages.mobile_subtext');
  }

  modal.style.display = 'block';
}
window.showMobileRestrictedModal = showMobileRestrictedModal;

// Simple wrapper around the Toast system used throughout the app
function showToast(message, type) {
  if (!message) return;

  if (typeof Toast !== 'undefined') {
    switch (type) {
      case 'success':
        Toast.success(t('common.success'), message);
        break;
      case 'error':
        Toast.error(t('common.error'), message);
        break;
      case 'warning':
        Toast.warning(t('common.warning'), message);
        break;
      case 'info':
      default:
        Toast.info(t('common.info'), message);
        break;
    }
  } else {
    alert(message);
  }
}
window.showToast = showToast;

// Track practice logo state for the settings modal
// currentLogoPath: logo path currently saved in the database
// pendingLogoPath: newly uploaded logo path, staged until Save Settings is clicked
window.currentLogoPath = '';
window.pendingLogoPath = '';
window.logoMarkedForRemoval = false;

// Initialize the app when the page loads
document.addEventListener('DOMContentLoaded', function () {
  // Reference to the full-page loading overlay
  var pageLoadingOverlay = document.getElementById('pageLoadingOverlay');

  // Track app initialization state
  var appInitialized = false;

  // We'll keep the loading overlay visible until the cases are fully loaded
  // The overlay will be hidden by the loadExistingCases function when complete

  // Drag-and-drop will be initialized after cases are loaded
  // See hideLoader function in loadExistingCases

  // Google Sign-In Modal & Popup Functionality
  const googleSignInBtn = document.getElementById('googleSignInBtn');
  const signInModal = document.getElementById('signInModal');
  const authPrivacyLink = document.getElementById('authPrivacyLink');
  const continueToGoogleBtn = document.getElementById('continueToGoogleBtn');
  let authPopup = null;

  if (googleSignInBtn && signInModal) {
    // Prevent default action on the sign-in button to show our modal first
    googleSignInBtn.addEventListener('click', function(e) {
      // Prevent default link behavior
      e.preventDefault();

      // Show the modal
      signInModal.style.display = 'block';
    });

    // Handle privacy link in auth modal
    if (authPrivacyLink) {
      authPrivacyLink.addEventListener('click', function(e) {
        e.preventDefault();
        // Close the auth modal
        signInModal.style.display = 'none';
        // Open the privacy modal
        if (privacyModal) {
          openModal(privacyModal);
        }
      });
    }

    // Close the modal if user clicks outside of it
    window.addEventListener('click', function(e) {
      if (e.target === signInModal) {
        signInModal.style.display = 'none';
      }
    });
  }

  // Clear validation state (error classes and messages) from the create case form
  function clearCreateCaseErrors() {
    var form = document.getElementById('createCaseForm');
    if (!form) return;

    // Remove error classes from fields
    var erroredFields = form.querySelectorAll('.field-error');
    erroredFields.forEach(function(field) {
      field.classList.remove('field-error');
    });

    // Remove error messages within the form
    var errorMessages = form.querySelectorAll('.error-message');
    errorMessages.forEach(function(msg) {
      if (msg.parentNode === form || form.contains(msg)) {
        msg.remove();
      }
    });
  }

  // Helper to open Google OAuth in a centered popup window
  function openAuthPopup(url) {
    const width = 500;
    const height = 650;
    const left = window.screenX + (window.outerWidth - width) / 2;
    const top = window.screenY + (window.outerHeight - height) / 2;
    const features = `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`;
    authPopup = window.open(url, 'googleAuthPopup', features);

    if (authPopup) {
      authPopup.focus();
      // Close our sign-in instructions modal once popup is opened
      if (signInModal) {
        signInModal.style.display = 'none';
      }
    } else {
      // Popup blocked: fall back to full-page redirect
      window.location.href = url;
    }
  }

  // Settings-only metadata parallel to window.assignmentLabels (which stays
  // a plain string array for backward compatibility with other consumers,
  // e.g. js/assignments.js's dropdown builder). Same index/order/length as
  // window.assignmentLabels at all times. {id: number|null, label, isLab}.
  // id === null means "not yet persisted" (a label added this session).
  window.assignmentLabelsMeta = window.assignmentLabelsMeta || [];

  // Function to add an assignment label
  function addAssignmentLabel() {
    // Only Practice Administrators may manage Assignment Labels. This
    // mirrors addGmailUser()'s admin check; the server independently
    // enforces this too (save-settings.php requires role === 'admin').
    if (!window.isPracticeAdmin) {
      return;
    }

    // Resolve DOM elements lazily in case they weren't bound yet
    if (!newAssignmentLabelInput) {
      newAssignmentLabelInput = document.getElementById('newAssignmentLabel');
    }
    if (!assignmentLabelErrorElement) {
      assignmentLabelErrorElement = document.getElementById('assignmentLabelError');
    }
    if (!assignmentLabelsList) {
      assignmentLabelsList = document.getElementById('assignmentLabelsList');
    }

    if (!newAssignmentLabelInput || !assignmentLabelErrorElement) {
      return;
    }

    var label = newAssignmentLabelInput.value.trim();

    // Clear previous error for this field
    assignmentLabelErrorElement.textContent = '';

    // If nothing was entered, just do nothing (no error message needed)
    if (!label) {
      return;
    }

    if (label.length > 150) {
      label = label.substring(0, 150);
    }

    var lower = label.toLowerCase();

    // Check for duplicate label (case-insensitive)
    if (window.assignmentLabels && window.assignmentLabels.some(function(existing) {
      return typeof existing === 'string' && existing.toLowerCase() === lower;
    })) {
      assignmentLabelErrorElement.textContent = t('settings.users.shared_assignment_labels.validation.duplicate');
      return;
    }

    // Optional: avoid collision with Gmail users for clarity
    if (window.gmailUsers && window.gmailUsers.some(function(email) {
      return typeof email === 'string' && email.toLowerCase() === lower;
    })) {
      assignmentLabelErrorElement.textContent = t('settings.users.shared_assignment_labels.validation.matches_user');
      return;
    }

    if (!window.assignmentLabels) {
      window.assignmentLabels = [];
    }
    if (!window.assignmentLabelsMeta) {
      window.assignmentLabelsMeta = [];
    }

    var newLabelIsLab = false;
    var isLabCheckbox = document.getElementById('newAssignmentLabelIsLab');
    if (isLabCheckbox) {
      newLabelIsLab = !!isLabCheckbox.checked;
      isLabCheckbox.checked = false;
    }

    window.assignmentLabels.push(label);
    window.assignmentLabelsMeta.push({ id: null, label: label, isLab: newLabelIsLab, recipients: [] });

    displayAssignmentLabels();
    newAssignmentLabelInput.value = '';
  }

  // Function to edit an existing assignment label. Opens the
  // renameAssignmentLabelModal (DentaTrak-styled) rather than a native
  // prompt(). Renaming is always allowed, including for labels currently
  // assigned to one or more cases - the label's stable id in
  // assignmentLabelsMeta is what preserves identity through a rename
  // (save-settings.php matches on id, never on text), and it propagates
  // the new text onto every case currently using the old text. The only
  // thing that ever blocks a save is genuinely REMOVING a label that's
  // still in use (see checkAssignmentLabelsInUse() in save-settings.php) -
  // that restriction is unrelated to renaming and is left untouched.
  var renameAssignmentLabelOldValue = null;

  function editAssignmentLabel(oldLabel) {
    if (!window.isPracticeAdmin) {
      return;
    }
    if (!window.assignmentLabels || window.assignmentLabels.length === 0) {
      return;
    }

    renameAssignmentLabelOldValue = oldLabel || '';

    var modal = document.getElementById('renameAssignmentLabelModal');
    var input = document.getElementById('renameAssignmentLabelInput');
    var errorEl = document.getElementById('renameAssignmentLabelError');
    if (!modal || !input) {
      return;
    }

    input.value = renameAssignmentLabelOldValue;
    if (errorEl) {
      errorEl.textContent = '';
    }

    modal.style.display = 'block';
    setTimeout(function() {
      input.focus();
      input.select();
    }, 50);
  }

  function closeRenameAssignmentLabelModal() {
    var modal = document.getElementById('renameAssignmentLabelModal');
    if (modal) {
      modal.style.display = 'none';
    }
    renameAssignmentLabelOldValue = null;
    var errorEl = document.getElementById('renameAssignmentLabelError');
    if (errorEl) {
      errorEl.textContent = '';
    }
  }

  function saveRenameAssignmentLabel() {
    if (renameAssignmentLabelOldValue === null) {
      return;
    }

    var input = document.getElementById('renameAssignmentLabelInput');
    var errorEl = document.getElementById('renameAssignmentLabelError');
    if (!input) {
      return;
    }

    var oldLabel = renameAssignmentLabelOldValue;
    var newLabel = (input.value || '').trim();

    // Empty/whitespace-only names must not be accepted.
    if (!newLabel) {
      if (errorEl) {
        errorEl.textContent = t('settings.users.shared_assignment_labels.validation.empty');
      }
      return;
    }

    if (newLabel.length > 150) {
      newLabel = newLabel.substring(0, 150);
    }

    var oldLower = oldLabel.toLowerCase();
    var newLower = newLabel.toLowerCase();

    // No-op: name unchanged (still a valid save, just nothing to do)
    if (newLower === oldLower) {
      closeRenameAssignmentLabelModal();
      return;
    }

    // Preserve existing duplicate-name validation (case-insensitive),
    // ignoring the original item itself.
    if (window.assignmentLabels && window.assignmentLabels.some(function(existing) {
      if (typeof existing !== 'string') return false;
      var existingLower = existing.toLowerCase();
      if (existingLower === oldLower) return false; // same item
      return existingLower === newLower;
    })) {
      if (errorEl) {
        errorEl.textContent = t('settings.users.shared_assignment_labels.validation.already_exists');
      }
      return;
    }

    // Optional: avoid collision with Gmail users
    if (window.gmailUsers && window.gmailUsers.some(function(email) {
      return typeof email === 'string' && email.toLowerCase() === newLower;
    })) {
      if (errorEl) {
        errorEl.textContent = t('settings.users.shared_assignment_labels.validation.matches_user');
      }
      return;
    }

    // Replace old label with new label. The underlying id in
    // assignmentLabelsMeta is left untouched - this IS what preserves Lab
    // identity through a rename (the id is what save-settings.php matches
    // on, never the text).
    for (var i = 0; i < window.assignmentLabels.length; i++) {
      if (window.assignmentLabels[i] === oldLabel) {
        window.assignmentLabels[i] = newLabel;
        if (window.assignmentLabelsMeta && window.assignmentLabelsMeta[i]) {
          window.assignmentLabelsMeta[i].label = newLabel;
        }
        break;
      }
    }

    displayAssignmentLabels();
    closeRenameAssignmentLabelModal();
  }

  // Wire up the rename modal's Save/Cancel/Close/Enter/Escape/outside-click
  // behavior once, matching the conventions of the other modals on this page.
  (function initRenameAssignmentLabelModal() {
    var modal = document.getElementById('renameAssignmentLabelModal');
    var saveBtn = document.getElementById('renameAssignmentLabelSave');
    var cancelBtn = document.getElementById('renameAssignmentLabelCancel');
    var closeBtn = document.getElementById('renameAssignmentLabelClose');
    var input = document.getElementById('renameAssignmentLabelInput');
    var form = document.getElementById('renameAssignmentLabelForm');

    if (!modal) {
      return;
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', saveRenameAssignmentLabel);
    }
    if (cancelBtn) {
      cancelBtn.addEventListener('click', closeRenameAssignmentLabelModal);
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeRenameAssignmentLabelModal);
    }
    if (form) {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        saveRenameAssignmentLabel();
      });
    }
    if (input) {
      input.addEventListener('input', function() {
        var errorEl = document.getElementById('renameAssignmentLabelError');
        if (errorEl) {
          errorEl.textContent = '';
        }
      });
    }

    window.addEventListener('click', function(e) {
      if (e.target === modal) {
        closeRenameAssignmentLabelModal();
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && modal.style.display === 'block') {
        closeRenameAssignmentLabelModal();
        // Rename is a child modal of Settings, and Settings has its own
        // document-level Escape handler for closing itself. Stop this
        // keypress here so it doesn't also reach that handler afterward
        // (which would immediately try to close/prompt-close Settings too,
        // on the very same Escape press that was only meant to dismiss
        // Rename).
        e.stopImmediatePropagation();
      }
    });
  })();

  // Function to toggle the Lab designation for an existing assignment label
  function setIsLabForAssignmentLabel(label, isLab) {
    if (!window.isPracticeAdmin || !window.assignmentLabelsMeta) {
      return;
    }
    for (var i = 0; i < window.assignmentLabels.length; i++) {
      if (window.assignmentLabels[i] === label && window.assignmentLabelsMeta[i]) {
        window.assignmentLabelsMeta[i].isLab = !!isLab;
        break;
      }
    }
  }

  // Function to display assignment labels
  function displayAssignmentLabels() {
    // Always get fresh reference to the element
    var labelsList = document.getElementById('assignmentLabelsList');

    if (!labelsList) {
      return;
    }

    labelsList.innerHTML = '';

    if (!window.assignmentLabels || window.assignmentLabels.length === 0) {
      return;
    }

    var showLabInsights = !!window.showLabInsights;

    window.assignmentLabels.forEach(function(label, idx) {
      var item = document.createElement('div');
      item.className = 'gmail-user-item';

      var labelSpan = document.createElement('span');
      labelSpan.className = 'gmail-user-email';
      labelSpan.textContent = label;

      item.appendChild(labelSpan);

      // Recipient checkbox selector for the label (admin only).
      if (window.isPracticeAdmin && window.practiceUsers && window.practiceUsers.length > 0) {
        var currentRecipients = window.assignmentLabelsMeta[idx] ? (window.assignmentLabelsMeta[idx].recipients || []) : [];
        var selectedCount = currentRecipients.length;

        var details = document.createElement('details');
        details.className = 'assignment-label-recipients';
        details.setAttribute('data-idx', idx);
        details.style.marginLeft = '0';

        var summary = document.createElement('summary');
        summary.className = 'assignment-label-recipients-summary';
        summary.textContent = (t('settings.users.shared_assignment_labels.recipients.label') || 'People notified for this label') +
          ' (' + (selectedCount === 0
            ? (t('settings.users.shared_assignment_labels.recipients.none') || 'No one')
            : t('settings.users.shared_assignment_labels.recipients.selected_count', { count: selectedCount })) +
          ')';
        details.appendChild(summary);

        var list = document.createElement('div');
        list.className = 'recipient-list';
        list.style.maxHeight = '160px';
        list.style.overflowY = 'auto';
        list.style.marginTop = '6px';

        window.practiceUsers.forEach(function(user) {
          var cbId = 'recipient-' + idx + '-' + user.id;
          var row = document.createElement('div');
          row.className = 'recipient-row';
          row.style.display = 'flex';
          row.style.alignItems = 'center';
          row.style.gap = '6px';
          row.style.padding = '2px 0';

          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.id = cbId;
          checkbox.value = user.id;
          checkbox.checked = currentRecipients.indexOf(user.id) !== -1;
          checkbox.setAttribute('data-user-id', user.id);

          var userLabel = document.createElement('label');
          userLabel.htmlFor = cbId;
          userLabel.textContent = (user.firstName + ' ' + user.lastName).trim() + ' <' + user.email + '>';
          userLabel.title = t('settings.users.shared_assignment_labels.recipients.toggle_label', { email: user.email }) || '';
          userLabel.style.cursor = 'pointer';

          checkbox.addEventListener('change', function() {
            var userId = parseInt(this.getAttribute('data-user-id'), 10);
            var meta = window.assignmentLabelsMeta && window.assignmentLabelsMeta[idx];
            if (!meta) return;
            if (this.checked) {
              if (meta.recipients.indexOf(userId) === -1) meta.recipients.push(userId);
            } else {
              meta.recipients = meta.recipients.filter(function(id) { return id !== userId; });
            }
            var count = meta.recipients.length;
            summary.textContent = (t('settings.users.shared_assignment_labels.recipients.label') || 'People notified for this label') +
              ' (' + (count === 0
                ? (t('settings.users.shared_assignment_labels.recipients.none') || 'No one')
                : t('settings.users.shared_assignment_labels.recipients.selected_count', { count: count })) +
              ')';
          });

          row.appendChild(checkbox);
          row.appendChild(userLabel);
          list.appendChild(row);
        });

        details.appendChild(list);
        item.appendChild(details);
      }

      // Lab checkbox - only rendered while SHOW_LAB_INSIGHTS is enabled.
      if (showLabInsights) {
        var meta = window.assignmentLabelsMeta && window.assignmentLabelsMeta[idx];
        var labWrapper = document.createElement('label');
        labWrapper.className = 'assignment-label-lab-checkbox';
        labWrapper.title = t('settings.users.practice_users.lab_tooltip');

        var labCheckbox = document.createElement('input');
        labCheckbox.type = 'checkbox';
        labCheckbox.checked = !!(meta && meta.isLab);
        labCheckbox.disabled = !window.isPracticeAdmin;
        labCheckbox.setAttribute('data-label', label);
        labCheckbox.addEventListener('change', function() {
          setIsLabForAssignmentLabel(this.getAttribute('data-label'), this.checked);
        });

        labWrapper.appendChild(labCheckbox);
        labWrapper.appendChild(document.createTextNode(t('settings.users.practice_users.lab')));
        item.appendChild(labWrapper);
      }

      // Edit/delete controls are only rendered for Practice Administrators.
      // Non-admins can still see the label list (it's used when assigning
      // cases) but cannot manage it - matches the server-side enforcement
      // in save-settings.php (role === 'admin').
      if (window.isPracticeAdmin) {
        // Container for action icons on the right
        var actions = document.createElement('div');
        actions.className = 'assignment-actions';
        actions.style.display = 'flex';
        actions.style.alignItems = 'center';
        actions.style.gap = '8px';

        // Edit button (same style as card edit: ✎)
        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'edit-assignment-label';
        editBtn.innerHTML = '✎';
        editBtn.title = t('settings.users.shared_assignment_labels.actions.edit');
        editBtn.setAttribute('data-label', label);
        editBtn.addEventListener('click', function() {
          var labelToEdit = this.getAttribute('data-label');
          editAssignmentLabel(labelToEdit);
        });

        // Remove button (reuse the same trash SVG as case card delete)
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'assignment-delete-btn';
        removeBtn.title = t('settings.users.shared_assignment_labels.actions.delete');
        removeBtn.innerHTML = '' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '  <polyline points="3 6 5 6 21 6"></polyline>' +
          '  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>' +
          '  <line x1="10" y1="11" x2="10" y2="17"></line>' +
          '  <line x1="14" y1="11" x2="14" y2="17"></line>' +
          '</svg>';
        removeBtn.style.color = '#e53935';
        removeBtn.setAttribute('data-label', label);
        removeBtn.addEventListener('click', function() {
          var labelToRemove = this.getAttribute('data-label');
          removeAssignmentLabel(labelToRemove);
        });

        actions.appendChild(editBtn);
        actions.appendChild(removeBtn);
        item.appendChild(actions);
      }

      labelsList.appendChild(item);
    });
  }

  // Function to remove an assignment label
  function removeAssignmentLabel(label) {
    if (!window.isPracticeAdmin) {
      return;
    }
    if (!window.assignmentLabels) {
      return;
    }

    var index = -1;
    for (var i = 0; i < window.assignmentLabels.length; i++) {
      if (window.assignmentLabels[i] === label) {
        index = i;
        break;
      }
    }

    if (index > -1) {
      window.assignmentLabels.splice(index, 1);
      if (window.assignmentLabelsMeta) {
        window.assignmentLabelsMeta.splice(index, 1);
      }
      displayAssignmentLabels();
    }
  }

  // When user clicks Continue in the sign-in modal, use popup instead of full redirect
  if (continueToGoogleBtn) {
    continueToGoogleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const targetUrl = continueToGoogleBtn.getAttribute('href') + '?mode=popup';
      openAuthPopup(targetUrl);
    });
  }


  // Modal functionality
  const privacyLink = document.getElementById('privacyLink');
  const termsLink = document.getElementById('termsLink');
  const privacyModal = document.getElementById('privacyModal');
  const termsModal = document.getElementById('termsModal');
  const closeBtns = document.querySelectorAll('.btn-close, .modal-close-btn');


  // Function to open a modal
  function openModal(modal) {
    if (modal) {
      modal.style.display = 'block';
      document.body.style.overflow = 'hidden'; // Prevent scrolling behind modal
    }
  }

  // Function to close all modals
  function closeModals() {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
      modal.style.display = 'none';
    });
    document.body.style.overflow = ''; // Restore scrolling
  }

  // Helper to determine if any modal is currently open
  function isAnyModalOpen() {
    var modals = document.querySelectorAll('.modal');
    if (!modals || modals.length === 0) return false;
    for (var i = 0; i < modals.length; i++) {
      if (modals[i].style.display === 'block') {
        return true;
      }
    }
    return false;
  }

   // Helper to determine if the UI should be considered blocked (modal or global overlay)
   function isUIBlocked() {
     if (isAnyModalOpen()) {
       return true;
     }
     if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
       return true;
     }
     return false;
   }

   // Show the global loading overlay with an optional message
   function showGlobalOverlay(message) {
     if (!pageLoadingOverlay) return;
     var textEl = pageLoadingOverlay.querySelector('.loading-text');
     if (textEl && message) {
       textEl.textContent = message;
     }
     pageLoadingOverlay.style.display = 'flex';
     pageLoadingOverlay.style.opacity = '1';
   }

   // Hide the global loading overlay
   function hideGlobalOverlay() {
     if (!pageLoadingOverlay) return;
     pageLoadingOverlay.style.opacity = '0';
     setTimeout(function() {
       pageLoadingOverlay.style.display = 'none';
     }, 300);
   }

  // Event listeners for opening modals
  if (privacyLink) {
    privacyLink.addEventListener('click', function(e) {
      e.preventDefault();
      openModal(privacyModal);
    });
  }

  if (termsLink) {
    termsLink.addEventListener('click', function(e) {
      e.preventDefault();
      openModal(termsModal);
    });
  }

  // Event listeners for closing modals
  // Exclude create case modal close button and settings modal close button - they have their own handlers with unsaved changes check
  var createCaseCloseBtn = document.getElementById('createCaseClose');
  var settingsBillingCloseBtn = document.getElementById('settingsBillingClose');
  closeBtns.forEach(btn => {
    if (btn !== createCaseCloseBtn && btn !== settingsBillingCloseBtn) {
      btn.addEventListener('click', closeModals);
    }
  });

  // Close modal when clicking outside of modal content
  window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
      // Always route case-modal backdrop clicks through
      // closeCreateCaseWithCheck() - never the generic closeModals()
      // below - regardless of hasUnsavedChanges. closeCreateCaseWithCheck()
      // already no-ops/shows a warning when there ARE unsaved changes, but
      // when there aren't (e.g. read-only "View Case" mode, which never has
      // unsaved changes) this used to fall through to closeModals(), which
      // just hides every modal with no cleanup - leaving stale state behind
      // such as the "Back to Archived Cases" button and the closeCreateCase
      // override installed by viewArchivedCase(), which then leaked into
      // whatever case was opened next.
      if (e.target === createCaseModal) {
        e.preventDefault();
        e.stopPropagation();
        closeCreateCaseWithCheck();
        return;
      }
      // Special handling for settings modal with unsaved changes
      var settingsModal = document.getElementById('settingsBillingModal');
      if (e.target === settingsModal && typeof hasUnsavedSettingsChanges === 'function' && hasUnsavedSettingsChanges()) {
        e.preventDefault();
        e.stopPropagation();
        closeSettingsBillingModal(false);
        return;
      }
      closeModals();
    }
  });

  // Close modal with Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      // Always route case-modal Escape presses through
      // closeCreateCaseWithCheck() - never the generic closeModals() below -
      // regardless of hasUnsavedChanges, for the same reason as the
      // backdrop-click handler above (read-only "View Case" mode never has
      // unsaved changes, but still needs closeCreateCase()'s cleanup).
      if (createCaseModal && createCaseModal.style.display === 'block') {
        // Check if any other modal is open that should take priority
        var deleteConfirmModal = document.getElementById('deleteConfirmModal');
        var unsavedChangesDialog = document.querySelector('[style*="position: fixed"][style*="z-index: 10000"]');

        if (deleteConfirmModal && deleteConfirmModal.style.display === 'block') {
          closeModals(); // Let delete confirmation handle it normally
          return;
        }

        if (unsavedChangesDialog) {
          return; // Let unsaved changes dialog handle it
        }

        e.preventDefault();
        e.stopPropagation();
        closeCreateCaseWithCheck();
        return;
      }

      // Don't close modals if settings modal is open (it has its own ESC handling)
      if (settingsBillingModal && settingsBillingModal.style.display === 'block') {
        return; // Let the settings-specific ESC handler deal with it
      }

      closeModals();
    }
  });

  // Practice Switcher functionality
  var practiceSwitcherBtn = document.getElementById('practiceSwitcherBtn');
  var practiceSwitcherDropdown = document.getElementById('practiceSwitcherDropdown');

  // User menu dropdown functionality
  var userMenuToggle = document.getElementById('userMenuToggle');
  var userMenu = document.getElementById('userMenu');

  // Define helper functions first
  function closePracticeSwitcher() {
    if (practiceSwitcherDropdown && practiceSwitcherDropdown.classList.contains('open')) {
      practiceSwitcherDropdown.classList.remove('open');
      if (practiceSwitcherBtn) {
        practiceSwitcherBtn.setAttribute('aria-expanded', 'false');
      }
    }
  }

  function openUserMenu() {
    if (userMenu && userMenuToggle && !userMenu.classList.contains('open')) {
      // Only one header dropdown/panel should be open at a time
      if (window.closeNotificationDropdown) window.closeNotificationDropdown();
      closePracticeSwitcher();

      userMenu.classList.add('open');
      userMenuToggle.setAttribute('aria-expanded', 'true');
    }
  }

  function closeUserMenu() {
    if (window.tourKeepsUserMenuOpen) {
      return;
    }
    if (userMenu && userMenuToggle && userMenu.classList.contains('open')) {
      userMenu.classList.remove('open');
      userMenuToggle.setAttribute('aria-expanded', 'false');
    }
  }

  // Make menu helpers available to the tour and other consumers
  window.openUserMenu = openUserMenu;
  window.closeUserMenu = closeUserMenu;
  window.closePracticeSwitcher = closePracticeSwitcher;

  // User menu event handlers
  if (userMenuToggle && userMenu) {
    userMenuToggle.addEventListener('click', function (e) {
      e.stopPropagation();
      if (userMenu.classList.contains('open')) {
        closeUserMenu();
      } else {
        openUserMenu();
      }
    });

    document.addEventListener('click', function () {
      closeUserMenu();
    });
  }

  // Practice Switcher event handlers
  if (practiceSwitcherBtn && practiceSwitcherDropdown) {
    practiceSwitcherBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      var isOpen = practiceSwitcherDropdown.classList.contains('open');
      practiceSwitcherDropdown.classList.toggle('open', !isOpen);
      practiceSwitcherBtn.setAttribute('aria-expanded', (!isOpen).toString());
      // Close user menu if open
      closeUserMenu();
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.practice-switcher')) {
        closePracticeSwitcher();
      }
    });

    // Handle practice selection
    var practiceItems = practiceSwitcherDropdown.querySelectorAll('.practice-switcher-item');
    practiceItems.forEach(function(item) {
      item.addEventListener('click', function(e) {
        e.preventDefault();
        var practiceId = this.getAttribute('data-practice-id');

        // Don't switch if already on this practice
        if (this.classList.contains('active')) {
          closePracticeSwitcher();
          return;
        }

        switchPractice(practiceId);
      });
    });
  }

  // Add click event listeners for menu items.
  // All items are addressed by explicit stable IDs — no positional or nth-child selectors.
  var billingMenuItem   = document.getElementById('billingMenuItem');
  var settingsMenuItem  = document.getElementById('settingsMenuItem');
  var contactUsMenuItem = document.getElementById('contactUsLink');

  if (billingMenuItem && !billingMenuItem.hasAttribute('data-menu-listener')) {
    billingMenuItem.setAttribute('data-menu-listener', 'true');
    billingMenuItem.addEventListener('click', function(e) {
      e.preventDefault();
      closeUserMenu();
      if (typeof window.openBillingPortal === 'function') {
        window.openBillingPortal();
      }
    });
  }

  if (settingsMenuItem && !settingsMenuItem.hasAttribute('data-menu-listener')) {
    settingsMenuItem.setAttribute('data-menu-listener', 'true');
    settingsMenuItem.addEventListener('click', function(e) {
      e.preventDefault();
      closeUserMenu();
      openSettingsBillingModal();
    });
  }

  // Wire the X close button inside the Billing modal
  var billingPortalCloseBtn = document.getElementById('billingPortalClose');
  if (billingPortalCloseBtn && !billingPortalCloseBtn.hasAttribute('data-menu-listener')) {
    billingPortalCloseBtn.setAttribute('data-menu-listener', 'true');
    billingPortalCloseBtn.addEventListener('click', function() {
      if (typeof window.closeBillingPortal === 'function') {
        window.closeBillingPortal();
      }
    });
  }

  if (contactUsMenuItem && !contactUsMenuItem.hasAttribute('data-menu-listener')) {
    contactUsMenuItem.setAttribute('data-menu-listener', 'true');
    contactUsMenuItem.addEventListener('click', function(e) {
      e.preventDefault();
      openContactModal();
    });
  }

  // Take a Tour menu item
  var startTourMenuItem = document.getElementById('startTourLink');
  if (startTourMenuItem) {
    startTourMenuItem.addEventListener('click', function(e) {
      e.preventDefault();
      // Close the user menu
      if (userMenu) {
        userMenu.classList.remove('show');
      }
      // Start the tour
      if (typeof window.startAppTour === 'function') {
        window.startAppTour();
      }
    });
  }

  // Settings Modal functionality
  var settingsBillingModal = document.getElementById('settingsBillingModal');
  var settingsBillingClose = document.getElementById('settingsBillingClose');
  var settingsCancelBtn = document.getElementById('settingsCancel');

  function openSettingsBillingModal() {
    if (!settingsBillingModal) return;

    // SECURITY: Settings is an admin-only surface. This guard covers every
    // call site (menu click, Ctrl+ shortcut, or any future direct call) so
    // non-admins can never open it client-side. window.isPracticeAdmin is
    // set from the server's isPracticeAdmin() check (api/get-settings.php) -
    // every underlying settings API independently re-verifies this too.
    if (!window.isPracticeAdmin) {
      showToast(t('settings.messages.admin_only'), 'error');
      return;
    }

    // Product decision: Settings is desktop/tablet-only on phones. Central
    // guard here covers every call site (menu click, Ctrl+, shortcut) so
    // phone users never land inside the admin UI, even via direct calls.
    if (window.matchMedia('(max-width: 720px)').matches) {
      showMobileRestrictedModal('settings');
      return;
    }

    // Do not open modal while the page is loading
    if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
      return;
    }

    // Close any open header dropdown/panel so only one surface is open
    if (window.closeUserMenu) window.closeUserMenu();
    if (window.closeNotificationDropdown) window.closeNotificationDropdown();
    if (window.closePracticeSwitcher) window.closePracticeSwitcher();

    // Show the modal
    settingsBillingModal.style.display = 'block';

    // Prevent the page behind the modal from scrolling. Every other modal in
    // this file does this (createCaseModal, trialExpiredModal, upgradeModal,
    // archivedCasesModal); the Settings modal was missing it. On touch
    // devices, leaving the background page scrollable while a tap starts
    // inside a nested scrollable region (.tab-content-scroll) can cause the
    // browser to interpret the tap as the start of a scroll/chain gesture
    // instead of a click, which cancels the synthetic click event entirely.
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';

    // Initialize settings twisties and restore their state
    initSettingsTwisties();

    // Initialize the Settings left-nav (drives which section panel is shown)
    initSettingsNav();

    // Load user settings
    loadSettings();
  }

  // Function to load user settings from the server
  function loadSettings() {
    fetch('api/get-settings.php')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Apply preferences to form fields
          applyUserSettings(
            data.preferences,
            data.gmailUsers,
            data.gmailUserLogins || {},
            data.adminUsers,
            data.practiceName,
            data.logoPath,
            data.assignmentLabels,
            data.isPracticeAdmin,
            data.practiceCreatorEmail || null,
            data.displayName || data.practiceName,
            data.legalName || '',
            data.limitedVisibilityUsers || {},
            data.canViewAnalyticsUsers || {},
            data.canEditCasesUsers || {},
            data.practiceCreatorHasGoogleAccount !== false,
            data.isGoogleDriveConnected === true,
            data.assignmentLabelsDetailed || [],
            data.practiceUsers || [],
            data.isLabUsers || {},
            data.showLabInsights === true,
            data.workflowStageLabels || {}
          );

          // Apply locale selections to the settings form
          var languageSelect = document.getElementById('languageSelect');
          if (languageSelect && data.language) {
            languageSelect.value = data.usePracticeDefault ? 'use_practice_default' : (data.storedUserLocale || data.language);
          }
          var practiceDefaultLanguageSelect = document.getElementById('practiceDefaultLanguage');
          if (practiceDefaultLanguageSelect && data.practiceDefaultLanguage) {
            practiceDefaultLanguageSelect.value = data.practiceDefaultLanguage;
          }

          // Deep link support - must run after applyUserSettings() because
          // that is what sets window.isPracticeAdmin, which the Billing
          // modal's own admin guard depends on.
          maybeOpenBillingPortalFromUrl();
        } else {
          // Error handled through UI
        }
      })
      .catch(error => {
        // Error handled through UI
      });
  }

  // Opens the Billing modal when the page was reached via `?billing=1`.
  //
  // Used by upgrade calls-to-action that live outside the app shell (e.g.
  // the plan-limit screen on baa-acceptance.php) so they lead into the SAME
  // billing/checkout flow as the Billing menu item rather than a separate
  // upgrade path. Runs at most once per page load and removes the parameter
  // from the URL so a refresh or back-navigation doesn't reopen the modal.
  var billingDeepLinkHandled = false;
  function maybeOpenBillingPortalFromUrl() {
    if (billingDeepLinkHandled) return;

    var params;
    try {
      params = new URLSearchParams(window.location.search);
    } catch (e) {
      return;
    }
    if (params.get('billing') !== '1') return;

    billingDeepLinkHandled = true;

    params.delete('billing');
    var query = params.toString();
    try {
      window.history.replaceState(
        {},
        '',
        window.location.pathname + (query ? '?' + query : '') + window.location.hash
      );
    } catch (e) {
      // Non-fatal - the modal still opens below
    }

    // openBillingPortal() applies its own admin-only and mobile guards.
    if (typeof window.openBillingPortal === 'function') {
      window.openBillingPortal();
    }
  }

  // Initialize collapsible settings sections (twisties)
  function initSettingsTwisties() {
    var twisties = document.querySelectorAll('#settingsForm .settings-twisty');
    if (!twisties || twisties.length === 0) return;

    twisties.forEach(function(twisty) {
      if (!twisty || twisty.dataset.twistyInitialized === '1') {
        return;
      }

      var header = twisty.querySelector('.settings-twisty-header');
      var content = twisty.querySelector('.settings-twisty-content');
      if (!header || !content) {
        return;
      }

      var twistyId = twisty.getAttribute('data-twisty-id') || '';
      var userKeyPart = (typeof currentUserEmail === 'string' && currentUserEmail) ? currentUserEmail.toLowerCase() : 'anonymous';
      var storageKey = 'settingsTwisty_' + userKeyPart + '_' + twistyId;
      var savedState = null;

      try {
        if (window.localStorage) {
          savedState = localStorage.getItem(storageKey);
        }
      } catch (e) {
        savedState = null;
      }

      var isOpen = savedState === null ? true : savedState === 'open';
      twisty.classList.toggle('open', isOpen);
      header.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

      header.addEventListener('click', function() {
        var nowOpen = !twisty.classList.contains('open');
        twisty.classList.toggle('open', nowOpen);
        header.setAttribute('aria-expanded', nowOpen ? 'true' : 'false');

        try {
          if (window.localStorage) {
            localStorage.setItem(storageKey, nowOpen ? 'open' : 'closed');
          }
        } catch (e) {
          // Ignore localStorage errors
        }
      });

      twisty.dataset.twistyInitialized = '1';
    });
  }

  // Initialize the Settings modal left-nav (two-column layout).
  // Drives which .settings-twisty panel is visible without altering
  // the existing accordion markup, IDs, or event handlers.
  function initSettingsNav() {
    var navItems = document.querySelectorAll('#settingsForm .settings-nav-item');
    var panels = document.querySelectorAll('#settingsForm .settings-panels .settings-twisty');
    if (!navItems || navItems.length === 0 || !panels || panels.length === 0) {
      return;
    }

    var userKeyPart = (typeof currentUserEmail === 'string' && currentUserEmail) ? currentUserEmail.toLowerCase() : 'anonymous';
    var storageKey = 'settingsActiveSection_' + userKeyPart;

    var validTargets = [];
    panels.forEach(function(panel) {
      validTargets.push(panel.getAttribute('data-twisty-id'));
    });

    function activate(target) {
      navItems.forEach(function(item) {
        item.classList.toggle('active', item.getAttribute('data-nav-target') === target);
      });
      panels.forEach(function(panel) {
        panel.classList.toggle('settings-panel-active', panel.getAttribute('data-twisty-id') === target);
      });
      // Show the selected section at the top of the right-side content area
      var panelsContainer = document.querySelector('#settingsForm .settings-panels');
      if (panelsContainer) {
        panelsContainer.scrollTop = 0;
      }
    }

    var savedTarget = null;
    try {
      if (window.localStorage) {
        savedTarget = localStorage.getItem(storageKey);
      }
    } catch (e) {
      savedTarget = null;
    }

    var initialTarget = (savedTarget && validTargets.indexOf(savedTarget) !== -1)
      ? savedTarget
      : navItems[0].getAttribute('data-nav-target');

    activate(initialTarget);

    navItems.forEach(function(item) {
      if (item.dataset.navInitialized === '1') {
        return;
      }
      item.addEventListener('click', function() {
        var target = item.getAttribute('data-nav-target');
        activate(target);
        try {
          if (window.localStorage) {
            localStorage.setItem(storageKey, target);
          }
        } catch (e) {
          // Ignore localStorage errors
        }
      });
      item.dataset.navInitialized = '1';
    });
  }

  // Apply loaded settings to form fields
  function applyUserSettings(preferences, loadedGmailUsers, loadedGmailLogins, loadedAdminUsers, practiceName, logoPath, loadedAssignmentLabels, isPracticeAdmin, practiceCreatorEmail, displayName, legalName, loadedLimitedVisibilityUsers, loadedCanViewAnalyticsUsers, loadedCanEditCasesUsers, practiceCreatorHasGoogleAccount, isGoogleDriveConnected, loadedAssignmentLabelsDetailed, loadedPracticeUsers, loadedIsLabUsers, showLabInsights, loadedWorkflowStageLabels) {
    window.isPracticeAdmin = !!isPracticeAdmin;
    window.practiceCreatorEmail = (practiceCreatorEmail || '').toLowerCase() || null;
    window.practiceCreatorHasGoogleAccount = practiceCreatorHasGoogleAccount !== false;
    window.isGoogleDriveConnected = isGoogleDriveConnected === true;
    window.showLabInsights = showLabInsights === true;
    window.practiceUsers = loadedPracticeUsers || [];

    // Fully-resolved workflow-stage display labels for the current
    // practice (see get-settings.php's `workflowStageLabels` field and
    // getStageLabel() above). Always an object; getStageLabel() falls back
    // safely to the internal status if a key is ever missing.
    window.workflowStageLabels = (loadedWorkflowStageLabels && typeof loadedWorkflowStageLabels === 'object')
      ? loadedWorkflowStageLabels
      : {};

    // Apply to the Settings inputs, Kanban headers, and status dropdown.
    // The board is already server-rendered with these same resolved
    // labels on first paint (see main.php), so on initial load this is a
    // no-op re-confirmation; it's what actually updates the UI whenever
    // Settings (re)loads later or a save just completed.
    if (typeof renderWorkflowStageLabels === 'function') {
      renderWorkflowStageLabels();
    }

    // Set tour completion status for Shepherd.js
    window.tourCompleted = !!preferences.tour_completed;
    window.tourSettingsLoaded = true;
    window.dispatchEvent(new Event('toursettingsloaded'));

    if (!window.isPracticeAdmin) {
      if (addGmailUserBtn) addGmailUserBtn.disabled = true;
      if (newGmailUserInput) newGmailUserInput.disabled = true;
    } else {
      if (addGmailUserBtn) addGmailUserBtn.disabled = false;
      if (newGmailUserInput) newGmailUserInput.disabled = false;
    }

    // Apply theme selection
    const themeValue = preferences.theme || 'light';
    const themeDropdown = document.getElementById('theme');
    if (themeDropdown) {
      themeDropdown.value = themeValue;
    }

    // Update practice name in header (use displayName if available)
    var nameToDisplay = displayName || practiceName;
    if (nameToDisplay) {
      const practiceNameElement = document.querySelector('.practice-name');
      if (practiceNameElement) {
        practiceNameElement.textContent = nameToDisplay;
      }
    }

    // Populate display name field in settings
    const displayNameInput = document.getElementById('displayName');
    if (displayNameInput) {
      displayNameInput.value = displayName || practiceName || '';
    }

    // Update logo display from the value currently saved in the database
    updateLogoDisplay(logoPath);

    // Reset logo state tracking for this session
    window.currentLogoPath = logoPath || '';
    window.pendingLogoPath = '';
    window.logoMarkedForRemoval = false;

    // Apply checkbox values
    const allowCardDelete = preferences.allow_card_delete !== undefined ? !!preferences.allow_card_delete : true;
    // Sync checkbox with database value
    const allowCardDeleteCheckbox = document.getElementById('allowCardDelete');
    if (allowCardDeleteCheckbox) {
      allowCardDeleteCheckbox.checked = allowCardDelete;
    }
    document.getElementById('highlightPastDue').checked = !!preferences.highlight_past_due;

    // Apply allow card delete preference to show/hide archive buttons
    var mainContainer = document.querySelector('.main-container');
    var cardContainer = document.querySelector('.kanban-board');
    var dashboard = document.querySelector('.dashboard');

    if (mainContainer) {
      if (allowCardDelete) {
        mainContainer.classList.add('allow-card-delete');
      } else {
        mainContainer.classList.remove('allow-card-delete');
      }
    }

    if (cardContainer) {
      if (allowCardDelete) {
        cardContainer.classList.add('allow-card-delete');
      } else {
        cardContainer.classList.remove('allow-card-delete');
      }
    }

    if (dashboard) {
      if (allowCardDelete) {
        dashboard.classList.add('allow-card-delete');
      } else {
        dashboard.classList.remove('allow-card-delete');
      }
    }

    // Save allow card delete preference in localStorage
    localStorage.setItem('allow_card_delete', allowCardDelete ? 'true' : 'false');

    // Apply past due days value
    const pastDueDaysInput = document.getElementById('pastDueDays');
    if (pastDueDaysInput) {
      pastDueDaysInput.value = preferences.past_due_days || 1;
    }

    // Apply delivered hide days value
    const deliveredHideDaysInput = document.getElementById('deliveredHideDays');
    if (deliveredHideDaysInput) {
      deliveredHideDaysInput.value = (typeof preferences.delivered_hide_days === 'number' ? preferences.delivered_hide_days : 0);
    }

    // Apply coming due values
    document.getElementById('highlightComingDue').checked = !!preferences.highlight_coming_due;
    const comingDueDaysInput = document.getElementById('comingDueDays');
    if (comingDueDaysInput) {
      comingDueDaysInput.value = preferences.coming_due_days || 5;
    }

    // Save coming due preferences in localStorage
    localStorage.setItem('highlight_coming_due', preferences.highlight_coming_due ? 'true' : 'false');
    localStorage.setItem('coming_due_days', (preferences.coming_due_days || 5).toString());

    // Apply appointment risk values
    document.getElementById('highlightAppointmentRisk').checked = !!preferences.highlight_appointment_risk;
    const appointmentRiskDaysInput = document.getElementById('appointmentRiskDays');
    if (appointmentRiskDaysInput) {
      appointmentRiskDaysInput.value = preferences.appointment_risk_days || 3;
    }

    // Save appointment risk preferences in localStorage
    localStorage.setItem('highlight_appointment_risk', preferences.highlight_appointment_risk ? 'true' : 'false');
    localStorage.setItem('appointment_risk_days', (preferences.appointment_risk_days || 3).toString());

    // Update conditional visibility
    const pastDueSettings = document.getElementById('pastDueSettings');
    if (pastDueSettings) {
      pastDueSettings.classList.toggle('hidden', !preferences.highlight_past_due);
    }
    const comingDueSettings = document.getElementById('comingDueSettings');
    if (comingDueSettings) {
      comingDueSettings.classList.toggle('hidden', !preferences.highlight_coming_due);
    }
    const appointmentRiskSettings = document.getElementById('appointmentRiskSettings');
    if (appointmentRiskSettings) {
      appointmentRiskSettings.classList.toggle('hidden', !preferences.highlight_appointment_risk);
    }

    // Apply Google Drive backup setting - fetch from practice-level API
    const googleDriveBackupCheckbox = document.getElementById('googleDriveBackup');
    if (googleDriveBackupCheckbox) {
      // Fetch backup status from the practice-level API
      fetch('/api/google-drive-backup.php?action=status', { credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            googleDriveBackupCheckbox.checked = data.backupEnabled || false;
            window.originalGoogleDriveBackup = data.backupEnabled || false;

            // Show/hide the workspace warning based on Drive connection
            var workspaceWarning = document.getElementById('googleDriveWorkspaceWarning');
            var backupNote = document.getElementById('googleDriveBackupNote');
            if (!data.driveConnected && workspaceWarning) {
              workspaceWarning.style.display = 'block';
              if (backupNote) backupNote.style.display = 'none';
            } else if (workspaceWarning) {
              workspaceWarning.style.display = 'none';
              if (backupNote) backupNote.style.display = 'block';
            }
          }
        })
        .catch(function(err) {

          googleDriveBackupCheckbox.checked = false;
          window.originalGoogleDriveBackup = false;
        });
    }

    // Load Admin users first
    if (loadedAdminUsers && loadedAdminUsers.length > 0) {
      window.adminUsers = loadedAdminUsers.slice();
    } else {
      window.adminUsers = [];
    }

    // Load regular users
    if (loadedGmailUsers && loadedGmailUsers.length > 0) {
      window.gmailUsers = loadedGmailUsers.slice();
      window.gmailUserLogins = loadedGmailLogins || {};
    } else {
      window.gmailUsers = [];
      window.gmailUserLogins = {};
    }

    // Load permission maps
    window.limitedVisibilityUsers = loadedLimitedVisibilityUsers || {};
    window.canViewAnalyticsUsers = loadedCanViewAnalyticsUsers || {};
    window.canEditCasesUsers = loadedCanEditCasesUsers || {};
    window.isLabUsers = loadedIsLabUsers || {};

    // Add limited-visibility class to body if current user has limited visibility
    // This is used by real-time updates to know whether to show/hide cases based on assignment
    var currentEmail = currentUserEmail.toLowerCase();
    if (currentEmail && window.limitedVisibilityUsers && window.limitedVisibilityUsers[currentEmail]) {
      document.body.classList.add('limited-visibility');
    } else {
      document.body.classList.remove('limited-visibility');
    }

    // Render combined practice users grid
    displayPracticeUsers();

    // Load assignment labels. assignmentLabelsMeta mirrors
    // assignmentLabels index-for-index and carries the stable id/isLab
    // metadata needed by the Settings save flow; it falls back to
    // {id: null, isLab: false} entries if the server hasn't returned the
    // detailed payload for some reason (keeps this code resilient).
    if (loadedAssignmentLabels && loadedAssignmentLabels.length > 0) {
      window.assignmentLabels = loadedAssignmentLabels.slice();
      window.assignmentLabelsMeta = loadedAssignmentLabels.map(function(label, idx) {
        var detailed = (loadedAssignmentLabelsDetailed || [])[idx];
        return {
          id: (detailed && typeof detailed.id === 'number') ? detailed.id : null,
          label: label,
          isLab: !!(detailed && detailed.isLab),
          recipients: (detailed && Array.isArray(detailed.recipients)) ? detailed.recipients : []
        };
      });
      displayAssignmentLabels();
    } else {
      window.assignmentLabels = [];
      window.assignmentLabelsMeta = [];
      displayAssignmentLabels();
    }

    // Capture original values immediately. All state that needs to be
    // snapshotted is already in window.assignmentLabelsMeta; the label list
    // DOM is reconstructed from that state, so a setTimeout is unnecessary
    // and creates a race where rapid user edits beat the snapshot.
    captureOriginalSettingsValues();
  }

  // Store original settings values for change detection
  window.originalSettingsValues = {};

  function captureOriginalSettingsValues() {
    // Deep copy permission maps to avoid reference issues
    var limitedCopy = {};
    var analyticsCopy = {};
    var editCopy = {};

    if (window.limitedVisibilityUsers) {
      Object.keys(window.limitedVisibilityUsers).forEach(function(key) {
        limitedCopy[key] = window.limitedVisibilityUsers[key];
      });
    }
    if (window.canViewAnalyticsUsers) {
      Object.keys(window.canViewAnalyticsUsers).forEach(function(key) {
        analyticsCopy[key] = window.canViewAnalyticsUsers[key];
      });
    }
    if (window.canEditCasesUsers) {
      Object.keys(window.canEditCasesUsers).forEach(function(key) {
        editCopy[key] = window.canEditCasesUsers[key];
      });
    }

    var labUsersCopy = {};
    if (window.isLabUsers) {
      Object.keys(window.isLabUsers).forEach(function(key) {
        labUsersCopy[key] = window.isLabUsers[key];
      });
    }

    var labelsMetaCopy = (window.assignmentLabelsMeta || []).map(function(m) {
      return { id: m.id, isLab: !!m.isLab, recipients: (m.recipients || []).slice() };
    });

    var workflowStageLabelInputsCopy = {};
    document.querySelectorAll('.workflow-stage-label-input').forEach(function(input) {
      if (input.dataset.internalStatus) {
        workflowStageLabelInputsCopy[input.dataset.internalStatus] = input.value;
      }
    });

    window.originalSettingsValues = {
      theme: document.getElementById('theme')?.value || 'light',
      displayName: document.getElementById('displayName')?.value || '',
      allowCardDelete: document.getElementById('allowCardDelete')?.checked || false,
      highlightPastDue: document.getElementById('highlightPastDue')?.checked || false,
      pastDueDays: document.getElementById('pastDueDays')?.value || '1',
      highlightComingDue: document.getElementById('highlightComingDue')?.checked || false,
      comingDueDays: document.getElementById('comingDueDays')?.value || '5',
      highlightAppointmentRisk: document.getElementById('highlightAppointmentRisk')?.checked || false,
      appointmentRiskDays: document.getElementById('appointmentRiskDays')?.value || '3',
      deliveredHideDays: document.getElementById('deliveredHideDays')?.value || '0',
      googleDriveBackup: document.getElementById('googleDriveBackup')?.checked || false,
      gmailUsers: window.gmailUsers ? window.gmailUsers.slice() : [],
      adminUsers: window.adminUsers ? window.adminUsers.slice() : [],
      assignmentLabels: window.assignmentLabels ? window.assignmentLabels.slice() : [],
      limitedVisibilityUsers: limitedCopy,
      canViewAnalyticsUsers: analyticsCopy,
      canEditCasesUsers: editCopy,
      isLabUsers: labUsersCopy,
      assignmentLabelsMeta: labelsMetaCopy,
      workflowStageLabelInputs: workflowStageLabelInputsCopy,
      logoPath: window.currentLogoPath || '',
      logoMarkedForRemoval: false,
      pendingLogoPath: ''
    };
  }

  function hasUnsavedSettingsChanges() {
    var orig = window.originalSettingsValues;
    if (!orig || Object.keys(orig).length === 0) return false;

    // Check simple form fields
    if ((document.getElementById('theme')?.value || 'light') !== orig.theme) return true;
    if ((document.getElementById('displayName')?.value || '') !== orig.displayName) return true;
    if ((document.getElementById('allowCardDelete')?.checked || false) !== orig.allowCardDelete) return true;
    if ((document.getElementById('highlightPastDue')?.checked || false) !== orig.highlightPastDue) return true;
    if ((document.getElementById('pastDueDays')?.value || '1') !== orig.pastDueDays) return true;
    if ((document.getElementById('highlightComingDue')?.checked || false) !== orig.highlightComingDue) return true;
    if ((document.getElementById('comingDueDays')?.value || '5') !== orig.comingDueDays) return true;
    if ((document.getElementById('highlightAppointmentRisk')?.checked || false) !== orig.highlightAppointmentRisk) return true;
    if ((document.getElementById('appointmentRiskDays')?.value || '3') !== orig.appointmentRiskDays) return true;
    if ((document.getElementById('deliveredHideDays')?.value || '0') !== orig.deliveredHideDays) return true;
    if ((document.getElementById('googleDriveBackup')?.checked || false) !== orig.googleDriveBackup) return true;

    // Check logo changes
    if (window.logoMarkedForRemoval) return true;
    if (window.pendingLogoPath && window.pendingLogoPath !== orig.logoPath) return true;

    // Check Workflow Stage Names inputs
    var origWorkflowStageLabelInputs = orig.workflowStageLabelInputs || {};
    var workflowStageInputsChanged = false;
    document.querySelectorAll('.workflow-stage-label-input').forEach(function(input) {
      var status = input.dataset.internalStatus;
      if (!status) return;
      if ((input.value || '') !== (origWorkflowStageLabelInputs[status] || '')) {
        workflowStageInputsChanged = true;
      }
    });
    if (workflowStageInputsChanged) return true;

    // Check arrays (users, labels)
    var currentGmailUsers = window.gmailUsers || [];
    var currentAdminUsers = window.adminUsers || [];
    var currentLabels = window.assignmentLabels || [];
    var currentLimitedUsers = window.limitedVisibilityUsers || {};
    var currentAnalyticsUsers = window.canViewAnalyticsUsers || {};
    var currentEditUsers = window.canEditCasesUsers || {};
    var currentLabUsers = window.isLabUsers || {};
    var origLimitedUsers = orig.limitedVisibilityUsers || {};
    var origAnalyticsUsers = orig.canViewAnalyticsUsers || {};
    var origEditUsers = orig.canEditCasesUsers || {};
    var origLabUsers = orig.isLabUsers || {};

    if (currentGmailUsers.length !== orig.gmailUsers.length) return true;
    if (currentAdminUsers.length !== orig.adminUsers.length) return true;
    if (currentLabels.length !== orig.assignmentLabels.length) return true;

    // Deep compare arrays
    for (var i = 0; i < currentGmailUsers.length; i++) {
      if (currentGmailUsers[i] !== orig.gmailUsers[i]) return true;
    }
    for (var i = 0; i < currentAdminUsers.length; i++) {
      if (currentAdminUsers[i] !== orig.adminUsers[i]) return true;
    }
    for (var i = 0; i < currentLabels.length; i++) {
      if (currentLabels[i] !== orig.assignmentLabels[i]) return true;
    }

    // Check Lab designation and notification recipients on assignment labels
    var currentLabelsMeta = window.assignmentLabelsMeta || [];
    var origLabelsMeta = orig.assignmentLabelsMeta || [];
    if (currentLabelsMeta.length !== origLabelsMeta.length) return true;
    for (var i = 0; i < currentLabelsMeta.length; i++) {
      if (!!currentLabelsMeta[i].isLab !== !!origLabelsMeta[i].isLab) return true;

      var currentRecipients = currentLabelsMeta[i].recipients || [];
      var origRecipients = origLabelsMeta[i].recipients || [];
      if (currentRecipients.length !== origRecipients.length) return true;
      for (var j = 0; j < currentRecipients.length; j++) {
        if (currentRecipients[j] !== origRecipients[j]) return true;
      }
    }

    // Check user permission maps
    var limitedKeys = Object.keys(currentLimitedUsers);
    var origLimitedKeys = Object.keys(origLimitedUsers);
    if (limitedKeys.length !== origLimitedKeys.length) return true;
    for (var i = 0; i < limitedKeys.length; i++) {
      var key = limitedKeys[i];
      if (currentLimitedUsers[key] !== origLimitedUsers[key]) return true;
    }

    var analyticsKeys = Object.keys(currentAnalyticsUsers);
    var origAnalyticsKeys = Object.keys(origAnalyticsUsers);
    if (analyticsKeys.length !== origAnalyticsKeys.length) return true;
    for (var i = 0; i < analyticsKeys.length; i++) {
      var key = analyticsKeys[i];
      if (currentAnalyticsUsers[key] !== origAnalyticsUsers[key]) return true;
    }

    var editKeys = Object.keys(currentEditUsers);
    var origEditKeys = Object.keys(origEditUsers);
    if (editKeys.length !== origEditKeys.length) return true;
    for (var i = 0; i < editKeys.length; i++) {
      var key = editKeys[i];
      if (currentEditUsers[key] !== origEditUsers[key]) return true;
    }

    var labKeys = Object.keys(currentLabUsers);
    var origLabKeys = Object.keys(origLabUsers);
    if (labKeys.length !== origLabKeys.length) return true;
    for (var i = 0; i < labKeys.length; i++) {
      var key = labKeys[i];
      if (!!currentLabUsers[key] !== !!origLabUsers[key]) return true;
    }

    return false;
  }

  // Track if we're currently showing the unsaved changes dialog for settings
  var settingsUnsavedDialogOpen = false;

  function closeSettingsBillingModal(forceClose) {
    // Rename Assignment Label is a child modal opened from within Settings.
    // While it's open, Settings must not close through ANY path (X, Cancel,
    // outside-click, Escape, or a force-close) - otherwise Settings' state
    // (backdrop, body scroll lock) would be torn down while the child modal
    // is still visually on top of it, leaving things inconsistent.
    var renameLabelModal = document.getElementById('renameAssignmentLabelModal');
    if (renameLabelModal && renameLabelModal.style.display === 'block') {
      return;
    }
    if (settingsBillingModal) {
      // Check for unsaved changes unless force closing
      if (!forceClose && hasUnsavedSettingsChanges()) {
        // Don't show another dialog if one is already open
        if (settingsUnsavedDialogOpen) {
          return;
        }

        // Show unsaved changes dialog ON TOP of the settings modal (modal stays visible)
        // This matches the Create/Edit Case modal behavior
        showSettingsUnsavedChangesWarning(function() {
          // User chose "Close Without Saving" - close the modal and reload original values
          settingsBillingModal.style.display = 'none';
          document.body.style.overflow = '';
          document.documentElement.style.overflow = '';
          resetLogoUploadState();
          loadSettings();
        });
        return; // Don't close the modal yet - wait for user decision
      }
      // No unsaved changes or force closing, close immediately
      settingsBillingModal.style.display = 'none';
      document.body.style.overflow = '';
      document.documentElement.style.overflow = '';

      // Reset logo upload state when closing without saving
      if (!forceClose) {
        resetLogoUploadState();
      }
    }
  }

  /**
   * Reset logo upload state - clears file input so same file can be selected again
   */
  function resetLogoUploadState() {
    const logoInput = document.getElementById('practiceLogo');
    if (logoInput) {
      logoInput.value = '';
    }
    // Reset pending state
    window.pendingLogoPath = '';
    window.logoMarkedForRemoval = false;
  }

  /**
   * Show unsaved changes warning dialog for Settings modal.
   * Uses the same copy and button labels as the Create/Edit Case modal.
   * Dialog appears ON TOP of the settings modal (modal stays visible underneath).
   * @param {Function} onCloseWithoutSaving - Callback when user chooses to close without saving
   */
  function showSettingsUnsavedChangesWarning(onCloseWithoutSaving) {
    settingsUnsavedDialogOpen = true;

    // Create custom confirmation dialog (same style as Create/Edit Case modal)
    var dialog = document.createElement('div');
    dialog.id = 'settingsUnsavedDialog';
    dialog.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10000;
    `;

    var content = document.createElement('div');
    content.style.cssText = `
      background: white;
      padding: 30px;
      border-radius: 8px;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    `;

    content.innerHTML = `
      <h3 style="margin: 0 0 15px 0; color: #333;">` + t('settings.common.unsaved_changes') + `</h3>
      <p style="margin: 0 0 25px 0; color: #666; line-height: 1.5;">
        ` + t('settings.messages.unsaved_message') + `
      </p>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button id="settings-stay-btn" style="
          padding: 10px 20px;
          background: #6c757d;
          color: white;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">` + t('settings.common.stay') + `</button>
        <button id="settings-close-btn" style="
          padding: 10px 20px;
          background: #dc3545;
          color: white;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">` + t('settings.common.close_without_saving') + `</button>
      </div>
    `;

    dialog.appendChild(content);
    document.body.appendChild(dialog);

    function closeDialog() {
      settingsUnsavedDialogOpen = false;
      if (dialog.parentNode) {
        document.body.removeChild(dialog);
      }
    }

    // "Stay" button - close dialog only, keep settings modal open
    document.getElementById('settings-stay-btn').addEventListener('click', function() {
      closeDialog();
    });

    // "Close Without Saving" button - close dialog and execute callback to close modal
    document.getElementById('settings-close-btn').addEventListener('click', function() {
      closeDialog();
      if (onCloseWithoutSaving) onCloseWithoutSaving();
    });

    // Clicking backdrop = "Stay" (close dialog, keep modal open)
    dialog.addEventListener('click', function(e) {
      if (e.target === dialog) {
        closeDialog();
      }
    });
  }

  // Add event listener for closing the modal
  if (settingsBillingClose) {
    settingsBillingClose.addEventListener('click', function() {
      closeSettingsBillingModal(false);
    });
  }

  // Mobile Restricted Modal (Settings/Billing on phones) - Close button + backdrop click
  var mobileRestrictedModalEl = document.getElementById('mobileRestrictedModal');
  var mobileRestrictedCloseBtn = document.getElementById('mobileRestrictedClose');
  if (mobileRestrictedCloseBtn && mobileRestrictedModalEl) {
    mobileRestrictedCloseBtn.addEventListener('click', function() {
      mobileRestrictedModalEl.style.display = 'none';
    });
    mobileRestrictedModalEl.addEventListener('click', function(e) {
      if (e.target === mobileRestrictedModalEl) {
        mobileRestrictedModalEl.style.display = 'none';
      }
    });
  }

  if (settingsCancelBtn) {
    settingsCancelBtn.addEventListener('click', function() {
      closeSettingsBillingModal(false);
    });
  }

  // Close modal when clicking outside
  window.addEventListener('click', function(e) {
    if (e.target === settingsBillingModal) {
      e.preventDefault();
      e.stopPropagation();
      closeSettingsBillingModal(false);
    }
  });

  // Keyboard handlers for settings modal and create case
  document.addEventListener('keydown', function(event) {
    var target = event.target;
    var tagName = target && target.tagName ? target.tagName.toLowerCase() : '';
    var isTypingField = tagName === 'input' || tagName === 'textarea' || tagName === 'select' || (target && target.isContentEditable);

    // Handle Escape for settings modal
    if (event.key === 'Escape' && settingsBillingModal && settingsBillingModal.style.display === 'block') {
      // If the unsaved changes dialog is open, ESC closes the dialog (acts as "Stay")
      if (settingsUnsavedDialogOpen) {
        var dialog = document.getElementById('settingsUnsavedDialog');
        if (dialog && dialog.parentNode) {
          settingsUnsavedDialogOpen = false;
          document.body.removeChild(dialog);
        }
        return;
      }
      // Otherwise, attempt to close the settings modal (will show dialog if unsaved changes)
      closeSettingsBillingModal(false);
      return;
    }

    // If any modal is open or the global overlay is visible, do not process other global shortcuts
    if (isUIBlocked()) {
      return;
    }

    // Add shortcut: Ctrl+, (or Cmd+,) opens the settings modal
    if (event.key === ',') {
      // Do not trigger the shortcut while the user is typing in a field
      if (isTypingField) {
        return;
      }

      // Do not trigger the shortcut while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      if ((event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        openSettingsBillingModal();
      }
    }

    // Add shortcut: Ctrl+K (or Cmd+K) opens the Create New Case modal
    if (event.key === 'k' || event.key === 'K') {
      // Do not trigger the shortcut while the user is typing in a field
      if (isTypingField) {
        return;
      }

      // Do not trigger the shortcut while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      if ((event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        openCreateCase();
      }
    }

    // Add shortcut: Ctrl+Shift+F (or Cmd+Shift+F) opens the Feedback modal
    if (event.key === 'f' || event.key === 'F') {
      // Do not trigger the shortcut while the user is typing in a field
      if (isTypingField) {
        return;
      }

      // Do not trigger the shortcut while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      if ((event.ctrlKey || event.metaKey) && event.shiftKey) {
        event.preventDefault();
        openContactModal();
      }
    }
  });

  // Handle the past due settings visibility toggle
  var highlightPastDueCheckbox = document.getElementById('highlightPastDue');
  var pastDueSettings = document.getElementById('pastDueSettings');

  if (highlightPastDueCheckbox && pastDueSettings) {
    highlightPastDueCheckbox.addEventListener('change', function() {
      // Toggle the visibility of the past due settings based on checkbox state
      if (this.checked) {
        pastDueSettings.classList.remove('hidden');
      } else {
        pastDueSettings.classList.add('hidden');
      }
    });
  }

  // Handle the coming due settings visibility toggle
  var highlightComingDueCheckbox = document.getElementById('highlightComingDue');
  var comingDueSettings = document.getElementById('comingDueSettings');

  if (highlightComingDueCheckbox && comingDueSettings) {
    highlightComingDueCheckbox.addEventListener('change', function() {
      if (this.checked) {
        comingDueSettings.classList.remove('hidden');
      } else {
        comingDueSettings.classList.add('hidden');
      }
    });
  }

  // Handle the appointment risk settings visibility toggle
  var highlightAppointmentRiskCheckbox = document.getElementById('highlightAppointmentRisk');
  var appointmentRiskSettings = document.getElementById('appointmentRiskSettings');

  if (highlightAppointmentRiskCheckbox && appointmentRiskSettings) {
    highlightAppointmentRiskCheckbox.addEventListener('change', function() {
      if (this.checked) {
        appointmentRiskSettings.classList.remove('hidden');
      } else {
        appointmentRiskSettings.classList.add('hidden');
      }
    });
  }

  // Handle Google Drive Backup checkbox with confirmation modal
  var googleDriveBackupCheckbox = document.getElementById('googleDriveBackup');
  var googleDriveBackupModal = document.getElementById('googleDriveBackupModal');
  var gdBackupCancel = document.getElementById('gdBackupCancel');
  var gdBackupConfirm = document.getElementById('gdBackupConfirm');

  if (googleDriveBackupCheckbox && googleDriveBackupModal) {
    googleDriveBackupCheckbox.addEventListener('change', function() {
      var checkbox = this;

      if (this.checked && !window.originalGoogleDriveBackup) {
        // Enabling backup - show confirmation modal
        this.checked = false;
        googleDriveBackupModal.style.display = 'flex';
      } else if (!this.checked && window.originalGoogleDriveBackup) {
        // Disabling backup - call API directly
        checkbox.disabled = true;
        fetch('/api/google-drive-backup.php?action=disable', {
          method: 'POST',
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          checkbox.disabled = false;
          if (data.success) {
            window.originalGoogleDriveBackup = false;
            showToast(t('settings.display.google_drive.disabled_toast'), 'success');
          } else {
            checkbox.checked = true; // Revert
            showToast(data.message || t('settings.display.google_drive.disable_error'), 'error');
          }
        })
        .catch(function(err) {
          checkbox.disabled = false;
          checkbox.checked = true; // Revert
          showToast(t('settings.display.google_drive.disable_unknown_error'), 'error');
        });
      }
    });

    if (gdBackupCancel) {
      gdBackupCancel.addEventListener('click', function() {
        googleDriveBackupModal.style.display = 'none';
        googleDriveBackupCheckbox.checked = false;
      });
    }

    if (gdBackupConfirm) {
      gdBackupConfirm.addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = t('settings.display.google_drive.enabling');

        // Call API to enable backup (creates folder)
        fetch('/api/google-drive-backup.php?action=enable', {
          method: 'POST',
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {

          btn.disabled = false;
          btn.textContent = t('settings.display.google_drive.confirm_button');
          googleDriveBackupModal.style.display = 'none';

          if (data.success) {
            googleDriveBackupCheckbox.checked = true;
            window.originalGoogleDriveBackup = true;
            showToast(t('settings.display.google_drive.enabled_toast'), 'success');
          } else {
            googleDriveBackupCheckbox.checked = false;
            if (data.noWorkspace) {
              showToast(t('settings.display.google_drive.workspace_required'), 'error');
            } else if (data.needsDriveConnection) {
              showToast(t('settings.display.google_drive.connect_first'), 'error');
            } else {
              showToast(data.message || t('settings.display.google_drive.unknown_error'), 'error');
            }
          }
        })
        .catch(function(err) {
          btn.disabled = false;
          btn.textContent = t('settings.display.google_drive.confirm_button');
          googleDriveBackupModal.style.display = 'none';
          googleDriveBackupCheckbox.checked = false;
          showToast(t('settings.display.google_drive.enable_error_prefix') + err.message, 'error');
        });
      });
    }

    // Close modal when clicking outside
    googleDriveBackupModal.addEventListener('click', function(e) {
      if (e.target === googleDriveBackupModal) {
        googleDriveBackupModal.style.display = 'none';
        googleDriveBackupCheckbox.checked = false;
      }
    });
  }

  // Feedback Modal functionality
  var feedbackModal = document.getElementById('feedbackModal');
  var feedbackClose = document.getElementById('feedbackClose');
  var feedbackCancel = document.getElementById('feedbackCancel');
  var feedbackForm = document.getElementById('feedbackForm');

  // Feedback Success Modal
  var feedbackSuccessModal = document.getElementById('feedbackSuccessModal');
  var feedbackSuccessClose = document.getElementById('feedbackSuccessClose');
  var feedbackSuccessOk = document.getElementById('feedbackSuccessOk');

  function openContactModal() {
    if (feedbackModal) {
      // Do not open modal while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      feedbackModal.style.display = 'block';
      // Reset the form when opening
      if (feedbackForm) {
        feedbackForm.reset();
      }
      // Reset to feedback tab
      const feedbackTab = document.querySelector('[data-tab="feedback"]');
      const supportTab = document.querySelector('[data-tab="support"]');
      const feedbackContent = document.getElementById('feedback-tab');
      const supportContent = document.getElementById('support-tab');

      if (feedbackTab && supportTab && feedbackContent && supportContent) {
        feedbackTab.classList.add('active');
        supportTab.classList.remove('active');
        feedbackContent.classList.add('active');
        supportContent.classList.remove('active');
      }
    }
  }

  function closeFeedbackModal() {
    if (feedbackModal) {
      feedbackModal.style.display = 'none';
    }
  }

  function openFeedbackSuccessModal() {
    if (feedbackSuccessModal) {
      feedbackSuccessModal.style.display = 'block';
    }
  }

  function closeFeedbackSuccessModal() {
    if (feedbackSuccessModal) {
      feedbackSuccessModal.style.display = 'none';
    }
  }

  // Add event listeners for the feedback modal
  if (feedbackClose) {
    feedbackClose.addEventListener('click', closeFeedbackModal);
  }

  if (feedbackCancel) {
    feedbackCancel.addEventListener('click', closeFeedbackModal);
  }

  // Contact tabs functionality
  const contactTabs = document.querySelectorAll('.contact-tab');
  const contactTabContents = document.querySelectorAll('.contact-tab-content');

  contactTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const targetTab = tab.dataset.tab;

      // Remove active class from all tabs and contents
      contactTabs.forEach(t => t.classList.remove('active'));
      contactTabContents.forEach(c => c.classList.remove('active'));

      // Add active class to clicked tab and corresponding content
      tab.classList.add('active');
      document.getElementById(targetTab + '-tab').classList.add('active');
    });
  });

  // Close modal when clicking outside
  window.addEventListener('click', function(e) {
    if (e.target === feedbackModal) {
      closeFeedbackModal();
    }
  });

  // Add event listeners for the success modal
  if (feedbackSuccessClose) {
    feedbackSuccessClose.addEventListener('click', closeFeedbackSuccessModal);
  }

  if (feedbackSuccessOk) {
    feedbackSuccessOk.addEventListener('click', closeFeedbackSuccessModal);
  }

  // Add direct click handler for submit button as a backup
  var feedbackSubmit = document.getElementById('feedbackSubmit');
  if (feedbackSubmit) {
    feedbackSubmit.addEventListener('click', function(e) {
      // Process feedback submission
      // If the form is valid, manually trigger submission handling
      if (feedbackForm && feedbackForm.checkValidity()) {
        e.preventDefault();
        submitFeedbackForm();
      }
    });
  }

  // Close success modal when clicking outside
  window.addEventListener('click', function(e) {
    if (e.target === feedbackSuccessModal) {
      closeFeedbackSuccessModal();
    }
  });

  // Handle escape key for modals
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      if (feedbackModal && feedbackModal.style.display === 'block') {
        closeFeedbackModal();
      }
      if (feedbackSuccessModal && feedbackSuccessModal.style.display === 'block') {
        closeFeedbackSuccessModal();
      }
    }
  });

  // Function to handle form submission
  function submitFeedbackForm() {
    // Process feedback data

    // Get form data
    // The feedback icon (feedback_type) is optional - a user can submit
    // feedback with just text and no icon selected.
    var feedbackType = document.querySelector('input[name="feedback_type"]:checked');
    var feedbackComments = document.getElementById('feedback_comments');

    // Show loading state
    var submitBtn = document.getElementById('feedbackSubmit');
    var originalBtnText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = t('common.sending');

    // Prepare the data
    var formData = {
      feedback_type: feedbackType ? feedbackType.value : '',
      feedback_comments: feedbackComments.value || ''
    };

    // Send the feedback data

    // Send the data to the server
    fetch('api/send-feedback.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(formData),
      credentials: 'same-origin'
    })
    .then(response => {
      // Process response
      return response.json();
    })
    .then(data => {
      // Process response data
      if (data.success) {
        // Close the feedback form and show success modal
        closeFeedbackModal();
        setTimeout(function() {
          openFeedbackSuccessModal();
        }, 300); // Small delay for better UX
      } else {
        // Show error message
        showToast(data.message || t('feedback.unable_to_send'), 'error');
      }
    })
    .catch(error => {
      // Handle fetch error
      if (typeof NetworkErrorHandler !== 'undefined') {
        NetworkErrorHandler.handle(error, 'sending feedback');
      } else {
        showToast(t('feedback.send_error'), 'error');
      }
    })
    .finally(() => {
      // Reset button state
      submitBtn.disabled = false;
      submitBtn.textContent = originalBtnText;
    });
  }

  // Handle feedback form submission
  if (feedbackForm) {
    feedbackForm.addEventListener('submit', function(e) {
      // Form submitted
      e.preventDefault();
      submitFeedbackForm();
    });
  }

  // Gmail user functionality
  window.gmailUsers = []; // Will store all added regular users - use window to ensure global scope
  window.gmailUserLogins = {}; // Map of email -> last_login_at timestamp (or null)
  window.adminUsers = []; // Will store all admin users - use window to ensure global scope
  window.assignmentLabels = []; // Will store free-text assignment labels for cases
  window.isPracticeAdmin = false;
  window.practiceCreatorEmail = null; // Lowercased email of the practice creator
  var addGmailUserBtn = document.getElementById('addGmailUser');
  var newGmailUserInput = document.getElementById('newGmailUser');
  var gmailErrorElement = document.getElementById('gmailError');
  var gmailUsersList = document.getElementById('gmailUsersList');

  // Assignment label elements
  var addAssignmentLabelBtn = document.getElementById('addAssignmentLabel');
  var newAssignmentLabelInput = document.getElementById('newAssignmentLabel');
  var assignmentLabelErrorElement = document.getElementById('assignmentLabelError');
  var assignmentLabelsList = document.getElementById('assignmentLabelsList');

  // Add event listener for adding assignment labels
  if (addAssignmentLabelBtn && newAssignmentLabelInput) {
    addAssignmentLabelBtn.addEventListener('click', function() {
      addAssignmentLabel();
    });

    // Also add on Enter key press in the label input
    newAssignmentLabelInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        addAssignmentLabel();
      }
    });
  }

  // Add event listener for adding Gmail users
  if (addGmailUserBtn && newGmailUserInput) {
    addGmailUserBtn.addEventListener('click', function() {
      addGmailUser();
    });

    // Also add on Enter key press
    newGmailUserInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        // If there's text in the field, add the user (don't save)
        // If the field is empty, trigger Save Settings
        if (newGmailUserInput.value.trim()) {
          addGmailUser();
        } else {
          var saveBtn = document.getElementById('saveSettings');
          if (saveBtn) {
            saveBtn.click();
          }
        }
        return false;
      }
    });
  }

  // Fallback: delegated handler for Add Label button clicks
  // Ensures labels can be added even if the direct handler was not bound
  document.addEventListener('click', function(e) {
    if (e.target && e.target.id === 'addAssignmentLabel') {
      e.preventDefault();
      addAssignmentLabel();
    }
  });

  // Delegated handler for assignment dropdowns during printing
  document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('assignment-select')) {
      // Check if any case is currently being printed
      if (window.isPrintingCase) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    }
  });

  // Delegated handler for delete/archive buttons
  // Ensures delete buttons work even for dynamically created cards
  document.addEventListener('click', function(e) {
    var target = e.target;

    // Check if clicked element is a delete button or is inside one
    var deleteButton = target.closest('.card-delete-btn');
    if (deleteButton) {
      e.preventDefault();
      e.stopPropagation();

      // Check if any case is currently being printed
      if (window.isPrintingCase) {
        return;
      }

      // Find the case card
      var caseCard = deleteButton.closest('.kanban-card');
      if (!caseCard) return;

      // Get case data from the data attribute
      var rawData = caseCard.dataset.caseJson;
      var cardData;
      try {
        cardData = JSON.parse(rawData);
      } catch(e) {
        cardData = {};
      }

      // Show delete confirmation
      if (cardData.id) {
        showDeleteConfirmation(caseCard, cardData.patientFirstName + ' ' + cardData.patientLastName, function() {
          deleteCase(cardData.id, caseCard);
        });
      }
      return;
    }

    // Check if clicked element is an edit button or is inside one
    var editButton = target.closest('.kanban-card-edit');
    if (editButton) {
      e.preventDefault();
      e.stopPropagation();

      // Check if any case is currently being printed
      if (window.isPrintingCase) {
        return;
      }

      // Find the case card
      var caseCard = editButton.closest('.kanban-card');
      if (!caseCard) return;

      // Get case data from the data attribute
      var rawData = caseCard.dataset.caseJson;
      var cardData;
      try {
        cardData = JSON.parse(rawData);
      } catch(e) {
        cardData = {};
      }

      // Open the modal for editing
      editCaseHandler(cardData);
      return;
    }
  });

  // Function to add a Gmail user
  function addGmailUser() {
    if (!window.isPracticeAdmin) {
      return;
    }

    // Check current user count against max (controls should already be disabled, but double-check)
    var currentUserCount = 0;
    var seenEmails = {};
    if (Array.isArray(window.gmailUsers)) {
      window.gmailUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }
    if (Array.isArray(window.adminUsers)) {
      window.adminUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }

    var maxUsers = billingInfo && billingInfo.max_users ? billingInfo.max_users : 0;
    if (maxUsers > 0 && currentUserCount >= maxUsers) {
      gmailErrorElement.textContent = t('settings.users.practice_users.validation.limit_reached', { count: maxUsers });
      return;
    }

    var email = newGmailUserInput.value.trim();

    // Clear previous error
    gmailErrorElement.textContent = '';

    // Validate email
    if (!email) {
      gmailErrorElement.textContent = t('settings.users.practice_users.validation.email_required');
      return;
    }

    // Validate email format (basic check)
    if (!email.includes('@') || !email.includes('.')) {
      gmailErrorElement.textContent = t('settings.users.practice_users.validation.email_invalid');
      return;
    }

    // Check for duplicate
    if (window.gmailUsers.includes(email)) {
      gmailErrorElement.textContent = t('settings.users.practice_users.validation.duplicate');
      return;
    }

    // Check if user is already in the CURRENT practice (to prevent duplicates)
    // Note: Users CAN belong to multiple practices, so we only block if they're already in THIS practice
    checkUserPracticeStatus(email).then(response => {
      if (response.inCurrentPractice) {
        // User is already in the current practice
        gmailErrorElement.textContent = t('settings.users.practice_users.validation.already_member');
        return;
      }

      // User can be added (even if they're in other practices - multi-practice membership is allowed)
      window.gmailUsers.push(email);

      // Add to display
      displayGmailUsers();

      // Clear input
      newGmailUserInput.value = '';
    }).catch(error => {
      gmailErrorElement.textContent = t('settings.users.practice_users.validation.check_error', { message: error.message });
      // Error message displayed in UI
    });
  }

  // Function to check if a user is already in a practice
  function checkUserPracticeStatus(email) {
    return fetch('api/check-user-practice.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ email: email })
    })
    .then(response => response.json())
    .then(data => {
      if (!data.success) {
        throw new Error(data.message || 'Failed to check user status');
      }
      return data;
    });
  }

  // Function to display Gmail users (wrapper for combined grid)
  function displayGmailUsers() {
    displayPracticeUsers();
  }

  // Function to update add user controls based on current user count vs max
  function updateAddUserControls() {
    var addBtn = document.getElementById('addGmailUser');
    var inputField = document.getElementById('newGmailUser');
    var errorElement = document.getElementById('gmailError');

    if (!addBtn || !inputField) return;

    // Calculate current user count from in-memory arrays
    var currentUserCount = 0;
    var seenEmails = {};

    if (Array.isArray(window.gmailUsers)) {
      window.gmailUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }
    if (Array.isArray(window.adminUsers)) {
      window.adminUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }

    // Check if we're at or over the limit
    var maxUsers = billingInfo && billingInfo.max_users ? billingInfo.max_users : 0;
    var atLimit = maxUsers > 0 && currentUserCount >= maxUsers;

    if (atLimit) {
      addBtn.disabled = true;
      inputField.disabled = true;
      addBtn.style.opacity = '0.5';
      inputField.style.opacity = '0.5';
      if (errorElement) {
        errorElement.textContent = t('settings.users.practice_users.validation.limit_reached', { count: maxUsers });
      }
    } else {
      // Only enable if user is practice admin
      if (window.isPracticeAdmin) {
        addBtn.disabled = false;
        inputField.disabled = false;
        addBtn.style.opacity = '1';
        inputField.style.opacity = '1';
      }
      if (errorElement) {
        errorElement.textContent = '';
      }
    }
  }

  // Reusable, accessible info tooltip (mouse hover + keyboard focus).
  // Builds the .dt-tooltip markup used by css/settings-billing.css.
  // Purely presentational -- does not read or write any app state.
  var dtTooltipIdCounter = 0;
  function createInfoTooltip(text, alignRight) {
    dtTooltipIdCounter++;
    var tooltipId = 'dtTooltip' + dtTooltipIdCounter;

    var wrapper = document.createElement('span');
    wrapper.className = 'dt-tooltip' + (alignRight ? ' dt-tooltip-align-right' : '');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dt-tooltip-trigger';
    trigger.textContent = 'i';
    trigger.setAttribute('aria-describedby', tooltipId);
    trigger.setAttribute('aria-label', t('settings.common.more_info'));
    trigger.textContent = t('settings.common.info_trigger') || 'i';

    var bubble = document.createElement('span');
    bubble.className = 'dt-tooltip-bubble';
    bubble.setAttribute('role', 'tooltip');
    bubble.id = tooltipId;
    bubble.textContent = text;

    wrapper.appendChild(trigger);
    wrapper.appendChild(bubble);
    return wrapper;
  }
  // Exposed so independently-loaded scripts (e.g. js/lab-insights.js) can
  // reuse the exact same tooltip markup/style instead of duplicating it.
  window.createInfoTooltip = createInfoTooltip;

  // Escape dismisses an open tooltip by blurring its trigger.
  // Isolated listener -- does not interact with any other keyboard
  // handlers (e.g. the settings form's Enter-to-save handler).
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('dt-tooltip-trigger')) {
      document.activeElement.blur();
    }
  });

  // Combined practice users grid (admins + authorized users)
  function displayPracticeUsers() {
    // Always get fresh reference to the element
    var usersList = document.getElementById('gmailUsersList');

    if (!usersList) {
      return;
    }

    usersList.innerHTML = '';

    // Calculate current user count for warning display
    var currentUserCount = 0;
    var seenEmails = {};
    if (Array.isArray(window.gmailUsers)) {
      window.gmailUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }
    if (Array.isArray(window.adminUsers)) {
      window.adminUsers.forEach(function(email) {
        if (email && !seenEmails[email.toLowerCase()]) {
          seenEmails[email.toLowerCase()] = true;
          currentUserCount++;
        }
      });
    }

    var maxUsers = billingInfo && billingInfo.max_users ? billingInfo.max_users : 0;

    // Show warning if workspace exceeds user limit (e.g., after downgrading from Evaluate)
    // Only show if current count exceeds max (not just at max)
    if (maxUsers > 0 && currentUserCount > maxUsers) {
      var warningBanner = document.createElement('div');
      warningBanner.className = 'user-limit-warning';
      warningBanner.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>' +
        '<span>' + t('settings.users.practice_users.limit_warning', { count: currentUserCount }) + '</span>';
      usersList.appendChild(warningBanner);
    }

    // Update add user controls based on current count
    updateAddUserControls();

    var usersMap = {};

    if (Array.isArray(window.gmailUsers)) {
      window.gmailUsers.forEach(function(email) {
        if (!email) return;
        var lower = email.toLowerCase();
        if (!usersMap[lower]) {
          usersMap[lower] = { email: email, isAdmin: false };
        }
      });
    }

    if (Array.isArray(window.adminUsers)) {
      window.adminUsers.forEach(function(email) {
        if (!email) return;
        var lower = email.toLowerCase();
        if (!usersMap[lower]) {
          usersMap[lower] = { email: email, isAdmin: true };
        } else {
          usersMap[lower].isAdmin = true;
        }
      });
    }

    var keys = Object.keys(usersMap);
    if (!keys.length) {
      return;
    }

    keys.sort();

    // Header row
    var headerRow = document.createElement('div');
    headerRow.className = 'gmail-user-item practice-user-header' + (window.showLabInsights ? ' has-lab-column' : '');

    var emailHeader = document.createElement('div');
    emailHeader.className = 'gmail-user-email';
    emailHeader.textContent = t('settings.users.practice_users.user');

    var adminHeader = document.createElement('div');
    adminHeader.className = 'practice-user-admin-header';
    adminHeader.appendChild(document.createTextNode(t('settings.users.practice_users.admin')));
    adminHeader.appendChild(createInfoTooltip(t('settings.users.practice_users.admin_tooltip')));

    var analyticsHeader = document.createElement('div');
    analyticsHeader.className = 'practice-user-analytics-header';
    analyticsHeader.appendChild(document.createTextNode(t('settings.users.practice_users.insights')));
    analyticsHeader.appendChild(createInfoTooltip(t('settings.users.practice_users.insights_tooltip')));

    var limitedHeader = document.createElement('div');
    limitedHeader.className = 'practice-user-limited-header';
    limitedHeader.appendChild(document.createTextNode(t('settings.users.practice_users.assigned_only')));
    limitedHeader.appendChild(createInfoTooltip(t('settings.users.practice_users.assigned_only_tooltip'), true));

    var showLabInsights = !!window.showLabInsights;
    var labHeader = null;
    if (showLabInsights) {
      labHeader = document.createElement('div');
      labHeader.className = 'practice-user-lab-header';
      labHeader.appendChild(document.createTextNode(t('settings.users.practice_users.lab')));
      labHeader.appendChild(createInfoTooltip(t('settings.users.practice_users.lab_tooltip'), true));
    }

    var removeHeader = document.createElement('div');
    removeHeader.className = 'practice-user-remove-header';
    removeHeader.textContent = t('settings.users.practice_users.remove');

    headerRow.appendChild(emailHeader);
    headerRow.appendChild(adminHeader);
    headerRow.appendChild(analyticsHeader);
    headerRow.appendChild(limitedHeader);
    if (labHeader) {
      headerRow.appendChild(labHeader);
    }
    headerRow.appendChild(removeHeader);
    usersList.appendChild(headerRow);

    var normalizedCreator = (window.practiceCreatorEmail || '').toLowerCase();
    var normalizedCurrent = (currentUserEmail || '').toLowerCase();

    keys.forEach(function(key) {
      var user = usersMap[key];
      var email = user.email;
      var lower = email.toLowerCase();
      var isAdmin = !!user.isAdmin;
      var isCreator = (lower === normalizedCreator);
      var isCurrent = (lower === normalizedCurrent);

      var row = document.createElement('div');
      row.className = 'gmail-user-item practice-user-row' + (showLabInsights ? ' has-lab-column' : '');

      var infoWrapper = document.createElement('div');
      infoWrapper.className = 'gmail-user-info';

      var userEmail = document.createElement('div');
      userEmail.className = 'gmail-user-email';

      var emailTextSpan = document.createElement('span');
      emailTextSpan.className = 'user-email-text';
      emailTextSpan.textContent = email;
      emailTextSpan.title = email;
      userEmail.appendChild(emailTextSpan);

      if (isCurrent) {
        var youBadge = document.createElement('span');
        youBadge.className = 'admin-badge';
        youBadge.textContent = t('settings.users.practice_users.badge_you');
        userEmail.appendChild(youBadge);
      }

      if (isCreator) {
        var creatorBadge = document.createElement('span');
        creatorBadge.className = 'admin-badge';
        creatorBadge.textContent = t('settings.users.practice_users.badge_creator');
        userEmail.appendChild(creatorBadge);
      }

      infoWrapper.appendChild(userEmail);
      row.appendChild(infoWrapper);

      // Admin checkbox cell
      var adminCell = document.createElement('div');
      adminCell.className = 'practice-user-admin-cell';
      var adminCheckbox = document.createElement('input');
      adminCheckbox.type = 'checkbox';
      adminCheckbox.className = 'practice-user-admin-checkbox';
      adminCheckbox.checked = isAdmin;
      adminCheckbox.setAttribute('data-email', email);

      if (!window.isPracticeAdmin || isCreator) {
        adminCheckbox.disabled = true;
      } else {
        // Check if user is Limited - if so, disable Admin checkbox
        var isLimited = !!(window.limitedVisibilityUsers && window.limitedVisibilityUsers[email]);
        if (isLimited) {
          adminCheckbox.disabled = true;
          adminCheckbox.checked = false;
        } else {
          adminCheckbox.addEventListener('change', function() {
            var targetEmail = this.getAttribute('data-email');
            var makeAdmin = !!this.checked;
            setAdminFlagForEmail(targetEmail, makeAdmin);
          });
        }
      }

      adminCell.appendChild(adminCheckbox);
      row.appendChild(adminCell);

      // Analytics checkbox cell
      var analyticsCell = document.createElement('div');
      analyticsCell.className = 'practice-user-analytics-cell';
      var analyticsCheckbox = document.createElement('input');
      analyticsCheckbox.type = 'checkbox';
      // Default to true if not set
      var canViewAnalytics = window.canViewAnalyticsUsers && window.canViewAnalyticsUsers[email] !== undefined
        ? window.canViewAnalyticsUsers[email] : true;
      analyticsCheckbox.checked = canViewAnalytics;
      analyticsCheckbox.setAttribute('data-email', email);

      if (!window.isPracticeAdmin || isCreator) {
        analyticsCheckbox.disabled = true;
      } else {
        analyticsCheckbox.addEventListener('change', function() {
          var targetEmail = this.getAttribute('data-email');
          var canView = !!this.checked;
          setCanViewAnalyticsForEmail(targetEmail, canView);
        });
      }

      analyticsCell.appendChild(analyticsCheckbox);
      row.appendChild(analyticsCell);

      // Limited Visibility checkbox cell
      var limitedCell = document.createElement('div');
      limitedCell.className = 'practice-user-limited-cell';
      var limitedCheckbox = document.createElement('input');
      limitedCheckbox.type = 'checkbox';
      limitedCheckbox.checked = !!(window.limitedVisibilityUsers && window.limitedVisibilityUsers[email]);
      limitedCheckbox.setAttribute('data-email', email);

      if (!window.isPracticeAdmin || isCreator) {
        limitedCheckbox.disabled = true;
      } else {
        limitedCheckbox.addEventListener('change', function() {
          var targetEmail = this.getAttribute('data-email');
          var isLimited = !!this.checked;
          setLimitedVisibilityForEmail(targetEmail, isLimited);
        });
      }

      limitedCell.appendChild(limitedCheckbox);
      row.appendChild(limitedCell);

      // Lab checkbox cell - only rendered while SHOW_LAB_INSIGHTS is enabled.
      if (showLabInsights) {
        var labCell = document.createElement('div');
        labCell.className = 'practice-user-lab-cell';
        var labCheckbox = document.createElement('input');
        labCheckbox.type = 'checkbox';
        labCheckbox.checked = !!(window.isLabUsers && window.isLabUsers[email]);
        labCheckbox.setAttribute('data-email', email);

        if (!window.isPracticeAdmin || isCreator) {
          labCheckbox.disabled = true;
        } else {
          labCheckbox.addEventListener('change', function() {
            var targetEmail = this.getAttribute('data-email');
            var isLab = !!this.checked;
            setIsLabForEmail(targetEmail, isLab);
          });
        }

        labCell.appendChild(labCheckbox);
        row.appendChild(labCell);
      }

      // Remove cell
      var removeCell = document.createElement('div');
      removeCell.className = 'practice-user-remove-cell';

      var canRemove = window.isPracticeAdmin && !isCreator && !isCurrent;
      if (canRemove) {
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-gmail-user';
        removeBtn.innerHTML = '&times;';
        removeBtn.setAttribute('data-email', email);
        removeBtn.addEventListener('click', function() {
          var emailToRemove = this.getAttribute('data-email');
          removePracticeUser(emailToRemove);
        });
        removeCell.appendChild(removeBtn);
      }

      row.appendChild(removeCell);
      usersList.appendChild(row);
    });
  }

  function setAdminFlagForEmail(email, makeAdmin) {
    if (!email) return;
    if (!window.adminUsers) window.adminUsers = [];
    if (!window.gmailUsers) window.gmailUsers = [];

    var lower = email.toLowerCase();
    var idxAdmin = -1;
    var idxUser = -1;

    window.adminUsers.forEach(function(e, i) {
      if (typeof e === 'string' && e.toLowerCase() === lower) idxAdmin = i;
    });
    window.gmailUsers.forEach(function(e, i) {
      if (typeof e === 'string' && e.toLowerCase() === lower) idxUser = i;
    });

    if (makeAdmin) {
      if (idxAdmin === -1) {
        window.adminUsers.push(email);
      }
      if (idxUser !== -1) {
        window.gmailUsers.splice(idxUser, 1);
      }
    } else {
      if (idxUser === -1) {
        window.gmailUsers.push(email);
      }
      if (idxAdmin !== -1) {
        window.adminUsers.splice(idxAdmin, 1);
      }
    }

    displayPracticeUsers();
  }

  // Function to set limited visibility flag for a user
  function setLimitedVisibilityForEmail(email, isLimited) {
    if (!email) return;
    if (!window.limitedVisibilityUsers) window.limitedVisibilityUsers = {};

    window.limitedVisibilityUsers[email] = isLimited;

    // If user is set to Limited, uncheck and disable the Admin checkbox
    var adminCheckbox = document.querySelector('input[data-email="' + email + '"][type="checkbox"].practice-user-admin-checkbox');
    if (adminCheckbox) {
      if (isLimited) {
        adminCheckbox.checked = false;
        adminCheckbox.disabled = true;
        // Also remove the user from the admin users array (it's an array of
        // emails, not a map, so find the matching entry and splice it out),
        // and ensure they remain tracked as a regular user so save-settings
        // still persists their practice membership (every user must be in
        // exactly one of adminUsers / gmailUsers, same invariant maintained
        // by setAdminFlagForEmail()).
        if (!window.adminUsers) window.adminUsers = [];
        if (!window.gmailUsers) window.gmailUsers = [];
        var lowerEmail = email.toLowerCase();
        var idxAdmin = -1;
        var idxUser = -1;
        window.adminUsers.forEach(function(e, i) {
          if (typeof e === 'string' && e.toLowerCase() === lowerEmail) idxAdmin = i;
        });
        window.gmailUsers.forEach(function(e, i) {
          if (typeof e === 'string' && e.toLowerCase() === lowerEmail) idxUser = i;
        });
        if (idxAdmin !== -1) {
          window.adminUsers.splice(idxAdmin, 1);
        }
        if (idxUser === -1) {
          window.gmailUsers.push(email);
        }
      } else {
        // Re-enable the Admin checkbox if user is not a creator and current user is practice admin
        var isCreator = window.practiceCreatorEmail === email.toLowerCase();
        if (!isCreator && window.isPracticeAdmin) {
          adminCheckbox.disabled = false;
        }
      }
    }
  }

  // Function to set can view analytics flag for a user
  function setCanViewAnalyticsForEmail(email, canView) {
    if (!email) return;
    if (!window.canViewAnalyticsUsers) window.canViewAnalyticsUsers = {};

    window.canViewAnalyticsUsers[email] = canView;
  }

  // Function to set can edit cases flag for a user
  function setCanEditCasesForEmail(email, canEdit) {
    if (!email) return;
    if (!window.canEditCasesUsers) window.canEditCasesUsers = {};

    window.canEditCasesUsers[email] = canEdit;
  }

  // Function to set the Lab designation flag for a user
  function setIsLabForEmail(email, isLab) {
    if (!email) return;
    if (!window.isLabUsers) window.isLabUsers = {};

    window.isLabUsers[email] = isLab;
  }

  // Function to remove a Gmail user
  function removeGmailUser(email) {
    removePracticeUser(email);
  }

  function removePracticeUser(email) {
    if (!window.isPracticeAdmin || !email) {
      return;
    }

    var lower = email.toLowerCase();
    var normalizedCreator = (window.practiceCreatorEmail || '').toLowerCase();
    var normalizedCurrent = (currentUserEmail || '').toLowerCase();

    if (lower === normalizedCreator || lower === normalizedCurrent) {
      return;
    }

    if (window.gmailUsers && window.gmailUsers.length) {
      window.gmailUsers = window.gmailUsers.filter(function(e) {
        return typeof e !== 'string' || e.toLowerCase() !== lower;
      });
    }

    if (window.adminUsers && window.adminUsers.length) {
      window.adminUsers = window.adminUsers.filter(function(e) {
        return typeof e !== 'string' || e.toLowerCase() !== lower;
      });
    }

    if (window.gmailUserLogins && Object.prototype.hasOwnProperty.call(window.gmailUserLogins, email)) {
      delete window.gmailUserLogins[email];
    }

    displayPracticeUsers();
  }

  // Admin user management (kept for API compatibility; uses shared grid)
  var addAdminUserBtn = null;
  var newAdminUserInput = null;
  var adminErrorElement = null;
  var adminUsersList = null;

  function addAdminUser() {
    // No-op; admin status is controlled via the grid checkboxes
  }

  function displayAdminUsers() {
    displayPracticeUsers();
  }

  function removeAdminUser(email) {
    removePracticeUser(email);
  }

  // Add functionality for the Save Settings button
  var saveSettingsBtn = document.getElementById('saveSettings');
  var settingsForm = document.getElementById('settingsForm');

  function saveSettings() {
      // Auto-add any pending email in the user input field before saving
      var pendingEmailInput = document.getElementById('newGmailUser');
      if (pendingEmailInput && pendingEmailInput.value.trim()) {
        var pendingEmail = pendingEmailInput.value.trim();
        // Basic validation
        if (pendingEmail.includes('@') && pendingEmail.includes('.') && !window.gmailUsers.includes(pendingEmail)) {
          window.gmailUsers.push(pendingEmail);
          pendingEmailInput.value = '';
          displayGmailUsers();
        }
      }

      // Get theme value from dropdown
      var themeDropdown = document.getElementById('theme');
      var theme = themeDropdown ? themeDropdown.value : 'light';

      // Get checkbox values
      var allowCardDelete = document.getElementById('allowCardDelete').checked;
      var highlightPastDue = document.getElementById('highlightPastDue').checked;
      var pastDueDays = document.getElementById('pastDueDays').value;
      var highlightComingDue = document.getElementById('highlightComingDue').checked;
      var comingDueDaysInput = document.getElementById('comingDueDays');
      var comingDueDays = comingDueDaysInput ? parseInt(comingDueDaysInput.value || '5', 10) : 5;
      var highlightAppointmentRisk = document.getElementById('highlightAppointmentRisk').checked;
      var appointmentRiskDaysInput = document.getElementById('appointmentRiskDays');
      var appointmentRiskDays = appointmentRiskDaysInput ? parseInt(appointmentRiskDaysInput.value || '3', 10) : 3;
      var googleDriveBackupCheckbox = document.getElementById('googleDriveBackup');
      var googleDriveBackup = googleDriveBackupCheckbox ? googleDriveBackupCheckbox.checked : false;

      // Practice default language (only when the language controls are visible)
      var practiceDefaultLanguageSelect = document.getElementById('practiceDefaultLanguage');
      var practiceDefaultLanguage = practiceDefaultLanguageSelect ? practiceDefaultLanguageSelect.value : null;

      // Delivered hide days (0 = show all)
      var deliveredHideDaysInput = document.getElementById('deliveredHideDays');
      var deliveredHideDays = deliveredHideDaysInput ? parseInt(deliveredHideDaysInput.value || '0', 10) : 0;

      // Practice settings - use displayName (editable) instead of practiceName
      var displayNameInput = document.getElementById('displayName');
      var displayName = displayNameInput ? displayNameInput.value.trim() : '';

      // Legacy fallback to practiceName if displayName doesn't exist
      var practiceNameInput = document.getElementById('practiceName');
      var practiceName = practiceNameInput ? practiceNameInput.value.trim() : '';

      // Practice logo settings
      var logoPathToSave = window.pendingLogoPath || window.currentLogoPath || '';

      // Workflow Stage Names - keyed by the six fixed internal statuses
      // (never by display text). Server-side normalizeWorkflowStageLabelsForSave()
      // is authoritative for trimming/validating/dropping blanks - this is
      // just the raw current input values.
      var workflowStageLabels = {};
      document.querySelectorAll('.workflow-stage-label-input').forEach(function(input) {
        if (input.dataset.internalStatus) {
          workflowStageLabels[input.dataset.internalStatus] = input.value;
        }
      });

      // Compile form data including Admin users, Gmail users, practice name, and logo
      var formData = {
        theme: theme,
        allowCardDelete: allowCardDelete,
        highlightPastDue: highlightPastDue,
        pastDueDays: pastDueDays,
        highlightComingDue: highlightComingDue,
        comingDueDays: comingDueDays,
        highlightAppointmentRisk: highlightAppointmentRisk,
        appointmentRiskDays: appointmentRiskDays,
        deliveredHideDays: deliveredHideDays,
        googleDriveBackup: googleDriveBackup,
        displayName: displayName, // New: editable display name
        practiceName: practiceName, // Legacy: kept for backwards compatibility
        logoPath: logoPathToSave,
        adminUsers: window.adminUsers, // Add the Admin users array
        gmailUsers: window.gmailUsers, // Add the Gmail users array
        assignmentLabels: window.assignmentLabels, // Legacy string array - kept for backward compatibility
        assignmentLabelsDetailed: (window.assignmentLabelsMeta || []).map(function(m, idx) {
          return {
            id: m.id,
            label: (window.assignmentLabels && window.assignmentLabels[idx] !== undefined) ? window.assignmentLabels[idx] : m.label,
            isLab: !!m.isLab,
            recipients: Array.isArray(m.recipients) ? m.recipients : []
          };
        }), // Stable-ID payload: preserves label identity through renames
        limitedVisibilityUsers: window.limitedVisibilityUsers || {}, // Add limited visibility map
        canViewAnalyticsUsers: window.canViewAnalyticsUsers || {}, // Add analytics permission map
        canEditCasesUsers: window.canEditCasesUsers || {}, // Add edit cases permission map
        isLabUsers: window.isLabUsers || {}, // Add Lab designation map (Lab Insights foundation)
        workflowStageLabels: workflowStageLabels // Practice-specific stage display-label overrides
      };

      if (practiceDefaultLanguage) {
        formData.practiceDefaultLanguage = practiceDefaultLanguage;
      }

      // Include logo action so the server can handle removals/updates
      if (window.logoMarkedForRemoval) {
        formData.logoAction = 'remove';
      } else if (window.pendingLogoPath && window.pendingLogoPath !== window.currentLogoPath) {
        formData.logoAction = 'update';
      } else {
        formData.logoAction = 'none';
      }

      // Prepare to save settings

      // Send data to server
      saveSettingsToServer(formData);
  }

  if (saveSettingsBtn) {
    saveSettingsBtn.addEventListener('click', saveSettings);
  }

  // Restore Defaults (Workflow Stage Names) - resets the six visible
  // inputs to their default stage names (read from each row's own fixed
  // left-hand label, so there is no second hardcoded six-item list on the
  // client). This is a CLIENT-SIDE FIELD RESET ONLY: it does not call any
  // API and does not save - hasUnsavedSettingsChanges() picks up the
  // change live (it compares current input values against the snapshot
  // taken when Settings last loaded), so the existing Save/Cancel and
  // close-with-unsaved-changes flows apply exactly as they would for any
  // other manually-edited field.
  var restoreWorkflowStageDefaultsBtn = document.getElementById('restoreWorkflowStageDefaultsBtn');
  if (restoreWorkflowStageDefaultsBtn) {
    restoreWorkflowStageDefaultsBtn.addEventListener('click', function() {
      document.querySelectorAll('.workflow-stage-label-input').forEach(function(input) {
        var labelEl = document.querySelector('label[for="' + input.id + '"]');
        input.value = labelEl ? labelEl.textContent.trim() : (input.dataset.internalStatus || '');
      });
    });
  }

  // Add Enter key handler for settings form
  if (settingsForm) {
    settingsForm.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        saveSettings();
      }
    });
  }

  // Function to apply settings immediately without page refresh
  function applySettingsImmediately(formData) {
    // Apply card delete visibility
    var allowCardDelete = formData.allowCardDelete;
    localStorage.setItem('allow_card_delete', allowCardDelete ? 'true' : 'false');

    // Update delete button visibility on all cards
    var mainContainer = document.querySelector('.main-container');
    if (mainContainer) {
      if (allowCardDelete) {
        mainContainer.classList.add('allow-card-delete');
      } else {
        mainContainer.classList.remove('allow-card-delete');
      }
    }

    var cardContainer = document.querySelector('.kanban-board');
    if (cardContainer) {
      if (allowCardDelete) {
        cardContainer.classList.add('allow-card-delete');
      } else {
        cardContainer.classList.remove('allow-card-delete');
      }
    }

    var dashboard = document.querySelector('.dashboard');
    if (dashboard) {
      if (allowCardDelete) {
        dashboard.classList.add('allow-card-delete');
      } else {
        dashboard.classList.remove('allow-card-delete');
      }
    }

    // Apply theme immediately
    if (formData.theme) {
      document.documentElement.setAttribute('data-theme', formData.theme);
    }

    // Update practice name in header immediately (prefer displayName over legacy practiceName)
    var nameToDisplay = formData.displayName || formData.practiceName;
    if (nameToDisplay) {
      var practiceNameElement = document.querySelector('.practice-name');
      if (practiceNameElement) {
        practiceNameElement.textContent = nameToDisplay;
      }
    }

    // Apply past due highlighting
    if (formData.highlightPastDue !== undefined) {
      localStorage.setItem('highlight_past_due', formData.highlightPastDue ? 'true' : 'false');
      localStorage.setItem('past_due_days', formData.pastDueDays.toString());
    }

    // Apply coming-due highlighting
    if (formData.highlightComingDue !== undefined) {
      localStorage.setItem('highlight_coming_due', formData.highlightComingDue ? 'true' : 'false');
      localStorage.setItem('coming_due_days', formData.comingDueDays.toString());
    }

    // Apply appointment-risk highlighting
    if (formData.highlightAppointmentRisk !== undefined) {
      localStorage.setItem('highlight_appointment_risk', formData.highlightAppointmentRisk ? 'true' : 'false');
      localStorage.setItem('appointment_risk_days', formData.appointmentRiskDays.toString());
    }

    // Trigger card highlighting update if the function exists (handles both past-due and coming-due)
    if (typeof updatePastDueHighlighting === 'function') {
      updatePastDueHighlighting();
    }

    // Store delivered hide days in localStorage for client awareness (even though filtering is server-side)
    if (typeof formData.deliveredHideDays !== 'undefined') {
      localStorage.setItem('delivered_hide_days', String(formData.deliveredHideDays));
    }

    // Apply logo changes immediately based on committed values
    if (formData.logoAction === 'remove') {
      window.currentLogoPath = '';
      window.pendingLogoPath = '';
      window.logoMarkedForRemoval = false;
      updateLogoDisplay('');
    } else if (formData.logoAction === 'update' && formData.logoPath) {
      window.currentLogoPath = formData.logoPath;
      window.pendingLogoPath = '';
      window.logoMarkedForRemoval = false;
      updateLogoDisplay(window.currentLogoPath);
    }

    if (typeof initializeAssignmentDropdown === 'function') {
      var assignmentSelects = document.querySelectorAll('.assignment-select');
      if (assignmentSelects && assignmentSelects.length > 0) {
        assignmentSelects.forEach(function(selectEl) {
          var caseId = selectEl.getAttribute('data-case-id') || '';
          var currentAssignee = selectEl.value || '';
          initializeAssignmentDropdown(selectEl, caseId, currentAssignee);
        });
      }
    }

    var settingsUpdatedEvent = new CustomEvent('settingsUpdated', { detail: { formData: formData } });
    window.dispatchEvent(settingsUpdatedEvent);
  }

  // Function to save settings to the server
  function saveSettingsToServer(formData) {
    // Show loading state
    var saveSettingsBtn = document.getElementById('saveSettings');
    var originalText = saveSettingsBtn.textContent;
    saveSettingsBtn.textContent = t('settings.common.saving');
    saveSettingsBtn.disabled = true;

    fetch('api/save-settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Settings saved successfully

        // Apply settings immediately
        applySettingsImmediately(formData);

        // Refresh assignmentLabelsMeta from the post-commit server state so
        // newly-added labels pick up their real database id right away.
        // Without this, a second save (without reopening Settings) would
        // still submit id: null for a label that already exists in the DB,
        // causing the server to insert a duplicate instead of updating it.
        // window.assignmentLabels is rebuilt in the same order/text so both
        // arrays stay index-aligned.
        if (data.assignmentLabelsDetailed) {
          window.assignmentLabels = data.assignmentLabelsDetailed.map(function(item) {
            return item.label;
          });
          window.assignmentLabelsMeta = data.assignmentLabelsDetailed.map(function(item) {
            return { id: item.id, label: item.label, isLab: !!item.isLab, recipients: item.recipients || [] };
          });
          displayAssignmentLabels();
        }

        // Refresh window.workflowStageLabels from the post-commit,
        // server-normalized (trimmed/validated) values, then immediately
        // re-render the Settings inputs, Kanban headers, and status
        // dropdown - no reload, no logout/practice-switch required.
        if (data.workflowStageLabels) {
          window.workflowStageLabels = data.workflowStageLabels;
          if (typeof renderWorkflowStageLabels === 'function') {
            renderWorkflowStageLabels();
          }
        }

        // Reset button state
        saveSettingsBtn.textContent = originalText;
        saveSettingsBtn.disabled = false;

        // Close the settings modal (force close since we just saved)
        closeSettingsBillingModal(true);

        // Show success toast
        if (typeof Toast !== 'undefined') {
          Toast.success(t('settings.messages.save_success_title'), t('settings.messages.save_success_message'));
        }
      } else {
        // Reset button state
        saveSettingsBtn.textContent = originalText;
        saveSettingsBtn.disabled = false;

        // Show the server's specific validation message when available
        // (e.g. "Cannot delete label(s) still in use...") rather than a
        // generic failure message.
        showToast(data.message || t('settings.messages.save_error'), 'error');

        // A stale client (missing the stable-ID label payload while Lab
        // Insights is enabled) was rejected to avoid corrupting Lab
        // identity - refresh in-memory settings so assignmentLabelsMeta
        // picks up current ids/isLab values instead of retrying blind.
        if (data.reload_required) {
          loadSettings();
        }
      }
    })
  }

  // Toggle visibility of past due days input based on checkbox
  var highlightPastDueCheckbox = document.getElementById('highlightPastDue');
  var pastDueSettings = document.getElementById('pastDueSettings');

  if (highlightPastDueCheckbox && pastDueSettings) {
    highlightPastDueCheckbox.addEventListener('change', function() {
      pastDueSettings.classList.toggle('hidden', !this.checked);
    });
  }

  // Toggle visibility of coming due days input based on checkbox
  var highlightComingDueCheckbox = document.getElementById('highlightComingDue');
  var comingDueSettings = document.getElementById('comingDueSettings');

  if (highlightComingDueCheckbox && comingDueSettings) {
    highlightComingDueCheckbox.addEventListener('change', function() {
      comingDueSettings.classList.toggle('hidden', !this.checked);
    });
  }

  // Toggle visibility of appointment risk days input based on checkbox
  var highlightAppointmentRiskCheckbox = document.getElementById('highlightAppointmentRisk');
  var appointmentRiskSettings = document.getElementById('appointmentRiskSettings');

  if (highlightAppointmentRiskCheckbox && appointmentRiskSettings) {
    highlightAppointmentRiskCheckbox.addEventListener('change', function() {
      appointmentRiskSettings.classList.toggle('hidden', !this.checked);
    });
  }

  // Add event handlers for billing section buttons
  document.addEventListener('DOMContentLoaded', function() {
    // Update payment method button
    const updatePaymentBtn = document.querySelector('.payment-methods .btn-outline');
    if (updatePaymentBtn) {
      updatePaymentBtn.addEventListener('click', function() {
        // Close the modal
        closeSettingsBillingModal(true);

        // Show a toast notification
        if (typeof Toast !== 'undefined') {
          Toast.info(t('billing.payment_method'), t('billing.messages.payment_update_soon'));
        }
      });
    }

    // Change plan button
    const changePlanBtn = document.querySelector('.billing-actions .btn-primary');
    if (changePlanBtn) {
      changePlanBtn.addEventListener('click', function() {
        // Close the modal
        closeSettingsBillingModal(true);

        // Show a toast notification
        if (typeof Toast !== 'undefined') {
          Toast.success(t('billing.messages.change_plan_title'), t('billing.messages.plan_updated'));
        }
      });
    }

    // Billing history button
    const billingHistoryBtn = document.querySelector('.billing-actions .btn-outline');
    if (billingHistoryBtn) {
      billingHistoryBtn.addEventListener('click', function() {
        // Close the modal
        closeSettingsBillingModal(true);

        // Show a toast notification
        if (typeof Toast !== 'undefined') {
          Toast.info(t('billing.messages.billing_history_title'), t('billing.messages.billing_history_soon'));
        }
      });
    }
  });

  // Validate the past due days input to ensure it's within range (1-99)
  var pastDueDaysInput = document.getElementById('pastDueDays');
  if (pastDueDaysInput) {
    pastDueDaysInput.addEventListener('input', function() {
      var value = parseInt(this.value, 10);

      // Remove non-numeric characters
      if (isNaN(value)) {
        this.value = '';
        return;
      }

      // Enforce the 1-99 range
      if (value < 1) this.value = '1';
      if (value > 99) this.value = '99';
    });
  }

  // Validate the coming due days input to ensure it's within range (1-99)
  var comingDueDaysInput = document.getElementById('comingDueDays');
  if (comingDueDaysInput) {
    comingDueDaysInput.addEventListener('input', function() {
      var value = parseInt(this.value, 10);

      // Remove non-numeric characters
      if (isNaN(value)) {
        this.value = '';
        return;
      }

      // Enforce the 1-99 range
      if (value < 1) this.value = '1';
      if (value > 99) this.value = '99';
    });
  }

  // Create Case modal functionality
  var createBtn = document.querySelector('.create-case-button');
  var createCaseModal = document.getElementById('createCaseModal');
  var closeBtn = document.getElementById('createCaseClose');
  var cancelBtn = document.getElementById('createCaseCancel');
  var submitBtn = document.getElementById('createCaseSubmit');

  var caseModalTabs = document.getElementById('caseViewTabs');
  var caseDetailsTab = document.querySelector('.case-tab[data-tab="details"]');
  var caseCommentsTab = document.querySelector('.case-tab[data-tab="comments"]');
  var caseHistoryTab = document.querySelector('.case-tab[data-tab="history"]');
  var caseCommentsPanel = document.getElementById('caseCommentsPanel');
  var caseHistoryPanel = document.getElementById('caseRevisionHistoryPanel');
  var caseHistoryContainer = document.getElementById('caseRevisionHistory');
  var createCaseForm = document.getElementById('createCaseForm');
  var caseViewLoading = document.getElementById('caseViewLoading');
  var caseViewError = document.getElementById('caseViewError');
  var caseViewRetry = document.getElementById('caseViewRetry');
  var caseViewErrorClose = document.getElementById('caseViewErrorClose');
  var currentEditCaseId = null;
  var pendingViewCaseId = null;
  var viewCaseTimings = null;
  window.viewCaseTimings = viewCaseTimings;

  function setCaseModalActiveTab(tabName) {
    if (tabName !== 'history' && tabName !== 'comments') {
      tabName = 'details';
    }

    if (caseDetailsTab) {
      caseDetailsTab.classList.toggle('case-tab-active', tabName === 'details');
    }
    if (caseCommentsTab) {
      caseCommentsTab.classList.toggle('case-tab-active', tabName === 'comments');
      caseCommentsTab.classList.toggle('case-tab-disabled', !currentEditCaseId);
    }
    if (caseHistoryTab) {
      caseHistoryTab.classList.toggle('case-tab-active', tabName === 'history');
      caseHistoryTab.classList.toggle('case-tab-disabled', !currentEditCaseId);
    }

    if (createCaseForm) {
      createCaseForm.classList.toggle('case-tab-panel-active', tabName === 'details');
    }
    if (caseCommentsPanel) {
      caseCommentsPanel.classList.toggle('case-tab-panel-active', tabName === 'comments');
    }
    if (caseHistoryPanel) {
      caseHistoryPanel.classList.toggle('case-tab-panel-active', tabName === 'history');
    }
  }

  function attachCaseModalTabHandlers() {
    if (caseDetailsTab) {
      caseDetailsTab.addEventListener('click', function() {
        setCaseModalActiveTab('details');
      });
    }
    if (caseCommentsTab) {
      caseCommentsTab.addEventListener('click', function() {
        // Only allow switching to comments when we have a real case selected
        if (caseCommentsTab.classList.contains('case-tab-disabled') || !currentEditCaseId) {
          return;
        }
        setCaseModalActiveTab('comments');
      });
    }
    if (caseHistoryTab) {
      caseHistoryTab.addEventListener('click', function() {
        // Only allow switching to history when we have a real case selected
        if (caseHistoryTab.classList.contains('case-tab-disabled') || !currentEditCaseId) {
          return;
        }
        setCaseModalActiveTab('history');
      });
    }
  }

  function renderCaseRevisionHistory(events) {
    if (!caseHistoryContainer) return;

    caseHistoryContainer.innerHTML = '';

    if (!events || !events.length) {
      var empty = document.createElement('p');
      empty.className = 'revision-empty-state';
      empty.textContent = t('cases.history.no_events');
      caseHistoryContainer.appendChild(empty);
      return;
    }

    var list = document.createElement('ul');
    list.className = 'revision-list';

    events.forEach(function(evt) {
      var item = document.createElement('li');
      item.className = 'revision-item';
      item.setAttribute('data-event-type', evt.event_type || 'unknown');

      var header = document.createElement('div');
      header.className = 'revision-header';

      if (evt.created_at) {
        var d = new Date(evt.created_at);
        if (!isNaN(d.getTime())) {
          // Format date more nicely
          var now = new Date();
          var diffMs = now - d;
          var diffMins = Math.floor(diffMs / 60000);
          var diffHours = Math.floor(diffMs / 3600000);
          var diffDays = Math.floor(diffMs / 86400000);

          var timeString;
          if (diffMins < 1) {
            timeString = t('cases.history.just_now');
          } else if (diffMins < 60) {
            timeString = I18n.pluralize(diffMins, 'common.relative.minutes_ago');
          } else if (diffHours < 24) {
            timeString = I18n.pluralize(diffHours, 'common.relative.hours_ago');
          } else if (diffDays < 7) {
            timeString = I18n.pluralize(diffDays, 'common.relative.days_ago');
          } else {
            timeString = I18n.formatDate(d, { style: 'medium', timeStyle: 'short' });
          }

          header.textContent = timeString;
        } else {
          header.textContent = evt.created_at;
        }
      }

      var body = document.createElement('div');
      body.className = 'revision-body';

      // Get user name for display
      var userName = evt.user_email ? evt.user_email.split('@')[0] : 'System';
      userName = userName.charAt(0).toUpperCase() + userName.slice(1);

      // Check if this is a revision/regression event (backward move)
      var isRevision = evt.event_type === 'case_revision' || evt.event_type === 'case_regression';
      if (isRevision) {
        item.classList.add('revision-highlight');
      }

      var description = '';
      switch (evt.event_type) {
        case 'case_created':
          description = 'Case created by ' + userName;
          break;
        case 'case_updated':
        case 'fields_updated':
          if (evt.meta && evt.meta.changed_fields && Array.isArray(evt.meta.changed_fields)) {
            var fieldMap = {
              'patientFirstName': t('cases.fields.patientFirstName'),
              'patientLastName': t('cases.fields.patientLastName'),
              'patientDOB': t('cases.fields.patientDOB'),
              'patientGender': t('cases.fields.patientGender'),
              'dentistName': t('cases.fields.dentistName'),
              'caseType': t('cases.fields.caseType'),
              'toothShade': t('cases.fields.toothShade'),
              'material': t('cases.fields.material'),
              'dueDate': t('cases.fields.dueDate'),
              'status': t('cases.fields.status'),
              'assignedTo': t('cases.fields.assignedTo'),
              'notes': t('cases.fields.notes'),
              'clinicalDetails': t('cases.fields.clinicalDetails')
            };
            // Check if we have old/new values for detailed display
            if (evt.meta.field_changes && typeof evt.meta.field_changes === 'object') {
              var changeDetails = [];
              Object.keys(evt.meta.field_changes).forEach(function(field) {
                var change = evt.meta.field_changes[field];
                var fieldName = fieldMap[field] || field.replace(/([A-Z])/g, ' $1').replace(/^./, function(str) { return str.toUpperCase(); });
                if (change.old && change.new) {
                  changeDetails.push(fieldName + ': "' + change.old + '" → "' + change.new + '"');
                } else if (change.new) {
                  changeDetails.push(fieldName + ' set to "' + change.new + '"');
                } else if (change.old) {
                  changeDetails.push(fieldName + ' cleared (was "' + change.old + '")');
                }
              });
              if (changeDetails.length > 0) {
                description = 'Updated by ' + userName + ': ' + changeDetails.join('; ');
              } else {
                var fieldNames = evt.meta.changed_fields.map(function(field) {
                  return fieldMap[field] || field.replace(/([A-Z])/g, ' $1').replace(/^./, function(str) { return str.toUpperCase(); });
                });
                description = 'Updated ' + fieldNames.join(', ') + ' by ' + userName;
              }
            } else {
              var fieldNames = evt.meta.changed_fields.map(function(field) {
                return fieldMap[field] || field.replace(/([A-Z])/g, ' $1').replace(/^./, function(str) { return str.toUpperCase(); });
              });
              description = 'Updated ' + fieldNames.join(', ') + ' by ' + userName;
            }
          } else {
            description = 'Case updated by ' + userName;
          }
          break;
        case 'status_changed':
          // Raw old_status/new_status remain stored/read as-is (the
          // internal status values); only this render-time sentence
          // resolves them to the practice's current display labels.
          var statusChangedOldLabel = evt.old_status ? getStageLabel(evt.old_status) : evt.old_status;
          var statusChangedNewLabel = evt.new_status ? getStageLabel(evt.new_status) : evt.new_status;
          if (statusChangedOldLabel && statusChangedNewLabel) {
            description = 'Changed status from ' + statusChangedOldLabel + ' to ' + statusChangedNewLabel + ' by ' + userName;
          } else if (statusChangedNewLabel) {
            description = 'Changed status to ' + statusChangedNewLabel + ' by ' + userName;
          } else {
            description = 'Status changed by ' + userName;
          }
          break;
        case 'case_revision':
        case 'case_regression':
          var revisionOldLabel = evt.old_status ? getStageLabel(evt.old_status) : evt.old_status;
          var revisionNewLabel = evt.new_status ? getStageLabel(evt.new_status) : evt.new_status;
          if (revisionOldLabel && revisionNewLabel) {
            description = 'Changed status from ' + revisionOldLabel + ' to ' + revisionNewLabel + ' (revision) by ' + userName;
          } else if (revisionNewLabel) {
            description = 'Changed status to ' + revisionNewLabel + ' (revision) by ' + userName;
          } else {
            description = 'Status changed (revision) by ' + userName;
          }
          break;
        case 'attachments_added':
          var fileCount = (evt.meta && evt.meta.count) || (evt.meta && evt.meta.attachment_count) || 1;
          if (evt.meta && evt.meta.file_names && Array.isArray(evt.meta.file_names)) {
            description = 'Added ' + fileCount + ' file' + (fileCount !== 1 ? 's' : '') + ': ' + evt.meta.file_names.join(', ') + ' by ' + userName;
          } else {
            description = 'Added ' + fileCount + ' file' + (fileCount !== 1 ? 's' : '') + ' by ' + userName;
          }
          break;
        case 'attachments_updated':
          description = 'Files updated by ' + userName;
          break;
        case 'attachments_deleted':
        case 'attachment_deleted':
          var deletedCount = (evt.meta && evt.meta.files_deleted) || 1;
          description = 'Deleted ' + deletedCount + ' file' + (deletedCount !== 1 ? 's' : '') + ' by ' + userName;
          break;
        case 'notes_updated':
          if (evt.meta && evt.meta.note_preview) {
            description = 'Added note by ' + userName + ': "' + evt.meta.note_preview + '"';
          } else if (evt.meta && evt.meta.notes_length) {
            description = 'Updated notes (' + evt.meta.notes_length + ' chars) by ' + userName;
          } else {
            description = 'Updated notes by ' + userName;
          }
          break;
        case 'assignment_set':
        case 'assignment_changed':
          if (evt.meta && evt.meta.old_assigned_to && evt.meta.assigned_to) {
            description = 'Reassigned from ' + evt.meta.old_assigned_to + ' to ' + evt.meta.assigned_to + ' by ' + userName;
          } else if (evt.meta && evt.meta.assigned_to) {
            description = 'Assigned to ' + evt.meta.assigned_to + ' by ' + userName;
          } else {
            description = 'Assignment updated by ' + userName;
          }
          break;
        case 'assignment_cleared':
          if (evt.meta && evt.meta.old_assigned_to) {
            description = 'Assignment cleared (was ' + evt.meta.old_assigned_to + ') by ' + userName;
          } else {
            description = 'Assignment cleared by ' + userName;
          }
          break;
        case 'labels_updated':
          if (evt.meta && evt.meta.labels_added && evt.meta.labels_added.length > 0) {
            description = 'Added label' + (evt.meta.labels_added.length > 1 ? 's' : '') + ': ' + evt.meta.labels_added.join(', ') + ' by ' + userName;
          } else if (evt.meta && evt.meta.labels_removed && evt.meta.labels_removed.length > 0) {
            description = 'Removed label' + (evt.meta.labels_removed.length > 1 ? 's' : '') + ': ' + evt.meta.labels_removed.join(', ') + ' by ' + userName;
          } else {
            description = 'Labels updated by ' + userName;
          }
          break;
        case 'due_date_changed':
          if (evt.meta && evt.meta.old_due_date && evt.meta.new_due_date) {
            description = 'Due date changed from ' + evt.meta.old_due_date + ' to ' + evt.meta.new_due_date + ' by ' + userName;
          } else if (evt.meta && evt.meta.new_due_date) {
            description = 'Due date set to ' + evt.meta.new_due_date + ' by ' + userName;
          } else {
            description = 'Due date changed by ' + userName;
          }
          break;
        case 'case_archived':
          description = 'Case archived by ' + userName;
          break;
        case 'case_archived_auto':
          description = 'Case automatically archived';
          break;
        case 'case_restored':
          description = 'Case restored by ' + userName;
          break;
        default:
          description = (evt.event_type || 'Activity').replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }) + ' by ' + userName;
          break;
      }

      body.textContent = description;

      item.appendChild(header);
      item.appendChild(body);
      list.appendChild(item);
    });

    caseHistoryContainer.appendChild(list);
  }

  function loadCaseRevisionHistory(caseId) {
    if (!caseHistoryContainer) return;
    currentEditCaseId = caseId || null;
    setCaseModalActiveTab('details');

    // Initialize comments for this case (only if feature flag enabled)
    if (window.featureFlags && window.featureFlags.SHOW_COMMENTS) {
      if (caseId && typeof window.initCaseComments === 'function') {
        window.initCaseComments(caseId);
      } else if (typeof window.clearCaseComments === 'function') {
        window.clearCaseComments();
      }
    }

    if (!caseId) {
      caseHistoryContainer.innerHTML = '';
      var empty = document.createElement('p');
      empty.className = 'revision-empty-state';
      empty.textContent = t('cases.history.no_events');
      caseHistoryContainer.appendChild(empty);
      return;
    }

    caseHistoryContainer.innerHTML = '';
    var loading = document.createElement('p');
    loading.className = 'revision-loading';
    loading.textContent = t('cases.history.loading');
    caseHistoryContainer.appendChild(loading);

    fetch('api/get-case-activity.php?caseId=' + encodeURIComponent(caseId), {
      credentials: 'same-origin'
    })
      .then(function(response) {
        return response.json();
      })
      .then(function(data) {
        if (!data || !data.success || !Array.isArray(data.events)) {
          caseHistoryContainer.innerHTML = '';
          var error = document.createElement('p');
          error.className = 'revision-error';
          error.textContent = t('cases.history.error');
          caseHistoryContainer.appendChild(error);
          return;
        }

        renderCaseRevisionHistory(data.events);
      })
      .catch(function() {
        caseHistoryContainer.innerHTML = '';
        var error = document.createElement('p');
        error.className = 'revision-error';
        error.textContent = t('cases.history.error');
        caseHistoryContainer.appendChild(error);
      });
  }

  attachCaseModalTabHandlers();

  // ============================================
  // DENTIST NAME AUTOCOMPLETE
  // Business Rule: Shows suggestions from previously used dentist names
  // scoped to the current practice, ordered by most recently used.
  // ============================================
  (function initDentistAutocomplete() {
    var dentistInput = document.getElementById('dentistName');
    var suggestionsDropdown = document.getElementById('dentistNameSuggestions');

    if (!dentistInput || !suggestionsDropdown) return;

    var debounceTimer = null;
    var highlightedIndex = -1;
    var currentSuggestions = [];

    // Fetch suggestions from API
    function fetchSuggestions(query) {
      if (!query || query.length < 1) {
        hideSuggestions();
        return;
      }

      fetch('api/get-dentist-suggestions.php?q=' + encodeURIComponent(query), {
        credentials: 'same-origin'
      })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data.success && data.suggestions && data.suggestions.length > 0) {
          showSuggestions(data.suggestions);
        } else {
          hideSuggestions();
        }
      })
      .catch(function() {
        hideSuggestions();
      });
    }

    // Display suggestions in dropdown
    function showSuggestions(suggestions) {
      currentSuggestions = suggestions;
      highlightedIndex = -1;
      suggestionsDropdown.innerHTML = '';

      suggestions.forEach(function(name, index) {
        var item = document.createElement('div');
        item.className = 'autocomplete-item';
        item.setAttribute('role', 'option');
        item.setAttribute('data-index', index);
        item.textContent = name;

        item.addEventListener('click', function() {
          selectSuggestion(name);
        });

        item.addEventListener('mouseenter', function() {
          highlightedIndex = index;
          updateHighlight();
        });

        suggestionsDropdown.appendChild(item);
      });

      suggestionsDropdown.classList.add('active');
    }

    // Hide suggestions dropdown
    function hideSuggestions() {
      suggestionsDropdown.classList.remove('active');
      suggestionsDropdown.innerHTML = '';
      currentSuggestions = [];
      highlightedIndex = -1;
    }

    // Select a suggestion
    function selectSuggestion(name) {
      dentistInput.value = name;
      hideSuggestions();
      dentistInput.focus();
    }

    // Update highlighted item
    function updateHighlight() {
      var items = suggestionsDropdown.querySelectorAll('.autocomplete-item');
      items.forEach(function(item, index) {
        item.classList.toggle('highlighted', index === highlightedIndex);
      });
    }

    // Input event handler with debounce
    dentistInput.addEventListener('input', function() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function() {
        fetchSuggestions(dentistInput.value.trim());
      }, 200);
    });

    // Keyboard navigation
    dentistInput.addEventListener('keydown', function(e) {
      if (!suggestionsDropdown.classList.contains('active')) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlightedIndex = Math.min(highlightedIndex + 1, currentSuggestions.length - 1);
        updateHighlight();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
        updateHighlight();
      } else if (e.key === 'Enter' && highlightedIndex >= 0) {
        e.preventDefault();
        selectSuggestion(currentSuggestions[highlightedIndex]);
      } else if (e.key === 'Escape') {
        hideSuggestions();
      }
    });

    // Hide on blur (with delay to allow click)
    dentistInput.addEventListener('blur', function() {
      setTimeout(hideSuggestions, 150);
    });

    // Show suggestions on focus if there's already text
    dentistInput.addEventListener('focus', function() {
      if (dentistInput.value.trim().length >= 1) {
        fetchSuggestions(dentistInput.value.trim());
      }
    });
  })();

  // ============================================
  // CASE NOTES CHARACTER LIMIT
  // Business Rule: Enforces 3,000 character limit on case notes.
  // Displays remaining character count with visual feedback.
  // ============================================
  (function initNotesCharacterCounter() {
    var notesTextarea = document.getElementById('notes');
    var charCounter = document.getElementById('notesCharCounter');

    if (!notesTextarea || !charCounter) return;

    var maxLength = 3000; // Character limit for case notes
    var warningThreshold = 2700; // Show warning at 90% capacity

    function updateCounter() {
      var currentLength = notesTextarea.value.length;
      var remaining = maxLength - currentLength;

      // Format number with comma separator
      var formattedCurrent = I18n.formatNumber(currentLength);
      var formattedMax = I18n.formatNumber(maxLength);

      charCounter.textContent = t('cases.comments.character_count', {current: formattedCurrent, max: formattedMax});

      // Update visual state
      charCounter.classList.remove('warning', 'error');
      if (currentLength >= maxLength) {
        charCounter.classList.add('error');
      } else if (currentLength >= warningThreshold) {
        charCounter.classList.add('warning');
      }
    }

    // Update on input
    notesTextarea.addEventListener('input', updateCounter);

    // Initialize counter on page load
    updateCounter();

    // Expose function to reset counter when form is reset
    window.resetNotesCharCounter = updateCounter;
  })();

  // ============================================
  // TOOTH NUMBER VALIDATION MODULE
  // Business Rule: For Crown case type, validates tooth number(s)
  // using standard dental numbering (1-32 for adult teeth).
  // Supports multiple formats: single (14), comma-separated (14, 30),
  // space-separated (14 30), ranges (14-18), or combinations (14-18, 30 31).
  // Rejects invalid values and displays inline error.
  // ============================================
  var toothNumberValidation = (function() {
    // Valid tooth number range for adult teeth (Universal Numbering System)
    var MIN_TOOTH_NUMBER = 1;
    var MAX_TOOTH_NUMBER = 32;

    /**
     * Parse and validate tooth number input supporting multiple formats
     * @param {string} value - Input string (e.g., "14", "14, 30", "14-18", "14-18, 30 31")
     * @returns {object} - { valid: boolean, error: string|null, numbers: number[], normalized: string }
     */
    function parseToothNumbers(value) {
      if (!value || value.trim() === '') {
        return { valid: false, error: t('cases.clinical.validation.tooth_number_required'), numbers: [], normalized: '' };
      }

      var trimmed = value.trim();
      var allNumbers = [];

      // Split by comma and/or whitespace (but not within ranges)
      // First, split by comma
      var commaParts = trimmed.split(',');

      for (var i = 0; i < commaParts.length; i++) {
        // Then split each comma part by whitespace
        var spaceParts = commaParts[i].trim().split(/\s+/);

        for (var j = 0; j < spaceParts.length; j++) {
          var part = spaceParts[j].trim();
          if (part === '') continue;

          // Check if it's a range (e.g., "14-18")
          if (part.indexOf('-') !== -1) {
            var rangeParts = part.split('-');

            // Validate range format
            if (rangeParts.length !== 2) {
              return { valid: false, error: t('cases.clinical.validation.tooth_number_invalid_range_format', {part: part}), numbers: [], normalized: '' };
            }

            var start = rangeParts[0].trim();
            var end = rangeParts[1].trim();

            // Validate both parts are numeric
            if (!/^\d+$/.test(start) || !/^\d+$/.test(end)) {
              return { valid: false, error: t('validation.tooth_numbers'), numbers: [], normalized: '' };
            }

            var startNum = parseInt(start, 10);
            var endNum = parseInt(end, 10);

            // Validate range bounds
            if (startNum < MIN_TOOTH_NUMBER || startNum > MAX_TOOTH_NUMBER) {
              return { valid: false, error: t('cases.clinical.validation.tooth_number_out_of_range', {number: startNum, min: MIN_TOOTH_NUMBER, max: MAX_TOOTH_NUMBER}), numbers: [], normalized: '' };
            }
            if (endNum < MIN_TOOTH_NUMBER || endNum > MAX_TOOTH_NUMBER) {
              return { valid: false, error: t('cases.clinical.validation.tooth_number_out_of_range', {number: endNum, min: MIN_TOOTH_NUMBER, max: MAX_TOOTH_NUMBER}), numbers: [], normalized: '' };
            }

            // Validate range direction
            if (startNum > endNum) {
              return { valid: false, error: t('cases.clinical.validation.tooth_number_invalid_range_order', {start: startNum, end: endNum}), numbers: [], normalized: '' };
            }

            // Expand range
            for (var n = startNum; n <= endNum; n++) {
              allNumbers.push(n);
            }
          } else {
            // Single number
            if (!/^\d+$/.test(part)) {
              return { valid: false, error: t('validation.tooth_numbers'), numbers: [], normalized: '' };
            }

            var num = parseInt(part, 10);

            if (num < MIN_TOOTH_NUMBER || num > MAX_TOOTH_NUMBER) {
              return { valid: false, error: t('cases.clinical.validation.tooth_number_out_of_range', {number: num, min: MIN_TOOTH_NUMBER, max: MAX_TOOTH_NUMBER}), numbers: [], normalized: '' };
            }

            allNumbers.push(num);
          }
        }
      }

      if (allNumbers.length === 0) {
        return { valid: false, error: t('cases.clinical.validation.tooth_number_required'), numbers: [], normalized: '' };
      }

      // Deduplicate and sort
      var uniqueNumbers = [];
      var seen = {};
      for (var k = 0; k < allNumbers.length; k++) {
        if (!seen[allNumbers[k]]) {
          seen[allNumbers[k]] = true;
          uniqueNumbers.push(allNumbers[k]);
        }
      }
      uniqueNumbers.sort(function(a, b) { return a - b; });

      // Create normalized string (comma-separated, sorted)
      var normalized = uniqueNumbers.join(', ');

      return { valid: true, error: null, numbers: uniqueNumbers, normalized: normalized };
    }

    /**
     * Validate a single tooth number (legacy function for backward compatibility)
     * @param {string} value - The tooth number to validate
     * @returns {object} - { valid: boolean, error: string|null }
     */
    function validateToothNumber(value) {
      // Use the new parser which handles all formats
      var result = parseToothNumbers(value);
      return { valid: result.valid, error: result.error };
    }

    /**
     * Validate multiple tooth numbers (comma-separated) - legacy function
     * @param {string} value - Comma-separated tooth numbers
     * @returns {object} - { valid: boolean, error: string|null, numbers: number[] }
     */
    function validateMultipleToothNumbers(value) {
      var result = parseToothNumbers(value);
      return { valid: result.valid, error: result.error, numbers: result.numbers };
    }

    /**
     * Show validation error on a field
     */
    function showFieldError(field, message) {
      field.classList.add('field-error');

      // Remove existing error message if any
      var existingError = field.parentNode.querySelector('.error-message');
      if (existingError) {
        existingError.remove();
      }

      var errorDiv = document.createElement('div');
      errorDiv.className = 'error-message';
      errorDiv.textContent = message;
      field.parentNode.insertBefore(errorDiv, field.nextSibling);
    }

    /**
     * Clear validation error from a field
     */
    function clearFieldError(field) {
      field.classList.remove('field-error');
      var existingError = field.parentNode.querySelector('.error-message');
      if (existingError) {
        existingError.remove();
      }
    }

    /**
     * Initialize tooth number validation for Crown case type
     */
    function init() {
      var toothNumberInput = document.getElementById('clinicalToothNumber');
      var caseTypeSelect = document.getElementById('caseType');

      if (!toothNumberInput) return;

      // Validate on blur
      toothNumberInput.addEventListener('blur', function() {
        var caseType = caseTypeSelect ? caseTypeSelect.value : '';

        // Only validate for Crown case type
        if (caseType !== 'Crown') {
          clearFieldError(toothNumberInput);
          return;
        }

        var value = toothNumberInput.value.trim();

        // Allow empty if not yet filled (required validation handles this)
        if (value === '') {
          clearFieldError(toothNumberInput);
          return;
        }

        var result = validateToothNumber(value);
        if (!result.valid) {
          showFieldError(toothNumberInput, result.error);
        } else {
          clearFieldError(toothNumberInput);
        }
      });

      // Clear error on input
      toothNumberInput.addEventListener('input', function() {
        clearFieldError(toothNumberInput);
      });
    }

    // Initialize when DOM is ready
    init();

    // Expose validation functions for use in form submission
    return {
      validateToothNumber: validateToothNumber,
      validateMultipleToothNumbers: validateMultipleToothNumbers,
      showFieldError: showFieldError,
      clearFieldError: clearFieldError
    };
  })();

  // Make validation available globally for form submission
  window.toothNumberValidation = toothNumberValidation;

  // Helper to reset the Create/Edit Case form back to "new case" state
  function resetCreateCaseFormToNew() {
    var form = document.getElementById('createCaseForm');
    var modalTitle = document.querySelector('.modal-title');
    var submitBtn = document.getElementById('createCaseSubmit');

    if (form) {
      if (typeof clearCreateCaseErrors === 'function') {
        clearCreateCaseErrors();
      }
      form.reset();
      delete form.dataset.caseId;
      delete form.dataset.driveFolderId;
      delete form.dataset.caseVersion;
      delete form.dataset.originalCaseData;
    }

    if (modalTitle) modalTitle.textContent = t('cases.create_new_case');
    if (submitBtn) submitBtn.textContent = t('cases.create_case');

    clearFileSelections();

    // Reset shipping link for new case
    updateTrackingNumberLink();

    // Hide activity timeline for new case
    if (typeof hideActivityTimeline === 'function') {
      hideActivityTimeline();
    }

    // Remove At Risk indicator for new case
    var atRiskIndicator = document.getElementById('caseDetailAtRisk');
    if (atRiskIndicator) {
      atRiskIndicator.remove();
    }

    // Remove revision indicator for new case
    var revisionIndicator = document.querySelector('.modal-header .case-detail-revision');
    if (revisionIndicator) {
      revisionIndicator.remove();
    }

    // Remove regression indicator for new case
    var regressionIndicator = document.querySelector('.modal-header .case-detail-regression');
    if (regressionIndicator) {
      regressionIndicator.remove();
    }

    // Clear clinical details fields for new case
    if (typeof clearClinicalDetailsFields === 'function') {
      clearClinicalDetailsFields();
    }
  }

  function openCreateCase() {
    if (createCaseModal) {
      // Do not open modal while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      // Check billing before allowing case creation
      if (!checkBillingForCaseCreation()) {
        return;
      }

      createCaseModal.style.display = 'block';
      document.body.style.overflow = 'hidden'; // Prevent scrolling behind modal
      resetCaseViewState();

      // Opening any case here (new or edit, via editCaseHandler()) always
      // establishes a normal, non-archive context - remove any leftover
      // "Back to Archived Cases" button so it can never carry over from a
      // previously viewed archived case (belt-and-suspenders alongside the
      // same removal in openCaseModalForView() and the cleanup in
      // closeCreateCase()).
      var existingBackBtnOnOpen = createCaseModal.querySelector('.back-to-archived');
      if (existingBackBtnOnOpen) {
        existingBackBtnOnOpen.remove();
      }

      // Determine if we're editing an existing case or creating a new one
      var form = document.getElementById('createCaseForm');
      var isUpdate = !!(form && form.dataset && form.dataset.caseId);

      // Show tabs only when editing an existing case
      if (caseModalTabs) {
        caseModalTabs.style.display = isUpdate ? 'flex' : 'none';
      }
      if (caseHistoryTab) {
        caseHistoryTab.classList.toggle('case-tab-disabled', !isUpdate);
      }

      // Always start on Details tab
      setCaseModalActiveTab('details');

      // Ensure any previous validation errors are cleared when opening the modal
      if (typeof clearCreateCaseErrors === 'function') {
        clearCreateCaseErrors();
      }

      // Start tracking form changes
      setTimeout(function() {
        trackFormChanges();
      }, 100);

      // Bind shipping-input listeners once so the tracking link stays in sync.
      var carrierInput = document.getElementById('carrier');
      var trackingInput = document.getElementById('trackingNumber');
      if (carrierInput && trackingInput && !carrierInput.dataset.shippingListenersBound) {
        carrierInput.addEventListener('change', function() {
          toggleCustomCarrierField();
          updateTrackingNumberLink();
        });
        trackingInput.addEventListener('input', updateTrackingNumberLink);
        carrierInput.dataset.shippingListenersBound = '1';
      }

      // Initialize assignment dropdown for a brand-new case only. When
      // editing an existing case, editCaseHandler() already scheduled its
      // own initializeAssignmentDropdown() call (with the real caseId and
      // stored assignee) before calling openCreateCase(). Since both calls
      // share the same 100ms delay, running this unconditionally would fire
      // *after* that one and clobber the just-selected assignment back to
      // "None" (caseId='', currentAssignee='') even though the data was
      // never lost - it's purely a dropdown re-initialization race. Archived
      // "View Case" doesn't go through openCreateCase() at all (see
      // openCaseModalForView()), which is why it was never affected.
      if (!isUpdate) {
        setTimeout(function() {
          var assignedToDropdown = document.getElementById('assignedTo');
          if (assignedToDropdown && typeof initializeAssignmentDropdown === 'function') {
            initializeAssignmentDropdown(assignedToDropdown, '', ''); // No caseId for new case, no current assignee
          }
        }, 100);
      }

      // Focus on the first input field
      setTimeout(function() {
        var firstInput = createCaseModal.querySelector('input:not([type="hidden"]):not([type="file"]):not([readonly])');
        if (firstInput) {
          firstInput.focus();
        }
      }, 150); // Small delay to ensure modal is fully displayed
    }
  }

  function closeCreateCase() {
    if (createCaseModal) {
      createCaseModal.style.display = 'none';
      document.body.style.overflow = ''; // Restore scrolling

      // Remove any "Back to Archived Cases" button left over from viewing
      // an archived case (see window.viewArchivedCase()). This is the
      // single point where the case modal's lifecycle genuinely ends, so
      // clearing it here - rather than in every possible "open" function -
      // guarantees it can never leak into the next case that's opened
      // (e.g. a normal board case). viewArchivedCase() re-adds it fresh
      // each time it's actually needed, so this has no effect on the
      // archived-case flow itself.
      var existingBackBtn = createCaseModal.querySelector('.back-to-archived');
      if (existingBackBtn) {
        existingBackBtn.remove();
      }

      // Reset modal state after viewing
      resetCreateCaseFormToNew();

      // Reset any view-only modifications
      var form = document.getElementById('createCaseForm');
      if (form) {
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
          input.removeAttribute('readonly');
          input.style.backgroundColor = '';
          input.style.cursor = '';
        });

        // Re-enable file inputs
        var fileInputs = form.querySelectorAll('input[type="file"]');
        fileInputs.forEach(function(input) {
          input.disabled = false;
          input.style.opacity = '';
        });

        // Show delete file buttons again
        var deleteButtons = form.querySelectorAll('.delete-file-btn');
        deleteButtons.forEach(function(btn) {
          btn.style.display = '';
        });
      }

      // Reset submit button
      var submitBtn = document.getElementById('createCaseSubmit');
      var cancelBtn = document.getElementById('createCaseCancel');
      if (submitBtn) {
        submitBtn.style.display = '';
      }
      if (cancelBtn) {
        cancelBtn.textContent = t('common.cancel');
      }

      // Reset unsaved changes tracking
      hasUnsavedChanges = false;
      originalFormData = null;

      // Reset submission state to allow new submissions
      isSubmitting = false;
    }
  }

  function populateCreateCaseForm(caseData) {
    var form = document.getElementById('createCaseForm');
    if (!form) return;

    // Set form data attribute for editing
    form.dataset.caseId = caseData.id || caseData.case_id;
    if (caseData.driveFolderId) {
      form.dataset.driveFolderId = caseData.driveFolderId;
    }

    // Populate basic fields - handle both camelCase and snake_case
    var patientFirstName = document.getElementById('patientFirstName');
    var patientLastName = document.getElementById('patientLastName');
    var patientDOB = document.getElementById('patientDOB');
    var patientGender = document.getElementById('patientGender');
    var dentistName = document.getElementById('dentistName');
    var caseType = document.getElementById('caseType');
    var toothShade = document.getElementById('toothShade');
    var material = document.getElementById('material');
    var dueDate = document.getElementById('dueDate');
    var patientAppointmentDate = document.getElementById('patientAppointmentDate');
    var status = document.getElementById('status');
    var assignedTo = document.getElementById('assignedTo');
    var notes = document.getElementById('notes');

    if (patientFirstName) patientFirstName.value = caseData.patientFirstName || caseData.patient_first_name || '';
    if (patientLastName) patientLastName.value = caseData.patientLastName || caseData.patient_last_name || '';
    if (patientDOB) patientDOB.value = caseData.patientDOB || caseData.patient_dob || '';
    if (patientGender) patientGender.value = caseData.patientGender || caseData.patient_gender || '';
    if (dentistName) dentistName.value = caseData.dentistName || caseData.dentist_name || '';
    if (caseType) caseType.value = caseData.caseType || caseData.case_type || '';
    if (toothShade) toothShade.value = caseData.toothShade || caseData.tooth_shade || '';
    if (material) material.value = caseData.material || '';
    if (dueDate) dueDate.value = caseData.dueDate || caseData.due_date || '';
    if (patientAppointmentDate) {
      var apptValue = caseData.patientAppointmentDate || caseData.patient_appointment_date || '';
      if (apptValue) {
        // Use the leading YYYY-MM-DD portion when present to keep the
        // calendar day exactly as stored instead of converting timezones.
        var apptPrefixMatch = String(apptValue).match(/^(\d{4}-\d{2}-\d{2})/);
        if (apptPrefixMatch) {
          patientAppointmentDate.value = apptPrefixMatch[1];
        } else {
          patientAppointmentDate.value = apptValue;
        }
      } else {
        patientAppointmentDate.value = '';
      }
    }
    if (status) status.value = caseData.status || 'Originated';
    if (notes) notes.value = caseData.notes || '';

    // Shipping fields
    var carrier = document.getElementById('carrier');
    var trackingNumber = document.getElementById('trackingNumber');
    var customCarrier = document.getElementById('customCarrier');
    if (carrier) carrier.value = caseData.carrier || '';
    if (customCarrier) customCarrier.value = caseData.customCarrier || '';
    if (trackingNumber) trackingNumber.value = caseData.trackingNumber || '';
    toggleCustomCarrierField();
    updateTrackingNumberLink();

    // Created By is read-only and resolved by the server; never sent back.
    var createdByDisplay = document.getElementById('createdByDisplay');
    if (createdByDisplay) {
      createdByDisplay.textContent = caseData.createdByName || t('common.unknown');
    }

    // Populate + select the Assigned To dropdown. The <select id="assignedTo">
    // only has a static "Select user..." option in the markup - the real
    // People/Assignment Labels <option>s are added dynamically by
    // initializeAssignmentDropdown() (assignments.js). Simply setting
    // assignedTo.value here (as this used to do) silently fails to select
    // anything when there's no matching <option> yet, which is why archived
    // (and other read-only "View Case") views appeared to have lost the
    // assignment even though it was persisted correctly server-side. This
    // mirrors the same call editCaseHandler() already makes for the normal
    // edit flow.
    if (assignedTo) {
      var currentAssignee = caseData.assignedTo || caseData.assigned_to || '';
      if (typeof initializeAssignmentDropdown === 'function') {
        setTimeout(function() {
          initializeAssignmentDropdown(assignedTo, caseData.id || caseData.case_id, currentAssignee);
        }, 100);
      } else {
        assignedTo.value = currentAssignee;
      }
    }

    // Populate clinical details if available
    var clinicalDetails = caseData.clinicalDetails || caseData.clinical_details || null;
    var caseTypeValue = caseData.caseType || caseData.case_type || '';
    if (typeof setClinicalDetailsData === 'function') {
      setClinicalDetailsData(clinicalDetails, caseTypeValue);
    }

    // Update modal title for editing
    var modalTitle = createCaseModal.querySelector('.modal-title');
    if (modalTitle) {
      modalTitle.textContent = t('cases.edit_case');
    }

    // Update submit button text
    var submitBtn = document.getElementById('createCaseSubmit');
    if (submitBtn) {
      submitBtn.textContent = t('cases.update_case');
    }

    // Load and display existing files
    if (caseData.files && Array.isArray(caseData.files)) {
      displayExistingFiles(caseData.files);
    }
  }

  // Show/hide the custom carrier field and clear it when not applicable.
  function toggleCustomCarrierField() {
    var carrier = document.getElementById('carrier');
    var customField = document.getElementById('customCarrierField');
    var customInput = document.getElementById('customCarrier');
    if (!carrier || !customField || !customInput) return;
    if (carrier.value === 'Other') {
      customField.style.display = 'block';
    } else {
      customField.style.display = 'none';
      customInput.value = '';
    }
  }

  // Carrier tracking URL templates. Carrier is a known, server-controlled
  // value; only the tracking number is user-provided and is encoded.
  function getCarrierTrackingUrl(carrier, trackingNumber) {
    var t = encodeURIComponent(trackingNumber.trim());
    if (!t) return '';
    switch (carrier) {
      case 'UPS':
        return 'https://www.ups.com/track?tracknum=' + t;
      case 'FedEx':
        return 'https://www.fedex.com/apps/fedextrack/?tracknumbers=' + t;
      case 'USPS':
        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' + t;
      case 'DHL':
        return 'https://www.dhl.com/en/hidden/toolbox/tracking.html?tracking-id=' + t;
      default:
        return '';
    }
  }

  function updateTrackingNumberLink() {
    var carrier = document.getElementById('carrier');
    var trackingNumber = document.getElementById('trackingNumber');
    var link = document.getElementById('trackingNumberLink');
    if (!carrier || !trackingNumber || !link) return;
    var url = getCarrierTrackingUrl(carrier.value, trackingNumber.value);
    if (url) {
      link.href = url;
      link.style.display = 'inline-block';
    } else {
      link.href = '#';
      link.style.display = 'none';
    }
  }

  function displayExistingFiles(files) {
    // Group files by type
    var fileGroups = {
      photos: [],
      intraoralScans: [],
      facialScans: [],
      radiographs: [],
      documents: []
    };

    files.forEach(function(file) {
      var type = file.type || 'documents';
      if (fileGroups[type]) {
        fileGroups[type].push(file);
      } else {
        fileGroups.documents.push(file);
      }
    });

    // Display files in their respective containers
    Object.keys(fileGroups).forEach(function(type) {
      var container = document.getElementById(type + '-files');
      if (container && fileGroups[type].length > 0) {
        container.innerHTML = '';
        fileGroups[type].forEach(function(file) {
          var fileElement = createFileElement(file, type);
          container.appendChild(fileElement);
        });
      }
    });
  }

  function createFileElement(file, type) {
    var div = document.createElement('div');
    div.className = 'selected-file';
    div.setAttribute('data-file-id', file.id);
    div.setAttribute('data-file-name', file.name);

    var fileInfo = document.createElement('div');
    fileInfo.className = 'file-info';

    var fileName = document.createElement('span');
    fileName.className = 'file-name';
    fileName.textContent = file.name;

    var viewLink = document.createElement('a');
    viewLink.href = file.webViewLink || '#';
    viewLink.target = '_blank';
    viewLink.className = 'file-view-link';
    viewLink.textContent = t('common.view');

    fileInfo.appendChild(fileName);
    fileInfo.appendChild(viewLink);
    div.appendChild(fileInfo);

    return div;
  }

  /**
   * Open a case by its ID (used by notifications)
   */
  var openingCaseById = false;

  function setViewCaseLoading() {
    if (caseViewLoading) caseViewLoading.style.display = 'block';
    if (caseViewError) caseViewError.style.display = 'none';
    if (createCaseForm) createCaseForm.style.display = 'none';
    if (caseModalTabs) caseModalTabs.style.display = 'none';
    if (caseCommentsPanel) caseCommentsPanel.style.display = 'none';
    if (caseHistoryPanel) caseHistoryPanel.style.display = 'none';
  }

  function setViewCaseError() {
    if (caseViewLoading) caseViewLoading.style.display = 'none';
    if (caseViewError) caseViewError.style.display = 'block';
    if (createCaseForm) createCaseForm.style.display = 'none';
    if (caseModalTabs) caseModalTabs.style.display = 'none';
  }

  function resetCaseViewState() {
    if (caseViewLoading) caseViewLoading.style.display = 'none';
    if (caseViewError) caseViewError.style.display = 'none';
    if (createCaseForm) createCaseForm.style.display = 'block';
    if (caseModalTabs) caseModalTabs.style.display = 'flex';
  }

  window.openCaseById = function(caseId) {
    if (!caseId || openingCaseById) return;
    openingCaseById = true;
    pendingViewCaseId = caseId;

    viewCaseTimings = window.viewCaseTimings = {
      shellStart: performance.now(),
      shellVisible: null,
      fieldsPopulated: null
    };

    var caseModal = document.getElementById('createCaseModal');
    if (caseModal) {
      caseModal.style.display = 'block';
      setViewCaseLoading();
      var modalTitle = caseModal.querySelector('.modal-title');
      if (modalTitle) {
        modalTitle.textContent = t('cases.loading') || 'Loading case...';
      }
      if (viewCaseTimings) viewCaseTimings.shellVisible = performance.now() - viewCaseTimings.shellStart;
    }

    var fetchStart = performance.now();
    fetch('api/get-case.php?id=' + encodeURIComponent(caseId), {
      credentials: 'same-origin'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      openingCaseById = false;
      if (data.success && data.case) {
        openCaseModalForView(data.case, fetchStart);
        // Switch to comments tab after opening
        setTimeout(function() {
          var commentsTab = document.querySelector('.case-tab[data-tab="comments"]');
          if (commentsTab) {
            commentsTab.click();
          }
        }, 100);
      } else {
        setViewCaseError();
        if (typeof showToast === 'function') {
          showToast(data.message || t('cases.toast.open_failed'), 'error');
        }
      }
    })
    .catch(function(error) {
      openingCaseById = false;
      setViewCaseError();
      if (typeof showToast === 'function') {
        showToast(t('cases.toast.open_error'), 'error');
      }
    });
  };

  function openCaseModalForView(caseData, fetchStart) {
    if (createCaseModal) {
      createCaseModal.style.display = 'block';

      // Remove any existing "Back to Archived Cases" button (only relevant when coming from archived modal)
      var existingBackBtn = createCaseModal.querySelector('.back-to-archived');
      if (existingBackBtn) {
        existingBackBtn.remove();
      }

      // Populate the form with case data
      populateCreateCaseForm(caseData);

      // Reveal the populated form and tabs
      resetCaseViewState();
      if (viewCaseTimings) {
        viewCaseTimings.fieldsPopulated = performance.now() - viewCaseTimings.shellStart;
      }

      // Change modal title to "View Case"
      var modalTitle = createCaseModal.querySelector('.modal-title');
      if (modalTitle) {
        modalTitle.textContent = t('cases.view_case');
      }

      // Hide submit button and show close button instead
      var submitBtn = document.getElementById('createCaseSubmit');
      var cancelBtn = document.getElementById('createCaseCancel');
      if (submitBtn) {
        submitBtn.style.display = 'none';
      }
      if (cancelBtn) {
        cancelBtn.textContent = t('common.close');
        cancelBtn.style.display = 'inline-block';
      }

      // Make all form fields readonly
      var form = document.getElementById('createCaseForm');
      if (form) {
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
          if (input.type !== 'button' && input.type !== 'submit' && input.type !== 'file') {
            if (input.tagName === 'SELECT') {
              // Disable select dropdowns but keep consistent styling
              input.disabled = true;
              input.style.backgroundColor = '#f8fafc';
              input.style.cursor = 'default';
              input.style.opacity = '1'; // Keep full opacity like other fields
              input.style.color = '#374151'; // Ensure text color matches other readonly fields
            } else {
              // Make input/textarea readonly
              input.setAttribute('readonly', 'readonly');
              input.style.backgroundColor = '#f8fafc';
              input.style.cursor = 'default';
            }
          }
        });

        // Disable file upload functionality
        var fileInputs = form.querySelectorAll('input[type="file"]');
        fileInputs.forEach(function(input) {
          input.disabled = true;
          input.style.opacity = '0.5';
        });

        // Disable delete file buttons
        var deleteButtons = form.querySelectorAll('.delete-file-btn');
        deleteButtons.forEach(function(btn) {
          btn.style.display = 'none';
        });
      }

      // Show tabs for viewing
      if (caseModalTabs) {
        caseModalTabs.style.display = 'flex';
      }
      if (caseHistoryTab) {
        caseHistoryTab.classList.remove('case-tab-disabled');
      }

      // Load revision history
      loadCaseRevisionHistory(caseData.case_id || caseData.id);

      // Always start on Details tab
      setCaseModalActiveTab('details');

      // Don't track form changes for view mode
      hasUnsavedChanges = false;
    }
  }

  if (createBtn) {
    createBtn.addEventListener('click', function() {
      // Check billing before allowing case creation
      if (!checkBillingForCaseCreation()) {
        return;
      }

      // Fully reset to a brand-new case state
      resetCreateCaseFormToNew();

      // For a brand-new case, hide tabs and reset history panel
      loadCaseRevisionHistory(null);
      openCreateCase();
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closeCreateCaseWithCheck);
  if (cancelBtn) cancelBtn.addEventListener('click', closeCreateCaseWithCheck);
  if (caseViewRetry) caseViewRetry.addEventListener('click', function() {
    if (pendingViewCaseId) openCaseById(pendingViewCaseId);
  });
  if (caseViewErrorClose) caseViewErrorClose.addEventListener('click', closeCreateCaseWithCheck);


  // Unsaved changes tracking
  var originalFormData = null;
  var hasUnsavedChanges = false;
  var isSubmitting = false;

  function trackFormChanges() {
    var form = document.getElementById('createCaseForm');
    if (!form) return;

    // Store original form data when modal opens
    originalFormData = new FormData(form);
    hasUnsavedChanges = false;

    // Track changes to form fields
    var inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(function(input) {
      input.addEventListener('change', function() {
        checkForChanges();
      });

      input.addEventListener('input', function() {
        checkForChanges();
      });
    });

    // Track file changes
    var fileInputs = form.querySelectorAll('input[type="file"]');
    fileInputs.forEach(function(input) {
      input.addEventListener('change', function() {
        checkForChanges();
      });
    });

    // Track file deletions
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('file-remove')) {
        setTimeout(checkForChanges, 100); // Small delay to allow UI update
      }
    });
  }

  function checkForChanges() {
    var form = document.getElementById('createCaseForm');
    if (!form || !originalFormData) return;

    // Check if form data has changed
    var currentFormData = new FormData(form);
    hasUnsavedChanges = !formDataEqual(originalFormData, currentFormData);
  }

  function formDataEqual(formData1, formData2) {
    // Convert FormData to objects for comparison
    var obj1 = {};
    var obj2 = {};

    for (var pair of formData1.entries()) {
      obj1[pair[0]] = pair[1];
    }

    for (var pair of formData2.entries()) {
      obj2[pair[0]] = pair[1];
    }

    // Compare keys and values
    var keys1 = Object.keys(obj1);
    var keys2 = Object.keys(obj2);

    if (keys1.length !== keys2.length) return false;

    for (var key of keys1) {
      if (obj1[key] !== obj2[key]) return false;
    }

    return true;
  }

  function showUnsavedChangesWarning(callback) {
    // Create custom confirmation dialog
    var dialog = document.createElement('div');
    dialog.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10000;
    `;

    var content = document.createElement('div');
    content.style.cssText = `
      background: white;
      padding: 30px;
      border-radius: 8px;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    `;

    content.innerHTML = `
      <h3 style="margin: 0 0 15px 0; color: #333;">${t('settings.common.unsaved_changes')}</h3>
      <p style="margin: 0 0 25px 0; color: #666; line-height: 1.5;">
        ${t('settings.messages.unsaved_message')}
      </p>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button id="stay-btn" style="
          padding: 10px 20px;
          background: #6c757d;
          color: white;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('settings.common.stay')}</button>
        <button id="close-btn" style="
          padding: 10px 20px;
          background: #dc3545;
          color: white;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('settings.common.close_without_saving')}</button>
      </div>
    `;

    dialog.appendChild(content);
    document.body.appendChild(dialog);

    // Add event listeners
    document.getElementById('stay-btn').addEventListener('click', function() {
      document.body.removeChild(dialog);
    });

    document.getElementById('close-btn').addEventListener('click', function() {
      document.body.removeChild(dialog);
      if (callback) callback();
    });

    // Close on backdrop click
    dialog.addEventListener('click', function(e) {
      if (e.target === dialog) {
        document.body.removeChild(dialog);
      }
    });
  }

  // Modify closeCreateCase to check for unsaved changes
  function closeCreateCaseWithCheck() {
    // Prevent closing if form is submitting
    if (isSubmitting) {
      return;
    }

    if (hasUnsavedChanges) {
      showUnsavedChangesWarning(function() {
        closeCreateCase();
      });
    } else {
      closeCreateCase();
    }
  }

  // Function to clear file selections
  function clearFileSelections() {
    document.querySelectorAll('.selected-files').forEach(function(container) {
      container.innerHTML = '';
    });

    // Clear accumulated files from all file inputs
    document.querySelectorAll('.attachment-input').forEach(function(input) {
      input._accumulatedFiles = [];
      input.value = ''; // Clear the input
    });
  }

  // Make file input labels keyboard accessible
  var fileLabels = document.querySelectorAll('.file-button[tabindex="0"]');
  fileLabels.forEach(function(label) {
    // Add keyboard event handling
    label.addEventListener('keydown', function(e) {
      // Activate on Enter or Space key
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault(); // Prevent default space/enter behavior
        // Programmatically click the label to open file dialog
        this.click();
      }
    });
    // Focus and blur styling is handled by CSS
  });

  // Handle file selection display with remove option
  // Accumulate files from multiple selections (different folders)
  var fileInputs = document.querySelectorAll('.attachment-input');

  fileInputs.forEach(function(input) {
    input.addEventListener('change', function() {
      var fileType = this.dataset.type;
      var filesContainer = document.getElementById(fileType + '-files');
      var inputElement = this;

      if (!filesContainer) {
        return;
      }

      // Get existing files from the input (previously selected)
      var existingFiles = [];
      if (inputElement._accumulatedFiles) {
        existingFiles = inputElement._accumulatedFiles.slice();
      }

      // Add new files to accumulated list (avoid duplicates by name)
      if (this.files.length > 0) {
        var existingNames = existingFiles.map(function(f) { return f.name; });
        for (var i = 0; i < this.files.length; i++) {
          var file = this.files[i];
          if (existingNames.indexOf(file.name) === -1) {
            existingFiles.push(file);
          }
        }
      }

      // Store accumulated files
      inputElement._accumulatedFiles = existingFiles;

      // Update the input's FileList with all accumulated files
      var dt = new DataTransfer();
      existingFiles.forEach(function(file) {
        dt.items.add(file);
      });
      inputElement.files = dt.files;

      // Clear only previously selected (non-existing) files from display
      var nonExisting = filesContainer.querySelectorAll('.selected-file:not(.existing-file)');
      nonExisting.forEach(function(el) { el.remove(); });

      // Display all accumulated files
      existingFiles.forEach(function(file) {
        // Create file element
        var fileElement = document.createElement('div');
        fileElement.className = 'selected-file';
        fileElement.dataset.fileName = file.name;

        // Create file name span
        var nameSpan = document.createElement('span');
        nameSpan.textContent = file.name;

        // Create delete button (no inner content - using CSS ::before)
        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'file-remove';
        deleteBtn.title = 'Remove file';

        // Assemble elements
        fileElement.appendChild(nameSpan);
        fileElement.appendChild(deleteBtn);
        filesContainer.appendChild(fileElement);

        // Add click handler to remove button
        deleteBtn.addEventListener('click', function() {
          var fileName = file.name;

          // Remove from accumulated files
          inputElement._accumulatedFiles = inputElement._accumulatedFiles.filter(function(f) {
            return f.name !== fileName;
          });

          // Update the input's FileList
          var newDt = new DataTransfer();
          inputElement._accumulatedFiles.forEach(function(f) {
            newDt.items.add(f);
          });
          inputElement.files = newDt.files;

          // Remove the visual element
          fileElement.remove();

          // Mark form as having unsaved changes
          hasUnsavedChanges = true;
        });
      });

      // Mark form as having unsaved changes when files are added
      if (existingFiles.length > 0) {
        hasUnsavedChanges = true;
      }
    });
  });

  // Form validation and submission with enhanced UX
  if (submitBtn) {
    submitBtn.addEventListener('click', function() {
      var form = document.getElementById('createCaseForm');
      var isValid = true;

      // Prevent multiple submissions
      if (isSubmitting) {
        return false;
      }

      // Helper function to add field error
      function addFieldError(field, message) {
        field.classList.add('field-error');
        if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
          var errorMessage = document.createElement('div');
          errorMessage.className = 'error-message';
          errorMessage.textContent = message || t('validation.required');
          field.parentNode.insertBefore(errorMessage, field.nextSibling);
        }
      }

      // Helper function to clear field error
      function clearFieldError(field) {
        field.classList.remove('field-error');
        if (field.nextElementSibling && field.nextElementSibling.classList.contains('error-message')) {
          field.nextElementSibling.remove();
        }
      }

      // Check all globally required fields (fields with required attribute)
      var requiredFields = form.querySelectorAll('[required]');

      requiredFields.forEach(function(field) {
        if (!field.value) {
          isValid = false;
          addFieldError(field, 'This field is required');
        } else {
          clearFieldError(field);
        }
      });

      // Check case-type-specific conditionally required fields
      var caseType = form.querySelector('#caseType');
      var currentCaseType = caseType ? caseType.value : '';

      // Find all conditionally required fields that are visible for the current case type
      var conditionalFields = form.querySelectorAll('[data-conditionally-required="true"]');

      conditionalFields.forEach(function(fieldContainer) {
        var caseTypes = fieldContainer.dataset.caseTypes || '';
        var caseTypeList = caseTypes.split(',').map(function(t) { return t.trim(); });

        // Only validate if this field is visible for the current case type
        if (caseTypeList.includes(currentCaseType)) {
          var input = fieldContainer.querySelector('input, select, textarea');
          if (input && !input.value) {
            isValid = false;
            addFieldError(input, t('cases.clinical.validation.required_for_case_type', {caseType: getCaseTypeDisplayLabel(currentCaseType)}));
          } else if (input) {
            clearFieldError(input);
          }
        }
      });

      // ============================================
      // TOOTH NUMBER VALIDATION ON SUBMIT
      // Business Rule: For Crown case type, validates tooth number
      // using standard dental numbering (1-32 for adult teeth).
      // ============================================
      if (currentCaseType === 'Crown' && window.toothNumberValidation) {
        var toothNumberInput = document.getElementById('clinicalToothNumber');
        if (toothNumberInput && toothNumberInput.value.trim() !== '') {
          var toothResult = window.toothNumberValidation.validateToothNumber(toothNumberInput.value);
          if (!toothResult.valid) {
            isValid = false;
            window.toothNumberValidation.showFieldError(toothNumberInput, toothResult.error);
          }
        }
      }

      // ============================================
      // CASE NOTES CHARACTER LIMIT VALIDATION ON SUBMIT
      // Business Rule: Notes field is limited to 3,000 characters.
      // ============================================
      var notesField = document.getElementById('notes');
      if (notesField && notesField.value.length > 3000) {
        isValid = false;
        addFieldError(notesField, t('validation.notes_max', {max: 3000}));
      }

      if (!isValid) {
        // Scroll to top of modal to show errors
        var modalContent = form.closest('.modal-content');
        if (modalContent) {
          modalContent.scrollTop = 0;
        }
        // Also scroll the first error field into view
        var firstError = form.querySelector('.field-error');
        if (firstError) {
          firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
          firstError.focus();
        }
        return false;
      }

      // Check if we're updating an existing case or creating a new one
      var isUpdate = form.dataset.caseId ? true : false;

      // Show enhanced loading state with animation
      isSubmitting = true;
      submitBtn.disabled = true;
      submitBtn.classList.add('submitting');

      // Add loading spinner and text
      submitBtn.innerHTML = isUpdate ?
        '<span class="btn-spinner"></span> ' + t('cases.updating_case') :
        '<span class="btn-spinner"></span> ' + t('cases.creating_case');

      // --- GCS Direct Upload Flow ---
      // Step 1: Upload files directly to GCS (bypasses Cloud Run 32MB limit)
      // Step 2: Submit case metadata with storage paths (no binary data)

      var hasNewFiles = typeof GCSUpload !== 'undefined' && GCSUpload.formHasFiles(form);

      var gcsUploadPromise;

      if (hasNewFiles) {
        var caseIdForUpload = isUpdate ? form.dataset.caseId : 'new';

        submitBtn.innerHTML = '<span class="btn-spinner"></span> ' + t('common.uploading');

        gcsUploadPromise = GCSUpload.uploadFilesToGCS(form, caseIdForUpload, csrfToken, function(uploaded, total, fileName) {
          submitBtn.innerHTML = '<span class="btn-spinner"></span> ' + t('common.uploading_with_count', {uploaded: uploaded, total: total});
        });
      } else {

        gcsUploadPromise = Promise.resolve([]);
      }

      var caseSubmitController = new AbortController();
      var caseSubmitTimeoutId = null;

      gcsUploadPromise.then(function(gcsFiles) {

        // Update button text for case submission phase
        submitBtn.innerHTML = isUpdate ?
          '<span class="btn-spinner"></span> ' + t('cases.saving_case') :
          '<span class="btn-spinner"></span> ' + t('cases.creating_case');

        // Build FormData WITHOUT file binaries - only text fields
        var formData = new FormData();

        // Copy all non-file form fields
        var formElements = form.elements;
        for (var i = 0; i < formElements.length; i++) {
          var el = formElements[i];
          if (el.name && el.type !== 'file' && el.type !== 'submit' && el.type !== 'button') {
            formData.append(el.name, el.value);
          }
        }

        // Collect and append clinical details as JSON
        if (typeof getClinicalDetailsData === 'function') {
          var clinicalDetails = getClinicalDetailsData();
          if (clinicalDetails && Object.keys(clinicalDetails).length > 0) {
            formData.append('clinicalDetails', JSON.stringify(clinicalDetails));
          }
        }

        // If updating, add case ID efficiently
        if (isUpdate) {
          formData.append('caseId', form.dataset.caseId);

          // Add drive folder ID from dataset if available
          if (form.dataset.driveFolderId) {
            formData.append('driveFolderId', form.dataset.driveFolderId);
          } else {
            // Quick lookup for drive folder ID using cached data
            var driveFolderId = getDriveFolderIdFromCache(form.dataset.caseId);
            if (driveFolderId) {
              formData.append('driveFolderId', driveFolderId);
            }
          }

          // Add version for optimistic locking (concurrent edit detection)
          if (form.dataset.caseVersion) {
            formData.append('version', form.dataset.caseVersion);
          }
        }

        // Append GCS uploaded file metadata (storage paths, not binary data)
        if (gcsFiles.length > 0) {
          formData.append('gcs_files', JSON.stringify(gcsFiles));
        }

        // Collect files for deletion efficiently
        var filesToDelete = collectFilesForDeletion();
        if (filesToDelete.length > 0) {
          formData.append('filesToDelete', JSON.stringify(filesToDelete));
        }

        // Submit case metadata (small payload, no binary data)
        var endpoint = isUpdate ? 'api/update-case.php' : 'api/create-case.php';

        caseSubmitTimeoutId = setTimeout(function() { caseSubmitController.abort(); }, 30000); // 30 second timeout (no files in body)

        return fetch(endpoint, {
          method: 'POST',
          body: formData,
          headers: {
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin',
          signal: caseSubmitController.signal
        });
      })
      .then(response => {
        if (caseSubmitTimeoutId) clearTimeout(caseSubmitTimeoutId);
        if (!response.ok) {
          // Read the response body to get the actual error message
          return response.text().then(text => {
            var errorMessage = 'Server error (status ' + response.status + ')';
            try {
              var errorData = JSON.parse(text);

              // Handle 401 Unauthorized (session expired during upload)
              if (response.status === 401) {
                var sessionError = new Error('Your session expired during upload. Please log in again. Your files were uploaded successfully and can be attached after re-authentication.');
                sessionError.sessionExpired = true;
                sessionError.uploadedFiles = gcsFiles; // Preserve uploaded file paths
                throw sessionError;
              }

              // Handle 409 Conflict (concurrent edit detected)
              if (response.status === 409 && errorData.conflict) {
                var conflictError = new Error(errorData.message || 'This case was modified by another user.');
                conflictError.conflict = true;
                conflictError.currentData = errorData.currentData;
                conflictError.currentVersion = errorData.currentVersion;
                throw conflictError;
              }

              if (errorData.message) {
                errorMessage = errorData.message;
              } else if (errorData.error) {
                errorMessage = errorData.error;
              }
            } catch (e) {
              if (e.sessionExpired) throw e; // Re-throw session errors
              if (e.conflict) throw e; // Re-throw conflict errors
              // If not JSON, use the text directly (truncated)
              if (text && text.length > 0) {
                errorMessage = text.substring(0, 200);
              }
            }
            throw new Error(errorMessage);
          });
        }
        return response.text();
      })
      .then(text => {
        try {
          return JSON.parse(text);
        } catch (e) {
          throw new Error('Server returned invalid JSON: ' + text.substring(0, 100) + '...');
        }
      })
      .then(data => {
        handleCaseSubmissionSuccess(data, form, submitBtn, isUpdate);
      })
      .catch(error => {
        handleCaseSubmissionError(error, form, submitBtn, isUpdate);
      });

      return false;
    });
  }

  // Helper function to get drive folder ID from cache
  function getDriveFolderIdFromCache(caseId) {
    // Try to find from existing cards efficiently
    var caseCards = document.querySelectorAll('.kanban-card');
    for (var i = 0; i < caseCards.length; i++) {
      try {
        var cardData = JSON.parse(caseCards[i].dataset.caseJson || '{}');
        if (cardData.id === caseId && cardData.driveFolderId) {
          return cardData.driveFolderId;
        }
      } catch (e) {
        continue;
      }
    }
    return null;
  }

  // Helper function to collect files for deletion
  function collectFilesForDeletion() {
    var markedForDeletion = document.querySelectorAll('.marked-for-deletion');
    var filesToDelete = [];

    markedForDeletion.forEach(function(element) {
      if (element.dataset.fileId && element.dataset.attachmentId) {
        filesToDelete.push({
          fileId: element.dataset.fileId,
          attachmentId: element.dataset.attachmentId
        });
      }
    });

    return filesToDelete;
  }

  // Optimized success handler
  function handleCaseSubmissionSuccess(data, form, submitBtn, isUpdate) {
    // Show success animation
    submitBtn.classList.remove('submitting');
    submitBtn.classList.add('success');
    submitBtn.innerHTML = '<span class="btn-checkmark"></span> ' + t('common.success_exclamation');

    // Use requestAnimationFrame for smooth DOM updates
    requestAnimationFrame(() => {
      if (isUpdate) {
        // Remove old card efficiently
        removeOldCard(form.dataset.caseId);
      }

      // Add new card with animation
      addCaseToKanbanWithAnimation(data.caseData);

      // Update counts
      updateColumnCounts();

      // Apply highlighting
      applyPastDueHighlighting(data.caseData);

      // Load billing info asynchronously for new cases
      if (!isUpdate) {
        setTimeout(() => loadBillingInfo(), 100);
      }

      // Reset and close after success animation
      setTimeout(() => {
        resetFormAndClose(form, submitBtn, isUpdate);
      }, 800);
    });
  }

  // Optimized error handler
  function handleCaseSubmissionError(error, form, submitBtn, isUpdate) {
    submitBtn.classList.remove('submitting');
    submitBtn.classList.add('error');
    submitBtn.innerHTML = '<span class="btn-error"></span> ' + t('common.error');

    // Handle concurrent edit conflict
    if (error.conflict) {
      showConcurrentEditConflictDialog(error, form);
      // Reset button immediately for conflict
      submitBtn.classList.remove('error');
      submitBtn.disabled = false;
      submitBtn.innerHTML = isUpdate ? t('cases.update_case') : t('cases.create_case');
      isSubmitting = false;
      return;
    }

    // Show appropriate error message
    // Errors from gcs-upload.js already contain user-friendly text
    // (e.g. "STL files must be under 250MB", "Maximum 15 files per case")
    // so we pass them through directly.
    var errorMessage;
    var msg = error.message || '';
    if (error.name === 'AbortError') {
      errorMessage = t('cases.toast.timeout');
    } else if (msg.indexOf('must be under') !== -1 || msg.indexOf('Maximum') !== -1 || msg.indexOf('cannot exceed') !== -1 || msg.indexOf('Over-limit') !== -1) {
      // Type-specific or aggregate limit error from frontend/backend validation
      errorMessage = msg;
    } else if (msg.indexOf('Failed to upload') !== -1) {
      errorMessage = t('cases.toast.upload_failed');
    } else if (msg.indexOf('upload URL') !== -1) {
      errorMessage = t('cases.toast.upload_prepare_failed');
    } else if (msg.indexOf('storage failed') !== -1) {
      errorMessage = t('cases.toast.storage_failed');
    } else if (msg.indexOf('verification failed') !== -1) {
      errorMessage = t('cases.toast.verification_failed', {message: msg});
    } else if (msg.indexOf('413') !== -1 || msg.toLowerCase().indexOf('too large') !== -1 || msg.toLowerCase().indexOf('payload too large') !== -1) {
      errorMessage = t('cases.toast.payload_too_large');
    } else {
      errorMessage = t(isUpdate ? 'cases.toast.update_failed' : 'cases.toast.create_failed', {message: msg});
    }

    showToast(errorMessage, 'error');

    // Reset button after error animation
    setTimeout(() => {
      submitBtn.classList.remove('error');
      submitBtn.disabled = false;
      submitBtn.innerHTML = isUpdate ? t('cases.update_case') : t('cases.create_case');
      isSubmitting = false;
    }, 2000);
  }

  // Show dialog when concurrent edit conflict is detected
  function showConcurrentEditConflictDialog(error, form) {
    var savedData = error.currentData || {};
    var originalDataStr = form ? form.dataset.originalCaseData : null;
    var originalData = originalDataStr ? JSON.parse(originalDataStr) : null;
    var hasOriginalData = originalData && Object.keys(originalData).length > 0;

    // Get user's current form values
    var yourData = {};
    if (form) {
      yourData.patientFirstName = (form.querySelector('#patientFirstName') || {}).value || '';
      yourData.patientLastName = (form.querySelector('#patientLastName') || {}).value || '';
      yourData.status = (form.querySelector('#status') || {}).value || '';
      yourData.dentistName = (form.querySelector('#dentistName') || {}).value || '';
      yourData.caseType = (form.querySelector('#caseType') || {}).value || '';
      yourData.toothShade = (form.querySelector('#toothShade') || {}).value || '';
      yourData.material = (form.querySelector('#material') || {}).value || '';
      yourData.dueDate = (form.querySelector('#dueDate') || {}).value || '';
      yourData.patientAppointmentDate = (form.querySelector('#patientAppointmentDate') || {}).value || '';
      yourData.notes = (form.querySelector('#notes') || {}).value || '';
    }

    var fieldLabels = {
      patientFirstName: t('cases.fields.firstName'),
      patientLastName: t('cases.fields.lastName'),
      status: t('cases.fields.status'),
      dentistName: t('cases.fields.dentistName'),
      caseType: t('cases.fields.caseType'),
      toothShade: t('cases.fields.shade'),
      material: t('cases.fields.material'),
      dueDate: t('cases.fields.dueDate'),
      notes: t('cases.fields.notes')
    };

    // Find all fields where your value differs from saved value
    var conflicts = [];
    for (var field in fieldLabels) {
      var yourVal = (yourData[field] || '').toString().trim();
      var savedVal = (savedData[field] || '').toString().trim();

      // Show any field where your value differs from the saved value
      if (yourVal !== savedVal) {
        conflicts.push({
          field: field,
          label: fieldLabels[field],
          yours: yourVal || '(empty)',
          saved: savedVal || '(empty)'
        });
      }
    }

    // If no differences at all, just update version and retry
    if (conflicts.length === 0) {
      if (form && savedData.version) {
        form.dataset.caseVersion = savedData.version;
      }
      setTimeout(function() {
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.click();
      }, 100);
      return;
    }

    // There are true conflicts - show the modal
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay conflict-modal-overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

    var modal = document.createElement('div');
    modal.className = 'conflict-modal';
    modal.style.cssText = 'background:white;border-radius:12px;padding:24px;max-width:650px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);max-height:90vh;overflow-y:auto;';

    // Build conflicts table - side by side comparison
    var conflictHtml = '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">' +
      '<thead><tr>' +
        '<th style="text-align:left;padding:10px;border-bottom:2px solid #e5e7eb;font-size:0.8rem;color:#6b7280;">' + t('cases.conflict.field') + '</th>' +
        '<th style="text-align:left;padding:10px;border-bottom:2px solid #e5e7eb;font-size:0.8rem;color:#dc2626;background:#fef2f2;">' + t('cases.conflict.your_value') + '</th>' +
        '<th style="text-align:left;padding:10px;border-bottom:2px solid #e5e7eb;font-size:0.8rem;color:#16a34a;background:#f0fdf4;">' + t('cases.conflict.their_value') + '</th>' +
      '</tr></thead><tbody>';

    conflicts.forEach(function(conflict) {
      conflictHtml += '<tr>' +
        '<td style="padding:10px;border-bottom:1px solid #f3f4f6;font-weight:600;color:#374151;">' + conflict.label + '</td>' +
        '<td style="padding:10px;border-bottom:1px solid #f3f4f6;background:#fef2f2;color:#991b1b;">' + escapeHtml(conflict.yours) + '</td>' +
        '<td style="padding:10px;border-bottom:1px solid #f3f4f6;background:#f0fdf4;color:#166534;">' + escapeHtml(conflict.saved) + '</td>' +
      '</tr>';
    });
    conflictHtml += '</tbody></table>';

    modal.innerHTML =
      '<div style="text-align:center;margin-bottom:20px;">' +
        '<div style="font-size:48px;margin-bottom:12px;">⚠️</div>' +
        '<h3 style="margin:0 0 8px 0;color:#1f2937;font-size:1.25rem;">' + t('cases.conflict.title') + '</h3>' +
        '<p style="margin:0;color:#6b7280;font-size:0.95rem;">' + t('cases.conflict.subtitle') + '</p>' +
      '</div>' +

      conflictHtml +

      '<p style="margin:0 0 16px 0;font-size:0.85rem;color:#6b7280;text-align:center;">' +
        '<strong>' + t('cases.conflict.load_theirs') + '</strong> ' + t('cases.conflict.load_theirs_description') + '<br>' +
        '<strong>' + t('cases.conflict.keep_mine') + '</strong> ' + t('cases.conflict.keep_mine_description') +
      '</p>' +

      '<div style="display:flex;gap:12px;justify-content:center;">' +
        '<button class="conflict-reload-btn" style="padding:10px 20px;background:#16a34a;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:500;">' + t('cases.conflict.load_theirs') + '</button>' +
        '<button class="conflict-cancel-btn" style="padding:10px 20px;background:#3b82f6;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:500;">' + t('cases.conflict.keep_mine') + '</button>' +
      '</div>';

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Handle "Load Their Version" - update form with saved data
    modal.querySelector('.conflict-reload-btn').addEventListener('click', function() {
      overlay.remove();
      if (savedData && savedData.id) {
        populateCreateCaseForm(savedData);
        if (form && savedData.version) {
          form.dataset.caseVersion = savedData.version;
          form.dataset.originalCaseData = JSON.stringify(savedData);
        }
        // Update the card on the board
        updateCardOnBoard(savedData);
        showToast(t('cases.toast.form_updated'), 'success');
      } else {
        location.reload();
      }
    });

    // Handle "Keep My Version" - keep form data, update version, and auto-save
    modal.querySelector('.conflict-cancel-btn').addEventListener('click', function() {
      overlay.remove();
      // Update version so save will succeed (will overwrite their changes)
      if (form && savedData.version) {
        form.dataset.caseVersion = savedData.version;
      }
      // Auto-trigger save with the user's version
      var submitBtn = document.getElementById('createCaseSubmit');
      if (submitBtn) {
        submitBtn.click();
      }
    });

    // Close on overlay click
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) {
        overlay.remove();
      }
    });
  }

  // Helper to update a card on the board
  function updateCardOnBoard(caseData) {
    if (!caseData || !caseData.id) return;
    var existingCards = document.querySelectorAll('.kanban-card');
    existingCards.forEach(function(card) {
      try {
        var cardData = JSON.parse(card.dataset.caseJson || '{}');
        if (cardData.id === caseData.id) {
          card.remove();
        }
      } catch(e) {}
    });
    if (typeof window.addCaseToKanban === 'function') {
      window.addCaseToKanban(caseData);
      if (typeof window.updateColumnCounts === 'function') {
        window.updateColumnCounts();
      }
    }
  }

  // Efficient old card removal
  function removeOldCard(caseId) {
    var caseCards = document.querySelectorAll('.kanban-card');
    for (var i = 0; i < caseCards.length; i++) {
      try {
        var cardData = JSON.parse(caseCards[i].dataset.caseJson || '{}');
        if (cardData.id === caseId) {
          caseCards[i].remove();
          break;
        }
      } catch (e) {
        continue;
      }
    }
  }

  // Enhanced card addition with animation
  function addCaseToKanbanWithAnimation(caseData) {
    var card = addCaseToKanban(caseData);
    if (card) {
      // Add entrance animation
      card.classList.add('card-entrance');
      setTimeout(() => card.classList.remove('card-entrance'), 600);
    }
  }

  // Reset form and close modal
  function resetFormAndClose(form, submitBtn, isUpdate) {
    hasUnsavedChanges = false;
    originalFormData = null;
    isSubmitting = false;
    submitBtn.disabled = false;
    submitBtn.classList.remove('success');
    submitBtn.innerHTML = isUpdate ? t('cases.update_case') : t('cases.create_case');

    closeCreateCase();
    form.reset();
    clearFileSelections();
  }

  // Allow pressing Enter in the Create Case modal to trigger the Create Case action,
  // while still allowing newlines in textarea fields and respecting button focus.
  var createCaseForm = document.getElementById('createCaseForm');
  if (createCaseForm && submitBtn) {
    createCaseForm.addEventListener('keydown', function(event) {
      if (event.key !== 'Enter') {
        return;
      }

      var target = event.target;
      var tagName = target && target.tagName ? target.tagName.toLowerCase() : '';

      // Do not intercept Enter inside textareas so users can add newlines
      if (tagName === 'textarea') {
        return;
      }

      // If focus is on a button, let that button handle the Enter key
      if (tagName === 'button' || (tagName === 'input' && target.type === 'button') || target.type === 'submit') {
        return; // Let the button handle its own click event
      }

      // If focus is on a file button label (Select Files), let it handle the Enter key
      if (tagName === 'label' && target.classList.contains('file-button')) {
        return; // Let the file button open the file dialog
      }

      // Only handle Enter when the Create Case modal is actually open
      if (!createCaseModal || createCaseModal.style.display !== 'block') {
        return;
      }

      event.preventDefault();
      submitBtn.click();
    });
  }

  // Delete confirmation modal functionality
  var deleteConfirmModal = document.getElementById('deleteConfirmModal');
  var deleteConfirmClose = document.getElementById('deleteConfirmClose');
  var deleteConfirmCancel = document.getElementById('deleteConfirmCancel');
  var deleteConfirmDelete = document.getElementById('deleteConfirmDelete');
  var deleteConfirmMessage = document.querySelector('.delete-confirm-message');

  // Current file being deleted
  var currentDeletingFile = null;
  var currentDeletingElement = null;
  var currentDeleteCallback = null;

  function showDeleteConfirmation(fileElement, fileName, onConfirm) {
    // Check if user has opted to skip the confirmation
    if (localStorage.getItem('skip_archive_confirmation') === 'true') {
      if (onConfirm) {
        onConfirm();
      }
      return;
    }

    // Create a simple confirmation modal
    const modal = document.createElement('div');
    modal.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999999;
    `;

    const content = document.createElement('div');
    content.style.cssText = `
      background: white;
      padding: 30px;
      border-radius: 8px;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    `;

    content.innerHTML = `
      <h3 style="margin: 0 0 15px 0; color: #f44336;">${t('archive.confirm.archive_title')}</h3>
      <p style="margin: 0 0 20px 0; color: #333;">${t('archive.confirm.archive_message', {name: fileName})}</p>
      <p style="margin: 0 0 20px 0; color: #666; font-size: 14px;">${t('archive.confirm.archive_undone')}</p>
      <label style="display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0 0 25px 0; color: #666; font-size: 13px; cursor: pointer;">
        <input type="checkbox" id="dontShowAgainCheckbox" style="cursor: pointer;">
        ${t('archive.dont_show_again')}
      </label>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button id="cancelBtn" style="
          background: #e0e0e0;
          color: #333;
          border: none;
          padding: 8px 20px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('common.cancel')}</button>
        <button id="confirmBtn" style="
          background: #f44336;
          color: white;
          border: none;
          padding: 8px 20px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('common.archive')}</button>
      </div>
    `;

    modal.appendChild(content);
    document.body.appendChild(modal);

    // Get button references
    const cancelBtn = document.getElementById('cancelBtn');
    const confirmBtn = document.getElementById('confirmBtn');
    const dontShowCheckbox = document.getElementById('dontShowAgainCheckbox');

    // Focus on the Archive button when modal opens
    setTimeout(() => {
      confirmBtn.focus();
    }, 100);

    // Add event listeners
    cancelBtn.onclick = () => {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', tabHandler);
      document.removeEventListener('keydown', escapeHandler);
      document.removeEventListener('keydown', enterHandler);
    };

    confirmBtn.onclick = () => {
      // Save preference if checkbox is checked
      if (dontShowCheckbox && dontShowCheckbox.checked) {
        localStorage.setItem('skip_archive_confirmation', 'true');
      }
      document.body.removeChild(modal);
      document.removeEventListener('keydown', tabHandler);
      document.removeEventListener('keydown', escapeHandler);
      document.removeEventListener('keydown', enterHandler);
      if (onConfirm) {
        onConfirm();
      }
    };

    // Tab trapping - only allow tabbing between the two buttons
    const tabHandler = (e) => {
      if (e.key === 'Tab') {
        e.preventDefault();
        // If focus is on cancel, move to archive
        if (document.activeElement === cancelBtn) {
          confirmBtn.focus();
        } else {
          // If focus is on archive or anything else, move to cancel
          cancelBtn.focus();
        }
      }
    };

    // Close on background click
    modal.onclick = (e) => {
      if (e.target === modal) {
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);
      }
    };

    // Close on Escape key
    const escapeHandler = (e) => {
      if (e.key === 'Escape') {
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);
      }
    };

    // Enter key triggers Archive
    const enterHandler = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault(); // Prevent form submission if any
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);

        // Execute the archive callback after modal is removed
        if (onConfirm) {
          setTimeout(() => onConfirm(), 0);
        }
      }
    };

    document.addEventListener('keydown', tabHandler);
    document.addEventListener('keydown', escapeHandler);
    document.addEventListener('keydown', enterHandler);
  }

  function closeDeleteConfirmation() {
    if (deleteConfirmModal) {
      deleteConfirmModal.style.display = 'none';
    }
    currentDeletingFile = null;
    currentDeletingElement = null;
    currentDeleteCallback = null;
  }

  // Wire up confirmation dialog event listeners
  if (deleteConfirmClose) deleteConfirmClose.addEventListener('click', closeDeleteConfirmation);
  if (deleteConfirmCancel) deleteConfirmCancel.addEventListener('click', closeDeleteConfirmation);

  // Handle the delete confirmation
  if (deleteConfirmDelete) {
    deleteConfirmDelete.addEventListener('click', function() {
      if (currentDeleteCallback && typeof currentDeleteCallback === 'function') {
        currentDeleteCallback();
      }
      closeDeleteConfirmation();
    });
  }

  // Close modal when clicking outside of it
  window.addEventListener('click', function(e) {
    if (e.target === deleteConfirmModal) closeDeleteConfirmation();
  });

  // Add escape key handler for delete confirmation
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && deleteConfirmModal && deleteConfirmModal.style.display === 'block') {
      closeDeleteConfirmation();
    }
  });

  // Escape key handler for the Mobile Restricted Modal (Settings/Billing on phones)
  document.addEventListener('keydown', function(event) {
    var mrModal = document.getElementById('mobileRestrictedModal');
    if (event.key === 'Escape' && mrModal && mrModal.style.display === 'block') {
      mrModal.style.display = 'none';
    }
  });

  // Function to ensure file delete buttons are visible and working
  function ensureFileDeleteButtons() {
    document.querySelectorAll('.file-remove').forEach(function(button) {
      // Remove any inline styles that might interfere with CSS
      button.removeAttribute('style');

      // Clear any inner HTML - we're using CSS ::before for the X
      button.innerHTML = '';
    });
  }

  // Function to update file count display
  function updateFileCountDisplay() {
    // This function updates any UI elements that show the file count
    document.querySelectorAll('.selected-files').forEach(function(container) {
      // Count files in this container
      var fileCount = container.querySelectorAll('.selected-file').length;

      // Get the attachment type from data attribute
      var type = container.dataset.type || '';

      // Track attachment count

      // Update any UI elements that show counts (if they exist)
      // For example, if there were badges showing file counts
      var countBadge = document.querySelector('.file-count-badge[data-type="' + type + '"]');
      if (countBadge) {
        countBadge.textContent = fileCount;
        countBadge.style.display = fileCount > 0 ? 'inline-block' : 'none';
      }
    });
  }

  // Handle conditional fields in the form
  var caseTypeSelect = document.getElementById('caseType');
  var materialElement = document.getElementById('material');
  var materialField = materialElement ? materialElement.closest('.form-field') : null;

  if (caseTypeSelect && materialField) {
    // Case types that require the material field
    var caseTypesRequiringMaterial = [
      "Crown", "Bridge", "Implant", "AOX", "Veneer", "Inlay/Onlay"
    ];

    function updateMaterialVisibility() {
      var selectedCaseType = caseTypeSelect.value;
      var requiresMaterial = caseTypesRequiringMaterial.includes(selectedCaseType);

      // Show/hide the material field based on case type
      materialField.style.display = requiresMaterial ? 'block' : 'none';
      document.getElementById('material').required = requiresMaterial;
    }

    // Set initial visibility
    updateMaterialVisibility();

    // Update when case type changes
    caseTypeSelect.addEventListener('change', updateMaterialVisibility);
  }

  // Function to calculate days in current status
  function getDaysInStatus(statusChangedAt) {
    if (!statusChangedAt) return t('common.not_applicable');

    try {
      var changedDate = new Date(statusChangedAt);
      if (isNaN(changedDate.getTime())) return t('common.not_applicable');

      var now = new Date();
      var diffTime = now.getTime() - changedDate.getTime();
      var diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));

      if (diffDays === 0) return t('common.today');
      return I18n.pluralize(diffDays, 'cases.status_days');
    } catch (e) {
      return t('common.not_applicable');
    }
  }

  // Function to format dates
  function formatDate(dateString, includeTime) {
    if (!dateString) return t('common.not_applicable');

    try {
      var date;

      // For date-only strings (YYYY-MM-DD), treat as local date to avoid timezone issues
      if (dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
        var parts = dateString.split('-');
        date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
      } else {
        date = new Date(dateString);
      }

      // Check if date is valid
      if (isNaN(date.getTime())) {
        return t('common.invalid_date');
      }

      var options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
      };

      if (includeTime) {
        options.hour = '2-digit';
        options.minute = '2-digit';
      }

      return I18n.formatDate(date, includeTime ? { style: 'medium', timeStyle: 'short' } : { style: 'medium' });
    } catch (e) {
      return t('common.invalid_date');
    }
  }

  /**
   * Compute the calendar-day difference between a due date and today (local).
   * Positive = due in the future, 0 = due today, negative = past due.
   * Handles date-only strings as local calendar dates to avoid timezone shifts.
   */
  function getCalendarDayDiff(dueDateString) {
    if (!dueDateString) return null;

    try {
      var due;
      if (dueDateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
        var parts = dueDateString.split('-');
        due = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      } else {
        due = new Date(dueDateString);
      }

      if (isNaN(due.getTime())) return null;
      due.setHours(0, 0, 0, 0);

      var today = new Date();
      today.setHours(0, 0, 0, 0);

      // Use round so DST transitions don't push a true integer-day diff up/down
      return Math.round((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    } catch (e) {
      return null;
    }
  }

  /**
   * Build the human-readable due warning badge text for the Kanban card.
   * Returns '' when the case should display its normal due date with no badge.
   * Only applies to future/today dates; past-due cases are handled separately.
   */
  function getDueWarningText(daysUntil) {
    if (daysUntil === null || daysUntil === undefined) return '';
    if (daysUntil < 0) return '';

    if (daysUntil > 1) return I18n.pluralize(daysUntil, 'cases.due.in_days');
    if (daysUntil === 1) return t('cases.due.tomorrow');
    if (daysUntil === 0) return t('cases.due.today');
    return '';
  }

  // Initialize drag-and-drop for Kanban board
  function initKanbanDragDrop() {
    // Disable drag-and-drop if trial expired
    if (billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
      return;
    }

    const kanbanCards = document.querySelectorAll('.kanban-card');
    const kanbanColumns = document.querySelectorAll('.kanban-column-body');

    // Make all existing cards draggable
    kanbanCards.forEach(card => {
      card.setAttribute('draggable', 'true');
      addDragListeners(card);
    });

    // Add drop targets to all columns
    kanbanColumns.forEach(column => {
      column.addEventListener('dragover', e => {
        e.preventDefault(); // Allow drop
        column.classList.add('drag-over');
      });

      column.addEventListener('dragleave', e => {
        column.classList.remove('drag-over');
      });

      column.addEventListener('drop', e => {
        e.preventDefault();
        column.classList.remove('drag-over');

        // Get the dragged card ID and data
        const cardId = e.dataTransfer.getData('text/plain');
        const draggedCard = document.getElementById(cardId);

        if (!draggedCard) return;

        // Get the column's internal status from its fixed data-status
        // attribute - NOT from the column header's visible text, which
        // will become practice-customizable and must never determine the
        // persisted status.
        const columnEl = column.closest('.kanban-column');
        const newStatus = columnEl ? columnEl.dataset.status : '';
        if (!newStatus) return;

        // Get card data
        let cardData;
        try {
          cardData = JSON.parse(draggedCard.dataset.caseJson);
        } catch (e) {
          // Handle parse error
          return;
        }

        // Only update if the status is actually changing
        if (cardData.status === newStatus) return;

        // Update the card's status via API
        updateCardStatus(draggedCard, cardData, newStatus, column);
      });
    });
  }

  // Add drag event listeners to a card
  function addDragListeners(card) {
    // Generate a unique ID if the card doesn't have one
    if (!card.id) {
      card.id = 'case-' + Math.random().toString(36).substring(2, 9);
    }

    // Track initial mouse position for drag direction
    var dragStartX = 0;

    card.addEventListener('mousedown', e => {
      dragStartX = e.clientX;
    });

    card.addEventListener('dragstart', e => {
      // Check if any case is currently being printed
      if (window.isPrintingCase) {
        e.preventDefault();
        return false;
      }

      e.dataTransfer.setData('text/plain', card.id);
      card.classList.add('dragging');

      // Store the start position for direction detection
      card.dataset.dragStartX = dragStartX;

      // Set drag effect
      e.dataTransfer.effectAllowed = 'move';
    });

    card.addEventListener('drag', e => {
      // Update tilt direction based on current mouse position vs start
      if (e.clientX === 0) return; // Ignore when drag ends (clientX becomes 0)

      var startX = parseInt(card.dataset.dragStartX) || 0;
      var currentX = e.clientX;

      if (currentX < startX - 10) {
        // Dragging left
        card.classList.remove('dragging-right');
        card.classList.add('dragging-left');
      } else if (currentX > startX + 10) {
        // Dragging right
        card.classList.remove('dragging-left');
        card.classList.add('dragging-right');
      }
    });

    card.addEventListener('dragend', e => {
      card.classList.remove('dragging');
      card.classList.remove('dragging-left');
      card.classList.remove('dragging-right');
      delete card.dataset.dragStartX;
    });
  }

  // Update card status via API (optimized for performance)
  function updateCardStatus(card, cardData, newStatus, targetColumn) {
    // Cache DOM elements to avoid repeated queries
    const originalColumn = card.closest('.kanban-column-body');
    const originalColumnContainer = originalColumn ? originalColumn.closest('.kanban-column') : null;
    const originalCountBadge = originalColumnContainer ? originalColumnContainer.querySelector('.kanban-column-count') : null;
    const targetColumnContainer = targetColumn.closest('.kanban-column');
    const targetCountBadge = targetColumnContainer ? targetColumnContainer.querySelector('.kanban-column-count') : null;

    // Cache original values
    const originalCount = originalCountBadge ? parseInt(originalCountBadge.textContent) || 0 : 0;
    const targetCount = targetCountBadge ? parseInt(targetCountBadge.textContent) || 0 : 0;
    const previousStatus = cardData.status;
    const previousLastUpdateDate = cardData.lastUpdateDate;
    const previousStatusClass = getWorkflowStatusCssClass(previousStatus);

    // Fast optimistic UI updates - batch DOM operations
    requestAnimationFrame(() => {
      // Update counts
      if (originalCountBadge) {
        originalCountBadge.textContent = Math.max(0, originalCount - 1);
      }
      if (targetCountBadge) {
        targetCountBadge.textContent = targetCount + 1;
      }

      // Handle empty states efficiently
      const originalEmpty = originalColumn.querySelector('.kanban-empty');
      const targetEmpty = targetColumn.querySelector('.kanban-empty');

      if (originalCount - 1 === 0 && !originalEmpty) {
        const emptyMsg = document.createElement('p');
        emptyMsg.className = 'kanban-empty';
        emptyMsg.textContent = t('cases.no_cases_in_stage');
        originalColumn.appendChild(emptyMsg);
      } else if (originalCount - 1 > 0 && originalEmpty) {
        originalEmpty.remove();
      }

      if (targetEmpty) {
        targetEmpty.remove();
      }

      // Move card immediately
      const firstCardInColumn = targetColumn.querySelector('.kanban-card');
      if (firstCardInColumn && firstCardInColumn !== card) {
        targetColumn.insertBefore(card, firstCardInColumn);
      } else {
        targetColumn.appendChild(card);
      }

      // Add visual feedback
      card.classList.add('updating');
    });

    // Prepare API data
    const updateData = {
      caseId: cardData.id,
      status: newStatus,
      driveFolderId: cardData.driveFolderId,
      version: cardData.version || null  // Include version for optimistic locking
    };

    // Fast async API call with timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout

    fetch('api/update-case-status.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(updateData),
      credentials: 'same-origin',
      signal: controller.signal
    })
    .then(response => {
      clearTimeout(timeoutId);
      if (!response.ok) {
        // Handle 409 Conflict (concurrent edit)
        if (response.status === 409) {
          return response.json().then(data => {
            var conflictError = new Error(data.message || 'This case was modified by another user.');
            conflictError.conflict = true;
            conflictError.currentData = data.currentData;
            throw conflictError;
          });
        }
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        // Update card data efficiently
        requestAnimationFrame(() => {
          cardData.status = newStatus;
          cardData.lastUpdateDate = data.caseData.lastUpdateDate;

          // Update version for optimistic locking
          if (data.caseData.version !== undefined) {
            cardData.version = data.caseData.version;
          } else if (data.newVersion !== undefined) {
            cardData.version = data.newVersion;
          }

          // Update revision count if returned (backward move)
          if (data.caseData.revisionCount !== undefined) {
            cardData.revisionCount = data.caseData.revisionCount;

            // Update revision count line on card if feature flag enabled
            if (window.featureFlags && window.featureFlags.SHOW_REVISION_COUNT) {
              const revisionCountLine = card.querySelector('.revision-count-line');
              const revisionCount = cardData.revisionCount;
              if (revisionCount > 0) {
                if (revisionCountLine) {
                  revisionCountLine.textContent = t('cases.revisions_count', {count: revisionCount});
                } else {
                  // Create revision count line if it doesn't exist
                  const cardHeader = card.querySelector('.kanban-card-header');
                  if (cardHeader) {
                    const newRevisionLine = document.createElement('div');
                    newRevisionLine.className = 'revision-count-line';
                    newRevisionLine.textContent = t('cases.revisions_count', {count: revisionCount});
                    cardHeader.appendChild(newRevisionLine);
                  }
                }
              }
            }
          }

          card.dataset.caseJson = JSON.stringify(cardData);

          // Update status class
          card.classList.remove(previousStatusClass);
          card.classList.add(getWorkflowStatusCssClass(newStatus));

          // Update date display
          const dateValue = card.querySelector('.date-value:last-child');
          if (dateValue) {
            dateValue.textContent = formatDate(cardData.lastUpdateDate, false);
          }

          // Apply highlighting
          applyPastDueHighlighting(cardData);

          // Add success feedback
          card.classList.add('update-success');
          setTimeout(() => card.classList.remove('update-success'), 600);

          // Remove updating class after a short delay
          setTimeout(() => card.classList.remove('updating'), 300);
        });
      } else {
        throw new Error(data.message || 'Update failed');
      }
    })
    .catch(error => {
      // Fast rollback on error
      requestAnimationFrame(() => {
        // Move card back
        if (originalColumn) {
          originalColumn.appendChild(card);
        }

        // Restore counts
        if (originalCountBadge) originalCountBadge.textContent = originalCount;
        if (targetCountBadge) targetCountBadge.textContent = targetCount;

        // Restore empty states
        if (originalCount === 0) {
          const emptyMsg = document.createElement('p');
          emptyMsg.className = 'kanban-empty';
          emptyMsg.textContent = t('cases.no_cases_in_stage');
          originalColumn.appendChild(emptyMsg);
        }
        if (targetCount === 0) {
          const emptyMsg = document.createElement('p');
          emptyMsg.className = 'kanban-empty';
          emptyMsg.textContent = t('cases.no_cases_in_stage');
          targetColumn.appendChild(emptyMsg);
        }

        // Restore card data
        cardData.status = previousStatus;
        cardData.lastUpdateDate = previousLastUpdateDate;
        card.dataset.caseJson = JSON.stringify(cardData);
        card.classList.remove(getWorkflowStatusCssClass(newStatus));
        card.classList.add(previousStatusClass);

        // Restore date
        const dateValue = card.querySelector('.date-value:last-child');
        if (dateValue && previousLastUpdateDate) {
          dateValue.textContent = formatDate(previousLastUpdateDate, false);
        }

        applyPastDueHighlighting(cardData);
        card.classList.remove('updating');

        // Add error feedback
        card.classList.add('update-error');
        setTimeout(() => card.classList.remove('update-error'), 500);

        // Show appropriate error message
        if (error.conflict) {
          showToast(t('cases.toast.concurrent_edit'), 'warning');
          // If we have current data, update the card with it
          if (error.currentData) {
            cardData.status = error.currentData.status || previousStatus;
            cardData.version = error.currentData.version;
            cardData.lastUpdateDate = error.currentData.lastUpdateDate || previousLastUpdateDate;
            card.dataset.caseJson = JSON.stringify(cardData);

            // Move card to the column matching the server-authoritative
            // status, found via the fixed data-status attribute (never
            // visible column-header text). The card container is
            // '.kanban-column-body' - the same class addCaseToKanban()
            // appends new cards into - not '.kanban-cards', which never
            // matched anything and silently no-op'd this repositioning.
            var correctColumn = document.querySelector('.kanban-column[data-status="' + cardData.status + '"] .kanban-column-body');
            if (correctColumn && correctColumn !== card.parentNode) {
              correctColumn.appendChild(card);
            }

            // The status-color class was just reset to previousStatusClass
            // above; if the server-authoritative status differs from that
            // (e.g. a third party moved the case elsewhere), correct it to
            // match cardData.status so styling stays accurate.
            card.classList.remove(previousStatusClass);
            card.classList.add(getWorkflowStatusCssClass(cardData.status));
          }
        } else {
          showToast(t('cases.toast.status_update_failed'), 'error');
        }
      });
    });
  }

  // Function to add a new case to the appropriate Kanban column
  function addCaseToKanban(caseData) {
    // Find the appropriate column based on the case's internal status,
    // matched against each column's fixed data-status attribute - never
    // against the column header's visible (and later practice-
    // customizable) text.
    var status = caseData.status;
    var columns = document.querySelectorAll('.kanban-column');
    var targetColumn = null;

    columns.forEach(function(column) {
      if (column.dataset.status === status) {
        targetColumn = column;
      }
    });


    if (targetColumn) {
      // Remove the 'No cases in this stage' message if present
      var emptyMessage = targetColumn.querySelector('.kanban-empty');
      if (emptyMessage) {
        emptyMessage.remove();
      }

      // Update the count badge
      var countBadge = targetColumn.querySelector('.kanban-column-count');
      if (countBadge) {
        var currentCount = parseInt(countBadge.textContent) || 0;
        countBadge.textContent = currentCount + 1;
      }

      // Create a new case card
      var caseCard = document.createElement('div');
      caseCard.className = 'kanban-card';

      // Add class based on status for colored left border
      var statusClass = getWorkflowStatusCssClass(status);
      caseCard.classList.add(statusClass);

      // Check if past due and add class immediately to prevent CLS
      var highlightPastDue = localStorage.getItem('highlight_past_due') === 'true';
      var highlightComingDue = localStorage.getItem('highlight_coming_due') === 'true';
      var highlightAppointmentRisk = localStorage.getItem('highlight_appointment_risk') === 'true';
      var isPastDue = false;
      var isComingDue = false;
      var isAppointmentRisk = false;
      var dueIndicatorText = '';
      var apptRiskText = '';
      var dueDayDiff = null;
      var apptDayDiff = null;

      // Past Due (red) takes top precedence
      if (status !== 'Delivered' && caseData.dueDate) {
        var pastDueDays = parseInt(localStorage.getItem('past_due_days') || '1', 10);
        dueDayDiff = getCalendarDayDiff(caseData.dueDate);

        if (dueDayDiff !== null) {
          if (highlightPastDue && dueDayDiff <= -pastDueDays) {
            caseCard.classList.add('kanban-card-past-due');
            isPastDue = true;
            dueIndicatorText = ' ' + t('cases.due.late');
          }
        }
      }

      // Appointment Risk (purple) if not already Late
      // Outranks Coming Due so an appointment-within-threshold case is purple
      // even when it is also within the coming-due window.
      if (!isPastDue && status !== 'Delivered' && caseData.patientAppointmentDate && highlightAppointmentRisk) {
        var appointmentRiskDays = parseInt(localStorage.getItem('appointment_risk_days') || '3', 10);
        apptDayDiff = getCalendarDayDiff(caseData.patientAppointmentDate);
        if (apptDayDiff !== null && apptDayDiff <= appointmentRiskDays) {
          caseCard.classList.add('kanban-card-appointment-risk');
          isAppointmentRisk = true;
          apptRiskText = t('cases.risk.appointment_abbreviation');
        }
      }

      // Coming Due (blue) if not already Late or Appointment Risk
      if (!isPastDue && !isAppointmentRisk && status !== 'Delivered' && caseData.dueDate) {
        var comingDueDays = parseInt(localStorage.getItem('coming_due_days') || '5', 10);
        dueDayDiff = dueDayDiff !== null ? dueDayDiff : getCalendarDayDiff(caseData.dueDate);
        if (dueDayDiff !== null && dueDayDiff >= 0 && dueDayDiff <= comingDueDays) {
          caseCard.classList.add('kanban-card-coming-due');
          isComingDue = true;
          dueIndicatorText = ' ' + getDueWarningText(dueDayDiff);
        }
      }

      // Create a separate copy of attachments first for clarity
      var attachmentsCopy = [];
      if (Array.isArray(caseData.attachments) && caseData.attachments.length > 0) {
        attachmentsCopy = JSON.parse(JSON.stringify(caseData.attachments));
      } else if (typeof caseData.attachments === 'string') {
        try {
          // Try to parse if it's a JSON string
          attachmentsCopy = JSON.parse(caseData.attachments);
        } catch(e) {
          // Failed to parse attachments
          attachmentsCopy = [];
        }
      }

      // Ensure we have all required fields in the case data
      var completeData = {
        id: caseData.id || ('temp_' + Date.now()),
        patientFirstName: caseData.patientFirstName || '',
        patientLastName: caseData.patientLastName || '',
        patientDOB: caseData.patientDOB || '',
        patientGender: caseData.patientGender || '',
        dentistName: caseData.dentistName || '',
        caseType: caseData.caseType || '',
        toothShade: caseData.toothShade || '',
        material: caseData.material || '',
        dueDate: caseData.dueDate || '',
        patientAppointmentDate: caseData.patientAppointmentDate || '',
        status: status,
        statusChangedAt: caseData.statusChangedAt || new Date().toISOString(),
        notes: caseData.notes || '',
        carrier: caseData.carrier || '',
        trackingNumber: caseData.trackingNumber || '',
        customCarrier: caseData.customCarrier || '',
        creationDate: caseData.creationDate || new Date().toISOString(),
        lastUpdateDate: caseData.lastUpdateDate || new Date().toISOString(),
        driveFolderId: caseData.driveFolderId || null,
        attachments: attachmentsCopy,
        assignedTo: caseData.assignedTo || '',
        atRisk: caseData.atRisk || { isAtRisk: false, reasons: [] },
        clinicalDetails: caseData.clinicalDetails || null,
        revisionCount: caseData.revisionCount || 0,
        version: caseData.version || 1,
        createdByUserId: caseData.createdByUserId || null,
        createdByName: caseData.createdByName || 'Unknown'
      };

      // Assignment info stored in completeData.assignedTo

      // Store complete case data as a data attribute (JSON string)
      // Ensure data is properly formatted for display
      var displayData = {
        id: completeData.id || ('temp_' + Date.now()),
        patientFirstName: completeData.patientFirstName || '',
        patientLastName: completeData.patientLastName || '',
        patientDOB: completeData.patientDOB || '',
        dentistName: completeData.dentistName || '',
        caseType: completeData.caseType || '',
        toothShade: completeData.toothShade || '',
        material: completeData.material || '',
        dueDate: completeData.dueDate || '',
        patientAppointmentDate: completeData.patientAppointmentDate || '',
        status: status,
        statusChangedAt: completeData.statusChangedAt || new Date().toISOString(),
        notes: completeData.notes || '',
        carrier: completeData.carrier || '',
        trackingNumber: completeData.trackingNumber || '',
        customCarrier: completeData.customCarrier || '',
        creationDate: completeData.creationDate || new Date().toISOString(),
        lastUpdateDate: completeData.lastUpdateDate || new Date().toISOString(),
        driveFolderId: completeData.driveFolderId || null,
        attachments: attachmentsCopy,
        assignedTo: completeData.assignedTo || '',
        atRisk: completeData.atRisk || { isAtRisk: false, reasons: [] },
        patientGender: completeData.patientGender || '',
        clinicalDetails: completeData.clinicalDetails || null,
        revisionCount: completeData.revisionCount || 0,
        version: completeData.version || 1,
        createdByUserId: completeData.createdByUserId || null,
        createdByName: completeData.createdByName || 'Unknown'
      };
      caseCard.dataset.caseJson = JSON.stringify(displayData);

      var creationDate = caseData.creationDate || new Date().toISOString();
      var lastUpdateDate = caseData.lastUpdateDate || new Date().toISOString();

      // Get assignment information if any
      var assignedEmail = completeData.assignedTo || '';

      // Build attachment indicator HTML (only if feature flag enabled)
      var attachmentIndicatorHtml = '';
      if (window.featureFlags && window.featureFlags.SHOW_ATTACHMENT_COUNT && attachmentsCopy && attachmentsCopy.length > 0) {
        var tooltipLines = [];
        var fileTypes = { photos: 0, xrays: 0, documents: 0, other: 0 };
        attachmentsCopy.forEach(function(att) {
          var type = att.type || 'other';
          if (type === 'photo' || type === 'photos') fileTypes.photos++;
          else if (type === 'xray' || type === 'xrays') fileTypes.xrays++;
          else if (type === 'document' || type === 'documents') fileTypes.documents++;
          else fileTypes.other++;
        });
        if (fileTypes.photos > 0) tooltipLines.push(I18n.pluralize(fileTypes.photos, 'attachments.photo'));
        if (fileTypes.xrays > 0) tooltipLines.push(I18n.pluralize(fileTypes.xrays, 'attachments.xray'));
        if (fileTypes.documents > 0) tooltipLines.push(I18n.pluralize(fileTypes.documents, 'attachments.document'));
        if (fileTypes.other > 0) tooltipLines.push(I18n.pluralize(fileTypes.other, 'attachments.file'));
        var tooltipText = tooltipLines.join('&#10;');
        attachmentIndicatorHtml = '<span class="attachment-indicator" title="' + tooltipText + '">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>' +
          '</svg>' +
          '<span class="attachment-count">' + attachmentsCopy.length + '</span>' +
          '</span>';
      }

      // Build At Risk indicator HTML (only if feature flag enabled)
      var atRiskHtml = '';
      var showAtRisk = window.featureFlags && window.featureFlags.SHOW_AT_RISK;
      if (showAtRisk && displayData.atRisk && displayData.atRisk.isAtRisk && displayData.atRisk.reasons && displayData.atRisk.reasons.length > 0) {
        // Use native title attribute for tooltip (won't be clipped by overflow:hidden)
        var reasonsTooltip = displayData.atRisk.reasons.join('\n');
        // Escape HTML entities for the title attribute
        var escapedTooltip = reasonsTooltip.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        atRiskHtml = '<div class="at-risk-indicator" title="' + escapedTooltip + '">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>' +
          '<line x1="12" y1="9" x2="12" y2="13"></line>' +
          '<line x1="12" y1="17" x2="12.01" y2="17"></line>' +
          '</svg>' +
          '<span>' + t('cases.risk.at_risk') + '</span>' +
          '</div>';
        caseCard.classList.add('at-risk');
      }

      // Revision count (used for revision count line below patient name)
      var revisionCount = displayData.revisionCount || 0;

      // Build revision count line (below patient name, only if flag enabled)
      var revisionCountLine = '';
      var showRevisionCount = window.featureFlags && window.featureFlags.SHOW_REVISION_COUNT;
      if (showRevisionCount && revisionCount > 0) {
        revisionCountLine = '<div class="revision-count-line">' + t('cases.revisions_count', {count: revisionCount}) + '</div>';
      }

      caseCard.innerHTML =
        '<button type="button" class="kanban-card-edit" title="' + t('cases.edit_case') + '">✎</button>' +
        '<div class="kanban-card-header">' +
        '  <h3 class="kanban-card-title">' + (displayData.patientFirstName || '') + ' ' + (displayData.patientLastName || '') + '</h3>' +
        revisionCountLine +
        '</div>' +
        '<div class="kanban-card-content">' +
        '  <p><strong>' + t('cases.type') + ':</strong> ' + (getCaseTypeDisplayLabel(displayData.caseType) || '') + '</p>' +
        '  <p><strong>' + t('cases.due_label') + ':</strong> ' + formatDate(displayData.dueDate) + (dueIndicatorText ? '<span class="late-indicator">' + dueIndicatorText + '</span>' : '') + '</p>' +
        (displayData.patientAppointmentDate ? '  <p><strong>' + t('cases.patient_appointment_short') + ':</strong> ' + formatDate(displayData.patientAppointmentDate) + (apptRiskText ? '<span class="appointment-risk-indicator">' + apptRiskText + '</span>' : '') + '</p>' : '') +
        '  <p class="dentist-row"><strong>' + t('cases.dentist') + ':</strong> ' + (displayData.dentistName || '') + attachmentIndicatorHtml + '</p>' +
        '  <div class="kanban-card-assignment">' +
        '    <div class="assignment-label"><strong>' + t('cases.assigned_to') + '</strong></div>' +
        '    <div class="assignment-value" data-case-id="' + displayData.id + '">' +
        '      <select class="assignment-select" data-case-id="' + displayData.id + '" name="assignmentSelect" aria-label="' + t('cases.assign_to') + '">' +
        '        <option value="loading">' + t('common.loading') + '</option>' +
        '      </select>' +
        '    </div>' +
        '  </div>' +
        atRiskHtml +
        '</div>' +
        '<div class="kanban-card-dates">' +
        '  <div style="display: flex; justify-content: space-between; align-items: flex-start;">' +
        '    <div>' +
        '      <div><span class="date-label">' + t('cases.created') + ':</span> <span class="date-value">' + formatDate(creationDate, false) + '</span></div>' +
        '      <div><span class="date-label">' + t('cases.updated') + ':</span> <span class="date-value">' + formatDate(lastUpdateDate, false) + '</span></div>' +
        (window.featureFlags && window.featureFlags.SHOW_IN_STATUS ? '      <div><span class="date-label">' + t('cases.in_status') + ':</span> <span class="date-value days-in-status">' + getDaysInStatus(displayData.statusChangedAt) + '</span></div>' : '') +
        '    </div>' +
        '    <button type="button" class="card-delete-btn" title="' + t('archive.confirm.archive_title') + '" data-case-id="' + displayData.id + '" style="margin-left: 10px; flex-shrink: 0; position: static !important; bottom: auto !important; right: auto !important;">' +
        '      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '        <rect x="3" y="3" width="18" height="4" rx="1" ry="1"></rect>' +
        '        <path d="M5 7h14v14H5z"></path>' +
        '        <path d="M10 12h4"></path>' +
        '      </svg>' +
        '    </button>' +
        '  </div>' +
        '</div>' +
        '<div class="kanban-card-actions">' +
        '  <button type="button" class="kanban-card-print" title="' + t('common.print') + '" aria-label="' + t('common.print') + '" data-case-id="' + displayData.id + '" style="width: 100%; justify-content: center;">🖨️ ' + t('common.print') + '</button>' +
        '</div>';

      // Initialize the assignment dropdown BEFORE adding to DOM
      const assignmentSelect = caseCard.querySelector('.assignment-select');
      if (assignmentSelect) {
        // First, try to set the initial value directly before full initialization
        if (assignedEmail) {
          assignmentSelect.value = assignedEmail;
        }

        // Keep the global assignments cache in sync with THIS render's
        // actual value, including clearing it when there is no longer an
        // assignee. Previously this only ever SET the cache (never
        // cleared it), so a stale entry from an earlier assignment could
        // survive here and then override the correct (empty) value below
        // inside initializeAssignmentDropdown(), which explicitly prefers
        // window.caseAssignments[caseId] over the value passed to it.
        if (typeof window.caseAssignments === 'object') {
          if (assignedEmail) {
            window.caseAssignments[displayData.id] = assignedEmail;
          } else {
            delete window.caseAssignments[displayData.id];
          }
        }

        // Initialize the assignment dropdown immediately
        initializeAssignmentDropdown(assignmentSelect, displayData.id, assignedEmail);
      }

      // Add the case card to the TOP of the column body (as first card)
      var columnBody = targetColumn.querySelector('.kanban-column-body');
      var firstExistingCard = columnBody ? columnBody.querySelector('.kanban-card') : null;
      if (firstExistingCard) {
        columnBody.insertBefore(caseCard, firstExistingCard);
      } else if (columnBody) {
        columnBody.appendChild(caseCard);
      }

      // Add click event for the edit button
      var editButton = caseCard.querySelector('.kanban-card-edit');
      if (editButton) {
        editButton.addEventListener('click', function(e) {
          // Prevent event from propagating to parent elements
          e.stopPropagation();

          // Check if any case is currently being printed
          if (window.isPrintingCase) {
            return;
          }

          // Get case data from the data attribute
          var rawData = caseCard.dataset.caseJson;

          var cardData;
          try {
            cardData = JSON.parse(rawData);
          } catch(e) {
            // Error parsing card data
            cardData = {}; // Default empty object if parse fails
          }

          // Open the modal for editing
          editCaseHandler(cardData);
        });
      }

      // Add click event for the delete button
      var deleteButton = caseCard.querySelector('.card-delete-btn');
      if (deleteButton) {
        deleteButton.addEventListener('click', function(e) {
          // Prevent event from propagating to parent elements
          e.stopPropagation();

          // Get case data from the data attribute
          var rawData = caseCard.dataset.caseJson;

          var cardData;
          try {
            cardData = JSON.parse(rawData);
          } catch(e) {
            // Error parsing card data
            cardData = {}; // Default empty object if parse fails
          }

          // Show delete confirmation
          if (cardData.id) {
            showDeleteConfirmation(caseCard, cardData.patientFirstName + ' ' + cardData.patientLastName, function() {
              deleteCase(cardData.id, caseCard);
            });
          }
        });
      }

      // Add click event for the print button
      var printButton = caseCard.querySelector('.kanban-card-print');
      if (printButton) {
        printButton.addEventListener('click', function(e) {
          // Prevent event from propagating to parent elements
          e.stopPropagation();

          // Get case data from the data attribute
          var rawData = caseCard.dataset.caseJson;

          var cardData;
          try {
            cardData = JSON.parse(rawData);
          } catch(e) {
            // Error parsing card data
            cardData = {}; // Default empty object if parse fails
          }

          // Print the case
          printCase(cardData);
        });
      }

      // Enable double-click on the entire card to edit (excluding interactive elements)
      caseCard.addEventListener('dblclick', function(e) {
        var target = e.target;
        var tagName = target && target.tagName ? target.tagName.toLowerCase() : '';

        // Ignore double-clicks on buttons, inputs, selects, and textareas
        if (tagName === 'button' || tagName === 'input' || tagName === 'select' || tagName === 'textarea') {
          return;
        }

        // Check if any case is currently being printed
        if (window.isPrintingCase) {
          return;
        }

        var rawData = caseCard.dataset.caseJson;
        var cardData;
        try {
          cardData = JSON.parse(rawData || '{}');
        } catch (e) {
          cardData = {};
        }

        editCaseHandler(cardData);
      });

      // Make the card draggable
      caseCard.setAttribute('draggable', 'true');
      addDragListeners(caseCard);

      // Trigger the cards updated event
      window.triggerCardsUpdated();
    }
  }

  // Function to delete/archive a case
  // Note: Archiving is allowed even when trial expired (cleanup operation)
  function deleteCase(caseId, caseCard) {
    // Check if any case is currently being printed
    if (window.isPrintingCase) {
      return;
    }

    fetch('api/delete-case.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({
        caseId: caseId
      }),
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Remove the card from the DOM with animation
        if (caseCard) {
          caseCard.style.transition = 'opacity 0.3s, transform 0.3s';
          caseCard.style.opacity = '0';
          caseCard.style.transform = 'scale(0.9)';

          setTimeout(function() {
            caseCard.remove();
            // Update column count
            updateColumnCounts();
            // Trigger cards updated event
            window.triggerCardsUpdated();
            // Refresh billing info to update case count
            loadBillingInfo();
            // Update archived cases badge count
            loadArchivedCaseCount();
          }, 300);
        }

        showToast(t('cases.toast.archived_success'), 'success');
      } else {
        showToast(t('cases.toast.archive_error', {message: data.message || t('common.unknown_error')}), 'error');
      }
    })
    .catch(error => {
      if (typeof NetworkErrorHandler !== 'undefined') {
        NetworkErrorHandler.handle(error, 'archiving case');
      } else {
        showToast(t('cases.toast.archive_error_retry'), 'error');
      }
    });
  }

  // Function to update column counts
  function updateColumnCounts() {
    var columns = document.querySelectorAll('.kanban-column');
    columns.forEach(function(column) {
      var countBadge = column.querySelector('.kanban-column-count');
      var cards = column.querySelectorAll('.kanban-card');
      var visibleCount = 0;
      cards.forEach(function(card) {
        if (card.style.display !== 'none') {
          visibleCount++;
        }
      });
      if (countBadge) {
        countBadge.textContent = visibleCount;
      }
    });
  }

  // Function to handle case editing
  function editCaseHandler(caseData) {
    // Check if any case is currently being printed
    if (window.isPrintingCase) {
      return;
    }

    // Get form and modal elements
    var form = document.getElementById('createCaseForm');
    var modalTitle = document.querySelector('.modal-title');
    var submitBtn = document.getElementById('createCaseSubmit');

    // Clear any previous validation errors when starting to edit a case
    if (typeof clearCreateCaseErrors === 'function') {
      clearCreateCaseErrors();
    }

    // Update modal title to indicate editing mode
    if (modalTitle) modalTitle.textContent = t('cases.edit_case');
    if (submitBtn) submitBtn.textContent = t('cases.update_case');

    // Show revision indicator in modal header if case has revisions
    var revisionCount = caseData.revisionCount || 0;
    var existingRevisionIndicator = document.querySelector('.modal-header .case-detail-regression');
    if (existingRevisionIndicator) {
      existingRevisionIndicator.remove();
    }
    if (revisionCount > 0 && modalTitle) {
      var revisionLabel = revisionCount === 1 ? 'Revision' : 'Revisions';
      var revisionIndicator = document.createElement('span');
      revisionIndicator.className = 'case-detail-regression';
      revisionIndicator.title = t('cases.revisions_count', {count: revisionCount});
      revisionIndicator.innerHTML =
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>' +
        '<path d="M3 3v5h5"></path>' +
        '</svg>' +
        '<span>' + revisionCount + ' ' + revisionLabel + '</span>';
      modalTitle.parentNode.insertBefore(revisionIndicator, modalTitle.nextSibling);
    }

    // Ensure all form fields exist before setting values
    if (!form) {
      // Form not found
      return;
    }

    // Set form field values with thorough null checking
    if (form.patientFirstName) form.patientFirstName.value = caseData.patientFirstName || '';
    if (form.patientLastName) form.patientLastName.value = caseData.patientLastName || '';

    // Handle DOB date carefully
    if (form.patientDOB && caseData.patientDOB) {
      try {
        var dobDate = new Date(caseData.patientDOB);
        // Check if date is valid before setting
        if (!isNaN(dobDate.getTime())) {
          form.patientDOB.value = dobDate.toISOString().split('T')[0];
        }
      } catch (e) {
        // Error formatting DOB
      }
    }

    // Set patient gender
    if (form.patientGender) form.patientGender.value = caseData.patientGender || '';

    if (form.dentistName) form.dentistName.value = caseData.dentistName || '';
    if (form.caseType) form.caseType.value = caseData.caseType || '';
    if (form.toothShade) form.toothShade.value = caseData.toothShade || '';

    // Trigger material field visibility update
    if (typeof updateMaterialVisibility === 'function') {
      updateMaterialVisibility();
    }

    // Set material value after visibility update
    if (form.material) form.material.value = caseData.material || '';

    // Handle due date carefully
    if (form.dueDate && caseData.dueDate) {
      try {
        var dueDate = new Date(caseData.dueDate);
        // Check if date is valid before setting
        if (!isNaN(dueDate.getTime())) {
          form.dueDate.value = dueDate.toISOString().split('T')[0];
        }
      } catch (e) {
        // Error formatting due date
      }
    }

    // Handle patient appointment date carefully
    if (form.patientAppointmentDate) {
      var apptDateValue = caseData.patientAppointmentDate || caseData.patient_appointment_date || '';
      if (apptDateValue) {
        // Prefer the leading YYYY-MM-DD portion when present to avoid
        // timezone/offset shifts changing the calendar day.
        var apptMatch = String(apptDateValue).match(/^(\d{4}-\d{2}-\d{2})/);
        if (apptMatch) {
          form.patientAppointmentDate.value = apptMatch[1];
        } else {
          try {
            var apptDate = new Date(apptDateValue);
            if (!isNaN(apptDate.getTime())) {
              form.patientAppointmentDate.value = apptDate.toISOString().split('T')[0];
            }
          } catch (e) {
            // Error formatting appointment date
          }
        }
      }
    }

    if (form.status) form.status.value = caseData.status || '';
    if (form.notes) form.notes.value = caseData.notes || '';
    if (form.carrier) form.carrier.value = caseData.carrier || '';
    if (form.customCarrier) form.customCarrier.value = caseData.customCarrier || '';
    if (form.trackingNumber) form.trackingNumber.value = caseData.trackingNumber || '';
    toggleCustomCarrierField();
    updateTrackingNumberLink();

    // Load clinical details if available
    var clinicalDetails = caseData.clinicalDetails || caseData.clinical_details || null;
    var caseTypeValue = caseData.caseType || '';
    if (typeof setClinicalDetailsData === 'function') {
      setClinicalDetailsData(clinicalDetails, caseTypeValue);
    }

    // Store the case ID for update handling
    form.dataset.caseId = caseData.id || '';

    // Store the drive folder ID for update handling
    if (caseData.driveFolderId) {
      form.dataset.driveFolderId = caseData.driveFolderId;
    }

    // Store the version for optimistic locking (concurrent edit detection)
    if (caseData.version) {
      form.dataset.caseVersion = caseData.version;
    }

    // Store original case data for conflict detection
    // This allows us to detect TRUE conflicts (same field changed by both users)
    form.dataset.originalCaseData = JSON.stringify({
      patientFirstName: caseData.patientFirstName || '',
      patientLastName: caseData.patientLastName || '',
      status: caseData.status || '',
      dentistName: caseData.dentistName || '',
      caseType: caseData.caseType || '',
      toothShade: caseData.toothShade || '',
      material: caseData.material || '',
      dueDate: caseData.dueDate || '',
      notes: caseData.notes || ''
    });

    // Set the assigned to dropdown (if it exists)
    if (form.assignedTo) {
      // Store the current assignee (might be empty)
      var currentAssignee = caseData.assignedTo || '';

      // Manually trigger initializeAssignmentDropdown if it exists
      if (typeof initializeAssignmentDropdown === 'function') {
        setTimeout(function() {
          initializeAssignmentDropdown(form.assignedTo, caseData.id, currentAssignee);
        }, 100);
      } else {
        // Fallback if function not available
        form.assignedTo.value = currentAssignee;
      }
    }

    // Clear file selections
    clearFileSelections();

    // Display existing attachments if any
    if (caseData.attachments && Array.isArray(caseData.attachments) && caseData.attachments.length > 0) {
      // Group attachments by type
      var attachmentsByType = {};
      caseData.attachments.forEach(function(attachment) {
        // The type from API (Photos, IntraoralScans) may not match our HTML IDs exactly
        var type = attachment.type;
        // Make sure to convert to match our HTML container IDs
        var typeMapping = {
          'Photos': 'photos',
          'Intraoral': 'intraoralScans',
          'IntraoralScans': 'intraoralScans',
          'Facial': 'facialScans',
          'FacialScans': 'facialScans',
          'Photogrammetry': 'photogrammetry',
          'CompletedDesigns': 'completedDesigns',
          'Completed': 'completedDesigns'
        };

        // Try to map the type, fallback to lowercase if not found
        var mappedType = typeMapping[type] || type.toLowerCase();
        if (!attachmentsByType[mappedType]) {
          attachmentsByType[mappedType] = [];
        }
        attachmentsByType[mappedType].push(attachment);
      });

      // Display in the appropriate containers
      Object.keys(attachmentsByType).forEach(function(type) {
        // First look for containers with matching data-api-type attribute
        var container = document.querySelector('.selected-files[data-api-type="' + type + '"]');

        if (!container) {
          // Try different selector formats until we find a matching container
          var containerSelectors = [
            '#' + type.toLowerCase() + '-files',  // Standard format: #photos-files
            '#' + type + '-files',               // Capitalized: #Photos-files
            '[data-type="' + type.toLowerCase() + '"]', // data-type attribute
            '[id$="-' + type.toLowerCase() + '-files"]', // Ends with pattern
            '[id$="' + type.toLowerCase() + '"]',  // Contains type name
            '.selected-files'                    // Any selected-files container
          ];

          // Try each selector until we find a matching container
          containerSelectors.some(function(selector) {
            var el = document.querySelector(selector);
            if (el) {
              container = el;
              return true; // Break the loop once we find a container
            }
            return false;
          });

          // If no container found, fallback to the first one
          if (!container) {
            container = document.querySelector('.selected-files');
          }
        }

        if (container) {
          attachmentsByType[type].forEach(function(file) {
            // Create the file element
            var fileElement = document.createElement('div');
            fileElement.className = 'selected-file existing-file';

            // Get the file path for local files
            var filePath = file.path || '';
            var fileId = file.id || '';
            fileElement.dataset.fileId = fileId;
            fileElement.dataset.attachmentId = fileId;

            // Determine if this is a GCS-stored file or a legacy local/Drive file
            var isGcsFile = (file.storageType === 'gcs' && file.storagePath);

            // Determine file extension for viewer support
            var fileExt = (file.fileName || '').split('.').pop().toLowerCase();

            // Create the filename label
            var nameSpan;
            if (filePath && !isGcsFile) {
              // Legacy local file path for viewing
              var viewUrl = '/' + filePath;
              nameSpan = document.createElement('a');
              nameSpan.href = viewUrl;
              nameSpan.target = '_blank';
              nameSpan.rel = 'noopener noreferrer';
              nameSpan.style.cssText = 'color: #2563eb; text-decoration: none; cursor: pointer;';
              nameSpan.title = 'Click to view: ' + file.fileName;
              nameSpan.textContent = file.fileName;

              // Add hover effect
              nameSpan.addEventListener('mouseenter', function() {
                this.style.textDecoration = 'underline';
              });
              nameSpan.addEventListener('mouseleave', function() {
                this.style.textDecoration = 'none';
              });
            } else {
              nameSpan = document.createElement('span');
              nameSpan.title = file.fileName;
              nameSpan.textContent = file.fileName;
              nameSpan.style.cssText = 'color: #374151;';
            }

            // View link for supported preview formats
            var viewableExts = ['stl', 'obj', 'ply', 'jpg', 'jpeg', 'png', 'webp', 'pdf'];
            var viewLink = null;
            if (isGcsFile && viewableExts.indexOf(fileExt) !== -1) {
              viewLink = document.createElement('a');
              viewLink.href = '#';
              viewLink.className = 'attachment-view-link';
              viewLink.textContent = t('common.view');
              viewLink.title = 'Open in File Viewer';
              viewLink.dataset.storagePath = file.storagePath;
              viewLink.dataset.fileName = file.fileName;
              viewLink.dataset.fileType = file.fileType || file.mimeType || fileExt;
              viewLink.addEventListener('click', function(e) {
                e.preventDefault();
                if (typeof openAttachmentViewer === 'function') {
                  try {
                    openAttachmentViewer(this.dataset.storagePath, this.dataset.fileName, this.dataset.fileType);
                  } catch (err) {
                    console.error('Attachment viewer failed to open:', err);
                    showToast(t('attachments.preview_unavailable'), 'error');
                  }
                } else {
                  console.error('openAttachmentViewer is not available');
                  showToast(t('attachments.preview_unavailable'), 'error');
                }
              });
            }

            // Download link
            var downloadLink = null;
            if (isGcsFile) {
              downloadLink = document.createElement('a');
              downloadLink.href = '#';
              downloadLink.className = 'attachment-download-link';
              downloadLink.textContent = t('common.download');
              downloadLink.dataset.storagePath = file.storagePath;
              downloadLink.dataset.fileName = file.fileName;
              downloadLink.addEventListener('click', function(e) {
                e.preventDefault();
                openGcsFile(this.dataset.storagePath, this.dataset.fileName);
              });
            } else if (filePath) {
              downloadLink = document.createElement('a');
              downloadLink.href = '/' + filePath;
              downloadLink.download = file.fileName;
              downloadLink.className = 'attachment-download-link';
              downloadLink.textContent = t('common.download');
            }

            // Create a simple delete button with visible styling
            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'file-remove';
            deleteBtn.title = 'Mark file for deletion (will be removed when you update the case)';
            deleteBtn.textContent = '❌';

            // Add event listener directly to the button
            deleteBtn.addEventListener('click', function(e) {
              e.preventDefault();
              e.stopPropagation();

              var currentFileElement = this.parentElement;

              // Mark the file element for deletion
              currentFileElement.classList.add('marked-for-deletion');
              currentFileElement.style.opacity = '0.5';
              currentFileElement.style.textDecoration = 'line-through';

              // Hide the delete button after marking
              this.style.display = 'none';

              // Add a visual indicator that it's marked for deletion
              var indicator = document.createElement('span');
              indicator.textContent = ' ' + t('attachments.will_be_deleted');
              indicator.style.color = '#dc3545';
              indicator.style.fontSize = '12px';
              indicator.style.fontStyle = 'italic';
              currentFileElement.appendChild(indicator);

              // Mark form as having unsaved changes
              hasUnsavedChanges = true;
            });

            // Group View and Download together so they sit beside each other
            var actionsContainer = document.createElement('div');
            actionsContainer.className = 'attachment-actions';
            if (viewLink) {
              actionsContainer.appendChild(viewLink);
              if (downloadLink) {
                var separator = document.createElement('span');
                separator.className = 'attachment-actions-separator';
                separator.textContent = '|';
                actionsContainer.appendChild(separator);
              }
            }
            if (downloadLink) {
              actionsContainer.appendChild(downloadLink);
            }

            // Assemble the elements in order: name, actions, remove
            fileElement.appendChild(nameSpan);
            if (actionsContainer.childNodes.length > 0) {
              fileElement.appendChild(actionsContainer);
            }
            fileElement.appendChild(deleteBtn);

            container.appendChild(fileElement);
          });
        }
      });

      // Update bulk download button based on eligible attachments
      updateDownloadAllButton(caseData);
    }

    // Open the modal (will show tabs because caseId is set on the form)
    openCreateCase();

    // Set the read-only Created By display for edit mode
    var createdByDisplay = document.getElementById('createdByDisplay');
    if (createdByDisplay) {
      createdByDisplay.textContent = caseData.createdByName || t('common.unknown');
    }

    // Display At Risk indicator in case detail view
    displayAtRiskInCaseDetail(caseData);

    // Load revision history for this case
    loadCaseRevisionHistory(caseData.id || null);

    // Load activity timeline for this case (only if feature flag enabled)
    if (window.featureFlags && window.featureFlags.SHOW_ACTIVITY_TIMELINE && typeof loadActivityTimeline === 'function' && caseData.id) {
      loadActivityTimeline(caseData.id);
    }

    // Call the function to ensure delete buttons are visible
    setTimeout(ensureFileDeleteButtons, 500); // Small delay to ensure DOM is updated

    // Simple check after modal is open to verify delete buttons
    setTimeout(function() {
      // Count and verify all delete buttons
      var deleteButtons = document.querySelectorAll('.file-remove');

      // Make sure no buttons have HTML content (should use CSS ::before)
      deleteButtons.forEach(function(btn) {
        btn.innerHTML = '';
      });
    }, 500); // Half second delay should be enough
  }

  // Display At Risk indicator in case detail view
  function displayAtRiskInCaseDetail(caseData) {
    // Remove any existing At Risk indicator
    var existingIndicator = document.getElementById('caseDetailAtRisk');
    if (existingIndicator) {
      existingIndicator.remove();
    }

    // Check feature flag - if disabled, don't show At Risk banner
    if (!window.featureFlags || !window.featureFlags.SHOW_AT_RISK_BANNER) {
      return;
    }

    // Check if case is at risk
    if (!caseData || !caseData.atRisk || !caseData.atRisk.isAtRisk) {
      return;
    }

    var reasons = caseData.atRisk.reasons || [];
    if (reasons.length === 0) {
      return;
    }

    // Build human-readable summary from reasons
    var summaryText = reasons.join(', ');
    // Capitalize first letter
    summaryText = summaryText.charAt(0).toUpperCase() + summaryText.slice(1);

    // Build clinical Risk Summary HTML
    // Icon: small circle with dot (subtle indicator, not warning triangle)
    var indicatorHtml = '<div id="caseDetailAtRisk" class="case-detail-at-risk" title="Click to view revision history">' +
      '<div class="case-detail-at-risk-icon">' +
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<circle cx="12" cy="12" r="10"></circle>' +
      '<circle cx="12" cy="12" r="3" fill="currentColor"></circle>' +
      '</svg>' +
      '</div>' +
      '<div class="case-detail-at-risk-content">' +
      '<p class="case-detail-at-risk-label">At Risk</p>' +
      '<p class="case-detail-at-risk-summary">' + escapeHtml(summaryText) + '</p>' +
      '</div>' +
      '</div>';

    // Insert at the top of the form
    var form = document.getElementById('createCaseForm');
    if (form) {
      var firstChild = form.firstChild;
      var tempDiv = document.createElement('div');
      tempDiv.innerHTML = indicatorHtml;
      var indicator = tempDiv.firstChild;

      // Add click handler to switch to Revision History tab
      indicator.addEventListener('click', function() {
        var historyTab = document.querySelector('.case-tab[data-tab="history"]');
        if (historyTab) {
          historyTab.click();
        }
      });

      form.insertBefore(indicator, firstChild);
    }
  }

  // Helper function to escape HTML (if not already defined)
  function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Load user settings first, then load existing cases
  function loadUserSettingsBeforeCases() {
    fetch('api/get-settings.php')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Apply preferences to form fields and localStorage
          applyUserSettings(
            data.preferences,
            data.gmailUsers,
            data.gmailUserLogins || {},
            data.adminUsers,
            data.practiceName,
            data.logoPath,
            data.assignmentLabels,
            data.isPracticeAdmin,
            data.practiceCreatorEmail || null,
            data.displayName || data.practiceName,
            data.legalName || '',
            data.limitedVisibilityUsers || {},
            data.canViewAnalyticsUsers || {},
            data.canEditCasesUsers || {},
            data.practiceCreatorHasGoogleAccount !== false,
            data.isGoogleDriveConnected === true,
            data.assignmentLabelsDetailed || [],
            data.practiceUsers || [],
            data.isLabUsers || {},
            data.showLabInsights === true,
            data.workflowStageLabels || {}
          );

          // Set localStorage values for past due and coming due highlighting
          if (data.preferences.highlight_past_due !== undefined) {
            localStorage.setItem('highlight_past_due', data.preferences.highlight_past_due ? 'true' : 'false');
          }
          if (data.preferences.past_due_days !== undefined) {
            localStorage.setItem('past_due_days', data.preferences.past_due_days.toString());
          }
          if (data.preferences.highlight_coming_due !== undefined) {
            localStorage.setItem('highlight_coming_due', data.preferences.highlight_coming_due ? 'true' : 'false');
          }
          if (data.preferences.coming_due_days !== undefined) {
            localStorage.setItem('coming_due_days', data.preferences.coming_due_days.toString());
          }
          if (data.preferences.highlight_appointment_risk !== undefined) {
            localStorage.setItem('highlight_appointment_risk', data.preferences.highlight_appointment_risk ? 'true' : 'false');
          }
          if (data.preferences.appointment_risk_days !== undefined) {
            localStorage.setItem('appointment_risk_days', data.preferences.appointment_risk_days.toString());
          }
        }
      })
      .catch(error => {
        // Silently handle errors
      })
      .finally(() => {
        // Load cases after settings are loaded (or failed to load)
        loadExistingCases();
      });
  }

  // Load existing cases from Google Drive when the page loads
  function loadExistingCases() {
    // Get reference to kanban board
    var kanbanBoard = document.querySelector('.kanban-board');

    // Slightly dim the kanban board while loading
    if (kanbanBoard) kanbanBoard.classList.add('loading');

    // Track loading state
    var loadingStartTime = Date.now();
    var minLoadingTime = 1000; // Show loading for at least this many ms for UX

    fetch('api/list-cases.php', {
      method: 'GET',
      credentials: 'same-origin'
    })
    .then(function (response) {
      return response.json();
    })
    .then(function (data) {
      if (!data || !data.success || !Array.isArray(data.cases)) {
        hideLoader();
        return;
      }

      // Add all cases to the board
      var totalCases = data.cases.length;

      if (totalCases === 0) {
        // No cases to add, hide loader immediately
        hideLoader();
        return;
      }

      // Clear existing board state before re-rendering (full refresh path)
      var columns = document.querySelectorAll('.kanban-column-body');
      columns.forEach(function (column) {
        var cards = column.querySelectorAll('.kanban-card');
        cards.forEach(function (card) { card.remove(); });

        if (column.children.length === 0) {
          var emptyMsg = document.createElement('p');
          emptyMsg.className = 'kanban-empty';
          emptyMsg.textContent = t('cases.no_cases_in_stage');
          column.appendChild(emptyMsg);
        }
      });

      // Add all cases at once (no stagger) to prevent CLS
      data.cases.forEach(function (caseData) {
        // Deep clone to ensure we don't lose data
        var clonedCase = JSON.parse(JSON.stringify(caseData));

        // Each case from the API already has status, dueDate, etc.
        addCaseToKanban(clonedCase);
      });

      // All cases added, apply past due highlighting
      if (typeof updatePastDueHighlighting === 'function') {
        updatePastDueHighlighting();
      }

      // Reconcile column counts with the actual rendered cards
      if (typeof window.updateColumnCounts === 'function') {
        window.updateColumnCounts();
      }

      // Hide loader after all cases are added
      hideLoader();
    })
    .catch(function (err) {
      // Failed to load cases
      hideLoader();
    });

    // Function to hide loader with minimum display time
    function hideLoader() {
      var elapsedTime = Date.now() - loadingStartTime;
      var remainingTime = Math.max(0, minLoadingTime - elapsedTime);

      setTimeout(function() {
        // Remove loading state from kanban board
        if (kanbanBoard) {
          kanbanBoard.classList.remove('loading');
          // Show the kanban board now that all cards are loaded (prevents CLS)
          kanbanBoard.classList.add('loaded');
        }

        // Mark app as initialized
        appInitialized = true;

        // Initialize drag-and-drop now that all cards are loaded
        initKanbanDragDrop();

        // Notify that cards are loaded (for search indexing)
        var cardsLoadedEvent = new CustomEvent('cardsLoaded');
        window.dispatchEvent(cardsLoadedEvent);

        // Execute any registered callbacks
        if (window.cardLoadedCallbacks && Array.isArray(window.cardLoadedCallbacks)) {
          window.cardLoadedCallbacks.forEach(function(callback) {
            if (typeof callback === 'function') {
              try {
                callback();
              } catch (e) {
                // Handle callback error silently
              }
            }
          });
        }

        // Fade out and hide the page loading overlay
        if (pageLoadingOverlay) {
          pageLoadingOverlay.style.opacity = '0';
          setTimeout(function() {
            pageLoadingOverlay.style.display = 'none';
          }, 500); // Wait for fade out animation to complete
        }
      }, remainingTime);
    }
  }

  // Function to clear highlighting from a specific card
  function clearCardHighlighting(card) {
    card.classList.remove('kanban-card-past-due');
    card.classList.remove('kanban-card-coming-due');
    var lateIndicator = card.querySelector('.late-indicator');
    if (lateIndicator) {
      lateIndicator.textContent = '';
    }
  }

  // Apply the full urgency hierarchy (Late > Appt Risk > Coming Due) to a card.
  // This is the single source of truth used both when cards are created and when
  // settings are updated, so the visual state matches the filter logic.
  function applyUrgencyHighlighting(caseData) {
    var highlightPastDue = localStorage.getItem('highlight_past_due') === 'true';
    var highlightComingDue = localStorage.getItem('highlight_coming_due') === 'true';
    var highlightAppointmentRisk = localStorage.getItem('highlight_appointment_risk') === 'true';

    // Don't highlight cases in the Delivered board (they are essentially closed)
    if (caseData.status === 'Delivered') return;

    // Find the card element
    var cardElement = document.querySelector('[data-case-id="' + caseData.id + '"]');
    if (!cardElement) return;
    var card = cardElement.closest('.kanban-card');
    if (!card) return;

    var pastDueDays = parseInt(localStorage.getItem('past_due_days') || '1', 10);
    var comingDueDays = parseInt(localStorage.getItem('coming_due_days') || '5', 10);
    var appointmentRiskDays = parseInt(localStorage.getItem('appointment_risk_days') || '3', 10);

    var lateIndicator = card.querySelector('.late-indicator');
    var apptIndicator = card.querySelector('.appointment-risk-indicator');

    // Clear previous state
    card.classList.remove('kanban-card-past-due');
    card.classList.remove('kanban-card-coming-due');
    card.classList.remove('kanban-card-appointment-risk');
    if (lateIndicator) lateIndicator.textContent = '';
    if (apptIndicator) apptIndicator.textContent = '';

    // Red late treatment takes top precedence
    var daysUntil = null;
    if (caseData.dueDate) {
      daysUntil = getCalendarDayDiff(caseData.dueDate);
    }
    if (highlightPastDue && daysUntil !== null && daysUntil <= -pastDueDays) {
      card.classList.add('kanban-card-past-due');
      if (lateIndicator) {
        lateIndicator.textContent = ' LATE';
      }
      return;
    }

    // Purple appointment risk takes precedence over Coming Due
    if (highlightAppointmentRisk && caseData.patientAppointmentDate) {
      var daysUntilAppt = getCalendarDayDiff(caseData.patientAppointmentDate);
      if (daysUntilAppt !== null && daysUntilAppt <= appointmentRiskDays) {
        card.classList.add('kanban-card-appointment-risk');
        if (apptIndicator) {
          apptIndicator.textContent = 'APPT RISK';
        }
        return;
      }
    }

    // Blue coming-due window: only while the case is not Late or Appt Risk
    if (highlightComingDue && daysUntil !== null && daysUntil >= 0 && daysUntil <= comingDueDays) {
      card.classList.add('kanban-card-coming-due');
      if (lateIndicator) {
        lateIndicator.textContent = ' ' + getDueWarningText(daysUntil);
      }
    }
  }

  // Backward-compatible alias for existing callers that expect the old name.
  function applyPastDueHighlighting(caseData) {
    applyUrgencyHighlighting(caseData);
  }

  // Function to update all cards' urgency highlighting
  function updatePastDueHighlighting() {
    // Reapply full urgency hierarchy to all cards
    document.querySelectorAll('.kanban-card[data-case-json]').forEach(function(card) {
      try {
        var caseData = JSON.parse(card.dataset.caseJson || '{}');
        if (caseData.id) {
          // Determine the internal status from the column's fixed
          // data-status attribute - not its visible (and later practice-
          // customizable) header text.
          var column = card.closest('.kanban-column');
          caseData.status = column ? (column.dataset.status || '') : (caseData.status || '');
          applyUrgencyHighlighting(caseData);
        }
      } catch (e) {
        // Skip card with invalid data
      }
    });
  }

  // Logo management functions
  function updateLogoDisplay(logoPath) {
    const currentLogo = document.getElementById('currentLogo');
    const currentLogoImg = document.getElementById('currentLogoImg');
    const headerLogo = document.querySelector('.main-logo');

    if (logoPath && logoPath.trim() !== '') {
      // Show current logo in settings
      if (currentLogo && currentLogoImg) {
        currentLogoImg.src = logoPath;
        currentLogo.style.display = 'flex';
      }

      // Only update the header logo when the path matches the committed DB value
      if (headerLogo && window.currentLogoPath && window.currentLogoPath === logoPath) {
        headerLogo.src = logoPath;
        headerLogo.style.display = '';
      }
    } else {
      // Hide current logo display
      if (currentLogo) {
        currentLogo.style.display = 'none';
      }

      // Hide header logo as well (no committed logo)
      if (headerLogo) {
        headerLogo.style.display = 'none';
        headerLogo.removeAttribute('src');
      }
    }
  }

  function setupLogoUpload() {
    const logoInput = document.getElementById('practiceLogo');
    const logoLabel = document.querySelector('.logo-upload-label');
    const deleteLogo = document.getElementById('deleteLogo');
    const currentLogo = document.getElementById('currentLogo');

    if (!logoInput || !logoLabel) return;

    // Handle file selection
    logoInput.addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        uploadLogo(file);
      }
    });

    // Handle drag and drop
    logoLabel.addEventListener('dragover', function(e) {
      e.preventDefault();
      if (!logoLabel.classList.contains('disabled')) {
        logoLabel.classList.add('drag-over');
      }
    });

    logoLabel.addEventListener('dragleave', function(e) {
      e.preventDefault();
      logoLabel.classList.remove('drag-over');
    });

    logoLabel.addEventListener('drop', function(e) {
      e.preventDefault();
      logoLabel.classList.remove('drag-over');

      if (logoLabel.classList.contains('disabled')) return;

      const files = e.dataTransfer.files;
      if (files.length > 0) {
        uploadLogo(files[0]);
      }
    });

    // Handle logo deletion (stage removal until settings are saved)
    if (deleteLogo) {
      deleteLogo.addEventListener('click', function() {
        // Mark logo for removal but don't hit the server yet
        window.logoMarkedForRemoval = true;

        // Clear any staged logo path
        window.pendingLogoPath = '';

        // Hide preview in the settings modal
        if (currentLogo) {
          currentLogo.style.display = 'none';
        }

        // Clear any selected file
        if (logoInput) {
          logoInput.value = '';
        }
      });
    }
  }

  function uploadLogo(file) {
    const logoLabel = document.querySelector('.logo-upload-label');
    const uploadText = logoLabel.querySelector('.upload-text');

    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      showToast(t('settings.practice.logo_invalid_file'), 'error');
      return;
    }

    // Validate file size (5MB)
    if (file.size > 5 * 1024 * 1024) {
      showToast(t('settings.practice.logo_file_too_large'), 'error');
      return;
    }

    // Show uploading state
    logoLabel.classList.add('uploading');
    uploadText.textContent = t('settings.practice.logo_uploading');

    // Create form data
    const formData = new FormData();
    formData.append('logo', file);

    // Upload file
    fetch('api/upload-logo.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-CSRF-Token': csrfToken
      }
    })
    .then(response => response.json())
    .then(data => {
      logoLabel.classList.remove('uploading');

      if (data.success) {
        logoLabel.classList.add('success');
        uploadText.textContent = t('settings.practice.logo_upload_successful');

        // Stage the new logo for this session only (settings preview)
        window.pendingLogoPath = data.logoPath || '';
        window.logoMarkedForRemoval = false;

        // Update only the settings preview using the pending path
        updateLogoDisplay(window.pendingLogoPath || window.currentLogoPath || '');

        showToast(t('settings.practice.logo_uploaded'), 'success');

        // Reset upload state after 2 seconds
        setTimeout(function() {
          logoLabel.classList.remove('success');
          uploadText.textContent = t('settings.practice.logo_choose');
        }, 2000);

      } else {
        logoLabel.classList.add('error');
        uploadText.textContent = t('settings.practice.logo_upload_failed');
        showToast(data.message || t('settings.practice.logo_upload_failed'), 'error');

        // Reset error state after 3 seconds
        setTimeout(function() {
          logoLabel.classList.remove('error');
          uploadText.textContent = t('settings.practice.logo_choose');
        }, 3000);
      }
    })
    .catch(function(error) {
      logoLabel.classList.remove('uploading');
      logoLabel.classList.add('error');
      uploadText.textContent = t('settings.practice.logo_upload_failed');
      showToast(t('settings.practice.logo_upload_error'), 'error');

      // Reset error state after 3 seconds
      setTimeout(function() {
        logoLabel.classList.remove('error');
        uploadText.textContent = t('settings.practice.logo_choose');
      }, 3000);
    });
  }

  function deletePracticeLogo() {
    fetch('api/delete-logo.php', {
      method: 'POST',
      headers: {
        'X-CSRF-Token': csrfToken
      }
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Hide current logo display
        updateLogoDisplay('');

        // Remove header logo
        const headerLogo = document.querySelector('.main-logo');
        if (headerLogo) {
          headerLogo.style.display = 'none';
        }

        showToast(t('settings.practice.logo_removed'), 'success');
      } else {
        showToast(data.message || t('settings.practice.logo_upload_failed'), 'error');
      }
    })
    .catch(function(error) {
      showToast(t('settings.practice.logo_remove_error'), 'error');
    });
  }

  // Dev-only fake case generator (power users only)
  var devGenerateBtn = document.getElementById('devGenerateCasesBtn');
  var devCaseCountInput = document.getElementById('devCaseCount');
  var devGenerateDemoDataBtn = document.getElementById('devGenerateDemoDataBtn');

  if (devGenerateBtn && devCaseCountInput) {
    devGenerateBtn.addEventListener('click', function () {
      var raw = devCaseCountInput.value;
      var count = parseInt(raw, 10);

      if (isNaN(count) || count < 1) {
        showToast(t('common.enter_case_count'), 'warning');
        return;
      }

      if (count > 500) {
        count = 500;
        devCaseCountInput.value = '500';
      }

      devGenerateBtn.disabled = true;
      var originalText = devGenerateBtn.textContent;
      devGenerateBtn.textContent = 'Working...';

      fetch('api/generate-fake-cases.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin',
        body: JSON.stringify({ count: count })
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        devGenerateBtn.disabled = false;
        devGenerateBtn.textContent = originalText;

        if (!data || !data.success) {
          var msg = (data && data.message) ? data.message : t('demo_data.generate_failed');
          showToast(msg, 'error');
          return;
        }

        showToast(data.message || t('demo_data.fake_cases_generated'), 'success');

        // Reload the page so the new cases are fetched and rendered
        setTimeout(function () {
          window.location.reload();
        }, 500);
      })
      .catch(function (err) {
        devGenerateBtn.disabled = false;
        devGenerateBtn.textContent = originalText;
        showToast(t('demo_data.generate_error', {message: err.message}), 'error');
      });
    });
  }

  // Dev-only dental practice demo data lifecycle
  var devGenerateDemoDataBtn = document.getElementById('devGenerateDemoDataBtn');
  var devResetDemoDataBtn = document.getElementById('devResetDemoDataBtn');
  var devDeleteDemoDataBtn = document.getElementById('devDeleteDemoDataBtn');
  var devDemoDataSizeSelect = document.getElementById('devDemoDataSize');
  var devDemoDataSummary = document.getElementById('devDemoDataSummary');

  function loadDemoDataSummary() {
    if (!devDemoDataSummary) return;

    fetch('api/manage-demo-data.php?action=status', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.success) {
          devDemoDataSummary.textContent = t('demo_data.unable_to_load');
          return;
        }

        if (data.total > 0) {
          devDemoDataSummary.textContent = t('demo_data.active_cases', {count: data.active}) + ' • ' + t('demo_data.archived_cases', {count: data.historical});
        } else {
          devDemoDataSummary.textContent = t('demo_data.no_data');
        }
      })
      .catch(function (err) {
        devDemoDataSummary.textContent = t('demo_data.unable_to_load');
        console.error('Demo summary error:', err);
      });
  }

  function callManageDemoData(action, confirmed, onDone) {
    var body = { action: action, confirmed: confirmed };
    fetch('api/manage-demo-data.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (data && data.needsConfirmation) {
        if (confirm(data.message)) {
          callManageDemoData(action, true, onDone);
          return;
        }
        showToast(t('common.action_cancelled'), 'info');
        return;
      }

      if (!data || !data.success) {
        showToast((data && data.message) ? data.message : t('demo_data.action_failed', {action: action}), 'error');
        return;
      }

      if (typeof onDone === 'function') {
        onDone(data);
      } else {
        showToast(data.message, 'success');
        loadDemoDataSummary();
      }
    })
    .catch(function (err) {
      showToast(t('demo_data.manage_error', {message: err.message}), 'error');
    });
  }

  function callDemoGenerator(body) {
    if (!body.dataset) {
      body.dataset = devDemoDataSizeSelect ? devDemoDataSizeSelect.value : 'standard';
    }

    return fetch('api/generate-dental-practice-demo-data.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    })
    .then(function (response) { return response.json(); });
  }

  if (devGenerateDemoDataBtn) {
    devGenerateDemoDataBtn.addEventListener('click', function () {
      var originalText = devGenerateDemoDataBtn.textContent;
      devGenerateDemoDataBtn.disabled = true;
      devGenerateDemoDataBtn.textContent = t('common.generating');

      function tryGenerate(needsConfirm) {
        callDemoGenerator(needsConfirm ? { confirmed: true } : {})
          .then(function (data) {
            devGenerateDemoDataBtn.disabled = false;
            devGenerateDemoDataBtn.textContent = originalText;

            if (data && data.needsConfirmation) {
              if (confirm(data.message || t('demo_data.confirm_overwrite'))) {
                devGenerateDemoDataBtn.disabled = true;
                devGenerateDemoDataBtn.textContent = t('common.generating');
                tryGenerate(true);
                return;
              }
              showToast(t('common.action_cancelled'), 'info');
              return;
            }

            if (!data || !data.success) {
              showToast((data && data.message) ? data.message : t('demo_data.generate_failed_action'), 'error');
              return;
            }

            showToast(data.message || t('demo_data.generated'), 'success');
            loadDemoDataSummary();
          })
          .catch(function (err) {
            devGenerateDemoDataBtn.disabled = false;
            devGenerateDemoDataBtn.textContent = originalText;
            showToast(t('demo_data.generate_error_action', {message: err.message}), 'error');
          });
      }

      tryGenerate(false);
    });
  }

  if (devDeleteDemoDataBtn) {
    devDeleteDemoDataBtn.addEventListener('click', function () {
      callManageDemoData('delete', false);
    });
  }

  if (devResetDemoDataBtn) {
    devResetDemoDataBtn.addEventListener('click', function () {
      var originalText = devResetDemoDataBtn.textContent;
      devResetDemoDataBtn.disabled = true;
      devResetDemoDataBtn.textContent = t('common.resetting');

      function afterDelete(data) {
        var dataset = devDemoDataSizeSelect ? devDemoDataSizeSelect.value : 'standard';
        callDemoGenerator({ dataset: dataset, confirmed: true })
          .then(function (genData) {
            devResetDemoDataBtn.disabled = false;
            devResetDemoDataBtn.textContent = originalText;

            if (!genData || !genData.success) {
              showToast((genData && genData.message) ? genData.message : t('demo_data.reset_generate_failed'), 'error');
              loadDemoDataSummary();
              return;
            }

            showToast(genData.message || t('demo_data.reset_success'), 'success');
            loadDemoDataSummary();
          })
          .catch(function (err) {
            devResetDemoDataBtn.disabled = false;
            devResetDemoDataBtn.textContent = originalText;
            showToast(t('demo_data.reset_generate_error', {message: err.message}), 'error');
          });
      }

      fetch('api/manage-demo-data.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'reset', confirmed: false })
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.needsConfirmation) {
          if (confirm(data.message)) {
            callManageDemoData('reset', true, afterDelete);
            return;
          }
          devResetDemoDataBtn.disabled = false;
          devResetDemoDataBtn.textContent = originalText;
          showToast(t('common.action_cancelled'), 'info');
          return;
        }

        if (!data || !data.success) {
          devResetDemoDataBtn.disabled = false;
          devResetDemoDataBtn.textContent = originalText;
          showToast((data && data.message) ? data.message : t('demo_data.reset_failed'), 'error');
          return;
        }

        afterDelete(data);
      })
      .catch(function (err) {
        devResetDemoDataBtn.disabled = false;
        devResetDemoDataBtn.textContent = originalText;
        showToast(t('demo_data.reset_error', {message: err.message}), 'error');
      });
    });
  }

  if (devDemoDataSummary) {
    loadDemoDataSummary();
  }

  // Function to print a case with all details and file contents
  function printCase(caseData) {
    if (!caseData || !caseData.id) {
      showToast(t('cases.toast.invalid_case_data'), 'error');
      return;
    }

    // Check if trial expired
    if (billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
      showUpgradeModal();
      return;
    }

    // Check if another case is already being printed
    if (window.isPrintingCase) {
      showToast(t('cases.toast.print_in_progress'), 'warning');
      return;
    }

    // Show progress message for large documents
    var hasAttachments = caseData.attachments && caseData.attachments.length > 0;

    // Set global flag to prevent other operations
    window.isPrintingCase = true;

    // Track which specific case is being printed
    window.currentlyPrintingCaseId = caseData.id;

    // Add safety timeout to reset flag after 60 seconds in case of errors
    setTimeout(function() {
      if (window.isPrintingCase) {
        window.isPrintingCase = false;
        window.currentlyPrintingCaseId = null;

        // Reset any stuck print buttons
        var printButtons = document.querySelectorAll('.kanban-card-print');
        printButtons.forEach(function(btn) {
          if (btn.disabled && btn.textContent.includes('Generating')) {
            btn.disabled = false;
            btn.textContent = '🖨️ Print';
          }
        });

        // Reset all edit buttons
        var editButtons = document.querySelectorAll('.kanban-card-edit');
        editButtons.forEach(function(button) {
          button.classList.remove('printing-disabled');
          button.style.opacity = '';
          button.style.cursor = '';
        });

        // Reset all assignment dropdowns
        var assignmentSelects = document.querySelectorAll('.assignment-select');
        assignmentSelects.forEach(function(select) {
          select.disabled = false;
          select.style.opacity = '';
          select.style.cursor = '';
        });

        // Reset all delete/archive buttons
        var deleteButtons = document.querySelectorAll('.card-delete-btn');
        deleteButtons.forEach(function(button) {
          button.disabled = false;
          button.classList.remove('printing-disabled');
          button.style.opacity = '';
          button.style.cursor = '';
        });
      }
    }, 60000); // 60 second timeout (matches server-side limit)

    // Disable all edit buttons visually during printing
    var editButtons = document.querySelectorAll('.kanban-card-edit');
    editButtons.forEach(function(button) {
      button.classList.add('printing-disabled');
      button.style.opacity = '0.5';
      button.style.cursor = 'not-allowed';
    });

    // Disable all assignment dropdowns visually during printing
    var assignmentSelects = document.querySelectorAll('.assignment-select');
    assignmentSelects.forEach(function(select) {
      select.disabled = true;
      select.style.opacity = '0.5';
      select.style.cursor = 'not-allowed';
    });

    // Disable all delete/archive buttons completely during printing
    var deleteButtons = document.querySelectorAll('.card-delete-btn');
    deleteButtons.forEach(function(button) {
      button.disabled = true;
      button.classList.add('printing-disabled');
      button.style.opacity = '0.5';
      button.style.cursor = 'not-allowed';
    });

    // Show loading state
    var printButton = document.querySelector('.kanban-card-print[data-case-id="' + caseData.id + '"]');
    if (printButton) {
      printButton.disabled = true;
      printButton.textContent = '🖨️ Generating...';
    }

    // Call the API to generate document
    // Add practice name to case data
    var practiceNameElement = document.querySelector('.practice-name');
    if (practiceNameElement) {
      caseData.practiceName = practiceNameElement.textContent;
    }

    fetch('api/print-case.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        caseData: caseData // Send the complete case data instead of just ID
      }),
      credentials: 'same-origin'
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }

      // Check the content type to determine how to handle the response
      var contentType = response.headers.get('Content-Type');

      if (contentType && contentType.includes('application/pdf')) {
        // Handle PDF content - download directly
        return response.blob().then(blob => {
          downloadBlobFile(blob, caseData, 'pdf');
        });
      } else {
        // Handle HTML content (fallback) - open in new window for printing
        return response.text().then(htmlContent => {
          // Add responsive print styles to HTML content
          var enhancedHtml = htmlContent.replace(
            '</head>',
            `
            <style>
              @media print {
                @page { size: Letter; margin: 0.5in; }
                @page landscape { size: Letter landscape; margin: 0.5in; }
                body { max-width: 100%; }
                table { width: 100%; font-size: 9px; }
                table td, table th { word-wrap: break-word; max-width: 180px; }
                img { max-width: 100%; height: auto; }
                pre { white-space: pre-wrap; word-wrap: break-word; max-width: 100%; font-size: 8px; }
              }
            </style>
            </head>`
          );

          // Open in new window for printing
          var printWindow = window.open('', '_blank');
          if (printWindow) {
            printWindow.document.write(enhancedHtml);
            printWindow.document.close();

            // Wait a moment for content to load, then trigger print
            setTimeout(function() {
              printWindow.print();
            }, 500);
          } else {
            // Fallback: download as file if popup blocked
            downloadHtmlFile(enhancedHtml, caseData);
          }
        });
      }
    })
    .catch(error => {
      if (typeof NetworkErrorHandler !== 'undefined') {
        NetworkErrorHandler.handle(error, 'generating document');
      } else {
        showToast(t('cases.toast.document_error'), 'error');
      }
    })
    .finally(() => {
      // Reset print button state
      if (printButton) {
        printButton.disabled = false;
        printButton.textContent = '🖨️ Print';
      }

      window.isPrintingCase = false;
      window.currentlyPrintingCaseId = null;

      // Re-enable all edit buttons visually after printing
      var editButtons = document.querySelectorAll('.kanban-card-edit');
      editButtons.forEach(function(button) {
        button.classList.remove('printing-disabled');
        button.style.opacity = '';
        button.style.cursor = '';
      });

      // Re-enable all assignment dropdowns visually after printing
      var assignmentSelects = document.querySelectorAll('.assignment-select');
      assignmentSelects.forEach(function(select) {
        select.disabled = false;
        select.style.opacity = '';
        select.style.cursor = '';
      });

      // Re-enable all delete/archive buttons completely after printing
      var deleteButtons = document.querySelectorAll('.card-delete-btn');
      deleteButtons.forEach(function(button) {
        button.disabled = false;
        button.classList.remove('printing-disabled');
        button.style.opacity = '';
        button.style.cursor = '';
      });
    });
  }

  function downloadHtmlFile(htmlContent, caseData) {
    // Create a download link for the HTML file
    const blob = new Blob([htmlContent], { type: 'text/html' });
    downloadBlobFile(blob, caseData, 'html');
  }

  function downloadBlobFile(blob, caseData, extension) {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href = url;

    // Generate filename with patient name and case ID
    var patientName = (caseData.patientFirstName + '_' + caseData.patientLastName).replace(/\s+/g, '_');
    var fileExtension = extension || 'pdf';
    a.download = 'Case_' + patientName + '_' + caseData.id + '.' + fileExtension;

    document.body.appendChild(a);
    a.click();
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);
  }

  // Open a GCS-stored file via signed download URL
  function openGcsFile(storagePath, fileName) {
    if (!storagePath) return;

    fetch('api/download-signed-url.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        storage_path: storagePath,
        filename: fileName || ''
      })
    })
    .then(function(response) {
      if (!response.ok) {
        return response.json().then(function(data) {
          throw new Error(data.error || 'Failed to get download URL');
        });
      }
      return response.json();
    })
    .then(function(data) {
      if (data.success && data.signed_url) {
        window.open(data.signed_url, '_blank');
      } else {
        throw new Error(data.error || 'Failed to get download URL');
      }
    })
    .catch(function(error) {

      if (typeof showToast === 'function') {
        showToast(t('attachments.download_failed', {message: error.message}), 'error');
      }
    });
  }

  // Show or hide the "Download All" attachments button.
  // Case data is authoritative; this is a local preview of availability.
  function updateDownloadAllButton(caseData) {
    var container = document.getElementById('attachmentDownloadAll');
    var btn = document.getElementById('downloadAllAttachmentsBtn');
    var btnLabel = btn ? btn.querySelector('.download-all-label') : null;
    var statusEl = document.getElementById('downloadAllAttachmentsStatus');
    if (!container || !btn) return;

    // Enforce the server-side feature flag independently in the UI.
    if (!window.featureFlags || !window.featureFlags.SHOW_CASE_DOWNLOAD_ALL) {
      container.style.display = 'none';
      if (statusEl) {
        statusEl.textContent = '';
        statusEl.style.display = 'none';
      }
      return;
    }

    var bulkZipMaxBytes = (typeof window.bulkZipMaxBytes === 'number' && window.bulkZipMaxBytes > 0)
      ? window.bulkZipMaxBytes
      : null;
    var attachments = caseData.attachments || [];
    var eligible = 0;
    var knownTotal = 0;
    var hasUnknownSize = false;
    if (Array.isArray(attachments)) {
      attachments.forEach(function(file) {
        if (file.storageType === 'gcs' && file.storagePath && file.fileName) {
          eligible++;
          if (typeof file.size === 'number' && file.size > 0) {
            knownTotal += file.size;
          } else {
            hasUnknownSize = true;
          }
        }
      });
    }

    if (eligible >= 2) {
      container.style.display = 'block';
      // Disable only when all sizes are known and exceed the validated limit.
      if (bulkZipMaxBytes !== null && !hasUnknownSize && knownTotal > bulkZipMaxBytes) {
        btn.disabled = true;
        var tooLargeLabel = t('attachments.download_all') + ' (' + eligible + ')';
        if (btnLabel) btnLabel.textContent = tooLargeLabel;
        btn.setAttribute('aria-label', t('attachments.download_all_aria') + ' (' + eligible + ')' + ' — ' + t('attachments.download_all_bundle_too_large'));
        if (statusEl) {
          statusEl.textContent = t('attachments.download_all_bundle_too_large');
          statusEl.style.display = 'block';
        }
      } else {
        btn.disabled = false;
        var downloadLabel = t('attachments.download_all') + ' (' + eligible + ')';
        if (btnLabel) btnLabel.textContent = downloadLabel;
        btn.setAttribute('aria-label', t('attachments.download_all_aria') + ' (' + eligible + ')');
        if (statusEl) {
          statusEl.textContent = '';
          statusEl.style.display = 'none';
        }
        btn.onclick = function(e) {
          e.preventDefault();
          downloadCaseAttachmentsZip(caseData.id);
        };
      }
    } else {
      container.style.display = 'none';
      if (statusEl) {
        statusEl.textContent = '';
        statusEl.style.display = 'none';
      }
    }
  }

  // Download all eligible attachments for a single case as one ZIP.
  // 1. Performs a lightweight authenticated preflight request that repeats all
  //    authoritative checks without downloading attachment contents.
  // 2. Only submits the hidden download form after the preflight succeeds.
  // 3. Uses a form+iframe so the browser can stream arbitrarily large archives
  //    directly to disk instead of buffering the whole ZIP in JavaScript.
  function downloadCaseAttachmentsZip(caseId) {
    var btn = document.getElementById('downloadAllAttachmentsBtn');
    var statusEl = document.getElementById('downloadAllAttachmentsStatus');
    if (!caseId) {
      if (statusEl) {
        statusEl.textContent = t('attachments.download_all_no_eligible');
        statusEl.style.display = 'block';
      }
      return;
    }

    if (btn) {
      btn.disabled = true;
      var prepLabel = btn.querySelector('.download-all-label');
      if (prepLabel) prepLabel.textContent = t('attachments.download_all_preparing');
    }
    if (statusEl) {
      statusEl.textContent = t('attachments.download_all_preparing');
      statusEl.style.display = 'block';
    }

    fetch('api/preflight-download-case-attachments-zip.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      credentials: 'same-origin',
      body: JSON.stringify({ case_id: caseId })
    })
    .then(function(response) {
      return response.json().catch(function() {
        throw new Error(t('attachments.download_all_failed'));
      });
    })
    .then(function(data) {
      if (!data || !data.success) {
        throw new Error(data && data.error ? data.error : t('attachments.download_all_failed'));
      }

      if (statusEl) {
        statusEl.textContent = t('attachments.download_started');
        statusEl.style.display = 'block';
      }

      // Submit the real download form; the browser streams the ZIP to disk.
      var iframeName = 'dt-zip-download-' + Date.now();
      var iframe = document.createElement('iframe');
      iframe.name = iframeName;
      iframe.style.display = 'none';
      iframe.setAttribute('aria-hidden', 'true');
      document.body.appendChild(iframe);

      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'api/download-case-attachments-zip.php';
      form.target = iframeName;
      form.style.display = 'none';

      function addInput(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      addInput('case_id', caseId);
      addInput('csrf_token', csrfToken);

      document.body.appendChild(form);
      form.submit();

      setTimeout(function() {
        if (btn) {
          btn.disabled = false;
          var labelEl = btn.querySelector('.download-all-label');
          if (labelEl) labelEl.textContent = t('attachments.download_all');
        }
        if (statusEl) {
          statusEl.textContent = '';
          statusEl.style.display = 'none';
        }
        if (form.parentNode) {
          document.body.removeChild(form);
        }
        setTimeout(function() {
          if (iframe.parentNode) {
            document.body.removeChild(iframe);
          }
        }, 30000);
      }, 1000);
    })
    .catch(function(error) {
      if (statusEl) {
        statusEl.textContent = t('attachments.download_all_failed', {message: error.message});
        statusEl.style.display = 'block';
      }
      if (btn) {
        btn.disabled = false;
        var labelEl = btn.querySelector('.download-all-label');
        if (labelEl) labelEl.textContent = t('attachments.download_all');
      }
    });
  }

  // Initialize logo upload functionality
  setupLogoUpload();

  // Load user settings first, then load existing cases
  loadUserSettingsBeforeCases();

  // Load and display billing information
  loadBillingInfo();

  // Main dashboard search is handled by patient-search.js

  // Billing functionality
  let billingInfo = null;



  // Load billing information from API
  function loadBillingInfo() {
    fetch('api/billing.php')
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          return;
        }

        billingInfo = data;

        // Hide billing UI completely for bypass users (partner practices, etc.)
        if (data.hide_billing_ui) {
          const billingTierElement = document.getElementById('userBillingTier');
          if (billingTierElement) {
            billingTierElement.style.display = 'none';
          }
          // Don't show any billing-related UI for bypass users
          return;
        }

        // Update billing tier display (only if billing feature is enabled)
        if (data.billing_tier && window.featureFlags && window.featureFlags.SHOW_BILLING) {
          const billingTierElement = document.getElementById('userBillingTier');
          if (billingTierElement) {
            let displayText = '';
            let showLink = false;

            if (data.billing_tier === 'evaluate') {
              // Show trial days remaining for Evaluate plan
              if (data.is_trial && data.trial_days_remaining !== null) {
                if (data.trial_expired) {
                  displayText = t('billing.link.trial_expired_upgrade');
                } else {
                  displayText = t('billing.link.evaluate_plan') + ' - ' + I18n.pluralize(data.trial_days_remaining, 'billing.link.days_left');
                }
              } else {
                displayText = t('billing.link.evaluate_plan');
              }
              showLink = true;
            } else if (data.billing_tier === 'operate') {
              displayText = t('billing.link.operate_plan');
              showLink = false;
            } else if (data.billing_tier === 'control') {
              displayText = t('billing.link.control_plan');
              showLink = false;
            }

            billingTierElement.textContent = displayText;
            billingTierElement.onclick = showLink ? function() {
              window.location.href = 'billing.php';
            } : null;
            billingTierElement.style.cursor = showLink ? 'pointer' : 'default';
          }
        }

        // Check if trial has expired - show prominent upgrade prompt
        // Skip for bypass users (they never have trial_expired = true)
        if (data.is_trial && data.trial_expired) {
          // Show upgrade modal on first load when trial expired
          setTimeout(function() {
            showTrialExpiredModal();
          }, 500);
        }

        // Apply billing restrictions
        applyBillingRestrictions();
      })
      .catch(error => {
        const billingTierElement = document.getElementById('userBillingTier');
        if (billingTierElement) {
          billingTierElement.textContent = t('billing.link.evaluate_plan');
        }
      });
  }

  // Apply billing restrictions based on current tier
  function applyBillingRestrictions() {
    if (!billingInfo) return;

    const trialExpired = billingInfo.is_trial && billingInfo.trial_expired;

    // Disable case creation if trial expired or at limit
    const createCaseButton = document.querySelector('.create-case-button');
    if (createCaseButton) {
      if (trialExpired || !billingInfo.can_create_cases) {
        createCaseButton.disabled = true;
        createCaseButton.title = trialExpired ? t('billing.upgrade.trial_expired_cases') : t('billing.upgrade.create_more_cases');
        createCaseButton.style.opacity = '0.5';
        createCaseButton.style.cursor = 'not-allowed';
      } else {
        createCaseButton.disabled = false;
        createCaseButton.title = '';
        createCaseButton.style.opacity = '1';
        createCaseButton.style.cursor = 'pointer';
      }
    }

    // Add visual indicator to Insights tab when trial expired
    const insightsTab = document.querySelector('.main-tab[data-tab="insights"]');
    const labInsightsTab = document.querySelector('.main-tab[data-tab="lab-insights"]');
    if (labInsightsTab && trialExpired) {
      labInsightsTab.style.opacity = '0.5';
      labInsightsTab.title = t('insights.trial_expired.lab_insights');
    } else if (labInsightsTab) {
      labInsightsTab.style.opacity = '1';
      labInsightsTab.title = '';
    }
    if (insightsTab && trialExpired) {
      insightsTab.style.opacity = '0.5';
      insightsTab.title = t('insights.trial_expired.practice_insights');
    } else if (insightsTab) {
      insightsTab.style.opacity = '1';
      insightsTab.title = '';
    }

    // Disable drag-and-drop on kanban cards when trial expired
    if (trialExpired) {
      const kanbanCards = document.querySelectorAll('.kanban-card');
      kanbanCards.forEach(function(card) {
        card.setAttribute('draggable', 'false');
        card.style.cursor = 'default';
      });
    }

    // Disable user management in settings if not allowed
    // This will be handled when the settings modal is opened
  }

  // Check billing before creating a case (async version)
  async function checkBillingForCaseCreationAsync() {
    // Always fetch fresh billing info to ensure accurate case count
    try {
      var response = await fetch('api/billing.php', { credentials: 'same-origin' });
      var data = await response.json();
      if (!data.error) {
        billingInfo = data;
      }
    } catch (e) {
      // Silent fail - will check billingInfo below
    }

    // If still no billing info, block to be safe
    if (!billingInfo) {
      showToast(t('billing.errors.unable_verify_billing'), 'error');
      return false;
    }

    // Check if user cannot create cases (at limit)
    // can_create_cases is the authoritative check from the server
    if (!billingInfo.can_create_cases) {
      showUpgradeModal();
      return false;
    }

    return true;
  }

  // Synchronous check using cached billing info (for immediate UI feedback)
  function checkBillingForCaseCreation() {
    // Use cached billing info for immediate check
    if (!billingInfo) {
      // If no cached info, allow and let server validate
      return true;
    }

    // Check if user cannot create cases (at limit)
    if (!billingInfo.can_create_cases) {
      showUpgradeModal();
      return false;
    }

    return true;
  }

  // Show trial expired modal with encouraging messaging
  function showTrialExpiredModal() {
    // Remove any existing modal
    var existingModal = document.getElementById('trialExpiredModal');
    if (existingModal) {
      existingModal.remove();
    }

    // Create the trial expired modal with encouraging messaging
    var modal = document.createElement('div');
    modal.id = 'trialExpiredModal';
    modal.className = 'modal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 10001; display: flex; align-items: center; justify-content: center;';

    modal.innerHTML = `
      <div style="background: white; border-radius: 16px; padding: 40px; max-width: 520px; width: 90%; text-align: center; box-shadow: 0 25px 80px rgba(0,0,0,0.35);">
        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px;">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
          </svg>
        </div>
        <h2 style="margin: 0 0 8px; font-size: 1.75rem; color: #1f2937; font-weight: 700;">${t('billing.trial.modal.title')}</h2>
        <p style="margin: 0 0 20px; color: #6b7280; font-size: 1.05rem; line-height: 1.6;">
          ${t('billing.trial.modal.message')}
        </p>
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; margin-bottom: 24px; text-align: left;">
          <p style="margin: 0 0 8px; color: #166534; font-weight: 600; font-size: 0.95rem;">${t('billing.trial.modal.good_news_title')}</p>
          <p style="margin: 0; color: #15803d; font-size: 0.9rem; line-height: 1.5;">
            ${t('billing.trial.modal.good_news_message')}
          </p>
        </div>
        <div style="display: flex; flex-direction: column; gap: 12px;">
          <a href="billing.php" style="padding: 14px 28px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; border-radius: 10px; font-size: 1.05rem; text-decoration: none; font-weight: 600; transition: all 0.2s; display: block; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);">
            ${t('billing.trial.modal.choose_plan')}
          </a>
          <button id="trialExpiredClose" style="padding: 12px 24px; border: none; background: transparent; font-size: 0.9rem; cursor: pointer; color: #9ca3af; transition: all 0.2s;">
            ${t('billing.trial.modal.read_only')}
          </button>
        </div>
        <p style="margin: 20px 0 0; color: #9ca3af; font-size: 0.8rem;">
          ${t('billing.trial.modal.support', { email: 'support@dentatrak.com' })}
        </p>
      </div>
    `;

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Close button handler
    document.getElementById('trialExpiredClose').addEventListener('click', function() {
      modal.remove();
      document.body.style.overflow = '';
    });
  }

  // Show upgrade modal when case limit reached (not trial expired)
  function showUpgradeModal() {
    // If trial expired, show the trial expired modal instead
    if (billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
      showTrialExpiredModal();
      return;
    }

    // Remove any existing modal to ensure fresh state and proper centering
    var existingModal = document.getElementById('upgradePlanModal');
    if (existingModal) {
      existingModal.remove();
    }

    // Create the upgrade modal for case limit
    var modal = document.createElement('div');
    modal.id = 'upgradePlanModal';
    modal.className = 'modal';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10001; display: flex; align-items: center; justify-content: center;';

    modal.innerHTML = `
      <div style="background: white; border-radius: 12px; padding: 32px; max-width: 450px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <div style="width: 64px; height: 64px; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
          </svg>
        </div>
        <h2 style="margin: 0 0 12px; font-size: 1.5rem; color: #1f2937;">${t('billing.trial.upgrade_prompt.title')}</h2>
        <p style="margin: 0 0 24px; color: #6b7280; font-size: 1rem; line-height: 1.5;">
          ${t('billing.trial.upgrade_prompt.message')}
        </p>
        <div style="display: flex; gap: 12px; justify-content: center;">
          <button id="upgradeModalClose" style="padding: 12px 24px; border: 1px solid #d1d5db; background: white; border-radius: 8px; font-size: 0.95rem; cursor: pointer; color: #374151; transition: all 0.2s;">
            ${t('billing.trial.upgrade_prompt.later')}
          </button>
          <a href="billing.php" style="padding: 12px 24px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border-radius: 8px; font-size: 0.95rem; text-decoration: none; font-weight: 500; transition: all 0.2s; display: inline-block;">
            ${t('billing.trial.upgrade_prompt.view_plans')}
          </a>
        </div>
      </div>
    `;

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Close button handler
    document.getElementById('upgradeModalClose').addEventListener('click', function() {
      modal.remove();
      document.body.style.overflow = '';
    });

    // Close on backdrop click
    modal.addEventListener('click', function(e) {
      if (e.target === modal) {
        modal.remove();
        document.body.style.overflow = '';
      }
    });
  }

  // Main tabs functionality
  const mainTabs = document.querySelectorAll('.main-tab');
  const mainTabPanes = document.querySelectorAll('.main-tab-pane');
  const insightsSubtabs = document.querySelectorAll('.insights-subtab');

  function activateInsightsSubview(view, updateHash) {
    updateHash = updateHash !== false;
    mainTabPanes.forEach(p => p.classList.remove('active'));
    document.getElementById(view === 'labs' ? 'lab-insights-tab' : 'insights-tab').classList.add('active');

    insightsSubtabs.forEach(st => {
      const isActive = st.dataset.insightsSubtab === view;
      st.classList.toggle('active', isActive);
      st.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (view === 'practice') {
      loadAnalyticsScripts();
    } else if (view === 'labs') {
      loadLabInsightsScripts();
    }

    if (updateHash && window.history.replaceState) {
      window.history.replaceState(null, null, '#insights/' + view);
    }
  }

  mainTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const targetTab = tab.dataset.tab;

      // Check if user has access to analytics
      if (targetTab === 'analytics' && billingInfo && !billingInfo.has_analytics) {
        showToast(t('insights.upgrade.analytics_control'), 'warning');
        return;
      }

      // Block Insights tab when trial expired
      if (targetTab === 'insights' && billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
        showTrialExpiredModal();
        return;
      }

      // Remove active class from all tabs and panes
      mainTabs.forEach(t => t.classList.remove('active'));
      mainTabPanes.forEach(p => p.classList.remove('active'));

      // Add active class to clicked tab and corresponding pane
      tab.classList.add('active');
      document.getElementById(targetTab + '-tab').classList.add('active');

      // Insights top tab defaults to the Practice sub-tab
      if (targetTab === 'insights') {
        activateInsightsSubview('practice', false);
      }
    });
  });

  insightsSubtabs.forEach(st => {
    st.addEventListener('click', () => {
      const view = st.dataset.insightsSubtab;
      if (!view) { return; }
      // Keep the top Insights tab active; just switch the subview
      mainTabPanes.forEach(p => p.classList.remove('active'));
      activateInsightsSubview(view);
    });
  });

  // Deep linking: #insights/practice or #insights/labs
  function applyInitialInsightsHash() {
    const hash = (window.location.hash || '').replace(/^#/, '');
    if (hash === 'insights' || hash === 'insights/practice') {
      mainTabs.forEach(t => t.classList.remove('active'));
      mainTabPanes.forEach(p => p.classList.remove('active'));
      const insightsTab = document.querySelector('.main-tab[data-tab="insights"]');
      if (insightsTab) { insightsTab.classList.add('active'); }
      document.getElementById('insights-tab').classList.add('active');
      activateInsightsSubview('practice', false);
    } else if (hash === 'insights/labs') {
      mainTabs.forEach(t => t.classList.remove('active'));
      mainTabPanes.forEach(p => p.classList.remove('active'));
      const insightsTab = document.querySelector('.main-tab[data-tab="insights"]');
      if (insightsTab) { insightsTab.classList.add('active'); }
      document.getElementById('lab-insights-tab').classList.add('active');
      activateInsightsSubview('labs', false);
    }
  }

  applyInitialInsightsHash();
  window.addEventListener('hashchange', applyInitialInsightsHash);

  // Lazy load Chart.js (shared by both Practice Insights and Lab Insights)
  var chartJsLoaded = false;
  var chartJsLoading = false;
  var chartJsCallbacks = [];
  function ensureChartJsLoaded(callback) {
    if (chartJsLoaded) {
      callback();
      return;
    }
    chartJsCallbacks.push(callback);
    if (chartJsLoading) {
      return;
    }
    chartJsLoading = true;
    var chartScript = document.createElement('script');
    chartScript.src = 'https://cdn.jsdelivr.net/npm/chart.js';
    chartScript.onload = function() {
      chartJsLoaded = true;
      chartJsCallbacks.forEach(function(cb) { cb(); });
      chartJsCallbacks = [];
    };
    document.body.appendChild(chartScript);
  }

  // Lazy load Chart.js and analytics-pro.js
  var analyticsScriptsLoaded = false;
  function loadAnalyticsScripts() {
    if (analyticsScriptsLoaded) {
      // Scripts already loaded, just refresh data
      if (typeof window.loadAnalyticsProData === 'function') {
        setTimeout(function() { window.loadAnalyticsProData(); }, 100);
      }
      return;
    }

    ensureChartJsLoaded(function() {
      var analyticsScript = document.createElement('script');
      analyticsScript.src = 'js/analytics-pro.js?v=' + Date.now();
      analyticsScript.onload = function() {
        analyticsScriptsLoaded = true;
        // Initialize analytics after script loads
        if (typeof window.loadAnalyticsProData === 'function') {
          setTimeout(function() { window.loadAnalyticsProData(); }, 100);
        }
      };
      document.body.appendChild(analyticsScript);
    });
  }

  // Lazy load Chart.js and lab-insights.js
  var labInsightsScriptsLoaded = false;
  function loadLabInsightsScripts() {
    if (labInsightsScriptsLoaded) {
      if (typeof window.loadLabInsightsData === 'function') {
        setTimeout(function() { window.loadLabInsightsData(); }, 100);
      }
      return;
    }

    ensureChartJsLoaded(function() {
      var labInsightsScript = document.createElement('script');
      labInsightsScript.src = 'js/lab-insights.js?v=' + Date.now();
      labInsightsScript.onload = function() {
        labInsightsScriptsLoaded = true;
        if (typeof window.loadLabInsightsData === 'function') {
          setTimeout(function() { window.loadLabInsightsData(); }, 100);
        }
      };
      document.body.appendChild(labInsightsScript);
    });
  }

  // Practice Insights / Lab Insights already re-fetch and re-render (via
  // loadAnalyticsProData()/loadLabInsightsData()) every time their tab is
  // clicked, which naturally picks up the latest window.workflowStageLabels.
  // The one gap is a tab that's already the active/visible one at the
  // moment Settings is saved - reuse those exact same existing refresh
  // functions (no new rendering logic) so it doesn't keep showing stale
  // custom labels until the next tab switch.
  window.addEventListener('settingsUpdated', function() {
    if (analyticsScriptsLoaded && typeof window.loadAnalyticsProData === 'function') {
      window.loadAnalyticsProData();
    }
    if (labInsightsScriptsLoaded && typeof window.loadLabInsightsData === 'function') {
      window.loadLabInsightsData();
    }
  });

  // Archived Cases Modal functionality
  const archivedCasesModal = document.getElementById('archivedCasesModal');
  const viewArchivedBtn = document.getElementById('viewArchivedBtn');
  const archivedCasesClose = document.getElementById('archivedCasesClose');
  const archivedCasesFooterClose = document.getElementById('archivedCasesFooterClose');

  let archivedCurrentPage = 1;
  let archivedPageSize = 25;
  let archivedTotalCount = 0;

  // Open archived cases modal
  if (viewArchivedBtn) {
    viewArchivedBtn.addEventListener('click', () => {
      archivedCasesModal.style.display = 'block';
      document.body.style.overflow = 'hidden'; // Prevent body scroll
      loadArchivedCases();
    });
  }

  // Close archived cases modal
  if (archivedCasesClose) {
    archivedCasesClose.addEventListener('click', () => {
      archivedCasesModal.style.display = 'none';
      document.body.style.overflow = ''; // Restore body scroll
    });
  }

  // Close archived cases modal from footer
  if (archivedCasesFooterClose) {
    archivedCasesFooterClose.addEventListener('click', () => {
      archivedCasesModal.style.display = 'none';
      document.body.style.overflow = ''; // Restore body scroll
    });
  }

  // Move View Archived Cases button next to search bar
  if (viewArchivedBtn) {
    const dashboardSearch = document.querySelector('.dashboard-search');

    // Open archived cases modal
    viewArchivedBtn.addEventListener('click', () => {
      archivedCasesModal.style.display = 'block';
      loadArchivedCases();
    });
  }

  // Close archived cases modal
  if (archivedCasesClose) {
    archivedCasesClose.addEventListener('click', () => {
      archivedCasesModal.style.display = 'none';
    });
  }

  // Close modal when clicking outside
  window.addEventListener('click', (e) => {
    if (e.target === archivedCasesModal) {
      archivedCasesModal.style.display = 'none';
      document.body.style.overflow = ''; // Restore body scroll
    }
  });

  // Search and filter functionality
  const archivedSearch = document.getElementById('archivedSearch');
  const archivedPageSizeSelect = document.getElementById('archivedPageSize');
  const archivedDateRange = document.getElementById('archivedDateRange');
  const archivedCaseType = document.getElementById('archivedCaseType');
  const archivedClearFilters = document.getElementById('archivedClearFilters');

  // Single source of truth for archived filter defaults
  const archivedFilterDefaults = {
    search: '',
    dateRange: '',
    caseType: '',
    pageSize: '25'
  };

  function getArchivedFilterValues() {
    return {
      search: archivedSearch ? archivedSearch.value : '',
      dateRange: archivedDateRange ? archivedDateRange.value : '',
      caseType: archivedCaseType ? archivedCaseType.value : '',
      pageSize: archivedPageSizeSelect ? archivedPageSizeSelect.value : archivedFilterDefaults.pageSize
    };
  }

  function archivedFiltersAreActive() {
    const v = getArchivedFilterValues();
    return Object.keys(archivedFilterDefaults).some(function(key) {
      return v[key] !== archivedFilterDefaults[key];
    });
  }

  function updateArchivedClearFiltersButton() {
    if (archivedClearFilters) {
      archivedClearFilters.disabled = !archivedFiltersAreActive();
    }
  }

  function clearArchivedFilters() {
    if (archivedSearch) {
      archivedSearch.value = archivedFilterDefaults.search;
    }
    if (archivedSearchClearBtn) {
      archivedSearchClearBtn.style.display = 'none';
    }
    if (archivedDateRange) {
      archivedDateRange.value = archivedFilterDefaults.dateRange;
    }
    if (archivedCaseType) {
      archivedCaseType.value = archivedFilterDefaults.caseType;
    }
    if (archivedPageSizeSelect) {
      archivedPageSizeSelect.value = archivedFilterDefaults.pageSize;
    }
    archivedPageSize = parseInt(archivedFilterDefaults.pageSize, 10);
    filterArchivedCasesClientSide();
  }

  // Store all archived cases for client-side filtering
  let allArchivedCases = [];
  let filteredArchivedCases = [];

  // Client-side search function
  function filterArchivedCasesClientSide() {
    const search = archivedSearch ? archivedSearch.value.toLowerCase().trim() : '';
    const dateRange = archivedDateRange ? archivedDateRange.value : '';
    const caseType = archivedCaseType ? archivedCaseType.value : '';

    // Filter cases
    filteredArchivedCases = allArchivedCases.filter(case_ => {
      // Search filter (patient name + dentist) - handle both camelCase and snake_case
      if (search.length >= 2) {
        const patientFirstName = case_.patientFirstName || case_.patient_first_name || '';
        const patientLastName = case_.patientLastName || case_.patient_last_name || '';
        const dentistName = case_.dentistName || case_.dentist_name || '';

        const fullName = (patientFirstName + ' ' + patientLastName).toLowerCase();
        const dentistNameLower = dentistName.toLowerCase();

        if (!fullName.includes(search) && !dentistNameLower.includes(search)) {
          return false;
        }
      }

      // Date range filter
      if (dateRange > 0) {
        const archivedDate = new Date(case_.archived_date);
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - parseInt(dateRange));
        if (archivedDate < cutoffDate) {
          return false;
        }
      }

      // Case type filter
      if (caseType && case_.case_type !== caseType) {
        return false;
      }

      return true;
    });

    // Reset to page 1 and display filtered results
    archivedCurrentPage = 1;
    displayPaginatedArchivedCases();
    updateArchivedPagination(filteredArchivedCases.length);

    const countSpan = document.getElementById('archivedCount');
    countSpan.textContent = t('archive.pagination.results_count', {visible: Math.min(filteredArchivedCases.length, archivedPageSize), total: filteredArchivedCases.length});

    updateArchivedClearFiltersButton();
  }

  // Display paginated results from filtered data
  function displayPaginatedArchivedCases() {
    const startIndex = (archivedCurrentPage - 1) * archivedPageSize;
    const endIndex = startIndex + archivedPageSize;
    const pageData = filteredArchivedCases.slice(startIndex, endIndex);
    displayArchivedCases(pageData);
  }

  if (archivedSearch) {
    archivedSearch.addEventListener('input', () => {
      filterArchivedCasesClientSide();
      // Toggle clear button visibility
      if (archivedSearchClearBtn) {
        archivedSearchClearBtn.style.display = archivedSearch.value.length > 0 ? 'block' : 'none';
      }
    });

    // Add clear button for archived search
    const archivedSearchContainer = archivedSearch.parentElement;
    var archivedSearchClearBtn = null;
    if (archivedSearchContainer) {
      archivedSearchClearBtn = document.createElement('button');
      archivedSearchClearBtn.type = 'button';
      archivedSearchClearBtn.className = 'archived-search-clear-btn';
      archivedSearchClearBtn.innerHTML = '&times;';
      archivedSearchClearBtn.title = t('archive.search.clear');
      archivedSearchClearBtn.setAttribute('aria-label', t('archive.search.clear'))

      archivedSearchClearBtn.addEventListener('click', function() {
        archivedSearch.value = '';
        archivedSearchClearBtn.style.display = 'none';
        filterArchivedCasesClientSide();
        archivedSearch.focus();
      });

      archivedSearchContainer.appendChild(archivedSearchClearBtn);
    }
  }

  if (archivedPageSizeSelect) {
    archivedPageSizeSelect.addEventListener('change', () => {
      archivedCurrentPage = 1;
      const newPageSize = parseInt(archivedPageSizeSelect.value);
      if (newPageSize > 0) {
        archivedPageSize = newPageSize;
        displayPaginatedArchivedCases();
        updateArchivedPagination(filteredArchivedCases.length);

        const countSpan = document.getElementById('archivedCount');
        countSpan.textContent = t('archive.pagination.results_count', {visible: Math.min(filteredArchivedCases.length, archivedPageSize), total: filteredArchivedCases.length});
        updateArchivedClearFiltersButton();
      }
    });
  }

  if (archivedDateRange) {
    archivedDateRange.addEventListener('change', () => {
      filterArchivedCasesClientSide();
    });
  }

  if (archivedCaseType) {
    archivedCaseType.addEventListener('change', () => {
      filterArchivedCasesClientSide();
    });
  }

  if (archivedClearFilters) {
    archivedClearFilters.addEventListener('click', () => {
      clearArchivedFilters();
    });
  }

  // Pagination
  const archivedPrevPage = document.getElementById('archivedPrevPage');
  const archivedNextPage = document.getElementById('archivedNextPage');

  if (archivedPrevPage) {
    archivedPrevPage.addEventListener('click', () => {
      if (archivedCurrentPage > 1) {
        archivedCurrentPage--;
        displayPaginatedArchivedCases();
        updateArchivedPagination(filteredArchivedCases.length);
      }
    });
  }

  if (archivedNextPage) {
    archivedNextPage.addEventListener('click', () => {
      const totalPages = Math.ceil(filteredArchivedCases.length / archivedPageSize);
      if (archivedCurrentPage < totalPages) {
        archivedCurrentPage++;
        displayPaginatedArchivedCases();
        updateArchivedPagination(filteredArchivedCases.length);
      }
    });
  }

  function loadArchivedCases() {
    const tbody = document.getElementById('archivedCasesTableBody');
    const countSpan = document.getElementById('archivedCount');

    // Show loading state
    tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + t('archive.loading') + '</td></tr>';
    countSpan.textContent = t('common.loading');

    // Load all archived cases at once (server-side pagination removed)
    const params = new URLSearchParams({
      page: 1,
      pageSize: 1000, // Load all cases at once
      search: '',
      dateRange: '',
      caseType: ''
    });

    fetch(`api/get-archived-cases.php?${params}`, {
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        allArchivedCases = data.cases;
        filteredArchivedCases = [...data.cases]; // Start with all cases

        // Update the archived cases badge
        updateArchivedCasesBadge(data.cases.length);

        // Apply initial filters and display
        filterArchivedCasesClientSide();
      } else {
        tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + t('archive.error.loading') + '</td></tr>';
        countSpan.textContent = t('archive.error.loading_count');
      }
    })
    .catch(error => {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + t('archive.error.loading') + '</td></tr>';
      countSpan.textContent = t('archive.error.loading_count');
    });
  }

  // Update the archived cases badge on the button
  function updateArchivedCasesBadge(count) {
    const badge = document.getElementById('archivedCasesBadge');
    if (badge) {
      if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  }

  // Load archived case count on page load (without opening modal)
  function loadArchivedCaseCount() {
    fetch('api/get-archived-cases.php?page=1&pageSize=1', {
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.totalCount !== undefined) {
        updateArchivedCasesBadge(data.totalCount);
      }
    })
    .catch(() => {
      // Silently fail - badge just won't show
    });
  }

  // Load archived case count on page load
  loadArchivedCaseCount();

  function displayArchivedCases(cases) {
    const tbody = document.getElementById('archivedCasesTableBody');

    if (cases.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + t('archive.empty.no_cases') + '</td></tr>';
      return;
    }

    tbody.innerHTML = cases.map(case_ => `
      <tr>
        <td>${case_.patientFirstName || case_.patient_first_name || ''} ${case_.patientLastName || case_.patient_last_name || ''}</td>
        <td>${case_.dentistName || case_.dentist_name || ''}</td>
        <td>${getCaseTypeDisplayLabel(case_.caseType || case_.case_type) || ''}</td>
        <td>${case_.status ? escapeHtml(getStageLabel(case_.status)) : ''}</td>
        <td>${formatDate(case_.creation_date, false)}</td>
        <td>${formatDate(case_.archived_date, false)}</td>
        <td>
          <div class="archived-actions">
            <button type="button" class="btn-view" onclick="viewArchivedCase('${case_.id}')" title="${t('archive.actions.view_details')}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
              ${t('archive.actions.view')}
            </button>
            <button type="button" class="btn-print" onclick="printArchivedCase('${case_.id}')" title="${t('archive.actions.print_case')}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 6 2 18 2 18 9"></polyline>
                <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                <rect x="6" y="14" width="12" height="8"></rect>
              </svg>
              ${t('archive.actions.print')}
            </button>
            <button type="button" class="btn-restore" onclick="restoreArchivedCase('${case_.id}')" title="${t('archive.actions.restore_case')}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                <path d="M3 3v5h5"></path>
              </svg>
              ${t('archive.actions.restore')}
            </button>
          </div>
        </td>
      </tr>
    `).join('');
  }

  function updateArchivedPagination(totalCount) {
    archivedTotalCount = totalCount;
    const totalPages = Math.ceil(totalCount / archivedPageSize);

    const prevBtn = document.getElementById('archivedPrevPage');
    const nextBtn = document.getElementById('archivedNextPage');
    const pageInfo = document.getElementById('archivedPageInfo');

    if (prevBtn) prevBtn.disabled = archivedCurrentPage <= 1;
    if (nextBtn) nextBtn.disabled = archivedCurrentPage >= totalPages;

    if (pageInfo) pageInfo.textContent = t('archive.pagination.page_info', {current: archivedCurrentPage, total: totalPages || 1});
  }

  window.restoreArchivedCase = function(caseId) {
    // Note: Restore is allowed even when trial expired (symmetrical with archive - organizational operation)
    showRestoreConfirmation(caseId);
  };

  window.viewArchivedCase = function(caseId) {
    // Check if trial expired
    if (billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
      showUpgradeModal();
      return;
    }

    // Store the current state of the archived modal
    const archivedModalWasOpen = archivedCasesModal && archivedCasesModal.style.display === 'block';

    // Hide the archived modal temporarily
    if (archivedModalWasOpen) {
      archivedCasesModal.style.display = 'none';
      document.body.style.overflow = ''; // Restore body scroll
    }

    // Load case data and open modal in read-only mode
    fetch(`api/get-case.php?id=${caseId}`, {
      credentials: 'same-origin'
    })
    .then(function(response) {
      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }
      return response.json();
    })
    .then(function(data) {
      if (data.success && data.case) {
        if (typeof openCaseModalForView === 'function') {
          // Store the original close function
          const originalCloseCreateCase = closeCreateCase;

          // Override the close function to restore archived modal
          closeCreateCase = function() {
            // Call the original close function
            originalCloseCreateCase();

            // Restore the archived modal if it was open
            if (archivedModalWasOpen && archivedCasesModal) {
              archivedCasesModal.style.display = 'block';
              document.body.style.overflow = 'hidden'; // Prevent body scroll again
            }

            // Restore the original close function
            setTimeout(() => {
              closeCreateCase = originalCloseCreateCase;
            }, 100);
          };

          // Add a "Back to Archived Cases" button if coming from archived modal
          if (archivedModalWasOpen) {
            setTimeout(() => {
              const modalHeader = createCaseModal.querySelector('.modal-header');
              if (modalHeader) {
                // Check if back button already exists
                if (!modalHeader.querySelector('.back-to-archived')) {
                  const backButton = document.createElement('button');
                  backButton.className = 'back-to-archived';
                  backButton.innerHTML = '← ' + t('archive.back_to_archived');
                  backButton.style.cssText = `
                    background: #6c757d;
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 12px;
                    margin-right: 10px;
                  `;
                  backButton.onclick = () => {
                    createCaseModal.style.display = 'none';
                    if (archivedCasesModal) {
                      archivedCasesModal.style.display = 'block';
                      document.body.style.overflow = 'hidden';
                    }
                  };

                  // Insert before the close button
                  const closeBtn = modalHeader.querySelector('.btn-close');
                  if (closeBtn) {
                    modalHeader.insertBefore(backButton, closeBtn);
                  } else {
                    modalHeader.appendChild(backButton);
                  }
                }
              }
            }, 100);
          }

          openCaseModalForView(data.case);
        } else {
          showToast(t('cases.toast.open_failed'), 'error');

          // Restore archived modal if there was an error
          if (archivedModalWasOpen && archivedCasesModal) {
            archivedCasesModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
          }
        }
      } else {
        showToast(t('cases.toast.load_error', {message: data.message || t('common.unknown_error')}), 'error');

        // Restore archived modal if there was an error
        if (archivedModalWasOpen && archivedCasesModal) {
          archivedCasesModal.style.display = 'block';
          document.body.style.overflow = 'hidden';
        }
      }
    })
    .catch(error => {
      showToast(t('cases.toast.load_error'), 'error');

      // Restore archived modal if there was an error
      if (archivedModalWasOpen && archivedCasesModal) {
        archivedCasesModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
      }
    });
  };

  window.printArchivedCase = function(caseId) {
    // First get the case data, then print it using the same function as main cases
    fetch(`api/get-case.php?id=${caseId}`, {
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.case) {
        // Use the exact same printCase function as main cases
        printCase(data.case);
      } else {
        showToast(t('cases.toast.load_data_error'), 'error');
      }
    })
    .catch(error => {
      showToast(t('cases.toast.load_error'), 'error');
    });
  };

  function showRestoreConfirmation(caseId) {
    // Create a simple confirmation modal
    const modal = document.createElement('div');
    modal.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999999;
    `;

    const content = document.createElement('div');
    content.style.cssText = `
      background: white;
      padding: 30px;
      border-radius: 8px;
      max-width: 400px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    `;

    content.innerHTML = `
      <h3 style="margin: 0 0 15px 0; color: #22c55e;">${t('archive.confirm.restore_title')}</h3>
      <p style="margin: 0 0 20px 0; color: #333;">${t('archive.confirm.restore_message')}</p>
      <p style="margin: 0 0 25px 0; color: #666; font-size: 14px;">${t('archive.confirm.restore_reappear')}</p>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button id="cancelBtn" style="
          background: #e0e0e0;
          color: #333;
          border: none;
          padding: 8px 20px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('common.cancel')}</button>
        <button id="confirmBtn" style="
          background: #22c55e;
          color: white;
          border: none;
          padding: 8px 20px;
          border-radius: 4px;
          cursor: pointer;
          font-size: 14px;
        ">${t('archive.actions.restore')}</button>
      </div>
    `;

    modal.appendChild(content);
    document.body.appendChild(modal);

    // Get button references
    const cancelBtn = document.getElementById('cancelBtn');
    const confirmBtn = document.getElementById('confirmBtn');

    // Focus on the Restore button when modal opens
    setTimeout(() => {
      confirmBtn.focus();
    }, 100);

    // Add event listeners
    cancelBtn.onclick = () => {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', tabHandler);
      document.removeEventListener('keydown', escapeHandler);
      document.removeEventListener('keydown', enterHandler);
    };

    confirmBtn.onclick = () => {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', tabHandler);
      document.removeEventListener('keydown', escapeHandler);
      document.removeEventListener('keydown', enterHandler);

      // Perform the restore
      fetch('api/restore-case.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ caseId: caseId }),
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          loadArchivedCases(); // Refresh the archived cases list
          loadExistingCases(); // Refresh the main kanban board
          loadBillingInfo(); // Refresh billing info to update case count
          showToast(t('archive.messages.restored'), 'success');
        } else {
          showToast(t('archive.messages.restore_failed', {message: data.message}), 'error');
        }
      })
      .catch(error => {
        if (typeof NetworkErrorHandler !== 'undefined') {
          NetworkErrorHandler.handle(error, 'restoring case');
        } else {
          showToast(t('archive.messages.restore_failed_retry'), 'error');
        }
      });
    };

    // Tab trapping - only allow tabbing between the two buttons
    const tabHandler = (e) => {
      if (e.key === 'Tab') {
        e.preventDefault();
        // If focus is on cancel, move to restore
        if (document.activeElement === cancelBtn) {
          confirmBtn.focus();
        } else {
          // If focus is on restore or anything else, move to cancel
          cancelBtn.focus();
        }
      }
    };

    // Close on background click
    modal.onclick = (e) => {
      if (e.target === modal) {
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);
      }
    };

    // Close on Escape key
    const escapeHandler = (e) => {
      if (e.key === 'Escape') {
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);
      }
    };

    // Enter key triggers Restore
    const enterHandler = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault(); // Prevent form submission if any
        document.body.removeChild(modal);
        document.removeEventListener('keydown', tabHandler);
        document.removeEventListener('keydown', escapeHandler);
        document.removeEventListener('keydown', enterHandler);

        // Perform the restore after modal is removed
        fetch('api/restore-case.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ caseId: caseId }),
          credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            loadArchivedCases(); // Refresh the archived cases list
            loadExistingCases(); // Refresh the main kanban board
            loadBillingInfo(); // Refresh billing info to update case count
          showToast(t('archive.messages.restored'), 'success');
          } else {
            showToast(t('archive.messages.restore_failed', {message: data.message}), 'error');
          }
        })
        .catch(error => {
          if (typeof NetworkErrorHandler !== 'undefined') {
            NetworkErrorHandler.handle(error, 'restoring case');
          } else {
            showToast(t('archive.messages.restore_failed_retry'), 'error');
          }
        });
      }
    };

    document.addEventListener('keydown', tabHandler);
    document.addEventListener('keydown', escapeHandler);
    document.addEventListener('keydown', enterHandler);
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Load settings on page load to apply archive button visibility
  loadSettings();

  // Keyboard shortcut for opening archived cases: Ctrl+Shift+A (or Cmd+Shift+A on Mac)
  document.addEventListener('keydown', function(e) {
    // Check for Ctrl+Shift+A or Cmd+Shift+A
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'A') {
      e.preventDefault();

      // Do not trigger the shortcut while the page is loading
      if (pageLoadingOverlay && pageLoadingOverlay.style.display !== 'none' && pageLoadingOverlay.style.opacity !== '0') {
        return;
      }

      // Check if archived cases modal exists and view button exists
      if (archivedCasesModal && viewArchivedBtn) {
        // Open the archived cases modal
        archivedCasesModal.style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent body scroll
        loadArchivedCases();
      }
    }
  });

  // Fallback: Force archive buttons to be visible after 2 seconds if settings haven't loaded
  setTimeout(() => {
    var archiveButtons = document.querySelectorAll('.card-delete-btn');
    var hasVisibleButtons = Array.from(archiveButtons).some(btn =>
      window.getComputedStyle(btn).display !== 'none'
    );

    if (!hasVisibleButtons && archiveButtons.length > 0) {
      var dashboard = document.querySelector('.dashboard');
      if (dashboard) {
        dashboard.classList.add('allow-card-delete');
      }
    }
  }, 2000);

  // Kanban Filter Functionality
  function initKanbanFilters() {
    const patientSearch = document.getElementById('patientSearch');
    const filterCaseType = document.getElementById('filterCaseType');
    const filterAssignedTo = document.getElementById('filterAssignedTo');
    const filterCarrier = document.getElementById('filterCarrier');
    const filterLateCases = document.getElementById('filterLateCases');
    const filterDueSoon = document.getElementById('filterDueSoon');
    const filterApptRisk = document.getElementById('filterApptRisk');
    const filterAtRisk = document.getElementById('filterAtRisk');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const kanbanFilterActiveDot = document.getElementById('kanbanFilterActiveDot');

    let filterTimeout;

    function applyFilters() {
      clearTimeout(filterTimeout);
      filterTimeout = setTimeout(() => {
        const searchTerm = patientSearch ? patientSearch.value.trim() : '';
        const caseType = filterCaseType ? filterCaseType.value : '';
        const assignedTo = filterAssignedTo ? filterAssignedTo.value : '';
        const carrier = filterCarrier ? filterCarrier.value : '';
        const lateOnly = filterLateCases ? filterLateCases.checked : false;
        const dueSoon = filterDueSoon ? filterDueSoon.checked : false;
        const apptRiskOnly = filterApptRisk ? filterApptRisk.checked : false;
        const atRiskOnly = filterAtRisk ? filterAtRisk.checked : false;

        // Build query parameters
        const params = new URLSearchParams();
        if (searchTerm) params.append('search', searchTerm);
        if (caseType) params.append('case_type', caseType);
        if (assignedTo) params.append('assigned_to', assignedTo);
        if (carrier) params.append('carrier', carrier);
        if (lateOnly) params.append('late_only', 'true');
        if (dueSoon) params.append('due_soon', 'true');
        if (apptRiskOnly) params.append('appt_risk_only', 'true');
        if (atRiskOnly) params.append('at_risk_only', 'true');

        // Show loading state
        const kanbanBoard = document.querySelector('.kanban-board');
        if (kanbanBoard) kanbanBoard.classList.add('loading');

        // Fetch filtered cases
        fetch(`api/list-cases.php?${params.toString()}`, {
          method: 'GET',
          credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
          if (!data || !data.success || !Array.isArray(data.cases)) {
            return;
          }

          let filteredCases = data.cases;

          // Clear existing cases
          const columns = document.querySelectorAll('.kanban-column-body');
          columns.forEach(column => {
            const cards = column.querySelectorAll('.kanban-card');
            cards.forEach(card => card.remove());

            // Show empty message if column is empty
            if (column.children.length === 0) {
              const emptyMsg = document.createElement('p');
              emptyMsg.className = 'kanban-empty';
              emptyMsg.textContent = t('cases.no_cases_in_stage');
              column.appendChild(emptyMsg);
            }
          });

          // Add filtered cases
          filteredCases.forEach(caseData => {
            const clonedCase = JSON.parse(JSON.stringify(caseData));
            addCaseToKanban(clonedCase);
          });

          // Reconcile column counts from the actual rendered cards
          if (typeof window.updateColumnCounts === 'function') {
            window.updateColumnCounts();
          }

          // Apply past due highlighting
          if (typeof updatePastDueHighlighting === 'function') {
            updatePastDueHighlighting();
          }

          // Update filter active indicator
          if (kanbanFilterActiveDot) {
            const hasActiveFilters = !!(searchTerm || caseType || assignedTo || carrier || lateOnly || dueSoon || atRiskOnly);
            kanbanFilterActiveDot.style.display = hasActiveFilters ? 'block' : 'none';
          }
        })
        .catch(error => {
          console.error('Kanban filter error:', error);
        })
        .finally(() => {
          if (kanbanBoard) {
            kanbanBoard.classList.remove('loading');
          }
        });
      }, 300); // Debounce for 300ms
    }

    // Make applyFilters globally available
    window.applyFilters = applyFilters;

    // Add event listeners
    if (patientSearch) {
      patientSearch.addEventListener('input', applyFilters);
    }

    if (filterCaseType) {
      filterCaseType.addEventListener('change', applyFilters);
    }

    if (filterAssignedTo) {
      filterAssignedTo.addEventListener('change', applyFilters);
    }

    if (filterCarrier) {
      filterCarrier.addEventListener('change', applyFilters);
    }

    if (filterLateCases) {
      filterLateCases.addEventListener('change', function() {
        if (filterLateCases.checked && filterDueSoon) {
          filterDueSoon.checked = false;
        }
        applyFilters();
      });
    }

    if (filterDueSoon) {
      filterDueSoon.addEventListener('change', function() {
        if (filterDueSoon.checked && filterLateCases) {
          filterLateCases.checked = false;
        }
        applyFilters();
      });
    }

    if (filterApptRisk) {
      filterApptRisk.addEventListener('change', applyFilters);
    }

    if (filterAtRisk) {
      filterAtRisk.addEventListener('change', applyFilters);
    }

    if (clearFiltersBtn) {
      clearFiltersBtn.addEventListener('click', function() {
        // Clear all filters
        if (patientSearch) patientSearch.value = '';
        if (filterCaseType) filterCaseType.value = '';
        if (filterAssignedTo) filterAssignedTo.value = '';
        if (filterCarrier) filterCarrier.value = '';
        if (filterLateCases) filterLateCases.checked = false;
        if (filterDueSoon) filterDueSoon.checked = false;
        if (filterApptRisk) filterApptRisk.checked = false;
        if (filterAtRisk) filterAtRisk.checked = false;

        // Apply cleared filters
        applyFilters();
      });
    }
  }

  // Initialize filters when page loads
  initKanbanFilters();

  // ============================================
  // SECURITY SETTINGS FUNCTIONALITY
  // Change Password, Two-Factor Authentication, Data Export
  // ============================================
  (function initSecuritySettings() {

    // ============================================
    // PASSWORD VISIBILITY TOGGLE (Settings)
    // ============================================
    var settingsPasswordToggles = document.querySelectorAll('#settingsForm .password-toggle-btn');
    settingsPasswordToggles.forEach(function(btn) {
      btn.addEventListener('click', function() {
        var targetId = btn.getAttribute('data-target');
        var input = document.getElementById(targetId);
        if (!input) return;

        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.classList.toggle('is-visible', isPassword);
        btn.setAttribute('aria-label', isPassword ? t('settings.security.change_password.hide_password') : t('settings.security.change_password.show_password'));
      });
    });

    // ============================================
    // CHANGE PASSWORD FUNCTIONALITY
    // ============================================
    var currentPasswordInput = document.getElementById('currentPassword');
    var newPasswordInput = document.getElementById('newPassword');
    var confirmPasswordInput = document.getElementById('confirmNewPassword');
    var changePasswordBtn = document.getElementById('changePasswordBtn');
    var changePasswordError = document.getElementById('changePasswordError');
    var changePasswordSuccess = document.getElementById('changePasswordSuccess');
    var passwordMatchStatus = document.getElementById('passwordMatchStatus');

    // Password requirement elements
    var pwReqLength = document.getElementById('pwReqLength');
    var pwReqUpper = document.getElementById('pwReqUpper');
    var pwReqNumber = document.getElementById('pwReqNumber');
    var pwReqSpecial = document.getElementById('pwReqSpecial');

    // Validate password requirements in real-time
    function validatePasswordRequirements(password) {
      var hasLength = password.length >= 8;
      var hasUpper = /[A-Z]/.test(password);
      var hasNumber = /[0-9]/.test(password);
      var hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);

      if (pwReqLength) {
        pwReqLength.textContent = (hasLength ? '✓' : '✗') + ' ' + t('settings.security.change_password.requirements.length');
        pwReqLength.classList.toggle('valid', hasLength);
      }
      if (pwReqUpper) {
        pwReqUpper.textContent = (hasUpper ? '✓' : '✗') + ' ' + t('settings.security.change_password.requirements.upper');
        pwReqUpper.classList.toggle('valid', hasUpper);
      }
      if (pwReqNumber) {
        pwReqNumber.textContent = (hasNumber ? '✓' : '✗') + ' ' + t('settings.security.change_password.requirements.number');
        pwReqNumber.classList.toggle('valid', hasNumber);
      }
      if (pwReqSpecial) {
        pwReqSpecial.textContent = (hasSpecial ? '✓' : '✗') + ' ' + t('settings.security.change_password.requirements.special');
        pwReqSpecial.classList.toggle('valid', hasSpecial);
      }

      return hasLength && hasUpper && hasNumber && hasSpecial;
    }

    // Check password match
    function checkPasswordMatch() {
      if (!confirmPasswordInput || !newPasswordInput || !passwordMatchStatus) return;

      var newPw = newPasswordInput.value;
      var confirmPw = confirmPasswordInput.value;

      if (confirmPw === '') {
        passwordMatchStatus.textContent = '';
        passwordMatchStatus.className = 'password-match';
      } else if (newPw === confirmPw) {
        passwordMatchStatus.textContent = '✓ ' + t('settings.security.change_password.match_yes');
        passwordMatchStatus.className = 'password-match match';
      } else {
        passwordMatchStatus.textContent = '✗ ' + t('settings.security.change_password.match_no');
        passwordMatchStatus.className = 'password-match no-match';
      }
    }

    if (newPasswordInput) {
      newPasswordInput.addEventListener('input', function() {
        validatePasswordRequirements(newPasswordInput.value);
        checkPasswordMatch();
      });
    }

    if (confirmPasswordInput) {
      confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }

    // Handle change password submission
    if (changePasswordBtn) {
      changePasswordBtn.addEventListener('click', function() {
        // Hide previous messages
        if (changePasswordError) changePasswordError.style.display = 'none';
        if (changePasswordSuccess) changePasswordSuccess.style.display = 'none';

        var currentPw = currentPasswordInput ? currentPasswordInput.value : '';
        var newPw = newPasswordInput ? newPasswordInput.value : '';
        var confirmPw = confirmPasswordInput ? confirmPasswordInput.value : '';

        // Client-side validation
        if (!currentPw) {
          showChangePasswordError(t('settings.security.change_password.validation.current_required'));
          return;
        }

        if (!validatePasswordRequirements(newPw)) {
          showChangePasswordError(t('settings.security.change_password.validation.requirements_not_met'));
          return;
        }

        if (newPw !== confirmPw) {
          showChangePasswordError(t('settings.security.change_password.validation.not_match'));
          return;
        }

        // Disable button during request
        changePasswordBtn.disabled = true;
        changePasswordBtn.textContent = t('settings.security.change_password.button_changing');

        // Send request to server
        fetch('api/change-password.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({
            currentPassword: currentPw,
            newPassword: newPw,
            confirmPassword: confirmPw
          }),
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            showChangePasswordSuccess(t('settings.security.change_password.success'));
            // Clear form
            if (currentPasswordInput) currentPasswordInput.value = '';
            if (newPasswordInput) newPasswordInput.value = '';
            if (confirmPasswordInput) confirmPasswordInput.value = '';
            validatePasswordRequirements('');
            checkPasswordMatch();
          } else {
            showChangePasswordError(data.message || t('settings.security.change_password.error'));
          }
        })
        .catch(function() {
          showChangePasswordError(t('settings.security.change_password.unknown_error'));
        })
        .finally(function() {
          changePasswordBtn.disabled = false;
          changePasswordBtn.textContent = t('settings.security.change_password.button');
        });
      });
    }

    function showChangePasswordError(message) {
      if (changePasswordError) {
        changePasswordError.textContent = message;
        changePasswordError.style.display = 'block';
      }
    }

    function showChangePasswordSuccess(message) {
      if (changePasswordSuccess) {
        changePasswordSuccess.textContent = message;
        changePasswordSuccess.style.display = 'block';
      }
    }

    // ============================================
    // TWO-FACTOR AUTHENTICATION FUNCTIONALITY
    // ============================================
    var twoFactorStatus = document.getElementById('twoFactorStatus');
    var twoFactorSetup = document.getElementById('twoFactorSetup');
    var twoFactorDisable = document.getElementById('twoFactorDisable');
    var twoFactorActions = document.getElementById('twoFactorActions');
    var enableTwoFactorBtn = document.getElementById('enableTwoFactorBtn');
    var disableTwoFactorBtn = document.getElementById('disableTwoFactorBtn');
    var verifyTwoFactorBtn = document.getElementById('verifyTwoFactorBtn');
    var cancelTwoFactorSetup = document.getElementById('cancelTwoFactorSetup');
    var twoFactorQRCode = document.getElementById('twoFactorQRCode');
    var twoFactorSecret = document.getElementById('twoFactorSecret');
    var twoFactorVerifyCode = document.getElementById('twoFactorVerifyCode');
    var twoFactorSetupError = document.getElementById('twoFactorSetupError');
    var confirmDisableTwoFactor = document.getElementById('confirmDisableTwoFactor');
    var cancelDisableTwoFactor = document.getElementById('cancelDisableTwoFactor');
    var twoFactorDisableError = document.getElementById('twoFactorDisableError');

    // Load 2FA status when settings modal opens
    function load2FAStatus() {
      fetch('api/2fa-setup.php?action=status', { credentials: 'same-origin' })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            update2FAStatusUI(data.enabled);
          }
        })
        .catch(function() {
          // Silently fail - 2FA status will show as disabled
        });
    }

    function update2FAStatusUI(enabled) {
      var statusBadge = twoFactorStatus ? twoFactorStatus.querySelector('.status-badge') : null;

      if (statusBadge) {
        if (enabled) {
          statusBadge.textContent = t('settings.security.two_factor.status_enabled');
          statusBadge.className = 'status-badge status-enabled';
        } else {
          statusBadge.textContent = t('settings.security.two_factor.status_disabled');
          statusBadge.className = 'status-badge status-disabled';
        }
      }

      if (enableTwoFactorBtn) enableTwoFactorBtn.style.display = enabled ? 'none' : 'inline-flex';
      if (disableTwoFactorBtn) disableTwoFactorBtn.style.display = enabled ? 'inline-flex' : 'none';
      if (twoFactorSetup) twoFactorSetup.style.display = 'none';
      if (twoFactorDisable) twoFactorDisable.style.display = 'none';
      if (twoFactorActions) twoFactorActions.style.display = 'flex';
    }

    // Enable 2FA - Start setup
    if (enableTwoFactorBtn) {
      enableTwoFactorBtn.addEventListener('click', function() {
        enableTwoFactorBtn.disabled = true;
        enableTwoFactorBtn.textContent = t('settings.security.two_factor.setup.loading');

        fetch('api/2fa-setup.php?action=setup', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrfToken },
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            // Show QR code and secret
            if (twoFactorQRCode) twoFactorQRCode.innerHTML = data.qrCode;
            if (twoFactorSecret) twoFactorSecret.textContent = data.secret;
            if (twoFactorSetup) twoFactorSetup.style.display = 'block';
            if (twoFactorActions) twoFactorActions.style.display = 'none';
            if (twoFactorVerifyCode) twoFactorVerifyCode.value = '';
            if (twoFactorSetupError) twoFactorSetupError.style.display = 'none';
          } else {
            if (typeof Toast !== 'undefined') {
              Toast.error(t('settings.security.two_factor.setup.setup_title'), data.message || t('settings.security.two_factor.setup.unknown_error'));
            }
          }
        })
        .catch(function() {
          if (typeof Toast !== 'undefined') {
            Toast.error(t('settings.security.two_factor.setup.setup_title'), t('settings.security.two_factor.setup.unknown_error'));
          }
        })
        .finally(function() {
          enableTwoFactorBtn.disabled = false;
          enableTwoFactorBtn.textContent = t('settings.security.two_factor.enable');
        });
      });
    }

    // Verify 2FA code
    if (verifyTwoFactorBtn) {
      verifyTwoFactorBtn.addEventListener('click', function() {
        var code = twoFactorVerifyCode ? twoFactorVerifyCode.value.trim() : '';

        if (!code || code.length !== 6) {
          if (twoFactorSetupError) {
            twoFactorSetupError.textContent = t('settings.security.two_factor.setup.code_required');
            twoFactorSetupError.style.display = 'block';
          }
          return;
        }

        verifyTwoFactorBtn.disabled = true;
        verifyTwoFactorBtn.textContent = t('settings.security.two_factor.setup.verifying');

        fetch('api/2fa-setup.php?action=verify', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({ code: code }),
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            update2FAStatusUI(true);
            if (typeof Toast !== 'undefined') {
              Toast.success(t('settings.security.two_factor.setup.success_title'), t('settings.security.two_factor.setup.success_message'));
            }
          } else {
            if (twoFactorSetupError) {
              twoFactorSetupError.textContent = data.message || t('settings.security.two_factor.setup.invalid');
              twoFactorSetupError.style.display = 'block';
            }
          }
        })
        .catch(function() {
          if (twoFactorSetupError) {
            twoFactorSetupError.textContent = t('settings.security.two_factor.setup.unknown_error');
            twoFactorSetupError.style.display = 'block';
          }
        })
        .finally(function() {
          verifyTwoFactorBtn.disabled = false;
          verifyTwoFactorBtn.textContent = t('settings.security.two_factor.setup.verify');
        });
      });
    }

    // Handle Enter key on verification code input
    if (twoFactorVerifyCode && verifyTwoFactorBtn) {
      twoFactorVerifyCode.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          verifyTwoFactorBtn.click();
        }
      });
    }

    // Cancel 2FA setup
    if (cancelTwoFactorSetup) {
      cancelTwoFactorSetup.addEventListener('click', function() {
        if (twoFactorSetup) twoFactorSetup.style.display = 'none';
        if (twoFactorActions) twoFactorActions.style.display = 'flex';
      });
    }

    // Show disable 2FA confirmation
    if (disableTwoFactorBtn) {
      disableTwoFactorBtn.addEventListener('click', function() {
        if (twoFactorDisable) twoFactorDisable.style.display = 'block';
        if (twoFactorActions) twoFactorActions.style.display = 'none';
        if (twoFactorDisableError) twoFactorDisableError.style.display = 'none';
      });
    }

    // Confirm disable 2FA
    if (confirmDisableTwoFactor) {
      confirmDisableTwoFactor.addEventListener('click', function() {
        confirmDisableTwoFactor.disabled = true;
        confirmDisableTwoFactor.textContent = t('settings.security.two_factor.disable.disabling');

        fetch('api/2fa-setup.php?action=disable', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (data.success) {
            update2FAStatusUI(false);
            if (typeof Toast !== 'undefined') {
              Toast.success(t('settings.security.two_factor.disable.success_title'), t('settings.security.two_factor.disable.success_message'));
            }
          } else {
            if (twoFactorDisableError) {
              twoFactorDisableError.textContent = data.message || t('settings.security.two_factor.disable.error');
              twoFactorDisableError.style.display = 'block';
            }
          }
        })
        .catch(function() {
          if (twoFactorDisableError) {
            twoFactorDisableError.textContent = t('settings.security.two_factor.disable.unknown_error');
            twoFactorDisableError.style.display = 'block';
          }
        })
        .finally(function() {
          confirmDisableTwoFactor.disabled = false;
          confirmDisableTwoFactor.textContent = t('settings.security.two_factor.disable.button');
        });
      });
    }

    // Cancel disable 2FA
    if (cancelDisableTwoFactor) {
      cancelDisableTwoFactor.addEventListener('click', function() {
        if (twoFactorDisable) twoFactorDisable.style.display = 'none';
        if (twoFactorActions) twoFactorActions.style.display = 'flex';
      });
    }

    // ============================================
    // DATA EXPORT FUNCTIONALITY
    // ============================================
    var exportDataBtn = document.getElementById('exportDataBtn');
    var exportStatus = document.getElementById('exportStatus');

    if (exportDataBtn) {
      exportDataBtn.addEventListener('click', function() {
        // Show styled confirmation modal instead of native confirm()
        showConfirmModal(
          t('settings.data_privacy.export.confirm_title'),
          t('settings.data_privacy.export.confirm_message'),
          function() {
            // User confirmed - proceed with export
            exportDataBtn.disabled = true;
            exportDataBtn.innerHTML = '<span class="btn-icon">⏳</span> ' + t('settings.data_privacy.export.preparing');

            if (exportStatus) {
              exportStatus.textContent = t('settings.data_privacy.export.preparing_status');
              exportStatus.className = 'export-status';
              exportStatus.style.display = 'block';
            }

            fetch('api/data-export.php?action=request', {
              method: 'POST',
              headers: { 'X-CSRF-Token': csrfToken },
              credentials: 'same-origin'
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
              if (data.success) {
                if (exportStatus) {
                  exportStatus.textContent = t('settings.data_privacy.export.success_status');
                  exportStatus.className = 'export-status success';
                }
                if (typeof Toast !== 'undefined') {
                  Toast.success(t('settings.data_privacy.export.success_title'), t('settings.data_privacy.export.success_message'));
                }
              } else {
                if (exportStatus) {
                  exportStatus.textContent = data.message || t('settings.data_privacy.export.error');
                  exportStatus.className = 'export-status error';
                }
              }
            })
            .catch(function() {
              if (exportStatus) {
                exportStatus.textContent = t('settings.data_privacy.export.unknown_error');
                exportStatus.className = 'export-status error';
              }
            })
            .finally(function() {
              exportDataBtn.disabled = false;
              exportDataBtn.innerHTML = '<span class="btn-icon">📥</span> ' + t('settings.data_privacy.export.button');
            });
          },
          null // No action needed on cancel
        );
      });
    }

    // Load 2FA status when settings modal opens
    var settingsModal = document.getElementById('settingsBillingModal');
    if (settingsModal) {
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.attributeName === 'style') {
            var display = settingsModal.style.display;
            if (display === 'block') {
              load2FAStatus();
            }
          }
        });
      });
      observer.observe(settingsModal, { attributes: true });
    }

    // Expose functions globally for real-time updates module
    window.addCaseToKanban = addCaseToKanban;
    window.updateColumnCounts = updateColumnCounts;

  })();
});
