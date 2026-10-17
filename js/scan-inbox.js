/**
 * ScanInbox - Incoming Scans tab
 *
 * Client for api/scan-inbox.php. Renders the practice's incoming scans and
 * wires the workflow actions: attach to existing case, create a new case
 * (through the normal Create Case modal, prefilled from scan details),
 * dismiss/restore, and retry.
 *
 * SAFETY: every vendor-supplied string is rendered with textContent only.
 * Instructions are plain text (the server strips markup at ingest); they
 * are never assigned through innerHTML.
 *
 * Loaded only when SCANNER_INBOX is enabled for this user (main.php gates
 * the script tag; the API enforces the flag independently).
 */
(function () {
  'use strict';

  if (!window.scannerInbox || window.scannerInbox.enabled !== true) {
    return;
  }

  var listEl = null;
  var includeDismissed = false;
  var pendingScanId = null;      // scan awaiting the case created in the modal
  var pendingScanDefaults = null;
  var attachScanId = null;
  var attachCases = [];
  var loaded = false;
  var loading = false;

  function tt(key, params) {
    if (typeof t === 'function') {
      try { return t(key, params); } catch (e) { return key; }
    }
    return key;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function api(action, body, method) {
    var opts = { credentials: 'same-origin', headers: {} };
    var url = 'api/scan-inbox.php';
    if (method === 'GET') {
      url += '?action=' + encodeURIComponent(action);
      if (body && body.include_dismissed) url += '&include_dismissed=1';
    } else {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      opts.body = JSON.stringify(Object.assign({ action: action }, body || {}));
    }
    return fetch(url, opts).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          var err = new Error(data.message || ('HTTP ' + res.status));
          err.errorCode = data.error_code;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  function showToastSafe(message, type) {
    if (typeof showToast === 'function') {
      showToast(message, type || 'info');
    }
  }

  function confirmAction(title, message, onConfirm) {
    if (typeof showConfirmModal === 'function') {
      showConfirmModal(title, message, onConfirm);
    } else if (window.confirm(message)) {
      onConfirm();
    }
  }

  // -------------------------------------------------------------------
  // List loading + rendering
  // -------------------------------------------------------------------

  function loadScans() {
    if (!listEl || loading) return;
    loading = true;
    api('list', { include_dismissed: includeDismissed }, 'GET')
      .then(function (data) {
        loaded = true;
        renderList(data.scans || []);
      })
      .catch(function (err) {
        listEl.textContent = '';
        listEl.appendChild(el('div', 'scan-inbox-empty', err.message || tt('scanner.error_load')));
      })
      .finally(function () {
        loading = false;
      });
  }

  function statusKey(status) {
    switch (status) {
      case 'processing': return 'scanner.status.processing';
      case 'ready': return 'scanner.status.ready';
      case 'failed': return 'scanner.status.failed';
      case 'linked': return 'scanner.status.linked';
      case 'dismissed': return 'scanner.status.dismissed';
      case 'superseded': return 'scanner.status.superseded';
      default: return 'scanner.status.received';
    }
  }

  function statusClass(status) {
    if (status === 'ready') return 'scan-chip-ready';
    if (status === 'linked') return 'scan-chip-linked';
    if (status === 'failed') return 'scan-chip-failed';
    if (status === 'dismissed') return 'scan-chip-dismissed';
    if (status === 'processing' || status === 'downloading') return 'scan-chip-processing';
    if (status === 'superseded') return 'scan-chip-superseded';
    return 'scan-chip-received';
  }

  function renderList(scans) {
    listEl.textContent = '';
    if (!scans.length) {
      listEl.appendChild(el('div', 'scan-inbox-empty', tt('scanner.empty')));
      return;
    }
    scans.forEach(function (scan) {
      listEl.appendChild(renderScanCard(scan));
    });
  }

  function renderScanCard(scan) {
    var card = el('div', 'scan-card');
    if (scan.isDismissed) card.classList.add('scan-card-dismissed');

    // Header: vendor + status
    var top = el('div', 'scan-card-top');
    var vendorWrap = el('div', 'scan-card-vendor-wrap');
    vendorWrap.appendChild(el('span', 'scan-vendor', scan.vendorLabel || scan.vendor));
    if (scan.isSynthetic) {
      vendorWrap.appendChild(el('span', 'scan-synthetic-badge', tt('scanner.synthetic_badge')));
    }
    top.appendChild(vendorWrap);
    top.appendChild(el('span', 'scan-chip ' + statusClass(scan.status), tt(statusKey(scan.status))));
    card.appendChild(top);

    // Patient + metadata grid
    var meta = el('dl', 'scan-meta');
    var patientName = [scan.patientFirstName, scan.patientLastName].filter(Boolean).join(' ');
    addMeta(meta, 'scanner.field_patient', patientName || tt('scanner.not_provided'));
    addMeta(meta, 'scanner.field_scan_id', scan.externalScanId);
    if (scan.scanAt) addMeta(meta, 'scanner.field_scanned', formatDateTime(scan.scanAt));
    if (scan.errorCode) addMeta(meta, 'scanner.field_error', tt('scanner.error_' + scan.errorCode) === 'scanner.error_' + scan.errorCode ? scan.errorCode : tt('scanner.error_' + scan.errorCode));
    card.appendChild(meta);

    // Instructions: plain text in <pre>, never HTML.
    if (scan.instructions) {
      var instrWrap = el('div', 'scan-instructions');
      instrWrap.appendChild(el('div', 'scan-instructions-label', tt('scanner.instructions')));
      instrWrap.appendChild(el('pre', 'scan-instructions-text', scan.instructions));
      card.appendChild(instrWrap);
    }

    // Files
    if (scan.files && scan.files.length) {
      var filesEl = el('ul', 'scan-files');
      scan.files.forEach(function (f) {
        filesEl.appendChild(renderFileRow(f, scan));
      });
      card.appendChild(filesEl);
    }

    // Suggested matches (identifier-based only - never name matching)
    if (!scan.isLinked && !scan.isDismissed && scan.suggestedCases && scan.suggestedCases.length) {
      var sugg = el('div', 'scan-suggestions');
      sugg.appendChild(el('span', 'scan-suggestions-label', tt('scanner.suggested_matches') + ':'));
      scan.suggestedCases.forEach(function (c) {
        var btn = el('button', 'scan-suggestion-btn', c.patientName ? (c.patientName + ' (' + c.caseId + ')') : c.caseId);
        btn.type = 'button';
        btn.addEventListener('click', function () { openAttachModal(scan, c.caseId); });
        sugg.appendChild(btn);
      });
      card.appendChild(sugg);
    }

    // Actions
    var actions = el('div', 'scan-card-actions');

    if (scan.isLinked) {
      actions.appendChild(el('span', 'scan-linked-note', tt('scanner.linked_to', { case: scan.caseId })));
    } else if (!scan.isDismissed) {
      var attachable = scan.attachableFiles > 0;
      var noInFlight = !(scan.status === 'processing' || scan.status === 'received');

      if (attachable && noInFlight) {
        var attachBtn = el('button', 'btn-secondary scan-action-attach', tt('scanner.attach_to_case'));
        attachBtn.type = 'button';
        attachBtn.addEventListener('click', function () { openAttachModal(scan, null); });
        actions.appendChild(attachBtn);

        var createBtn = el('button', 'btn-secondary scan-action-create', tt('scanner.create_case'));
        createBtn.type = 'button';
        createBtn.addEventListener('click', function () { startCaseFromScan(scan); });
        actions.appendChild(createBtn);
      }

      if (scan.status === 'failed' || scan.files.some(function (f) { return f.status === 'failed'; })) {
        var retryBtn = el('button', 'btn-secondary scan-action-retry', tt('scanner.retry'));
        retryBtn.type = 'button';
        retryBtn.addEventListener('click', function () {
          retryBtn.disabled = true;
          api('retry', { scan_id: scan.id })
            .then(function () {
              showToastSafe(tt('scanner.retry_started'), 'success');
              loadScans();
            })
            .catch(function (err) {
              showToastSafe(err.message, 'error');
            })
            .finally(function () { retryBtn.disabled = false; });
        });
        actions.appendChild(retryBtn);
      }

      var dismissBtn = el('button', 'btn-secondary scan-action-dismiss', tt('scanner.dismiss'));
      dismissBtn.type = 'button';
      dismissBtn.addEventListener('click', function () {
        confirmAction(tt('scanner.dismiss_title'), tt('scanner.dismiss_confirm'), function () {
          api('dismiss', { scan_id: scan.id })
            .then(function () { showToastSafe(tt('scanner.dismissed_toast'), 'success'); loadScans(); })
            .catch(function (err) { showToastSafe(err.message, 'error'); });
        });
      });
      actions.appendChild(dismissBtn);
    } else {
      var restoreBtn = el('button', 'btn-secondary scan-action-restore', tt('scanner.restore'));
      restoreBtn.type = 'button';
      restoreBtn.addEventListener('click', function () {
        api('restore', { scan_id: scan.id })
          .then(function () { showToastSafe(tt('scanner.restored_toast'), 'success'); loadScans(); })
          .catch(function (err) { showToastSafe(err.message, 'error'); });
      });
      actions.appendChild(restoreBtn);
    }

    card.appendChild(actions);
    return card;
  }

  function addMeta(dl, labelKey, value) {
    dl.appendChild(el('dt', '', tt(labelKey)));
    dl.appendChild(el('dd', '', value));
  }

  function renderFileRow(file, scan) {
    var li = el('li', 'scan-file');
    var nameSpan = el('span', 'scan-file-name', file.fileName + (file.revision ? ' (' + tt('scanner.revision') + ' ' + file.revision + ')' : ''));
    li.appendChild(nameSpan);
    li.appendChild(el('span', 'scan-chip ' + statusClass(file.status === 'ready' && file.attachedCaseId ? 'linked' : file.status), tt(statusKey(file.attachedCaseId ? 'linked' : file.status))));

    if (file.status === 'ready') {
      var link = el('a', 'scan-file-download', tt('scanner.download'));
      link.href = 'api/scan-file.php?file_id=' + encodeURIComponent(file.id) + '&download=1';
      link.rel = 'noopener';
      li.appendChild(link);
    } else if (file.status === 'failed' && file.errorCode) {
      li.appendChild(el('span', 'scan-file-error', file.errorCode));
    }
    return li;
  }

  function formatDateTime(value) {
    try {
      var d = new Date(value);
      if (isNaN(d.getTime())) return value;
      return d.toLocaleString();
    } catch (e) {
      return value;
    }
  }

  // -------------------------------------------------------------------
  // Attach modal
  // -------------------------------------------------------------------

  function openAttachModal(scan, preselectCaseId) {
    var modal = document.getElementById('scanAttachModal');
    var select = document.getElementById('scanAttachCaseSelect');
    var intro = document.getElementById('scanAttachIntro');
    var error = document.getElementById('scanAttachError');
    var suggWrap = document.getElementById('scanAttachSuggestions');
    var suggList = document.getElementById('scanAttachSuggestionsList');
    var instrWrap = document.getElementById('scanAttachInstructionsWrap');
    var instrPreview = document.getElementById('scanAttachInstructionsPreview');
    var instrCheckbox = document.getElementById('scanAttachAppendInstructions');
    if (!modal || !select) return;

    attachScanId = scan.id;
    error.style.display = 'none';
    error.textContent = '';
    var patientName = [scan.patientFirstName, scan.patientLastName].filter(Boolean).join(' ');
    intro.textContent = tt('scanner.attach_intro', {
      id: scan.externalScanId,
      patient: patientName || tt('scanner.not_provided'),
    });

    // Suggestions (identifier-derived only)
    suggList.textContent = '';
    if (scan.suggestedCases && scan.suggestedCases.length) {
      scan.suggestedCases.forEach(function (c) {
        var btn = el('button', 'scan-suggestion-btn', c.patientName ? (c.patientName + ' (' + c.caseId + ')') : c.caseId);
        btn.type = 'button';
        btn.addEventListener('click', function () {
          select.value = c.caseId;
        });
        suggList.appendChild(btn);
      });
      suggWrap.style.display = '';
    } else {
      suggWrap.style.display = 'none';
    }

    // Imported instructions preview + explicit opt-in merge
    if (scan.instructions) {
      instrPreview.textContent = scan.instructions;
      instrWrap.style.display = '';
      instrCheckbox.checked = false;
    } else {
      instrWrap.style.display = 'none';
      instrCheckbox.checked = false;
    }

    // Populate case picker
    select.innerHTML = '';
    select.appendChild(el('option', '', tt('scanner.select_case_placeholder'))).value = '';
    api('cases', null, 'GET')
      .then(function (data) {
        attachCases = data.cases || [];
        attachCases.forEach(function (c) {
          var label = [c.patientFirstName, c.patientLastName].filter(Boolean).join(' ');
          label = label ? (label + ' - ' + (c.caseType || '') + ' (' + c.id + ')') : c.id;
          var opt = el('option', '', label);
          opt.value = c.id;
          select.appendChild(opt);
        });
        if (preselectCaseId) {
          select.value = preselectCaseId;
        }
      })
      .catch(function () {
        // Picker failed; leave placeholder only - user can still retry.
      });

    modal.style.display = 'block';
  }

  function closeAttachModal() {
    var modal = document.getElementById('scanAttachModal');
    if (modal) modal.style.display = 'none';
    attachScanId = null;
  }

  function confirmAttach() {
    var select = document.getElementById('scanAttachCaseSelect');
    var error = document.getElementById('scanAttachError');
    var checkbox = document.getElementById('scanAttachAppendInstructions');
    var confirmBtn = document.getElementById('scanAttachConfirm');
    var caseId = select ? select.value : '';
    if (!caseId) {
      error.textContent = tt('scanner.select_case_error');
      error.style.display = '';
      return;
    }
    if (!attachScanId) return;

    confirmBtn.disabled = true;
    api('link', {
      scan_id: attachScanId,
      case_id: caseId,
      append_instructions: !!(checkbox && checkbox.checked),
    })
      .then(function () {
        closeAttachModal();
        showToastSafe(tt('scanner.attach_success'), 'success');
        loadScans();
      })
      .catch(function (err) {
        error.textContent = err.message || tt('scanner.attach_error');
        error.style.display = '';
      })
      .finally(function () {
        confirmBtn.disabled = false;
      });
  }

  // -------------------------------------------------------------------
  // Create case from scan (existing Create Case workflow)
  // -------------------------------------------------------------------

  function startCaseFromScan(scan) {
    confirmAction(
      tt('scanner.create_title'),
      tt('scanner.create_confirm', { id: scan.externalScanId }),
      function () {
        pendingScanId = scan.id;
        pendingScanDefaults = {
          first: scan.patientFirstName || '',
          last: scan.patientLastName || '',
          dob: scan.patientDob || '',
          instructions: scan.instructions || '',
        };

        var createBtn = document.querySelector('.create-case-button');
        if (!createBtn) {
          pendingScanId = null;
          showToastSafe(tt('scanner.error_load'), 'error');
          return;
        }
        createBtn.click();

        // Prefill available scan details as editable defaults once the
        // modal opens. Fields stay fully editable - this is a default,
        // not an auto-match.
        setTimeout(function () {
          setFieldValue('patientFirstName', pendingScanDefaults.first);
          setFieldValue('patientLastName', pendingScanDefaults.last);
          setFieldValue('patientDOB', pendingScanDefaults.dob);
          if (pendingScanDefaults.instructions) {
            var notes = document.getElementById('notes');
            if (notes && !notes.value) {
              notes.value = pendingScanDefaults.instructions;
            }
          }
        }, 250);
      }
    );
  }

  function setFieldValue(id, value) {
    var field = document.getElementById(id);
    if (field && value) {
      field.value = value;
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  /**
   * Called by app.js after a case-creation submit succeeds. Links the
   * pending scan to the newly created case; failures surface as a toast
   * and leave the scan in the inbox for manual attach.
   */
  function linkCreatedCase(caseId) {
    if (!pendingScanId || !caseId) return;
    var scanId = pendingScanId;
    pendingScanId = null;
    pendingScanDefaults = null;
    api('link', { scan_id: scanId, case_id: caseId, append_instructions: false })
      .then(function () {
        showToastSafe(tt('scanner.attach_success'), 'success');
        loadScans();
      })
      .catch(function (err) {
        showToastSafe(err.message || tt('scanner.attach_error'), 'error');
        loadScans();
      });
  }

  // -------------------------------------------------------------------
  // Wiring
  // -------------------------------------------------------------------

  function init() {
    listEl = document.getElementById('scanInboxList');
    if (!listEl) return;

    var tab = document.querySelector('.main-tab[data-tab="incoming-scans"]');
    if (tab) {
      tab.addEventListener('click', function () {
        if (!loaded) loadScans();
      });
    }

    var refresh = document.getElementById('scanInboxRefresh');
    if (refresh) {
      refresh.addEventListener('click', loadScans);
    }

    var dismissedToggle = document.getElementById('scanInboxShowDismissed');
    if (dismissedToggle) {
      dismissedToggle.addEventListener('change', function () {
        includeDismissed = !!dismissedToggle.checked;
        loadScans();
      });
    }

    var attachConfirm = document.getElementById('scanAttachConfirm');
    var attachCancel = document.getElementById('scanAttachCancel');
    var attachClose = document.getElementById('scanAttachClose');
    var attachModal = document.getElementById('scanAttachModal');
    if (attachConfirm) attachConfirm.addEventListener('click', confirmAttach);
    if (attachCancel) attachCancel.addEventListener('click', closeAttachModal);
    if (attachClose) attachClose.addEventListener('click', closeAttachModal);
    if (attachModal) {
      attachModal.addEventListener('click', function (e) {
        if (e.target === attachModal) closeAttachModal();
      });
    }
  }

  window.ScanInbox = {
    linkCreatedCase: linkCreatedCase,
    reload: loadScans,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
