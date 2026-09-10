/**
 * DentaTrak List View
 *
 * A compact, high-density alternative to the Kanban board. The list is a
 * projection of the authoritative card DOM state: every mutation path
 * (filters, create/edit/save, drag-and-drop, review changes, realtime
 * updates) already keeps each .kanban-card's dataset.caseJson current, so
 * this view reads case data from the cards rather than holding a second
 * copy of the dataset.
 *
 * Public surface: window.caseListView
 *   - refresh()          rebuild rows immediately (no-op while hidden)
 *   - scheduleRefresh()  debounced refresh (marks stale while hidden)
 *   - setView(mode)      'board' | 'list'
 *   - isActive()         whether List View is the active view
 */
(function () {
  'use strict';

  var VIEW_KEY_PREFIX = 'caseViewMode_';
  var REFRESH_DEBOUNCE_MS = 120;

  var expandedCaseId = null;
  var sortKey = 'updated';
  var sortDir = 'desc';
  var stale = true;
  var refreshTimer = null;

  function listEl() {
    return document.getElementById('caseListView');
  }

  function isActive() {
    return document.body.classList.contains('case-view-list');
  }

  /**
   * Preference key part: the authenticated user's numeric db id (non-PII),
   * exposed on #userEmailData. Falls back to the lowercased email so the key
   * stays stable if the attribute is ever absent.
   */
  function userKeyPart() {
    var el = document.getElementById('userEmailData');
    var userId = el ? parseInt(el.getAttribute('data-user-id') || '0', 10) : 0;
    if (userId > 0) return String(userId);
    return (typeof currentUserEmail === 'string' && currentUserEmail)
      ? currentUserEmail.toLowerCase()
      : 'anonymous';
  }

  function legacyEmailKeyPart() {
    return (typeof currentUserEmail === 'string' && currentUserEmail)
      ? currentUserEmail.toLowerCase()
      : 'anonymous';
  }

  function getSavedView() {
    try {
      var key = VIEW_KEY_PREFIX + userKeyPart();
      var saved = localStorage.getItem(key);
      if (saved === null) {
        // Migrate a preference stored under the older email-based key so an
        // existing user's choice is not lost by the key change.
        var legacyKey = VIEW_KEY_PREFIX + legacyEmailKeyPart();
        if (legacyKey !== key) {
          saved = localStorage.getItem(legacyKey);
          if (saved === 'list' || saved === 'board') {
            localStorage.setItem(key, saved);
          }
        }
      }
      return (saved === 'list' || saved === 'board') ? saved : 'board';
    } catch (e) {
      return 'board';
    }
  }

  function saveView(mode) {
    try {
      localStorage.setItem(VIEW_KEY_PREFIX + userKeyPart(), mode);
    } catch (e) {
      // localStorage unavailable - view preference simply won't persist
    }
  }

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function tr(key) {
    if (typeof t === 'function') {
      var value = t(key);
      if (value && value !== key) return value;
    }
    return key;
  }

  function formatListDate(value) {
    if (!value) return '';
    try {
      var date;
      if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        var parts = value.split('-');
        date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      } else {
        date = new Date(value);
      }
      if (isNaN(date.getTime())) return '';
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (e) {
      return '';
    }
  }

  function formatListDateTime(value) {
    if (!value) return '';
    try {
      var date = new Date(value);
      if (isNaN(date.getTime())) return '';
      return date.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    } catch (e) {
      return '';
    }
  }

  function dateSortValue(value) {
    if (!value) return null;
    var time;
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      var parts = value.split('-');
      time = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)).getTime();
    } else {
      time = new Date(value).getTime();
    }
    return isNaN(time) ? null : time;
  }

  /**
   * Collect the currently rendered (filtered) cases from the card DOM.
   * Returns null if the board is absent so renderers can show an error state.
   */
  function collectCases() {
    var board = document.getElementById('kanbanBoard');
    if (!board) return null;

    var cases = [];
    var cards = board.querySelectorAll('.kanban-card');
    cards.forEach(function (card) {
      // patient-search.js applies the text search client-side by hiding
      // cards (inline display:none) rather than removing them - the list
      // must mirror the same visible set.
      if (card.style.display === 'none') return;
      try {
        var data = JSON.parse(card.dataset.caseJson || '{}');
        if (data && data.id) {
          cases.push(data);
        }
      } catch (e) {
        // Skip unreadable cards rather than failing the whole list
      }
    });
    return cases;
  }

  /**
   * Whether any filter/search control currently holds a non-default value.
   * Mirrors the active-filter check used by applyFilters().
   */
  function hasActiveFilters() {
    var ids = ['patientSearch', 'filterCaseType', 'filterAssignedTo', 'filterReviewStatus', 'filterCarrier'];
    for (var i = 0; i < ids.length; i++) {
      var el = document.getElementById(ids[i]);
      if (el && el.value) return true;
    }
    var checkIds = ['filterLateCases', 'filterDueSoon', 'filterApptRisk', 'filterAtRisk'];
    for (var j = 0; j < checkIds.length; j++) {
      var box = document.getElementById(checkIds[j]);
      if (box && box.checked) return true;
    }
    return false;
  }

  /**
   * Workflow column order taken from the live board so Status sorting
   * follows the practice's configured column order (including customs).
   */
  function workflowOrderMap() {
    var map = {};
    document.querySelectorAll('.kanban-column').forEach(function (column, index) {
      if (column.dataset.status) {
        map[column.dataset.status] = index;
      }
    });
    return map;
  }

  function isFinalStatus(status) {
    return (typeof isFinalWorkflowColumn === 'function') ? isFinalWorkflowColumn(status) : false;
  }

  /**
   * Compact urgency indicators, mirroring the Kanban card's precedence:
   * Late > Appointment Risk > Due Soon. Returns { late, dueSoon, apptRisk, dueText, apptText }.
   */
  function urgencyFlags(caseData) {
    var flags = { late: false, dueSoon: false, apptRisk: false, dueText: '', apptText: '' };
    if (!caseData || isFinalStatus(caseData.status)) return flags;

    var dayDiff = (typeof window.getCalendarDayDiff === 'function') ? window.getCalendarDayDiff : null;
    if (!dayDiff) return flags;

    var highlightPastDue = localStorage.getItem('highlight_past_due') === 'true';
    var highlightComingDue = localStorage.getItem('highlight_coming_due') === 'true';
    var highlightApptRisk = localStorage.getItem('highlight_appointment_risk') === 'true';

    var dueDiff = caseData.dueDate ? dayDiff(caseData.dueDate) : null;
    var apptDiff = caseData.patientAppointmentDate ? dayDiff(caseData.patientAppointmentDate) : null;

    if (highlightPastDue && dueDiff !== null && dueDiff <= -parseInt(localStorage.getItem('past_due_days') || '1', 10)) {
      flags.late = true;
      flags.dueText = tr('cases.due.late');
      return flags;
    }

    if (highlightApptRisk && apptDiff !== null && apptDiff <= parseInt(localStorage.getItem('appointment_risk_days') || '3', 10)) {
      flags.apptRisk = true;
      flags.apptText = tr('cases.risk.appointment_abbreviation');
      return flags;
    }

    if (highlightComingDue && dueDiff !== null) {
      var comingDueDays = parseInt(localStorage.getItem('coming_due_days') || '5', 10);
      if (dueDiff >= 0 && dueDiff <= comingDueDays) {
        flags.dueSoon = true;
        flags.dueText = (typeof window.getDueWarningText === 'function') ? window.getDueWarningText(dueDiff) : '';
      }
    }

    return flags;
  }

  function assignedDisplay(assignedTo) {
    if (!assignedTo) return '';
    var value = String(assignedTo);
    return value.indexOf('@') !== -1 ? value.split('@')[0] : value;
  }

  function patientName(caseData) {
    return ((caseData.patientFirstName || '') + ' ' + (caseData.patientLastName || '')).trim();
  }

  function reviewLabel(caseData) {
    return caseData.reviewStatus === 'reviewed' ? tr('cases.reviewed') : tr('cases.needs_review');
  }

  /* ---------- Sorting ---------- */

  var SORTERS = {
    review: function (a, b) {
      // Needs Review sorts before Reviewed.
      var rank = function (c) { return c.reviewStatus === 'reviewed' ? 1 : 0; };
      return rank(a) - rank(b);
    },
    patient: function (a, b) {
      var an = ((a.patientLastName || '') + ' ' + (a.patientFirstName || '')).toLowerCase();
      var bn = ((b.patientLastName || '') + ' ' + (b.patientFirstName || '')).toLowerCase();
      return an.localeCompare(bn);
    },
    type: function (a, b) {
      return String(a.caseType || '').localeCompare(String(b.caseType || ''));
    },
    status: function (a, b) {
      var order = workflowOrderMap();
      var ai = order[a.status] !== undefined ? order[a.status] : 999;
      var bi = order[b.status] !== undefined ? order[b.status] : 999;
      return ai - bi;
    },
    assigned: function (a, b) {
      return assignedDisplay(a.assignedTo).toLowerCase().localeCompare(assignedDisplay(b.assignedTo).toLowerCase());
    },
    due: function (a, b) {
      return compareDates(a.dueDate, b.dueDate);
    },
    appointment: function (a, b) {
      return compareDates(a.patientAppointmentDate, b.patientAppointmentDate);
    },
    dentist: function (a, b) {
      return String(a.dentistName || '').toLowerCase().localeCompare(String(b.dentistName || '').toLowerCase());
    },
    updated: function (a, b) {
      return compareDates(a.lastUpdateDate, b.lastUpdateDate);
    }
  };

  // Sentinels so empty dates always sort last in BOTH directions.
  var EMPTY_FIRST = { e: 1 };
  var EMPTY_SECOND = { e: 2 };

  function compareDates(av, bv) {
    var at = dateSortValue(av);
    var bt = dateSortValue(bv);
    if (at === null && bt === null) return 0;
    if (at === null) return EMPTY_FIRST;   // a empty -> a last
    if (bt === null) return EMPTY_SECOND;  // b empty -> b last
    return at - bt;
  }

  function sortCases(cases) {
    var sorter = SORTERS[sortKey];
    if (!sorter) return cases;
    var sorted = cases.slice();
    sorted.sort(function (a, b) {
      var result = sorter(a, b);
      if (result === EMPTY_FIRST) return 1;
      if (result === EMPTY_SECOND) return -1;
      return sortDir === 'desc' ? -result : result;
    });
    return sorted;
  }

  /* ---------- Rendering ---------- */

  /**
   * Review tracking state comes from the case-review-tracking-off body
   * class, which is set server-side before first paint and kept in sync by
   * applyCaseReviewTrackingEnabled() - more reliable than the JS flag,
   * which is only populated once settings finish loading.
   */
  function reviewTrackingEnabled() {
    return !document.body.classList.contains('case-review-tracking-off');
  }

  function columnCount() {
    // chevron + patient + type + status + assigned + due + appt + dentist + updated,
    // plus review when tracking is enabled
    return reviewTrackingEnabled() ? 10 : 9;
  }

  function sortableTh(label, key, extraClass) {
    var active = sortKey === key;
    var ariaSort = active ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
    var arrow = active ? (sortDir === 'asc' ? ' \u25B2' : ' \u25BC') : '';
    return '<th class="cl-th ' + (extraClass || '') + (active ? ' sorted' : '') + '" aria-sort="' + ariaSort + '">' +
      '<button type="button" class="cl-sort-btn" data-sort-key="' + key + '" aria-label="' + esc(label) + '">' +
      esc(label) + '<span class="cl-sort-arrow" aria-hidden="true">' + arrow + '</span>' +
      '</button></th>';
  }

  function buildTable(cases) {
    var reviewEnabled = reviewTrackingEnabled();
    var html = '<table class="case-list-table"><thead><tr>' +
      '<th class="cl-th cl-expand-th" aria-label="' + esc(tr('cases.list.expand')) + '"></th>';

    if (reviewEnabled) {
      html += sortableTh(tr('cases.list.review'), 'review', 'cl-review');
    }

    html += sortableTh(tr('cases.list.patient'), 'patient', 'cl-patient') +
      sortableTh(tr('cases.list.type'), 'type', 'cl-type') +
      sortableTh(tr('cases.list.status'), 'status', 'cl-status') +
      sortableTh(tr('cases.list.assigned'), 'assigned', 'cl-assigned') +
      sortableTh(tr('cases.list.due_date'), 'due', 'cl-due') +
      sortableTh(tr('cases.list.appointment'), 'appointment', 'cl-appt') +
      sortableTh(tr('cases.list.dentist'), 'dentist', 'cl-dentist') +
      sortableTh(tr('cases.list.updated'), 'updated', 'cl-updated') +
      '</tr></thead><tbody>';

    cases.forEach(function (caseData) {
      html += buildRow(caseData, reviewEnabled);
      if (expandedCaseId && String(caseData.id) === String(expandedCaseId)) {
        html += buildDetailRow(caseData, reviewEnabled);
      }
    });

    html += '</tbody></table>';
    return html;
  }

  function buildRow(caseData, reviewEnabled) {
    var id = String(caseData.id);
    var name = patientName(caseData) || tr('cases.unnamed_patient');
    var flags = urgencyFlags(caseData);
    var isExpanded = String(expandedCaseId) === id;
    var statusLabel = (typeof getStageLabel === 'function') ? getStageLabel(caseData.status) : (caseData.status || '');
    var statusClass = (typeof getWorkflowStatusCssClass === 'function') ? getWorkflowStatusCssClass(caseData.status) : '';
    var isAtRisk = !!(window.featureFlags && window.featureFlags.SHOW_AT_RISK &&
      caseData.atRisk && caseData.atRisk.isAtRisk);

    var html = '<tr class="case-list-row" data-case-id="' + esc(id) + '"' +
      (caseData.archived ? ' data-archived="1"' : '') + '>';

    // Expand chevron
    html += '<td class="cl-td cl-expand-td">' +
      '<button type="button" class="case-list-expand' + (isExpanded ? ' expanded' : '') + '"' +
      ' aria-expanded="' + (isExpanded ? 'true' : 'false') + '"' +
      ' aria-label="' + esc((isExpanded ? tr('cases.list.collapse') : tr('cases.list.expand')) + ' - ' + name) + '"' +
      ' title="' + esc(isExpanded ? tr('cases.list.collapse') : tr('cases.list.expand')) + '">' +
      '<span class="cl-chevron" aria-hidden="true"></span></button></td>';

    // Review chip (feature-gated)
    if (reviewEnabled) {
      var isReviewed = caseData.reviewStatus === 'reviewed';
      var reviewTip = isReviewed && caseData.reviewedAt
        ? (caseData.reviewedByName || 'Unknown') + ' \u00B7 ' + formatListDateTime(caseData.reviewedAt)
        : (isReviewed ? tr('cases.mark_needs_review') : tr('cases.mark_reviewed'));
      html += '<td class="cl-td cl-review" data-label="' + esc(tr('cases.review_status')) + '">' +
        '<button type="button" class="kanban-card-review case-list-review ' + (isReviewed ? 'reviewed' : 'needs-review') + '"' +
        ' data-case-id="' + esc(id) + '"' +
        ' aria-label="' + esc(isReviewed ? tr('cases.mark_needs_review_aria') : tr('cases.mark_reviewed_aria')) + '"' +
        (caseData.archived ? ' disabled' : '') +
        ' title="' + esc(reviewTip) + '">' + esc(reviewLabel(caseData)) + '</button></td>';
    }

    // Patient (primary identifier; opens the case)
    var atRiskFlag = isAtRisk
      ? ' <span class="case-list-flag cl-flag-atrisk" title="' + esc((caseData.atRisk.reasons || []).join('\n')) + '">' + esc(tr('cases.risk.at_risk')) + '</span>'
      : '';
    html += '<td class="cl-td cl-patient" data-label="' + esc(tr('cases.list.patient')) + '">' +
      '<button type="button" class="case-list-open" data-case-id="' + esc(id) + '">' + esc(name) + '</button>' +
      atRiskFlag + '</td>';

    // Type
    html += '<td class="cl-td cl-type" data-label="' + esc(tr('cases.list.type')) + '">' + esc(caseData.caseType || '\u2014') + '</td>';

    // Workflow status
    html += '<td class="cl-td cl-status" data-label="' + esc(tr('cases.list.status')) + '">' +
      '<span class="case-list-status ' + esc(statusClass) + '">' + esc(statusLabel || '\u2014') + '</span></td>';

    // Assigned To
    html += '<td class="cl-td cl-assigned" data-label="' + esc(tr('cases.list.assigned')) + '" title="' + esc(caseData.assignedTo || '') + '">' +
      esc(assignedDisplay(caseData.assignedTo) || '\u2014') + '</td>';

    // Due Date (+ Late / Due Soon flag)
    var dueText = formatListDate(caseData.dueDate) || '\u2014';
    if (flags.late) {
      dueText += ' <span class="case-list-flag cl-flag-late">' + esc(tr('cases.due.late')) + '</span>';
    } else if (flags.dueSoon && flags.dueText) {
      dueText += ' <span class="case-list-flag cl-flag-duesoon">' + esc(flags.dueText) + '</span>';
    }
    html += '<td class="cl-td cl-due' + (flags.late ? ' cl-due-late' : '') + '" data-label="' + esc(tr('cases.list.due_date')) + '">' + dueText + '</td>';

    // Patient Appointment (+ Appt Risk flag)
    var apptText = formatListDate(caseData.patientAppointmentDate) || '\u2014';
    if (flags.apptRisk) {
      apptText += ' <span class="case-list-flag cl-flag-appt">' + esc(tr('cases.risk.appointment_abbreviation')) + '</span>';
    }
    html += '<td class="cl-td cl-appt' + (flags.apptRisk ? ' cl-appt-risk' : '') + '" data-label="' + esc(tr('cases.list.appointment')) + '">' + apptText + '</td>';

    // Dentist
    html += '<td class="cl-td cl-dentist" data-label="' + esc(tr('cases.list.dentist')) + '">' + esc(caseData.dentistName || '\u2014') + '</td>';

    // Updated
    html += '<td class="cl-td cl-updated" data-label="' + esc(tr('cases.list.updated')) + '">' + esc(formatListDate(caseData.lastUpdateDate) || '\u2014') + '</td>';

    html += '</tr>';
    return html;
  }

  function detailField(label, value) {
    if (!value) return '';
    return '<div class="cl-detail-item"><span class="cl-detail-label">' + esc(label) + '</span>' +
      '<span class="cl-detail-value">' + esc(value) + '</span></div>';
  }

  function buildDetailRow(caseData, reviewEnabled) {
    var items = '';

    items += detailField(tr('cases.list.created'), formatListDateTime(caseData.creationDate) +
      (caseData.createdByName && caseData.createdByName !== 'Unknown' ? ' \u00B7 ' + caseData.createdByName : ''));
    items += detailField(tr('cases.list.updated'), formatListDateTime(caseData.lastUpdateDate));
    items += detailField(tr('cases.list.status_changed'), formatListDateTime(caseData.statusChangedAt));
    items += detailField(tr('cases.list.assigned'), caseData.assignedTo || '');
    items += detailField(tr('cases.list.tracking'), caseData.trackingNumber || '');
    items += detailField(tr('cases.carrier'), caseData.customCarrier || caseData.carrier || '');
    items += detailField(tr('cases.list.dentist'), caseData.dentistName || '');

    var attachments = Array.isArray(caseData.attachments) ? caseData.attachments : [];
    if (attachments.length > 0) {
      items += detailField(tr('cases.list.attachments'), String(attachments.length));
    }

    if (caseData.revisionCount) {
      items += detailField(tr('cases.list.revisions'), String(caseData.revisionCount));
    }

    if (reviewEnabled) {
      var reviewText;
      if (caseData.reviewStatus === 'reviewed' && caseData.reviewedAt) {
        reviewText = tr('cases.reviewed_by_timestamp')
          .replace('{name}', caseData.reviewedByName || 'Unknown')
          .replace('{timestamp}', formatListDateTime(caseData.reviewedAt));
      } else {
        reviewText = tr('cases.needs_review');
      }
      items += detailField(tr('cases.review_status'), reviewText);
    }

    var notesPreview = '';
    if (caseData.notes) {
      var trimmed = String(caseData.notes).trim();
      if (trimmed.length > 160) trimmed = trimmed.substring(0, 160) + '\u2026';
      notesPreview = '<div class="cl-detail-notes"><span class="cl-detail-label">' + esc(tr('cases.list.notes')) + '</span>' +
        '<span class="cl-detail-value">' + esc(trimmed) + '</span></div>';
    }

    var openBtn = '<button type="button" class="case-list-open-detail" data-case-id="' + esc(caseData.id) + '">' +
      esc(tr('cases.list.open_case')) + '</button>';

    return '<tr class="case-list-detail-row" data-case-id="' + esc(caseData.id) + '">' +
      '<td colspan="' + columnCount() + '"><div class="cl-detail-panel">' +
      '<div class="cl-detail-grid">' + items + '</div>' +
      notesPreview + openBtn +
      '</div></td></tr>';
  }

  function stateRow(message) {
    return '<tr class="case-list-state-row"><td colspan="' + columnCount() + '">' +
      '<div class="case-list-state">' + esc(message) + '</div></td></tr>';
  }

  function render() {
    var lv = listEl();
    if (!lv) return;

    var board = document.getElementById('kanbanBoard');
    var cases = collectCases();
    var html;

    // While the initial case load is in flight the board has no cards yet;
    // show a loading state rather than flashing "No cases yet".
    var initialLoadPending = board && !board.classList.contains('loaded') &&
      document.querySelectorAll('.kanban-card').length === 0;

    if (cases === null) {
      html = '<table class="case-list-table"><tbody>' + stateRow(tr('cases.list.error')) + '</tbody></table>';
    } else if (initialLoadPending) {
      html = '<table class="case-list-table"><tbody>' + stateRow(tr('cases.list.loading')) + '</tbody></table>';
    } else if (cases.length === 0) {
      var message = hasActiveFilters() ? tr('cases.list.empty_filtered') : tr('cases.list.empty');
      html = '<table class="case-list-table"><tbody>' + stateRow(message) + '</tbody></table>';
    } else {
      html = buildTable(sortCases(cases));
    }

    lv.innerHTML = html;
  }

  function refresh(force) {
    if (!isActive() && !force) {
      stale = true;
      return;
    }
    stale = false;
    render();
  }

  function scheduleRefresh() {
    if (!isActive()) {
      stale = true;
      return;
    }
    if (refreshTimer) clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function () {
      refreshTimer = null;
      refresh();
    }, REFRESH_DEBOUNCE_MS);
  }

  /* ---------- View switching ---------- */

  function setView(mode) {
    var list = mode === 'list';
    document.body.classList.toggle('case-view-list', list);
    document.body.classList.toggle('case-view-board', !list);

    var boardBtn = document.getElementById('boardViewToggle');
    var listBtn = document.getElementById('listViewToggle');
    if (boardBtn) {
      boardBtn.classList.toggle('active', !list);
      boardBtn.setAttribute('aria-pressed', list ? 'false' : 'true');
    }
    if (listBtn) {
      listBtn.classList.toggle('active', list);
      listBtn.setAttribute('aria-pressed', list ? 'true' : 'false');
    }

    var lv = listEl();
    if (lv) lv.hidden = !list;

    var mobileNav = document.getElementById('mobileKanbanNav');
    if (mobileNav) mobileNav.hidden = list;

    saveView(mode);

    if (list && stale) {
      refresh(true);
    }
  }

  /* ---------- Event wiring ---------- */

  function bindEvents() {
    var lv = listEl();
    if (!lv) return;

    lv.addEventListener('click', function (e) {
      var target = e.target;
      if (!target) return;

      // Expand/collapse chevron
      var expandBtn = target.closest('.case-list-expand');
      if (expandBtn) {
        e.preventDefault();
        e.stopPropagation();
        var row = expandBtn.closest('.case-list-row');
        var caseId = row ? row.getAttribute('data-case-id') : null;
        if (!caseId) return;
        expandedCaseId = (expandedCaseId === caseId) ? null : caseId;
        render();
        return;
      }

      // Review chip - reuses the shared review mutation path
      var reviewBtn = target.closest('.case-list-review');
      if (reviewBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (reviewBtn.disabled || reviewBtn.classList.contains('loading')) return;
        var rid = reviewBtn.getAttribute('data-case-id');
        if (!rid) return;
        var card = document.querySelector('.kanban-card[data-case-id="' + rid + '"]');
        var cardData = {};
        try { cardData = card ? JSON.parse(card.dataset.caseJson || '{}') : {}; } catch (err) { cardData = {}; }
        if (cardData.archived) return;
        reviewBtn.classList.add('loading');
        if (typeof window.updateCaseReviewStatus === 'function') {
          window.updateCaseReviewStatus(rid, cardData.reviewStatus !== 'reviewed');
        } else {
          reviewBtn.classList.remove('loading');
        }
        return;
      }

      // Sort headers
      var sortBtn = target.closest('.cl-sort-btn');
      if (sortBtn) {
        e.preventDefault();
        var key = sortBtn.getAttribute('data-sort-key');
        if (!key || !SORTERS[key]) return;
        if (sortKey === key) {
          sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          sortKey = key;
          sortDir = 'asc';
        }
        render();
        return;
      }

      // Open the case through the same path a Kanban card uses
      var openBtn = target.closest('.case-list-open, .case-list-open-detail');
      if (openBtn) {
        e.preventDefault();
        e.stopPropagation();
        openRowCase(openBtn.getAttribute('data-case-id'));
        return;
      }

      // Row click opens the case (skip interactive elements)
      var row = target.closest('.case-list-row');
      if (row && !target.closest('button, a, input, select, textarea')) {
        openRowCase(row.getAttribute('data-case-id'));
      }
    });

    // Keyboard: Enter/Space on a focused row opens the case
    lv.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      if (e.target.closest('button, a, input, select, textarea')) return;
      var row = e.target.closest('.case-list-row');
      if (!row) return;
      e.preventDefault();
      openRowCase(row.getAttribute('data-case-id'));
    });
  }

  /**
   * Open a case exactly as a Kanban card interaction does: hand the card's
   * own normalized case object to editCaseHandler() - no extra get-case.php
   * round trip. The card payload is already practice-authorized (the same
   * data the card's edit button and double-click use), and server-side
   * mutation endpoints still enforce requireCaseAccess on save. Falls back
   * to openCaseById only when the card payload is missing or marks the case
   * archived (its read-only/archived routing is needed there).
   */
  function openRowCase(caseId) {
    if (!caseId) return;

    var card = document.querySelector('.kanban-card[data-case-id="' + caseId + '"]');
    var cardData = null;
    try {
      cardData = card ? JSON.parse(card.dataset.caseJson || 'null') : null;
    } catch (e) {
      cardData = null;
    }

    if (cardData && cardData.id && !cardData.archived &&
        typeof window.editCaseHandler === 'function') {
      window.editCaseHandler(cardData);
      return;
    }

    if (typeof window.openCaseById === 'function') {
      window.openCaseById(caseId, { tab: 'details' });
    }
  }

  function init() {
    bindEvents();

    var boardBtn = document.getElementById('boardViewToggle');
    var listBtn = document.getElementById('listViewToggle');
    if (boardBtn) {
      boardBtn.addEventListener('click', function () { setView('board'); });
    }
    if (listBtn) {
      listBtn.addEventListener('click', function () { setView('list'); });
    }

    // Rebuild when the board re-renders (initial load + filter changes)
    // or when card content changes (realtime, review toggles, drag/drop).
    window.addEventListener('cardsLoaded', scheduleRefresh);
    window.addEventListener('cardsUpdated', scheduleRefresh);

    // The patient search also applies a client-side card hide
    // (patient-search.js) that fires before the server-side refetch
    // resolves - refresh immediately so the list mirrors it.
    var searchInput = document.getElementById('patientSearch');
    if (searchInput) {
      searchInput.addEventListener('input', scheduleRefresh);
    }

    // Apply the saved view (defaults to Board).
    setView(getSavedView());
  }

  window.caseListView = {
    refresh: refresh,
    scheduleRefresh: scheduleRefresh,
    setView: setView,
    isActive: isActive
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
