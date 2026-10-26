/**
 * ScanInbox - Incoming Scans tab
 *
 * Client for api/scan-inbox.php. Renders the practice's incoming scans with
 * status views (Needs attention / Linked / Dismissed / All), patient + scan
 * id search, vendor and scan-date filters, and server-side pagination.
 * Workflow actions: preview, download, attach to existing case, create a
 * case (normal Create Case modal prefilled), dismiss/restore, retry, and
 * open the linked case.
 *
 * SAFETY: every vendor-supplied string is rendered with textContent only.
 * Instructions are plain text (the server strips markup at ingest); they
 * are never assigned through innerHTML. Search terms are sent to the
 * server and never logged or persisted anywhere patient data would leak.
 *
 * Loaded only when SCANNER_INBOX is enabled for this user (main.php gates
 * the script tag; the API enforces the flag independently).
 */
(function () {
  'use strict';

  if (!window.scannerInbox || window.scannerInbox.enabled !== true) {
    return;
  }

  var VIEWS = ['attention', 'linked', 'dismissed', 'all'];
  var PAGE_SIZE = 25;
  var STATE_KEY = 'scanInboxState';

  var listEl = null;
  var toolbarEl = null;
  var pagerEl = null;
  var pendingScanId = null;
  var pendingScanDefaults = null;
  var attachScanId = null;
  var attachCases = [];
  var loaded = false;
  var loading = false;
  var searchTimer = null;
  var lastData = null;

  var state = { view: 'attention', q: '', vendor: '', dateFrom: '', dateTo: '', page: 1 };
  try {
    var saved = sessionStorage.getItem(STATE_KEY);
    if (saved) {
      var parsed = JSON.parse(saved);
      if (parsed && typeof parsed === 'object') {
        state = Object.assign(state, parsed);
      }
    }
  } catch (e) { /* storage unavailable - defaults apply */ }

  function saveState() {
    try { sessionStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {}
  }

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

  function btn(className, label, onClick) {
    var b = el('button', className, label);
    b.type = 'button';
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }

  function api(action, body, method) {
    var opts = { credentials: 'same-origin', headers: {} };
    var url = 'api/scan-inbox.php';
    if (method === 'GET') {
      var params = { action: action };
      if (body) {
        Object.keys(body).forEach(function (k) {
          if (body[k] !== '' && body[k] !== null && body[k] !== undefined) params[k] = body[k];
        });
      }
      url += '?' + new URLSearchParams(params).toString();
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
  // Toolbar (views, search, vendor, dates, reset)
  // -------------------------------------------------------------------

  function filtersActive() {
    return state.q !== '' || state.vendor !== '' || state.dateFrom !== '' || state.dateTo !== '';
  }

  function renderToolbar() {
    if (!toolbarEl) return;
    toolbarEl.textContent = '';

    var counts = (lastData && lastData.counts) || { attention: 0, linked: 0, dismissed: 0, all: 0 };
    var tabs = el('div', 'scan-inbox-tabs');
    tabs.setAttribute('role', 'tablist');
    VIEWS.forEach(function (view) {
      var count = counts[view] !== undefined ? counts[view] : 0;
      var tab = el('button', 'scan-inbox-tab' + (state.view === view ? ' scan-inbox-tab-active' : ''), tt('scanner.view.' + view) + ' (' + count + ')');
      tab.type = 'button';
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-selected', state.view === view ? 'true' : 'false');
      tab.addEventListener('click', function () {
        if (state.view !== view) {
          state.view = view;
          state.page = 1;
          saveState();
          loadScans();
        }
      });
      tabs.appendChild(tab);
    });
    toolbarEl.appendChild(tabs);

    var filters = el('div', 'scan-inbox-filters');

    var search = el('input', 'scan-inbox-search');
    search.type = 'search';
    search.id = 'scanInboxSearch';
    search.placeholder = tt('scanner.search_placeholder');
    search.setAttribute('aria-label', tt('scanner.search_placeholder'));
    search.value = state.q;
    search.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.q = search.value.trim();
        state.page = 1;
        saveState();
        loadScans();
      }, 300);
    });
    filters.appendChild(search);

    var vendorSel = el('select', 'scan-inbox-vendor');
    vendorSel.id = 'scanInboxVendor';
    vendorSel.setAttribute('aria-label', tt('scanner.filter_vendor'));
    var allOpt = el('option', '', tt('scanner.filter_vendor_all'));
    allOpt.value = '';
    vendorSel.appendChild(allOpt);
    ((lastData && lastData.vendors) || []).forEach(function (v) {
      var opt = el('option', '', v.label);
      opt.value = v.key;
      vendorSel.appendChild(opt);
    });
    vendorSel.value = state.vendor;
    vendorSel.addEventListener('change', function () {
      state.vendor = vendorSel.value;
      state.page = 1;
      saveState();
      loadScans();
    });
    filters.appendChild(vendorSel);

    var fromInput = el('input', 'scan-inbox-date');
    fromInput.type = 'date';
    fromInput.id = 'scanInboxDateFrom';
    fromInput.setAttribute('aria-label', tt('scanner.filter_date_from'));
    fromInput.value = state.dateFrom;
    fromInput.addEventListener('change', function () {
      state.dateFrom = fromInput.value;
      state.page = 1;
      saveState();
      loadScans();
    });
    filters.appendChild(fromInput);

    var toInput = el('input', 'scan-inbox-date');
    toInput.type = 'date';
    toInput.id = 'scanInboxDateTo';
    toInput.setAttribute('aria-label', tt('scanner.filter_date_to'));
    toInput.value = state.dateTo;
    toInput.addEventListener('change', function () {
      state.dateTo = toInput.value;
      state.page = 1;
      saveState();
      loadScans();
    });
    filters.appendChild(toInput);

    if (filtersActive()) {
      var reset = btn('btn-secondary scan-inbox-reset', tt('scanner.reset_filters'), function () {
        state.q = '';
        state.vendor = '';
        state.dateFrom = '';
        state.dateTo = '';
        state.page = 1;
        saveState();
        loadScans();
      });
      filters.appendChild(reset);
    }

    toolbarEl.appendChild(filters);
  }

  // -------------------------------------------------------------------
  // List loading + pagination
  // -------------------------------------------------------------------

  function loadScans() {
    if (!listEl || loading) return;
    loading = true;
    api('list', {
      view: state.view,
      q: state.q,
      vendor: state.vendor,
      date_from: state.dateFrom,
      date_to: state.dateTo,
      page: state.page,
      per_page: PAGE_SIZE,
    }, 'GET')
      .then(function (data) {
        loaded = true;
        lastData = data;
        if (data.total > 0 && state.page > Math.ceil(data.total / PAGE_SIZE)) {
          state.page = Math.ceil(data.total / PAGE_SIZE);
          loading = false;
          loadScans();
          return;
        }
        renderToolbar();
        renderList(data.scans || []);
        renderPager(data);
      })
      .catch(function (err) {
        listEl.textContent = '';
        listEl.appendChild(el('div', 'scan-inbox-empty', err.message || tt('scanner.error_load')));
      })
      .finally(function () {
        loading = false;
      });
  }

  function renderPager(data) {
    if (!pagerEl) return;
    pagerEl.textContent = '';
    var total = data.total || 0;
    if (total === 0) return;
    var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    var from = (state.page - 1) * PAGE_SIZE + 1;
    var to = Math.min(total, state.page * PAGE_SIZE);

    var info = el('span', 'scan-inbox-page-info', tt('scanner.showing', { from: from, to: to, total: total }));
    pagerEl.appendChild(info);

    var controls = el('div', 'scan-inbox-page-controls');
    var prev = btn('btn-secondary scan-inbox-page-btn', tt('scanner.page_prev'), function () {
      state.page = Math.max(1, state.page - 1);
      saveState();
      loadScans();
    });
    prev.disabled = state.page <= 1;
    prev.setAttribute('aria-label', tt('scanner.page_prev'));
    controls.appendChild(prev);

    var num = el('span', 'scan-inbox-page-num', tt('scanner.page_of', { page: state.page, pages: pages }));
    controls.appendChild(num);

    var next = btn('btn-secondary scan-inbox-page-btn', tt('scanner.page_next'), function () {
      state.page = Math.min(pages, state.page + 1);
      saveState();
      loadScans();
    });
    next.disabled = state.page >= pages;
    next.setAttribute('aria-label', tt('scanner.page_next'));
    controls.appendChild(next);

    pagerEl.appendChild(controls);
  }

  // -------------------------------------------------------------------
  // Rendering
  // -------------------------------------------------------------------

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
    var totalAll = lastData && lastData.counts ? lastData.counts.all : 0;
    if (!scans.length) {
      if (totalAll === 0) {
        listEl.appendChild(el('div', 'scan-inbox-empty', tt('scanner.empty')));
      } else {
        var wrap = el('div', 'scan-inbox-empty');
        wrap.appendChild(el('p', '', tt('scanner.no_matches')));
        wrap.appendChild(btn('btn-secondary', tt('scanner.reset_filters'), function () {
          state.q = '';
          state.vendor = '';
          state.dateFrom = '';
          state.dateTo = '';
          state.page = 1;
          saveState();
          loadScans();
        }));
        listEl.appendChild(wrap);
      }
      return;
    }
    scans.forEach(function (scan) {
      listEl.appendChild(renderScanRow(scan));
    });
  }

  function patientLabel(scan) {
    return [scan.patientFirstName, scan.patientLastName].filter(Boolean).join(' ');
  }

  function renderScanRow(scan) {
    var row = el('div', 'scan-row' + (scan.isDismissed ? ' scan-row-dismissed' : ''));
    var bodyId = 'scan-detail-' + scan.id;

    // -- Compact summary line
    var summary = el('div', 'scan-row-summary');
    var main = el('div', 'scan-row-main');

    var expand = btn('scan-row-expand', '', null);
    expand.setAttribute('aria-expanded', 'false');
    expand.setAttribute('aria-controls', bodyId);
    expand.setAttribute('aria-label', tt('scanner.details'));
    var arrow = el('span', 'scan-row-arrow');
    arrow.setAttribute('aria-hidden', 'true');
    expand.appendChild(arrow);
    main.appendChild(expand);

    var nameWrap = el('div', 'scan-row-patient');
    nameWrap.appendChild(el('span', 'scan-row-name', patientLabel(scan) || tt('scanner.not_provided')));
    if (scan.isSynthetic) {
      nameWrap.appendChild(el('span', 'scan-synthetic-badge', tt('scanner.synthetic_badge')));
    }
    main.appendChild(nameWrap);

    main.appendChild(el('span', 'scan-row-vendor', scan.vendorLabel || scan.vendor));
    if (scan.scanAt) {
      main.appendChild(el('span', 'scan-row-date', formatDateTime(scan.scanAt)));
    }

    var chips = el('div', 'scan-row-chips');
    chips.appendChild(el('span', 'scan-chip ' + statusClass(scan.status), tt(statusKey(scan.status))));
    if (scan.isPartiallyLinked) {
      chips.appendChild(el('span', 'scan-chip scan-chip-partial', tt('scanner.partial_linked')));
    }
    var fileCount = (scan.files || []).length;
    chips.appendChild(el('span', 'scan-row-files', tt('scanner.files_count', { count: fileCount })));
    summary.appendChild(main);
    summary.appendChild(chips);
    row.appendChild(summary);

    // -- Action bar (context-specific)
    var actions = el('div', 'scan-row-actions');

    if (scan.isLinked) {
      if (scan.linkedCase && scan.linkedCase.exists) {
        var caseLabel = scan.linkedCase.patientName
          ? scan.linkedCase.patientName + ' (' + scan.linkedCase.id + ')'
          : scan.linkedCase.id;
        actions.appendChild(el('span', 'scan-linked-label', tt('scanner.linked_to', { case: caseLabel })));
        var openBtn = btn('btn-secondary scan-action-open', tt('scanner.open_case'), function () {
          if (typeof window.openCaseById === 'function') {
            window.openCaseById(scan.linkedCase.id, {});
          }
        });
        actions.appendChild(openBtn);
      } else {
        actions.appendChild(el('span', 'scan-linked-label', tt('scanner.case_unavailable')));
      }
      // Linked scans with unlinked ready files stay actionable (revisions).
      if (scan.isPartiallyLinked) {
        actions.appendChild(btn('btn-secondary scan-action-attach', tt('scanner.attach_updated_files'), function () {
          openAttachModal(scan, scan.caseId, true);
        }));
      }
    } else if (!scan.isDismissed) {
      var attachable = scan.attachableFiles > 0;
      var noInFlight = !(scan.status === 'processing' || scan.status === 'received');

      if (attachable && noInFlight) {
        actions.appendChild(btn('btn-secondary scan-action-attach', tt('scanner.attach_to_case'), function () {
          openAttachModal(scan, null);
        }));
        actions.appendChild(btn('btn-secondary scan-action-create', tt('scanner.create_case'), function () {
          startCaseFromScan(scan);
        }));
      }

      if (scan.status === 'failed' || (scan.files || []).some(function (f) { return f.status === 'failed'; })) {
        var retryBtn = btn('btn-secondary scan-action-retry', tt('scanner.retry'), null);
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

      actions.appendChild(btn('btn-secondary scan-action-dismiss', tt('scanner.dismiss'), function () {
        confirmAction(tt('scanner.dismiss_title'), tt('scanner.dismiss_confirm'), function () {
          api('dismiss', { scan_id: scan.id })
            .then(function () { showToastSafe(tt('scanner.dismissed_toast'), 'success'); loadScans(); })
            .catch(function (err) { showToastSafe(err.message, 'error'); });
        });
      }));
    } else {
      actions.appendChild(btn('btn-secondary scan-action-restore', tt('scanner.restore'), function () {
        api('restore', { scan_id: scan.id })
          .then(function () { showToastSafe(tt('scanner.restored_toast'), 'success'); loadScans(); })
          .catch(function (err) { showToastSafe(err.message, 'error'); });
      }));
    }
    row.appendChild(actions);

    // -- Expandable details
    var body = el('div', 'scan-row-detail');
    body.id = bodyId;
    body.hidden = true;
    expand.addEventListener('click', function () {
      var open = body.hidden;
      body.hidden = !open;
      expand.setAttribute('aria-expanded', open ? 'true' : 'false');
      row.classList.toggle('scan-row-open', open);
    });

    var meta = el('dl', 'scan-meta');
    addMeta(meta, 'scanner.field_scan_id', scan.externalScanId);
    if (scan.externalPatientRef) addMeta(meta, 'scanner.field_patient_ref', scan.externalPatientRef);
    if (scan.scanAt) addMeta(meta, 'scanner.field_scanned', formatDateTime(scan.scanAt));
    if (scan.errorCode) addMeta(meta, 'scanner.field_error', tt('scanner.error_' + scan.errorCode) === 'scanner.error_' + scan.errorCode ? scan.errorCode : tt('scanner.error_' + scan.errorCode));
    body.appendChild(meta);

    if (scan.instructions) {
      var instrWrap = el('div', 'scan-instructions');
      instrWrap.appendChild(el('div', 'scan-instructions-label', tt('scanner.instructions')));
      instrWrap.appendChild(el('pre', 'scan-instructions-text', scan.instructions));
      body.appendChild(instrWrap);
    }

    if (scan.files && scan.files.length) {
      var filesEl = el('ul', 'scan-files');
      scan.files.forEach(function (f) {
        filesEl.appendChild(renderFileRow(f, scan));
      });
      body.appendChild(filesEl);
    }

    // Suggested matches (identifier-based only - never name matching)
    if (!scan.isLinked && !scan.isDismissed && scan.suggestedCases && scan.suggestedCases.length) {
      var sugg = el('div', 'scan-suggestions');
      sugg.appendChild(el('span', 'scan-suggestions-label', tt('scanner.suggested_matches') + ':'));
      scan.suggestedCases.forEach(function (c) {
        var sBtn = el('button', 'scan-suggestion-btn', c.patientName ? (c.patientName + ' (' + c.caseId + ')') : c.caseId);
        sBtn.type = 'button';
        sBtn.addEventListener('click', function () { openAttachModal(scan, c.caseId); });
        sugg.appendChild(sBtn);
      });
      body.appendChild(sugg);
    }

    row.appendChild(body);
    return row;
  }

  function addMeta(dl, labelKey, value) {
    dl.appendChild(el('dt', '', tt(labelKey)));
    dl.appendChild(el('dd', '', value));
  }

  // Vendor-declared file role -> display label. Unknown values render
  // verbatim (charset-stripped at ingest); no role -> nothing guessed.
  function fileRoleLabel(role) {
    if (!role) return '';
    var normalized = String(role).toLowerCase();
    var key = 'scanner.role.' + normalized;
    var translated = tt(key);
    return translated === key ? String(role) : translated;
  }

  function renderFileRow(file, scan) {
    var li = el('li', 'scan-file');
    // File names render clean - internal revision tokens stay available in
    // the tooltip rather than appended to the name.
    var nameSpan = el('span', 'scan-file-name', file.fileName);
    if (file.revision) {
      nameSpan.title = tt('scanner.revision') + ': ' + file.revision;
    }
    li.appendChild(nameSpan);
    var roleText = fileRoleLabel(file.fileRole);
    if (roleText) {
      li.appendChild(el('span', 'scan-file-role', roleText));
    }
    li.appendChild(el('span', 'scan-chip ' + statusClass(file.status === 'ready' && file.attachedCaseId ? 'linked' : file.status), tt(statusKey(file.attachedCaseId ? 'linked' : file.status))));

    if (file.status === 'ready') {
      var viewable = typeof window.isAttachmentViewable === 'function'
        && window.isAttachmentViewable(file.fileName)
        && typeof window.openAttachmentViewer === 'function';
      if (viewable) {
        var viewBtn = el('button', 'btn-secondary scan-file-view', tt('scanner.view_file'));
        viewBtn.type = 'button';
        viewBtn.addEventListener('click', function () {
          var contentUrl = 'api/scan-file.php?file_id=' + encodeURIComponent(file.id);
          window.openAttachmentViewer(null, file.fileName, file.contentType || 'model/stl', null, {
            contentUrl: contentUrl,
            downloadUrl: contentUrl + '&download=1',
          });
        });
        li.appendChild(viewBtn);
      }
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

  function openAttachModal(scan, preselectCaseId, lockToCase) {
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
    intro.textContent = tt('scanner.attach_intro', {
      id: scan.externalScanId,
      patient: patientLabel(scan) || tt('scanner.not_provided'),
    });

    suggList.textContent = '';
    if (!lockToCase && scan.suggestedCases && scan.suggestedCases.length) {
      scan.suggestedCases.forEach(function (c) {
        var sBtn = el('button', 'scan-suggestion-btn', c.patientName ? (c.patientName + ' (' + c.caseId + ')') : c.caseId);
        sBtn.type = 'button';
        sBtn.addEventListener('click', function () {
          select.value = c.caseId;
        });
        suggList.appendChild(sBtn);
      });
      suggWrap.style.display = '';
    } else {
      suggWrap.style.display = 'none';
    }

    if (scan.instructions) {
      instrPreview.textContent = scan.instructions;
      instrWrap.style.display = '';
      instrCheckbox.checked = false;
    } else {
      instrWrap.style.display = 'none';
      instrCheckbox.checked = false;
    }

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
        select.disabled = !!lockToCase;
      })
      .catch(function () {
        // Picker failed; leave placeholder only - user can still retry.
      });

    modal.style.display = 'block';
  }

  function closeAttachModal() {
    var modal = document.getElementById('scanAttachModal');
    if (modal) modal.style.display = 'none';
    var select = document.getElementById('scanAttachCaseSelect');
    if (select) select.disabled = false;
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
    toolbarEl = document.getElementById('scanInboxToolbar');
    pagerEl = document.getElementById('scanInboxPager');

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

    renderToolbar();

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
