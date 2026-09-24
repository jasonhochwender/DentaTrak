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

// Practice-wide 2FA enforcement: if ANY API responds that the current
// practice requires 2FA this session hasn't satisfied (e.g. enforcement
// was enabled mid-session, or the session was restored via Remember Me),
// route the whole app through the challenge/enrollment page instead of
// leaving every subsequent request to fail. The response is cloned before
// inspection so callers still receive the original untouched response.
(function () {
  var originalFetch = window.fetch;
  window.fetch = function (input, init) {
    return originalFetch.apply(this, arguments).then(function (response) {
      if (!response.ok) {
        try {
          response.clone().json().then(function (data) {
            if (data && (data.error_code === 'PRACTICE_2FA_SETUP_REQUIRED' ||
                         data.error_code === 'PRACTICE_2FA_CHALLENGE_REQUIRED')) {
              window.location.href = data.redirect || '2fa-required.php';
            }
          }).catch(function () {});
        } catch (e) {}
      }
      return response;
    });
  };
})();

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
  var defaults = ['Originated', 'Sent To External Lab', 'Designed', 'Manufactured', 'Received From External Lab', 'Delivered'];
  if (defaults.indexOf(status) === -1) {
    return 'kanban-card-custom';
  }
  return 'kanban-card-' + String(status || '').toLowerCase().replace(/\s+/g, '-');
}
window.getWorkflowStatusCssClass = getWorkflowStatusCssClass;

/**
 * Normalize a boolean-ish preference value from the server/localStorage.
 * Handles JS booleans, numbers, and strings (e.g. "0", "1", "true", "false").
 * Empty/null/undefined falls back to the caller-supplied default.
 */
function toBoolean(value, defaultValue) {
  if (typeof value === 'boolean') return value;
  if (value === null || value === undefined) return defaultValue;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') {
    var s = value.trim().toLowerCase();
    if (s === 'false' || s === '0' || s === 'no' || s === 'off' || s === '') return false;
    if (s === 'true' || s === '1' || s === 'yes' || s === 'on') return true;
  }
  return defaultValue;
}
window.toBoolean = toBoolean;

/**
 * Return the internal status id of the practice's final (last active)
 * workflow column. Uses the persisted workflow column snapshot when available;
 * otherwise falls back to the last visible kanban column in the DOM, and only
 * finally falls back to the legacy 'Delivered' default. This keeps the
 * correct final column for every user (including non-admins) and before
 * settings snapshots have loaded.
 */
function getFinalWorkflowColumnStatus() {
  var snapshot = (typeof window !== 'undefined' && window.workflowColumnsSnapshot) || null;
  var active = snapshot && snapshot.active;
  if (Array.isArray(active) && active.length > 0) {
    var last = active[active.length - 1];
    if (last && typeof last.id === 'string') {
      return last.id;
    }
  }

  // If the snapshot is missing (e.g. non-admin users), derive the final
  // column from the actual rendered board columns.
  if (typeof document !== 'undefined') {
    var columns = document.querySelectorAll('.kanban-column');
    if (columns.length > 0) {
      var lastColumn = columns[columns.length - 1];
      if (lastColumn && typeof lastColumn.dataset.status === 'string') {
        return lastColumn.dataset.status;
      }
    }
  }

  return 'Delivered';
}

/**
 * Determine whether a case status corresponds to the final workflow column
 * for the current practice. Completed cases in this column should not show
 * Late / Due Soon / Appointment Risk warnings.
 */
function isFinalWorkflowColumn(status) {
  return status === getFinalWorkflowColumnStatus();
}

/**
 * Determine whether the current viewport is a phone width. Used to keep
 * drag-and-drop and the desktop card layout disabled on phones while
 * the mobile kanban carousel and compact cards take over.
 */
