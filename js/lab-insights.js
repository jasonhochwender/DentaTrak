/**
 * Lab Insights - client-side rendering only.
 *
 * All metrics are computed authoritatively server-side in
 * api/get-lab-insights.php. This file fetches, renders, sorts (client-side,
 * over already-fetched data - no extra requests), and manages empty states.
 *
 * Two layouts:
 *  - Performance layout (default): driven by the additive `performance`
 *    payload from api/lab-performance-metrics.php (volume / turnaround /
 *    on-time / remakes / trends / benchmarks / coverage).
 *  - Legacy layout: rendered when `performance` is null so the page keeps
 *    working if the metrics layer ever fails.
 *
 * Control-only gating reuses the exact same [data-control-feature] blur
 * mechanism as Practice Insights (js/analytics-pro.js's
 * applyTierBasedVisibility, which generically queries
 * `[data-control-feature]` - no changes needed there for this to work).
 */
(function () {
  'use strict';

  var liChart = null;
  var liLabs = [];
  var liSort = { key: 'currentWorkload', dir: 'desc' };
  var liExpandedLabKey = null;
  var liWorkloadByLab = {};

  var liPerf = null;            // last performance payload
  var liPerfLabs = [];          // performance.labs (sorted view)
  var liPerfSort = { key: 'workload', dir: 'desc' };
  var liSelectedLab = null;     // labKey or 'practice'
  var liCaseType = '';          // '' = all case types
  var liCompare = [];           // up to 2 labKeys selected for comparison
  var liCompareMetric = 'turnaround';
  var LI_COMPARE_MAX = 2;
  var liTrendMetric = 'turnaroundDays';
  var liTrendGranularity = 'auto';   // auto | week | month | quarter
  var liPerfChart = null;

  function fmtDays(value) {
    if (value === null || value === undefined) { return '—'; }
    return I18n.pluralize(value, 'insights.metrics.days');
  }

  function fmtDaysShort(value) {
    if (value === null || value === undefined) { return '—'; }
    return I18n.pluralize(value, 'insights.metrics.days');
  }

  function fmtPercent(value) {
    if (value === null || value === undefined) { return '—'; }
    return value + '%';
  }

  function fmtCount(value) {
    return (value === null || value === undefined) ? '0' : String(value);
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function setChartAriaLabel(canvas, title, labels, values, valueFormatter) {
    if (!canvas) { return; }
    var fmt = valueFormatter || function(v) { return String(v); };
    var summary = (labels || []).map(function(label, i) {
      var val = (values && typeof values[i] !== 'undefined') ? fmt(values[i]) : '';
      return label + ': ' + val;
    }).join('; ');
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', title + '. ' + summary);
  }

  function attachTooltip(containerEl, text, alignRight) {
    if (!containerEl || typeof window.createInfoTooltip !== 'function') { return; }
    containerEl.appendChild(window.createInfoTooltip(text, !!alignRight));
  }

  function setLoading(isLoading) {
    var el = document.getElementById('liLoading');
    if (el) { el.style.display = isLoading ? 'flex' : 'none'; }
  }

  function setError(message) {
    var el = document.getElementById('liError');
    var text = document.getElementById('liErrorText');
    if (el) { el.style.display = message ? 'flex' : 'none'; }
    if (text && message) { text.textContent = message; }
  }

  function showEmptyStates(hasLabs, hasHistory) {
    var noLabs = document.getElementById('liNoLabsEmptyState');
    var noHistory = document.getElementById('liNoHistoryEmptyState');
    var content = document.getElementById('liContent');

    if (!hasLabs) {
      if (noLabs) { noLabs.style.display = 'block'; }
      if (noHistory) { noHistory.style.display = 'none'; }
      if (content) { content.style.display = 'none'; }
      return;
    }

    if (!hasHistory) {
      if (noLabs) { noLabs.style.display = 'none'; }
      if (noHistory) { noHistory.style.display = 'block'; }
      if (content) { content.style.display = 'none'; }
      return;
    }

    if (noLabs) { noLabs.style.display = 'none'; }
    if (noHistory) { noHistory.style.display = 'none'; }
    if (content) { content.style.display = 'block'; }
  }

  function clearLabTable() {
    liLabs = [];
    liWorkloadByLab = {};
    liExpandedLabKey = null;
    var tbody = document.getElementById('liLabTableBody');
    if (tbody) { tbody.innerHTML = ''; }
    renderSortIndicators();
  }

  // ======================================================================
  // Operational KPI summary + Lab Case Health (current-state top section)
  // ======================================================================

  /**
   * Navigate from a Lab Insights KPI card / Case Health segment to the
   * Cases tab with the lab scope + matching risk filter applied. Mirrors
   * analytics-pro.js navigateToFilteredCases but always sets the hidden
   * filterLabCases scope so the destination is lab cases only.
   * 'lab_cases' leaves every risk filter cleared.
   */
  function liNavigateToCases(target, labKey) {
    var casesTab = document.querySelector('.main-tab[data-tab="cases"]');
    if (casesTab) { casesTab.click(); }

    // Optional single-laboratory scope (Lab Insights labKey). Always reset
    // first so KPI/health/queue navigation without a lab clears any stale
    // drill-down scope left by the comparison table.
    var labKeyInput = document.getElementById('filterLabKey');
    if (labKeyInput) { labKeyInput.value = labKey || ''; }

    var setCheck = function (id, checked) {
      var el = document.getElementById(id);
      if (el) { el.checked = checked; }
      return el || null;
    };
    ['filterLateCases', 'filterDueSoon', 'filterApptRisk', 'filterAtRisk',
     'filterAwaitingAck', 'filterNeedsInfo', 'filterDeliveredMonth',
     'filterAttentionOnly'].forEach(function (id) {
      setCheck(id, false);
    });

    var labEl = setCheck('filterLabCases', true);
    var targetEl = null;
    if (target === 'late') { targetEl = setCheck('filterLateCases', true); }
    else if (target === 'due_soon') { targetEl = setCheck('filterDueSoon', true); }
    else if (target === 'appt_risk') { targetEl = setCheck('filterApptRisk', true); }
    else if (target === 'awaiting_ack') { targetEl = setCheck('filterAwaitingAck', true); }
    // 'lab_cases' intentionally leaves every risk filter cleared.

    var changeSource = targetEl || labEl || document.getElementById('filterLateCases');
    if (changeSource) {
      changeSource.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (typeof window.applyFilters === 'function') {
      window.applyFilters();
    }
  }

  /** Turnaround / On-Time KPI cards drill into the Lab Detail analysis. */
  function liNavigateToPerfDetail() {
    selectLab('practice');
  }

  function liSetText(id, text) {
    var el = document.getElementById(id);
    if (el) { el.textContent = text; }
  }

  function liSetHtml(id, html) {
    var el = document.getElementById(id);
    if (el) { el.innerHTML = html; }
  }

  /**
   * Render the four operational KPI cards + Lab Case Health stacked bar.
   * Returns true when the operational payload was rendered. Current-state
   * metrics (active/overdue/health) come from data.operational and are
   * never range-filtered; turnaround/on-time come from
   * performance.practice (selected reporting period) with a legacy
   * summary fallback.
   */
  function renderOperationalSummary(data) {
    var ops = document.getElementById('liOps');
    if (!ops) { return false; }
    var o = data && data.operational;
    if (!o) {
      ops.style.display = 'none';
      // Restore the perf-layout summary strip if a previous payload hid it.
      var ps = document.getElementById('liPerfSummary');
      var pn = document.getElementById('liPerfNow');
      if (ps) { ps.style.display = ''; }
      if (pn) { pn.style.display = ''; }
      return false;
    }
    ops.style.display = 'block';

    // The new KPI row + health bar replace the perf layout's five-card
    // summary and the "right now" chip strip (open cases is now the Active
    // Lab Cases KPI; open remakes remains in Lab Detail > Remakes).
    var ps = document.getElementById('liPerfSummary');
    var pn = document.getElementById('liPerfNow');
    if (ps) { ps.style.display = 'none'; }
    if (pn) { pn.style.display = 'none'; }

    var health = o.caseHealth || {};
    var active = o.activeLabCases || 0;
    var overdue = o.overdueLabCases || 0;

    // ── KPI cards ──
    liSetText('liKpiActiveCasesValue', fmtCount(active));
    liSetText('liKpiOverdueValue', fmtCount(overdue));

    var overdueCard = document.getElementById('liKpiOverdue');
    if (overdueCard) {
      overdueCard.classList.toggle('ap-kpi-card--alert-active', overdue > 0);
    }
    liSetText('liKpiOverdueSub',
      (o.overduePct === null || o.overduePct === undefined)
        ? t('insights.labkpi.require_attention')
        : t('insights.kpi.overdue_pct_of_active', { pct: String(o.overduePct) }));

    var perfPractice = (data.performance && data.performance.practice) || null;
    var ta = perfPractice ? (perfPractice.turnaround || {}) : null;
    var ot = perfPractice ? (perfPractice.onTime || {}) : null;

    if (ta) {
      liSetHtml('liKpiTurnaroundValue',
        (ta.avgDays === null || ta.avgDays === undefined)
          ? '<span class="li-muted">—</span>'
          : escapeHtml(fmtDays(ta.avgDays)));
      liSetText('liKpiTurnaroundSub', I18n.pluralize(ta.n || 0, 'insights.perf.context.based_on_cases'));
    } else {
      // Legacy fallback: range-filtered global turnaround, no sample size.
      var sd = data.summary || {};
      liSetText('liKpiTurnaroundValue',
        (sd.avgTurnaroundDays === null || sd.avgTurnaroundDays === undefined)
          ? '—' : fmtDays(sd.avgTurnaroundDays));
      liSetText('liKpiTurnaroundSub', t('insights.labkpi.selected_period'));
    }

    if (ot) {
      liSetHtml('liKpiOnTimeValue',
        (ot.pct === null || ot.pct === undefined)
          ? '<span class="li-muted">—</span>'
          : escapeHtml(fmtPercent(ot.pct)));
      liSetText('liKpiOnTimeSub', I18n.pluralize(ot.n || 0, 'insights.perf.context.based_on_cases'));
    } else {
      liSetText('liKpiOnTimeValue', '—');
      liSetText('liKpiOnTimeSub', t('insights.labkpi.selected_period'));
    }

    // ── Lab Case Health stacked bar (mutually-exclusive buckets; each
    //    active lab case counted exactly once, server-side) ──
    var onTrack = health.onTrack || 0;
    var dueSoon = health.dueSoon || 0;
    var apptRisk = health.appointmentRisk || 0;
    var late = health.overdue || 0;
    var flowTotal = onTrack + dueSoon + apptRisk + late;

    var healthCard = document.getElementById('liHealthCard');
    if (healthCard) {
      healthCard.classList.toggle('ap-health-card--empty', flowTotal === 0);
    }
    var pct = function (n) { return flowTotal > 0 ? (n / flowTotal) * 100 : 0; };
    var widths = {
      liHealthOnTrack: pct(onTrack),
      liHealthDueSoon: pct(dueSoon),
      liHealthApptRisk: pct(apptRisk),
      liHealthOverdue: pct(late)
    };
    Object.keys(widths).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.style.width = widths[id] + '%'; }
    });
    liSetText('liHealthOnTrackCount', fmtCount(onTrack));
    liSetText('liHealthDueSoonCount', fmtCount(dueSoon));
    liSetText('liHealthApptRiskCount', fmtCount(apptRisk));
    liSetText('liHealthOverdueCount', fmtCount(late));

    // Exact-value tooltips on the interactive segments + legend links.
    var segLabels = {
      liHealthApptRisk: t('insights.sections.appointment_risk'),
      liHealthOverdue: t('insights.sections.late')
    };
    Object.keys(segLabels).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.title = segLabels[id] + ': ' + fmtCount(
          id === 'liHealthApptRisk' ? apptRisk : late) +
          ' (' + Math.round(pct(id === 'liHealthApptRisk' ? apptRisk : late)) + '%)';
        el.setAttribute('aria-label',
          segLabels[id] + ': ' + fmtCount(id === 'liHealthApptRisk' ? apptRisk : late));
      }
    });

    liSetText('liHealthOverduePct',
      (o.overduePct === null || o.overduePct === undefined)
        ? ''
        : t('insights.kpi.overdue_pct_of_active', { pct: String(o.overduePct) }));

    return true;
  }

  /** Bind KPI/health navigation once (elements are static markup). */
  var liOpsNavBound = false;
  function bindOpsNavigation() {
    if (liOpsNavBound) { return; }
    liOpsNavBound = true;
    var kpiMap = {
      liKpiActiveCases: 'lab_cases',
      liKpiOverdue: 'late',
      liKpiTurnaround: 'perf',
      liKpiOnTime: 'perf'
    };
    Object.keys(kpiMap).forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) { return; }
      el.addEventListener('click', function () {
        if (kpiMap[id] === 'perf') { liNavigateToPerfDetail(); }
        else { liNavigateToCases(kpiMap[id]); }
      });
    });
    var healthMap = {
      liHealthApptRisk: 'appt_risk',
      liHealthOverdue: 'late',
      liHealthApptRiskLegend: 'appt_risk',
      liHealthOverdueLegend: 'late'
    };
    Object.keys(healthMap).forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) { return; }
      var go = function () { liNavigateToCases(healthMap[id]); };
      el.addEventListener('click', go);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      });
    });
  }

  // ======================================================================
  // Lab Cases Requiring Attention queue
  // Items arrive pre-classified from api/get-lab-insights.php
  // attentionQueue (same population + thresholds as Lab Case Health).
  // `reasons` drives chip filtering; `tier` preserves server priority.
  // ======================================================================
  var liAttnState = { items: [], counts: {}, filter: 'all', sort: 'priority', lab: 'all' };
  var liAttnCountsByLab = {};   // labKey => {all,overdue,appt_risk,due_soon,awaiting_ack}
  var liAttnBound = false;
  var LI_ATTN_PREVIEW = 5;

  function liAttnChipCountKey(filter) {
    return { all: 'all', overdue: 'overdue', appt_risk: 'appt_risk', due_soon: 'due_soon', awaiting_ack: 'awaiting_ack' }[filter] || 'all';
  }

  function liAttnFilterMatch(item, filter) {
    // Classifier reasons use 'late'; the chip id is 'overdue'.
    var reasonCat = filter === 'overdue' ? 'late' : filter;
    if (filter !== 'all' && (item.reasons || []).indexOf(reasonCat) === -1) { return false; }
    if (liAttnState.lab !== 'all' && String(item.labKey) !== String(liAttnState.lab)) { return false; }
    return true;
  }

  function liAttnStatusLabel(status) {
    if (typeof window.getStageLabel === 'function') { return window.getStageLabel(status); }
    return status || '';
  }

  function liAttnCaseTypeLabel(caseType) {
    if (caseType && typeof window.getCaseTypeDisplayLabel === 'function') {
      return window.getCaseTypeDisplayLabel(caseType);
    }
    return caseType || '';
  }

  /** Ordered reason entries: primary category first, secondaries after. */
  function liAttnReasonEntries(item) {
    var entries = [];
    var has = function (cat) { return (item.reasons || []).indexOf(cat) !== -1; };
    var daysUntilDue = item.daysUntilDue;
    var daysUntilAppt = item.daysUntilAppt;

    if (has('late') && daysUntilDue !== null && daysUntilDue !== undefined) {
      var overdueDays = Math.max(0, -daysUntilDue);
      entries.push({ cat: 'late', text: I18n.pluralize(overdueDays, 'insights.attention.reason_late') });
    }
    if (has('appt_risk') && daysUntilAppt !== null && daysUntilAppt !== undefined) {
      var apptText;
      if (daysUntilAppt < 0) {
        apptText = I18n.pluralize(-daysUntilAppt, 'insights.attention.reason_appt_missed');
      } else if (daysUntilAppt === 0) {
        apptText = t('insights.attention.reason_appt_today');
      } else {
        apptText = I18n.pluralize(daysUntilAppt, 'insights.attention.reason_appt_in');
      }
      entries.push({ cat: 'appt_risk', text: apptText });
    }
    if (has('due_soon') && daysUntilDue !== null && daysUntilDue !== undefined) {
      var dueText = daysUntilDue === 0
        ? t('insights.attention.reason_due_today')
        : I18n.pluralize(daysUntilDue, 'insights.attention.reason_due_in');
      entries.push({ cat: 'due_soon', text: dueText });
    }
    if (has('awaiting_ack')) {
      entries.push({ cat: 'awaiting_ack', text: t('insights.attention.reason_awaiting_ack') });
    }

    var primaryCat = {
      late_appt: 'late', appt_risk: 'appt_risk', late: 'late',
      due_soon: 'due_soon', awaiting_ack: 'awaiting_ack'
    }[item.primaryReason];
    if (primaryCat) {
      entries.sort(function (a, b) {
        if (a.cat === primaryCat) { return -1; }
        if (b.cat === primaryCat) { return 1; }
        return 0;
      });
    }
    return entries;
  }

  function liAttnSortValue(item, sort) {
    switch (sort) {
      case 'due_date':
        return item.daysUntilDue === null || item.daysUntilDue === undefined ? Infinity : item.daysUntilDue;
      case 'appt_date':
        return item.daysUntilAppt === null || item.daysUntilAppt === undefined ? Infinity : item.daysUntilAppt;
      case 'days_overdue': {
        var overdue = item.daysUntilDue === null || item.daysUntilDue === undefined ? -Infinity : Math.max(0, -item.daysUntilDue);
        return -overdue;
      }
      case 'lab':
        return String(item.lab || '').toLowerCase() || '￿';
      default:
        return item.tier || 99;
    }
  }

  function liAttnSortedItems() {
    var items = liAttnState.items.filter(function (item) {
      return liAttnFilterMatch(item, liAttnState.filter);
    });
    if (liAttnState.sort === 'priority') { return items; } // server order
    return items.slice().sort(function (a, b) {
      var av = liAttnSortValue(a, liAttnState.sort);
      var bv = liAttnSortValue(b, liAttnState.sort);
      if (av === bv) { return (a.tier || 99) - (b.tier || 99); }
      return av < bv ? -1 : 1;
    });
  }

  function liAttnOpenCase(item) {
    if (typeof window.openCaseById === 'function') {
      window.openCaseById(item.id);
    }
  }

  function liAttnSetEmpty(hasAny, hasFilterMatch) {
    var empty = document.getElementById('liAttnEmpty');
    if (!empty) { return; }
    empty.hidden = false;
    liSetText('liAttnEmptyTitle', t(hasAny && !hasFilterMatch
      ? 'insights.labattn.empty_filter_title' : 'insights.labattn.empty_title'));
    liSetText('liAttnEmptySub', t(hasAny && !hasFilterMatch
      ? 'insights.labattn.empty_filter_subtitle' : 'insights.labattn.empty_subtitle'));
    var clearBtn = document.getElementById('liAttnClearFilters');
    if (clearBtn) { clearBtn.hidden = !(hasAny && !hasFilterMatch); }
  }

  function renderLiAttnRows() {
    var body = document.getElementById('liAttnBody');
    var wrap = document.getElementById('liAttnTableWrap');
    var empty = document.getElementById('liAttnEmpty');
    var footer = document.getElementById('liAttnFooter');
    var showing = document.getElementById('liAttnShowing');
    var viewAll = document.getElementById('liAttnViewAll');
    if (!body) { return; }

    var sorted = liAttnSortedItems();
    var preview = sorted.slice(0, LI_ATTN_PREVIEW);
    var totalForFilter = sorted.length;
    var hasAny = (liAttnState.counts.all || liAttnState.items.length) > 0;

    if (preview.length === 0) {
      body.innerHTML = '';
      if (wrap) { wrap.hidden = true; }
      if (empty) { liAttnSetEmpty(hasAny, totalForFilter > 0); }
      if (footer) { footer.hidden = true; }
      return;
    }

    if (wrap) { wrap.hidden = false; }
    if (empty) { empty.hidden = true; }

    body.innerHTML = preview.map(function (item) {
      var name = ((item.patientFirstName || '') + ' ' + (item.patientLastName || '')).trim();
      var typeLabel = liAttnCaseTypeLabel(item.caseType);
      var reasons = liAttnReasonEntries(item);
      var primary = reasons[0];
      var secondary = reasons.slice(1);
      var dateLabelDue = t('insights.attention.date_due');
      var dateLabelAppt = t('insights.attention.date_appt');

      var reasonHtml = primary
        ? '<span class="ap-attn-reason ap-attn-reason--' + escapeHtml(primary.cat) + '">' + escapeHtml(primary.text) + '</span>' +
          secondary.map(function (r) {
            return '<span class="ap-attn-reason-secondary">' + escapeHtml(r.text) + '</span>';
          }).join('')
        : '<span class="ap-attn-reason-secondary">' + escapeHtml(t('insights.attention.reason_generic')) + '</span>';

      var dateHtml = '';
      if (item.dueDate) {
        dateHtml += '<span class="ap-attn-date-line"><span class="ap-attn-date-label">' + escapeHtml(dateLabelDue) + '</span> ' + escapeHtml(I18n.formatDate(item.dueDate)) + '</span>';
      }
      if (item.patientAppointmentDate) {
        dateHtml += '<span class="ap-attn-date-line"><span class="ap-attn-date-label">' + escapeHtml(dateLabelAppt) + '</span> ' + escapeHtml(I18n.formatDate(item.patientAppointmentDate)) + '</span>';
      }
      if (!dateHtml) { dateHtml = '<span class="ap-attn-muted">—</span>'; }

      return '<tr class="ap-attn-row" data-li-attn-case="' + escapeHtml(item.id) + '">' +
        '<td class="ap-attn-cell-patient" data-label="' + escapeHtml(t('insights.attention.col_patient')) + '">' +
          '<button type="button" class="ap-attn-patient-link" data-li-attn-open="' + escapeHtml(item.id) + '">' +
            '<span class="ap-attn-patient">' + escapeHtml(name || '—') + '</span>' +
            (typeLabel ? '<span class="ap-attn-case-type">' + escapeHtml(typeLabel) + '</span>' : '') +
          '</button>' +
        '</td>' +
        '<td class="ap-attn-cell-assigned" data-label="' + escapeHtml(t('insights.labattn.col_lab')) + '">' +
          '<span class="ap-attn-assigned">' + escapeHtml(item.lab || '—') + '</span>' +
        '</td>' +
        '<td class="ap-attn-cell-risk" data-label="' + escapeHtml(t('insights.attention.col_risk')) + '">' + reasonHtml + '</td>' +
        '<td class="ap-attn-cell-status" data-label="' + escapeHtml(t('insights.attention.col_status')) + '">' + escapeHtml(liAttnStatusLabel(item.status)) + '</td>' +
        '<td class="ap-attn-cell-date" data-label="' + escapeHtml(t('insights.attention.col_date')) + '">' + dateHtml + '</td>' +
        '<td class="ap-attn-cell-action">' +
          '<button type="button" class="ap-btn ap-btn-secondary ap-attn-view" data-li-attn-open="' + escapeHtml(item.id) + '">' + escapeHtml(t('insights.attention.view_case')) + '</button>' +
        '</td>' +
      '</tr>';
    }).join('');

    if (footer) {
      footer.hidden = false;
      if (showing) {
        showing.textContent = t('insights.attention.showing', { shown: preview.length, total: totalForFilter });
      }
      if (viewAll) {
        viewAll.hidden = !hasAny || totalForFilter <= LI_ATTN_PREVIEW;
      }
    }
  }

  /** Populate the laboratory filter from the practice's live labs. */
  function liAttnPopulateLabFilter(labs) {
    var sel = document.getElementById('liAttnLabFilter');
    if (!sel) { return; }
    var prev = liAttnState.lab;
    sel.innerHTML = '<option value="all">' + escapeHtml(t('insights.labattn.lab_filter_all')) + '</option>';
    (labs || []).forEach(function (lab) {
      if (!lab || lab.isLive === false || !lab.labKey) { return; }
      var opt = document.createElement('option');
      opt.value = lab.labKey;
      opt.textContent = lab.name || lab.labKey;
      sel.appendChild(opt);
    });
    // Keep a previously-selected lab only if it still exists.
    var stillThere = Array.prototype.some.call(sel.options, function (o) { return o.value === String(prev); });
    liAttnState.lab = stillThere ? prev : 'all';
    sel.value = liAttnState.lab;
  }

  function renderLiAttentionQueue(data) {
    var section = document.getElementById('liAttentionSection');
    var queue = data && data.attentionQueue;
    if (!section) { return; }
    if (!queue) {
      section.style.display = 'none';
      return;
    }
    section.style.display = '';

    liAttnState.items = Array.isArray(queue.items) ? queue.items : [];
    liAttnState.counts = queue.counts || {};
    liAttnState.counts.all = queue.total !== undefined ? queue.total : liAttnState.items.length;
    liAttnCountsByLab = queue.countsByLab || {};

    var map = { all: 'liAttnCountAll', overdue: 'liAttnCountOverdue', appt_risk: 'liAttnCountApptRisk', due_soon: 'liAttnCountDueSoon', awaiting_ack: 'liAttnCountAck' };
    Object.keys(map).forEach(function (filter) {
      var el = document.getElementById(map[filter]);
      if (el) { el.textContent = liAttnState.counts[liAttnChipCountKey(filter)] || 0; }
    });

    renderLiAttnRows();
    bindLiAttentionQueue();
  }

  function bindLiAttentionQueue() {
    if (liAttnBound) { return; }
    liAttnBound = true;

    var filtersEl = document.getElementById('liAttnFilters');
    if (filtersEl) {
      filtersEl.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-li-attn-filter]');
        if (!chip) { return; }
        liAttnState.filter = chip.getAttribute('data-li-attn-filter') || 'all';
        filtersEl.querySelectorAll('.ap-attention-chip').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
        renderLiAttnRows();
      });
    }

    var labSel = document.getElementById('liAttnLabFilter');
    if (labSel) {
      labSel.addEventListener('change', function () {
        liAttnState.lab = labSel.value || 'all';
        renderLiAttnRows();
      });
    }

    var sortEl = document.getElementById('liAttnSort');
    if (sortEl) {
      sortEl.addEventListener('change', function () {
        liAttnState.sort = sortEl.value || 'priority';
        renderLiAttnRows();
      });
    }

    var clearBtn = document.getElementById('liAttnClearFilters');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        liAttnState.filter = 'all';
        liAttnState.lab = 'all';
        if (labSel) { labSel.value = 'all'; }
        if (filtersEl) {
          filtersEl.querySelectorAll('.ap-attention-chip').forEach(function (c) {
            c.classList.toggle('is-active', c.getAttribute('data-li-attn-filter') === 'all');
          });
        }
        renderLiAttnRows();
      });
    }

    var body = document.getElementById('liAttnBody');
    if (body) {
      body.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-li-attn-open]');
        if (!opener) { return; }
        var id = opener.getAttribute('data-li-attn-open');
        var item = liAttnState.items.find(function (i) { return String(i.id) === String(id); });
        if (item) { liAttnOpenCase(item); }
      });
    }

    var viewAll = document.getElementById('liAttnViewAll');
    if (viewAll) {
      viewAll.addEventListener('click', function () {
        // Map the active chip to the matching Case Board risk filter; the
        // lab scope (filterLabCases) is always applied by liNavigateToCases.
        var targetByFilter = {
          all: 'lab_cases', overdue: 'late', appt_risk: 'appt_risk',
          due_soon: 'due_soon', awaiting_ack: 'awaiting_ack'
        };
        liNavigateToCases(targetByFilter[liAttnState.filter] || 'lab_cases');
      });
    }
  }

  // ======================================================================
  // Performance layout (new)
  // ======================================================================

  /**
   * Display-ready metrics for a lab honoring the active case-type filter.
   * Per-type stats already ship in performance.labs[].caseTypes, so the
   * filter is applied client-side with the same denominators the backend
   * used - table, comparison panel, and chart all read this one helper.
   * Lab-attributed remake rate has no per-type breakdown upstream, so it
   * reports null under a type filter rather than implying all-type data.
   */
  function labRowMetrics(lab) {
    var ct = liCaseType;
    if (ct) {
      var ts = (lab.caseTypes || {})[ct];
      if (!ts) {
        return {
          volume: 0, completed: 0, hasTypeData: false,
          turnaround: { avgDays: null, n: 0, sufficient: false },
          onTime: { pct: null, n: 0, sufficient: false },
          remake: { remakeRatePct: null, rateDenominator: 0, rateSufficient: false },
          labAttrRate: null
        };
      }
      return {
        volume: ts.uniqueCases || 0,
        completed: ts.completedCases || 0,
        hasTypeData: true,
        turnaround: { avgDays: ts.avgTurnaroundDays, n: ts.turnaroundN || 0, sufficient: !!ts.sufficient },
        onTime: { pct: ts.onTimePct, n: ts.onTimeN || 0, sufficient: !!ts.sufficient },
        remake: { remakeRatePct: ts.remakeRatePct, rateDenominator: ts.uniqueCases || 0, rateSufficient: !!ts.sufficient },
        labAttrRate: null
      };
    }
    var rm = lab.remakes || {};
    return {
      volume: lab.volume ? lab.volume.uniqueCases : 0,
      completed: lab.completed ? lab.completed.uniqueCases : 0,
      hasTypeData: true,
      turnaround: lab.turnaround || { avgDays: null, n: 0, sufficient: false },
      onTime: lab.onTime || { pct: null, n: 0, sufficient: false },
      remake: { remakeRatePct: rm.remakeRatePct, rateDenominator: rm.rateDenominator, rateSufficient: rm.rateSufficient },
      labAttrRate: rm.labAttributedRatePct
    };
  }

  function perfSortValue(lab, key) {
    var m = labRowMetrics(lab);
    switch (key) {
      case 'name': return lab.name;
      case 'cases': return m.volume;
      case 'turnaround': return m.turnaround.avgDays;
      case 'onTime': return m.onTime.pct;
      case 'remakeRate': return m.remake.remakeRatePct;
      case 'labAttrRate': return liCaseType ? null : m.labAttrRate;
      case 'workload': return lab.workload ? lab.workload.openCases : null;
      case 'overdue': return lab.workload ? (lab.workload.late || 0) : null;
      default: return null;
    }
  }

  function sortPerfLabs() {
    var key = liPerfSort.key;
    var dir = liPerfSort.dir === 'asc' ? 1 : -1;
    liPerfLabs.sort(function (a, b) {
      var av = perfSortValue(a, key);
      var bv = perfSortValue(b, key);
      if (av === null && bv === null) { return 0; }
      if (av === null) { return 1; }
      if (bv === null) { return -1; }
      if (typeof av === 'string') {
        return dir * av.localeCompare(bv);
      }
      return dir * (av - bv);
    });
  }

  function renderPerfSortIndicators() {
    var headers = document.querySelectorAll('#liPerfTable thead th');
    headers.forEach(function (th) {
      th.classList.remove('li-sort-active');
      var existingArrow = th.querySelector('.li-sort-arrow');
      if (existingArrow) { existingArrow.remove(); }
      if (th.dataset.sort === liPerfSort.key) {
        th.classList.add('li-sort-active');
        var arrow = document.createElement('span');
        arrow.className = 'li-sort-arrow';
        arrow.textContent = liPerfSort.dir === 'asc' ? '▲' : '▼';
        th.appendChild(arrow);
      }
    });
  }

  /**
   * Render a metric value honoring the backend's sample-size signal.
   * n=0 → "—" + "Not enough data"; n<min → muted value + "Based on N cases";
   * n>=min → normal value.
   */
  function metricValueHtml(value, n, sufficient, formatter) {
    var fmt = formatter || function (v) { return String(v); };
    if (value === null || value === undefined || n === 0) {
      return '<span class="li-muted">—</span><div class="li-metric-note">' +
        escapeHtml(t('insights.perf.context.not_enough_data')) + '</div>';
    }
    var note = '';
    if (!sufficient) {
      note = '<div class="li-metric-note li-metric-note-quiet">' +
        escapeHtml(I18n.pluralize(n, 'insights.perf.context.based_on_cases')) +
        ' &middot; ' + escapeHtml(t('insights.perf.context.limited_history')) + '</div>';
    }
    return '<span class="' + (sufficient ? '' : 'li-value-muted') + '">' +
      escapeHtml(fmt(value)) + '</span>' + note;
  }

  /** "1.4 days faster than practice average" / "...pts above/below" / "Similar..." */
  function vsPracticeText(bench, kind) {
    if (!bench || bench.sufficient !== true || bench.delta === null || bench.delta === undefined) {
      return '';
    }
    var d = bench.delta;
    if (kind === 'days') {
      if (Math.abs(d) < 0.5) { return t('insights.perf.context.vs_similar'); }
      return d < 0
        ? t('insights.perf.context.vs_faster', { delta: Math.abs(d) })
        : t('insights.perf.context.vs_slower', { delta: d });
    }
    // percentage points
    if (Math.abs(d) < 1) { return t('insights.perf.context.vs_similar'); }
    return d > 0
      ? t('insights.perf.context.vs_above', { delta: d })
      : t('insights.perf.context.vs_below', { delta: Math.abs(d) });
  }

  function prevPeriodText(prev, formatter) {
    if (!prev || prev.previous === null || prev.previous === undefined) { return ''; }
    return t('insights.perf.context.prev_period', { value: formatter(prev.previous) });
  }

  /** One practice-level summary card. */
  function summaryCard(label, valueHtml, accent, extraClass) {
    return '<div class="ap-metric-card ' + accent + ' ' + (extraClass || '') + '">' +
      '<div class="ap-metric-value">' + valueHtml + '</div>' +
      '<div class="ap-metric-label">' + escapeHtml(label) + '</div>' +
      '</div>';
  }

  function renderPerfSummary(practice) {
    var grid = document.getElementById('liPerfSummary');
    if (!grid) { return; }
    var p = practice || {};
    var ta = p.turnaround || {};
    var ot = p.onTime || {};
    var rm = p.remakes || {};

    var onTimeHtml = metricValueHtml(ot.pct, ot.n, ot.sufficient, fmtPercent);
    if (ot.n > 0 && ot.dueDateCoveragePct !== null && ot.dueDateCoveragePct < 100) {
      onTimeHtml += '<div class="li-metric-note">' +
        escapeHtml(t('insights.perf.context.due_date_coverage', { pct: ot.dueDateCoveragePct })) + '</div>';
    }

    var html = '';
    html += summaryCard(t('insights.perf.summary.lab_cases'), escapeHtml(fmtCount(p.volume && p.volume.uniqueCases)), 'accent-blue');
    html += summaryCard(t('insights.perf.summary.avg_turnaround'),
      metricValueHtml(ta.avgDays, ta.n, ta.sufficient, fmtDays), 'accent-green');
    html += summaryCard(t('insights.perf.summary.on_time'), onTimeHtml, 'accent-green');
    html += summaryCard(t('insights.perf.summary.remake_rate'),
      metricValueHtml(rm.remakeRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent), 'accent-orange');
    html += summaryCard(t('insights.perf.summary.lab_remake_rate'),
      metricValueHtml(rm.labAttributedRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent), 'accent-orange');
    grid.innerHTML = html;

    // Current-state metrics get their own strip so the "now" semantics are
    // visually separated from the selected-period cards.
    var now = document.getElementById('liPerfNow');
    if (now) {
      var openCases = perfTotalOpenCases();
      var openRemakes = p.openRemakes || 0;
      now.innerHTML =
        '<span class="ap-now-caption">' + escapeHtml(t('insights.perf.context.right_now')) + '</span>' +
        '<span class="ap-now-chip"><strong>' + escapeHtml(fmtCount(openCases)) + '</strong> ' +
          escapeHtml(t('insights.perf.summary.open_cases').toLowerCase()) + '</span>' +
        '<span class="ap-now-chip"><strong>' + escapeHtml(fmtCount(openRemakes)) + '</strong> ' +
          escapeHtml(t('insights.perf.summary.open_remakes').toLowerCase()) + '</span>';
    }
  }

  function perfTotalOpenCases() {
    var total = 0;
    (liPerfLabs || []).forEach(function (l) {
      total += (l.workload && l.workload.openCases) || 0;
    });
    return total;
  }

  // ======================================================================
  // Lab Insights & Recommendations
  // ======================================================================
  // Structured cards over api/lab-recommendations.php output, merged with
  // persisted lifecycle states (new/reviewed/dismissed/resolved) served by
  // get-lab-insights.php. Deterministic metrics - no AI calls happen here.

  var LI_RECS_PREVIEW = 3;
  var LI_RECS_PLURAL_TYPES = {
    multi_remake: true,
    late_workload_lab: true,
    late_workload_practice: true,
    insight_appt_risk: true,
    insight_appt_risk_practice: true,
    insight_awaiting_ack: true,
    insight_awaiting_ack_practice: true,
    insight_due_soon: true,
    insight_due_soon_practice: true
  };
  var liRecsPayload = null;        // {status, items, computed_at}
  var liRecStateFilter = 'active'; // active|reviewed|dismissed|resolved|all
  var liRecScope = 'practice';     // 'practice' or a labKey - mirrors liSelectedLab
  var liRecShowAll = false;
  var liRecBusy = false;

  /** Map a structured recommendation object to localized display text. */
  function recMessage(rec) {
    var params = rec.params || {};
    // Resolve code params to display labels (reason/attribution catalogs).
    if (params.reasonCode) {
      params.reasonLabel = t('remakes.reasons.' + params.reasonCode) || params.reasonCode;
    }
    if (params.attrCode) {
      params.attrLabel = t('remakes.attribution.' + params.attrCode) || params.attrCode;
    }
    if (params.type === 'Unknown') {
      params.type = t('insights.perf.detail.unknown_type');
    }
    var key = 'insights.recs.msg.' + rec.type;
    if (LI_RECS_PLURAL_TYPES[rec.type]) {
      return I18n.pluralize(params.count || 1, key, params);
    }
    return t(key, params);
  }

  function recHeading(rec) {
    return t('insights.recs.heading.' + rec.type) || rec.title || '';
  }

  /** Brief "why it matters" explanation; falls back to a generic note. */
  function recWhy(rec) {
    var key = 'insights.labrecs.why.' + rec.type;
    var v = t(key);
    return (v && v !== key) ? v : t('insights.labrecs.why_default');
  }

  function recSeverityLabel(severity) {
    var known = { attention: 1, watch: 1, improvement: 1, info: 1 };
    return known[severity] ? t('insights.recs.severity.' + severity) : '';
  }

  function recStateLabel(state) {
    var map = { new: 'state_new', reviewed: 'state_reviewed_chip',
                dismissed: 'state_dismissed_chip', resolved: 'state_resolved_chip' };
    return map[state] ? t('insights.labrecs.' + map[state]) : '';
  }

  /** Lab badge text: configured lab name, or an honest scope label. */
  function recLabLabel(rec) {
    if (rec.scope === 'lab' && rec.labName) { return rec.labName; }
    var labs = (rec.params && rec.params.labs) ? parseInt(rec.params.labs, 10) : 0;
    if (labs > 1) { return t('insights.labrecs.multi_lab'); }
    return t('insights.labrecs.scope_all');
  }

  function recContextLabel(rec) {
    return rec.context === 'current'
      ? t('insights.labrecs.context_current')
      : t('insights.labrecs.context_period');
  }

  /** Action button label for a rec's structured action target. */
  function recActionLabel(rec) {
    var a = rec.action || {};
    if (a.type === 'cases') {
      return t('insights.labrecs.action_cases_' + (a.filter || 'lab_cases'));
    }
    if (a.type === 'perf') { return t('insights.labrecs.action_perf'); }
    return '';
  }

  function recMatchesState(rec) {
    var st = rec.state || 'new';
    if (liRecStateFilter === 'all') { return true; }
    if (liRecStateFilter === 'active') { return st === 'new'; }
    return st === liRecStateFilter;
  }

  function recMatchesScope(rec) {
    if (liRecScope === 'practice' || !liRecScope) { return true; }
    return rec.scope === 'lab' && rec.labKey === liRecScope;
  }

  function recFilteredItems(kind) {
    var items = (liRecsPayload && Array.isArray(liRecsPayload.items)) ? liRecsPayload.items : [];
    return items.filter(function (r) {
      var isObs = (r.kind === 'observation');
      if (kind === 'observation' ? !isObs : isObs) { return false; }
      return recMatchesState(r) && recMatchesScope(r);
    });
  }

  function liRecCounts() {
    var items = (liRecsPayload && Array.isArray(liRecsPayload.items)) ? liRecsPayload.items : [];
    var c = { new: 0, reviewed: 0, dismissed: 0, resolved: 0 };
    items.forEach(function (r) {
      var st = r.state || 'new';
      if (c[st] !== undefined) { c[st]++; }
    });
    return c;
  }

  function renderRecCounts() {
    var c = liRecCounts();
    var map = { new: 'liRecCountActive', reviewed: 'liRecCountReviewed',
                dismissed: 'liRecCountDismissed', resolved: 'liRecCountResolved' };
    Object.keys(map).forEach(function (k) {
      var el = document.getElementById(map[k]);
      if (el) { el.textContent = c[k]; }
    });
  }

  /** Populate the scope select from the loaded labs; keep selection. */
  function renderRecScopeSelect() {
    var sel = document.getElementById('liRecScope');
    if (!sel) { return; }
    var prev = liRecScope || 'practice';
    sel.innerHTML = '';
    var optAll = document.createElement('option');
    optAll.value = 'practice';
    optAll.textContent = t('insights.labrecs.scope_all');
    sel.appendChild(optAll);
    (liPerfLabs || []).forEach(function (l) {
      if (!l.labKey) { return; }
      var o = document.createElement('option');
      o.value = l.labKey;
      o.textContent = l.name || l.labKey;
      sel.appendChild(o);
    });
    // Honor the lab detail selection when it names a real lab.
    var effective = (liSelectedLab && liSelectedLab !== 'practice'
        && (liPerfLabs || []).some(function (l) { return l.labKey === liSelectedLab; }))
        ? liSelectedLab : prev;
    liRecScope = effective === 'practice' ? 'practice' : effective;
    sel.value = liRecScope;
  }

  /** Sync the rec scope selector whenever the lab detail selection changes. */
  function liRecSyncScope() {
    var sel = document.getElementById('liRecScope');
    if (sel && liSelectedLab) {
      var valid = liSelectedLab === 'practice'
        || (liPerfLabs || []).some(function (l) { return l.labKey === liSelectedLab; });
      if (valid) {
        liRecScope = liSelectedLab;
        sel.value = liRecScope;
        if (liRecsPayload) { renderRecommendationLists(); }
      }
    }
  }

  function liRecParamLabel(key) {
    var map = { cur: 'metric_current', prev: 'metric_previous', delta: 'metric_change',
                count: 'metric_count', total: 'metric_total', pct: 'metric_rate',
                overall: 'metric_overall', type: 'metric_case_type', lab: 'metric_lab',
                labs: 'metric_labs', reasonLabel: 'metric_reason', attrLabel: 'metric_attribution' };
    return map[key] ? t('insights.labrecs.' + map[key]) : key;
  }

  /** Structured detail rows for the expander - verified params only. */
  function recDetailRows(rec) {
    var params = rec.params || {};
    var skip = { reasonCode: 1, attrCode: 1, type: 1 };
    var rows = [];
    Object.keys(params).forEach(function (k) {
      if (skip[k]) { return; }
      var v = params[k];
      if (v === null || v === undefined || v === '') { return; }
      if (typeof v === 'number') { v = (Math.round(v * 10) / 10).toString(); }
      rows.push({ label: liRecParamLabel(k), value: String(v) });
    });
    return rows;
  }

  function recLifecycleButtons(rec) {
    var st = rec.state || 'new';
    var key = rec.rec_key || rec.id;
    var btn = function (state, label, cls) {
      return '<button type="button" class="lir-btn ' + (cls || 'lir-btn-ghost') +
        '" data-rec-key="' + escapeHtml(key) + '" data-rec-state="' + state + '">' +
        escapeHtml(label) + '</button>';
    };
    if (st === 'new') {
      return btn('reviewed', t('insights.recs.mark_reviewed')) +
             btn('dismissed', t('insights.recs.dismiss'));
    }
    if (st === 'reviewed') {
      return btn('new', t('insights.recs.mark_new')) +
             btn('dismissed', t('insights.recs.dismiss'));
    }
    if (st === 'dismissed') {
      return btn('new', t('insights.recs.restore'));
    }
    return '';
  }

  function renderRecCard(rec, idx) {
    var severity = rec.severity || 'info';
    var st = rec.state || 'new';
    var isObs = rec.kind === 'observation';
    var action = rec.action || {};
    var actionLabel = recActionLabel(rec);
    var detailId = 'lirDet-' + (rec.rec_key || rec.id || idx);

    var html = '<li class="lir-card lir-' + escapeHtml(severity) +
      (isObs ? ' lir-observation' : '') + '" data-rec-key="' + escapeHtml(rec.rec_key || '') + '">' +
      '<div class="lir-card-head">' +
        '<span class="lir-priority">#' + (idx + 1) + '</span>' +
        '<span class="lir-sev lir-sev-' + escapeHtml(severity) + '">' + escapeHtml(recSeverityLabel(severity)) + '</span>' +
        (st !== 'new' ? '<span class="lir-state lir-state-' + escapeHtml(st) + '">' + escapeHtml(recStateLabel(st)) + '</span>' : '') +
        '<span class="lir-lab">' + escapeHtml(recLabLabel(rec)) + '</span>' +
      '</div>' +
      '<h3 class="lir-title">' + escapeHtml(recHeading(rec)) + '</h3>' +
      '<p class="lir-observed"><span class="lir-tag">' + escapeHtml(t('insights.labrecs.observed')) + '</span> ' +
        escapeHtml(recMessage(rec)) + '</p>' +
      '<p class="lir-why"><span class="lir-tag">' + escapeHtml(t('insights.labrecs.why_label')) + '</span> ' +
        escapeHtml(recWhy(rec)) + '</p>' +
      '<div class="lir-card-foot">' +
        '<span class="lir-context">' + escapeHtml(recContextLabel(rec)) + '</span>' +
        '<span class="lir-spacer"></span>';

    if (actionLabel) {
      html += '<button type="button" class="lir-btn lir-btn-primary" data-rec-action="' +
        escapeHtml(JSON.stringify(action)) + '">' + escapeHtml(actionLabel) + '</button>';
    } else if (!isObs) {
      html += '<span class="lir-noaction">' + escapeHtml(t('insights.labrecs.informational')) + '</span>';
    }
    html += '<button type="button" class="lir-btn lir-btn-ghost" data-rec-detail="' + escapeHtml(detailId) + '">' +
      escapeHtml(t('insights.recs.view_details')) + '</button>' +
      recLifecycleButtons(rec) +
      '</div>' +
      '<div class="lir-detail" id="' + escapeHtml(detailId) + '" hidden>' +
        '<div class="lir-detail-grid"></div>' +
        '<p class="lir-detail-meta">' + escapeHtml(t('insights.labrecs.detail_meta', {
          period: recContextLabel(rec),
          time: liRecsPayload && liRecsPayload.computed_at ? liRecsPayload.computed_at : ''
        })) + '</p>' +
      '</div>' +
    '</li>';
    return html;
  }

  /** Fill the expander grid lazily on first open. */
  function fillRecDetail(rec, container) {
    if (container.dataset.filled === '1') { return; }
    container.dataset.filled = '1';
    var rows = recDetailRows(rec);
    var html = '';
    rows.forEach(function (r) {
      html += '<div class="lir-detail-item"><span class="lir-detail-k">' + escapeHtml(r.label) +
        '</span><span class="lir-detail-v">' + escapeHtml(r.value) + '</span></div>';
    });
    if (!rows.length) {
      html = '<p class="lir-detail-empty">' + escapeHtml(t('insights.labrecs.detail_empty')) + '</p>';
    }
    container.querySelector('.lir-detail-grid').innerHTML = html;
  }

  function renderObservationRow(rec) {
    var st = rec.state || 'new';
    var key = rec.rec_key || rec.id;
    return '<li class="lir-obs" data-rec-key="' + escapeHtml(key) + '">' +
      '<span class="lir-obs-dot"></span>' +
      '<span class="lir-obs-text"><strong>' + escapeHtml(recHeading(rec)) + '</strong> ' +
        escapeHtml(recMessage(rec)) + '</span>' +
      (st !== 'new' ? '<span class="lir-state lir-state-' + escapeHtml(st) + '">' + escapeHtml(recStateLabel(st)) + '</span>' : '') +
      '<span class="lir-obs-actions">' + recLifecycleButtons(rec) + '</span>' +
    '</li>';
  }

  function renderRecommendationLists() {
    var host = document.getElementById('liRecsActive');
    var posHost = document.getElementById('liRecsPositive');
    var posList = document.getElementById('liRecsPositiveList');
    var moreBtn = document.getElementById('liRecsMore');
    var statusEl = document.getElementById('liRecsStatus');
    if (!host) { return; }

    var status = liRecsPayload ? liRecsPayload.status : 'unavailable';
    var insights = recFilteredItems('insight');
    var observations = recFilteredItems('observation');

    // Status messaging for non-ok envelopes.
    if (statusEl) {
      statusEl.hidden = (status === 'ok');
      if (status === 'unavailable') {
        statusEl.textContent = t('insights.labrecs.unavailable');
        statusEl.className = 'lir-status lir-status-warn';
      } else if (status === 'insufficient') {
        statusEl.textContent = t('insights.labrecs.insufficient');
        statusEl.className = 'lir-status';
      }
    }

    // Summary line.
    var summaryEl = document.getElementById('liRecsSummary');
    if (summaryEl) {
      var activeCount = recFilteredItems('insight').filter(function (r) { return (r.state || 'new') === 'new'; }).length
        + observations.filter(function (r) { return (r.state || 'new') === 'new'; }).length;
      var labs = {};
      (liRecsPayload && liRecsPayload.items || []).forEach(function (r) {
        if ((r.state || 'new') === 'new' && r.scope === 'lab' && r.labKey) { labs[r.labKey] = true; }
      });
      var labCount = Object.keys(labs).length;
      var scopeSuffix = liRecScope !== 'practice' ? ' ' + t('insights.labrecs.summary_scoped') : '';
      summaryEl.textContent = I18n.pluralize(activeCount, 'insights.labrecs.summary_active') +
        (labCount > 0 ? ' ' + I18n.pluralize(labCount, 'insights.labrecs.summary_labs') : '') + scopeSuffix;
    }

    // Active insights list (top N + view-all).
    if (!insights.length) {
      host.innerHTML = '<div class="lir-empty">' + escapeHtml(t('insights.labrecs.empty_active')) +
        '<span class="lir-empty-sub">' + escapeHtml(t('insights.labrecs.empty_active_sub')) + '</span></div>';
    } else {
      var showAll = liRecShowAll || liRecStateFilter !== 'active';
      var visible = showAll ? insights : insights.slice(0, LI_RECS_PREVIEW);
      var html = '<ul class="lir-list">';
      visible.forEach(function (rec, idx) { html += renderRecCard(rec, idx); });
      html += '</ul>';
      host.innerHTML = html;
    }

    // View-all toggle (only meaningful for the default Active view).
    if (moreBtn) {
      var extra = insights.length - LI_RECS_PREVIEW;
      if (liRecStateFilter === 'active' && extra > 0) {
        moreBtn.style.display = '';
        moreBtn.textContent = liRecShowAll
          ? t('insights.recs.less')
          : I18n.pluralize(extra, 'insights.recs.more');
      } else {
        moreBtn.style.display = 'none';
        moreBtn.onclick = null;
      }
    }

    // Positive observations - compact secondary subsection.
    if (posHost && posList) {
      if (observations.length) {
        posHost.hidden = false;
        posList.innerHTML = observations.map(renderObservationRow).join('');
      } else {
        posHost.hidden = true;
        posList.innerHTML = '';
      }
    }
    wireRecEvents(host);
    if (posList) { wireRecEvents(posList); }
  }

  function wireRecEvents(root) {
    root.querySelectorAll('[data-rec-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var action;
        try { action = JSON.parse(btn.dataset.recAction); } catch (e) { return; }
        liRecNavigate(action);
      });
    });
    root.querySelectorAll('[data-rec-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var panel = document.getElementById(btn.dataset.recDetail);
        if (!panel) { return; }
        var card = btn.closest('.lir-card');
        var rec = liRecsPayload && liRecsPayload.items
          ? liRecsPayload.items.filter(function (r) { return (r.rec_key || r.id) === card.dataset.recKey; })[0]
          : null;
        if (rec) { fillRecDetail(rec, panel); }
        panel.hidden = !panel.hidden;
        btn.textContent = panel.hidden
          ? t('insights.recs.view_details')
          : t('insights.labrecs.hide_details');
      });
    });
    root.querySelectorAll('[data-rec-state]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        liRecSetState(btn.dataset.recKey, btn.dataset.recState, btn);
      });
    });
  }

  /** Navigate from a recommendation's structured action target. */
  function liRecNavigate(action) {
    if (!action || !action.type) { return; }
    if (action.type === 'cases') {
      liNavigateToCases(action.filter || 'lab_cases', action.labKey || '');
    } else if (action.type === 'perf') {
      selectLab(action.labKey || 'practice', { noScroll: true });
      var detail = document.getElementById('liLabDetail');
      if (detail) { detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    } else if (action.type === 'attention') {
      var attn = document.getElementById('liAttentionSection');
      if (attn) { attn.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    }
  }

  /** Persist a lifecycle transition, then update local state + counts. */
  function liRecSetState(recKey, state, btn) {
    if (liRecBusy || !recKey) { return; }
    liRecBusy = true;
    if (btn) { btn.disabled = true; }
    var csrf = document.querySelector('meta[name="csrf-token"]');
    fetch('api/lab-recommendation-state.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf ? csrf.getAttribute('content') : ''
      },
      body: JSON.stringify({
        action: 'set_state',
        rec_key: recKey,
        state: state
      })
    }).then(function (r) { return r.json(); }).then(function (d) {
      liRecBusy = false;
      if (!d || !d.success) {
        if (btn) { btn.disabled = false; }
        var meta = btn && btn.closest('.lir-card, .lir-obs');
        if (meta) {
          var err = meta.querySelector('.lir-error');
          if (!err) {
            err = document.createElement('span');
            err.className = 'lir-error';
            meta.appendChild(err);
          }
          err.textContent = t('insights.labrecs.update_failed');
        }
        return;
      }
      if (liRecsPayload && liRecsPayload.items) {
        liRecsPayload.items.forEach(function (r) {
          if ((r.rec_key || r.id) === recKey) { r.state = state; }
        });
      }
      renderRecCounts();
      renderRecommendationLists();
    }).catch(function () {
      liRecBusy = false;
      if (btn) { btn.disabled = false; }
    });
  }

  /**
   * Render Lab Insights & Recommendations. `data` is the recommendations
   * envelope from get-lab-insights.php: {status, items, computed_at}.
   */
  function renderRecommendations(data) {
    var section = document.getElementById('liRecs');
    if (!section) { return; }
    section.style.display = 'block';
    liRecShowAll = false;

    var statusEl = document.getElementById('liRecsStatus');
    var toolbar = document.getElementById('liRecsToolbar');
    liRecsPayload = (data && typeof data === 'object' && data.status) ? data
      : { status: 'unavailable', items: [], computed_at: null };

    if (toolbar) { toolbar.hidden = false; }
    renderRecScopeSelect();
    renderRecCounts();

    // State filter chips.
    document.querySelectorAll('#liRecStateChips .lir-chip').forEach(function (chip) {
      chip.classList.toggle('is-active', chip.dataset.recStateFilter === liRecStateFilter);
    });

    renderRecommendationLists();

    // Empty-state copy lives inside renderRecommendationLists; the legacy
    // liRecsList container is gone - guard for stale callers.
    var legacy = document.getElementById('liRecsList');
    if (legacy) { legacy.innerHTML = ''; }
  }

  /** Navigate to the Case Board scoped to one laboratory (+ optional risk). */
  function liNavigateToLabCases(target, labKey) {
    liNavigateToCases(target, labKey);
  }

  function renderPerfTable() {
    var tbody = document.getElementById('liPerfTableBody');
    var table = document.getElementById('liPerfTable');
    if (!tbody) { return; }
    tbody.innerHTML = '';
    sortPerfLabs();
    renderPerfSortIndicators();

    var canCompare = liPerfLabs.length >= 2;
    if (table) { table.classList.toggle('li-perf-table--no-compare', !canCompare); }
    // Drop stale selections if labs disappeared (e.g. range/filter change).
    liCompare = liCompare.filter(function (k) {
      return liPerfLabs.some(function (l) { return l.labKey === k; });
    });

    liPerfLabs.forEach(function (lab) {
      var row = document.createElement('tr');
      row.className = 'li-lab-row' + (liSelectedLab === lab.labKey ? ' li-lab-row-selected' : '');
      row.dataset.labKey = lab.labKey;
      row.tabIndex = 0;
      row.setAttribute('role', 'button');
      row.setAttribute('aria-label', lab.name);

      var m = labRowMetrics(lab);
      var rm = lab.remakes || {};
      var wl = lab.workload || {};
      var openCases = wl.openCases || 0;
      var lateCases = wl.late || 0;

      var compareCell = '<td class="li-compare-col">';
      if (canCompare) {
        var checked = liCompare.indexOf(lab.labKey) !== -1;
        compareCell += '<input type="checkbox" class="li-compare-check" data-lab-key="' +
          escapeHtml(lab.labKey) + '"' + (checked ? ' checked' : '') +
          ' aria-label="' + escapeHtml(t('insights.perf.compare.select_label')) + ' ' + escapeHtml(lab.name) + '">';
      }
      compareCell += '</td>';

      var nameCell = lab.isLive
        ? '<span class="li-lab-name" title="' + escapeHtml(lab.name) + '">' + escapeHtml(lab.name) + '</span>'
        : '<span class="li-lab-name li-lab-name-removed" title="' + escapeHtml(lab.name) +
          escapeHtml(t('insights.perf.table.removed_suffix')) + '">' + escapeHtml(lab.name) + '</span>';

      var turnHtml = metricValueHtml(
        m.turnaround.avgDays, m.turnaround.n, m.turnaround.sufficient, fmtDays);
      if (!liCaseType) {
        var turnBench = vsPracticeText(lab.benchmarks && lab.benchmarks.vsPractice
          ? lab.benchmarks.vsPractice.avgTurnaroundDays : null, 'days');
        if (turnBench) {
          turnHtml += '<div class="li-metric-note">' + escapeHtml(turnBench) + '</div>';
        }
      }

      var onTimeHtml = metricValueHtml(m.onTime.pct, m.onTime.n, m.onTime.sufficient, fmtPercent);
      if (!liCaseType && lab.onTime && lab.onTime.dueDateCoveragePct !== null
          && lab.onTime.dueDateCoveragePct < 100) {
        onTimeHtml += '<div class="li-metric-note">' +
          escapeHtml(t('insights.perf.context.due_date_coverage', { pct: lab.onTime.dueDateCoveragePct })) + '</div>';
      }
      if (!liCaseType) {
        var onTimeBench = vsPracticeText(lab.benchmarks && lab.benchmarks.vsPractice
          ? lab.benchmarks.vsPractice.onTimePct : null, 'pts');
        if (onTimeBench) {
          onTimeHtml += '<div class="li-metric-note">' + escapeHtml(onTimeBench) + '</div>';
        }
      }

      var remakeDenom = liCaseType ? m.volume : rm.rateDenominator;
      var remakeHtml = metricValueHtml(m.remake.remakeRatePct, remakeDenom, m.remake.rateSufficient, fmtPercent);
      var labAttrHtml;
      if (liCaseType) {
        labAttrHtml = '<span class="li-muted">—</span><div class="li-metric-note li-metric-note-quiet">' +
          escapeHtml(t('insights.perf.context.not_by_type')) + '</div>';
      } else {
        labAttrHtml = metricValueHtml(m.labAttrRate, rm.rateDenominator, rm.rateSufficient, fmtPercent);
      }

      // Current-state cells link to the Case Board scoped to this lab;
      // they stop propagation so the row's Lab Detail navigation is not
      // triggered. Counts always reflect live open periods, never the
      // selected reporting range.
      var workloadHtml = '<button type="button" class="li-cell-link" data-li-nav="lab_cases" data-li-lab="' +
        escapeHtml(lab.labKey) + '">' + escapeHtml(fmtCount(openCases)) + '</button>' +
        '<div class="li-metric-note li-metric-note-quiet">' +
        escapeHtml(t('insights.perf.context.active_cases_note')) + '</div>';
      if (lateCases > 0) {
        workloadHtml += '<div class="li-metric-note li-metric-note-late">' +
          '<button type="button" class="li-cell-link li-cell-link-late" data-li-nav="late" data-li-lab="' +
          escapeHtml(lab.labKey) + '">' + escapeHtml(I18n.pluralize(lateCases, 'insights.perf.context.late_count')) +
          '</button></div>';
      }

      var overdueHtml = lateCases > 0
        ? '<button type="button" class="li-cell-link li-cell-link-late" data-li-nav="late" data-li-lab="' +
          escapeHtml(lab.labKey) + '">' + escapeHtml(fmtCount(lateCases)) + '</button>'
        : '<span class="li-muted">0</span>';

      row.innerHTML =
        compareCell +
        '<td data-label="' + escapeHtml(t('insights.perf.table.lab')) + '">' + nameCell + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.cases')) + '">' + escapeHtml(fmtCount(m.volume)) + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.avg_turnaround')) + '">' + turnHtml + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.on_time')) + '">' + onTimeHtml + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.remake_rate')) + '">' + remakeHtml + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.lab_attributed')) + '">' + labAttrHtml + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.current_workload')) + '">' + workloadHtml + '</td>' +
        '<td class="li-num" data-label="' + escapeHtml(t('insights.perf.table.overdue')) + '">' + overdueHtml + '</td>';

      row.addEventListener('click', function (e) {
        if (e.target.closest('.li-compare-check, .li-cell-link')) { return; }
        selectLab(lab.labKey);
      });
      row.addEventListener('keydown', function (e) {
        if (e.target !== row) { return; }
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          selectLab(lab.labKey);
        }
      });
      tbody.appendChild(row);
    });

    renderComparePanel();
  }

  // ======================================================================
  // Two-laboratory comparison + metric bar visualization
  // ======================================================================

  function findPerfLab(labKey) {
    for (var i = 0; i < liPerfLabs.length; i++) {
      if (liPerfLabs[i].labKey === labKey) { return liPerfLabs[i]; }
    }
    return null;
  }

  /** Populate the case-type filter from types actually present in the data. */
  function populateCaseTypeFilter() {
    var sel = document.getElementById('liCaseTypeFilter');
    if (!sel) { return; }
    var types = {};
    var collect = function (cts) {
      Object.keys(cts || {}).forEach(function (k) {
        if (k && k !== 'Unknown') { types[k] = true; }
      });
    };
    if (liPerf && liPerf.practice) { collect(liPerf.practice.caseTypes); }
    liPerfLabs.forEach(function (l) { collect(l.caseTypes); });
    var keys = Object.keys(types).sort(function (a, b) {
      return liCaseTypeLabel(a).localeCompare(liCaseTypeLabel(b));
    });
    var prev = liCaseType;
    sel.innerHTML = '<option value="">' + escapeHtml(t('insights.perf.case_type_filter.all')) + '</option>';
    keys.forEach(function (k) {
      var opt = document.createElement('option');
      opt.value = k;
      opt.textContent = liCaseTypeLabel(k);
      sel.appendChild(opt);
    });
    // Keep the selection only if the type still exists in the data.
    liCaseType = keys.indexOf(prev) !== -1 ? prev : '';
    sel.value = liCaseType;
  }

  function liCaseTypeLabel(caseType) {
    if (caseType && typeof window.getCaseTypeDisplayLabel === 'function') {
      return window.getCaseTypeDisplayLabel(caseType);
    }
    return caseType || '';
  }

  /** Comparison metric definitions: value extractor + formatter. */
  function compareMetricDefs() {
    return {
      turnaround: {
        label: t('insights.perf.compare.metric_turnaround'),
        value: function (lab) { return labRowMetrics(lab).turnaround.avgDays; },
        fmt: fmtDays,
        note: function (lab) { return labRowMetrics(lab).turnaround; }
      },
      onTime: {
        label: t('insights.perf.compare.metric_ontime'),
        value: function (lab) { return labRowMetrics(lab).onTime.pct; },
        fmt: fmtPercent,
        note: function (lab) { return labRowMetrics(lab).onTime; }
      },
      workload: {
        label: t('insights.perf.compare.metric_workload'),
        value: function (lab) { return lab.workload ? lab.workload.openCases : null; },
        fmt: function (v) { return fmtCount(v); },
        note: function () { return null; }
      },
      overdue: {
        label: t('insights.perf.compare.metric_overdue'),
        value: function (lab) { return lab.workload ? (lab.workload.late || 0) : null; },
        fmt: function (v) { return fmtCount(v); },
        note: function () { return null; }
      },
      remake: {
        label: t('insights.perf.compare.metric_remake'),
        value: function (lab) { return labRowMetrics(lab).remake.remakeRatePct; },
        fmt: fmtPercent,
        note: function (lab) { return labRowMetrics(lab).remake; }
      }
    };
  }

  function compareMetricCellHtml(lab, metricKey) {
    var defs = compareMetricDefs();
    var def = defs[metricKey] || defs.turnaround;
    var m = labRowMetrics(lab);
    var v;
    switch (metricKey) {
      case 'cases': v = m.volume; break;
      case 'completed': v = m.completed; break;
      case 'workload': v = lab.workload ? lab.workload.openCases : null; break;
      case 'overdue': v = lab.workload ? (lab.workload.late || 0) : null; break;
      default: v = def.value(lab); break;
    }
    var fmt = metricKey === 'cases' || metricKey === 'completed' || metricKey === 'workload' || metricKey === 'overdue'
      ? function (x) { return fmtCount(x); }
      : def.fmt;
    if (v === null || v === undefined) {
      return '<span class="li-muted">—</span>';
    }
    var note = '';
    var n = null;
    if (metricKey === 'turnaround') { n = m.turnaround.n; }
    else if (metricKey === 'onTime') { n = m.onTime.n; }
    else if (metricKey === 'remake') { n = liCaseType ? m.volume : (lab.remakes ? lab.remakes.rateDenominator : null); }
    if (n !== null && n !== undefined && n > 0) {
      note = '<div class="li-metric-note li-metric-note-quiet">' +
        escapeHtml(I18n.pluralize(n, 'insights.perf.context.based_on_cases')) + '</div>';
    }
    return '<span>' + escapeHtml(fmt(v)) + '</span>' + note;
  }

  function renderComparePanel() {
    var panel = document.getElementById('liComparePanel');
    if (!panel) { return; }
    if (liCompare.length === 0) {
      panel.hidden = true;
      panel.innerHTML = '';
      return;
    }
    panel.hidden = false;

    var labA = findPerfLab(liCompare[0]);
    var labB = liCompare.length > 1 ? findPerfLab(liCompare[1]) : null;

    if (!labB) {
      panel.innerHTML =
        '<div class="li-compare-head">' +
          '<strong>' + escapeHtml(t('insights.perf.compare.title')) + '</strong>' +
          '<span class="li-compare-hint">' + escapeHtml(t('insights.perf.compare.need_two')) + '</span>' +
          '<button type="button" class="ap-btn ap-btn-secondary li-compare-close">' +
            escapeHtml(t('insights.perf.compare.clear')) + '</button>' +
        '</div>';
      bindCompareClose(panel);
      return;
    }

    var defs = compareMetricDefs();
    var metricKeys = ['turnaround', 'onTime', 'workload', 'overdue', 'remake'];
    if (!defs[liCompareMetric]) { liCompareMetric = 'turnaround'; }

    var rows = [
      ['cases', t('insights.perf.compare.metric_cases')],
      ['completed', t('insights.perf.compare.metric_completed')],
      ['turnaround', t('insights.perf.compare.metric_turnaround')],
      ['ontime', t('insights.perf.compare.metric_ontime')],
      ['workload', t('insights.perf.compare.metric_workload')],
      ['overdue', t('insights.perf.compare.metric_overdue')],
      ['remake', t('insights.perf.compare.metric_remake')]
    ];

    var html =
      '<div class="li-compare-head">' +
        '<strong>' + escapeHtml(t('insights.perf.compare.title')) + '</strong>' +
        '<span class="li-compare-names">' + escapeHtml(labA.name) + ' &harr; ' + escapeHtml(labB.name) + '</span>' +
        '<button type="button" class="ap-btn ap-btn-secondary li-compare-close">' +
          escapeHtml(t('insights.perf.compare.clear')) + '</button>' +
      '</div>' +
      '<div class="li-compare-controls">' +
        '<label class="li-compare-metric-label" for="liCompareMetric">' +
          escapeHtml(t('insights.perf.compare.compare_by')) +
          '<select class="ap-select" id="liCompareMetric">' +
            metricKeys.map(function (k) {
              return '<option value="' + k + '"' + (k === liCompareMetric ? ' selected' : '') + '>' +
                escapeHtml(defs[k].label) + '</option>';
            }).join('') +
          '</select>' +
        '</label>' +
      '</div>' +
      '<div class="li-compare-body">' +
        '<table class="li-compare-table">' +
          '<thead><tr><th></th><th>' + escapeHtml(labA.name) + '</th><th>' + escapeHtml(labB.name) + '</th></tr></thead>' +
          '<tbody>' +
            rows.map(function (r) {
              var key = r[0];
              var cellKey = key === 'ontime' ? 'onTime' : key;
              return '<tr><th scope="row">' + escapeHtml(r[1]) + '</th>' +
                '<td>' + compareMetricCellHtml(labA, cellKey) + '</td>' +
                '<td>' + compareMetricCellHtml(labB, cellKey) + '</td></tr>';
            }).join('') +
          '</tbody>' +
        '</table>' +
        '<div class="li-compare-chart" id="liCompareChart"></div>' +
      '</div>' +
      '<p class="li-table-footnote">' + escapeHtml(t('insights.perf.compare.disclaimer')) + '</p>';

    panel.innerHTML = html;
    bindCompareClose(panel);

    var metricSel = panel.querySelector('#liCompareMetric');
    if (metricSel) {
      metricSel.addEventListener('change', function () {
        liCompareMetric = metricSel.value || 'turnaround';
        renderComparePanel();
      });
    }
    renderCompareBars(labA, labB, liCompareMetric);
  }

  function bindCompareClose(panel) {
    var btn = panel.querySelector('.li-compare-close');
    if (btn) {
      btn.addEventListener('click', function () {
        liCompare = [];
        renderComparePanel();
        renderPerfTableBodyOnly();
      });
    }
  }

  /** Re-render only row checkboxes after compare state changes. */
  function renderPerfTableBodyOnly() {
    var tbody = document.getElementById('liPerfTableBody');
    if (!tbody) { return; }
    tbody.querySelectorAll('.li-compare-check').forEach(function (cb) {
      cb.checked = liCompare.indexOf(cb.getAttribute('data-lab-key')) !== -1;
    });
  }

  function renderCompareBars(labA, labB, metricKey) {
    var host = document.getElementById('liCompareChart');
    if (!host) { return; }
    var defs = compareMetricDefs();
    var def = defs[metricKey] || defs.turnaround;
    var va = def.value(labA);
    var vb = def.value(labB);
    if (va === null || va === undefined) { va = 0; }
    if (vb === null || vb === undefined) { vb = 0; }
    var max = Math.max(va, vb, 0.0001);
    var bar = function (lab, v) {
      var pct = Math.max(0, Math.min(100, (v / max) * 100));
      return '<div class="li-cmp-bar-row">' +
        '<span class="li-cmp-bar-name" title="' + escapeHtml(lab.name) + '">' + escapeHtml(lab.name) + '</span>' +
        '<span class="li-cmp-bar-track"><span class="li-cmp-bar-fill" style="width:' + pct.toFixed(1) + '%"></span></span>' +
        '<span class="li-cmp-bar-val">' + escapeHtml(def.fmt(v)) + '</span>' +
      '</div>';
    };
    host.innerHTML =
      '<div class="li-cmp-chart-title">' + escapeHtml(def.label) + '</div>' +
      bar(labA, va) + bar(labB, vb);
  }

  function renderLabDetailSelect() {
    var sel = document.getElementById('liLabDetailSelect');
    if (!sel) { return; }
    var current = sel.value;
    sel.innerHTML = '';
    var optAll = document.createElement('option');
    optAll.value = 'practice';
    optAll.textContent = t('insights.perf.detail.practice_option');
    sel.appendChild(optAll);
    liPerfLabs.forEach(function (lab) {
      var opt = document.createElement('option');
      opt.value = lab.labKey;
      opt.textContent = lab.name;
      sel.appendChild(opt);
    });
    sel.value = liSelectedLab || current || 'practice';
  }

  /** Resolve the aggregate block for the selected scope (lab or 'practice'). */
  function selectedScopeData() {
    if (!liPerf) { return null; }
    if (liSelectedLab === 'practice' || !liSelectedLab) {
      return liPerf.practice;
    }
    for (var i = 0; i < liPerfLabs.length; i++) {
      if (liPerfLabs[i].labKey === liSelectedLab) { return liPerfLabs[i]; }
    }
    return liPerf.practice;
  }

  function detailStatCard(label, valueHtml, tipKey) {
    var tip = tipKey ? ' title="' + escapeHtml(t(tipKey)) + '"' : '';
    return '<div class="li-stat">' +
      '<div class="li-stat-value">' + valueHtml + '</div>' +
      '<div class="li-stat-label"' + tip + '>' + escapeHtml(label) + '</div></div>';
  }

  /** Scorecard stat with an optional Case Board drill-down link. */
  function scorecardStat(label, valueHtml, opts) {
    opts = opts || {};
    var inner = valueHtml;
    if (opts.navTarget) {
      inner = '<button type="button" class="li-cell-link' + (opts.alert ? ' li-cell-link-late' : '') +
        '" data-li-nav="' + escapeHtml(opts.navTarget) + '" data-li-lab="' +
        escapeHtml(opts.labKey || '') + '">' + valueHtml + '</button>';
    }
    return detailStatCard(label, inner, opts.tipKey);
  }

  /** Per-lab attention counts for the Current Workload group. */
  function labAttentionCounts(labKey, isPractice) {
    if (isPractice) {
      var c = liAttnState.counts || {};
      return { overdue: c.overdue || 0, appt_risk: c.appt_risk || 0, awaiting_ack: c.awaiting_ack || 0 };
    }
    var c = liAttnCountsByLab[labKey] || {};
    return { overdue: c.overdue || 0, appt_risk: c.appt_risk || 0, awaiting_ack: c.awaiting_ack || 0 };
  }

  // Overdue uses the same workload.late definition as the comparison table
  // so scorecard values reconcile row-for-row; appt-risk/ack come from the
  // shared attention classification.
  function scorecardOverdue(wl, attn) {
    return (wl && wl.late !== undefined) ? (wl.late || 0) : attn.overdue;
  }

  function renderPerfDetail() {
    var section = document.getElementById('liLabDetail');
    var body = document.getElementById('liLabDetailBody');
    var title = document.getElementById('liLabDetailTitle');
    var subtitle = document.getElementById('liLabDetailSubtitle');
    if (!section || !body) { return; }

    var d = selectedScopeData();
    if (!d) { section.style.display = 'none'; return; }
    section.style.display = 'block';

    var isPractice = (liSelectedLab === 'practice' || !liSelectedLab);
    // The lab name is the primary heading inside Lab Detail; the selector
    // beside it identifies the scope. Practice scope keeps its label.
    if (isPractice) {
      title.textContent = t('insights.perf.detail.practice_option');
    } else {
      title.textContent = d.name || t('insights.perf.detail.title');
    }

    var t8n = d.turnaround || {};
    var ot = d.onTime || {};
    var dl = d.daysLate || {};
    var rm = d.remakes || {};
    var wl = d.workload || {};
    if (isPractice) {
      // Practice scope has no per-lab workload block - aggregate the labs.
      wl = { openCases: 0, openPeriods: 0, late: 0, withDueDate: 0 };
      liPerfLabs.forEach(function (l) {
        var w = l.workload || {};
        wl.openCases += w.openCases || 0;
        wl.openPeriods += w.openPeriods || 0;
        wl.late += w.late || 0;
        wl.withDueDate += 0; // per-lab denominator is not pooled
      });
    }
    var vol = d.volume || {};
    var comp = d.completed || {};
    var bench = d.benchmarks || {};
    var prev = bench.previousPeriod || null;
    var vs = bench.vsPractice || {};

    // Reporting context: historical metrics follow the range selector;
    // workload metrics are explicitly "as of now".
    var rangeSel = document.getElementById('liRangeSelect');
    var rangeText = rangeSel && rangeSel.selectedIndex >= 0
      ? rangeSel.options[rangeSel.selectedIndex].text : '';
    var wlSummary = escapeHtml(I18n.pluralize(wl.openCases || 0, 'insights.perf.context.cases_count'));
    if ((wl.late || 0) > 0) {
      wlSummary += ' &middot; ' + escapeHtml(I18n.pluralize(wl.late, 'insights.perf.context.late_count'));
    }
    subtitle.textContent = [
      wlSummary,
      rangeText ? t('insights.perf.detail.based_on_range', { range: rangeText }) : '',
      isPractice ? '' : (d.isLive ? '' : t('insights.perf.table.removed_suffix'))
    ].filter(Boolean).join('  ·  ');

    var navLab = isPractice ? '' : liSelectedLab;
    var attn = labAttentionCounts(liSelectedLab, isPractice);

    // Revision (workflow-regression) counts live on the legacy labs
    // payload; resolve them for the selected lab or aggregate for practice.
    var revCount = 0, revRate = null, revDenom = 0;
    if (isPractice) {
      liLabs.forEach(function (l) {
        revCount += l.revisionCount || 0;
        revDenom += l.casesAssigned || 0;
      });
      revRate = revDenom > 0 ? Math.round((revCount / revDenom) * 1000) / 10 : null;
    } else {
      var revLab = liLabs.find(function (l) { return l.labKey === liSelectedLab; });
      if (revLab) {
        revCount = revLab.revisionCount || 0;
        revRate = revLab.revisionRate;
        revDenom = revLab.casesAssigned || 0;
      }
    }

    var html = '<div class="li-scorecard">';

    // ── Group 1: Delivery Performance (selected reporting period) ──
    html += '<div class="li-scorecard-group">' +
      '<h3 class="li-scorecard-group-title">' + escapeHtml(t('insights.perf.detail.group_delivery')) + '</h3>' +
      '<div class="li-scorecard-grid">';

    var casesHtml = escapeHtml(fmtCount(vol.uniqueCases));
    if (vol.engagements && vol.engagements !== vol.uniqueCases) {
      casesHtml += '<div class="li-metric-note">' + escapeHtml(
        I18n.pluralize(vol.engagements, 'insights.perf.context.engagements')) + '</div>';
    }
    html += detailStatCard(t('insights.perf.detail.completed_cases'), casesHtml,
      'insights.perf.detail.tip_completed');

    var turnHtml = metricValueHtml(t8n.avgDays, t8n.n, t8n.sufficient, fmtDays);
    var vb = vsPracticeText(vs.avgTurnaroundDays, 'days');
    if (vb) { turnHtml += '<div class="li-metric-note">' + escapeHtml(vb) + '</div>'; }
    var pb = prevPeriodText(prev && prev.avgTurnaroundDays, fmtDays);
    if (pb) { turnHtml += '<div class="li-metric-note">' + escapeHtml(pb) + '</div>'; }
    html += detailStatCard(t('insights.perf.summary.avg_turnaround'), turnHtml,
      'insights.perf.detail.tip_turnaround');

    html += detailStatCard(t('insights.perf.detail.median_turnaround'),
      metricValueHtml(t8n.medianDays, t8n.n, t8n.sufficient, fmtDays),
      'insights.perf.detail.tip_median');

    var onTimeHtml = metricValueHtml(ot.pct, ot.n, ot.sufficient, fmtPercent);
    var ob = vsPracticeText(vs.onTimePct, 'pts');
    if (ob) { onTimeHtml += '<div class="li-metric-note">' + escapeHtml(ob) + '</div>'; }
    var opb = prevPeriodText(prev && prev.onTimePct, fmtPercent);
    if (opb) { onTimeHtml += '<div class="li-metric-note">' + escapeHtml(opb) + '</div>'; }
    if (ot.n > 0 && ot.dueDateCoveragePct !== null && ot.dueDateCoveragePct < 100) {
      onTimeHtml += '<div class="li-metric-note">' +
        escapeHtml(t('insights.perf.context.due_date_coverage', { pct: ot.dueDateCoveragePct })) + '</div>';
    }
    html += detailStatCard(t('insights.perf.summary.on_time'), onTimeHtml,
      'insights.perf.detail.tip_ontime');

    html += detailStatCard(t('insights.perf.detail.avg_days_late'),
      metricValueHtml(dl.avgDays, dl.n, dl.n >= (liPerf.meta ? liPerf.meta.minSampleSize : 5), fmtDays),
      'insights.perf.detail.tip_days_late');

    html += '</div></div>'; // delivery group

    // ── Group 2: Current Workload (as of now, not range-filtered) ──
    html += '<div class="li-scorecard-group">' +
      '<h3 class="li-scorecard-group-title">' + escapeHtml(t('insights.perf.detail.group_workload')) + '</h3>' +
      '<div class="li-scorecard-grid">';

    html += scorecardStat(t('insights.perf.detail.active_associated'),
      escapeHtml(fmtCount(wl.openCases || 0)),
      { navTarget: 'lab_cases', labKey: navLab, tipKey: 'insights.perf.detail.tip_workload' });
    html += scorecardStat(t('insights.perf.table.overdue'),
      escapeHtml(fmtCount(scorecardOverdue(wl, attn))),
      { navTarget: 'late', labKey: navLab, alert: true, tipKey: 'insights.perf.detail.tip_overdue' });
    html += scorecardStat(t('insights.attention.filter_appt_risk'),
      escapeHtml(fmtCount(attn.appt_risk)),
      { navTarget: 'appt_risk', labKey: navLab, tipKey: 'insights.perf.detail.tip_appt_risk' });
    html += scorecardStat(t('insights.attention.filter_awaiting_ack'),
      escapeHtml(fmtCount(attn.awaiting_ack)),
      { navTarget: 'awaiting_ack', labKey: navLab, tipKey: 'insights.perf.detail.tip_awaiting_ack' });

    html += '</div></div>'; // workload group

    // ── Group 3: Quality & Reliability ──
    html += '<div class="li-scorecard-group">' +
      '<h3 class="li-scorecard-group-title">' + escapeHtml(t('insights.perf.detail.group_quality')) + '</h3>' +
      '<div class="li-scorecard-grid">';

    var remakeDenom = rm.rateDenominator;
    var remakeHtml = metricValueHtml(rm.remakeRatePct, remakeDenom, rm.rateSufficient, fmtPercent);
    html += detailStatCard(t('insights.perf.summary.remake_rate'), remakeHtml,
      'insights.perf.detail.tip_remake');
    html += detailStatCard(t('insights.perf.summary.lab_remake_rate'),
      metricValueHtml(rm.labAttributedRatePct, remakeDenom, rm.rateSufficient, fmtPercent),
      'insights.perf.detail.tip_lab_attr');
    var revHtml = escapeHtml(fmtCount(revCount));
    if (revRate !== null && revRate !== undefined) {
      revHtml += '<div class="li-metric-note">' + escapeHtml(fmtPercent(revRate)) + '</div>';
    }
    html += detailStatCard(t('insights.perf.detail.workflow_regressions'), revHtml,
      'insights.perf.detail.tip_regressions');

    html += '</div>';
    // Supporting links to the detailed sections below — kept inside the
    // group so the scorecard stays the summary, not a duplicate analysis.
    html += '<p class="li-scorecard-links">' +
      '<a href="#liDetailRemakes">' + escapeHtml(t('insights.perf.detail.remake_details_link')) + '</a>' +
      ' &middot; ' +
      '<a href="#liRevisionsSection">' + escapeHtml(t('insights.perf.detail.regressions_link')) + '</a>' +
      '</p></div>'; // quality group
    html += '</div>'; // .li-scorecard

    // ── Performance Trends (adaptive: empty / single-observation / chart) ──
    html += '<div class="li-detail-block">' +
      '<div class="ap-chart-card full-width">' +
      '<div class="ap-chart-header">' +
      '<div>' +
      '<h3 class="ap-chart-title">' + escapeHtml(t('insights.perf.detail.trend_title')) + '</h3>' +
      '<p class="ap-chart-description">' + escapeHtml(t('insights.perf.detail.trend_desc')) + '</p>' +
      '</div>' +
      '<div class="ap-chart-controls li-trend-controls">' +
      '<select class="ap-select li-trend-select" id="liTrendMetricSelect" aria-label="' +
        escapeHtml(t('insights.perf.detail.trend_metric')) + '">' +
      '<option value="turnaroundDays"' + (liTrendMetric === 'turnaroundDays' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_turnaround')) + '</option>' +
      '<option value="onTimePct"' + (liTrendMetric === 'onTimePct' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_on_time')) + '</option>' +
      '<option value="completedCases"' + (liTrendMetric === 'completedCases' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_completed_volume')) + '</option>' +
      '<option value="remakeRatePct"' + (liTrendMetric === 'remakeRatePct' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_remake_rate')) + '</option>' +
      '</select>' +
      '<select class="ap-select li-trend-select" id="liTrendGranularitySelect" aria-label="' +
        escapeHtml(t('insights.perf.detail.trend_granularity')) + '"></select>' +
      '</div></div>' +
      '<div class="li-trend-summary" id="liTrendSummary"></div>' +
      '<div class="ap-chart-container li-trend-chart" id="liTrendChartWrap">' +
      '<div class="li-trend-state" id="liTrendState"></div>' +
      '<canvas id="liPerfTrendChart" role="img" aria-label="' + escapeHtml(t('insights.labs.perf_trend_aria')) + '"></canvas>' +
      '</div></div></div>';

    // ── Case-type performance ──
    var types = d.caseTypes || {};
    var typeKeys = Object.keys(types);
    html += '<div class="li-detail-block">' +
      '<h3 class="li-detail-heading">' + escapeHtml(t('insights.perf.detail.case_types')) + '</h3>';
    if (typeKeys.length === 0) {
      html += '<p class="li-muted">' + escapeHtml(t('insights.perf.detail.no_case_types')) + '</p>';
    } else {
      typeKeys.sort(function (a, b) { return (types[b].uniqueCases || 0) - (types[a].uniqueCases || 0); });
      html += '<div class="li-table-wrap"><table class="li-table li-type-table"><thead><tr>' +
        '<th>' + escapeHtml(t('insights.perf.detail.case_type_col')) + '</th>' +
        '<th>' + escapeHtml(t('insights.perf.table.cases')) + '</th>' +
        '<th>' + escapeHtml(t('insights.perf.table.avg_turnaround')) + '</th>' +
        '<th>' + escapeHtml(t('insights.perf.table.on_time')) + '</th>' +
        '<th>' + escapeHtml(t('insights.perf.table.remake_rate')) + '</th>' +
        '</tr></thead><tbody>';
      typeKeys.forEach(function (type) {
        var ct = types[type];
        var typeName = type === 'Unknown' ? t('insights.perf.detail.unknown_type') : type;
        var ctBench = (bench.caseTypes && bench.caseTypes[type] && bench.caseTypes[type].avgTurnaroundDays)
          ? bench.caseTypes[type].avgTurnaroundDays : null;
        var ctTurn = metricValueHtml(ct.avgTurnaroundDays, ct.turnaroundN, ct.turnaroundN >= (liPerf.meta ? liPerf.meta.minSampleSize : 5), fmtDays);
        if (ctBench) {
          var faster = ctBench.delta < 0;
          ctTurn += '<div class="li-metric-note">' + escapeHtml(
            faster
              ? t('insights.perf.context.vs_faster', { delta: Math.abs(ctBench.delta) })
              : (Math.abs(ctBench.delta) < 0.5
                  ? t('insights.perf.context.vs_similar')
                  : t('insights.perf.context.vs_slower', { delta: ctBench.delta }))) + '</div>';
        }
        html += '<tr>' +
          '<td>' + escapeHtml(typeName) + '</td>' +
          '<td>' + escapeHtml(fmtCount(ct.uniqueCases)) + '</td>' +
          '<td>' + ctTurn + '</td>' +
          '<td>' + metricValueHtml(ct.onTimePct, ct.onTimeN, ct.onTimeN >= (liPerf.meta ? liPerf.meta.minSampleSize : 5), fmtPercent) + '</td>' +
          '<td>' + metricValueHtml(ct.remakeRatePct, ct.uniqueCases, ct.sufficient, fmtPercent) + '</td>' +
          '</tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '</div>';

    // ── Remakes ──
    html += '<div class="li-detail-block" id="liDetailRemakes">' +
      '<h3 class="li-detail-heading">' + escapeHtml(t('insights.perf.detail.remakes')) + '</h3>';

    if ((rm.total || 0) === 0) {
      html += '<p class="li-muted li-remake-empty">' + escapeHtml(t('insights.perf.detail.no_remakes')) + '</p>';
    } else {
      html += '<div class="li-detail-stats li-remake-stats">';
      html += detailStatCard(t('insights.perf.detail.total_events'), escapeHtml(fmtCount(rm.total)));
      html += detailStatCard(t('insights.perf.detail.cases_with_remakes'), escapeHtml(fmtCount(rm.casesWithRemakes)));
      html += detailStatCard(t('insights.perf.summary.remake_rate'),
        metricValueHtml(rm.remakeRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent));
      html += detailStatCard(t('insights.perf.summary.lab_remake_rate'),
        metricValueHtml(rm.labAttributedRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent));
      html += detailStatCard(t('insights.perf.detail.multi_remake_cases'), escapeHtml(fmtCount(rm.multiRemakeCases)));
      html += detailStatCard(t('insights.perf.detail.open_remakes'),
        escapeHtml(fmtCount(rm.openRemakes)) +
        '<div class="li-metric-note">' + escapeHtml(t('insights.perf.context.workload_now')) + '</div>');
      html += detailStatCard(t('insights.perf.detail.avg_duration'),
        metricValueHtml(rm.avgDurationDays, rm.durationN, rm.durationN > 0, fmtDays));
      html += '</div>';

      html += '<p class="li-remake-explainer">' + escapeHtml(t('insights.perf.detail.remake_explainer')) + '</p>';

      html += '<div class="li-remake-breakdowns">';
      html += renderBreakdownBars(t('insights.perf.detail.reasons'), rm.reasons, 'remakes.reasons.');
      html += renderBreakdownBars(t('insights.perf.detail.attribution'), rm.attributions, 'remakes.attribution.');
      html += '</div>';
    }
    html += '</div>';

    // ── Current workload drill-down (legacy patient rows, lab scope only) ──
    if (!isPractice) {
      var rows = liWorkloadByLab[liSelectedLab] || [];
      html += '<div class="li-detail-block">' +
        '<h3 class="li-detail-heading">' + escapeHtml(t('insights.perf.detail.workload_heading')) + '</h3>' +
        renderWorkloadDrilldown(liSelectedLab) +
        '</div>';
    }

    body.innerHTML = html;

    var metricSel = document.getElementById('liTrendMetricSelect');
    if (metricSel) {
      metricSel.addEventListener('change', function () {
        liTrendMetric = metricSel.value;
        renderPerfTrendChart();
      });
    }
    var granSel = document.getElementById('liTrendGranularitySelect');
    if (granSel) {
      granSel.addEventListener('change', function () {
        liTrendGranularity = granSel.value;
        renderPerfTrendChart();
      });
    }
    renderPerfTrendChart();
  }

  /** Horizontal CSS bars for reason/attribution breakdowns. */
  function renderBreakdownBars(title, counts, i18nPrefix) {
    if (!counts || Object.keys(counts).length === 0) {
      return '<div class="li-breakdown"><h4 class="li-breakdown-title">' + escapeHtml(title) + '</h4>' +
        '<p class="li-muted">' + escapeHtml(t('insights.perf.detail.no_remakes')) + '</p></div>';
    }
    var max = 0;
    Object.keys(counts).forEach(function (k) { max = Math.max(max, counts[k]); });
    var html = '<div class="li-breakdown"><h4 class="li-breakdown-title">' + escapeHtml(title) + '</h4><ul class="li-bar-list">';
    Object.keys(counts).forEach(function (code) {
      var label = t(i18nPrefix + code) || code;
      var w = max > 0 ? Math.max(4, Math.round((counts[code] / max) * 100)) : 0;
      html += '<li class="li-bar-row">' +
        '<span class="li-bar-label">' + escapeHtml(label) + '</span>' +
        '<span class="li-bar-track"><span class="li-bar-fill" style="width:' + w + '%"></span></span>' +
        '<span class="li-bar-count">' + escapeHtml(fmtCount(counts[code])) + '</span>' +
        '</li>';
    });
    html += '</ul></div>';
    return html;
  }

  // ── Performance Trends: adaptive rendering ────────────────────────────

  var LI_TREND_METRICS = {
    turnaroundDays:  { kind: 'line', fmt: 'days',  prevKey: 'avgTurnaroundDays' },
    onTimePct:       { kind: 'line', fmt: 'pct',   prevKey: 'onTimePct' },
    completedCases:  { kind: 'bar',  fmt: 'count', prevKey: 'volumeUniqueCases' },
    remakeRatePct:   { kind: 'line', fmt: 'pct',   prevKey: 'remakeRatePct' },
  };

  function liTrendScope() {
    return (liSelectedLab && liSelectedLab !== 'practice') ? liSelectedLab : 'practice';
  }

  /** Days covered by the current reporting range (null when unbounded). */
  function liTrendSpanDays() {
    var r = liPerf && liPerf.range;
    if (!r || !r.start || !r.end) { return null; }
    var s = Date.parse(r.start), e = Date.parse(r.end);
    if (isNaN(s) || isNaN(e)) { return null; }
    return Math.round((e - s) / 86400000);
  }

  function liTrendMonthBucketCount() {
    var m = liPerf && liPerf.trends && liPerf.trends.month;
    return m && m.buckets ? m.buckets.length : 0;
  }

  /** Auto granularity: week for short ranges, quarter for long histories. */
  function liTrendAutoGranularity() {
    var span = liTrendSpanDays();
    var hasWeek = !!(liPerf && liPerf.trends && liPerf.trends.week);
    if (hasWeek && span !== null && span <= 130) { return 'week'; }
    if ((span !== null && span > 550) || liTrendMonthBucketCount() > 18) { return 'quarter'; }
    return 'month';
  }

  function liTrendResolvedGranularity() {
    var g = liTrendGranularity;
    if (g === 'auto') { return liTrendAutoGranularity(); }
    // Fall back when the requested granularity has no backing data.
    if (g === 'week' && !(liPerf.trends && liPerf.trends.week)) { return 'month'; }
    if (g === 'quarter') { return 'quarter'; } // aggregated client-side from months
    return 'month';
  }

  /** 'YYYY-MM' -> 'Nov 2025'; '2025-W45' -> 'W45 2025'. */
  function liTrendBucketLabel(b, gran) {
    if (gran === 'quarter') {
      var qm = b.match(/^(\d{4})-Q([1-4])$/);
      return qm ? 'Q' + qm[2] + ' ' + qm[1] : b;
    }
    if (gran === 'week') {
      var wm = b.match(/^(\d{4})-W(\d{2})$/);
      return wm ? 'W' + wm[2] + ' ' + wm[1].slice(2) : b;
    }
    var mm = b.match(/^(\d{4})-(\d{2})$/);
    if (!mm) { return b; }
    var names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return names[parseInt(mm[2], 10) - 1] + ' ' + mm[1];
  }

  /**
   * Extract {buckets, values, aux, granularity} for the current scope+metric.
   * Quarter granularity is aggregated client-side from monthly buckets
   * (turnaround/on-time pooled with their sample sizes so zeros and missing
   * data are never fabricated).
   */
  function liTrendData() {
    var tr = liPerf.trends;
    var gran = liTrendResolvedGranularity();
    var scope = liTrendScope();
    var src = (gran === 'quarter') ? tr.month : (tr[gran] || tr.month);
    var series = (src && src.series && src.series[scope]) || {};
    var buckets = (src && src.buckets) || [];
    var metric = LI_TREND_METRICS[liTrendMetric] || LI_TREND_METRICS.turnaroundDays;

    if (gran !== 'quarter') {
      return {
        buckets: buckets.slice(),
        values: buckets.map(function (b) { return series[liTrendMetric] ? series[liTrendMetric][b] : null; }),
        aux: {
          n: function (b) { return series.completedCases ? series.completedCases[b] : null; },
          eligible: function (b) { return series.onTimeEligible ? series.onTimeEligible[b] : null; },
          on: function (b) { return series.onTimeOn ? series.onTimeOn[b] : null; },
          events: function (b) { return series.remakeEvents ? series.remakeEvents[b] : null; },
          volume: function (b) { return series.volumeUniqueCases ? series.volumeUniqueCases[b] : null; },
        },
        granularity: gran,
      };
    }

    // Aggregate months into quarters.
    var qmap = {}; // 'YYYY-Qn' => {order, vals...}
    var order = [];
    buckets.forEach(function (b, i) {
      var m = b.match(/^(\d{4})-(\d{2})$/);
      if (!m) { return; }
      var q = 'Q' + (Math.ceil(parseInt(m[2], 10) / 3));
      var key = m[1] + '-' + q;
      if (!qmap[key]) {
        qmap[key] = { order: order.length, turnSum: 0, turnN: 0, on: 0, late: 0,
                      completed: 0, events: 0, volume: 0 };
        order.push(key);
      }
      var qv = qmap[key];
      var get = function (k) { return series[k] ? series[k][b] : null; };
      var td = get('turnaroundDays'), cn = get('completedCases') || 0;
      if (td !== null && td !== undefined && cn > 0) { qv.turnSum += td * cn; qv.turnN += cn; }
      qv.completed += get('completedCases') || 0;
      qv.on += get('onTimeOn') || 0;
      qv.late += get('onTimeLate') || 0;
      qv.events += get('remakeEvents') || 0;
      qv.volume += get('volumeUniqueCases') || 0;
    });
    var qb = order, qv = order.map(function (k) { return qmap[k]; });
    var qvalues = qv.map(function (q) {
      if (liTrendMetric === 'turnaroundDays') { return q.turnN > 0 ? q.turnSum / q.turnN : null; }
      if (liTrendMetric === 'onTimePct') { return (q.on + q.late) > 0 ? (q.on / (q.on + q.late)) * 100 : null; }
      if (liTrendMetric === 'completedCases') { return q.completed; }
      if (liTrendMetric === 'remakeRatePct') { return q.volume > 0 ? (q.events / q.volume) * 100 : null; }
      return null;
    });
    return {
      buckets: qb,
      values: qvalues,
      aux: {
        n: function (b) { return qmap[b].turnN || null; },
        eligible: function (b) { var q = qmap[b]; return (q.on + q.late) || null; },
        on: function (b) { return qmap[b].on || null; },
        events: function (b) { return qmap[b].events || null; },
        volume: function (b) { return qmap[b].volume || null; },
      },
      granularity: 'quarter',
    };
  }

  /** Compact range summary above the chart (value + sample + prev-period). */
  function renderTrendSummary() {
    var el = document.getElementById('liTrendSummary');
    if (!el) { return; }
    var d = selectedScopeData();
    var metric = LI_TREND_METRICS[liTrendMetric] || LI_TREND_METRICS.turnaroundDays;
    var html = '';
    if (d) {
      var value = null, n = null;
      if (liTrendMetric === 'turnaroundDays') { value = d.turnaround && d.turnaround.avgDays; n = d.turnaround && d.turnaround.n; }
      else if (liTrendMetric === 'onTimePct') { value = d.onTime && d.onTime.pct; n = d.onTime && d.onTime.n; }
      else if (liTrendMetric === 'completedCases') { value = d.completed && d.completed.uniqueCases; n = null; }
      else if (liTrendMetric === 'remakeRatePct') {
        value = d.remakes && d.remakes.remakeRatePct;
        n = d.remakes && d.remakes.rateDenominator;
      }
      if (value !== null && value !== undefined) {
        var fmt = metric.fmt === 'pct' ? fmtPercent : (metric.fmt === 'count' ? fmtCount : fmtDays);
        html += '<span class="li-trend-summary-value">' + escapeHtml(fmt(value)) + '</span>';
        html += '<span class="li-trend-summary-label">' + escapeHtml(t('insights.perf.detail.trend_summary_label')) + '</span>';
        if (n !== null && n !== undefined && metric.fmt !== 'count') {
          html += '<span class="li-trend-summary-n">' + escapeHtml(
            I18n.pluralize(n, 'insights.perf.detail.trend_based_on')) + '</span>';
        }
        var prev = d.benchmarks && d.benchmarks.previousPeriod && d.benchmarks.previousPeriod[metric.prevKey];
        if (prev && prev.delta !== null && prev.delta !== undefined) {
          var better = metric.prevKey === 'onTimePct' ? prev.delta > 0
            : (metric.prevKey === 'remakeRatePct' || metric.prevKey === 'volumeUniqueCases' ? prev.delta < 0 : prev.delta < 0);
          html += '<span class="li-trend-summary-delta' + (better ? ' li-trend-delta-good' : ' li-trend-delta-bad') + '">' +
            escapeHtml(t('insights.perf.detail.trend_vs_prev', { delta: fmt(Math.abs(prev.delta)) })) + '</span>';
        }
      }
    }
    el.innerHTML = html;
  }

  function renderPerfTrendChart() {
    var canvas = document.getElementById('liPerfTrendChart');
    var stateEl = document.getElementById('liTrendState');
    var wrap = document.getElementById('liTrendChartWrap');
    if (!canvas || !liPerf || !liPerf.trends) { return; }
    if (liPerfChart) { liPerfChart.destroy(); liPerfChart = null; }
    renderTrendSummary();

    var metric = LI_TREND_METRICS[liTrendMetric] || LI_TREND_METRICS.turnaroundDays;
    var data = liTrendData();
    var gran = data.granularity;
    var buckets = data.buckets;
    var values = data.values;

    // Populate the granularity selector with only meaningful options.
    var granSel = document.getElementById('liTrendGranularitySelect');
    if (granSel) {
      var hasWeek = !!(liPerf.trends.week);
      var monthCount = liTrendMonthBucketCount();
      var opts = [];
      if (hasWeek) { opts.push(['week', t('insights.perf.detail.gran_week')]); }
      opts.push(['month', t('insights.perf.detail.gran_month')]);
      if (monthCount > 4) { opts.push(['quarter', t('insights.perf.detail.gran_quarter')]); }
      var cur = liTrendGranularity === 'auto' ? gran : liTrendGranularity;
      if (opts.every(function (o) { return o[0] !== cur; })) { cur = gran; }
      granSel.innerHTML = opts.map(function (o) {
        return '<option value="' + o[0] + '"' + (o[0] === cur ? ' selected' : '') + '>' + escapeHtml(o[1]) + '</option>';
      }).join('');
    }

    // Trim leading buckets with no observations for the selected metric.
    // Line metrics: only null/undefined are "no data" (a real 0% is data).
    // Bar metric: leading zero months before first activity are noise.
    var isBar = metric.kind === 'bar';
    var isEmptyVal = function (v) {
      if (v === null || v === undefined) { return true; }
      return isBar && v === 0;
    };
    var hasAny = false, hasPositive = false;
    for (var i = 0; i < values.length; i++) {
      var vv = values[i];
      if (vv !== null && vv !== undefined) { hasAny = true; }
      if (vv !== null && vv !== undefined && vv !== 0) { hasPositive = true; }
    }
    var firstIdx = 0;
    while (firstIdx < values.length && isEmptyVal(values[firstIdx])) { firstIdx++; }
    if (firstIdx > 0 && firstIdx < values.length) {
      buckets = buckets.slice(firstIdx);
      values = values.slice(firstIdx);
    }

    // Observation count drives the adaptive state.
    var obs = 0, firstVal = null, firstAuxN = null;
    for (var j = 0; j < values.length; j++) {
      var v = values[j];
      if (isEmptyVal(v)) { continue; }
      obs++;
      if (firstVal === null) {
        firstVal = v;
        firstAuxN = metric.fmt === 'days' ? (data.aux.n(buckets[j]) || 0)
          : (metric.fmt === 'pct' ? (data.aux.eligible(buckets[j]) || 0) : v);
      }
    }

    var isPct = metric.fmt === 'pct';

    // ── Adaptive states ──
    if (stateEl) { stateEl.style.display = 'none'; stateEl.innerHTML = ''; }
    canvas.style.display = 'none';
    if (wrap) { wrap.classList.remove('li-trend-chart--compact', 'li-trend-chart--summary'); }

    if (!hasAny || obs === 0) {
      // No qualifying observations at all.
      if (stateEl) {
        stateEl.style.display = 'block';
        stateEl.innerHTML = '<div class="li-trend-empty">' +
          '<strong>' + escapeHtml(t('insights.perf.detail.trend_empty_title')) + '</strong>' +
          '<span>' + escapeHtml(t('insights.perf.detail.trend_empty_body')) + '</span></div>';
      }
      if (wrap) { wrap.classList.add('li-trend-chart--compact'); }
      return;
    }
    if (obs === 1) {
      // Single observation: compact summary, no chart.
      var fmtSingle = metric.fmt === 'pct' ? fmtPercent : (metric.fmt === 'count' ? fmtCount : fmtDays);
      var singleN = metric.fmt === 'days'
        ? I18n.pluralize(firstAuxN, 'insights.perf.detail.trend_cases_n')
        : (metric.fmt === 'pct' && firstAuxN > 0
            ? I18n.pluralize(firstAuxN, 'insights.perf.detail.trend_cases_n') : '');
      if (stateEl) {
        stateEl.style.display = 'block';
        stateEl.innerHTML = '<div class="li-trend-single">' +
          '<div class="li-trend-single-value">' + escapeHtml(fmtSingle(firstVal)) + '</div>' +
          '<div class="li-trend-single-label">' + escapeHtml(t('insights.perf.detail.trend_single_label')) + '</div>' +
          (singleN ? '<div class="li-trend-single-n">' + escapeHtml(singleN) + '</div>' : '') +
          '<div class="li-trend-single-note">' + escapeHtml(t('insights.perf.detail.trend_single_note')) + '</div></div>';
      }
      if (wrap) { wrap.classList.add('li-trend-chart--summary'); }
      return;
    }

    // ── Full chart ──
    canvas.style.display = 'block';
    if (wrap) { wrap.classList.remove('li-trend-chart--compact', 'li-trend-chart--summary'); }

    var color = '#1e40af';
    var isNarrow = window.innerWidth < 480;
    var scope = liTrendScope();
    var labels = buckets.map(function (b) { return liTrendBucketLabel(b, gran); });

    function tooltipText(ctx) {
      var bi = ctx.dataIndex;
      var b = buckets[bi];
      var lines = [labels[bi]];
      var v = ctx.parsed.y;
      if (metric.fmt === 'days') {
        lines.push(fmtDays(v));
        var n = data.aux.n(b);
        if (n) { lines.push(I18n.pluralize(n, 'insights.perf.detail.trend_cases_n')); }
      } else if (metric.fmt === 'pct') {
        if (liTrendMetric === 'onTimePct') {
          var elig = data.aux.eligible(b) || 0, on = data.aux.on(b) || 0;
          lines.push(fmtPercent(v) + ' (' + on + '/' + elig + ')');
        } else {
          var ev = data.aux.events(b);
          lines.push(fmtPercent(v) + (ev !== null && ev !== undefined ? ' · ' + I18n.pluralize(ev, 'insights.perf.detail.trend_events_n') : ''));
        }
      } else {
        lines.push(I18n.pluralize(v, 'insights.perf.detail.trend_cases_n'));
      }
      return lines;
    }

    liPerfChart = new Chart(canvas.getContext('2d'), {
      type: isBar ? 'bar' : 'line',
      data: {
        labels: labels,
        datasets: [{
          label: scope === 'practice'
            ? t('insights.perf.detail.practice_option')
            : (selectedScopeData() ? selectedScopeData().name : scope),
          data: values,
          borderColor: color,
          backgroundColor: isBar ? color : color,
          tension: 0.3,
          fill: false,
          spanGaps: false,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { ticks: { autoSkip: true, maxRotation: isNarrow ? 45 : 0, font: { size: isNarrow ? 12 : 10, family: "'Poppins', sans-serif" } } },
          y: {
            beginAtZero: isBar || isPct,
            suggestedMax: isPct ? 100 : undefined,
            max: isPct ? 100 : undefined,
            min: isPct ? 0 : undefined,
            ticks: {
              precision: isPct || isBar ? 0 : 1,
              callback: isPct ? function (v) { return v + '%'; } : undefined,
              font: { size: isNarrow ? 12 : 10, family: "'Poppins', sans-serif" },
            },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (item) { return tooltipText(item); },
            },
          },
        },
      },
    });

    setChartAriaLabel(canvas, t('insights.labs.perf_trend_aria'), labels, values,
      function (v) { return v === null ? t('insights.empty.no_data') : String(v); });
  }

  function selectLab(labKey, opts) {
    liSelectedLab = labKey;
    renderLabDetailSelect();
    renderPerfTable();
    renderPerfDetail();
    liRecSyncScope();
    if (opts && opts.noScroll) { return; }
    var detail = document.getElementById('liLabDetail');
    if (detail) {
      detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      var sel = document.getElementById('liLabDetailSelect');
      if (sel) { sel.focus({ preventScroll: true }); }
    }
  }

  function renderRevisionsSection(labs) {
    var section = document.getElementById('liRevisionsSection');
    var tbody = document.getElementById('liRevisionsBody');
    if (!section || !tbody) { return; }

    var anyRevisions = (labs || []).some(function (l) { return (l.revisionCount || 0) > 0; });
    if (!anyRevisions) {
      section.style.display = 'none';
      return;
    }
    section.style.display = 'block';
    tbody.innerHTML = '';
    labs.forEach(function (l) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><span class="li-lab-name">' + escapeHtml(l.name) + '</span></td>' +
        '<td>' + escapeHtml(fmtCount(l.revisionCount)) + '</td>' +
        '<td>' + escapeHtml(fmtPercent(l.revisionRate)) + '</td>';
      tbody.appendChild(tr);
    });
  }

  /** Render the performance layout; returns false when no perf payload. */
  function renderPerformance(data) {
    var perfWrap = document.getElementById('liPerf');
    var legacy = document.getElementById('liLegacy');
    if (!perfWrap || !legacy) { return false; }

    if (!data.performance) {
      perfWrap.style.display = 'none';
      legacy.style.display = 'block';
      return false;
    }

    liPerf = data.performance;
    liPerfLabs = (liPerf.labs || []).slice();
    if (!liSelectedLab) { liSelectedLab = 'practice'; }
    populateCaseTypeFilter();

    try {
      renderPerfSummary(liPerf.practice);
      try {
        renderRecommendations(data.recommendations);
      } catch (recErr) {
        // A bad recommendation item must not take down the perf layout.
        if (typeof console !== 'undefined' && console.error) {
          console.error('[Lab Insights] Recommendations render failed:', recErr);
        }
        var recSection = document.getElementById('liRecs');
        if (recSection) { recSection.style.display = 'none'; }
      }
      renderPerfTable();
      renderLabDetailSelect();
      renderPerfDetail();
      renderRevisionsSection(data.labs || []);
    } catch (e) {
      // Any render failure must not break the page - restore the legacy
      // layout and let the existing renderers handle the payload.
      if (typeof console !== 'undefined' && console.error) {
        console.error('[Lab Insights] Performance render failed, using legacy layout:', e);
      }
      perfWrap.style.display = 'none';
      legacy.style.display = 'block';
      return false;
    }

    legacy.style.display = 'none';
    perfWrap.style.display = 'block';
    return true;
  }

  // ======================================================================
  // Legacy layout (performance payload absent)
  // ======================================================================

  function renderSummary(summary) {
    if (!summary) { return; }
    document.getElementById('liActiveLabs').textContent = fmtCount(summary.activeLabs);
    document.getElementById('liCasesAtLabs').textContent = fmtCount(summary.casesCurrentlyAtLabs);
    document.getElementById('liAvgTurnaround').textContent = summary.avgTurnaroundDays !== null ? fmtDays(summary.avgTurnaroundDays) : '—';
    document.getElementById('liLateCases').textContent = fmtCount(summary.lateCasesAtLabs);
    document.getElementById('liRevisions').textContent = fmtCount(summary.totalRevisions);
    document.getElementById('liDirectTransfers').textContent = fmtCount(summary.directLabTransfers);
  }

  function sortLabs() {
    var key = liSort.key;
    var dir = liSort.dir === 'asc' ? 1 : -1;
    liLabs.sort(function (a, b) {
      var av = a[key];
      var bv = b[key];
      // Nulls (e.g. no turnaround sample yet) sort last regardless of direction.
      if (av === null && bv === null) { return 0; }
      if (av === null) { return 1; }
      if (bv === null) { return -1; }
      if (typeof av === 'string') {
        return dir * av.localeCompare(bv);
      }
      return dir * (av - bv);
    });
  }

  function renderSortIndicators() {
    var headers = document.querySelectorAll('#liLabTable thead th');
    headers.forEach(function (th) {
      th.classList.remove('li-sort-active');
      var existingArrow = th.querySelector('.li-sort-arrow');
      if (existingArrow) { existingArrow.remove(); }
      if (th.dataset.sort === liSort.key) {
        th.classList.add('li-sort-active');
        var arrow = document.createElement('span');
        arrow.className = 'li-sort-arrow';
        arrow.textContent = liSort.dir === 'asc' ? '▲' : '▼';
        th.appendChild(arrow);
      }
    });
  }

  function renderWorkloadDrilldown(labKey) {
    var rows = liWorkloadByLab[labKey] || [];
    if (rows.length === 0) {
      return '<div class="li-workload-inner"><p class="li-muted">' + t('insights.labs.nothing_in_progress') + '</p></div>';
    }
    var html = '<div class="li-workload-inner"><table class="li-workload-table"><thead><tr>' +
      '<th>' + t('insights.labs.patient') + '</th>' +
      '<th>' + t('insights.labs.type') + '</th>' +
      '<th>' + t('insights.labs.status') + '</th>' +
      '<th>' + t('insights.labs.due_date') + '</th>' +
      '<th>' + t('insights.labs.days_late') + '</th></tr></thead><tbody>';
    rows.forEach(function (r) {
      html += '<tr>' +
        '<td>' + escapeHtml(r.patientName || r.caseId) + '</td>' +
        '<td>' + escapeHtml(r.caseType || '—') + '</td>' +
        '<td>' + escapeHtml((r.status && typeof getStageLabel === 'function' ? getStageLabel(r.status) : r.status) || '—') + '</td>' +
        '<td>' + escapeHtml(r.dueDate || '—') + '</td>' +
        '<td>' + (r.daysLate !== null ? '<span class="li-days-late">' + escapeHtml(r.daysLate) + '</span>' : '—') + '</td>' +
        '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
  }

  function renderTable() {
    var tbody = document.getElementById('liLabTableBody');
    if (!tbody) { return; }
    tbody.innerHTML = '';

    sortLabs();
    renderSortIndicators();

    liLabs.forEach(function (lab) {
      var row = document.createElement('tr');
      row.className = 'li-lab-row';
      row.dataset.labKey = lab.labKey;

      var nameCell = lab.isLive
        ? '<span class="li-lab-name" title="' + escapeHtml(lab.name) + '">' + escapeHtml(lab.name) + '</span>'
        : '<span class="li-lab-name li-lab-name-removed" title="' + escapeHtml(lab.name) + t('insights.labs.removed_suffix') + '">' + escapeHtml(lab.name) + '</span>';

      var turnaroundCell = (lab.avgTurnaroundDays !== null)
        ? escapeHtml(fmtDays(lab.avgTurnaroundDays))
        : '<span class="li-muted">—</span>';

      var lateCell = (lab.lateCaseRate !== null)
        ? escapeHtml(fmtPercent(lab.lateCaseRate)) + ' <span class="li-muted">(' + fmtCount(lab.lateCaseCount) + ')</span>'
        : '<span class="li-muted">—</span>';

      var lateDeliveryCell = (lab.lateDeliveryRate !== null)
        ? escapeHtml(fmtPercent(lab.lateDeliveryRate)) + ' <span class="li-muted">(' + fmtCount(lab.lateDeliverySampleSize) + ')</span>'
        : '<span class="li-muted">—</span>';

      var revisionRateCell = (lab.revisionRate !== null)
        ? escapeHtml(fmtPercent(lab.revisionRate))
        : '<span class="li-muted">—</span>';

      row.innerHTML =
        '<td>' + nameCell + '</td>' +
        '<td>' + fmtCount(lab.currentWorkload) + '</td>' +
        '<td>' + fmtCount(lab.casesAssigned) + '</td>' +
        '<td>' + fmtCount(lab.completed) + '</td>' +
        '<td>' + turnaroundCell + '</td>' +
        '<td>' + lateCell + '</td>' +
        '<td>' + lateDeliveryCell + '</td>' +
        '<td>' + fmtCount(lab.revisionCount) + '</td>' +
        '<td>' + revisionRateCell + '</td>' +
        '<td>' + fmtCount(lab.directTransfersOut) + '</td>';

      row.addEventListener('click', function () {
        toggleWorkloadRow(lab.labKey, row);
      });

      tbody.appendChild(row);

      if (liExpandedLabKey === lab.labKey) {
        var detailRow = document.createElement('tr');
        detailRow.className = 'li-workload-row';
        var td = document.createElement('td');
        td.colSpan = 10;
        td.innerHTML = renderWorkloadDrilldown(lab.labKey);
        detailRow.appendChild(td);
        tbody.appendChild(detailRow);
      }
    });
  }

  function toggleWorkloadRow(labKey) {
    liExpandedLabKey = (liExpandedLabKey === labKey) ? null : labKey;
    renderTable();
  }

  function renderTrend(trend) {
    var section = document.getElementById('liTrendSection');
    var canvas = document.getElementById('liTrendChart');
    if (!section || !canvas) { return; }

    if (liChart) {
      liChart.destroy();
      liChart = null;
    }

    if (!trend || !trend.series || trend.series.length === 0) {
      section.style.display = 'none';
      return;
    }

    section.style.display = 'block';

    var palette = ['#1e40af', '#f97316', '#10b981', '#8b5cf6', '#06b6d4'];
    var datasets = trend.series.map(function (s, i) {
      var color = palette[i % palette.length];
      return {
        label: s.label,
        data: s.data,
        borderColor: color,
        backgroundColor: color,
        tension: 0.3,
        fill: false,
      };
    });

    var isNarrow = window.innerWidth < 480;
    var ctx = canvas.getContext('2d');
    liChart = new Chart(ctx, {
      type: 'line',
      data: { labels: trend.labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            ticks: {
              autoSkip: true,
              maxRotation: (isNarrow ? 45 : 0),
              font: { size: (isNarrow ? 12 : 10), family: "'Poppins', sans-serif" }
            }
          },
          y: { beginAtZero: true, ticks: { precision: 0, font: { size: (isNarrow ? 12 : 10), family: "'Poppins', sans-serif" } } }
        },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              font: { size: (isNarrow ? 11 : 11), family: "'Poppins', sans-serif" },
              usePointStyle: true,
              pointStyle: 'circle',
              padding: isNarrow ? 8 : 12,
              boxWidth: isNarrow ? 10 : 12
            }
          }
        }
      }
    });

    var ariaLabels = (trend.labels || []);
    var ariaValues = datasets.length > 0 ? datasets[0].data : [];
    setChartAriaLabel(canvas, t('insights.labs.case_trend_aria'), ariaLabels, ariaValues, function(v) { return I18n.pluralize(v, 'insights.creators.case'); });
  }

  function attachStaticTooltips() {
    var turnaroundLabel = document.querySelector('#liAvgTurnaround').parentElement.querySelector('.li-label-with-tooltip');
    if (turnaroundLabel && !turnaroundLabel.querySelector('.dt-tooltip')) {
      attachTooltip(turnaroundLabel, t('insights.tooltips.average_lab_turnaround'));
    }
    var casesAssignedHeader = document.getElementById('liCasesAssignedHeader');
    if (casesAssignedHeader && !casesAssignedHeader.querySelector('.dt-tooltip')) {
      casesAssignedHeader.appendChild(document.createTextNode(' '));
      attachTooltip(casesAssignedHeader, t('insights.tooltips.cases_assigned_header'), true);
    }
    var completedHeader = document.getElementById('liCompletedHeader');
    if (completedHeader && !completedHeader.querySelector('.dt-tooltip')) {
      completedHeader.appendChild(document.createTextNode(' '));
      attachTooltip(completedHeader, t('insights.tooltips.completed_header'), true);
    }
    var turnaroundHeader = document.getElementById('liTurnaroundHeader');
    if (turnaroundHeader && !turnaroundHeader.querySelector('.dt-tooltip')) {
      turnaroundHeader.appendChild(document.createTextNode(' '));
      attachTooltip(turnaroundHeader, t('insights.tooltips.avg_turnaround_header'), true);
    }
    var lateRateHeader = document.getElementById('liLateRateHeader');
    if (lateRateHeader && !lateRateHeader.querySelector('.dt-tooltip')) {
      lateRateHeader.appendChild(document.createTextNode(' '));
      attachTooltip(lateRateHeader, t('insights.tooltips.late_rate_header'), true);
    }
    var lateDeliveryRateHeader = document.getElementById('liLateDeliveryRateHeader');
    if (lateDeliveryRateHeader && !lateDeliveryRateHeader.querySelector('.dt-tooltip')) {
      lateDeliveryRateHeader.appendChild(document.createTextNode(' '));
      attachTooltip(lateDeliveryRateHeader, t('insights.tooltips.late_delivery_rate_header'), true);
    }
  }

  function render(data) {
    showEmptyStates(data.hasLabs, data.hasHistory);
    bindOpsNavigation();
    renderOperationalSummary(data);

    liLabs = data.labs || [];
    liAttnPopulateLabFilter(liLabs);
    renderLiAttentionQueue(data);
    liWorkloadByLab = {};
    (data.currentWorkload || []).forEach(function (row) {
      if (!liWorkloadByLab[row.labKey]) { liWorkloadByLab[row.labKey] = []; }
      liWorkloadByLab[row.labKey].push(row);
    });

    if (!data.hasLabs || !data.hasHistory) {
      var opsWrap = document.getElementById('liOps');
      if (opsWrap) { opsWrap.style.display = 'none'; }
      var attnWrap = document.getElementById('liAttentionSection');
      if (attnWrap) { attnWrap.style.display = 'none'; }
      clearLabTable();
      if (liChart) {
        liChart.destroy();
        liChart = null;
      }
      var trendSection = document.getElementById('liTrendSection');
      if (trendSection) { trendSection.style.display = 'none'; }
      var perfWrap = document.getElementById('liPerf');
      var legacyWrap = document.getElementById('liLegacy');
      if (perfWrap) { perfWrap.style.display = 'none'; }
      if (legacyWrap) { legacyWrap.style.display = 'none'; }
      return;
    }

    if (renderPerformance(data)) {
      return; // performance layout handled everything
    }

    // Legacy fallback (performance payload absent)
    renderSummary(data.summary);
    attachStaticTooltips();
    renderTable();
    renderTrend(data.trend);
  }

  function fetchAndRender() {
    // Plan entitlement is emitted by main.php; anything other than a strict
    // true (including an evaluation failure) must not fetch protected data.
    if (window.userHasControlAccess !== true) {
      setLoading(false);
      return;
    }

    setLoading(true);
    setError('');
    var range = document.getElementById('liRangeSelect') ? document.getElementById('liRangeSelect').value : '12';

    // A pending flag set by activateInsightsSubview() marks this request as a
    // screen visit; it is cleared only when the screen renders successfully.
    var headers = {};
    if (window.__insightsVisitPending && window.__insightsVisitPending.labs) {
      headers['X-Insights-Visit'] = '1';
    }

    fetch('api/get-lab-insights.php?range=' + encodeURIComponent(range), { credentials: 'same-origin', headers: headers })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) {
            var err = new Error('Request failed with status ' + response.status);
            err.serverMessage = data && data.message;
            err.errorCode = data && data.error_code;
            throw err;
          }
          return data;
        });
      })
      .then(function (data) {
        setLoading(false);
        if (!data || !data.success) {
          setError((data && data.message) ? data.message : (t('insights.error.labs_data') || 'Unable to load lab insights.'));
          return;
        }
        setError('');
        if (window.__insightsVisitPending) {
          window.__insightsVisitPending.labs = false;
        }
        render(data);
      })
      .catch(function (error) {
        setLoading(false);
        // A mid-session entitlement loss surfaces the server's message
        // (e.g. upgrade_required) rather than a generic failure.
        var catchMsg = (error && error.errorCode === 'upgrade_required' && error.serverMessage)
          ? error.serverMessage
          : (t('insights.error.labs_data') || 'Unable to load lab insights. Please try again.');
        setError(catchMsg);
        if (typeof console !== 'undefined' && console.error) {
          console.error('[Lab Insights] Failed to load lab insights:', error);
        }
      });
  }

  function initOnce() {
    var headers = document.querySelectorAll('#liLabTable thead th');
    headers.forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.dataset.sort;
        if (liSort.key === key) {
          liSort.dir = liSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          liSort.key = key;
          liSort.dir = (key === 'name') ? 'asc' : 'desc';
        }
        renderTable();
      });
    });

    var perfHeaders = document.querySelectorAll('#liPerfTable thead th');
    perfHeaders.forEach(function (th) {
      th.addEventListener('click', function () {
        var key = th.dataset.sort;
        if (!key || th.hasAttribute('data-nosort')) { return; }
        if (liPerfSort.key === key) {
          liPerfSort.dir = liPerfSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          liPerfSort.key = key;
          liPerfSort.dir = (key === 'name') ? 'asc' : 'desc';
        }
        renderPerfTable();
      });
    });

    var detailSelect = document.getElementById('liLabDetailSelect');
    if (detailSelect) {
      detailSelect.addEventListener('change', function () {
        liSelectedLab = detailSelect.value;
        renderPerfTable();
        renderPerfDetail();
        liRecSyncScope();
      });
    }

    // Lab Insights & Recommendations controls: laboratory scope, lifecycle
    // state chips, and the view-all toggle.
    var recScope = document.getElementById('liRecScope');
    if (recScope) {
      recScope.addEventListener('change', function () {
        selectLab(recScope.value, { noScroll: true });
      });
    }
    var recChips = document.getElementById('liRecStateChips');
    if (recChips) {
      recChips.addEventListener('click', function (e) {
        var chip = e.target.closest('.lir-chip');
        if (!chip) { return; }
        liRecStateFilter = chip.dataset.recStateFilter || 'active';
        liRecShowAll = false;
        recChips.querySelectorAll('.lir-chip').forEach(function (c) {
          c.classList.toggle('is-active', c === chip);
        });
        renderRecommendationLists();
      });
    }
    var recMore = document.getElementById('liRecsMore');
    if (recMore) {
      recMore.addEventListener('click', function () {
        liRecShowAll = !liRecShowAll;
        renderRecommendationLists();
      });
    }

    var caseTypeSelect = document.getElementById('liCaseTypeFilter');
    if (caseTypeSelect) {
      caseTypeSelect.addEventListener('change', function () {
        liCaseType = caseTypeSelect.value || '';
        renderPerfTable();
      });
    }

    // Delegated handlers for the comparison checkboxes and the
    // workload/overdue Case Board drill-down links inside perf rows.
    var perfBody = document.getElementById('liPerfTableBody');
    if (perfBody) {
      perfBody.addEventListener('change', function (e) {
        var cb = e.target.closest('.li-compare-check');
        if (!cb) { return; }
        var key = cb.getAttribute('data-lab-key');
        var idx = liCompare.indexOf(key);
        if (cb.checked) {
          if (idx === -1) {
            if (liCompare.length >= LI_COMPARE_MAX) {
              liCompare.shift(); // keep the two most recently selected
            }
            liCompare.push(key);
          }
        } else if (idx !== -1) {
          liCompare.splice(idx, 1);
        }
        renderPerfTable();
      });
      perfBody.addEventListener('click', function (e) {
        var link = e.target.closest('.li-cell-link');
        if (!link) { return; }
        e.preventDefault();
        e.stopPropagation();
        liNavigateToLabCases(link.getAttribute('data-li-nav') || 'lab_cases',
          link.getAttribute('data-li-lab') || '');
      });
    }

    // Same drill-down links inside the Lab Detail scorecard body.
    var detailBody = document.getElementById('liLabDetailBody');
    if (detailBody) {
      detailBody.addEventListener('click', function (e) {
        var link = e.target.closest('.li-cell-link');
        if (!link) { return; }
        e.preventDefault();
        liNavigateToLabCases(link.getAttribute('data-li-nav') || 'lab_cases',
          link.getAttribute('data-li-lab') || '');
      });
    }

    var refreshBtn = document.getElementById('liRefreshData');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', fetchAndRender);
    }

    var rangeSelect = document.getElementById('liRangeSelect');
    if (rangeSelect) {
      rangeSelect.addEventListener('change', fetchAndRender);
    }
  }

  var initialized = false;
  window.loadLabInsightsData = function () {
    if (!initialized) {
      initOnce();
      initialized = true;
    }
    fetchAndRender();
  };

  // Resize and orientation-change handler so the trend charts refit their
  // containers when the viewport changes or the tab becomes visible.
  var liResizeTimeout;
  function resizeLabCharts() {
    if (liChart && typeof liChart.resize === 'function') {
      liChart.resize();
    }
    if (liPerfChart && typeof liPerfChart.resize === 'function') {
      liPerfChart.resize();
    }
  }
  window.addEventListener('resize', function () {
    clearTimeout(liResizeTimeout);
    liResizeTimeout = setTimeout(resizeLabCharts, 150);
  });
  window.addEventListener('orientationchange', function () {
    setTimeout(resizeLabCharts, 300);
  });
  document.addEventListener('insightsVisible', resizeLabCharts);
})();
