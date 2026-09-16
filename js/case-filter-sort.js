(function () {
  'use strict';

  var bootstrapEl = document.getElementById('caseViewBootstrap');
  var bootstrap = bootstrapEl ? JSON.parse(bootstrapEl.textContent) : {};
  var filters = Object.assign({}, (bootstrap.preferences || {}).filters);
  var criteria = ((bootstrap.preferences || {}).sort || []).slice();
  var fields = ['type', 'assigned', 'due', 'appointment', 'dentist', 'updated', 'patient', 'status', 'review'];
  var labels = { type: 'type', assigned: 'assigned', due: 'due_date', appointment: 'appointment', dentist: 'dentist', updated: 'updated', patient: 'patient', status: 'status', review: 'review' };
  var filterKeys = ['patientSearch', 'filterCaseType', 'filterAssignedTo', 'filterReviewStatus', 'filterCarrier', 'filterLateCases', 'filterDueSoon', 'filterApptRisk', 'filterAtRisk'];
  var checks = ['filterLateCases', 'filterDueSoon', 'filterApptRisk', 'filterAtRisk'];
  var params = ['search', 'case_type', 'assigned_to', 'review_status', 'carrier', 'late_only', 'due_soon', 'appt_risk_only', 'at_risk_only'];
  var collator = new Intl.Collator(document.documentElement.lang || undefined, { sensitivity: 'base', numeric: true });
  var saveTimer, saving, pending, failed, orderTimer;
  var generation = 0;
  var rendering = 0;
  function withBoardRender(callback) {
    rendering++;
    try { return callback(); } finally { rendering--; }
  }
  var orderRank = 0;
  var defaultOrder = new WeakMap();

  function tr(key) { return typeof t === 'function' ? t(key) : key; }
  function esc(value) { return String(value).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function label(field) { return tr('cases.list.' + labels[field]); }
  function availableFields() { return fields.filter(function (f) { return f !== 'review' || !document.body.classList.contains('case-review-tracking-off'); }); }
  function defaultDirection(field) { return field === 'updated' ? 'desc' : 'asc'; }
  function directions(field) {
    return field === 'updated' ? { asc: tr('filters.oldest'), desc: tr('filters.newest') }
      : (field === 'due' || field === 'appointment') ? { asc: tr('filters.earliest'), desc: tr('filters.latest') }
      : { asc: tr('filters.az'), desc: tr('filters.za') };
  }
  function normalizeSort(sort) {
    var seen = new Set();
    return (Array.isArray(sort) ? sort : []).filter(function (c) {
      if (!c || !availableFields().includes(c.field) || !['asc', 'desc'].includes(c.direction) || seen.has(c.field)) return false;
      seen.add(c.field);
      return true;
    }).slice(0, 3).map(function (c) { return { field: c.field, direction: c.direction }; });
  }
  function getSort() { return criteria.map(function (c) { return Object.assign({}, c); }); }
  function effectiveSort(view) { return criteria.length ? getSort() : view === 'list' ? [{ field: 'updated', direction: 'desc' }] : []; }
  function text(value) { return value == null ? '' : String(value).trim(); }
  function assignedDisplay(value) {
    return (Array.isArray(value) ? value : [value]).map(function (v) {
      v = text(v);
      return v.includes('@') ? v.split('@')[0] : v;
    }).filter(Boolean).sort(collator.compare).join(', ');
  }
  function dateValue(value) {
    if (!value) return null;
    var normalized = String(value).replace(' ', 'T');
    if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) normalized += 'T00:00:00Z';
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(normalized)) normalized += 'Z';
    var result = Date.parse(normalized);
    return Number.isFinite(result) ? result : null;
  }
  function value(c, field) {
    if (field === 'due') return dateValue(c.dueDate);
    if (field === 'appointment') return dateValue(c.patientAppointmentDate);
    if (field === 'updated') return dateValue(c.lastUpdateDate);
    if (field === 'assigned') return assignedDisplay(c.assignedTo);
    if (field === 'patient') return text(c.patientLastName) + ' ' + text(c.patientFirstName);
    if (field === 'dentist') return text(c.dentistName);
    if (field === 'review') return tr(c.reviewStatus === 'reviewed' ? 'cases.reviewed' : 'cases.needs_review');
    if (field === 'status') return c.status ? (typeof getStageLabel === 'function' ? getStageLabel(c.status) : c.status) : '';
    if (field === 'type') {
      var option = Array.from(document.querySelectorAll('#filterCaseType option')).find(function (o) { return o.value === c.caseType; });
      return c.caseType ? (option ? option.textContent : c.caseType) : '';
    }
    return '';
  }
  function compare(a, b, sort) {
    for (var c of (sort || criteria)) {
      var av = value(a, c.field), bv = value(b, c.field);
      var am = av == null || (typeof av === 'string' && !av.trim());
      var bm = bv == null || (typeof bv === 'string' && !bv.trim());
      if (am !== bm) return am ? 1 : -1;
      if (am) continue;
      var diff = typeof av === 'number' ? av - bv : collator.compare(av, bv);
      if (diff) return c.direction === 'desc' ? -diff : diff;
    }
    return String(a.id).localeCompare(String(b.id), 'en', { numeric: true }) || (String(a.id) < String(b.id) ? -1 : String(a.id) > String(b.id) ? 1 : 0);
  }
  function sortCases(cases, view) { var sort = effectiveSort(view); return cases.slice().sort(function (a, b) { return compare(a, b, sort); }); }
  function rememberCard(card) { defaultOrder.set(card, --orderRank); scheduleBoard(); }
  function applyBoard(restoreDefault) {
    clearTimeout(orderTimer);
    if (!criteria.length && restoreDefault !== true) return;
    if (document.querySelector('.kanban-card.dragging')) {
      orderTimer = setTimeout(function () { applyBoard(restoreDefault); }, 50);
      return;
    }
    document.querySelectorAll('.kanban-column-body').forEach(function (column) {
      var cards = Array.from(column.querySelectorAll('.kanban-card'));
      var data = new Map();
      cards.forEach(function (card, index) {
        if (!defaultOrder.has(card)) defaultOrder.set(card, index);
        try { data.set(card, JSON.parse(card.dataset.caseJson)); } catch (e) { data.set(card, { id: card.id }); }
      });
      var sorted = cards.slice().sort(function (a, b) {
        return criteria.length ? compare(data.get(a), data.get(b)) : defaultOrder.get(a) - defaultOrder.get(b);
      });
      sorted.forEach(function (card, index) {
        var current = column.querySelectorAll('.kanban-card')[index];
        if (current !== card) column.insertBefore(card, current || null);
      });
    });
  }
  function scheduleBoard() { clearTimeout(orderTimer); orderTimer = setTimeout(applyBoard, 0); }
  function readFilters() {
    filterKeys.forEach(function (key) {
      var el = document.getElementById(key);
      filters[key] = checks.includes(key) ? !!(el && el.checked) : el ? el.value : '';
    });
    if (document.body.classList.contains('case-review-tracking-off')) filters.filterReviewStatus = '';
    return Object.assign({}, filters);
  }
  function query() {
    var current = readFilters(), search = new URLSearchParams();
    filterKeys.forEach(function (key, i) { if (current[key]) search.set(params[i], current[key] === true ? 'true' : current[key]); });
    return search.toString();
  }
  function showError(key) {
    var el = document.getElementById('caseViewSaveError');
    if (el) { el.hidden = !key; el.querySelector('span').textContent = key ? tr(key) : ''; }
    if (key && typeof window.showToast === 'function') window.showToast(tr(key), 'warning');
  }
  function snapshot() { return { filters: readFilters(), sort: getSort() }; }
  function queueSave() { pending = snapshot(); clearTimeout(saveTimer); saveTimer = setTimeout(flush, 350); }
  function flush() {
    clearTimeout(saveTimer);
    if (saving) return saving;
    if (!pending) return Promise.resolve(!failed);
    saving = (async function () {
      while (pending) {
        var next = pending;
        pending = null;
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        try {
          var token = document.querySelector('meta[name="csrf-token"]');
          var response = await fetch('api/case-view-preferences.php', {
            method: 'POST', credentials: 'same-origin', keepalive: true, signal: controller.signal,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token ? token.content : '' },
            body: JSON.stringify({ userId: bootstrap.userId, practiceId: bootstrap.practiceId, preferences: next })
          });
          var result = await response.json();
          if (!response.ok || !result.success || result.userId !== bootstrap.userId || result.practiceId !== bootstrap.practiceId) throw new Error('Save failed');
          failed = false;
          showError(null);
        } catch (e) { failed = true; showError('filters.save_failed'); }
        finally { clearTimeout(timeout); }
      }
      return !failed;
    })().finally(function () { saving = null; });
    return saving;
  }
  function summary() {
    return criteria.length ? tr('filters.sort_order') + ': ' + criteria.map(function (c, i) { return (i + 1) + '. ' + label(c.field) + ', ' + directions(c.field)[c.direction]; }).join('; ') : tr('filters.default_sort_description');
  }
  function indicator() {
    var active = !!query() || criteria.length > 0;
    var dot = document.getElementById('kanbanFilterActiveDot');
    if (dot) { dot.style.display = active ? 'block' : 'none'; dot.classList.toggle('active', active); }
  }
  function renderControls() {
    var container = document.getElementById('caseSortRows');
    if (!container) return;
    var rows = criteria.length ? criteria : [{ field: '', direction: 'asc' }];
    container.innerHTML = rows.map(function (c, i) {
      var choices = (i ? '' : '<option value="">' + esc(tr('filters.default_sort')) + '</option>') + availableFields().map(function (f) {
        return '<option value="' + f + '"' + (f === c.field ? ' selected' : '') + (criteria.some(function (other, j) { return j !== i && other.field === f; }) ? ' disabled' : '') + '>' + esc(label(f)) + '</option>';
      }).join('');
      var dirs = directions(c.field);
      return '<div class="case-sort-row" data-index="' + i + '"><div><label for="caseSortField' + i + '">' + esc(tr(i ? 'filters.then_by' : 'filters.sort_by')) + '</label>' +
        '<select id="caseSortField' + i + '" data-sort-field aria-describedby="caseSortGuidance">' + choices + '</select></div>' +
        '<div><label for="caseSortDirection' + i + '">' + esc(tr('filters.direction')) + '</label><select id="caseSortDirection' + i + '" data-sort-direction' + (!c.field ? ' disabled' : '') + '>' +
        ['asc', 'desc'].map(function (dir) { return '<option value="' + dir + '"' + (c.direction === dir ? ' selected' : '') + '>' + esc(dirs[dir]) + '</option>'; }).join('') + '</select></div>' +
        (i ? '<button type="button" class="filter-clear-btn" data-remove-sort aria-label="' + esc(tr('filters.remove_sort') + ' ' + (i + 1)) + '">' + esc(tr('common.remove')) + '</button>' : '') + '</div>';
    }).join('');
    document.getElementById('addCaseSort').disabled = !criteria.length || criteria.length >= 3;
    document.getElementById('caseSortSummary').textContent = summary();
    indicator();
  }
  function setSort(next) {
    if (!criteria.length) {
      document.querySelectorAll('.kanban-column-body').forEach(function (column) {
        column.querySelectorAll('.kanban-card').forEach(function (card, index) { defaultOrder.set(card, index); });
      });
    }
    criteria = normalizeSort(next);
    renderControls();
    applyBoard(true);
    window.dispatchEvent(new CustomEvent('caseSortChanged'));
    queueSave();
  }
  function headerClick(field) {
    var effective = effectiveSort('list');
    var direction = effective.length === 1 && effective[0].field === field ? (effective[0].direction === 'asc' ? 'desc' : 'asc') : defaultDirection(field);
    setSort([{ field: field, direction: direction }]);
  }
  function init() {
    criteria = normalizeSort(criteria);
    filterKeys.forEach(function (key) {
      var el = document.getElementById(key);
      if (!el) return;
      if (checks.includes(key)) el.checked = filters[key] === true;
      else {
        var saved = filters[key] || '';
        if (key === 'filterAssignedTo' && saved && !Array.from(el.options).some(function (o) { return o.value === saved; })) {
          var option = document.createElement('option'); option.value = saved; option.textContent = saved; option.title = tr('filters.saved_value'); el.appendChild(option);
        }
        el.value = saved;
      }
    });
    if (document.body.classList.contains('case-review-tracking-off')) document.getElementById('filterReviewStatus').value = '';
    renderControls();
    document.addEventListener('change', function (e) {
      if (filterKeys.includes(e.target.id)) { queueSave(); indicator(); }
      var row = e.target.closest('#caseSortRows .case-sort-row');
      if (!row) return;
      var next = getSort(), index = Number(row.dataset.index), focusId = e.target.id;
      if (e.target.matches('[data-sort-field]')) {
        if (!e.target.value) next = [];
        else next[index] = { field: e.target.value, direction: defaultDirection(e.target.value) };
      } else if (next[index]) next[index].direction = e.target.value;
      setSort(next);
      var focus = document.getElementById(focusId); if (focus) focus.focus();
    });
    document.addEventListener('input', function (e) { if (e.target.id === 'patientSearch') { queueSave(); indicator(); } });
    document.getElementById('caseSortRows').addEventListener('click', function (e) {
      var button = e.target.closest('[data-remove-sort]');
      if (!button) return;
      var next = getSort(); next.splice(Number(button.parentNode.dataset.index), 1); setSort(next); document.getElementById('addCaseSort').focus();
    });
    document.getElementById('addCaseSort').addEventListener('click', function () {
      var next = getSort(), field = availableFields().find(function (f) { return !next.some(function (c) { return c.field === f; }); });
      if (next.length >= 3 || !field) return;
      next.push({ field: field, direction: defaultDirection(field) }); setSort(next); document.getElementById('caseSortField' + (next.length - 1)).focus();
    });
    document.getElementById('resetCaseSort').addEventListener('click', function () { setSort([]); });
    document.getElementById('retryCaseViewSave').addEventListener('click', function () { queueSave(); flush(); });
    document.addEventListener('click', function (e) { if (e.target.closest('#clearFiltersBtn, .search-clear-btn')) { queueSave(); indicator(); } });
    document.addEventListener('keydown', function (e) { if (e.target.id === 'patientSearch' && e.key === 'Escape') { queueSave(); indicator(); } });
    window.addEventListener('cardsLoaded', function () { applyBoard(); indicator(); });
    window.addEventListener('cardsUpdated', function () { scheduleBoard(); if (!rendering && query() && window.applyFilters) window.applyFilters(); });
    window.addEventListener('settingsUpdated', function () {
      criteria = normalizeSort(criteria);
      var review = document.getElementById('filterReviewStatus');
      if (review && document.body.classList.contains('case-review-tracking-off')) review.value = '';
      renderControls();
      applyBoard();
      window.dispatchEvent(new CustomEvent('caseSortChanged'));
    });
    window.addEventListener('pagehide', flush);
    if (bootstrap.available === false) showError('filters.load_failed');
  }

  window.caseFilterSort = { getSort: getSort, effectiveSort: effectiveSort, setSort: setSort, headerClick: headerClick, compare: compare, sortCases: sortCases, assignedDisplay: assignedDisplay, summary: summary, rememberCard: rememberCard, applyBoard: applyBoard, withBoardRender: withBoardRender, query: query, flush: flush, indicator: indicator,
    nextRequest: function () { return ++generation; }, currentRequest: function (id) { return id === generation && !window.switchPracticeInProgress; } };
  document.addEventListener('DOMContentLoaded', init);
})();