function isTouchPhone() {
  return window.matchMedia('(max-width: 480px)').matches;
}

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
  if (window.allWorkflowStageLabels && Object.prototype.hasOwnProperty.call(window.allWorkflowStageLabels, internalStatus)) {
    return window.allWorkflowStageLabels[internalStatus];
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
async function switchPractice(practiceId, triggerElement) {
  if (!practiceId) return;

  // Prevent duplicate switch requests.
  if (window.switchPracticeInProgress) return;
  window.switchPracticeInProgress = true;

  // Show loading indicator and disable the control that triggered the switch.
  var loadingOverlay = document.getElementById('pageLoadingOverlay');
  if (loadingOverlay) {
    loadingOverlay.style.display = 'flex';
    loadingOverlay.style.opacity = '1';
  }

  if (triggerElement && typeof triggerElement.setAttribute === 'function') {
    triggerElement.setAttribute('aria-busy', 'true');
    triggerElement.disabled = true;
  }

  function restoreControls() {
    window.switchPracticeInProgress = false;
    if (loadingOverlay) {
      loadingOverlay.style.display = 'none';
      loadingOverlay.style.opacity = '0';
    }
    if (triggerElement && typeof triggerElement.removeAttribute === 'function') {
      triggerElement.removeAttribute('aria-busy');
      triggerElement.disabled = false;
      triggerElement.focus();
    }
  }

  try {
    if (window.caseFilterSort) await window.caseFilterSort.flush();
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
      // Reload the page to get fresh context for the new practice.
      window.location.reload();
    } else {
      restoreControls();
      showToast(data.message || data.error || t('practice_switcher.switch_failed'), 'error');
    }
  } catch (error) {
    restoreControls();

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
function showConfirmModal(title, message, onConfirm, onCancel, preventBackgroundClose, returnFocus) {
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

  function closeConfirmModal() {
    modal.style.display = 'none';
    modal.onclick = null;
    if (escapeHandler) {
      document.removeEventListener('keydown', escapeHandler, true);
    }
    if (returnFocus && returnFocus.focus && !returnFocus.disabled) {
      returnFocus.focus();
    }
  }

  // Escape dismisses only the confirm modal. Use capture phase so this runs
  // before the Settings modal's own Escape handler and prevents it from also
  // closing the parent modal.
  function escapeHandler(e) {
    if (e.key === 'Escape' && modal.style.display === 'block') {
      closeConfirmModal();
      if (onCancel) onCancel();
      e.stopImmediatePropagation();
    }
  }
  document.addEventListener('keydown', escapeHandler, true);

  // Clean up old event listeners by cloning buttons
  var newOkBtn = okBtn.cloneNode(true);
  var newCancelBtn = cancelBtn.cloneNode(true);
  okBtn.parentNode.replaceChild(newOkBtn, okBtn);
  cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

  // Add new event listeners
  newOkBtn.addEventListener('click', function() {
    closeConfirmModal();
    if (onConfirm) onConfirm();
  });

  newCancelBtn.addEventListener('click', function() {
    closeConfirmModal();
    if (onCancel) onCancel();
  });

  // Close on background click (unless prevented)
  if (!preventBackgroundClose) {
    modal.onclick = function(e) {
      if (e.target === modal) {
        e.stopPropagation();
        closeConfirmModal();
        if (onCancel) onCancel();
      }
    };
  } else {
    modal.onclick = null; // Remove background close handler
  }
}

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
  // Inline field-error helpers shared by client validation and the
  // server field-error mapping (missingFields/field responses).
  function addCaseFieldError(field, message) {
    if (!field) return;
    field.classList.add('field-error');
    if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
      var errorMessage = document.createElement('div');
      errorMessage.className = 'error-message';
      errorMessage.textContent = message || t('validation.required');
      field.parentNode.insertBefore(errorMessage, field.nextSibling);
    }
  }

  function clearCaseFieldError(field) {
    if (!field) return;
    field.classList.remove('field-error');
    if (field.nextElementSibling && field.nextElementSibling.classList.contains('error-message')) {
      field.nextElementSibling.remove();
    }
  }

  // Maps a server-reported field name to its form control id. Clinical
  // fields arrive as 'clinical_<key>' (e.g. 'clinical_toothNumber' ->
  // 'clinicalToothNumber'); general field names already match their ids.
  function serverFieldToElementId(name) {
    if (typeof name !== 'string') return null;
    if (name.indexOf('clinical_') === 0) {
      var key = name.slice(9);
      return 'clinical' + key.charAt(0).toUpperCase() + key.slice(1);
    }
    return name;
  }

  // Renders inline errors for server-reported field failures
  // (missingFields / field) so the user sees exactly which fields need
  // attention instead of only a toast. Returns true when at least one
  // field could be mapped and highlighted.
  function applyServerFieldErrors(error) {
    var names = [];
    if (error && Array.isArray(error.missingFields)) {
      names = names.concat(error.missingFields);
    }
    if (error && error.field) {
      names.push(error.field);
    }
    var first = null;
    names.forEach(function(name) {
      var el = document.getElementById(serverFieldToElementId(name));
      if (el) {
        addCaseFieldError(el);
        if (!first) first = el;
      }
    });
    if (first) {
      if (typeof setCaseModalActiveTab === 'function') {
        setCaseModalActiveTab('details');
      }
      first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      try { first.focus(); } catch (e) {}
      return true;
    }
    return false;
  }

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
      var meta = window.assignmentLabelsMeta && window.assignmentLabelsMeta[idx];
      var currentRecipients = meta ? (meta.recipients || []) : [];
      var selectedCount = currentRecipients.length;

      var item = document.createElement('div');
      item.className = 'gmail-user-item assignment-label-card';

      // Card header: label name (with lab indicator when enabled) on the left,
      // Rename/Delete on the right.
      var header = document.createElement('div');
      header.className = 'assignment-label-header';

      var nameGroup = document.createElement('div');
      nameGroup.className = 'assignment-label-name-group';

      var labelSpan = document.createElement('span');
      labelSpan.className = 'gmail-user-email';
      labelSpan.textContent = label;
      nameGroup.appendChild(labelSpan);

      // Lab checkbox - only rendered while SHOW_LAB_INSIGHTS is enabled.
      if (showLabInsights) {
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
        nameGroup.appendChild(labWrapper);
      }

      header.appendChild(nameGroup);

      // Edit/delete controls are only rendered for Practice Administrators.
      if (window.isPracticeAdmin) {
        var actions = document.createElement('div');
        actions.className = 'assignment-actions';

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'edit-assignment-label';
        editBtn.innerHTML = '✎';
        editBtn.title = t('settings.users.shared_assignment_labels.actions.edit');
        editBtn.setAttribute('data-label', label);
        editBtn.addEventListener('click', function() {
          editAssignmentLabel(this.getAttribute('data-label'));
        });

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
        removeBtn.setAttribute('data-label', label);
        removeBtn.addEventListener('click', function() {
          removeAssignmentLabel(this.getAttribute('data-label'));
        });

        actions.appendChild(editBtn);
        actions.appendChild(removeBtn);
        header.appendChild(actions);
      }

      item.appendChild(header);

      // Recipient notification section (admin only).
      if (window.isPracticeAdmin && window.practiceUsers && window.practiceUsers.length > 0) {
        function buildSummaryText(count) {
          return (t('settings.users.shared_assignment_labels.recipients.label') || 'People notified when this label changes') +
            ' (' + (count === 0
              ? (t('settings.users.shared_assignment_labels.recipients.none') || 'None')
              : t('settings.users.shared_assignment_labels.recipients.selected_count', { count: count })) +
            ')';
        }

        var details = document.createElement('details');
        details.className = 'assignment-label-recipients';
        details.setAttribute('data-idx', idx);

        var summary = document.createElement('summary');
        summary.className = 'assignment-label-recipients-summary';
        summary.textContent = buildSummaryText(selectedCount);
        details.appendChild(summary);

        var list = document.createElement('div');
        list.className = 'recipient-list';

        window.practiceUsers.forEach(function(user) {
          var cbId = 'recipient-' + idx + '-' + user.id;
          var row = document.createElement('div');
          row.className = 'recipient-row';

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

          checkbox.addEventListener('change', function() {
            var userId = parseInt(this.getAttribute('data-user-id'), 10);
            if (!meta) return;
            if (this.checked) {
              if (meta.recipients.indexOf(userId) === -1) meta.recipients.push(userId);
            } else {
              meta.recipients = meta.recipients.filter(function(id) { return id !== userId; });
            }
            summary.textContent = buildSummaryText(meta.recipients.length);
          });

          row.appendChild(checkbox);
          row.appendChild(userLabel);
          list.appendChild(row);
        });

        details.appendChild(list);
        item.appendChild(details);
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
  // Exclude create case modal close button, settings modal close button, and
  // the integration config modal close button - they have their own handlers
  // (unsaved-changes checks / child-modal cleanup). Routing them through the
  // generic closeModals() would hide EVERY open modal including parents.
  var createCaseCloseBtn = document.getElementById('createCaseClose');
  var settingsBillingCloseBtn = document.getElementById('settingsBillingClose');
  var integrationConfigCloseBtn = document.getElementById('integrationConfigClose');
  closeBtns.forEach(btn => {
    if (btn !== createCaseCloseBtn && btn !== settingsBillingCloseBtn && btn !== integrationConfigCloseBtn) {
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
      // The integration config modal is a child of Settings - its backdrop
      // click must close only itself, never every open modal.
      var integrationModal = document.getElementById('integrationConfigModal');
      if (e.target === integrationModal) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof window.closeIntegrationConfigModal === 'function') {
          window.closeIntegrationConfigModal();
        }
        return;
      }
      closeModals();
    }
  });

  // Close modal with Escape key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      // The attachment viewer overlays the case modal and has its own
      // document-level Escape handler - let it dismiss first so one keypress
      // does not close both layers.
      var attachmentViewerModal = document.getElementById('attachmentViewerModal');
      if (attachmentViewerModal && attachmentViewerModal.style.display === 'flex') {
        return;
      }

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
      cleanupUserMenuDividers();
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

  // On phones, suppress any .user-menu-divider that no longer has a visible
  // .user-menu-item on both sides (e.g. Settings and Billing hidden on a phone
  // leaves the admin-group divider stranded). Reset on wider viewports.
  function cleanupUserMenuDividers() {
    if (!userMenu) return;
    var isPhone = window.matchMedia('(max-width: 767px)').matches;
    var dividers = userMenu.querySelectorAll('.user-menu-divider');
    dividers.forEach(function(divider) {
      if (!isPhone) {
        divider.style.display = '';
        return;
      }

      function hasVisibleItem(dir) {
        var el = divider[dir];
        while (el) {
          if (el.classList && el.classList.contains('user-menu-item')) {
            if (window.getComputedStyle(el).display !== 'none') return true;
          }
          el = el[dir];
        }
        return false;
      }

      var visibleBefore = hasVisibleItem('previousElementSibling');
      var visibleAfter = hasVisibleItem('nextElementSibling');
      divider.style.display = (visibleBefore && visibleAfter) ? '' : 'none';
    });
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

    // Keep menu dividers tidy when the viewport moves across the phone
    // breakpoint (and on load, so the initial hidden state is correct).
    window.addEventListener('resize', cleanupUserMenuDividers);
    cleanupUserMenuDividers();
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

        switchPractice(practiceId, this);
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

  // Phone-sized viewport check used by the Settings open guard and resize handler.
  function isPhoneViewport() {
    return window.matchMedia('(max-width: 767px)').matches;
  }

  function openSettingsBillingModal() {
    if (!settingsBillingModal) return;

    // UI restriction: Practice Settings is not supported on phone-sized screens.
    // This guard covers menu clicks, Ctrl/Cmd+, shortcuts, direct function calls,
    // and any stale element that tries to open the modal below 768px.
    if (isPhoneViewport()) {
      var phoneTitle = t('settings.messages.phone_unavailable_title') || 'Practice settings';
      var phoneMessage = t('settings.messages.phone_unavailable') || 'Practice settings are available on a desktop or tablet. You can continue using cases, notifications, attachments, and Insights on this device.';
      if (typeof Toast !== 'undefined' && Toast.info) {
        Toast.info(phoneTitle, phoneMessage, { duration: 6000 });
      } else {
        alert(phoneMessage);
      }
      return;
    }

    // SECURITY: Settings is an admin-only surface. This guard covers every
    // call site (menu click, Ctrl+ shortcut, or any future direct call) so
    // non-admins can never open it client-side. window.isPracticeAdmin is
    // set from the server's isPracticeAdmin() check (api/get-settings.php) -
    // every underlying settings API independently re-verifies this too.
    if (!window.isPracticeAdmin) {
      showToast(t('settings.messages.admin_only'), 'error');
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

    // Load integration connection state when the Integrations panel exists
    // (no-op when SHOW_PMS_INTEGRATIONS is off and nothing was rendered).
    if (window.initIntegrationsPanel) {
      initIntegrationsPanel();
    }

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
            data.workflowStageLabels || {},
            data.workflowColumns || null,
            data.currentPracticeId
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
        var isActive = item.getAttribute('data-nav-target') === target;
        item.classList.toggle('active', isActive);
        item.setAttribute('aria-current', isActive ? 'true' : 'false');
        // On phones the nav is a horizontally scrolling row; keep the
        // active tab in view so users can see where they are.
        if (isActive && typeof item.scrollIntoView === 'function') {
          try { item.scrollIntoView({ block: 'nearest', inline: 'center' }); } catch (e) { /* older browsers */ }
        }
      });
      panels.forEach(function(panel) {
        panel.classList.toggle('settings-panel-active', panel.getAttribute('data-twisty-id') === target);
      });
      // Show the selected section at the top of the right-side content area
      var panelsContainer = document.querySelector('#settingsForm .settings-panels');
      if (panelsContainer) {
        panelsContainer.scrollTop = 0;
      }
      if (window.dtTooltipLayer) window.dtTooltipLayer.hide();
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

  // Apply the practice-level Case Review Tracking flag to the UI.
  // When OFF, all review-specific UI is hidden via the case-review-tracking-off
  // body class and any active Review Status filter is cleared so cases are not
  // left invisibly filtered. When ON, existing review state is shown again.
  function applyCaseReviewTrackingEnabled(enabled) {
    window.caseReviewTrackingEnabled = !!enabled;
    var off = !window.caseReviewTrackingEnabled;
    document.body.classList.toggle('case-review-tracking-off', off);

    // If the feature is being turned off while a Review Status filter is
    // active, clear it and refresh the board so cases are not hidden.
    var reviewFilter = document.getElementById('filterReviewStatus');
    if (off && reviewFilter && reviewFilter.value) {
      reviewFilter.value = '';
      if (typeof window.applyFilters === 'function') {
        window.applyFilters();
      }
    }

    // Re-evaluate the modal review panel if a case is currently loaded.
    var reviewContainer = document.getElementById('reviewStatusContainer');
    if (reviewContainer) {
      if (off) {
        reviewContainer.style.display = 'none';
      } else if (typeof currentEditCaseData !== 'undefined' && currentEditCaseData && currentEditCaseData.id) {
        renderReviewStatus(currentEditCaseData);
      }
    }

    // The List View review column appears/disappears with this flag.
    if (window.caseListView && typeof window.caseListView.scheduleRefresh === 'function') {
      window.caseListView.scheduleRefresh();
    }
  }
  window.applyCaseReviewTrackingEnabled = applyCaseReviewTrackingEnabled;

  // Apply loaded settings to form fields
  function applyUserSettings(preferences, loadedGmailUsers, loadedGmailLogins, loadedAdminUsers, practiceName, logoPath, loadedAssignmentLabels, isPracticeAdmin, practiceCreatorEmail, displayName, legalName, loadedLimitedVisibilityUsers, loadedCanViewAnalyticsUsers, loadedCanEditCasesUsers, practiceCreatorHasGoogleAccount, isGoogleDriveConnected, loadedAssignmentLabelsDetailed, loadedPracticeUsers, loadedIsLabUsers, showLabInsights, loadedWorkflowStageLabels, loadedWorkflowColumns, serverCurrentPracticeId) {
    window.isPracticeAdmin = !!isPracticeAdmin;
    window.practiceCreatorEmail = (practiceCreatorEmail || '').toLowerCase() || null;
    window.practiceCreatorHasGoogleAccount = practiceCreatorHasGoogleAccount !== false;
    window.isGoogleDriveConnected = isGoogleDriveConnected === true;
    window.showLabInsights = showLabInsights === true;
    window.practiceUsers = loadedPracticeUsers || [];
    window.caseReviewTrackingEnabled = toBoolean(preferences.case_review_tracking_enabled, false);

    if (typeof applyCaseReviewTrackingEnabled === 'function') {
      applyCaseReviewTrackingEnabled(window.caseReviewTrackingEnabled);
    }

    // Fully-resolved workflow-stage display labels for the current
    // practice (see get-settings.php's `workflowStageLabels` field and
    // getStageLabel() above). Always an object; getStageLabel() falls back
    // safely to the internal status if a key is ever missing.
    window.workflowStageLabels = (loadedWorkflowStageLabels && typeof loadedWorkflowStageLabels === 'object')
      ? loadedWorkflowStageLabels
      : {};

    // Persisted workflow columns for the draft editor.
    // Reject settings responses that belong to a different practice than the
    // page was rendered for (e.g. after a practice switch in another tab).
    var pagePracticeId = window.currentPracticeId;
    var serverPracticeId = serverCurrentPracticeId || (loadedWorkflowColumns && loadedWorkflowColumns.practiceId) || null;
    if (pagePracticeId && serverPracticeId && parseInt(serverPracticeId, 10) !== parseInt(pagePracticeId, 10)) {
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[workflow] Settings response practice mismatch; clearing workflow snapshot. page=' + pagePracticeId + ' server=' + serverPracticeId);
      }
      window.workflowColumnsSnapshot = { fingerprint: '', active: [], archived: [] };
    } else {
      window.workflowColumnsSnapshot = (loadedWorkflowColumns && typeof loadedWorkflowColumns === 'object')
        ? loadedWorkflowColumns
        : { fingerprint: '', active: [], archived: [] };
    }

    // Apply to the Settings inputs, Kanban headers, and status dropdown.
    // The board is already server-rendered with these same resolved
    // labels on first paint (see main.php), so on initial load this is a
    // no-op re-confirmation; it's what actually updates the UI whenever
    // Settings (re)loads later or a save just completed.
    if (typeof renderWorkflowStageLabels === 'function') {
      renderWorkflowStageLabels();
    }

    // Set tour completion status for Shepherd.js
    window.tourCompleted = toBoolean(preferences.tour_completed, false);
    window.tourSettingsLoaded = true;
    window.dispatchEvent(new Event('toursettingsloaded'));

    // Initialize workflow column handlers after the admin flag is known and
    // the Settings DOM has been rendered. The earlier startup call returns
    // before this flag is set, so it is re-driven here.
    if (typeof initWorkflowColumnsManager === 'function') {
      initWorkflowColumnsManager();
    }

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

    // Apply checkbox values - use toBoolean to handle DB strings like "0" / "1".
    const allowCardDelete = toBoolean(preferences.allow_card_delete, true);
    const highlightPastDue = toBoolean(preferences.highlight_past_due, true);
    const highlightComingDue = toBoolean(preferences.highlight_coming_due, false);
    const highlightAppointmentRisk = toBoolean(preferences.highlight_appointment_risk, true);

    // Sync checkboxes with database value
    const allowCardDeleteCheckbox = document.getElementById('allowCardDelete');
    if (allowCardDeleteCheckbox) {
      allowCardDeleteCheckbox.checked = allowCardDelete;
    }
    const highlightPastDueCheckbox = document.getElementById('highlightPastDue');
    if (highlightPastDueCheckbox) {
      highlightPastDueCheckbox.checked = highlightPastDue;
    }

    // Apply allow card delete preference to show/hide archive buttons
    var mainContainer = document.querySelector('.main-container');
    var cardContainer = document.querySelector('.kanban-board');
    var dashboard = document.querySelector('.dashboard');

    if (mainContainer) {
      mainContainer.classList.toggle('allow-card-delete', allowCardDelete);
    }

    if (cardContainer) {
      cardContainer.classList.toggle('allow-card-delete', allowCardDelete);
    }

    if (dashboard) {
      dashboard.classList.toggle('allow-card-delete', allowCardDelete);
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

    // Apply Case Review Tracking checkbox (practice-level)
    const caseReviewTrackingEnabledCheckbox = document.getElementById('caseReviewTrackingEnabled');
    if (caseReviewTrackingEnabledCheckbox) {
      caseReviewTrackingEnabledCheckbox.checked = window.caseReviewTrackingEnabled;
    }

    // Apply coming due values
    const comingDueDaysInput = document.getElementById('comingDueDays');
    if (comingDueDaysInput) {
      comingDueDaysInput.value = preferences.coming_due_days || 5;
    }

    // Save coming due preferences in localStorage
    localStorage.setItem('highlight_coming_due', highlightComingDue ? 'true' : 'false');
    localStorage.setItem('coming_due_days', (preferences.coming_due_days || 5).toString());

    // Apply appointment risk values
    const appointmentRiskDaysInput = document.getElementById('appointmentRiskDays');
    if (appointmentRiskDaysInput) {
      appointmentRiskDaysInput.value = preferences.appointment_risk_days || 3;
    }

    // Save appointment risk preferences in localStorage
    localStorage.setItem('highlight_appointment_risk', highlightAppointmentRisk ? 'true' : 'false');
    localStorage.setItem('appointment_risk_days', (preferences.appointment_risk_days || 3).toString());

    // Sync checkboxes for coming due and appointment risk
    const highlightComingDueCheckbox = document.getElementById('highlightComingDue');
    if (highlightComingDueCheckbox) {
      highlightComingDueCheckbox.checked = highlightComingDue;
    }
    const highlightAppointmentRiskCheckbox = document.getElementById('highlightAppointmentRisk');
    if (highlightAppointmentRiskCheckbox) {
      highlightAppointmentRiskCheckbox.checked = highlightAppointmentRisk;
    }

    // Update conditional visibility
    const pastDueSettings = document.getElementById('pastDueSettings');
    if (pastDueSettings) {
      pastDueSettings.classList.toggle('hidden', !highlightPastDue);
    }
    const comingDueSettings = document.getElementById('comingDueSettings');
    if (comingDueSettings) {
      comingDueSettings.classList.toggle('hidden', !highlightComingDue);
    }
    const appointmentRiskSettings = document.getElementById('appointmentRiskSettings');
    if (appointmentRiskSettings) {
      appointmentRiskSettings.classList.toggle('hidden', !highlightAppointmentRisk);
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
      caseReviewTrackingEnabled: document.getElementById('caseReviewTrackingEnabled')?.checked || false,
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
    if ((document.getElementById('caseReviewTrackingEnabled')?.checked || false) !== orig.caseReviewTrackingEnabled) return true;
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

    // Check workflow column draft.
    if (typeof window.workflowColumnsHasUnsavedChanges === 'function' && window.workflowColumnsHasUnsavedChanges()) {
      return true;
    }

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
    if (window.dtTooltipLayer) window.dtTooltipLayer.hide();
    // Rename Assignment Label and the reusable Confirm modal are opened as
    // child modals from within Settings. While either is open, Settings must
    // not close through ANY path (X, Cancel, outside-click, Escape, or a
    // force-close) - otherwise Settings' state (backdrop, body scroll lock)
    // would be torn down while the child modal is still on top.
    var renameLabelModal = document.getElementById('renameAssignmentLabelModal');
    if (renameLabelModal && renameLabelModal.style.display === 'block') {
      return;
    }
    var confirmModal = document.getElementById('confirmModal');
    if (confirmModal && confirmModal.style.display === 'block') {
      return;
    }
    // The Open Dental setup modal is a child of Settings - Settings must not
    // close underneath it (its own Escape/X/backdrop dismiss it alone).
    var integrationModal = document.getElementById('integrationConfigModal');
    if (integrationModal && integrationModal.style.display === 'block') {
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
          // Tear down any stale child-modal state so reopening Settings
          // never resurrects the integration setup modal.
          if (typeof window.closeIntegrationConfigModal === 'function') {
            window.closeIntegrationConfigModal();
          }
          resetLogoUploadState();
          loadSettings();
        });
        return; // Don't close the modal yet - wait for user decision
      }
      // No unsaved changes or force closing, close immediately
      settingsBillingModal.style.display = 'none';
      document.body.style.overflow = '';
      document.documentElement.style.overflow = '';
      // Tear down any stale child-modal state so reopening Settings never
      // resurrects the integration setup modal.
      if (typeof window.closeIntegrationConfigModal === 'function') {
        window.closeIntegrationConfigModal();
      }

      // Reset logo upload state when closing without saving
      if (!forceClose) {
        resetLogoUploadState();
      }
    }
  }

  // If the viewport shrinks to a phone width while Settings is open, close it
  // cleanly so the backdrop and body scroll lock are not left behind.
  var phoneViewportMediaQuery = window.matchMedia('(max-width: 767px)');
  if (phoneViewportMediaQuery && typeof phoneViewportMediaQuery.addEventListener === 'function') {
    phoneViewportMediaQuery.addEventListener('change', function(e) {
      if (e.matches && settingsBillingModal && settingsBillingModal.style.display === 'block') {
        closeSettingsBillingModal(true);
      }
    });
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

  if (settingsCancelBtn) {
    settingsCancelBtn.addEventListener('click', function() {
      // Close Settings triggers the centralized unsaved-changes modal if needed.
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
  if (typeof window.isPracticeAdmin === 'undefined') {
    window.isPracticeAdmin = false;
  }
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
    trigger.setAttribute('aria-describedby', tooltipId);
    trigger.setAttribute('aria-label', t('settings.common.more_info'));
    trigger.setAttribute('aria-expanded', 'false');
    trigger.textContent = t('settings.common.info_trigger') || 'i';

    // The inline bubble is the persistent, screen-reader-only description
    // target for aria-describedby. The visible tooltip is rendered in a
    // body-level layer (see dtTooltipLayer below) so it can never be
    // clipped by a scrolling/overflow:hidden ancestor such as the Settings
    // modal panels or the Practice Users grid rows.
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

  // Body-level tooltip layer shared by every .dt-tooltip-trigger. Positioned
  // from the trigger's viewport rect (position: fixed), flips above/below and
  // clamps to the viewport, follows scroll/resize, and closes on Escape,
  // focus loss, outside interaction, or when the trigger leaves the DOM.
  var dtTooltipLayer = (function() {
    var layer = null;
    var arrow = null;
    var activeTrigger = null;
    var openedByPointer = false;
    var shownAt = 0;
    var EDGE = 8;
    var GAP = 9;

    function ensureLayer() {
      if (layer) return layer;
      layer = document.createElement('div');
      layer.className = 'dt-tooltip-layer';
      layer.setAttribute('aria-hidden', 'true');
      arrow = document.createElement('span');
      arrow.className = 'dt-tooltip-layer-arrow';
      layer.appendChild(document.createElement('span'));
      layer.appendChild(arrow);
      document.body.appendChild(layer);
      return layer;
    }

    function textFor(trigger) {
      var id = trigger.getAttribute('aria-describedby');
      var src = id ? document.getElementById(id) : null;
      return src ? src.textContent : '';
    }

    function position() {
      if (!activeTrigger || !layer) return;
      if (!activeTrigger.isConnected) { hide(); return; }
      var rect = activeTrigger.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) { hide(); return; }
      var vw = window.innerWidth;
      var vh = window.innerHeight;
      // Fully hidden by a scroll container -> close rather than float.
      if (rect.bottom < 0 || rect.top > vh || rect.right < 0 || rect.left > vw) { hide(); return; }

      layer.style.maxWidth = Math.min(240, vw - EDGE * 2) + 'px';
      var lw = layer.offsetWidth;
      var lh = layer.offsetHeight;
      var cx = rect.left + rect.width / 2;
      var placement = 'above';
      var top;
      if (rect.top - GAP - lh >= EDGE) {
        top = rect.top - GAP - lh;
      } else if (rect.bottom + GAP + lh <= vh - EDGE) {
        placement = 'below';
        top = rect.bottom + GAP;
      } else if (rect.right + GAP + lw <= vw - EDGE) {
        placement = 'right';
      } else {
        placement = 'left';
      }

      var left;
      if (placement === 'above' || placement === 'below') {
        left = Math.min(Math.max(EDGE, cx - lw / 2), vw - EDGE - lw);
        arrow.style.left = Math.min(Math.max(10, cx - left), lw - 10) + 'px';
        arrow.style.top = '';
      } else {
        top = Math.min(Math.max(EDGE, rect.top + rect.height / 2 - lh / 2), vh - EDGE - lh);
        left = placement === 'right' ? rect.right + GAP : rect.left - GAP - lw;
        arrow.style.top = Math.min(Math.max(10, rect.top + rect.height / 2 - top), lh - 10) + 'px';
        arrow.style.left = '';
      }
      layer.style.top = Math.round(top) + 'px';
      layer.style.left = Math.round(left) + 'px';
      layer.setAttribute('data-placement', placement);
    }

    function show(trigger, viaPointer) {
      if (!trigger) return;
      if (activeTrigger && activeTrigger !== trigger) hide();
      ensureLayer();
      if (activeTrigger !== trigger) shownAt = Date.now();
      activeTrigger = trigger;
      openedByPointer = !!viaPointer;
      layer.firstChild.textContent = textFor(trigger);
      layer.classList.add('is-visible');
      trigger.setAttribute('aria-expanded', 'true');
      position();
    }

    function hide() {
      if (activeTrigger) activeTrigger.setAttribute('aria-expanded', 'false');
      activeTrigger = null;
      if (layer) layer.classList.remove('is-visible');
    }

    function triggerFrom(target) {
      return target && target.closest ? target.closest('.dt-tooltip-trigger') : null;
    }

    document.addEventListener('mouseover', function(e) {
      var trig = triggerFrom(e.target);
      if (trig && trig !== activeTrigger) show(trig, true);
    });
    document.addEventListener('mouseout', function(e) {
      var trig = triggerFrom(e.target);
      if (trig && trig === activeTrigger && openedByPointer && document.activeElement !== trig) hide();
    });
    document.addEventListener('focusin', function(e) {
      var trig = triggerFrom(e.target);
      if (trig) { show(trig, false); } else if (activeTrigger) { hide(); }
    });
    document.addEventListener('focusout', function(e) {
      var trig = triggerFrom(e.target);
      if (trig && trig === activeTrigger) hide();
    });
    // Tap / click toggles (touch devices have no hover). Triggers are
    // type="button" so this never submits the surrounding settings form.
    // A tap emits mouseover/focusin (which already opened the tooltip)
    // before click, so a click within the same interaction keeps it open
    // instead of immediately toggling it closed.
    document.addEventListener('click', function(e) {
      var trig = triggerFrom(e.target);
      if (trig) {
        e.preventDefault();
        var sameInteraction = activeTrigger === trig && (Date.now() - shownAt) < 400;
        if (activeTrigger === trig && !sameInteraction) { hide(); } else { show(trig, false); }
      }
    });
    document.addEventListener('pointerdown', function(e) {
      if (activeTrigger && !triggerFrom(e.target)) hide();
    }, true);
    // Capture phase + stopImmediatePropagation, matching the confirm/rename
    // child-modal pattern: the first Escape dismisses only the tooltip and
    // does not also close the Settings modal underneath.
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && activeTrigger) {
        hide();
        e.stopImmediatePropagation();
      }
    }, true);
    document.addEventListener('scroll', function() { if (activeTrigger) position(); }, true);
    window.addEventListener('resize', function() { if (activeTrigger) position(); });

    return { hide: hide, position: position };
  })();
  window.dtTooltipLayer = dtTooltipLayer;

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
      // Full address stays in the DOM text (screen readers get it even when
      // the visual is ellipsized) and is also exposed on hover via title.
      emailTextSpan.title = email;
      userEmail.appendChild(emailTextSpan);

      if (isCurrent || isCreator) {
        var badges = document.createElement('span');
        badges.className = 'user-badges';
        if (isCurrent) {
          var youBadge = document.createElement('span');
          youBadge.className = 'admin-badge';
          youBadge.textContent = t('settings.users.practice_users.badge_you');
          badges.appendChild(youBadge);
        }
        if (isCreator) {
          var creatorBadge = document.createElement('span');
          creatorBadge.className = 'admin-badge';
          creatorBadge.textContent = t('settings.users.practice_users.badge_creator');
          badges.appendChild(creatorBadge);
        }
        userEmail.appendChild(badges);
      }

      infoWrapper.appendChild(userEmail);
      row.appendChild(infoWrapper);

      // Per-cell label (translated) shown only in the stacked phone-card
      // layout, where the shared header row is hidden. Carries its own
      // info tooltip so help stays attached to the correct permission.
      function cellLabel(labelKey, tooltipKey) {
        var lbl = document.createElement('span');
        lbl.className = 'practice-user-cell-label';
        var txt = document.createElement('span');
        txt.textContent = t(labelKey);
        lbl.appendChild(txt);
        if (tooltipKey) lbl.appendChild(createInfoTooltip(t(tooltipKey)));
        return lbl;
      }

      // Admin checkbox cell
      var adminCell = document.createElement('div');
      adminCell.className = 'practice-user-admin-cell';
      adminCell.appendChild(cellLabel('settings.users.practice_users.admin', 'settings.users.practice_users.admin_tooltip'));
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
      analyticsCell.appendChild(cellLabel('settings.users.practice_users.insights', 'settings.users.practice_users.insights_tooltip'));
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
      limitedCell.appendChild(cellLabel('settings.users.practice_users.assigned_only', 'settings.users.practice_users.assigned_only_tooltip'));
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
        labCell.appendChild(cellLabel('settings.users.practice_users.lab', 'settings.users.practice_users.lab_tooltip'));
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
        removeCell.appendChild(cellLabel('settings.users.practice_users.remove', null));
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-gmail-user';
        removeBtn.innerHTML = '&times;';
        removeBtn.setAttribute('aria-label', t('settings.users.practice_users.remove') + ' ' + email);
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

      // Case Review Tracking (practice-level, admin-only)
      var caseReviewTrackingEnabledInput = document.getElementById('caseReviewTrackingEnabled');
      var caseReviewTrackingEnabled = caseReviewTrackingEnabledInput ? caseReviewTrackingEnabledInput.checked : false;

      // Practice settings - use displayName (editable) instead of practiceName
      var displayNameInput = document.getElementById('displayName');
      var displayName = displayNameInput ? displayNameInput.value.trim() : '';

      // Legacy fallback to practiceName if displayName doesn't exist
      var practiceNameInput = document.getElementById('practiceName');
      var practiceName = practiceNameInput ? practiceNameInput.value.trim() : '';

      // Practice logo settings
      var logoPathToSave = window.pendingLogoPath || window.currentLogoPath || '';

      // Workflow Stage Names - keyed by the practice's active internal
      // column ids (including custom columns). Server-side
      // normalizeWorkflowStageLabelsForSave() is authoritative for
      // trimming/validating/dropping blanks - this is just the raw current
      // input values.
      var workflowStageLabels = {};
      document.querySelectorAll('.workflow-column-label-input').forEach(function(input) {
        if (input.dataset.internalId) {
          workflowStageLabels[input.dataset.internalId] = input.value;
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
        caseReviewTrackingEnabled: caseReviewTrackingEnabled,
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
        workflowStageLabels: workflowStageLabels, // Practice-specific stage display-label overrides
        workflowColumns: (typeof window.getWorkflowColumnsPayload === 'function' ? window.getWorkflowColumnsPayload() : null),
        expectedPracticeId: window.currentPracticeId
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

  // Initialize the workflow column manager (add/reorder/archive/restore).
  // Guard against transient script-order issues in environments where
  // workflow-draft-ui.js has not yet executed at DOMContentLoaded.
  if (typeof initWorkflowColumnsManager === 'function') {
    initWorkflowColumnsManager();
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

    // Apply Case Review Tracking immediately
    if (typeof formData.caseReviewTrackingEnabled !== 'undefined') {
      applyCaseReviewTrackingEnabled(formData.caseReviewTrackingEnabled);
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
    var workflowColumnsSubmitted = !!(formData && formData.workflowColumns) &&
      (typeof window.workflowColumnsHasUnsavedChanges === 'function' ? window.workflowColumnsHasUnsavedChanges() : true);
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
    .then(function (response) {
      // Always read as text first so HTML errors do not break JSON parsing.
      return response.text().then(function (text) {
        var contentType = (response.headers.get('content-type') || '').toLowerCase();
        if (!contentType || contentType.indexOf('application/json') === -1) {
          return { ok: response.ok, status: response.status, contentType: contentType, raw: text, json: null, notJson: true };
        }
        var parsed = null;
        var jsonError = null;
        try {
          parsed = text ? JSON.parse(text) : null;
        } catch (e) {
          jsonError = e.message;
        }
        return { ok: response.ok, status: response.status, contentType: contentType, raw: text, json: parsed, jsonError: jsonError };
      });
    })
    .then(function (result) {
      if (result.notJson) {
        console.error('[saveSettings] non-JSON response', result.status, result.contentType, result.raw.substring(0, 500));
        throw new Error('The server returned an invalid response. Check the local PHP error log.');
      }
      if (result.jsonError) {
        console.error('[saveSettings] JSON parse error', result.jsonError, result.raw.substring(0, 500));
        throw new Error('The server returned an invalid response. Check the local PHP error log.');
      }
      if (!result.json) {
        console.error('[saveSettings] empty response', result.status, result.raw.substring(0, 500));
        throw new Error('The server returned an invalid response. Check the local PHP error log.');
      }
      return result.json;
    })
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

        // Refresh the workflow draft from the persisted snapshot.
        if (data.workflowColumns) {
          window.workflowColumnsSnapshot = data.workflowColumns;
          if (typeof initWorkflowColumnsManager === 'function') {
            initWorkflowColumnsManager();
          }
        }

        // Close the settings modal only when everything succeeds.
        closeSettingsBillingModal(true);

        // Show success toast
        if (typeof Toast !== 'undefined') {
          Toast.success(t('settings.messages.save_success_title'), t('settings.messages.save_success_message'));
        }

        // If the workflow structure changed, perform a controlled full-page
        // reload so the server-rendered board, status selectors, and filters
        // pick up the new configuration.
        if (workflowColumnsSubmitted) {
          setTimeout(function () {
            window.location.reload();
          }, 600);
        }
      } else {
        // Show the server's specific validation message when available.
        // If the server points to the workflow columns, or if the request
        // contained workflow data, surface the error in the Workflow Columns
        // section so the admin can see it in context.
        if (data.field === 'workflowColumns' || workflowColumnsSubmitted) {
          var workflowError = document.getElementById('workflowColumnsError');
          if (workflowError) {
            workflowError.textContent = data.message || t('settings.workflow_columns.save_failed');
            workflowError.style.display = 'block';
            workflowError.hidden = false;
            if (workflowError.scrollIntoView) {
              workflowError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
          } else {
            showToast(data.message || t('settings.workflow_columns.save_failed'), 'error');
          }
          // Surface the error on the specific column name input.
          var inputs = document.querySelectorAll('.workflow-column-label-input');
          var focused = false;
          if (data.invalidId) {
            for (var k = 0; k < inputs.length; k++) {
              if (inputs[k].dataset.internalId === data.invalidId) {
                inputs[k].classList.add('workflow-column-name-invalid');
                inputs[k].focus();
                focused = true;
                break;
              }
            }
          }
          if (!focused) {
            for (var j = 0; j < inputs.length; j++) {
              if ((inputs[j].value || '').trim() === '') {
                inputs[j].classList.add('workflow-column-name-invalid');
                inputs[j].focus();
                break;
              }
            }
          }
        } else {
          showToast(data.message || t('settings.messages.save_error'), 'error');
        }

        if (data.reload_required) {
          loadSettings();
        }
      }
    })
    .catch(function (error) {
      // Ensure the button is always restored and the modal stays open.
      var workflowError = document.getElementById('workflowColumnsError');
      if (workflowError) {
        workflowError.textContent = error && error.message ? error.message : t('settings.messages.save_error');
        workflowError.style.display = 'block';
        workflowError.hidden = false;
        if (workflowError.scrollIntoView) {
          workflowError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      } else {
        showToast(error && error.message ? error.message : t('settings.messages.save_error'), 'error');
      }
    })
    .finally(function () {
      saveSettingsBtn.textContent = originalText;
      saveSettingsBtn.disabled = false;
    });
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
  var currentEditCaseData = null;
  var pendingViewCaseId = null;
  var pendingCaseOpenOptions = null;
  var pendingAttachmentScroll = false;
  var caseOpenRequestId = 0;
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
      createCaseForm.style.display = tabName === 'details' ? 'block' : 'none';
    }
    if (caseCommentsPanel) {
      caseCommentsPanel.classList.toggle('case-tab-panel-active', tabName === 'comments');
      caseCommentsPanel.style.display = tabName === 'comments' ? 'block' : 'none';
    }
    if (caseHistoryPanel) {
      caseHistoryPanel.classList.toggle('case-tab-panel-active', tabName === 'history');
      caseHistoryPanel.style.display = tabName === 'history' ? 'block' : 'none';
    }

    // The Comments-tab submit label depends on pending case edits ("Add
    // Comment" vs "Save All Changes") - refresh it whenever tabs switch.
    if (typeof window.updateCaseCommentSubmitState === 'function') {
      window.updateCaseCommentSubmitState();
    }

    if (window.viewCaseTimings) {
      window.viewCaseTimings.tabActivated = performance.now() - window.viewCaseTimings.shellStart;
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

      // Get user name for display - prefer the server-resolved display name
      // (full name or account email), then the raw event email local-part.
      var userName = evt.user_name || (evt.user_email ? evt.user_email.split('@')[0] : 'System');
      userName = userName.charAt(0).toUpperCase() + userName.slice(1);

      // Check if this is a revision/regression event (backward move)
      var isRevision = evt.event_type === 'case_revision' || evt.event_type === 'case_regression';
      if (isRevision) {
        item.classList.add('revision-highlight');
      }

      var description = '';
      switch (evt.event_type) {
        case 'case_created':
          if (evt.meta && evt.meta.source === 'integration:open_dental') {
            description = 'Case automatically created from Open Dental';
          } else {
            description = 'Case created by ' + userName;
          }
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
        case 'remake_initiated':
          var remakeReasonLabel = evt.meta && evt.meta.remake_reason ? t('remakes.reasons.' + evt.meta.remake_reason) : '';
          var remakeAttributionLabel = evt.meta && evt.meta.remake_attribution ? t('remakes.attribution.' + evt.meta.remake_attribution) : '';
          if (remakeReasonLabel && remakeReasonLabel.indexOf('remakes.') === 0) remakeReasonLabel = '';
          if (remakeAttributionLabel && remakeAttributionLabel.indexOf('remakes.') === 0) remakeAttributionLabel = '';
          var remakeDetail = remakeReasonLabel + (remakeAttributionLabel ? ' (' + remakeAttributionLabel + ')' : '');
          description = 'Remake #' + (evt.meta && evt.meta.remake_number || '?') +
            (remakeDetail ? ' recorded: ' + remakeDetail : ' recorded') + ' by ' + userName;
          break;
        case 'remake_completed':
          description = 'Remake #' + (evt.meta && evt.meta.remake_number || '?') + ' marked complete by ' + userName;
          break;
        case 'source_deleted':
          if (evt.meta && evt.meta.source === 'integration:open_dental') {
            description = 'Source lab case was deleted in Open Dental';
          } else {
            description = 'Source record was deleted in the connected system';
          }
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
    // Establish the active case first: the Comments and History tabs derive
    // their enabled state from currentEditCaseId. This must not depend on the
    // History panel existing - when SHOW_REVISION_HISTORY is off there is no
    // #caseRevisionHistory element, and returning early here left
    // currentEditCaseId null, so the Comments tab stayed disabled and never
    // loaded for an existing case.
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

    // Everything below renders the Revision History panel, which only exists
    // when SHOW_REVISION_HISTORY is enabled.
    if (!caseHistoryContainer) return;

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

      // Drop any option injected for a previously-edited legacy case type
      // so it can never become selectable on a new case.
      resetCaseTypeSelect(document.getElementById('caseType'));
    }

    // Hide the saved-case meta row (Created By) and restore the edit-only
    // Status field to its create-mode hidden/disabled state, then collapse
    // the empty shipping section for the fresh form.
    updateCaseModalMeta();
    updateShippingSectionState();

    var newCaseRemakeBtn = document.getElementById('recordRemakeBtn');
    if (newCaseRemakeBtn) newCaseRemakeBtn.hidden = true;
    if (form) delete form.dataset.caseArchived;
    if (typeof window.loadCaseRemakeHistory === 'function') {
      window.loadCaseRemakeHistory(null);
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

    // Hide review status panel for new case
    if (typeof renderReviewStatus === 'function') {
      renderReviewStatus(null);
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

      // Remember the element that opened the modal so focus can be restored on close.
      if (document.activeElement && !caseModalOpener) {
        caseModalOpener = document.activeElement;
      }

      createCaseModal.style.display = 'block';
      if (window.viewCaseTimings) {
        window.viewCaseTimings.modalVisible = performance.now() - window.viewCaseTimings.shellStart;
      }
      document.body.style.overflow = 'hidden'; // Prevent scrolling behind modal
      resetCaseViewState();

      // New cases have no summary; editing cases render their summary
      // after populateCreateCaseForm()/editCaseHandler() sets the data.
      if (window.MobileCaseModal && typeof window.MobileCaseModal.renderSummary === 'function') {
        window.MobileCaseModal.renderSummary(null);
      }

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

      // Shipping section toggle (expanded state is set per-case by
      // updateShippingSectionState in the populate/reset paths).
      var shippingToggle = document.getElementById('shippingToggle');
      if (shippingToggle && !shippingToggle.dataset.bound) {
        shippingToggle.addEventListener('click', function() {
          var fields = document.getElementById('shippingFields');
          var expanded = shippingToggle.getAttribute('aria-expanded') === 'true';
          shippingToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          if (fields) fields.hidden = expanded;
        });
        shippingToggle.dataset.bound = '1';
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

      // Initial focus: Create Case starts on Patient First Name; Edit Case
      // focuses the modal container so no editable field jumps the caret.
      setTimeout(function() {
        if (isUpdate) {
          createCaseModal.focus();
        } else {
          var firstField = document.getElementById('patientFirstName');
          if (firstField) {
            firstField.focus();
          }
        }
      }, 150); // Small delay to ensure modal is fully displayed
    }
  }

  function closeCreateCase() {
    ++caseOpenRequestId;
    openingCaseById = false;
    pendingViewCaseId = null;
    pendingCaseOpenOptions = null;
    pendingAttachmentScroll = false;
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

      // Clear the active case so the Comments/History tabs are disabled and
      // hold no stale comments or counts until the next case is opened.
      loadCaseRevisionHistory(null);

      // Clear mobile summary and section headings so the next case opens fresh.
      if (window.MobileCaseModal && typeof window.MobileCaseModal.clearMobileState === 'function') {
        window.MobileCaseModal.clearMobileState();
      }

      // Restore the previously active mobile Kanban column/position.
      if (window.MobileKanban && typeof window.MobileKanban.restoreActiveColumn === 'function' && window.matchMedia('(max-width: 480px)').matches) {
        setTimeout(function() {
          window.MobileKanban.restoreActiveColumn(false);
        }, 50);
      }

      // Restore focus to the control or card that opened the modal when practical.
      if (caseModalOpener && typeof caseModalOpener.focus === 'function') {
        try {
          if (caseModalOpener.tabIndex >= 0 || /^(BUTTON|A|INPUT|SELECT|TEXTAREA)$/.test(caseModalOpener.tagName)) {
            caseModalOpener.focus();
          }
        } catch (e) {
          // Ignore focus errors for non-focusable elements.
        }
      }
      caseModalOpener = null;

      // Reset any view-only modifications
      var form = document.getElementById('createCaseForm');
      if (form) {
        var inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
          input.removeAttribute('readonly');
          input.style.backgroundColor = '';
          input.style.cursor = '';
          if (input._caseViewDisabled !== undefined) {
            input.disabled = input._caseViewDisabled;
            delete input._caseViewDisabled;
            input.style.opacity = '';
            input.style.color = '';
          }
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
    if (caseType) {
      setCaseTypeValue(caseType, caseData.caseType || caseData.case_type || '');
    }
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
      submitBtn.textContent = t('cases.save_all_changes');
    }

    // Load and display existing files
    if (caseData.files && Array.isArray(caseData.files)) {
      displayExistingFiles(caseData.files);
    }

    // Render the compact mobile case summary (phone viewports only).
    if (window.MobileCaseModal && typeof window.MobileCaseModal.renderSummary === 'function') {
      window.MobileCaseModal.renderSummary(caseData);
    }

    // Record Remake is an explicit action for active saved cases; hidden
    // for new cases and archived read-only views (server rejects archived).
    var recordRemakeBtn = document.getElementById('recordRemakeBtn');
    if (recordRemakeBtn) {
      recordRemakeBtn.hidden = !(caseData && (caseData.id || caseData.case_id) && !caseData.archived);
    }
    form.dataset.caseArchived = caseData && caseData.archived ? '1' : '0';
    if (typeof window.loadCaseRemakeHistory === 'function') {
      window.loadCaseRemakeHistory(caseData && (caseData.id || caseData.case_id), { archived: !!(caseData && caseData.archived) });
    }

    // Render review status controls (hidden for new/unsaved cases)
    renderReviewStatus(caseData);

    // Meta row (Created By / review / Record Remake) and the collapsible
    // shipping section track the loaded case's state.
    updateCaseModalMeta();
    updateShippingSectionState();
  }

  /**
   * Render the review status panel in the case modal.
   * Pass null or a case without an id to hide it.
   */
  function renderReviewStatus(caseData) {
    var container = document.getElementById('reviewStatusContainer');
    var valueEl = document.getElementById('reviewStatusValue');
    var timestampEl = document.getElementById('reviewStatusTimestamp');
    var actionBtn = document.getElementById('reviewStatusAction');
    if (!container || !valueEl || !timestampEl || !actionBtn) return;

    if (!window.caseReviewTrackingEnabled) {
      container.style.display = 'none';
      actionBtn.onclick = null;
      return;
    }

    if (!caseData || !caseData.id) {
      container.style.display = 'none';
      actionBtn.onclick = null;
      return;
    }

    var isReviewed = caseData.reviewStatus === 'reviewed';
    var reviewedAt = caseData.reviewedAt;
    var reviewedByName = caseData.reviewedByName || 'Unknown';

    container.classList.remove('reviewed', 'needs-review');
    container.classList.add(isReviewed ? 'reviewed' : 'needs-review');

    valueEl.textContent = isReviewed ? t('cases.reviewed') : t('cases.needs_review');

    if (isReviewed && reviewedAt) {
      timestampEl.textContent = t('cases.reviewed_by_timestamp', {
        name: reviewedByName,
        timestamp: formatDate(reviewedAt, true)
      });
      timestampEl.style.display = 'block';
    } else {
      timestampEl.textContent = '';
      timestampEl.style.display = 'none';
    }

    actionBtn.textContent = isReviewed ? t('cases.mark_needs_review') : t('cases.mark_reviewed');
    actionBtn.dataset.reviewed = isReviewed ? 'true' : 'false';
    actionBtn.disabled = !!caseData.archived;

    actionBtn.onclick = function() {
      if (actionBtn.disabled) return;
      window.updateCaseReviewStatus(caseData.id, !isReviewed);
    };

    container.style.display = '';
  }

  /**
   * Apply review-state changes to an existing Kanban card without rebuilding it.
   * Merges only the review fields into card.dataset.caseJson and updates the
   * badge DOM in place. Used by both the card badge and the modal review action.
   */
  window.applyReviewStateToCard = function(caseId, reviewData) {
    if (!reviewData) return false;

    var card = (typeof window.findCardByCaseId === 'function')
      ? window.findCardByCaseId(caseId)
      : document.querySelector('.kanban-card[data-case-id="' + caseId + '"], .kanban-card[data-case_id="' + caseId + '"], .kanban-card[data-id="' + caseId + '"]');
    if (!card) return false;

    var cardData = {};
    try {
      cardData = JSON.parse(card.dataset.caseJson || '{}');
    } catch (e) {
      cardData = {};
    }

    cardData.reviewStatus = reviewData.reviewStatus || 'needs_review';
    cardData.reviewedAt = reviewData.reviewedAt || null;
    cardData.reviewedByUserId = reviewData.reviewedByUserId || null;
    cardData.reviewedByName = reviewData.reviewedByName || 'Unknown';
    if (typeof reviewData.archived !== 'undefined') {
      cardData.archived = !!reviewData.archived;
    }
    card.dataset.caseJson = JSON.stringify(cardData);
    card.dataset.caseId = caseId;

    var isReviewed = cardData.reviewStatus === 'reviewed';
    var reviewText = isReviewed ? t('cases.reviewed') : t('cases.needs_review');
    var reviewTooltip = '';
    if (isReviewed && cardData.reviewedAt) {
      reviewTooltip = (cardData.reviewedByName || 'Unknown') + ' · ' + formatDate(cardData.reviewedAt, true);
    } else {
      reviewTooltip = isReviewed ? t('cases.mark_needs_review') : t('cases.mark_reviewed');
    }
    var reviewAriaLabel = isReviewed ? t('cases.mark_needs_review_aria') : t('cases.mark_reviewed_aria');

    var reviewBadge = card.querySelector('.kanban-card-review');
    if (!reviewBadge) {
      var header = card.querySelector('.kanban-card-header');
      if (header) {
        reviewBadge = document.createElement('button');
        reviewBadge.type = 'button';
        reviewBadge.className = 'kanban-card-review';
        reviewBadge.setAttribute('data-case-id', caseId);
        reviewBadge.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (reviewBadge.disabled || reviewBadge.getAttribute('aria-disabled') === 'true') return;
          var liveData = {};
          try { liveData = JSON.parse(card.dataset.caseJson || '{}'); } catch (err) { liveData = {}; }
          if (!liveData.id || liveData.archived) return;
          window.updateCaseReviewStatus(liveData.id, liveData.reviewStatus !== 'reviewed');
        });
        reviewBadge.addEventListener('mousedown', function(e) { e.stopPropagation(); });
        reviewBadge.addEventListener('dragstart', function(e) { e.preventDefault(); e.stopPropagation(); });
        var headerActionsBtn = header.querySelector('.case-actions-toggle');
        if (headerActionsBtn) {
          header.insertBefore(reviewBadge, headerActionsBtn);
        } else {
          header.appendChild(reviewBadge);
        }
      }
    }

    if (reviewBadge) {
      reviewBadge.className = 'kanban-card-review ' + (isReviewed ? 'reviewed' : 'needs-review');
      reviewBadge.textContent = reviewText;
      reviewBadge.setAttribute('aria-label', reviewAriaLabel);
      reviewBadge.setAttribute('data-case-id', caseId);
      reviewBadge.title = reviewTooltip.replace(/"/g, '&quot;');
      reviewBadge.disabled = !!cardData.archived;
      reviewBadge.classList.remove('loading');
    }

    // If a Review Status filter is active and the case no longer matches,
    // re-run the normal filtered board refresh so the card leaves the view.
    var reviewFilter = document.getElementById('filterReviewStatus');
    var activeReviewFilter = reviewFilter ? reviewFilter.value : '';
    if (activeReviewFilter && cardData.reviewStatus !== activeReviewFilter && typeof window.applyFilters === 'function') {
      window.applyFilters();
    }

    // Notify secondary surfaces (e.g. List View) that card data changed.
    if (typeof window.triggerCardsUpdated === 'function') {
      window.triggerCardsUpdated();
    }

    return true;
  };

  /**
   * Call the server to mark a case as reviewed or needs review.
   */
  window.updateCaseReviewStatus = function(caseId, reviewed) {
    if (!window.caseReviewTrackingEnabled) {
      showToast(t('cases.review_tracking_disabled'), 'error');
      return;
    }

    var actionBtn = document.getElementById('reviewStatusAction');
    var cardBadges = document.querySelectorAll('.kanban-card-review[data-case-id="' + caseId + '"]');
    var isArchived = false;

    if (actionBtn) {
      actionBtn.disabled = true;
    }
    cardBadges.forEach(function(badge) {
      badge.disabled = true;
      badge.classList.add('loading');
    });

    fetch('api/update-case-review.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify({ caseId: caseId, reviewed: reviewed })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
      if (data.success && data.reviewData) {
        isArchived = !!data.reviewData.archived;

        // Update the currently loaded case data and re-render the panel
        if (currentEditCaseData && (currentEditCaseData.id === data.reviewData.id || currentEditCaseData.case_id === data.reviewData.id)) {
          currentEditCaseData.reviewStatus = data.reviewData.reviewStatus;
          currentEditCaseData.reviewedAt = data.reviewData.reviewedAt;
          currentEditCaseData.reviewedByUserId = data.reviewData.reviewedByUserId;
          currentEditCaseData.reviewedByName = data.reviewData.reviewedByName;
        }
        renderReviewStatus(data.reviewData);

        // Update the Kanban card if visible
        window.applyReviewStateToCard(caseId, data.reviewData);

        if (data.changed !== false) {
          showToast(data.message, 'success');
        }
      } else {
        showToast(data.message || t('common.error'), 'error');
      }
    })
    .catch(function(err) {
      if (typeof console !== 'undefined' && console.error) {
        console.error('Review status update failed: ' + err.message);
      }
      showToast(t('common.error'), 'error');
    })
    .finally(function() {
      if (actionBtn && !isArchived) {
        actionBtn.disabled = false;
      }
      cardBadges.forEach(function(badge) {
        badge.classList.remove('loading');
        if (!isArchived) {
          badge.disabled = false;
        }
      });
      // Notify secondary surfaces (e.g. List View) even on failure so a
      // loading chip clears instead of sticking.
      if (typeof window.triggerCardsUpdated === 'function') {
        window.triggerCardsUpdated();
      }
    });
  };

  // Show/hide the custom carrier field and clear it when not applicable.
  /**
   * Set the Case Type select to a stored value. Canonical types match an
   * option directly; stored legacy aliases ('Mixed' vs 'Mixed Case Type')
   * resolve through their shared slug; values with no option at all
   * (e.g. legacy 'Implant') get a one-off injected option so the stored
   * type round-trips through Edit instead of silently blanking. Options
   * injected this way are removed by resetCaseTypeSelect() so they never
   * become selectable for new cases.
   */
  function setCaseTypeValue(select, storedValue) {
    if (!select) return;
    resetCaseTypeSelect(select);
    var stored = storedValue || '';
    select.value = stored;
    if (stored && select.value !== stored) {
      if (typeof getCaseTypeSlug === 'function') {
        var storedSlug = getCaseTypeSlug(stored);
        for (var oi = 0; oi < select.options.length; oi++) {
          if (getCaseTypeSlug(select.options[oi].value) === storedSlug) {
            select.value = select.options[oi].value;
            break;
          }
        }
      }
    }
    if (stored && select.value !== stored) {
      var opt = document.createElement('option');
      opt.value = stored;
      opt.dataset.legacyOption = '1';
      opt.textContent = (typeof getCaseTypeDisplayLabel === 'function')
        ? getCaseTypeDisplayLabel(stored)
        : stored;
      select.appendChild(opt);
      select.value = stored;
    }
  }

  /** Drop any option injected by setCaseTypeSelect for a legacy stored value. */
  function resetCaseTypeSelect(select) {
    if (!select) return;
    for (var i = select.options.length - 1; i >= 0; i--) {
      if (select.options[i].dataset && select.options[i].dataset.legacyOption === '1') {
        select.remove(i);
      }
    }
  }

  /** Sync modal chrome that depends on saved-vs-new state: the meta row
      (Created By) and the Status field. The Workflow section renders in
      the same position for Create and Edit. Status stays disabled and
      non-required for Create Case - new cases always enter the practice's
      first active workflow stage, which create-case.php derives
      server-side, so the disabled select is display-only and never
      submitted. */
  function updateCaseModalMeta() {
    var meta = document.getElementById('caseModalMeta');
    var form = document.getElementById('createCaseForm');
    if (!meta || !form) return;
    var isUpdate = !!(form.dataset && form.dataset.caseId);
    meta.style.display = isUpdate ? '' : 'none';

    var statusWrap = document.getElementById('statusFieldWrap');
    var status = document.getElementById('status');
    if (status) {
      status.disabled = !isUpdate;
      if (isUpdate) {
        status.setAttribute('required', '');
      } else {
        status.removeAttribute('required');
      }
      // The required marker only makes sense while Status is editable.
      var requiredMark = statusWrap ? statusWrap.querySelector('.required') : null;
      if (requiredMark) requiredMark.style.display = isUpdate ? '' : 'none';
    }
  }

  /** Expand the shipping section only when it holds values; otherwise
      collapse it to a compact header. Never hides populated data. */
  function updateShippingSectionState() {
    var toggle = document.getElementById('shippingToggle');
    var fields = document.getElementById('shippingFields');
    if (!toggle || !fields) return;
    var carrier = document.getElementById('carrier');
    var tracking = document.getElementById('trackingNumber');
    var custom = document.getElementById('customCarrier');
    var hasValues = !!((carrier && carrier.value) || (tracking && tracking.value) || (custom && custom.value));
    toggle.setAttribute('aria-expanded', hasValues ? 'true' : 'false');
    fields.hidden = !hasValues;
  }

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
   * Render a case's stored attachments into the attachment group containers.
   * Shared by editCaseHandler() (synchronous board/list path, where the case
   * payload already carries attachments) and loadCaseHeavyData() (async
   * notification/deep-link path, where attachments arrive in the follow-up
   * heavy response). The caller is responsible for clearing the containers
   * beforehand (clearFileSelections) so a re-render cannot duplicate rows.
   */
  function renderExistingAttachments(attachments) {
    if (!attachments || !Array.isArray(attachments) || attachments.length === 0) {
      return;
    }

    // Group attachments by type
    var attachmentsByType = {};
    attachments.forEach(function(attachment) {
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
      var mappedType = typeMapping[type] || (type ? String(type).toLowerCase() : 'documents');
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

          // Stash the fields the attachment viewer needs on the row itself
          // so the click handler can rebuild the full ordered attachment
          // list (in displayed order) for Previous/Next navigation.
          if (isGcsFile) {
            fileElement.dataset.storagePath = file.storagePath;
            fileElement.dataset.fileName = file.fileName;
            fileElement.dataset.fileType = file.fileType || file.mimeType || '';
          }

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

          // View link for every stored attachment. Types without a
          // previewable renderer open the viewer's empty state (which still
          // offers Download and Previous/Next navigation).
          var viewLink = null;
          if (isGcsFile) {
            viewLink = document.createElement('a');
            viewLink.href = '#';
            viewLink.className = 'attachment-view-link';
            viewLink.textContent = t('common.view');
            viewLink.title = t('attachments.viewer.open_viewer');
            viewLink.addEventListener('click', function(e) {
              e.preventDefault();
              if (typeof openAttachmentViewer === 'function') {
                try {
                  // Ordered exactly as displayed: every stored attachment
                  // row in document order across the type containers.
                  var rows = Array.prototype.slice.call(
                    document.querySelectorAll('#createCaseForm .existing-file[data-storage-path]')
                  );
                  var list = rows.map(function(row) {
                    return {
                      storagePath: row.dataset.storagePath,
                      fileName: row.dataset.fileName,
                      fileType: row.dataset.fileType
                    };
                  });
                  openAttachmentViewer(file.storagePath, file.fileName, file.fileType || file.mimeType || '', list);
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
    if (caseModalTabs) caseModalTabs.style.display = 'none';

    // Clear stale case data so a failed load does not expose the prior case.
    if (typeof loadCaseRevisionHistory === 'function') {
      loadCaseRevisionHistory(null);
    }
    if (typeof resetCreateCaseFormToNew === 'function') {
      resetCreateCaseFormToNew();
    }
    if (createCaseForm) createCaseForm.style.display = 'none';
  }

  function resetCaseViewState() {
    if (caseViewLoading) caseViewLoading.style.display = 'none';
    if (caseViewError) caseViewError.style.display = 'none';
    if (createCaseForm) createCaseForm.style.display = 'block';
    if (caseModalTabs) caseModalTabs.style.display = 'flex';
  }

  window.openCaseById = function(caseId, options) {
    if (!caseId || !createCaseModal || !createCaseForm || window.isPrintingCase || isSubmitting) {
      return Promise.resolve(false);
    }
    options = options || {};
    if (hasUnsavedWork()) {
      showUnsavedChangesWarning(function() {
        closeCreateCase();
        window.openCaseById(caseId, options);
      });
      return Promise.resolve(false);
    }

    closeCreateCase();
    var requestId = ++caseOpenRequestId;
    openingCaseById = true;
    pendingViewCaseId = String(caseId);
    pendingCaseOpenOptions = {
      tab: ['comments', 'files'].indexOf(options.tab) !== -1 ? options.tab : 'details',
      commentId: options.commentId ? String(options.commentId) : null
    };
    var destination = pendingCaseOpenOptions;
    caseModalOpener = document.activeElement;

    viewCaseTimings = window.viewCaseTimings = {
      source: 'notification',
      shellStart: performance.now(),
      shellVisible: null,
      getCaseRequestStart: null,
      getCaseResponseMs: null,
      getCaseParseMs: null,
      getCaseServerMs: null,
      editCaseHandlerStart: null,
      editCaseHandlerEnd: null,
      fieldsPopulated: null,
      tabActivated: null,
      modalUsable: null
    };

    var caseModal = document.getElementById('createCaseModal');
    if (caseModal) {
      caseModal.style.display = 'block';
      document.body.style.overflow = 'hidden';
      setViewCaseLoading();
      var modalTitle = caseModal.querySelector('.modal-title');
      if (modalTitle) {
        modalTitle.textContent = t('cases.loading') || 'Loading case...';
      }
      if (viewCaseTimings) viewCaseTimings.shellVisible = performance.now() - viewCaseTimings.shellStart;
    }

    viewCaseTimings.getCaseRequestStart = performance.now();
    var fetchStart = performance.now();
    var coreUrl = 'api/get-case.php?id=' + encodeURIComponent(caseId) + '&view=core';
    return fetch(coreUrl, {
      credentials: 'same-origin'
    })
    .then(function(response) {
      if (viewCaseTimings) {
        viewCaseTimings.getCaseResponseMs = performance.now() - viewCaseTimings.getCaseRequestStart;
      }
      if (!response.ok) throw new Error('Case unavailable');
      return response.json();
    })
    .then(function(data) {
      if (viewCaseTimings) {
        viewCaseTimings.getCaseParseMs = performance.now() - viewCaseTimings.getCaseRequestStart - (viewCaseTimings.getCaseResponseMs || 0);
        viewCaseTimings.getCaseServerMs = typeof data.serverTimeMs === 'number' ? data.serverTimeMs : null;
      }
      if (requestId !== caseOpenRequestId || caseModal.style.display === 'none') return false;
      openingCaseById = false;
      if (!data.success || !data.case || String(data.case.id || data.case.case_id) !== String(caseId)) {
        throw new Error('Case unavailable');
      }
      var archived = data.case.archived === true || data.case.archived === 1 || data.case.archived === '1' ||
                     data.case.is_archived === true || data.case.is_archived === 1 || data.case.is_archived === '1';
      if (archived && typeof billingInfo !== 'undefined' && billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
        closeCreateCase();
        showUpgradeModal();
        return false;
      }
      if (viewCaseTimings) viewCaseTimings.editCaseHandlerStart = performance.now() - viewCaseTimings.shellStart;
      if (!archived && data.can_edit === true) {
        if (!checkBillingForCaseCreation()) {
          closeCreateCase();
          return false;
        }
        editCaseHandler(data.case);
      } else {
        openCaseModalForView(data.case, fetchStart);
      }
      if (viewCaseTimings) {
        viewCaseTimings.editCaseHandlerEnd = performance.now() - viewCaseTimings.shellStart;
        viewCaseTimings.fieldsPopulated = performance.now() - viewCaseTimings.shellStart;
      }
      if (destination.tab === 'comments' && caseCommentsTab && caseCommentsPanel) {
        // Switch to comments tab after opening
        setCaseModalActiveTab('comments');
        if (destination.commentId && typeof window.focusCaseComment === 'function') {
          window.focusCaseComment(String(caseId), destination.commentId);
        }
      } else {
        setCaseModalActiveTab('details');
        if (destination.tab === 'files') {
          // Scroll to the Attachments heading as soon as the Details form is
          // visible. If the heading has not been rendered yet, defer to the
          // heavy-data follow-up.
          var attachmentsHeading = createCaseForm ? createCaseForm.querySelector('.attachments-title') : null;
          if (attachmentsHeading) {
            attachmentsHeading.setAttribute('tabindex', '-1');
            attachmentsHeading.focus({ preventScroll: true });
            attachmentsHeading.scrollIntoView({ block: 'start', behavior: 'auto' });
            pendingAttachmentScroll = false;
          } else {
            pendingAttachmentScroll = true;
          }
        }
      }

      // Load heavy attachments/clinical data in the background now that the
      // modal is already usable with the core fields.
      loadCaseHeavyData(caseId, requestId, destination);

      return true;
    })
    .catch(function(error) {
      if (requestId !== caseOpenRequestId || caseModal.style.display === 'none') return false;
      openingCaseById = false;
      setViewCaseError();
      if (typeof showToast === 'function') {
        showToast(t('cases.toast.open_error'), 'error');
      }
      return false;
    });
  };

  function loadCaseHeavyData(caseId, requestId, destination) {
    var caseModal = document.getElementById('createCaseModal');
    if (viewCaseTimings) viewCaseTimings.heavyRequestStart = performance.now() - viewCaseTimings.shellStart;
    setAttachmentsLoadState('loading');
    fetch('api/get-case.php?id=' + encodeURIComponent(caseId) + '&view=heavy', {
      credentials: 'same-origin'
    })
    .then(function(response) {
      if (!response.ok) throw new Error('Heavy case data unavailable');
      return response.json();
    })
    .then(function(data) {
      if (requestId !== caseOpenRequestId || caseModal.style.display === 'none') return;
      if (!data.success || !data.case) return;
      if (String(currentEditCaseId) !== String(caseId)) return;

      if (viewCaseTimings) viewCaseTimings.heavyLoaded = performance.now() - viewCaseTimings.shellStart;

      if (currentEditCaseData && data.case) {
        if (Array.isArray(data.case.attachments)) {
          currentEditCaseData.attachments = data.case.attachments;
          // The core view omits attachments; now that the heavy payload has
          // arrived, render the stored files through the same renderer the
          // board/list path uses and re-evaluate Download All eligibility.
          renderExistingAttachments(data.case.attachments);
          updateDownloadAllButton(currentEditCaseData);
        }
        if (Array.isArray(data.case.revisions)) {
          currentEditCaseData.revisions = data.case.revisions;
        }
      }

      setAttachmentsLoadState('ready');

      // Clinical details are part of the core payload and are populated
      // synchronously before the modal becomes editable; the heavy follow-up
      // must never overwrite user-editable values.

      if (pendingAttachmentScroll) {
        pendingAttachmentScroll = false;
        if (destination && destination.tab === 'files') {
          var attachmentsHeading = createCaseForm ? createCaseForm.querySelector('.attachments-title') : null;
          if (attachmentsHeading) {
            attachmentsHeading.setAttribute('tabindex', '-1');
            attachmentsHeading.focus({ preventScroll: true });
            attachmentsHeading.scrollIntoView({ block: 'start', behavior: 'auto' });
          }
        }
      }
    })
    .catch(function(error) {
      // Heavy data is secondary; failing to load attachments/clinical details
      // should not break the core case editing experience. Surface an honest
      // error state only if this response still belongs to the open case.
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('Failed to load heavy case data:', error);
      }
      if (requestId !== caseOpenRequestId || caseModal.style.display === 'none') return;
      if (String(currentEditCaseId) !== String(caseId)) return;
      setAttachmentsLoadState('error');
    });
  }

  /**
   * Show/hide the attachments loading indicator in the case modal's
   * attachments section. 'loading' while the heavy fetch is in flight,
   * 'error' when it failed, 'ready'/null clears it so a genuinely empty
   * case is visually distinct from a failed load.
   */
  function setAttachmentsLoadState(state) {
    var el = document.getElementById('attachmentsLoadStatus');
    if (!el) return;
    if (state === 'loading') {
      el.textContent = t('attachments.loading');
      el.classList.remove('is-error');
      el.style.display = 'block';
    } else if (state === 'error') {
      el.textContent = t('attachments.load_error');
      el.classList.add('is-error');
      el.style.display = 'block';
    } else {
      el.textContent = '';
      el.classList.remove('is-error');
      el.style.display = 'none';
    }
  }

  function openCaseModalForView(caseData, fetchStart) {
    currentEditCaseId = caseData.id || caseData.case_id || null;
    currentEditCaseData = caseData;

    if (createCaseModal) {
      // Remember the element that opened the modal so focus can be restored on close.
      if (document.activeElement && !caseModalOpener) {
        caseModalOpener = document.activeElement;
      }

      createCaseModal.style.display = 'block';
      if (window.viewCaseTimings) {
        window.viewCaseTimings.modalVisible = performance.now() - window.viewCaseTimings.shellStart;
      }

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
              if (input._caseViewDisabled === undefined) input._caseViewDisabled = input.disabled;
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
    if (pendingViewCaseId && typeof openCaseById === 'function') {
      openCaseById(pendingViewCaseId, pendingCaseOpenOptions);
    }
  });
  if (caseViewErrorClose) caseViewErrorClose.addEventListener('click', closeCreateCaseWithCheck);


  // Unsaved changes tracking
  var originalFormData = null;
  var hasUnsavedChanges = false;
  var isSubmitting = false;
  var caseModalOpener = null;
  // Set by saveAllCaseChanges() while a case save runs with a pending comment
  // draft - consumed by resetFormAndClose()/handleCaseSubmissionError().
  var pendingPostSaveComment = false;
  // Guards the submit click handler against re-entering saveAllCaseChanges()
  // when the orchestrator itself triggers the click.
  var caseFormSubmitDirect = false;

  // Expose case-form dirty state to the comments module (its own closure).
  window.caseFormHasUnsavedChanges = function() {
    return hasUnsavedChanges;
  };

  // Unsaved work = modified case fields OR an unposted comment draft.
  function hasUnsavedWork() {
    var commentDraft = typeof window.caseCommentHasDraft === 'function' &&
      window.caseCommentHasDraft();
    return hasUnsavedChanges || commentDraft;
  }

  /**
   * Shared save for the case modal: saves modified case details AND posts a
   * non-empty comment draft, regardless of which tab initiated it. The case
   * save runs through the existing submit handler (validation, uploads,
   * update-case.php); when a comment draft exists it is flagged with
   * pendingPostSaveComment and posted once the case save resolves - before
   * the modal closes on success, or alongside the error on failure.
   */
  function saveAllCaseChanges() {
    var form = document.getElementById('createCaseForm');
    var isUpdate = !!(form && form.dataset.caseId);

    if (!isUpdate) {
      // Create mode: the Comments tab is unavailable for unsaved cases, so
      // this behaves exactly like the plain create submit. dispatchEvent is
      // used instead of click() because a nested click() is dropped when the
      // trigger was itself a programmatic click (conflict retry, Enter key).
      submitBtn.dispatchEvent(new Event('click'));
      return;
    }

    var hasCommentDraft = typeof window.caseCommentHasDraft === 'function' &&
      window.caseCommentHasDraft();

    if (isSubmitting) {
      // A case save is already in flight - flag the draft so the running save
      // posts it before the modal closes; do not start a second submission.
      if (hasCommentDraft) pendingPostSaveComment = true;
      return;
    }
    var commentInFlight = typeof window.caseCommentInFlight === 'function'
      ? window.caseCommentInFlight() : null;
    if (commentInFlight) {
      // A comment POST is still running - retry once it settles instead of
      // dropping this save. A successful post clears the draft (case-only
      // save proceeds); a failed post keeps it so nothing is lost.
      commentInFlight.then(function() { saveAllCaseChanges(); });
      return;
    }

    if (!hasUnsavedChanges && !hasCommentDraft) {
      if (typeof showToast === 'function') {
        showToast(t('cases.toast.nothing_to_save'), 'info');
      }
      return;
    }

    if (hasUnsavedChanges) {
      // Validate pending case edits before anything is submitted. On failure
      // nothing is saved - the comment draft and edits stay in place so the
      // user can fix the fields and retry once.
      if (!validateCaseForm(form)) {
        return;
      }
      pendingPostSaveComment = hasCommentDraft;
      caseFormSubmitDirect = true;
      // dispatchEvent, not click(): a nested click() on the same button is
      // silently dropped (click-in-progress flag) when this save was itself
      // triggered by a programmatic click - e.g. the conflict dialog's
      // "Keep My Version" or the Enter-key shortcut.
      submitBtn.dispatchEvent(new Event('click'));
      caseFormSubmitDirect = false;
      if (!isSubmitting) pendingPostSaveComment = false; // submit was blocked
      return;
    }

    if (hasCommentDraft && typeof window.postCaseComment === 'function') {
      window.postCaseComment();
    }
  }
  window.saveAllCaseChanges = saveAllCaseChanges;

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

    // Keep the Comments-tab submit button label in sync ("Add Comment" vs
    // "Save All Changes" when case edits are pending).
    if (typeof window.updateCaseCommentSubmitState === 'function') {
      window.updateCaseCommentSubmitState();
    }
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

    if (hasUnsavedWork()) {
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

  // Compact attachment categories: keep the per-category file count and
  // the has-files styling in sync with every render path (new selections,
  // existing-file rendering, removals, clears) via one observer instead
  // of instrumenting each mutation site. Legacy groups
  // (radiographs/documents) stay hidden until they hold files.
  function refreshAttachmentGroupStates() {
    document.querySelectorAll('.attachments-grid .attachment-group').forEach(function(group) {
      var container = group.querySelector('.selected-files');
      var count = container ? container.querySelectorAll('.selected-file').length : 0;
      group.classList.toggle('has-files', count > 0);
      var countEl = group.querySelector('[data-attachment-count]');
      if (countEl) {
        countEl.textContent = count > 0
          ? t(count === 1 ? 'attachments.file_one' : 'attachments.file_other', {count: count})
          : '';
      }
    });
  }

  if (typeof MutationObserver === 'function') {
    var attachmentObserver = new MutationObserver(refreshAttachmentGroupStates);
    document.querySelectorAll('.attachments-grid .selected-files').forEach(function(container) {
      attachmentObserver.observe(container, { childList: true });
    });
  }

  /**
   * Validate the case form: globally required fields, case-type conditional
   * fields, Crown tooth numbering, and the notes length limit. On failure the
   * affected fields are highlighted, the modal switches to the Details tab
   * (errors live there even when the save was triggered from another tab),
   * and the first error is scrolled into view. Returns true when the form is
   * safe to submit.
   */
  function validateCaseForm(form) {
    var isValid = true;

    // Inline error helpers are module-scoped (addCaseFieldError /
    // clearCaseFieldError) so server-reported field errors render the
    // same way; the no-arg call uses the localized validation.required.
    var addFieldError = addCaseFieldError;
    var clearFieldError = clearCaseFieldError;

    // Check all globally required fields (fields with required attribute)
    var requiredFields = form.querySelectorAll('[required]');

    requiredFields.forEach(function(field) {
      if (!field.value) {
        isValid = false;
        addFieldError(field);
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

    // Custom Carrier is required when Carrier = Other and a tracking
    // number is provided - same rule the server enforces
    // (api.cases.other_carrier_required).
    var carrierSelect = document.getElementById('carrier');
    var trackingInput = document.getElementById('trackingNumber');
    var customCarrierInput = document.getElementById('customCarrier');
    if (carrierSelect && trackingInput && customCarrierInput
        && carrierSelect.value === 'Other'
        && trackingInput.value.trim() !== ''
        && customCarrierInput.value.trim() === '') {
      isValid = false;
      addFieldError(customCarrierInput, t('api.cases.other_carrier_required'));
    } else if (customCarrierInput) {
      clearFieldError(customCarrierInput);
    }

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
      // Errors live on the Details tab - switch to it when the save was
      // triggered from another tab so the highlighted fields are visible.
      if (typeof setCaseModalActiveTab === 'function') {
        setCaseModalActiveTab('details');
      }
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
    }

    return isValid;
  }

  // Form validation and submission with enhanced UX
  if (submitBtn) {
    submitBtn.addEventListener('click', function() {
      var form = document.getElementById('createCaseForm');
      var isUpdateMode = !!(form && form.dataset.caseId);

      // Edit mode: route through the shared save so a pending comment draft
      // posts together with case changes. caseFormSubmitDirect is set by
      // saveAllCaseChanges() itself when it forwards the click.
      if (isUpdateMode && !caseFormSubmitDirect) {
        saveAllCaseChanges();
        return;
      }

      // Prevent multiple submissions
      if (isSubmitting) {
        return false;
      }

      if (!validateCaseForm(form)) {
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

        // Copy all non-file form fields (disabled controls - e.g. the
        // edit-only Status select in Create Case - are never submitted)
        var formElements = form.elements;
        for (var i = 0; i < formElements.length; i++) {
          var el = formElements[i];
          if (el.name && !el.disabled && el.type !== 'file' && el.type !== 'submit' && el.type !== 'button') {
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

              // Preserve field-targeted error info so the handler can
              // render inline errors on the exact failing field(s).
              var fieldError = new Error(errorMessage);
              if (errorData.field) fieldError.field = errorData.field;
              if (errorData.missingFields) fieldError.missingFields = errorData.missingFields;
              throw fieldError;
            } catch (e) {
              if (e.sessionExpired) throw e; // Re-throw session errors
              if (e.conflict) throw e; // Re-throw conflict errors
              if (e.field || e.missingFields) throw e; // Re-throw field errors
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
    // A backward status change saved through Edit Case may be a remake -
    // capture the id now because resetFormAndClose clears dataset.caseId.
    var regressionCaseId = (isUpdate && data.isRegression === true) ? form.dataset.caseId : null;

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
        // Ask whether the backward move was a remake (move already saved).
        if (regressionCaseId && typeof window.promptRemakeForRegression === 'function') {
          window.promptRemakeForRegression(regressionCaseId);
        }
      }, 800);
    });
  }

  // Optimized error handler
  function handleCaseSubmissionError(error, form, submitBtn, isUpdate) {
    submitBtn.classList.remove('submitting');
    submitBtn.classList.add('error');
    submitBtn.innerHTML = '<span class="btn-error"></span> ' + t('common.error');

    // The comment draft is an independent record - post it even though the
    // case save failed, so the user's reply is not silently left behind.
    if (pendingPostSaveComment) {
      pendingPostSaveComment = false;
      if (typeof window.postCaseComment === 'function') {
        window.postCaseComment();
      }
    }

    // Handle concurrent edit conflict
    if (error.conflict) {
      showConcurrentEditConflictDialog(error, form);
      // Reset button immediately for conflict
      submitBtn.classList.remove('error');
      submitBtn.disabled = false;
      submitBtn.innerHTML = isUpdate ? t('cases.save_all_changes') : t('cases.create_case');
      isSubmitting = false;
      return;
    }

    // Show appropriate error message
    // Errors from gcs-upload.js already contain user-friendly text
    // (e.g. "STL files must be under 250MB", "Maximum 15 files per upload")
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

    // Server field errors (missingFields / field) get the same inline
    // treatment as client validation - the toast stays as a summary.
    applyServerFieldErrors(error);

    showToast(errorMessage, 'error');

    // Reset button after error animation
    setTimeout(() => {
      submitBtn.classList.remove('error');
      submitBtn.disabled = false;
      submitBtn.innerHTML = isUpdate ? t('cases.save_all_changes') : t('cases.create_case');
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
    submitBtn.innerHTML = isUpdate ? t('cases.save_all_changes') : t('cases.create_case');

    // A comment draft was pending when the case saved - post it now and only
    // close once it succeeds, so the modal never resets with work left over.
    if (pendingPostSaveComment && typeof window.postCaseComment === 'function') {
      pendingPostSaveComment = false;
      window.postCaseComment().then(function(commentPosted) {
        if (commentPosted) {
          closeCreateCase();
          form.reset();
          clearFileSelections();
        } else {
          // Case saved; the comment is the only thing left. Re-baseline the
          // form so the just-saved values are not treated as unsaved edits,
          // and keep the draft in place for retry.
          originalFormData = new FormData(form);
          hasUnsavedChanges = false;
          if (typeof showToast === 'function') {
            showToast(t('cases.toast.case_saved_comment_failed'), 'error');
          }
          if (typeof window.updateCaseCommentSubmitState === 'function') {
            window.updateCaseCommentSubmitState();
          }
        }
      });
      return;
    }

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

  // Material is a .clinical-field inside Clinical Details: visibility,
  // clear-on-hide and conditional requiredness are driven by
  // updateClinicalFieldsVisibility() and the data-conditionally-required
  // validation path (clinical-details.js), keyed off the same
  // data-case-types list the server renders from getCaseTypesRequiringMaterial().

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
    // Disable drag-and-drop if trial expired or on phone widths
    if (billingInfo && billingInfo.is_trial && billingInfo.trial_expired) {
      return;
    }
    if (isTouchPhone()) {
      // Phones use the column carousel and card action menu instead.
      document.querySelectorAll('.kanban-card').forEach(card => {
        card.setAttribute('draggable', 'false');
      });
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
      if (column.dataset.dropBound) return;
      column.dataset.dropBound = 'true';
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
    if (card.dataset.dragBound) return;
    card.dataset.dragBound = 'true';
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

      if (window.caseFilterSort) window.caseFilterSort.rememberCard(card);
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

          if (typeof window.triggerCardsUpdated === 'function') {
            window.triggerCardsUpdated();
          }

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

        // A backward move may be a remake - ask, never auto-record. The
        // move and its case_regression/revision record are already saved.
        if (data.isRegression === true && typeof window.promptRemakeForRegression === 'function') {
          var regressionCaseId = cardData.id || cardData.case_id;
          setTimeout(function () { window.promptRemakeForRegression(regressionCaseId); }, 400);
        }
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

        if (typeof window.triggerCardsUpdated === 'function') {
          window.triggerCardsUpdated();
        }

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
            if (typeof window.triggerCardsUpdated === 'function') {
              window.triggerCardsUpdated();
            }

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
      if (!isFinalWorkflowColumn(status) && caseData.dueDate) {
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
      if (!isPastDue && !isFinalWorkflowColumn(status) && caseData.patientAppointmentDate && highlightAppointmentRisk) {
        var appointmentRiskDays = parseInt(localStorage.getItem('appointment_risk_days') || '3', 10);
        apptDayDiff = getCalendarDayDiff(caseData.patientAppointmentDate);
        if (apptDayDiff !== null && apptDayDiff <= appointmentRiskDays) {
          caseCard.classList.add('kanban-card-appointment-risk');
          isAppointmentRisk = true;
          apptRiskText = t('cases.risk.appointment_abbreviation');
        }
      }

      // Coming Due (blue) if not already Late or Appointment Risk
      if (!isPastDue && !isAppointmentRisk && !isFinalWorkflowColumn(status) && caseData.dueDate) {
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
        createdByName: caseData.createdByName || 'Unknown',
        reviewedAt: caseData.reviewedAt || null,
        reviewedByUserId: caseData.reviewedByUserId || null,
        reviewedByName: caseData.reviewedByName || 'Unknown',
        reviewStatus: caseData.reviewStatus || 'needs_review'
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
        createdByName: completeData.createdByName || 'Unknown',
        reviewedAt: completeData.reviewedAt || null,
        reviewedByUserId: completeData.reviewedByUserId || null,
        reviewedByName: completeData.reviewedByName || 'Unknown',
        reviewStatus: completeData.reviewStatus || 'needs_review'
      };
      caseCard.dataset.caseJson = JSON.stringify(displayData);
      caseCard.dataset.caseId = displayData.id;

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

      // Build review status badge
      var isReviewed = displayData.reviewStatus === 'reviewed';
      var reviewClass = isReviewed ? 'reviewed' : 'needs-review';
      var reviewText = isReviewed ? t('cases.reviewed') : t('cases.needs_review');
      var reviewTooltip = '';
      if (isReviewed && displayData.reviewedAt) {
        reviewTooltip = (displayData.reviewedByName || 'Unknown') + ' · ' + formatDate(displayData.reviewedAt, true);
      } else {
        reviewTooltip = isReviewed ? t('cases.mark_needs_review') : t('cases.mark_reviewed');
      }
      var reviewAriaLabel = isReviewed ? t('cases.mark_needs_review_aria') : t('cases.mark_reviewed_aria');
      var reviewTitleAttr = ' title="' + escapeHtml(reviewTooltip).replace(/"/g, '&quot;') + '"';
      var reviewBadgeHtml = '<button type="button" class="kanban-card-review ' + reviewClass + '" data-case-id="' + escapeHtml(displayData.id) + '" aria-label="' + escapeHtml(reviewAriaLabel) + '"' + reviewTitleAttr + '>' + reviewText + '</button>';

      caseCard.innerHTML =
        '<div class="kanban-card-header">' +
        '  <div>' +
        '    <h3 class="kanban-card-title">' + (displayData.patientFirstName || '') + ' ' + (displayData.patientLastName || '') + '</h3>' +
        revisionCountLine +
        '  </div>' +
        reviewBadgeHtml +
        '  <button type="button" class="case-actions-toggle" data-case-id="' + displayData.id + '" title="' + t('cases.actions_menu') + '" aria-label="' + t('cases.actions_menu') + '" aria-haspopup="menu" aria-expanded="false">⋮</button>' +
        '</div>' +
        '<div class="kanban-card-content">' +
        '  <p><strong>' + t('cases.type') + ':</strong> ' + (getCaseTypeDisplayLabel(displayData.caseType) || '') + '</p>' +
        '  <p><strong>' + t('cases.due_label') + ':</strong> ' + (displayData.dueDate ? formatDate(displayData.dueDate) : '\u2014') + '<span class="late-indicator">' + (dueIndicatorText || '') + '</span></p>' +
        (displayData.patientAppointmentDate ? '  <p class="kanban-card-appointment-row"><strong>' + t('cases.patient_appointment_short') + ':</strong> ' + formatDate(displayData.patientAppointmentDate) + (apptRiskText ? '<span class="appointment-risk-indicator">' + apptRiskText + '</span>' : '') + '</p>' : '') +
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
        '  <div><span class="date-label">' + t('cases.created') + ':</span> <span class="date-value">' + formatDate(creationDate, false) + '</span></div>' +
        '  <div><span class="date-label">' + t('cases.updated') + ':</span> <span class="date-value">' + formatDate(lastUpdateDate, false) + '</span></div>' +
        (window.featureFlags && window.featureFlags.SHOW_IN_STATUS ? '  <div><span class="date-label">' + t('cases.in_status') + ':</span> <span class="date-value days-in-status">' + getDaysInStatus(displayData.statusChangedAt) + '</span></div>' : '') +
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
      if (window.caseFilterSort) window.caseFilterSort.rememberCard(caseCard);

      // Add click event for the review status badge
      var reviewBadge = caseCard.querySelector('.kanban-card-review');
      if (reviewBadge) {
        reviewBadge.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();

          if (reviewBadge.disabled || reviewBadge.getAttribute('aria-disabled') === 'true') {
            return;
          }

          var rawData = caseCard.dataset.caseJson;
          var cardData;
          try {
            cardData = JSON.parse(rawData || '{}');
          } catch (e) {
            cardData = {};
          }

          if (!cardData.id || cardData.archived) {
            return;
          }

          var currentlyReviewed = cardData.reviewStatus === 'reviewed';
          window.updateCaseReviewStatus(cardData.id, !currentlyReviewed);
        });

        reviewBadge.addEventListener('mousedown', function(e) {
          e.stopPropagation();
        });

        reviewBadge.addEventListener('dragstart', function(e) {
          e.preventDefault();
          e.stopPropagation();
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

      // Make the card draggable only on desktop; phones use the action menu
      // and swipe-to-column behavior instead of drag-and-drop.
      if (!isTouchPhone()) {
        caseCard.setAttribute('draggable', 'true');
        addDragListeners(caseCard);
      } else {
        caseCard.setAttribute('draggable', 'false');
      }

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
    if (!window.viewCaseTimings) {
      window.viewCaseTimings = {
        source: 'kanban',
        shellStart: performance.now(),
        shellVisible: null,
        getCaseRequestStart: null,
        getCaseResponseMs: null,
        getCaseParseMs: null,
        getCaseServerMs: null,
        editCaseHandlerStart: null,
        editCaseHandlerEnd: null,
        fieldsPopulated: null,
        tabActivated: null,
        modalUsable: null
      };
    }
    window.viewCaseTimings.editCaseHandlerStart = performance.now() - window.viewCaseTimings.shellStart;

    // Remember the current case data for later heavy/secondary updates.
    currentEditCaseId = caseData.id || caseData.case_id || null;
    currentEditCaseData = caseData;

    // Check if any case is currently being printed
    if (window.isPrintingCase) {
      return;
    }

    // Preserve the active mobile column so it can be restored after the modal closes.
    if (window.MobileKanban && typeof window.MobileKanban.saveActiveColumn === 'function' && window.matchMedia('(max-width: 480px)').matches) {
      window.MobileKanban.saveActiveColumn();
    }

    // Remember the element that opened the modal so focus can be restored on close.
    if (document.activeElement && !caseModalOpener) {
      caseModalOpener = document.activeElement;
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
    if (submitBtn) submitBtn.textContent = t('cases.save_all_changes');

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
    } else if (form.patientDOB) {
      form.patientDOB.value = '';
    }

    // Set patient gender
    if (form.patientGender) form.patientGender.value = caseData.patientGender || '';

    if (form.dentistName) form.dentistName.value = caseData.dentistName || '';
    if (form.caseType) setCaseTypeValue(form.caseType, caseData.caseType || '');
    if (form.toothShade) form.toothShade.value = caseData.toothShade || '';

    // Material value is set here; its visibility/required state is derived
    // from caseType by setClinicalDetailsData() below (material is a
    // .clinical-field now - no separate visibility call needed).
    if (form.material) form.material.value = caseData.material || '';

    // Handle due date carefully
    if (form.dueDate) {
      if (caseData.dueDate) {
        try {
          var dueDate = new Date(caseData.dueDate);
          // Check if date is valid before setting
          if (!isNaN(dueDate.getTime())) {
            form.dueDate.value = dueDate.toISOString().split('T')[0];
          }
        } catch (e) {
          // Error formatting due date
        }
      } else {
        form.dueDate.value = '';
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
      } else {
        form.patientAppointmentDate.value = '';
      }
    }

    if (form.status) form.status.value = caseData.status || '';
    if (form.notes) form.notes.value = caseData.notes || '';
    if (form.carrier) form.carrier.value = caseData.carrier || '';
    if (form.customCarrier) form.customCarrier.value = caseData.customCarrier || '';
    if (form.trackingNumber) form.trackingNumber.value = caseData.trackingNumber || '';
    toggleCustomCarrierField();
    updateTrackingNumberLink();
    updateShippingSectionState();

    // Load clinical details if available
    var clinicalDetails = caseData.clinicalDetails || caseData.clinical_details || null;
    var caseTypeValue = caseData.caseType || '';
    if (typeof setClinicalDetailsData === 'function') {
      setClinicalDetailsData(clinicalDetails, caseTypeValue);
    }

    // Store the case ID for update handling
    form.dataset.caseId = caseData.id || '';

    // Meta row (Created By / review status / Record Remake) shows only
    // for saved cases - dataset.caseId must be set first.
    updateCaseModalMeta();

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

    // Record Remake is an explicit action for active saved cases; hidden
    // for archived read-only views (server rejects archived regardless).
    var recordRemakeBtn = document.getElementById('recordRemakeBtn');
    if (recordRemakeBtn) {
      recordRemakeBtn.hidden = !(caseData && (caseData.id || caseData.case_id) && !caseData.archived);
    }
    form.dataset.caseArchived = caseData && caseData.archived ? '1' : '0';
    if (typeof window.loadCaseRemakeHistory === 'function') {
      window.loadCaseRemakeHistory(caseData && (caseData.id || caseData.case_id), { archived: !!(caseData && caseData.archived) });
    }

    // Render review status panel
    renderReviewStatus(caseData);

    // Clear file selections
    clearFileSelections();
    // A stale heavy-load error/loading note must not leak into this case.
    setAttachmentsLoadState(null);

    // Display existing attachments if any (shared with the async heavy-data
    // path so all entry points render identical attachment lists).
    renderExistingAttachments(caseData.attachments);


    // Update bulk download button based on eligible attachments. This must run
    // for every case open - not only when the case has attachments - so the
    // control never leaks a previous case's enabled state.
    updateDownloadAllButton(caseData);

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

    // Render the compact mobile case summary (phone viewports only).
    if (window.MobileCaseModal && typeof window.MobileCaseModal.renderSummary === 'function') {
      window.MobileCaseModal.renderSummary(caseData);
    }

    if (window.viewCaseTimings) {
      window.viewCaseTimings.editCaseHandlerEnd = performance.now() - window.viewCaseTimings.shellStart;
      if (!window.viewCaseTimings.fieldsPopulated) {
        window.viewCaseTimings.fieldsPopulated = window.viewCaseTimings.editCaseHandlerEnd;
      }
    }
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
            data.workflowStageLabels || {},
            data.workflowColumns || null,
            data.currentPracticeId
          );

          // Set localStorage values for past due and coming due highlighting.
          // toBoolean guards against numeric/boolean values being sent as strings.
          if (data.preferences.highlight_past_due !== undefined) {
            localStorage.setItem('highlight_past_due', toBoolean(data.preferences.highlight_past_due, true) ? 'true' : 'false');
          }
          if (data.preferences.past_due_days !== undefined) {
            localStorage.setItem('past_due_days', data.preferences.past_due_days.toString());
          }
          if (data.preferences.highlight_coming_due !== undefined) {
            localStorage.setItem('highlight_coming_due', toBoolean(data.preferences.highlight_coming_due, false) ? 'true' : 'false');
          }
          if (data.preferences.coming_due_days !== undefined) {
            localStorage.setItem('coming_due_days', data.preferences.coming_due_days.toString());
          }
          if (data.preferences.highlight_appointment_risk !== undefined) {
            localStorage.setItem('highlight_appointment_risk', toBoolean(data.preferences.highlight_appointment_risk, true) ? 'true' : 'false');
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

    var requestId = window.caseFilterSort ? window.caseFilterSort.nextRequest() : 0;
    fetch('api/list-cases.php?' + (window.caseFilterSort ? window.caseFilterSort.query() : ''), {
      method: 'GET',
      credentials: 'same-origin'
    })
    .then(function (response) {
      return response.json();
    })
    .then(function (data) {
      if (window.caseFilterSort && !window.caseFilterSort.currentRequest(requestId)) { hideLoader(); return; }
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
      var renderLoadedCases = function () { data.cases.forEach(function (caseData) {
        // Deep clone to ensure we don't lose data
        var clonedCase = JSON.parse(JSON.stringify(caseData));

        // Each case from the API already has status, dueDate, etc.
        addCaseToKanban(clonedCase);
      }); };
      if (window.caseFilterSort) window.caseFilterSort.withBoardRender(renderLoadedCases);
      else renderLoadedCases();

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

  // Remove the purple APPT RISK badge element (if any) from a card. The badge is
  // only ever rendered when the case qualifies, so removing the node entirely
  // avoids leaving an empty styled span behind.
  function removeAppointmentRiskBadge(card) {
    var apptIndicator = card.querySelector('.appointment-risk-indicator');
    if (apptIndicator) apptIndicator.remove();
  }

  // Add the purple APPT RISK badge to the card's appointment row, creating the
  // span only when the case actually qualifies.
  function addAppointmentRiskBadge(card) {
    var row = card.querySelector('.kanban-card-appointment-row');
    if (!row) return;
    var apptIndicator = row.querySelector('.appointment-risk-indicator');
    if (!apptIndicator) {
      apptIndicator = document.createElement('span');
      apptIndicator.className = 'appointment-risk-indicator';
      row.appendChild(apptIndicator);
    }
    apptIndicator.textContent = t('cases.risk.appointment_abbreviation');
  }

  // Function to clear highlighting from a specific card
  function clearCardHighlighting(card) {
    card.classList.remove('kanban-card-past-due');
    card.classList.remove('kanban-card-coming-due');
    card.classList.remove('kanban-card-appointment-risk');
    var lateIndicator = card.querySelector('.late-indicator');
    if (lateIndicator) {
      lateIndicator.textContent = '';
    }
    removeAppointmentRiskBadge(card);
  }

  // Apply the full urgency hierarchy (Late > Appt Risk > Coming Due) to a card.
  // This is the single source of truth used both when cards are created and when
  // settings are updated, so the visual state matches the filter logic.
  function applyUrgencyHighlighting(caseData) {
    // Find the card element first so we can clear stale highlights even when
    // the case has moved into the final workflow column.
    var cardElement = document.querySelector('[data-case-id="' + caseData.id + '"]');
    if (!cardElement) return;
    var card = cardElement.closest('.kanban-card');
    if (!card) return;

    var lateIndicator = card.querySelector('.late-indicator');

    // Clear previous state before re-evaluating so moving into the final
    // workflow column immediately removes Late/Due Soon/Appointment Risk warnings.
    card.classList.remove('kanban-card-past-due');
    card.classList.remove('kanban-card-coming-due');
    card.classList.remove('kanban-card-appointment-risk');
    if (lateIndicator) lateIndicator.textContent = '';
    removeAppointmentRiskBadge(card);

    // Don't highlight cases in the final workflow column (they are essentially closed).
    // The final column is the last active workflow column for this practice.
    if (isFinalWorkflowColumn(caseData.status)) return;

    var highlightPastDue = localStorage.getItem('highlight_past_due') === 'true';
    var highlightComingDue = localStorage.getItem('highlight_coming_due') === 'true';
    var highlightAppointmentRisk = localStorage.getItem('highlight_appointment_risk') === 'true';

    var pastDueDays = parseInt(localStorage.getItem('past_due_days') || '1', 10);
    var comingDueDays = parseInt(localStorage.getItem('coming_due_days') || '5', 10);
    var appointmentRiskDays = parseInt(localStorage.getItem('appointment_risk_days') || '3', 10);

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
        addAppointmentRiskBadge(card);
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

        // Reset all case-actions toggles
        var stuckToggles = document.querySelectorAll('.case-actions-toggle');
        stuckToggles.forEach(function(button) {
          button.disabled = false;
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
      }
    }, 60000); // 60 second timeout (matches server-side limit)

    // Disable all case-actions toggles during printing (Edit/Print/Archive
    // all live inside the menu now; disabling the trigger blocks them all)
    var actionToggles = document.querySelectorAll('.case-actions-toggle');
    actionToggles.forEach(function(button) {
      button.disabled = true;
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
      window.isPrintingCase = false;
      window.currentlyPrintingCaseId = null;

      // Re-enable all case-actions toggles after printing
      var actionToggles = document.querySelectorAll('.case-actions-toggle');
      actionToggles.forEach(function(button) {
        button.disabled = false;
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
      // Must be 'flex', not 'block': an inline display:block overrides the
      // stylesheet's column flexbox, which is what keeps the button pinned to
      // the right edge when the (wider) status line appears.
      container.style.display = 'flex';
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
  // 3. Uses a persistent hidden iframe so the browser can stream arbitrarily
  //    large archives directly to disk instead of buffering the whole ZIP in
  //    JavaScript. The iframe is never removed: detaching the frame that
  //    initiated a download can abort it mid-stream in some browsers.
  // 4. Detects browser handoff via a short-lived cookie (dt_zip_dl_<token>)
  //    that the server sets when it starts emitting the ZIP response, and
  //    detects failures by reading the JSON error document that error
  //    responses load into the same-origin iframe.
  var zipDownloadInFlight = false;
  var ZIP_DOWNLOAD_HANDOFF_TIMEOUT_MS = 240000;

  function getZipDownloadFrame() {
    var frame = document.getElementById('dtZipDownloadFrame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'dtZipDownloadFrame';
      frame.name = 'dtZipDownloadFrame';
      frame.title = 'attachment download';
      frame.style.display = 'none';
      frame.setAttribute('aria-hidden', 'true');
      document.body.appendChild(frame);
    }
    return frame;
  }

  // If the download endpoint returned a JSON error document, it is rendered
  // inside the hidden iframe; extract its message. Returns null when the
  // frame holds no readable document (about:blank or a converted download).
  function readZipIframeError(frame) {
    try {
      var doc = frame.contentDocument;
      if (!doc || !doc.body || doc.URL === 'about:blank') return null;
      var text = (doc.body.textContent || '').trim();
      if (!text) return null;
      try {
        var data = JSON.parse(text);
        if (data && (data.error || data.message)) {
          return String(data.error || data.message);
        }
      } catch (parseErr) {
        // Non-JSON response body (e.g. an HTML error page).
      }
      return t('attachments.download_all_failed', { message: 'Unexpected response from the server' });
    } catch (e) {
      return null;
    }
  }

  function downloadCaseAttachmentsZip(caseId) {
    var btn = document.getElementById('downloadAllAttachmentsBtn');
    var statusEl = document.getElementById('downloadAllAttachmentsStatus');
    var btnLabel = btn ? btn.querySelector('.download-all-label') : null;

    function setStatus(message) {
      if (statusEl) {
        statusEl.textContent = message || '';
        statusEl.style.display = message ? 'block' : 'none';
      }
    }
    function restoreButton() {
      if (btn) {
        btn.disabled = false;
        if (btnLabel) btnLabel.textContent = t('attachments.download_all');
      }
    }

    if (!caseId) {
      setStatus(t('attachments.download_all_no_eligible'));
      return;
    }

    if (zipDownloadInFlight) {
      setStatus(t('attachments.download_all_in_progress'));
      return;
    }
    zipDownloadInFlight = true;
    if (btn) {
      btn.disabled = true;
      if (btnLabel) btnLabel.textContent = t('attachments.download_all_preparing');
    }
    setStatus(t('attachments.download_all_preparing'));

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
        throw new Error(t('attachments.download_all_failed', { message: 'Unexpected response from the server' }));
      });
    })
    .then(function(data) {
      if (!data || !data.success) {
        throw new Error(data && data.error ? data.error : t('attachments.download_all_failed', { message: 'Request failed' }));
      }

      var token = 'dl' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
      var cookieName = 'dt_zip_dl_' + token;
      document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';

      var frame = getZipDownloadFrame();
      var settled = false;
      var pollTimer = null;
      var deadlineTimer = null;

      function finish(kind, detail) {
        if (settled) return;
        settled = true;
        zipDownloadInFlight = false;
        if (pollTimer) clearInterval(pollTimer);
        if (deadlineTimer) clearTimeout(deadlineTimer);
        frame.onload = null;
        document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        restoreButton();
        if (kind === 'handoff') {
          setStatus(t('attachments.download_started'));
        } else if (kind === 'timeout') {
          setStatus(t('attachments.download_all_timeout'));
        } else {
          setStatus(t('attachments.download_all_failed', { message: detail || 'Request failed' }));
        }
      }

      // Error responses render a JSON document inside the same-origin iframe
      // and fire load; a successful ZIP response becomes a browser download
      // and never produces a readable document.
      frame.onload = function() {
        var serverError = readZipIframeError(frame);
        if (serverError !== null) {
          finish('error', serverError);
        }
      };

      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'api/download-case-attachments-zip.php';
      form.target = frame.name;
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
      addInput('download_token', token);

      document.body.appendChild(form);
      form.submit();
      setTimeout(function() {
        if (form.parentNode) {
          form.parentNode.removeChild(form);
        }
      }, 1000);

      // The server sets the handoff cookie with the ZIP response headers;
      // observing it proves the browser received the download response. It
      // does not prove the file finished saving to disk.
      pollTimer = setInterval(function() {
        if (document.cookie.indexOf(cookieName + '=1') !== -1) {
          finish('handoff');
        }
      }, 500);
      deadlineTimer = setTimeout(function() {
        finish('timeout');
      }, ZIP_DOWNLOAD_HANDOFF_TIMEOUT_MS);
    })
    .catch(function(error) {
      zipDownloadInFlight = false;
      setStatus(t('attachments.download_all_failed', { message: error.message }));
      restoreButton();
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
            // Reveal the link only after the authoritative billing text is set,
            // preventing a flash of the placeholder "Billing" label.
            billingTierElement.style.visibility = 'visible';
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
          billingTierElement.style.visibility = 'visible';
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

  // Remember the last selected Insights subview (Practice or Lab) so that
  // switching between the Cases and Insights top tabs does not reset it.
  const INSIGHTS_VIEW_KEY = 'lastInsightsSubview';
  function getDefaultInsightsSubview() {
    try {
      const saved = sessionStorage.getItem(INSIGHTS_VIEW_KEY);
      if (saved === 'practice' || saved === 'labs') { return saved; }
    } catch (e) { /* storage unavailable */ }
    return 'practice';
  }
  function saveInsightsSubview(view) {
    try {
      if (view === 'practice' || view === 'labs') {
        sessionStorage.setItem(INSIGHTS_VIEW_KEY, view);
      }
    } catch (e) { /* storage unavailable */ }
  }

  function activateInsightsSubview(view, updateHash) {
    updateHash = updateHash !== false;
    mainTabPanes.forEach(p => p.classList.remove('active'));
    const targetPane = document.getElementById(view === 'labs' ? 'lab-insights-tab' : 'insights-tab');
    if (targetPane) { targetPane.classList.add('active'); }

    insightsSubtabs.forEach(st => {
      const isActive = st.dataset.insightsSubtab === view;
      st.classList.toggle('active', isActive);
      st.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    setInsightsLoading(view, true);
    setInsightsError(view, '');

    // Insights data requires a Control-plan entitlement in addition to the
    // analytics permission. main.php emits window.userHasControlAccess
    // (true / false / null = evaluation failed) and renders the upgrade or
    // error state whenever it is not true, so protected data must never be
    // fetched unless it is exactly true.
    var hasInsightsAccess = window.userHasControlAccess === true;

    // Mark a pending Insights visit: the next data load for this view carries
    // the X-Insights-Visit header so the server records a last-viewed
    // timestamp once the screen actually renders successfully. Refresh
    // buttons, filter changes, and settingsUpdated refetches never set this
    // flag, so background data refreshes do not count as visits.
    window.__insightsVisitPending = window.__insightsVisitPending || {};
    window.__insightsVisitPending[view] = hasInsightsAccess;

    saveInsightsSubview(view);

    if (hasInsightsAccess) {
      if (view === 'practice') {
        loadAnalyticsScripts();
      } else if (view === 'labs') {
        loadLabInsightsScripts();
      }
    }

    if (updateHash && window.history.replaceState) {
      window.history.replaceState(null, null, '#insights/' + view);
    }

    // Notify any chart listeners that the Insights pane is now visible so
    // they can recompute their dimensions after the tab change.
    setTimeout(function () {
      document.dispatchEvent(new CustomEvent('insightsVisible'));
      try { window.dispatchEvent(new Event('resize')); } catch (e) {}
    }, 0);
  }

  mainTabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const targetTab = tab.dataset.tab;

      // Check if user has access to analytics
      if (targetTab === 'analytics' && billingInfo && !billingInfo.has_analytics) {
        showToast(t('insights.upgrade.analytics_control'), 'warning');
        return;
      }

      // Block Insights tab when the user lacks analytics permission or the trial is expired
      if (targetTab === 'insights' && !window.userCanViewAnalytics) {
        showToast(t('insights.no_permission'), 'warning');
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

      // Insights top tab restores the last selected Practice/Lab subview
      // instead of always defaulting to Practice.
      if (targetTab === 'insights') {
        activateInsightsSubview(getDefaultInsightsSubview(), false);
      }
    });
  });

  insightsSubtabs.forEach(st => {
    st.addEventListener('click', () => {
      const view = st.dataset.insightsSubtab;
      if (!view) { return; }
      if (!window.userCanViewAnalytics) {
        showToast(t('insights.no_permission'), 'warning');
        return;
      }
      // Keep the top Insights tab active; just switch the subview
      mainTabPanes.forEach(p => p.classList.remove('active'));
      activateInsightsSubview(view);
    });
  });

  // Deep linking: #insights/practice or #insights/labs
  function applyInitialInsightsHash() {
    const hash = (window.location.hash || '').replace(/^#/, '');
    if (hash === 'insights' || hash === 'insights/practice') {
      const pane = document.getElementById('insights-tab');
      if (!pane) { return; }
      mainTabs.forEach(t => t.classList.remove('active'));
      mainTabPanes.forEach(p => p.classList.remove('active'));
      const insightsTab = document.querySelector('.main-tab[data-tab="insights"]');
      if (insightsTab) { insightsTab.classList.add('active'); }
      pane.classList.add('active');
      activateInsightsSubview(getDefaultInsightsSubview(), false);
    } else if (hash === 'insights/labs') {
      const pane = document.getElementById('lab-insights-tab');
      if (!pane) { return; }
      mainTabs.forEach(t => t.classList.remove('active'));
      mainTabPanes.forEach(p => p.classList.remove('active'));
      const insightsTab = document.querySelector('.main-tab[data-tab="insights"]');
      if (insightsTab) { insightsTab.classList.add('active'); }
      pane.classList.add('active');
      activateInsightsSubview('labs', false);
    }
  }

  // Lazy load Chart.js (shared by both Practice Insights and Lab Insights)
  // Declared before any activation function so the callback queue is always
  // initialized, even when Practice Insights is triggered by the initial hash.
  var chartJsLoaded = false;
  var chartJsLoading = false;
  var chartJsLoadFailed = false;
  var chartJsCallbacks = [];
  function ensureChartJsLoaded(callback) {
    if (chartJsLoaded) {
      callback(null);
      return;
    }
    if (chartJsLoadFailed) {
      callback(new Error('Chart.js previously failed to load'));
      return;
    }
    // Defensive: the queue must exist before any push. This guards against
    // accidental future reordering; the real fix is the call order below.
    if (!chartJsCallbacks) {
      chartJsCallbacks = [];
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
      chartJsLoading = false;
      var cbs = chartJsCallbacks;
      chartJsCallbacks = [];
      cbs.forEach(function(cb) { cb(null); });
    };
    chartScript.onerror = function() {
      chartJsLoading = false;
      chartJsLoadFailed = true;
      if (typeof console !== 'undefined' && console.error) {
        console.error('[Chart.js] Failed to load Chart.js from ' + chartScript.src);
      }
      // Drain the callback queue and inform the dependent loaders so they can
      // fail locally without blocking the rest of DentaTrak.
      var cbs = chartJsCallbacks;
      chartJsCallbacks = [];
      cbs.forEach(function(cb) { cb(new Error('Chart.js failed to load from ' + chartScript.src)); });
    };
    document.body.appendChild(chartScript);
  }

  // Lazy load Chart.js and analytics-pro.js
  // Helpers for visible loading and failure states inside Insights tabs.
  function setInsightsLoading(view, isLoading) {
    var loadingId = view === 'labs' ? 'liLoading' : 'apLoading';
    if (typeof document !== 'undefined' && document.getElementById) {
      var el = document.getElementById(loadingId);
      if (el) { el.style.display = isLoading ? 'flex' : 'none'; }
    }
  }

  function setInsightsError(view, message) {
    var errorId = view === 'labs' ? 'liError' : 'apError';
    var textId = view === 'labs' ? 'liErrorText' : 'apErrorText';
    if (typeof document !== 'undefined' && document.getElementById) {
      var el = document.getElementById(errorId);
      var text = document.getElementById(textId);
      if (el) { el.style.display = message ? 'flex' : 'none'; }
      if (text && message) { text.textContent = message; }
    }
  }

  var analyticsScriptsLoaded = false;
  var analyticsScriptsLoading = false;
  var analyticsScriptLoadFailed = false;
  function refreshAnalyticsProData() {
    if (typeof window.loadAnalyticsProData === 'function') {
      setTimeout(function() { window.loadAnalyticsProData(); }, 100);
    }
  }
  function loadAnalyticsScripts() {
    if (analyticsScriptsLoaded) {
      // Scripts already loaded, just refresh data
      refreshAnalyticsProData();
      return;
    }
    if (analyticsScriptLoadFailed) {
      setInsightsLoading('practice', false);
      setInsightsError('practice', t('insights.error.analytics_js_failed') || 'Unable to load analytics components. Please refresh.');
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[Practice Insights] analytics-pro.js previously failed; skipping.');
      }
      return;
    }
    if (analyticsScriptsLoading) {
      // Already loading; the in-flight request will call loadAnalyticsProData
      // once analytics-pro.js is ready.
      return;
    }

    analyticsScriptsLoading = true;
    ensureChartJsLoaded(function(err) {
      if (err) {
        analyticsScriptsLoading = false;
        analyticsScriptLoadFailed = true;
        setInsightsLoading('practice', false);
        setInsightsError('practice', t('insights.error.chart_js_failed') || 'Unable to load chart library. Please refresh.');
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[Practice Insights] Chart.js not available:', err && err.message ? err.message : err);
        }
        return;
      }
      if (typeof Chart === 'undefined') {
        analyticsScriptsLoading = false;
        analyticsScriptLoadFailed = true;
        setInsightsLoading('practice', false);
        setInsightsError('practice', t('insights.error.chart_js_failed') || 'Chart library is not available. Please refresh.');
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[Practice Insights] Chart.js not defined after load.');
        }
        return;
      }
      var analyticsScript = document.createElement('script');
      analyticsScript.src = 'js/analytics-pro.js?v=' + Date.now();
      analyticsScript.onload = function() {
        analyticsScriptsLoaded = true;
        analyticsScriptsLoading = false;
        refreshAnalyticsProData();
      };
      analyticsScript.onerror = function() {
        analyticsScriptsLoading = false;
        analyticsScriptLoadFailed = true;
        setInsightsLoading('practice', false);
        setInsightsError('practice', t('insights.error.analytics_js_failed') || 'Unable to load analytics components. Please refresh.');
        if (typeof console !== 'undefined' && console.error) {
          console.error('[Practice Insights] Failed to load analytics-pro.js');
        }
      };
      document.body.appendChild(analyticsScript);
    });
  }

  // Lazy load Chart.js and lab-insights.js
  var labInsightsScriptsLoaded = false;
  var labInsightsScriptsLoading = false;
  var labInsightsScriptLoadFailed = false;
  function refreshLabInsightsData() {
    if (typeof window.loadLabInsightsData === 'function') {
      setTimeout(function() { window.loadLabInsightsData(); }, 100);
    }
  }
  function loadLabInsightsScripts() {
    if (labInsightsScriptsLoaded) {
      // Scripts already loaded, just refresh data
      refreshLabInsightsData();
      return;
    }
    if (labInsightsScriptLoadFailed) {
      setInsightsLoading('labs', false);
      setInsightsError('labs', t('insights.error.labs_js_failed') || 'Unable to load lab insights. Please refresh.');
      if (typeof console !== 'undefined' && console.warn) {
        console.warn('[Lab Insights] lab-insights.js previously failed; skipping.');
      }
      return;
    }
    if (labInsightsScriptsLoading) {
      // Already loading; the in-flight request will call loadLabInsightsData
      // once lab-insights.js is ready.
      return;
    }

    labInsightsScriptsLoading = true;
    ensureChartJsLoaded(function(err) {
      if (err) {
        labInsightsScriptsLoading = false;
        labInsightsScriptLoadFailed = true;
        setInsightsLoading('labs', false);
        setInsightsError('labs', t('insights.error.chart_js_failed') || 'Unable to load chart library. Please refresh.');
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[Lab Insights] Chart.js not available:', err && err.message ? err.message : err);
        }
        return;
      }
      if (typeof Chart === 'undefined') {
        labInsightsScriptsLoading = false;
        labInsightsScriptLoadFailed = true;
        setInsightsLoading('labs', false);
        setInsightsError('labs', t('insights.error.chart_js_failed') || 'Chart library is not available. Please refresh.');
        if (typeof console !== 'undefined' && console.warn) {
          console.warn('[Lab Insights] Chart.js not defined after load.');
        }
        return;
      }
      var labInsightsScript = document.createElement('script');
      labInsightsScript.src = 'js/lab-insights.js?v=' + Date.now();
      labInsightsScript.onload = function() {
        labInsightsScriptsLoaded = true;
        labInsightsScriptsLoading = false;
        refreshLabInsightsData();
      };
      labInsightsScript.onerror = function() {
        labInsightsScriptsLoading = false;
        labInsightsScriptLoadFailed = true;
        setInsightsLoading('labs', false);
        setInsightsError('labs', t('insights.error.labs_js_failed') || 'Unable to load lab insights. Please refresh.');
        if (typeof console !== 'undefined' && console.error) {
          console.error('[Lab Insights] Failed to load lab-insights.js');
        }
      };
      document.body.appendChild(labInsightsScript);
    });
  }

  // Deep-link and initial-hash activation for Practice / Lab Insights.
  // This must run after the lazy-load helpers above are fully initialized so
  // that calling activateInsightsSubview('practice') can safely queue a
  // Chart.js callback in chartJsCallbacks.
  applyInitialInsightsHash();
  window.addEventListener('hashchange', applyInitialInsightsHash);

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
      loadArchivedDentists();
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
      loadArchivedDentists();
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

  // Search and filter functionality. archivedState is the single source of
  // truth: DOM controls mirror it, queries are built from it, and Clear
  // Filters resets it rather than clearing controls independently.
  const archivedSearch = document.getElementById('archivedSearch');
  const archivedPageSizeSelect = document.getElementById('archivedPageSize');
  const archivedDateRange = document.getElementById('archivedDateRange');
  const archivedCreatedRange = document.getElementById('archivedCreatedRange');
  const archivedCaseType = document.getElementById('archivedCaseType');
  const archivedStatus = document.getElementById('archivedStatus');
  const archivedDentist = document.getElementById('archivedDentist');
  const archivedClearFilters = document.getElementById('archivedClearFilters');
  const archivedCustomDates = document.getElementById('archivedCustomDates');
  const archivedCreatedCustomDates = document.getElementById('archivedCreatedCustomDates');
  const archivedFrom = document.getElementById('archivedFrom');
  const archivedTo = document.getElementById('archivedTo');
  const archivedCreatedFrom = document.getElementById('archivedCreatedFrom');
  const archivedCreatedTo = document.getElementById('archivedCreatedTo');
  const archivedDateError = document.getElementById('archivedDateError');
  const archivedActiveFilters = document.getElementById('archivedActiveFilters');

  const archivedFilterDefaults = {
    search: '',
    caseType: '',
    status: '',
    dentist: '',
    archivedDays: '',
    archivedFrom: '',
    archivedTo: '',
    createdDays: '',
    createdFrom: '',
    createdTo: '',
    sort: 'archived',
    dir: 'desc'
  };
  let archivedState = Object.assign({}, archivedFilterDefaults);
  let archivedSearchDebounce = null;

  // Criteria keys that count as an "active filter" (sort/page excluded).
  const archivedFilterKeys = ['search', 'caseType', 'status', 'dentist', 'archivedDays', 'archivedFrom', 'archivedTo', 'createdDays', 'createdFrom', 'createdTo'];

  function archivedFiltersAreActive() {
    return archivedFilterKeys.some(function(key) {
      return archivedState[key] !== archivedFilterDefaults[key];
    });
  }

  function updateArchivedClearFiltersButton() {
    if (archivedClearFilters) {
      archivedClearFilters.disabled = !archivedFiltersAreActive();
    }
  }

  // Push archivedState into the DOM controls (used by Clear Filters and
  // chip removal so UI can never diverge from state).
  function syncArchivedControls() {
    if (archivedSearch) archivedSearch.value = archivedState.search;
    if (archivedSearchClearBtn) archivedSearchClearBtn.style.display = archivedState.search ? 'block' : 'none';
    if (archivedCaseType) archivedCaseType.value = archivedState.caseType;
    if (archivedStatus) archivedStatus.value = archivedState.status;
    if (archivedDentist) archivedDentist.value = archivedState.dentist;
    if (archivedDateRange) archivedDateRange.value = archivedState.archivedDays || (archivedState.archivedFrom || archivedState.archivedTo ? 'custom' : '');
    if (archivedCreatedRange) archivedCreatedRange.value = archivedState.createdDays || (archivedState.createdFrom || archivedState.createdTo ? 'custom' : '');
    if (archivedFrom) archivedFrom.value = archivedState.archivedFrom;
    if (archivedTo) archivedTo.value = archivedState.archivedTo;
    if (archivedCreatedFrom) archivedCreatedFrom.value = archivedState.createdFrom;
    if (archivedCreatedTo) archivedCreatedTo.value = archivedState.createdTo;
    if (archivedCustomDates) archivedCustomDates.hidden = !(archivedDateRange && archivedDateRange.value === 'custom');
    if (archivedCreatedCustomDates) archivedCreatedCustomDates.hidden = !(archivedCreatedRange && archivedCreatedRange.value === 'custom');
    updateArchivedSortHeaders();
  }

  function hideArchivedDateError() {
    if (archivedDateError) {
      archivedDateError.hidden = true;
      archivedDateError.textContent = '';
    }
  }

  // Validate a custom range before it reaches the server. Returns false
  // (and shows the localized error) when From is after To.
  function archivedCustomRangeValid() {
    const ranges = [
      { from: archivedState.archivedFrom, to: archivedState.archivedTo },
      { from: archivedState.createdFrom, to: archivedState.createdTo }
    ];
    for (const r of ranges) {
      if (r.from && r.to && r.from > r.to) {
        if (archivedDateError) {
          archivedDateError.textContent = t('archive.filters.invalid_date_range');
          archivedDateError.hidden = false;
        }
        return false;
      }
    }
    hideArchivedDateError();
    return true;
  }

  function archivedQueryParams() {
    return new URLSearchParams({
      page: archivedCurrentPage,
      pageSize: archivedPageSize,
      search: archivedState.search,
      caseType: archivedState.caseType,
      status: archivedState.status,
      dentist: archivedState.dentist,
      archivedDays: archivedState.archivedDays,
      archivedFrom: archivedState.archivedFrom,
      archivedTo: archivedState.archivedTo,
      createdDays: archivedState.createdDays,
      createdFrom: archivedState.createdFrom,
      createdTo: archivedState.createdTo,
      sort: archivedState.sort,
      dir: archivedState.dir
    });
  }

  // Render one removable chip per active criterion.
  function renderArchivedChips() {
    if (!archivedActiveFilters) return;
    const chips = [];
    const chip = function(key, label, value) {
      chips.push({ key: key, label: label, value: value });
    };
    if (archivedState.search) chip('search', t('archive.active_filters.search'), archivedState.search);
    if (archivedState.caseType) chip('caseType', t('archive.fields.case_type'), getCaseTypeDisplayLabel(archivedState.caseType) || archivedState.caseType);
    if (archivedState.status) chip('status', t('archive.fields.status'), getStageLabel(archivedState.status) || archivedState.status);
    if (archivedState.dentist) chip('dentist', t('archive.fields.dentist'), archivedState.dentist);
    if (archivedState.archivedDays) {
      chip('archivedDays', t('archive.fields.archived'), t('archive.filters.last_n_days', {count: parseInt(archivedState.archivedDays, 10)}));
    } else if (archivedState.archivedFrom || archivedState.archivedTo) {
      chip('archivedRange', t('archive.fields.archived'), (archivedState.archivedFrom || '…') + ' – ' + (archivedState.archivedTo || '…'));
    }
    if (archivedState.createdDays) {
      chip('createdDays', t('archive.fields.created'), t('archive.filters.last_n_days', {count: parseInt(archivedState.createdDays, 10)}));
    } else if (archivedState.createdFrom || archivedState.createdTo) {
      chip('createdRange', t('archive.fields.created'), (archivedState.createdFrom || '…') + ' – ' + (archivedState.createdTo || '…'));
    }

    archivedActiveFilters.innerHTML = chips.map(function(c) {
      return '<span class="archive-filter-chip">' +
        '<span class="archive-filter-chip-label">' + escapeHtml(c.label) + ':</span> ' +
        escapeHtml(c.value) +
        ' <button type="button" class="archive-filter-chip-remove" data-chip="' + c.key + '" aria-label="' + escapeHtml(t('archive.filters.remove_filter', {name: c.label})) + '">&times;</button>' +
        '</span>';
    }).join('');
    archivedActiveFilters.hidden = chips.length === 0;

    archivedActiveFilters.querySelectorAll('.archive-filter-chip-remove').forEach(function(btn) {
      btn.addEventListener('click', function() {
        removeArchivedChip(btn.dataset.chip);
      });
    });
  }

  function removeArchivedChip(key) {
    const clear = {
      search: function() { archivedState.search = ''; },
      caseType: function() { archivedState.caseType = ''; },
      status: function() { archivedState.status = ''; },
      dentist: function() { archivedState.dentist = ''; },
      archivedDays: function() { archivedState.archivedDays = ''; },
      archivedRange: function() { archivedState.archivedFrom = ''; archivedState.archivedTo = ''; },
      createdDays: function() { archivedState.createdDays = ''; },
      createdRange: function() { archivedState.createdFrom = ''; archivedState.createdTo = ''; }
    };
    if (clear[key]) clear[key]();
    archivedCurrentPage = 1;
    syncArchivedControls();
    loadArchivedCases();
  }

  // Sortable column headers: click toggles direction on the active column
  // or switches columns with a sensible default direction.
  function setArchivedSort(column) {
    if (archivedState.sort === column) {
      archivedState.dir = archivedState.dir === 'asc' ? 'desc' : 'asc';
    } else {
      archivedState.sort = column;
      archivedState.dir = (column === 'archived' || column === 'created') ? 'desc' : 'asc';
    }
    archivedCurrentPage = 1;
    updateArchivedSortHeaders();
    loadArchivedCases();
  }

  function updateArchivedSortHeaders() {
    document.querySelectorAll('.archived-cases-table .archived-sort').forEach(function(btn) {
      const th = btn.closest('th');
      const active = btn.dataset.sort === archivedState.sort;
      const dirLabel = archivedState.dir === 'asc' ? t('archive.sort.ascending') : t('archive.sort.descending');
      if (th) {
        if (active) {
          th.setAttribute('aria-sort', archivedState.dir === 'asc' ? 'ascending' : 'descending');
        } else {
          th.removeAttribute('aria-sort');
        }
      }
      btn.classList.toggle('sorted-asc', active && archivedState.dir === 'asc');
      btn.classList.toggle('sorted-desc', active && archivedState.dir === 'desc');
      btn.setAttribute('aria-label', active
        ? t('archive.sort.sorted_by', {column: btn.textContent.trim(), direction: dirLabel})
        : t('archive.sort.sort_by', {column: btn.textContent.trim()}));
    });
  }

  function clearArchivedFilters() {
    // One reset source of truth: state resets, controls re-sync, page 1.
    // Page size is intentionally preserved.
    archivedState = Object.assign({}, archivedFilterDefaults);
    archivedCurrentPage = 1;
    hideArchivedDateError();
    syncArchivedControls();
    loadArchivedCases();
  }

  var archivedSearchClearBtn = null;
  if (archivedSearch) {
    archivedSearch.addEventListener('input', () => {
      archivedState.search = archivedSearch.value;
      if (archivedSearchClearBtn) {
        archivedSearchClearBtn.style.display = archivedSearch.value.length > 0 ? 'block' : 'none';
      }
      clearTimeout(archivedSearchDebounce);
      archivedSearchDebounce = setTimeout(function() {
        archivedCurrentPage = 1;
        loadArchivedCases();
      }, 300);
    });

    // Add clear button for archived search
    const archivedSearchContainer = archivedSearch.parentElement;
    if (archivedSearchContainer) {
      archivedSearchClearBtn = document.createElement('button');
      archivedSearchClearBtn.type = 'button';
      archivedSearchClearBtn.className = 'archived-search-clear-btn';
      archivedSearchClearBtn.innerHTML = '&times;';
      archivedSearchClearBtn.title = t('archive.search.clear');
      archivedSearchClearBtn.setAttribute('aria-label', t('archive.search.clear'))

      archivedSearchClearBtn.addEventListener('click', function() {
        archivedState.search = '';
        archivedSearch.value = '';
        archivedSearchClearBtn.style.display = 'none';
        archivedCurrentPage = 1;
        loadArchivedCases();
        archivedSearch.focus();
      });

      archivedSearchContainer.appendChild(archivedSearchClearBtn);
    }
  }

  if (archivedPageSizeSelect) {
    archivedPageSizeSelect.addEventListener('change', () => {
      const newPageSize = parseInt(archivedPageSizeSelect.value);
      if (newPageSize > 0) {
        archivedPageSize = newPageSize;
        archivedCurrentPage = 1;
        loadArchivedCases();
      }
    });
  }

  // Preset/custom date selects. 'custom' reveals the From/To inputs; any
  // other value clears the explicit bounds.
  function bindArchivedDateSelect(selectEl, customEl, daysKey, fromKey, toKey) {
    if (!selectEl) return;
    selectEl.addEventListener('change', function() {
      if (selectEl.value === 'custom') {
        archivedState[daysKey] = '';
        if (customEl) customEl.hidden = false;
      } else {
        archivedState[daysKey] = selectEl.value;
        archivedState[fromKey] = '';
        archivedState[toKey] = '';
        if (customEl) customEl.hidden = true;
      }
      hideArchivedDateError();
      archivedCurrentPage = 1;
      loadArchivedCases();
    });
  }
  bindArchivedDateSelect(archivedDateRange, archivedCustomDates, 'archivedDays', 'archivedFrom', 'archivedTo');
  bindArchivedDateSelect(archivedCreatedRange, archivedCreatedCustomDates, 'createdDays', 'createdFrom', 'createdTo');

  function bindArchivedDateInput(inputEl, key) {
    if (!inputEl) return;
    inputEl.addEventListener('change', function() {
      archivedState[key] = inputEl.value;
      archivedCurrentPage = 1;
      loadArchivedCases();
    });
  }
  bindArchivedDateInput(archivedFrom, 'archivedFrom');
  bindArchivedDateInput(archivedTo, 'archivedTo');
  bindArchivedDateInput(archivedCreatedFrom, 'createdFrom');
  bindArchivedDateInput(archivedCreatedTo, 'createdTo');

  if (archivedCaseType) {
    archivedCaseType.addEventListener('change', () => {
      archivedState.caseType = archivedCaseType.value;
      archivedCurrentPage = 1;
      loadArchivedCases();
    });
  }

  if (archivedStatus) {
    archivedStatus.addEventListener('change', () => {
      archivedState.status = archivedStatus.value;
      archivedCurrentPage = 1;
      loadArchivedCases();
    });
  }

  if (archivedDentist) {
    archivedDentist.addEventListener('change', () => {
      archivedState.dentist = archivedDentist.value;
      archivedCurrentPage = 1;
      loadArchivedCases();
    });
  }

  if (archivedClearFilters) {
    archivedClearFilters.addEventListener('click', () => {
      clearArchivedFilters();
    });
  }

  document.querySelectorAll('.archived-cases-table .archived-sort').forEach(function(btn) {
    btn.addEventListener('click', function() {
      setArchivedSort(btn.dataset.sort);
    });
  });

  // Populate the dentist filter from the practice's archived cases.
  function loadArchivedDentists() {
    if (!archivedDentist) return;
    fetch('api/get-archived-cases.php?meta=1', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(data => {
        if (!data.success) return;
        const current = archivedDentist.value;
        archivedDentist.innerHTML = '<option value="">' + escapeHtml(t('archive.filters.all_dentists')) + '</option>' +
          data.dentists.map(function(name) {
            return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
          }).join('');
        archivedDentist.value = current;
      })
      .catch(() => {});
  }

  // Pagination
  const archivedPrevPage = document.getElementById('archivedPrevPage');
  const archivedNextPage = document.getElementById('archivedNextPage');

  if (archivedPrevPage) {
    archivedPrevPage.addEventListener('click', () => {
      if (archivedCurrentPage > 1) {
        archivedCurrentPage--;
        loadArchivedCases();
      }
    });
  }

  if (archivedNextPage) {
    archivedNextPage.addEventListener('click', () => {
      const totalPages = Math.ceil(archivedTotalCount / archivedPageSize);
      if (archivedCurrentPage < totalPages) {
        archivedCurrentPage++;
        loadArchivedCases();
      }
    });
  }

  function loadArchivedCases() {
    const tbody = document.getElementById('archivedCasesTableBody');
    const countSpan = document.getElementById('archivedCount');

    if (!archivedCustomRangeValid()) {
      return;
    }

    // Show loading state
    tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + t('archive.loading') + '</td></tr>';
    countSpan.textContent = t('common.loading');

    fetch(`api/get-archived-cases.php?${archivedQueryParams()}`, {
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        archivedTotalCount = data.totalCount;
        if (typeof data.totalArchived !== 'undefined') {
          updateArchivedCasesBadge(data.totalArchived);
        }

        // If filters shrank the result set below the current page (e.g.
        // after a restore), step back until rows appear.
        if (data.cases.length === 0 && data.totalCount > 0 && archivedCurrentPage > 1) {
          archivedCurrentPage = Math.ceil(data.totalCount / archivedPageSize) || 1;
          loadArchivedCases();
          return;
        }

        displayArchivedCases(data.cases);
        updateArchivedPagination(archivedTotalCount);
        renderArchivedChips();
        updateArchivedClearFiltersButton();

        const start = data.totalCount === 0 ? 0 : (archivedCurrentPage - 1) * archivedPageSize + 1;
        const end = Math.min(archivedCurrentPage * archivedPageSize, data.totalCount);
        countSpan.textContent = t('archive.pagination.results_range', {start: start, end: end, total: data.totalCount});
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
      if (archivedFiltersAreActive()) {
        tbody.innerHTML = '<tr><td colspan="7" class="loading-row archived-empty-filtered">' +
          escapeHtml(t('archive.empty.no_matches')) + ' ' +
          '<button type="button" class="btn-clear-filters archived-empty-clear" id="archivedEmptyClear">' +
          escapeHtml(t('archive.filters.clear_filters')) + '</button></td></tr>';
        const emptyClear = document.getElementById('archivedEmptyClear');
        if (emptyClear) {
          emptyClear.addEventListener('click', clearArchivedFilters);
        }
      } else {
        tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + escapeHtml(t('archive.empty.no_cases')) + '</td></tr>';
      }
      return;
    }

    tbody.innerHTML = cases.map(case_ => `
      <tr>
        <td>${escapeHtml((case_.patientFirstName || case_.patient_first_name || '') + ' ' + (case_.patientLastName || case_.patient_last_name || '')).trim()}</td>
        <td>${escapeHtml(case_.dentistName || case_.dentist_name || '')}</td>
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

  // PHI Access Audit report (practice admins only). The markup only exists
  // for admins (main.php gates it server-side) and the API re-checks
  // isPracticeAdmin() - client checks here are convenience, not security.
  const phiAuditModal = document.getElementById('phiAuditModal');
  const phiAuditOpenBtn = document.getElementById('phiAuditOpenBtn');

  if (phiAuditModal && phiAuditOpenBtn) {
    const phiAuditClose = document.getElementById('phiAuditClose');
    const phiAuditFooterClose = document.getElementById('phiAuditFooterClose');
    const phiAuditDateRange = document.getElementById('phiAuditDateRange');
    const phiAuditUser = document.getElementById('phiAuditUser');
    const phiAuditAction = document.getElementById('phiAuditAction');
    const phiAuditResource = document.getElementById('phiAuditResource');
    const phiAuditTracking = document.getElementById('phiAuditTracking');
    const phiAuditClearFilters = document.getElementById('phiAuditClearFilters');
    const phiAuditExportCsv = document.getElementById('phiAuditExportCsv');
    const phiAuditCustomDates = document.getElementById('phiAuditCustomDates');
    const phiAuditFrom = document.getElementById('phiAuditFrom');
    const phiAuditTo = document.getElementById('phiAuditTo');
    const phiAuditDateError = document.getElementById('phiAuditDateError');
    const phiAuditActiveFilters = document.getElementById('phiAuditActiveFilters');
    const phiAuditPageSizeSelect = document.getElementById('phiAuditPageSize');
    const phiAuditPrevPage = document.getElementById('phiAuditPrevPage');
    const phiAuditNextPage = document.getElementById('phiAuditNextPage');

    // Single source of truth - DOM controls mirror this state.
    const phiAuditDefaults = {
      preset: '30',
      from: '',
      to: '',
      userId: '',
      action: '',
      resourceType: '',
      trackingNumber: '',
      sort: 'accessed_at',
      dir: 'desc'
    };
    let phiAuditState = Object.assign({}, phiAuditDefaults);
    let phiAuditCurrentPage = 1;
    let phiAuditPageSize = 25;
    let phiAuditTotalCount = 0;
    let phiAuditMeta = null;
    let phiAuditTrackingDebounce = null;

    const phiAuditFilterKeys = ['preset', 'from', 'to', 'userId', 'action', 'resourceType', 'trackingNumber'];

    function phiAuditActionLabel(action) {
      // t() returns '' for missing keys - fall back to the raw DB value.
      return t('phiAudit.actions.' + action) || action;
    }

    function phiAuditResourceLabel(resourceType) {
      if (!resourceType) return '';
      return t('phiAudit.resources.' + resourceType) || resourceType;
    }

    function phiAuditFormatTimestamp(accessedAt) {
      // accessed_at is server-local "Y-m-d H:i:s"; normalizing to "T" gives a
      // reliable local-time parse for Intl formatting.
      if (!accessedAt || typeof I18n === 'undefined' || !I18n.formatDate) {
        return accessedAt || '';
      }
      const d = new Date(String(accessedAt).replace(' ', 'T'));
      if (isNaN(d.getTime())) return accessedAt;
      return I18n.formatDate(d, { style: 'short', timeStyle: 'short' });
    }

    function phiAuditFiltersActive() {
      return phiAuditFilterKeys.some(function(key) {
        return phiAuditState[key] !== phiAuditDefaults[key];
      });
    }

    function updatePhiAuditClearButton() {
      if (phiAuditClearFilters) {
        phiAuditClearFilters.disabled = !phiAuditFiltersActive();
      }
    }

    function syncPhiAuditControls() {
      if (phiAuditDateRange) phiAuditDateRange.value = phiAuditState.preset;
      if (phiAuditUser) phiAuditUser.value = phiAuditState.userId;
      if (phiAuditAction) phiAuditAction.value = phiAuditState.action;
      if (phiAuditResource) phiAuditResource.value = phiAuditState.resourceType;
      if (phiAuditTracking) phiAuditTracking.value = phiAuditState.trackingNumber;
      if (phiAuditFrom) phiAuditFrom.value = phiAuditState.from;
      if (phiAuditTo) phiAuditTo.value = phiAuditState.to;
      if (phiAuditCustomDates) phiAuditCustomDates.hidden = phiAuditState.preset !== 'custom';
      updatePhiAuditSortHeaders();
      updatePhiAuditClearButton();
    }

    function hidePhiAuditDateError() {
      if (phiAuditDateError) {
        phiAuditDateError.hidden = true;
        phiAuditDateError.textContent = '';
      }
    }

    function phiAuditRangeValid() {
      if (phiAuditState.preset === 'custom' && phiAuditState.from && phiAuditState.to && phiAuditState.from > phiAuditState.to) {
        if (phiAuditDateError) {
          phiAuditDateError.textContent = t('archive.filters.invalid_date_range');
          phiAuditDateError.hidden = false;
        }
        return false;
      }
      hidePhiAuditDateError();
      return true;
    }

    function phiAuditQueryParams() {
      return new URLSearchParams({
        page: phiAuditCurrentPage,
        page_size: phiAuditPageSize,
        preset: phiAuditState.preset,
        from: phiAuditState.from,
        to: phiAuditState.to,
        user_id: phiAuditState.userId,
        action: phiAuditState.action,
        resource_type: phiAuditState.resourceType,
        tracking_number: phiAuditState.trackingNumber,
        sort: phiAuditState.sort,
        dir: phiAuditState.dir
      });
    }

    function phiAuditDateChipLabel() {
      if (phiAuditState.preset === 'custom') {
        return (phiAuditState.from || '…') + ' – ' + (phiAuditState.to || '…');
      }
      if (phiAuditState.preset === 'all') {
        return t('phiAudit.filters.all_dates');
      }
      return t('archive.filters.last_n_days', {count: parseInt(phiAuditState.preset, 10)});
    }

    function phiAuditUserLabel(userId) {
      if (!phiAuditMeta || !phiAuditMeta.users) return userId;
      const found = phiAuditMeta.users.find(function(u) { return String(u.id) === String(userId); });
      if (!found) return userId;
      return (found.full_name && found.full_name.trim()) ? found.full_name.trim() : found.email;
    }

    function renderPhiAuditChips() {
      if (!phiAuditActiveFilters) return;
      const chips = [];
      const chip = function(key, label, value) {
        chips.push({ key: key, label: label, value: value });
      };
      // The date range is always set (defaults to Last 30 Days), so it only
      // earns a chip when the admin changed it away from the default.
      if (phiAuditState.preset !== phiAuditDefaults.preset) {
        chip('dateRange', t('phiAudit.filters.date_range'), phiAuditDateChipLabel());
      }
      if (phiAuditState.userId) chip('userId', t('phiAudit.filters.user'), phiAuditUserLabel(phiAuditState.userId));
      if (phiAuditState.action) chip('action', t('phiAudit.filters.action'), phiAuditActionLabel(phiAuditState.action));
      if (phiAuditState.resourceType) chip('resourceType', t('phiAudit.filters.resource'), phiAuditResourceLabel(phiAuditState.resourceType));
      if (phiAuditState.trackingNumber) chip('trackingNumber', t('phiAudit.filters.tracking_number'), phiAuditState.trackingNumber);

      phiAuditActiveFilters.innerHTML = chips.map(function(c) {
        return '<span class="archive-filter-chip">' +
          '<span class="archive-filter-chip-label">' + escapeHtml(c.label) + ':</span> ' +
          escapeHtml(c.value) +
          ' <button type="button" class="archive-filter-chip-remove" data-chip="' + c.key + '" aria-label="' + escapeHtml(t('archive.filters.remove_filter', {name: c.label})) + '">&times;</button>' +
          '</span>';
      }).join('');
      phiAuditActiveFilters.hidden = chips.length === 0;

      phiAuditActiveFilters.querySelectorAll('.archive-filter-chip-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
          removePhiAuditChip(btn.dataset.chip);
        });
      });
    }

    function removePhiAuditChip(key) {
      const clear = {
        dateRange: function() { phiAuditState.preset = '30'; phiAuditState.from = ''; phiAuditState.to = ''; },
        userId: function() { phiAuditState.userId = ''; },
        action: function() { phiAuditState.action = ''; },
        resourceType: function() { phiAuditState.resourceType = ''; },
        trackingNumber: function() { phiAuditState.trackingNumber = ''; }
      };
      if (clear[key]) clear[key]();
      phiAuditCurrentPage = 1;
      syncPhiAuditControls();
      loadPhiAuditLog();
    }

    function setPhiAuditSort(column) {
      if (phiAuditState.sort === column) {
        phiAuditState.dir = phiAuditState.dir === 'asc' ? 'desc' : 'asc';
      } else {
        phiAuditState.sort = column;
        phiAuditState.dir = column === 'accessed_at' ? 'desc' : 'asc';
      }
      phiAuditCurrentPage = 1;
      updatePhiAuditSortHeaders();
      loadPhiAuditLog();
    }

    function updatePhiAuditSortHeaders() {
      document.querySelectorAll('.phi-audit-table .archived-sort').forEach(function(btn) {
        const th = btn.closest('th');
        const active = btn.dataset.sort === phiAuditState.sort;
        const dirLabel = phiAuditState.dir === 'asc' ? t('archive.sort.ascending') : t('archive.sort.descending');
        if (th) {
          if (active) {
            th.setAttribute('aria-sort', phiAuditState.dir === 'asc' ? 'ascending' : 'descending');
          } else {
            th.removeAttribute('aria-sort');
          }
        }
        btn.classList.toggle('sorted-asc', active && phiAuditState.dir === 'asc');
        btn.classList.toggle('sorted-desc', active && phiAuditState.dir === 'desc');
        btn.setAttribute('aria-label', active
          ? t('archive.sort.sorted_by', {column: btn.textContent.trim(), direction: dirLabel})
          : t('archive.sort.sort_by', {column: btn.textContent.trim()}));
      });
    }

    function clearPhiAuditFilters() {
      phiAuditState = Object.assign({}, phiAuditDefaults);
      phiAuditCurrentPage = 1;
      hidePhiAuditDateError();
      syncPhiAuditControls();
      loadPhiAuditLog();
    }

    function phiAuditResourceCell(entry) {
      // Show the basename of a storage path, never the full object layout.
      // resource_id is safe internal metadata (path or export ID), not PHI.
      if (!entry.resource_id) return '—';
      const base = String(entry.resource_id).split('/').pop();
      return escapeHtml(base);
    }

    function phiAuditCaseCell(entry) {
      // The CASE column shows the case's tracking number resolved server-side
      // through the practice-scoped cases_cache join. Events with no linked
      // case show an em dash; a linked case that no longer resolves (deleted,
      // or no tracking number) gets the localized neutral fallback - never
      // the raw internal case_id.
      if (!entry.has_case) return '—';
      return entry.case_tracking_number || t('phiAudit.unavailable');
    }

    function phiAuditDetailsCell(entry) {
      let meta = {};
      try { meta = entry.meta_json ? JSON.parse(entry.meta_json) : {}; } catch (e) { meta = {}; }
      const parts = [];
      if (meta.file_count !== undefined && meta.file_count !== null) {
        parts.push(t('phiAudit.details.file_count', {count: meta.file_count}));
      }
      if (meta.export_id !== undefined && meta.export_id !== null) {
        parts.push(t('phiAudit.details.export_id', {id: meta.export_id}));
      }
      return parts.length ? escapeHtml(parts.join(' · ')) : '—';
    }

    function loadPhiAuditMeta() {
      fetch('api/phi-access-log.php?meta=1', { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
          if (!data.success) return;
          phiAuditMeta = data;
          if (phiAuditUser) {
            const current = phiAuditUser.value;
            phiAuditUser.innerHTML = '<option value="">' + escapeHtml(t('phiAudit.filters.all_users')) + '</option>' +
              data.users.map(function(u) {
                const label = (u.full_name && u.full_name.trim()) ? u.full_name.trim() : u.email;
                return '<option value="' + escapeHtml(String(u.id)) + '">' + escapeHtml(label) + '</option>';
              }).join('');
            phiAuditUser.value = current;
          }
          if (phiAuditAction) {
            const current = phiAuditAction.value;
            phiAuditAction.innerHTML = '<option value="">' + escapeHtml(t('phiAudit.filters.all_actions')) + '</option>' +
              data.actions.map(function(a) {
                return '<option value="' + escapeHtml(a) + '">' + escapeHtml(phiAuditActionLabel(a)) + '</option>';
              }).join('');
            phiAuditAction.value = current;
          }
          if (phiAuditResource) {
            const current = phiAuditResource.value;
            phiAuditResource.innerHTML = '<option value="">' + escapeHtml(t('phiAudit.filters.all_resources')) + '</option>' +
              data.resourceTypes.map(function(r) {
                return '<option value="' + escapeHtml(r) + '">' + escapeHtml(phiAuditResourceLabel(r)) + '</option>';
              }).join('');
            phiAuditResource.value = current;
          }
        })
        .catch(() => {});
    }

    function loadPhiAuditLog() {
      const tbody = document.getElementById('phiAuditTableBody');
      const countSpan = document.getElementById('phiAuditCount');
      if (!tbody) return;

      if (!phiAuditRangeValid()) {
        return;
      }

      tbody.innerHTML = '<tr><td colspan="6" class="loading-row">' + escapeHtml(t('archive.loading')) + '</td></tr>';
      countSpan.textContent = t('common.loading');

      fetch('api/phi-access-log.php?' + phiAuditQueryParams(), { credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
          if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="6" class="loading-row">' + escapeHtml(data.message || t('phiAudit.load_failed')) + '</td></tr>';
            countSpan.textContent = '';
            return;
          }

          phiAuditCurrentPage = data.page;
          phiAuditPageSize = data.pageSize;
          phiAuditTotalCount = data.total;

          countSpan.textContent = t('phiAudit.count', {
            from: data.showingFrom,
            to: data.showingTo,
            total: data.total
          });

          document.getElementById('phiAuditPageInfo').textContent = t('archive.pagination.page_info', {
            current: data.page,
            total: data.totalPages
          });
          if (phiAuditPrevPage) phiAuditPrevPage.disabled = data.page <= 1;
          if (phiAuditNextPage) phiAuditNextPage.disabled = data.page >= data.totalPages;

          if (!data.entries.length) {
            if (phiAuditFiltersActive()) {
              tbody.innerHTML = '<tr><td colspan="6" class="loading-row archived-empty-filtered">' +
                escapeHtml(t('phiAudit.empty_filtered')) +
                ' <button type="button" class="btn-clear-filters archived-empty-clear" id="phiAuditEmptyClear">' +
                escapeHtml(t('archive.filters.clear_filters')) + '</button></td></tr>';
              const emptyClear = document.getElementById('phiAuditEmptyClear');
              if (emptyClear) emptyClear.addEventListener('click', clearPhiAuditFilters);
            } else {
              tbody.innerHTML = '<tr><td colspan="6" class="loading-row archived-empty-filtered">' + escapeHtml(t('phiAudit.empty')) + '</td></tr>';
            }
          } else {
            tbody.innerHTML = data.entries.map(function(entry) {
              return '<tr>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.datetime')) + '" title="' + escapeHtml(entry.accessed_at) + '">' + escapeHtml(phiAuditFormatTimestamp(entry.accessed_at)) + '</td>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.user')) + '">' + escapeHtml(entry.user_name || entry.user_email || '') + '</td>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.action')) + '">' + escapeHtml(phiAuditActionLabel(entry.access_type)) + '</td>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.resource')) + '">' + escapeHtml(phiAuditResourceLabel(entry.resource_type)) +
                  (entry.resource_id ? ' <span class="phi-audit-resource-id">' + phiAuditResourceCell(entry) + '</span>' : '') + '</td>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.case')) + '">' + escapeHtml(phiAuditCaseCell(entry)) + '</td>' +
                '<td data-label="' + escapeHtml(t('phiAudit.fields.details')) + '">' + phiAuditDetailsCell(entry) + '</td>' +
                '</tr>';
            }).join('');
          }

          renderPhiAuditChips();
          updatePhiAuditClearButton();
        })
        .catch(() => {
          tbody.innerHTML = '<tr><td colspan="6" class="loading-row">' + escapeHtml(t('phiAudit.load_failed')) + '</td></tr>';
          countSpan.textContent = '';
        });
    }

    function exportPhiAuditCsv() {
      // POST + CSRF because the export writes an audit row of its own; a
      // hidden same-origin form hands the CSV response to the browser.
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'api/phi-access-log.php?action=export';
      form.style.display = 'none';
      const add = function(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      };
      add('csrf_token', csrfToken);
      const params = phiAuditQueryParams();
      params.forEach(function(value, key) { add(key, value); });
      document.body.appendChild(form);
      form.submit();
      setTimeout(function() {
        if (form.parentNode) form.parentNode.removeChild(form);
        // The export created an audit event; refresh so it is visible.
        loadPhiAuditLog();
      }, 1500);
    }

    phiAuditOpenBtn.addEventListener('click', function() {
      phiAuditModal.style.display = 'block';
      document.body.style.overflow = 'hidden';
      if (!phiAuditMeta) loadPhiAuditMeta();
      syncPhiAuditControls();
      loadPhiAuditLog();
    });

    const closePhiAudit = function() {
      phiAuditModal.style.display = 'none';
      document.body.style.overflow = '';
    };
    if (phiAuditClose) phiAuditClose.addEventListener('click', closePhiAudit);
    if (phiAuditFooterClose) phiAuditFooterClose.addEventListener('click', closePhiAudit);
    window.addEventListener('click', function(e) {
      if (e.target === phiAuditModal) closePhiAudit();
    });

    if (phiAuditDateRange) {
      phiAuditDateRange.addEventListener('change', function() {
        phiAuditState.preset = phiAuditDateRange.value;
        if (phiAuditState.preset !== 'custom') {
          phiAuditState.from = '';
          phiAuditState.to = '';
        }
        if (phiAuditCustomDates) phiAuditCustomDates.hidden = phiAuditState.preset !== 'custom';
        hidePhiAuditDateError();
        phiAuditCurrentPage = 1;
        loadPhiAuditLog();
      });
    }

    [phiAuditFrom, phiAuditTo].forEach(function(inputEl, idx) {
      if (!inputEl) return;
      const key = idx === 0 ? 'from' : 'to';
      inputEl.addEventListener('change', function() {
        phiAuditState[key] = inputEl.value;
        phiAuditCurrentPage = 1;
        loadPhiAuditLog();
      });
    });

    if (phiAuditUser) {
      phiAuditUser.addEventListener('change', function() {
        phiAuditState.userId = phiAuditUser.value;
        phiAuditCurrentPage = 1;
        loadPhiAuditLog();
      });
    }

    if (phiAuditAction) {
      phiAuditAction.addEventListener('change', function() {
        phiAuditState.action = phiAuditAction.value;
        phiAuditCurrentPage = 1;
        loadPhiAuditLog();
      });
    }

    if (phiAuditResource) {
      phiAuditResource.addEventListener('change', function() {
        phiAuditState.resourceType = phiAuditResource.value;
        phiAuditCurrentPage = 1;
        loadPhiAuditLog();
      });
    }

    if (phiAuditTracking) {
      phiAuditTracking.addEventListener('input', function() {
        phiAuditState.trackingNumber = phiAuditTracking.value;
        clearTimeout(phiAuditTrackingDebounce);
        phiAuditTrackingDebounce = setTimeout(function() {
          phiAuditCurrentPage = 1;
          loadPhiAuditLog();
        }, 300);
      });
    }

    if (phiAuditClearFilters) {
      phiAuditClearFilters.addEventListener('click', clearPhiAuditFilters);
    }

    if (phiAuditExportCsv) {
      phiAuditExportCsv.addEventListener('click', exportPhiAuditCsv);
    }

    if (phiAuditPageSizeSelect) {
      phiAuditPageSizeSelect.addEventListener('change', function() {
        const newSize = parseInt(phiAuditPageSizeSelect.value, 10);
        if (newSize > 0) {
          phiAuditPageSize = newSize;
          phiAuditCurrentPage = 1;
          loadPhiAuditLog();
        }
      });
    }

    if (phiAuditPrevPage) {
      phiAuditPrevPage.addEventListener('click', function() {
        if (phiAuditCurrentPage > 1) {
          phiAuditCurrentPage--;
          loadPhiAuditLog();
        }
      });
    }

    if (phiAuditNextPage) {
      phiAuditNextPage.addEventListener('click', function() {
        const totalPages = Math.max(1, Math.ceil(phiAuditTotalCount / phiAuditPageSize));
        if (phiAuditCurrentPage < totalPages) {
          phiAuditCurrentPage++;
          loadPhiAuditLog();
        }
      });
    }

    document.querySelectorAll('.phi-audit-table .archived-sort').forEach(function(btn) {
      btn.addEventListener('click', function() {
        setPhiAuditSort(btn.dataset.sort);
      });
    });
  }

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
        loadArchivedDentists();
        loadArchivedCases();
      }
    }
  });

  // Kanban Filter Functionality
  function initKanbanFilters() {
    const patientSearch = document.getElementById('patientSearch');
    const filterCaseType = document.getElementById('filterCaseType');
    const filterAssignedTo = document.getElementById('filterAssignedTo');
    const filterReviewStatus = document.getElementById('filterReviewStatus');
    const filterCarrier = document.getElementById('filterCarrier');
    const filterLateCases = document.getElementById('filterLateCases');
    const filterDueSoon = document.getElementById('filterDueSoon');
    const filterApptRisk = document.getElementById('filterApptRisk');
    const filterAtRisk = document.getElementById('filterAtRisk');
    const clearFiltersBtn = document.getElementById('clearFiltersBtn');
    const kanbanFilterActiveDot = document.getElementById('kanbanFilterActiveDot');

    let filterTimeout;

    function applyFilters() {
      const requestId = window.caseFilterSort ? window.caseFilterSort.nextRequest() : 0;
      clearTimeout(filterTimeout);
      filterTimeout = setTimeout(() => {
        const searchTerm = patientSearch ? patientSearch.value.trim() : '';
        const caseType = filterCaseType ? filterCaseType.value : '';
        const assignedTo = filterAssignedTo ? filterAssignedTo.value : '';
        const reviewStatus = filterReviewStatus ? filterReviewStatus.value : '';
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
        if (reviewStatus) params.append('review_status', reviewStatus);
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
          if (window.caseFilterSort && !window.caseFilterSort.currentRequest(requestId)) return;
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
          const renderFilteredCases = () => filteredCases.forEach(caseData => {
            const clonedCase = JSON.parse(JSON.stringify(caseData));
            addCaseToKanban(clonedCase);
          });
          if (window.caseFilterSort) window.caseFilterSort.withBoardRender(renderFilteredCases);
          else renderFilteredCases();

          // Reconcile column counts from the actual rendered cards
          if (typeof window.updateColumnCounts === 'function') {
            window.updateColumnCounts();
          }

          // Notify listeners that the board has been repopulated.
          // Mobile kanban navigation uses this to restore the selected column.
          window.dispatchEvent(new CustomEvent('cardsLoaded'));

          // Apply past due highlighting
          if (typeof updatePastDueHighlighting === 'function') {
            updatePastDueHighlighting();
          }

          // Update filter active indicator
          if (kanbanFilterActiveDot) {
            const hasActiveFilters = !!(searchTerm || caseType || assignedTo || reviewStatus || carrier || lateOnly || dueSoon || atRiskOnly);
            kanbanFilterActiveDot.style.display = hasActiveFilters ? 'block' : 'none';
            if (window.caseFilterSort) window.caseFilterSort.indicator();
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

    if (filterReviewStatus) {
      filterReviewStatus.addEventListener('change', applyFilters);
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
        if (filterReviewStatus) filterReviewStatus.value = '';
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
    // SIGN OUT OF ALL OTHER SESSIONS (self-service)
    // ============================================
    var signOutOtherSessionsBtn = document.getElementById('signOutOtherSessionsBtn');
    var signOutSessionsError = document.getElementById('signOutSessionsError');

    if (signOutOtherSessionsBtn) {
      signOutOtherSessionsBtn.addEventListener('click', function() {
        showConfirmModal(
          t('settings.security.sessions.confirm_title'),
          t('settings.security.sessions.confirm_message'),
          function() {
            signOutOtherSessionsBtn.disabled = true;
            if (signOutSessionsError) signOutSessionsError.style.display = 'none';

            fetch('api/revoke-sessions.php', {
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
                if (typeof Toast !== 'undefined') {
                  Toast.success(t('settings.security.sessions.revoke_success_title'), data.message || t('settings.security.sessions.revoke_success'));
                } else {
                  showToast(data.message || t('settings.security.sessions.revoke_success'), 'success');
                }
              } else if (signOutSessionsError) {
                signOutSessionsError.textContent = data.message || t('settings.security.sessions.revoke_error');
                signOutSessionsError.style.display = 'block';
              }
            })
            .catch(function() {
              if (signOutSessionsError) {
                signOutSessionsError.textContent = t('settings.security.sessions.revoke_error');
                signOutSessionsError.style.display = 'block';
              }
            })
            .finally(function() {
              signOutOtherSessionsBtn.disabled = false;
            });
          },
          null,
          false,
          signOutOtherSessionsBtn
        );
      });
    }

    // ============================================
    // PRACTICE-WIDE 2FA ENFORCEMENT (owner/admin)
    // ============================================
    var practiceRequire2fa = document.getElementById('practiceRequire2fa');
    var practice2faSummary = document.getElementById('practice2faSummary');
    var practice2faMembersToggle = document.getElementById('practice2faMembersToggle');
    var practice2faMembers = document.getElementById('practice2faMembers');
    var practice2faMembersBody = document.getElementById('practice2faMembersBody');
    var practice2faError = document.getElementById('practice2faError');
    var practice2faSuccess = document.getElementById('practice2faSuccess');
    var practice2faBusy = false;

    function practice2faEscape(text) {
      var div = document.createElement('div');
      div.textContent = text == null ? '' : String(text);
      return div.innerHTML;
    }

    function practice2faShowError(message) {
      if (practice2faError) {
        practice2faError.textContent = message || '';
        practice2faError.style.display = message ? 'block' : 'none';
      }
      if (practice2faSuccess) practice2faSuccess.style.display = 'none';
    }

    function practice2faShowSuccess(message) {
      if (practice2faSuccess) {
        practice2faSuccess.textContent = message || '';
        practice2faSuccess.style.display = message ? 'block' : 'none';
      }
      if (practice2faError) practice2faError.style.display = 'none';
    }

    function practice2faRenderStatus(data) {
      if (practiceRequire2fa) {
        practiceRequire2fa.checked = !!data.required;
        practiceRequire2fa.disabled = false;
      }
      var counts = data.counts || { total: 0, enabled: 0, needs_setup: 0 };
      if (practice2faSummary) {
        var stateLabel = data.required
          ? t('settings.security.practice_2fa.state_required')
          : t('settings.security.practice_2fa.state_optional');
        practice2faSummary.textContent = stateLabel + ' · ' +
          t('settings.security.practice_2fa.summary', {
            protected: counts.enabled,
            needsSetup: counts.needs_setup,
            total: counts.total
          });
        practice2faSummary.style.display = 'block';
      }
      if (practice2faMembersToggle) {
        practice2faMembersToggle.style.display = counts.total ? 'inline' : 'none';
      }
      if (practice2faMembersBody) {
        practice2faMemberMap = {};
        var html = '';
        (data.members || []).forEach(function(m) {
          practice2faMemberMap[m.id] = m;
          var statusText = m.totp_enabled
            ? t('settings.security.practice_2fa.status_enabled')
            : t('settings.security.practice_2fa.status_setup_required');
          var statusClass = m.totp_enabled ? 'status-badge status-enabled' : 'status-badge status-warning';
          var roleText = m.is_owner
            ? t('settings.security.practice_2fa.role_owner')
            : (m.role === 'admin'
                ? t('settings.security.practice_2fa.role_admin')
                : t('settings.security.practice_2fa.role_member'));
          var nameCell = (m.name || m.email || '');
          if (m.email && m.email !== nameCell) {
            nameCell += ' · ' + m.email;
          }
          var actionCell = m.totp_enabled
            ? '<button type="button" class="btn-link practice-2fa-recovery-btn" data-member-id="' + m.id + '">' +
              practice2faEscape(t('settings.security.practice_2fa.send_recovery')) + '</button>'
            : '';
          actionCell += '<button type="button" class="btn-link practice-2fa-signout-btn" data-member-id="' + m.id + '">' +
            practice2faEscape(t('settings.security.practice_2fa.signout_sessions')) + '</button>';
          html += '<tr>' +
            '<td data-label="' + t('settings.security.practice_2fa.col_user') + '">' + practice2faEscape(nameCell) + '</td>' +
            '<td data-label="' + t('settings.security.practice_2fa.col_role') + '">' + practice2faEscape(roleText) + '</td>' +
            '<td data-label="' + t('settings.security.practice_2fa.col_status') + '"><span class="' + statusClass + '">' + practice2faEscape(statusText) + '</span></td>' +
            '<td data-label="' + t('settings.security.practice_2fa.col_actions') + '">' + actionCell + '</td>' +
            '</tr>';
        });
        practice2faMembersBody.innerHTML = html;
      }
    }

    var practice2faMemberMap = {};

    function practice2faLoadStatus() {
      if (!practiceRequire2fa) return;
      fetch('api/practice-2fa-policy.php?action=status', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.success) {
            practice2faRenderStatus(data);
          } else {
            practice2faShowError(data.message || t('settings.security.practice_2fa.error'));
          }
        })
        .catch(function() {
          practice2faShowError(t('settings.security.practice_2fa.error'));
        });
    }

    function practice2faSave(enabled) {
      if (practice2faBusy || !practiceRequire2fa) return;
      practice2faBusy = true;
      practiceRequire2fa.disabled = true;
      practice2faShowError('');
      practice2faShowSuccess('');

      fetch('api/practice-2fa-policy.php?action=update', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        credentials: 'same-origin',
        body: JSON.stringify({ enabled: enabled })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          practice2faShowSuccess(data.message || '');
          practice2faLoadStatus();
          return;
        }
        // Revert the checkbox - the policy did not change.
        practiceRequire2fa.checked = !enabled;
        if (data.error_code === 'ACTOR_2FA_REQUIRED') {
          // The actor must enroll first - route them through the existing
          // blocking setup page, then they can re-apply the toggle.
          practice2faShowError(data.message || t('settings.security.practice_2fa.actor_setup_required'));
          if (data.redirect) {
            setTimeout(function() { window.location.href = data.redirect; }, 1500);
          }
        } else {
          practice2faShowError(data.message || t('settings.security.practice_2fa.error'));
        }
      })
      .catch(function() {
        practiceRequire2fa.checked = !enabled;
        practice2faShowError(t('settings.security.practice_2fa.error'));
      })
      .finally(function() {
        practice2faBusy = false;
        if (practiceRequire2fa) practiceRequire2fa.disabled = false;
      });
    }

    if (practiceRequire2fa) {
      practiceRequire2fa.disabled = true; // until status loads
      practice2faLoadStatus();

      practiceRequire2fa.addEventListener('change', function() {
        var enabled = practiceRequire2fa.checked;
        if (enabled) {
          // Revert immediately - only a confirmed save may leave it on.
          practiceRequire2fa.checked = false;
          showConfirmModal(
            t('settings.security.practice_2fa.confirm_title'),
            t('settings.security.practice_2fa.confirm_message'),
            function() { practice2faSave(true); },
            null,
            false,
            practiceRequire2fa
          );
        } else {
          practice2faSave(false);
        }
      });
    }

    function practice2faSendRecovery(memberId) {
      var member = practice2faMemberMap[memberId];
      if (!member) return;
      var name = member.name || member.email || '';
      showConfirmModal(
        t('settings.security.practice_2fa.recovery_confirm_title'),
        t('settings.security.practice_2fa.recovery_confirm_message', { name: name }),
        function() {
          fetch('api/practice-2fa-policy.php?action=send_member_recovery', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({ member_id: memberId })
          })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.success) {
              practice2faShowSuccess(data.message || t('settings.security.practice_2fa.recovery_sent'));
            } else {
              practice2faShowError(data.message || t('settings.security.practice_2fa.recovery_send_failed'));
            }
          })
          .catch(function() {
            practice2faShowError(t('settings.security.practice_2fa.recovery_send_failed'));
          });
        },
        null,
        false,
        null
      );
    }

    function practice2faSignOutMember(memberId, triggerBtn) {
      var member = practice2faMemberMap[memberId];
      if (!member) return;
      var name = member.name || member.email || '';
      showConfirmModal(
        t('settings.security.practice_2fa.signout_confirm_title'),
        t('settings.security.practice_2fa.signout_confirm_message', { name: name }),
        function() {
          fetch('api/practice-2fa-policy.php?action=revoke_member_sessions', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({ member_id: memberId })
          })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.success) {
              practice2faShowSuccess(data.message || t('settings.security.practice_2fa.signout_success'));
            } else {
              practice2faShowError(data.message || t('settings.security.practice_2fa.signout_failed'));
            }
          })
          .catch(function() {
            practice2faShowError(t('settings.security.practice_2fa.signout_failed'));
          });
        },
        null,
        false,
        triggerBtn || null
      );
    }

    if (practice2faMembers) {
      practice2faMembers.addEventListener('click', function(e) {
        var recoveryBtn = e.target.closest('.practice-2fa-recovery-btn');
        if (recoveryBtn && recoveryBtn.dataset.memberId) {
          practice2faSendRecovery(parseInt(recoveryBtn.dataset.memberId, 10));
          return;
        }
        var signoutBtn = e.target.closest('.practice-2fa-signout-btn');
        if (signoutBtn && signoutBtn.dataset.memberId) {
          practice2faSignOutMember(parseInt(signoutBtn.dataset.memberId, 10), signoutBtn);
        }
      });
    }

    if (practice2faMembersToggle && practice2faMembers) {
      practice2faMembersToggle.addEventListener('click', function() {
        var open = practice2faMembers.style.display !== 'none';
        practice2faMembers.style.display = open ? 'none' : 'block';
        practice2faMembersToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        practice2faMembersToggle.textContent = open
          ? t('settings.security.practice_2fa.members_toggle')
          : t('settings.security.practice_2fa.members_toggle_hide');
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
          null, // No action needed on cancel
          false,
          exportDataBtn
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

    // Expose mobile kanban helpers so js/mobile-kanban.js can reuse the
    // existing authoritative case actions and status-change path.
    window.editCaseHandler = editCaseHandler;
    window.printCase = printCase;
    window.deleteCase = deleteCase;
    window.showDeleteConfirmation = showDeleteConfirmation;
    window.updateCardStatus = updateCardStatus;

    // Expose day-diff helpers so the mobile case modal can reuse the same
    // past-due / coming-due / appointment-risk calculations as the board.
    window.getCalendarDayDiff = getCalendarDayDiff;
    window.getDueWarningText = getDueWarningText;

    // Expose the Settings modal open/close functions for tests, keyboard
    // shortcuts, and programmatic callers. openSettingsBillingModal enforces
    // its own phone-viewport and admin guards.
    window.openSettingsBillingModal = openSettingsBillingModal;

    // Allow notification panel to close the settings modal when it opens.
    window.closeSettingsBillingModal = closeSettingsBillingModal;

  })();
});

/**
 * Workflow column management in Settings > Display & Behavior.
 * Handles add, rename (via the Settings save button), reorder, archive,
 * and restore. All structural changes call /api/workflow-columns.php.
 */

/**
 * Settings > Integrations (PMS connections) - Phase B
 *
 * Provider-agnostic card UI driven by api/integrations.php. Provider UI
 * metadata lives in INTEGRATION_PROVIDER_SPECS below; adding a provider is
 * a spec entry plus a card in main.php, not a restructure.
 *
 * SECURITY: this module never stores, logs, or echoes credential values.
 * Secrets travel only in the POST body of the configure request; blank
 * fields mean "keep existing credential" server-side.
 */
(function () {
    'use strict';

    var INTEGRATION_PROVIDER_SPECS = {
        open_dental: {
            // Guided onboarding: the practice's Customer Key is generated
            // through the Open Dental Developer Portal API (generate_key
            // action). No credential fields - the admin never sees or types
            // developer secrets.
            guided: true,
            credentialFields: []
        }
    };

    var integrationsState = {
        loaded: false,
        connectionsByProvider: {},   // provider -> public connection projection
        configProvider: null,
        returnFocusEl: null          // element to refocus when the child modal closes
    };

    function integrationsPost(payload) {
        return fetch('api/integrations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function statusLabelKey(status) {
        switch (status) {
            case 'active':   return 'settings.integrations.status.connected';
            case 'pending':  return 'settings.integrations.status.pending';
            case 'error':    return 'settings.integrations.status.error';
            case 'disabled': return 'settings.integrations.status.disabled';
            default:         return 'settings.integrations.status.not_connected';
        }
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function setBadge(provider, status) {
        var badge = document.getElementById('integrationStatusBadge-' + provider);
        if (!badge) return;
        badge.textContent = t(statusLabelKey(status || 'none'));
        badge.className = 'integration-status-badge' +
            (status && status !== 'none' ? ' integration-status-' + status : '');
    }

    function renderMeta(provider, conn) {
        var meta = document.getElementById('integrationMeta-' + provider);
        if (!meta) return;
        if (!conn) { meta.innerHTML = ''; return; }

        var never = t('settings.integrations.meta.never');
        var row = function (labelKey, valueHtml) {
            return '<div class="integration-meta-row"><span class="integration-meta-label">'
                + esc(t(labelKey)) + '</span><span>' + valueHtml + '</span></div>';
        };

        // 1. Connection - reflects the verified/active connection state.
        var html = row('settings.integrations.meta.connection', esc(t(statusLabelKey(conn.status))));

        if (conn.status === 'active' || conn.status === 'error') {
            // 2. Automatic lab case updates - driven by the real event
            //    subscription state, not the connection.
            var sub = conn.subscription || null;
            var subActive = !!(sub && sub.status === 'active');
            html += row('settings.integrations.meta.auto_updates', esc(
                subActive ? t('settings.integrations.meta.auto_updates_on')
                          : t('settings.integrations.meta.auto_updates_off')));

            // 3/4. Activity: when a lab case notification last arrived, and
            //      when it last created or updated a DentaTrak case.
            html += row('settings.integrations.meta.last_event_received',
                esc((sub && sub.last_event_received_at) || never));
            html += row('settings.integrations.meta.last_case_write',
                esc(conn.last_case_write_at || never));

            if (sub && sub.cases_imported !== undefined && sub.cases_imported !== null) {
                html += row('settings.integrations.meta.cases_imported', esc(String(sub.cases_imported)));
            }
            if (sub && sub.last_failure_reason) {
                html += '<div class="integration-meta-row"><span class="integration-meta-label">'
                    + esc(t('settings.integrations.meta.delivery_issue')) + '</span><span class="integration-meta-error">'
                    + esc(sub.last_failure_reason) + '</span></div>';
            }

            // Deletion watch state - only surfaced when relevant (not a
            // plain "not_configured" on a connection without updates on).
            if (sub && sub.deletion_watch && sub.deletion_watch !== 'not_configured') {
                html += row('settings.integrations.meta.deletion_watch', esc(
                    t('settings.integrations.meta.deletion_watch_' + sub.deletion_watch)));
            }

            // 5. Existing lab case import - one-line outcome of the most
            //    recent admin-initiated import (counts only, never PHI).
            var bf = conn.backfill || null;
            if (bf && bf.status) {
                var bfText;
                if (bf.status === 'running') {
                    bfText = t('settings.integrations.import.in_progress');
                } else if (bf.status === 'failed') {
                    bfText = t('settings.integrations.import.failed');
                } else {
                    bfText = t('settings.integrations.import.summary', {
                        imported: bf.imported, existing: bf.existing,
                        skipped: bf.skipped, failed: bf.failed
                    });
                }
                html += row('settings.integrations.meta.last_import', esc(bfText));
            }
        }

        if (conn.status === 'error' && conn.last_error) {
            html += '<div class="integration-meta-row"><span class="integration-meta-label">'
                + esc(t('settings.integrations.meta.connection_issue')) + '</span><span class="integration-meta-error">'
                + esc(conn.last_error) + '</span></div>';
        }

        meta.innerHTML = html;
    }

    function renderActions(provider, conn) {
        var card = document.getElementById('integrationCard-' + provider);
        if (!card) return;
        var status = conn ? conn.status : 'none';
        var show = function (cls, visible) {
            var btn = card.querySelector('.integration-action-' + cls);
            if (btn) btn.style.display = visible ? '' : 'none';
        };
        show('connect',    status === 'none');
        show('configure',  status === 'pending' || status === 'active' || status === 'error' || status === 'disabled');
        show('test',       status === 'pending' || status === 'active' || status === 'error');
        show('disconnect', status === 'pending' || status === 'active' || status === 'error');
        show('reenable',   status === 'disabled');
        var subActive = status === 'active' && conn && conn.subscription && conn.subscription.status === 'active';
        // The LabCaseDeleted watch may be absent on connections subscribed
        // before it existed - re-offer subscribe so it can be repaired.
        // 'unsupported' (OD < 26.1.6) is terminal - never re-offered.
        var delWatch = subActive ? (conn.subscription.deletion_watch || null) : null;
        var delWatchMissing = subActive && delWatch !== 'active' && delWatch !== 'unsupported';
        show('subscribe',   status === 'active' && (!subActive || delWatchMissing));
        show('unsubscribe', subActive);

        // Historical import controls appear only on a verified connection;
        // the controls lock while an import run is in flight.
        var importBlock = document.getElementById('integrationImport-' + provider);
        if (importBlock) {
            importBlock.style.display = (status === 'active') ? '' : 'none';
            var running = !!(conn && conn.backfill && conn.backfill.status === 'running');
            var importBtn = card.querySelector('.integration-action-import');
            var scopeSel = document.getElementById('integrationImportScope-' + provider);
            if (importBtn) importBtn.disabled = running;
            if (scopeSel) scopeSel.disabled = running;
        }
    }

    function renderProvider(provider) {
        var conn = integrationsState.connectionsByProvider[provider] || null;
        setBadge(provider, conn ? conn.status : 'none');
        renderMeta(provider, conn);
        renderActions(provider, conn);
    }

    // Plan lock: render the locked card state without calling the API (the
    // server returns 403 plan_required anyway). Used when PHP rendered the
    // card with data-locked, and as the defensive path when the list call
    // itself reports the entitlement failure.
    function applyPlanLock(provider, serverMessage) {
        var card = document.getElementById('integrationCard-' + provider);
        if (!card) return;
        card.classList.add('integration-card-locked');
        card.setAttribute('data-locked', 'true');
        var badge = document.getElementById('integrationStatusBadge-' + provider);
        if (badge) {
            badge.textContent = t('settings.integrations.status.not_on_plan');
            badge.className = 'integration-status-badge integration-status-locked';
        }
        var actions = card.querySelector('.integration-card-actions');
        if (actions) actions.style.display = 'none';
        var importBlock = document.getElementById('integrationImport-' + provider);
        if (importBlock) importBlock.style.display = 'none';
        if (!card.querySelector('.integration-locked-note')) {
            var note = document.createElement('p');
            note.className = 'integration-locked-note';
            note.textContent = serverMessage || t('settings.integrations.locked_note');
            var meta = document.getElementById('integrationMeta-' + provider);
            card.insertBefore(note, meta || null);
        }
    }

    function allProviderCardsLocked() {
        var cards = document.querySelectorAll('.integration-card[data-provider]');
        if (!cards.length) return false;
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].getAttribute('data-locked') !== 'true') return false;
        }
        return true;
    }

    function loadIntegrations() {
        var panelErr = document.getElementById('integrationsPanelError');
        // All cards server-rendered locked (plan not entitled): nothing to
        // fetch - the API would answer 403 plan_required for every action.
        if (allProviderCardsLocked()) {
            integrationsState.loaded = true;
            return Promise.resolve();
        }
        return fetch('api/integrations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (data.error_code === 'plan_required') {
                        Object.keys(INTEGRATION_PROVIDER_SPECS).forEach(function (p) {
                            applyPlanLock(p, data.message);
                        });
                        return;
                    }
                    if (panelErr) { panelErr.textContent = data.message || t('settings.integrations.messages.load_failed'); panelErr.style.display = ''; }
                    return;
                }
                integrationsState.connectionsByProvider = {};
                (data.connections || []).forEach(function (conn) {
                    integrationsState.connectionsByProvider[conn.provider] = conn;
                });
                integrationsState.loaded = true;
                Object.keys(INTEGRATION_PROVIDER_SPECS).forEach(renderProvider);
                // The config modal can open before the list resolves - if it
                // is open, refresh Step 1 with the just-loaded key state so a
                // stored key never presents as "no key" (which would invite a
                // generate click that the server correctly 409s).
                if (integrationsState.configProvider) {
                    renderGuidedKeyState(
                        integrationsState.connectionsByProvider[integrationsState.configProvider] || null
                    );
                }
            })
            .catch(function () {
                if (panelErr) { panelErr.textContent = t('settings.integrations.messages.load_failed'); panelErr.style.display = ''; }
            });
    }

    // ------------------------- Configure modal -------------------------

    function openIntegrationConfig(provider, openerEl) {
        var modal = document.getElementById('integrationConfigModal');
        var spec = INTEGRATION_PROVIDER_SPECS[provider];
        if (!modal || !spec) return;

        integrationsState.configProvider = provider;
        integrationsState.returnFocusEl = openerEl || null;
        var conn = integrationsState.connectionsByProvider[provider] || null;
        var configuredKeys = (conn && conn.credential_keys) || [];

        document.getElementById('integrationConfigTitle').textContent =
            spec.guided
                ? t('settings.integrations.setup.title', { provider: t('settings.integrations.providers.open_dental.name') })
                : t('settings.integrations.modal.title', { provider: t('settings.integrations.providers.open_dental.name') });
        document.getElementById('integrationConfigDescription').textContent =
            spec.guided
                ? t('settings.integrations.setup.description')
                : t('settings.integrations.modal.description');

        // Guided providers render the step-by-step setup; the generic
        // credential form + Save button apply only to unguided providers.
        var guided = document.getElementById('integrationGuidedSetup');
        var saveBtn = document.getElementById('integrationConfigSave');
        var credNote = document.getElementById('integrationCredentialsNote');
        var cancelBtn = document.getElementById('integrationConfigCancel');
        if (guided) guided.style.display = spec.guided ? '' : 'none';
        if (saveBtn) saveBtn.style.display = spec.guided ? 'none' : '';
        if (credNote) credNote.style.display = spec.guided ? 'none' : '';
        if (cancelBtn) cancelBtn.textContent = spec.guided ? t('common.close') : t('common.cancel');
        if (spec.guided) {
            integrationsState.keyJustGenerated = false;
            renderGuidedKeyState(conn);
            var guidance = document.getElementById('integrationTestGuidance');
            if (guidance) { guidance.textContent = ''; guidance.style.display = 'none'; }
            // Refresh connection state - the list may still be in flight from
            // panel init, and stale data would show the wrong Step 1 state.
            loadIntegrations();
        }

        var fields = document.getElementById('integrationCredentialFields');
        fields.innerHTML = '';
        spec.credentialFields.forEach(function (field) {
            var isConfigured = configuredKeys.indexOf(field.key) !== -1;
            var wrap = document.createElement('div');
            wrap.className = 'form-field integration-credential-field';
            var label = document.createElement('label');
            label.setAttribute('for', 'integrationCred-' + field.key);
            label.textContent = t(field.labelKey);
            var input = document.createElement('input');
            input.id = 'integrationCred-' + field.key;
            input.type = 'password';
            input.autocomplete = 'off';
            input.setAttribute('data-credential-key', field.key);
            var hint = document.createElement('div');
            hint.className = 'configured-hint';
            hint.textContent = isConfigured
                ? t('settings.integrations.modal.configured')
                : t('settings.integrations.modal.not_configured');
            wrap.appendChild(label);
            wrap.appendChild(input);
            wrap.appendChild(hint);
            fields.appendChild(wrap);
        });

        var err = document.getElementById('integrationConfigError');
        err.textContent = '';
        err.style.display = 'none';

        modal.style.display = 'block';
    }

    function closeIntegrationConfig() {
        var modal = document.getElementById('integrationConfigModal');
        if (modal) modal.style.display = 'none';
        integrationsState.configProvider = null;
        // Clear any typed secrets from the DOM so they cannot linger.
        var fields = document.getElementById('integrationCredentialFields');
        if (fields) fields.innerHTML = '';
        // The generated Customer Key must not survive in the DOM after the
        // modal closes (any close path: X, Close, Escape, backdrop, parent
        // modal teardown).
        var keyValue = document.getElementById('integrationKeyValue');
        if (keyValue) keyValue.textContent = '';
        var keyReady = document.getElementById('integrationKeyReady');
        if (keyReady) keyReady.style.display = 'none';
        integrationsState.keyJustGenerated = false;
        // Return focus to the button that opened the modal.
        if (integrationsState.returnFocusEl && document.contains(integrationsState.returnFocusEl)) {
            try { integrationsState.returnFocusEl.focus(); } catch (e) {}
        }
        integrationsState.returnFocusEl = null;
    }

    // ------------------------- Guided Open Dental setup -----------------

    /**
     * Step 1 state: Generate (no key yet) / show-once key (just generated) /
     * configured (key exists - offer explicit Regenerate).
     */
    function renderGuidedKeyState(conn) {
        // A freshly generated key is being shown once on screen - a late
        // loadIntegrations() resolution must never wipe it out.
        if (integrationsState.keyJustGenerated) return;
        var hasKey = !!(conn && conn.credential_keys && conn.credential_keys.indexOf('customer_key') !== -1);
        var genWrap = document.getElementById('integrationKeyGenerateWrap');
        var keyReady = document.getElementById('integrationKeyReady');
        var keyExisting = document.getElementById('integrationKeyExisting');
        var keyValue = document.getElementById('integrationKeyValue');
        if (keyValue) keyValue.textContent = '';
        if (keyReady) keyReady.style.display = 'none';
        if (genWrap) genWrap.style.display = hasKey ? 'none' : '';
        if (keyExisting) keyExisting.style.display = hasKey ? '' : 'none';
    }

    /**
     * Show an error at the bottom of the child modal and bring it into
     * view - the body scrolls independently, so a fresh error can sit
     * below the fold while the admin is looking at Step 1.
     */
    function showIntegrationModalError(text) {
        var err = document.getElementById('integrationConfigError');
        if (!err) return;
        err.textContent = text;
        err.style.display = '';
        if (typeof err.scrollIntoView === 'function') {
            err.scrollIntoView({ block: 'nearest' });
        }
    }

    function generateIntegrationKey(regenerate) {
        var provider = integrationsState.configProvider || 'open_dental';
        var err = document.getElementById('integrationConfigError');
        var genBtn = document.getElementById('integrationGenerateKey');
        var regenBtn = document.getElementById('integrationRegenerateKey');
        var busyBtn = regenerate ? regenBtn : genBtn;
        if (busyBtn) {
            busyBtn.disabled = true;
            busyBtn.dataset.originalLabel = busyBtn.textContent;
            busyBtn.textContent = t('settings.integrations.setup.generating');
        }
        integrationsPost({ action: 'generate_key', provider: provider, regenerate: !!regenerate })
            .then(function (data) {
                if (busyBtn) { busyBtn.disabled = false; busyBtn.textContent = busyBtn.dataset.originalLabel; }
                if (data.success) {
                    err.textContent = '';
                    err.style.display = 'none';
                    // Show the key once, in place - Copy button beside it.
                    integrationsState.keyJustGenerated = true;
                    var genWrap = document.getElementById('integrationKeyGenerateWrap');
                    var keyReady = document.getElementById('integrationKeyReady');
                    var keyExisting = document.getElementById('integrationKeyExisting');
                    var keyValue = document.getElementById('integrationKeyValue');
                    if (genWrap) genWrap.style.display = 'none';
                    if (keyExisting) keyExisting.style.display = 'none';
                    if (keyValue) keyValue.textContent = data.customer_key;
                    if (keyReady) keyReady.style.display = '';
                    if (data.connection) {
                        integrationsState.connectionsByProvider[provider] = data.connection;
                        renderProvider(provider);
                    }
                    showToast(data.message || t('settings.integrations.messages.key_generated'), 'success');
                } else {
                    showIntegrationModalError(data.message || t('settings.integrations.messages.generate_failed'));
                }
            })
            .catch(function () {
                if (busyBtn) { busyBtn.disabled = false; busyBtn.textContent = busyBtn.dataset.originalLabel; }
                showIntegrationModalError(t('settings.integrations.messages.generate_failed'));
            });
    }

    function copyIntegrationKey() {
        var keyValue = document.getElementById('integrationKeyValue');
        var copyBtn = document.getElementById('integrationKeyCopy');
        var text = keyValue ? keyValue.textContent : '';
        if (!text) return;
        var done = function () {
            if (copyBtn) {
                copyBtn.textContent = t('settings.integrations.setup.copied');
                setTimeout(function () {
                    copyBtn.textContent = t('settings.integrations.setup.copy_key');
                }, 2000);
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, done);
        } else {
            // Fallback for older browsers/HTTP contexts.
            var ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
        }
    }

    /**
     * Map a safe server error_code to next-step guidance for the admin.
     * Codes come from OpenDentalApiException categories - never raw errors.
     */
    function testErrorGuidance(errorCode) {
        switch (errorCode) {
            case 'credentials_missing':
                return t('settings.integrations.errors.key_not_installed');
            case 'auth':
                return t('settings.integrations.errors.credentials_rejected');
            case 'server_config_missing':
                return t('settings.integrations.errors.server_config');
            case 'econnector_offline':
                return t('settings.integrations.errors.econnector_offline');
            case 'network':
            case 'timeout':
            case 'server_error':
                return t('settings.integrations.errors.unreachable');
            default:
                return t('settings.integrations.errors.unexpected');
        }
    }

    function testIntegrationFromModal() {
        var provider = integrationsState.configProvider;
        var conn = provider && integrationsState.connectionsByProvider[provider];
        var guidance = document.getElementById('integrationTestGuidance');
        var testBtn = document.getElementById('integrationModalTest');
        if (!conn) {
            if (guidance) {
                guidance.textContent = t('settings.integrations.errors.no_key');
                guidance.style.display = '';
            }
            return;
        }
        if (testBtn) {
            testBtn.disabled = true;
            testBtn.textContent = t('settings.integrations.testing');
        }
        integrationsPost({ action: 'test_connection', connection_id: conn.id })
            .then(function (data) {
                if (testBtn) {
                    testBtn.disabled = false;
                    testBtn.textContent = t('settings.integrations.test_connection');
                }
                if (data.connection) {
                    integrationsState.connectionsByProvider[provider] = data.connection;
                    renderProvider(provider);
                }
                if (data.success) {
                    if (guidance) {
                        var subActive = data.connection && data.connection.subscription
                            && data.connection.subscription.status === 'active';
                        guidance.textContent = t('settings.integrations.messages.test_succeeded')
                            + (subActive ? '' : ' ' + t('settings.integrations.messages.test_succeeded_next'));
                        guidance.style.display = '';
                    }
                    showToast(data.message || t('settings.integrations.messages.test_succeeded'), 'success');
                } else if (guidance) {
                    guidance.textContent = testErrorGuidance(data.error_code);
                    guidance.style.display = '';
                    if (typeof guidance.scrollIntoView === 'function') {
                        guidance.scrollIntoView({ block: 'nearest' });
                    }
                }
            })
            .catch(function () {
                if (testBtn) {
                    testBtn.disabled = false;
                    testBtn.textContent = t('settings.integrations.test_connection');
                }
                if (guidance) {
                    guidance.textContent = t('settings.integrations.errors.unexpected');
                    guidance.style.display = '';
                    if (typeof guidance.scrollIntoView === 'function') {
                        guidance.scrollIntoView({ block: 'nearest' });
                    }
                }
            });
    }

    function saveIntegrationConfig() {
        var provider = integrationsState.configProvider;
        var spec = provider && INTEGRATION_PROVIDER_SPECS[provider];
        if (!spec) return;

        var err = document.getElementById('integrationConfigError');
        var credentials = {};
        spec.credentialFields.forEach(function (field) {
            var input = document.getElementById('integrationCred-' + field.key);
            var value = input ? input.value : '';
            if (value !== '') {
                credentials[field.key] = value; // non-empty only; blank = keep
            }
        });

        var saveBtn = document.getElementById('integrationConfigSave');
        saveBtn.disabled = true;
        integrationsPost({ action: 'configure', provider: provider, credentials: credentials })
            .then(function (data) {
                saveBtn.disabled = false;
                if (data.success) {
                    closeIntegrationConfig();
                    showToast(data.message || t('settings.integrations.messages.saved'), 'success');
                    loadIntegrations();
                } else {
                    err.textContent = data.message || t('settings.integrations.messages.save_failed');
                    err.style.display = '';
                }
            })
            .catch(function () {
                saveBtn.disabled = false;
                err.textContent = t('settings.integrations.messages.save_failed');
                err.style.display = '';
            });
    }

    // ------------------------- Other actions ---------------------------

    function disconnectIntegration(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        showConfirmModal(
            t('settings.integrations.disconnect_confirm_title'),
            t('settings.integrations.disconnect_confirm_message'),
            function () {
                integrationsPost({ action: 'disconnect', connection_id: conn.id })
                    .then(function (data) {
                        if (data.success) {
                            showToast(data.message || t('settings.integrations.messages.disconnected'), 'success');
                            loadIntegrations();
                        } else {
                            showToast(data.message || t('settings.integrations.messages.disconnect_failed'), 'error');
                        }
                    })
                    .catch(function () { showToast(t('settings.integrations.messages.disconnect_failed'), 'error'); });
            }
        );
    }

    function testIntegrationConnection(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        var card = document.getElementById('integrationCard-' + provider);
        var btn = card ? card.querySelector('.integration-action-test') : null;
        var originalLabel = btn ? btn.textContent : '';
        if (btn) {
            btn.disabled = true;
            btn.textContent = t('settings.integrations.testing');
        }
        integrationsPost({ action: 'test_connection', connection_id: conn.id })
            .then(function (data) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                }
                if (data.success) {
                    showToast(data.message || t('settings.integrations.messages.test_succeeded'), 'success');
                } else {
                    showToast(testErrorGuidance(data.error_code), 'error');
                }
                loadIntegrations();
            })
            .catch(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalLabel;
                }
                showToast(t('settings.integrations.messages.test_failed'), 'error');
            });
    }

    function subscribeIntegration(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        integrationsPost({ action: 'subscribe', connection_id: conn.id })
            .then(function (data) {
                if (data.success) {
                    showToast(data.message || t('settings.integrations.messages.subscribed'), 'success');
                    loadIntegrations();
                } else {
                    // bad_request on subscribe almost always means the office
                    // runs an Open Dental version too old for lab case
                    // notifications (LabCase watch needs OD 25.4.14+).
                    var msg = data.error_code === 'bad_request'
                        ? t('settings.integrations.errors.subscribe_version')
                        : (data.message || t('settings.integrations.messages.subscribe_failed'));
                    showToast(msg, 'error');
                }
            })
            .catch(function () { showToast(t('settings.integrations.messages.subscribe_failed'), 'error'); });
    }

    function unsubscribeIntegration(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        showConfirmModal(
            t('settings.integrations.subscription.disable'),
            t('settings.integrations.subscription.disable_confirm'),
            function () {
                integrationsPost({ action: 'unsubscribe', connection_id: conn.id })
                    .then(function (data) {
                        if (data.success) {
                            showToast(data.message || t('settings.integrations.messages.unsubscribed'), 'success');
                            loadIntegrations();
                        } else {
                            showToast(data.message || t('settings.integrations.messages.subscribe_failed'), 'error');
                        }
                    })
                    .catch(function () { showToast(t('settings.integrations.messages.subscribe_failed'), 'error'); });
            }
        );
    }

    function reenableIntegration(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        integrationsPost({ action: 'reenable', connection_id: conn.id })
            .then(function (data) {
                if (data.success) {
                    showToast(data.message || t('settings.integrations.messages.reenabled'), 'success');
                    loadIntegrations();
                } else {
                    showToast(data.message || t('settings.integrations.messages.save_failed'), 'error');
                }
            })
            .catch(function () { showToast(t('settings.integrations.messages.save_failed'), 'error'); });
    }

    // --------------------- Historical import ---------------------------

    function importExistingLabCases(provider) {
        var conn = integrationsState.connectionsByProvider[provider];
        if (!conn) return;
        var scopeSel = document.getElementById('integrationImportScope-' + provider);
        var days = scopeSel ? parseInt(scopeSel.value, 10) : 90;
        showConfirmModal(
            t('settings.integrations.import.confirm_title'),
            t('settings.integrations.import.confirm_message'),
            function () {
                integrationsPost({ action: 'import_existing', connection_id: conn.id, days: days })
                    .then(function (data) {
                        if (data.success) {
                            if (data.connection) {
                                integrationsState.connectionsByProvider[provider] = data.connection;
                                renderProvider(provider);
                            }
                            showToast(data.message || t('settings.integrations.import.started'), 'success');
                            pollImport(provider);
                        } else {
                            showToast(data.message || t('settings.integrations.import.start_failed'), 'error');
                        }
                    })
                    .catch(function () { showToast(t('settings.integrations.import.start_failed'), 'error'); });
            }
        );
    }

    // Refresh the card while an import runs so the completion summary
    // appears without a manual reload. Bounded (~2 min); the persisted
    // backfill projection still shows the final result on the next load.
    function pollImport(provider) {
        if (integrationsState.importPollTimer) {
            clearTimeout(integrationsState.importPollTimer);
        }
        var tries = 0;
        var tick = function () {
            tries++;
            fetch('api/integrations.php?action=list')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { return; }
                    (data.connections || []).forEach(function (c) {
                        integrationsState.connectionsByProvider[c.provider] = c;
                    });
                    renderProvider(provider);
                    var bf = integrationsState.connectionsByProvider[provider]
                        && integrationsState.connectionsByProvider[provider].backfill;
                    if (bf && bf.status === 'running' && tries < 24) {
                        integrationsState.importPollTimer = setTimeout(tick, 5000);
                    }
                })
                .catch(function () { /* transient poll failure - next tick retries */ });
        };
        integrationsState.importPollTimer = setTimeout(tick, 4000);
    }

    // ------------------------- Wiring ---------------------------------

    function initIntegrationsPanel() {
        var list = document.querySelector('.integrations-list');
        if (!list) return; // flag off -> nothing rendered

        document.querySelectorAll('.integration-action-connect, .integration-action-configure').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                openIntegrationConfig(btn.getAttribute('data-provider'), btn);
            });
        });
        document.querySelectorAll('.integration-action-test').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                testIntegrationConnection(btn.getAttribute('data-provider'));
            });
        });
        document.querySelectorAll('.integration-action-disconnect').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                disconnectIntegration(btn.getAttribute('data-provider'));
            });
        });
        document.querySelectorAll('.integration-action-subscribe').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                subscribeIntegration(btn.getAttribute('data-provider'));
            });
        });
        document.querySelectorAll('.integration-action-unsubscribe').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                unsubscribeIntegration(btn.getAttribute('data-provider'));
            });
        });
        document.querySelectorAll('.integration-action-reenable').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                reenableIntegration(btn.getAttribute('data-provider'));
            });
        });
        document.querySelectorAll('.integration-action-import').forEach(function (btn) {
            if (btn.dataset.integrationInit) return;
            btn.dataset.integrationInit = '1';
            btn.addEventListener('click', function () {
                importExistingLabCases(btn.getAttribute('data-provider'));
            });
        });



        var closeBtn = document.getElementById('integrationConfigClose');
        var cancelBtn = document.getElementById('integrationConfigCancel');
        var saveBtn = document.getElementById('integrationConfigSave');
        if (closeBtn && !closeBtn.dataset.integrationInit) { closeBtn.dataset.integrationInit = '1'; closeBtn.addEventListener('click', closeIntegrationConfig); }
        if (cancelBtn && !cancelBtn.dataset.integrationInit) { cancelBtn.dataset.integrationInit = '1'; cancelBtn.addEventListener('click', closeIntegrationConfig); }
        if (saveBtn && !saveBtn.dataset.integrationInit) { saveBtn.dataset.integrationInit = '1'; saveBtn.addEventListener('click', saveIntegrationConfig); }

        var genBtn = document.getElementById('integrationGenerateKey');
        var regenBtn = document.getElementById('integrationRegenerateKey');
        var copyBtn = document.getElementById('integrationKeyCopy');
        var modalTestBtn = document.getElementById('integrationModalTest');
        if (genBtn && !genBtn.dataset.integrationInit) { genBtn.dataset.integrationInit = '1'; genBtn.addEventListener('click', function () { generateIntegrationKey(false); }); }
        if (regenBtn && !regenBtn.dataset.integrationInit) { regenBtn.dataset.integrationInit = '1'; regenBtn.addEventListener('click', function () { generateIntegrationKey(true); }); }
        if (copyBtn && !copyBtn.dataset.integrationInit) { copyBtn.dataset.integrationInit = '1'; copyBtn.addEventListener('click', copyIntegrationKey); }
        if (modalTestBtn && !modalTestBtn.dataset.integrationInit) { modalTestBtn.dataset.integrationInit = '1'; modalTestBtn.addEventListener('click', testIntegrationFromModal); }

        // This modal opens ON TOP of Settings, so Escape must dismiss only
        // it - never the parent. Capture phase + stopPropagation runs before
        // the document-level Settings/global Escape handlers (all bubble).
        if (!document.documentElement.dataset.integrationEscapeInit) {
            document.documentElement.dataset.integrationEscapeInit = '1';
            document.addEventListener('keydown', function (e) {
                var m = document.getElementById('integrationConfigModal');
                if (e.key === 'Escape' && m && m.style.display === 'block') {
                    e.preventDefault();
                    e.stopPropagation();
                    closeIntegrationConfig();
                }
            }, true);
        }

        loadIntegrations();
    }

    // Settings teardown calls this so the child modal can never survive in
    // a stale open state when the parent closes through another path.
    window.closeIntegrationConfigModal = closeIntegrationConfig;

    window.initIntegrationsPanel = initIntegrationsPanel;
})();
