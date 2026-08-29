/**
 * Lab Insights v1 - client-side rendering only.
 * All metrics are computed authoritatively server-side in
 * api/get-lab-insights.php; this file fetches, renders, sorts (client-side,
 * over already-fetched data - no extra requests), and manages empty states.
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

  function fmtDays(value) {
    if (value === null || value === undefined) { return '\u2014'; }
    return I18n.pluralize(value, 'insights.metrics.days');
  }

  function fmtPercent(value) {
    if (value === null || value === undefined) { return '\u2014'; }
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

  function renderSummary(summary) {
    if (!summary) { return; }
    document.getElementById('liActiveLabs').textContent = fmtCount(summary.activeLabs);
    document.getElementById('liCasesAtLabs').textContent = fmtCount(summary.casesCurrentlyAtLabs);
    document.getElementById('liAvgTurnaround').textContent = summary.avgTurnaroundDays !== null ? fmtDays(summary.avgTurnaroundDays) : '\u2014';
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
        arrow.textContent = liSort.dir === 'asc' ? '\u25B2' : '\u25BC';
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
        '<td>' + escapeHtml(r.caseType || '\u2014') + '</td>' +
        '<td>' + escapeHtml((r.status && typeof getStageLabel === 'function' ? getStageLabel(r.status) : r.status) || '\u2014') + '</td>' +
        '<td>' + escapeHtml(r.dueDate || '\u2014') + '</td>' +
        '<td>' + (r.daysLate !== null ? '<span class="li-days-late">' + escapeHtml(r.daysLate) + '</span>' : '\u2014') + '</td>' +
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
        : '<span class="li-muted">\u2014</span>';

      var lateCell = (lab.lateCaseRate !== null)
        ? escapeHtml(fmtPercent(lab.lateCaseRate)) + ' <span class="li-muted">(' + fmtCount(lab.lateCaseCount) + ')</span>'
        : '<span class="li-muted">\u2014</span>';

      var lateDeliveryCell = (lab.lateDeliveryRate !== null)
        ? escapeHtml(fmtPercent(lab.lateDeliveryRate)) + ' <span class="li-muted">(' + fmtCount(lab.lateDeliverySampleSize) + ')</span>'
        : '<span class="li-muted">\u2014</span>';

      var revisionRateCell = (lab.revisionRate !== null)
        ? escapeHtml(fmtPercent(lab.revisionRate))
        : '<span class="li-muted">\u2014</span>';

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

    var ctx = canvas.getContext('2d');
    liChart = new Chart(ctx, {
      type: 'line',
      data: { labels: trend.labels, datasets: datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'bottom' } },
      },
    });
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
    if (!data.hasLabs || !data.hasHistory) {
      return;
    }

    renderSummary(data.summary);

    liLabs = data.labs || [];
    liWorkloadByLab = {};
    (data.currentWorkload || []).forEach(function (row) {
      if (!liWorkloadByLab[row.labKey]) { liWorkloadByLab[row.labKey] = []; }
      liWorkloadByLab[row.labKey].push(row);
    });

    attachStaticTooltips();
    renderTable();
    renderTrend(data.trend);
  }

  function fetchAndRender() {
    setLoading(true);
    setError('');
    var range = document.getElementById('liRangeSelect') ? document.getElementById('liRangeSelect').value : '12';

    fetch('api/get-lab-insights.php?range=' + encodeURIComponent(range), { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Request failed with status ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        setLoading(false);
        if (!data || !data.success) {
          setError((data && data.message) ? data.message : (t('insights.error.labs_data') || 'Unable to load lab insights.'));
          return;
        }
        setError('');
        render(data);
      })
      .catch(function (error) {
        setLoading(false);
        setError(t('insights.error.labs_data') || 'Unable to load lab insights. Please try again.');
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
})();
