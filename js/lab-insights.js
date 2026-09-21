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
  var liPerfSort = { key: 'turnaround', dir: 'asc' };
  var liSelectedLab = null;     // labKey or 'practice'
  var liTrendMetric = 'turnaroundDays';
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
  // Performance layout (new)
  // ======================================================================

  function perfSortValue(lab, key) {
    switch (key) {
      case 'name': return lab.name;
      case 'cases': return lab.volume ? lab.volume.uniqueCases : null;
      case 'turnaround': return lab.turnaround ? lab.turnaround.avgDays : null;
      case 'onTime': return lab.onTime ? lab.onTime.pct : null;
      case 'remakeRate': return lab.remakes ? lab.remakes.remakeRatePct : null;
      case 'labAttrRate': return lab.remakes ? lab.remakes.labAttributedRatePct : null;
      case 'workload': return lab.workload ? lab.workload.openCases : null;
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
        escapeHtml(I18n.pluralize(n, 'insights.perf.context.based_on_cases')) + '</div>';
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
  // Smart Recommendations
  // ======================================================================

  var LI_RECS_PREVIEW = 3;
  var LI_RECS_PLURAL_TYPES = {
    multi_remake: true,
    late_workload_lab: true,
    late_workload_practice: true
  };

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

  function recSeverityLabel(severity) {
    var known = { attention: 1, watch: 1, improvement: 1, info: 1 };
    return known[severity] ? t('insights.recs.severity.' + severity) : '';
  }

  /**
   * Render structured recommendation objects produced by
   * api/lab-recommendations.php. The frontend performs no metric math; it
   * only maps each item's params through i18n templates.
   */
  function renderRecommendations(recs) {
    var section = document.getElementById('liRecs');
    var list = document.getElementById('liRecsList');
    var moreBtn = document.getElementById('liRecsMore');
    if (!section || !list || !moreBtn) { return; }

    recs = (recs && Array.isArray(recs.items)) ? recs.items
      : (Array.isArray(recs) ? recs : []);
    if (recs.length === 0) {
      section.style.display = 'block';
      list.innerHTML = '<li class="ap-rec ap-rec-empty">' +
        escapeHtml(t('insights.recs.empty')) + '</li>';
      moreBtn.style.display = 'none';
      return;
    }

    section.style.display = 'block';
    var html = '';
    recs.forEach(function (rec, idx) {
      var severity = rec.severity || 'info';
      var hidden = idx >= LI_RECS_PREVIEW ? ' ap-rec-collapsed' : '';
      html += '<li class="ap-rec ap-rec-' + escapeHtml(severity) + hidden + '">' +
        '<span class="ap-rec-severity">' + escapeHtml(recSeverityLabel(severity)) + '</span>' +
        '<span class="ap-rec-text">' +
        '<strong class="ap-rec-title">' + escapeHtml(recHeading(rec)) + '</strong> ' +
        '<span class="ap-rec-msg">' + escapeHtml(recMessage(rec)) + '</span>' +
        '</span></li>';
    });
    list.innerHTML = html;

    var extra = recs.length - LI_RECS_PREVIEW;
    if (extra > 0) {
      moreBtn.style.display = '';
      moreBtn.textContent = I18n.pluralize(extra, 'insights.recs.more');
      moreBtn.onclick = function () {
        var collapsed = list.querySelectorAll('.ap-rec-collapsed');
        var showing = collapsed.length === 0;
        if (showing) {
          recs.forEach(function (rec, idx) {
            if (idx >= LI_RECS_PREVIEW && list.children[idx]) {
              list.children[idx].classList.add('ap-rec-collapsed');
            }
          });
        } else {
          collapsed.forEach(function (el) { el.classList.remove('ap-rec-collapsed'); });
        }
        moreBtn.textContent = showing
          ? I18n.pluralize(extra, 'insights.recs.more')
          : t('insights.recs.less');
      };
    } else {
      moreBtn.style.display = 'none';
      moreBtn.onclick = null;
    }
  }

  function renderPerfTable() {
    var tbody = document.getElementById('liPerfTableBody');
    if (!tbody) { return; }
    tbody.innerHTML = '';
    sortPerfLabs();
    renderPerfSortIndicators();

    liPerfLabs.forEach(function (lab) {
      var row = document.createElement('tr');
      row.className = 'li-lab-row' + (liSelectedLab === lab.labKey ? ' li-lab-row-selected' : '');
      row.dataset.labKey = lab.labKey;
      row.tabIndex = 0;
      row.setAttribute('role', 'button');
      row.setAttribute('aria-label', lab.name);

      var nameCell = lab.isLive
        ? '<span class="li-lab-name" title="' + escapeHtml(lab.name) + '">' + escapeHtml(lab.name) + '</span>'
        : '<span class="li-lab-name li-lab-name-removed" title="' + escapeHtml(lab.name) +
          escapeHtml(t('insights.perf.table.removed_suffix')) + '">' + escapeHtml(lab.name) + '</span>';

      var turnHtml = metricValueHtml(
        lab.turnaround.avgDays, lab.turnaround.n, lab.turnaround.sufficient, fmtDays);
      var turnBench = vsPracticeText(lab.benchmarks.vsPractice.avgTurnaroundDays, 'days');
      if (turnBench) {
        turnHtml += '<div class="li-metric-note">' + escapeHtml(turnBench) + '</div>';
      }

      var onTimeHtml = metricValueHtml(lab.onTime.pct, lab.onTime.n, lab.onTime.sufficient, fmtPercent);
      var onTimeBench = vsPracticeText(lab.benchmarks.vsPractice.onTimePct, 'pts');
      if (onTimeBench) {
        onTimeHtml += '<div class="li-metric-note">' + escapeHtml(onTimeBench) + '</div>';
      }

      var rm = lab.remakes;
      var remakeHtml = metricValueHtml(rm.remakeRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent);
      var labAttrHtml = metricValueHtml(rm.labAttributedRatePct, rm.rateDenominator, rm.rateSufficient, fmtPercent);

      var wl = lab.workload;
      var workloadHtml = escapeHtml(I18n.pluralize(wl.openCases || 0, 'insights.perf.context.cases_count'));
      if ((wl.late || 0) > 0) {
        workloadHtml += '<div class="li-metric-note li-metric-note-quiet">' +
          escapeHtml(I18n.pluralize(wl.late, 'insights.perf.context.late_count')) + '</div>';
      }

      row.innerHTML =
        '<td>' + nameCell + '</td>' +
        '<td>' + escapeHtml(fmtCount(lab.volume.uniqueCases)) + '</td>' +
        '<td>' + turnHtml + '</td>' +
        '<td>' + onTimeHtml + '</td>' +
        '<td>' + remakeHtml + '</td>' +
        '<td>' + labAttrHtml + '</td>' +
        '<td>' + workloadHtml + '</td>';

      row.addEventListener('click', function () { selectLab(lab.labKey); });
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          selectLab(lab.labKey);
        }
      });
      tbody.appendChild(row);
    });
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

  function detailStatCard(label, valueHtml) {
    return '<div class="li-stat">' +
      '<div class="li-stat-value">' + valueHtml + '</div>' +
      '<div class="li-stat-label">' + escapeHtml(label) + '</div></div>';
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
    // Static section title; the lab selector already names the scope, so
    // repeating it here just duplicates text.
    title.textContent = t('insights.perf.detail.title');

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

    subtitle.textContent = isPractice
      ? t('insights.perf.detail.practice_option')
      : (d.isLive ? '' : t('insights.perf.table.removed_suffix'));

    var html = '<div class="li-detail-stats">';

    // Cases + completed
    var casesHtml = escapeHtml(fmtCount(vol.uniqueCases));
    if (vol.engagements && vol.engagements !== vol.uniqueCases) {
      casesHtml += '<div class="li-metric-note">' + escapeHtml(
        I18n.pluralize(vol.engagements, 'insights.perf.context.engagements')) + '</div>';
    }
    html += detailStatCard(t('insights.perf.summary.lab_cases'), casesHtml);
    html += detailStatCard(t('insights.perf.detail.completed_cases'), escapeHtml(fmtCount(comp.uniqueCases)));

    // Turnaround w/ vsPractice + prev period
    var turnHtml = metricValueHtml(t8n.avgDays, t8n.n, t8n.sufficient, fmtDays);
    var vb = vsPracticeText(vs.avgTurnaroundDays, 'days');
    if (vb) { turnHtml += '<div class="li-metric-note">' + escapeHtml(vb) + '</div>'; }
    var pb = prevPeriodText(prev && prev.avgTurnaroundDays, fmtDays);
    if (pb) { turnHtml += '<div class="li-metric-note">' + escapeHtml(pb) + '</div>'; }
    html += detailStatCard(t('insights.perf.summary.avg_turnaround'), turnHtml);

    html += detailStatCard(t('insights.perf.detail.median_turnaround'),
      metricValueHtml(t8n.medianDays, t8n.n, t8n.sufficient, fmtDays));

    var onTimeHtml = metricValueHtml(ot.pct, ot.n, ot.sufficient, fmtPercent);
    var ob = vsPracticeText(vs.onTimePct, 'pts');
    if (ob) { onTimeHtml += '<div class="li-metric-note">' + escapeHtml(ob) + '</div>'; }
    var opb = prevPeriodText(prev && prev.onTimePct, fmtPercent);
    if (opb) { onTimeHtml += '<div class="li-metric-note">' + escapeHtml(opb) + '</div>'; }
    if (ot.n > 0 && ot.dueDateCoveragePct !== null && ot.dueDateCoveragePct < 100) {
      onTimeHtml += '<div class="li-metric-note">' +
        escapeHtml(t('insights.perf.context.due_date_coverage', { pct: ot.dueDateCoveragePct })) + '</div>';
    }
    html += detailStatCard(t('insights.perf.summary.on_time'), onTimeHtml);

    html += detailStatCard(t('insights.perf.detail.avg_days_late'),
      metricValueHtml(dl.avgDays, dl.n, dl.n >= (liPerf.meta ? liPerf.meta.minSampleSize : 5), fmtDays));

    var wlHtml = escapeHtml(I18n.pluralize(wl.openCases || 0, 'insights.perf.context.cases_count'));
    if ((wl.late || 0) > 0) {
      wlHtml += '<div class="li-metric-note li-metric-note-quiet">' +
        escapeHtml(I18n.pluralize(wl.late, 'insights.perf.context.late_count')) + '</div>';
    }
    wlHtml += '<div class="li-metric-note">' + escapeHtml(t('insights.perf.context.workload_now')) + '</div>';
    html += detailStatCard(t('insights.perf.detail.current_workload'), wlHtml);

    html += '</div>'; // .li-detail-stats

    // ── Trend (contained chart card, aligned with Practice Insights) ──
    html += '<div class="li-detail-block">' +
      '<div class="ap-chart-card full-width">' +
      '<div class="ap-chart-header">' +
      '<div>' +
      '<h3 class="ap-chart-title">' + escapeHtml(t('insights.perf.detail.trend')) + '</h3>' +
      '<p class="ap-chart-description">' + escapeHtml(t('insights.perf.detail.trend_subtitle')) + '</p>' +
      '</div>' +
      '<div class="ap-chart-controls">' +
      '<select class="ap-select li-trend-select" id="liTrendMetricSelect" aria-label="' +
        escapeHtml(t('insights.perf.detail.trend_metric')) + '">' +
      '<option value="turnaroundDays"' + (liTrendMetric === 'turnaroundDays' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_turnaround')) + '</option>' +
      '<option value="onTimePct"' + (liTrendMetric === 'onTimePct' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_on_time')) + '</option>' +
      '<option value="volumeUniqueCases"' + (liTrendMetric === 'volumeUniqueCases' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_volume')) + '</option>' +
      '<option value="remakeRatePct"' + (liTrendMetric === 'remakeRatePct' ? ' selected' : '') + '>' + escapeHtml(t('insights.perf.detail.trend_remake_rate')) + '</option>' +
      '</select></div></div>' +
      '<div class="ap-chart-container li-trend-chart">' +
      '<canvas id="liPerfTrendChart" role="img" aria-label="Lab performance trend"></canvas>' +
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
    html += '<div class="li-detail-block">' +
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

  function renderPerfTrendChart() {
    var canvas = document.getElementById('liPerfTrendChart');
    if (!canvas || !liPerf || !liPerf.trends) { return; }
    if (liPerfChart) { liPerfChart.destroy(); liPerfChart = null; }

    var scope = (liSelectedLab && liSelectedLab !== 'practice') ? liSelectedLab : 'practice';
    var series = liPerf.trends.series[scope];
    var months = liPerf.trends.months || [];
    if (!series || months.length === 0) { return; }

    var values = months.map(function (m) { return series[liTrendMetric][m]; });

    // Trim leading months that have no data for the selected metric so short
    // histories don't render a long run of empty axis before the first real
    // point. Trailing months stay: they represent the rest of the selected range.
    var firstDataIdx = 0;
    while (firstDataIdx < values.length && (values[firstDataIdx] === null || values[firstDataIdx] === undefined)) {
      firstDataIdx++;
    }
    if (firstDataIdx > 0 && firstDataIdx < values.length) {
      months = months.slice(firstDataIdx);
      values = values.slice(firstDataIdx);
    }

    var isBar = liTrendMetric === 'volumeUniqueCases';
    var isPct = liTrendMetric === 'onTimePct' || liTrendMetric === 'remakeRatePct';
    var color = '#1e40af';

    var isNarrow = window.innerWidth < 480;
    liPerfChart = new Chart(canvas.getContext('2d'), {
      type: isBar ? 'bar' : 'line',
      data: {
        labels: months,
        datasets: [{
          label: scope === 'practice'
            ? t('insights.perf.detail.practice_option')
            : (selectedScopeData() ? selectedScopeData().name : scope),
          data: values,
          borderColor: color,
          backgroundColor: isBar ? color : color,
          tension: 0.3,
          fill: false,
          spanGaps: true,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { ticks: { autoSkip: true, maxRotation: isNarrow ? 45 : 0, font: { size: isNarrow ? 12 : 10, family: "'Poppins', sans-serif" } } },
          y: { beginAtZero: true, suggestedMax: isPct ? 100 : undefined,
               ticks: { precision: isPct || isBar ? 0 : 1, font: { size: isNarrow ? 12 : 10, family: "'Poppins', sans-serif" } } },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (item) {
                var v = item.parsed.y;
                if (v === null || v === undefined) { return t('insights.charts.no_data'); }
                return isPct ? v + '%' : (isBar ? v + ' ' + t('insights.charts.dataset_cases') : I18n.pluralize(v, 'insights.metrics.days'));
              },
            },
          },
        },
      },
    });

    setChartAriaLabel(canvas, 'Lab performance trend', months, values,
      function (v) { return v === null ? 'no data' : String(v); });
  }

  function selectLab(labKey) {
    liSelectedLab = labKey;
    renderLabDetailSelect();
    renderPerfTable();
    renderPerfDetail();
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
    setChartAriaLabel(canvas, 'Lab case trend', ariaLabels, ariaValues, function(v) { return v + ' cases'; });
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

    liLabs = data.labs || [];
    liWorkloadByLab = {};
    (data.currentWorkload || []).forEach(function (row) {
      if (!liWorkloadByLab[row.labKey]) { liWorkloadByLab[row.labKey] = []; }
      liWorkloadByLab[row.labKey].push(row);
    });

    if (!data.hasLabs || !data.hasHistory) {
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
