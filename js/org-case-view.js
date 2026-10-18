/**
 * Consolidated "All practices" case view (Scale organizations)
 *
 * Renders the organization-scoped case list inside the existing Cases tab.
 * Everything here is presentation only: api/list-org-cases.php re-derives
 * the authorized organization practice set and the live Scale entitlement
 * on every request, and every case action resolves the case's own
 * practice server-side. This file never sends a synthetic practice id.
 */
(function () {
  'use strict';

  var state = {
    active: false,
    eligible: false,
    anchorId: 0,
    practices: [],
    page: 1,
    pageSize: 50,
    totalPages: 1,
    sort: 'updated',
    dir: 'desc',
    loading: false,
    // Extra server params applied by Insights drill-downs (e.g. active_only)
    // that have no filter-bar control. Cleared when the user edits a filter.
    extraParams: {},
    // Modal-practice swap bookkeeping: when a case in a different practice
    // is opened (or a create targets another practice) we temporarily swap
    // the practice-scoped option globals + the status <select> options.
    swap: null
  };

  function readBootstrap() {
    var el = document.getElementById('caseViewBootstrap');
    if (!el) return null;
    try {
      var data = JSON.parse(el.textContent || '{}');
      return data.orgCaseView || null;
    } catch (e) {
      return null;
    }
  }

  // Expose the scope flag immediately (deferred scripts run in order, so
  // every later script - patient-search, realtime-updates, case-filter-sort
  // - can test it even before DOMContentLoaded init runs).
  var earlyBoot = readBootstrap();
  window.__orgCaseViewActive = !!(earlyBoot && earlyBoot.active);

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatDate(value) {
    if (!value) return '\u2014';
    var d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) {
      var dayMatch = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (dayMatch) {
        d = new Date(Number(dayMatch[1]), Number(dayMatch[2]) - 1, Number(dayMatch[3]));
      }
    }
    return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString();
  }

  // ---------------------------------------------------------------
  // Scope switching (the "All practices" switcher item)
  // ---------------------------------------------------------------
  function setOrgScope(enabled) {
    return fetch('api/set-case-view-scope.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
      credentials: 'same-origin',
      body: JSON.stringify({ scope: enabled ? 'organization' : 'practice' })
    }).then(function (r) { return r.json(); });
  }

  function wireSwitcher() {
    var orgItem = document.querySelector('.practice-switcher-item[data-org-scope]');
    if (!orgItem) return;
    orgItem.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (orgItem.disabled || orgItem.classList.contains('active')) return;
      orgItem.disabled = true;
      var flushed = (window.caseFilterSort && typeof window.caseFilterSort.flush === 'function')
        ? Promise.resolve(window.caseFilterSort.flush())
        : Promise.resolve();
      flushed.then(function () {
        return setOrgScope(true);
      }).then(function (data) {
        if (data && data.success) {
          window.location.reload();
        } else {
          orgItem.disabled = false;
          if (typeof showToast === 'function') {
            showToast((data && data.message) || 'Unable to open the consolidated view.', 'error');
          }
        }
      }).catch(function () {
        orgItem.disabled = false;
      });
    }, true); // capture: runs before app.js's data-practice-id handler
  }

  // ---------------------------------------------------------------
  // Filters -> query params (mirrors list-cases.php semantics)
  // ---------------------------------------------------------------
  function el(id) { return document.getElementById(id); }

  function currentFilters() {
    var f = {};
    var search = el('patientSearch');
    if (search && search.value.trim() !== '') f.search = search.value.trim();
    var caseType = el('filterCaseType');
    if (caseType && caseType.value) f.case_type = caseType.value;
    var practice = el('filterOrgPractice');
    if (practice && practice.value) f.practice = practice.value;
    var assigned = el('filterAssignedTo');
    if (assigned && assigned.value) f.assigned_to = assigned.value;
    var review = el('filterReviewStatus');
    if (review && review.value) f.review_status = review.value;
    var carrier = el('filterCarrier');
    if (carrier && carrier.value) f.carrier = carrier.value;
    if (el('filterLateCases') && el('filterLateCases').checked) f.late_only = 'true';
    if (el('filterDueSoon') && el('filterDueSoon').checked) f.due_soon = 'true';
    if (el('filterApptRisk') && el('filterApptRisk').checked) f.appt_risk_only = 'true';
    if (el('filterAtRisk') && el('filterAtRisk').checked) f.at_risk_only = 'true';
    return f;
  }

  function bindFilters() {
    var ids = ['patientSearch', 'filterCaseType', 'filterOrgPractice', 'filterAssignedTo',
               'filterReviewStatus', 'filterCarrier', 'filterLateCases', 'filterDueSoon',
               'filterApptRisk', 'filterAtRisk'];
    var debounce = null;
    ids.forEach(function (id) {
      var node = el(id);
      if (!node) return;
      var evt = (node.tagName === 'INPUT' && node.type === 'text') ? 'input' : 'change';
      node.addEventListener(evt, function () {
        if (id === 'filterOrgPractice') persistPracticeFilter(node.value);
        state.extraParams = {}; // user-edited filters supersede drill-down params
        clearTimeout(debounce);
        debounce = setTimeout(function () { state.page = 1; load(); }, evt === 'input' ? 300 : 0);
      });
    });

    var clearBtn = el('clearFiltersBtn');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        var practice = el('filterOrgPractice');
        if (practice) { practice.value = ''; persistPracticeFilter(''); }
        // Let the existing clear handler reset its own controls first.
        setTimeout(function () { state.page = 1; load(); }, 0);
      });
    }
  }

  // The practice-filter selection is tab-local and keyed to the user +
  // anchor practice so a different user/org or a switched practice can
  // never inherit a filter for a practice they cannot see.
  function practiceFilterKey() {
    return 'orgPracticeFilter.' + state.anchorId;
  }
  function persistPracticeFilter(value) {
    try { sessionStorage.setItem(practiceFilterKey(), value || ''); } catch (e) {}
  }
  function restorePracticeFilter() {
    var practice = el('filterOrgPractice');
    if (!practice) return;
    try {
      var saved = sessionStorage.getItem(practiceFilterKey());
      if (saved && practice.querySelector('option[value="' + saved + '"]')) {
        practice.value = saved;
      }
    } catch (e) {}
  }

  // ---------------------------------------------------------------
  // List loading + rendering
  // ---------------------------------------------------------------
  function load() {
    if (state.loading) return;
    state.loading = true;
    var tbody = el('orgCasesTableBody');
    if (tbody && state.page === 1) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + escapeHtml(t('common.loading')) + '</td></tr>';
    }

    var params = new URLSearchParams(currentFilters());
    Object.keys(state.extraParams || {}).forEach(function (k) {
      if (state.extraParams[k]) params.set(k, String(state.extraParams[k]));
    });
    params.set('page', String(state.page));
    params.set('pageSize', String(state.pageSize));
    params.set('sort', state.sort);
    params.set('dir', state.dir);

    fetch('api/list-org-cases.php?' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        state.loading = false;
        var data = res.data || {};
        if (!res.ok || !data.success) {
          if (data && data.error_code === 'ORG_VIEW_UNAVAILABLE') {
            // Scope collapsed (revoked access / lost entitlement): the
            // session flag was cleared server-side - reload into the
            // normal single-practice view.
            window.location.reload();
            return;
          }
          renderError((data && data.message) || t('cases.load_error'));
          return;
        }
        state.reviewTrackingAny = !!data.reviewTrackingAny;
        renderCounts(data.counts || {});
        renderAssigneeOptions(data.assigneeOptions || []);
        renderRows(data.cases || []);
        renderPagination(data);
      })
      .catch(function () {
        state.loading = false;
        renderError(t('cases.load_error'));
      });
  }

  function renderError(message) {
    var tbody = el('orgCasesTableBody');
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + escapeHtml(message || 'Error') + '</td></tr>';
    }
  }

  function renderCounts(counts) {
    var set = function (id, v) { var n = el(id); if (n) n.textContent = String(v || 0); };
    set('orgStatOverdue', counts.overdue);
    set('orgStatDueSoon', counts.dueSoon);
    // "Awaiting action" intentionally absent - the codebase has no
    // established definition for it. Needs-review is shown only when at
    // least one practice tracks review state.
    var needsReviewWrap = document.querySelector('.org-stat-needs-review');
    if (needsReviewWrap) {
      needsReviewWrap.style.display = state.reviewTrackingAny ? '' : 'none';
    }
    if (typeof counts.dueSoonDays === 'number') {
      var label = document.querySelector('.org-stat-due-soon .org-stat-label');
      if (label) label.textContent = t('org_view.due_soon_nd', { count: counts.dueSoonDays });
    }
  }

  function renderAssigneeOptions(groups) {
    var select = el('filterAssignedTo');
    if (!select) return;
    var current = select.value;
    var html = '<option value="">' + escapeHtml(t('filters.anyone')) + '</option>';
    groups.forEach(function (g) {
      if (!g.options || !g.options.length) return;
      html += '<optgroup label="' + escapeHtml(g.practiceName) + '">';
      g.options.forEach(function (o) {
        // Composite value "<practiceId>|<raw>" keeps identically named
        // assignees/labels in different practices distinct end-to-end.
        html += '<option value="' + escapeHtml(g.practiceId + '|' + o.value) + '">' +
                escapeHtml(o.value) + (o.kind === 'label' ? ' (' + escapeHtml(t('assignments.assignment_labels')) + ')' : '') +
                '</option>';
      });
      html += '</optgroup>';
    });
    select.innerHTML = html;
    if (current) select.value = current;
  }

  function renderRows(cases) {
    var tbody = el('orgCasesTableBody');
    if (!tbody) return;
    if (!cases.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="loading-row">' + escapeHtml(t('org_view.empty')) + '</td></tr>';
      return;
    }
    tbody.innerHTML = cases.map(function (c) {
      var statusLabel = c.statusLabel || c.status || '';
      var assigned = c.assignedTo ? String(c.assignedTo).split('@')[0] : '';
      var classes = 'org-case-row';
      if (c.atRisk && c.atRisk.isAtRisk) classes += ' org-case-at-risk';
      return '<tr class="' + classes + '" data-case-id="' + escapeHtml(c.id) + '" tabindex="0" role="button"' +
             ' aria-label="' + escapeHtml((c.patientFirstName || '') + ' ' + (c.patientLastName || '')) + '">' +
        '<td class="org-cell-patient" data-label="' + escapeHtml(t('archive.fields.patient_name')) + '">' + escapeHtml((c.patientFirstName || '') + ' ' + (c.patientLastName || '')).trim() + '</td>' +
        '<td class="org-cell-practice" data-label="' + escapeHtml(t('org_view.practice')) + '"><span class="org-practice-badge">' + escapeHtml(c.practiceName || '') + '</span></td>' +
        '<td data-label="' + escapeHtml(t('archive.fields.case_type')) + '">' + escapeHtml(typeof getCaseTypeDisplayLabel === 'function' ? (getCaseTypeDisplayLabel(c.caseType) || c.caseType || '') : (c.caseType || '')) + '</td>' +
        '<td data-label="' + escapeHtml(t('archive.fields.status')) + '">' + escapeHtml(statusLabel) + '</td>' +
        '<td data-label="' + escapeHtml(t('archive.fields.dentist')) + '">' + escapeHtml(c.dentistName || '') + '</td>' +
        '<td data-label="' + escapeHtml(t('cases.due_date')) + '">' + escapeHtml(formatDate(c.dueDate)) + '</td>' +
        '<td data-label="' + escapeHtml(t('cases.assigned_to')) + '">' + escapeHtml(assigned) + '</td>' +
      '</tr>';
    }).join('');

    Array.prototype.forEach.call(tbody.querySelectorAll('.org-case-row'), function (row, i) {
      var practiceId = cases[i] ? cases[i].practiceId : null;
      var open = function () {
        var caseId = row.getAttribute('data-case-id');
        if (caseId) openCaseInPracticeContext(caseId, practiceId);
      };
      row.addEventListener('click', open);
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
  }

  function renderPagination(data) {
    var wrap = el('orgPagination');
    if (!wrap) return;
    state.totalPages = data.totalPages || 1;
    state.page = data.page || 1;
    var total = data.total || 0;
    wrap.hidden = total === 0;
    var info = el('orgPageInfo');
    if (info) {
      info.textContent = t('archive.pagination.page_info', { current: state.page, total: state.totalPages }) +
        ' \u00b7 ' + total + ' ' + t('org_view.total_cases');
    }
    var prev = el('orgPrevPage');
    var next = el('orgNextPage');
    if (prev) prev.disabled = state.page <= 1;
    if (next) next.disabled = state.page >= state.totalPages;
    var totalEl = el('orgStatTotal');
    if (totalEl) totalEl.textContent = String(total);
  }

  function bindPaginationAndSort() {
    var prev = el('orgPrevPage');
    var next = el('orgNextPage');
    var size = el('orgPageSize');
    if (prev) prev.addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
    if (next) next.addEventListener('click', function () { if (state.page < state.totalPages) { state.page++; load(); } });
    if (size) size.addEventListener('change', function () { state.pageSize = parseInt(size.value, 10) || 50; state.page = 1; load(); });

    Array.prototype.forEach.call(document.querySelectorAll('.org-sort'), function (btn) {
      btn.addEventListener('click', function () {
        var field = btn.getAttribute('data-sort');
        if (state.sort === field) {
          state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = field;
          state.dir = 'asc';
        }
        state.page = 1;
        load();
      });
    });
  }

  // ---------------------------------------------------------------
  // Practice-scoped option swap for cross-practice case opens
  // ---------------------------------------------------------------
  function fetchPracticeOptions(practiceId) {
    return fetch('api/get-org-practice-options.php?practice_id=' + encodeURIComponent(practiceId), {
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function applyOptionSwap(opts) {
    if (!state.swap) {
      state.swap = {
        adminUsers: window.adminUsers,
        gmailUsers: window.gmailUsers,
        assignmentLabels: window.assignmentLabels,
        statusHtml: null
      };
      var statusSelect = el('status');
      state.swap.statusHtml = statusSelect ? statusSelect.innerHTML : null;
    }
    window.adminUsers = opts.adminUsers || [];
    window.gmailUsers = opts.gmailUsers || [];
    window.assignmentLabels = opts.assignmentLabels || [];

    var statusSelect = el('status');
    if (statusSelect && Array.isArray(opts.statusOptions)) {
      var html = '<option value="">' + escapeHtml(t('select.select_status')) + '</option>';
      opts.statusOptions.forEach(function (s) {
        html += '<option value="' + escapeHtml(s.id) + '">' + escapeHtml(s.label) + '</option>';
      });
      statusSelect.innerHTML = html;
    }
  }

  function restoreOptionSwap() {
    if (!state.swap) return;
    window.adminUsers = state.swap.adminUsers;
    window.gmailUsers = state.swap.gmailUsers;
    window.assignmentLabels = state.swap.assignmentLabels;
    var statusSelect = el('status');
    if (statusSelect && state.swap.statusHtml !== null) {
      statusSelect.innerHTML = state.swap.statusHtml;
    }
    state.swap = null;
    window.__orgCaseModalPracticeId = null;
  }

  function openCaseInPracticeContext(caseId, practiceId) {
    var pid = parseInt(practiceId, 10);
    if (pid && pid !== state.anchorId) {
      fetchPracticeOptions(pid).then(function (opts) {
        if (opts && opts.success) {
          applyOptionSwap(opts);
          window.__orgCaseModalPracticeId = pid;
        }
        if (typeof window.openCaseById === 'function') window.openCaseById(caseId);
      }).catch(function () {
        if (typeof window.openCaseById === 'function') window.openCaseById(caseId);
      });
    } else {
      window.__orgCaseModalPracticeId = null;
      if (typeof window.openCaseById === 'function') window.openCaseById(caseId);
    }
  }

  // ---------------------------------------------------------------
  // Create flow: explicit practice chooser - never silently picks one
  // ---------------------------------------------------------------
  function buildPracticeChooser() {
    var wrap = el('orgCreatePracticeWrap');
    var select = el('orgCreatePractice');
    if (!wrap || !select) return null;
    var editable = state.practices.filter(function (p) { return p.isMember && p.canEditCases; });
    select.innerHTML = '<option value="">' + escapeHtml(t('org_view.select_practice')) + '</option>' +
      editable.map(function (p) {
        return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
      }).join('');
    return { wrap: wrap, select: select, editable: editable };
  }

  function onCreatePracticeChange() {
    var select = el('orgCreatePractice');
    if (!select) return;
    var pid = parseInt(select.value, 10);
    if (!pid) { restoreOptionSwap(); return; }
    fetchPracticeOptions(pid).then(function (opts) {
      if (opts && opts.success) {
        applyOptionSwap(opts);
        window.__orgCaseModalPracticeId = pid;
        var statusSelect = el('status');
        if (statusSelect && opts.statusOptions && opts.statusOptions.length) {
          statusSelect.value = opts.statusOptions[0].id;
        }
        var assignedTo = el('assignedTo');
        if (assignedTo && typeof initializeAssignmentDropdown === 'function') {
          initializeAssignmentDropdown(assignedTo, '', '');
        }
      }
    });
  }

  function wireCreateFlow() {
    var createBtn = document.querySelector('.create-case-button');
    if (!createBtn) return;

    createBtn.addEventListener('click', function (e) {
      if (!state.active) return; // single-practice mode - normal flow
      // The app.js listener opens the modal directly; in org scope we must
      // first show the practice chooser, so intercept once and re-fire.
      if (createBtn.dataset.orgChooserReady === '1') {
        delete createBtn.dataset.orgChooserReady;
        return; // let the normal handler open the modal
      }
      e.preventDefault();
      e.stopImmediatePropagation();

      var chooser = buildPracticeChooser();
      if (!chooser || !chooser.editable.length) {
        if (typeof showToast === 'function') {
          showToast(t('org_view.no_editable_practice'), 'error');
        }
        return;
      }
      if (chooser.editable.length === 1) {
        // Exactly one editable practice: still show it explicitly inside
        // the modal (never silently implied by scope alone), preselected.
        prepareOrgCreate(chooser.editable[0].id, true);
        return;
      }
      showCreateChooser(chooser.editable);
    }, true);

    var select = el('orgCreatePractice');
    if (select) select.addEventListener('change', onCreatePracticeChange);
  }

  function showCreateChooser(editable) {
    // Lightweight chooser: reuse the switcher dropdown visual language.
    var existing = document.getElementById('orgCreateChooser');
    if (existing) existing.remove();
    var menu = document.createElement('div');
    menu.id = 'orgCreateChooser';
    menu.className = 'org-create-chooser';
    menu.setAttribute('role', 'menu');
    menu.innerHTML = '<div class="org-create-chooser-title">' + escapeHtml(t('org_view.create_in_practice')) + '</div>' +
      editable.map(function (p) {
        return '<button type="button" role="menuitem" class="practice-switcher-item" data-pid="' + p.id + '">' +
               '<span class="practice-item-name">' + escapeHtml(p.name) + '</span></button>';
      }).join('');
    var createBtn = document.querySelector('.create-case-button');
    createBtn.parentNode.appendChild(menu);
    Array.prototype.forEach.call(menu.querySelectorAll('[data-pid]'), function (btn) {
      btn.addEventListener('click', function () {
        var pid = parseInt(btn.getAttribute('data-pid'), 10);
        menu.remove();
        prepareOrgCreate(pid, false);
      });
    });
    setTimeout(function () {
      document.addEventListener('click', function closer(ev) {
        if (!menu.contains(ev.target)) { menu.remove(); document.removeEventListener('click', closer); }
      });
    }, 0);
  }

  function prepareOrgCreate(practiceId, skipChooser) {
    var wrap = el('orgCreatePracticeWrap');
    var select = el('orgCreatePractice');
    buildPracticeChooser();
    if (select) {
      select.disabled = false;
      select.value = String(practiceId);
    }
    if (wrap) wrap.hidden = false;
    onCreatePracticeChange();
    var createBtn = document.querySelector('.create-case-button');
    createBtn.dataset.orgChooserReady = '1';
    createBtn.click();
  }

  // ---------------------------------------------------------------
  // Lifecycle: refresh + option restore when the case modal closes,
  // and refresh when realtime updates touch the (hidden) board.
  // ---------------------------------------------------------------
  function wireLifecycle() {
    var modal = el('createCaseModal');
    if (modal) {
      var observer = new MutationObserver(function () {
        if (modal.style.display === 'none') {
          var hadSwap = !!state.swap;
          restoreOptionSwap();
          var wrap = el('orgCreatePracticeWrap');
          var practiceSelect = el('orgCreatePractice');
          if (wrap) wrap.hidden = true;
          if (practiceSelect) practiceSelect.disabled = true;
          if (hadSwap || state.active) load();
        }
      });
      observer.observe(modal, { attributes: true, attributeFilter: ['style'] });
    }
    // Other users' updates arrive via realtime-updates.js -> cardsUpdated,
    // or via the org-aware delegate below.
    window.addEventListener('cardsUpdated', function () {
      if (state.active) load();
    });
    window.orgCaseViewRefresh = function () { load(); };

    // Insights drill-down: activate the matching filter state and reload.
    // opts: {practiceId, lateOnly, dueSoonOnly, activeOnly} - the resulting
    // list reproduces the metric's scope and definition exactly.
    window.orgCaseViewDrillDown = function (opts) {
      if (!state.active) return;
      opts = opts || {};
      var practice = el('filterOrgPractice');
      if (practice) {
        practice.value = opts.practiceId ? String(opts.practiceId) : '';
        persistPracticeFilter(practice.value);
      }
      var late = el('filterLateCases'); if (late) late.checked = !!opts.lateOnly;
      var soon = el('filterDueSoon'); if (soon) soon.checked = !!opts.dueSoonOnly;
      state.extraParams = {};
      if (opts.activeOnly) state.extraParams.active_only = 'true';
      state.page = 1;
      load();
    };
  }

  // ---------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------
  function init() {
    var org = readBootstrap();
    if (!org || !org.eligible) return;

    state.eligible = !!org.eligible;
    state.active = !!org.active;
    state.anchorId = org.anchorId || 0;
    state.practices = org.practices || [];
    window.__orgCaseViewActive = state.active;

    wireSwitcher();
    if (!state.active) return;

    // View-mode toggles (board/compact/list) project kanban cards; the
    // consolidated view renders its own scoped list instead, so the toggle
    // is disabled with an explanation rather than implying board support.
    var toggle = document.querySelector('.case-view-toggle');
    if (toggle) {
      toggle.classList.add('case-view-toggle-disabled');
      toggle.setAttribute('title', t('org_view.view_modes_note'));
      Array.prototype.forEach.call(toggle.querySelectorAll('.case-view-btn'), function (b) {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
      });
    }

    // Populate the authorized-practice filter.
    var practiceSelect = el('filterOrgPractice');
    if (practiceSelect) {
      state.practices.forEach(function (p) {
        var opt = document.createElement('option');
        opt.value = String(p.id);
        opt.textContent = p.name;
        practiceSelect.appendChild(opt);
      });
      restorePracticeFilter();
    }

    bindFilters();
    bindPaginationAndSort();
    wireCreateFlow();
    wireLifecycle();
    load();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
