/**
 * Phase 3 mobile case modal enhancements.
 * - Compact, scrollable case summary.
 * - Mobile section headings inside the existing Details form.
 * - Summary sync on patient/case/status/assignment/due-date changes.
 */
(function() {
  'use strict';

  var SUMMARY_ID = 'mobileCaseSummary';
  var ASSIGNED_OBSERVER = null;
  var CURRENT_SUMMARY_DATA = null;

  function getElement(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getSelectedText(select) {
    if (!select || select.selectedIndex < 0) return '';
    var text = (select.options[select.selectedIndex].textContent || '').trim();
    return text;
  }

  function formatShortDate(dateString) {
    if (!dateString) return '';
    try {
      var m = String(dateString).match(/^(\d{4}-\d{2}-\d{2})/);
      var d = m ? new Date(m[1] + 'T00:00:00') : new Date(dateString);
      if (isNaN(d.getTime())) return dateString;
      if (typeof I18n !== 'undefined' && I18n.formatDate) {
        return I18n.formatDate(d, { style: 'short' });
      }
      return d.toLocaleDateString();
    } catch (e) {
      return dateString;
    }
  }

  function tOr(key, fallback) {
    if (typeof t !== 'function') return fallback;
    var v = t(key);
    return (v && v !== key) ? v : fallback;
  }

  function isPhoneViewport() {
    return window.matchMedia('(max-width: 480px)').matches;
  }

  // ===========================================
  // Risk / due-date calculations (reuse board logic)
  // ===========================================
  function computeRiskBadges(caseData) {
    var badges = [];
    if (!caseData) return badges;

    var status = caseData.status || '';
    var highlightPastDue = localStorage.getItem('highlight_past_due') === 'true';
    var highlightComingDue = localStorage.getItem('highlight_coming_due') === 'true';
    var highlightAppointmentRisk = localStorage.getItem('highlight_appointment_risk') === 'true';
    var isPastDue = false;
    var isAppointmentRisk = false;
    var dueDayDiff = null;

    if (status !== 'Delivered' && caseData.dueDate) {
      var pastDueDays = parseInt(localStorage.getItem('past_due_days') || '1', 10);
      if (typeof window.getCalendarDayDiff === 'function') {
        dueDayDiff = window.getCalendarDayDiff(caseData.dueDate);
        if (dueDayDiff !== null && highlightPastDue && dueDayDiff <= -pastDueDays) {
          isPastDue = true;
          badges.push({
            className: 'mobile-case-badge mobile-case-badge-late',
            text: tOr('cases.due.late', 'LATE'),
            label: tOr('filter.late_label', 'Past due')
          });
        }
      }
    }

    if (!isPastDue && status !== 'Delivered' && caseData.patientAppointmentDate && highlightAppointmentRisk) {
      var appointmentRiskDays = parseInt(localStorage.getItem('appointment_risk_days') || '3', 10);
      if (typeof window.getCalendarDayDiff === 'function') {
        var apptDayDiff = window.getCalendarDayDiff(caseData.patientAppointmentDate);
        if (apptDayDiff !== null && apptDayDiff <= appointmentRiskDays) {
          isAppointmentRisk = true;
          badges.push({
            className: 'mobile-case-badge mobile-case-badge-appointment-risk',
            text: tOr('cases.risk.appointment_abbreviation', 'APPT RISK'),
            label: tOr('filter.appointment_risk_label', 'Appointment approaching')
          });
        }
      }
    }

    if (!isPastDue && !isAppointmentRisk && status !== 'Delivered' && caseData.dueDate) {
      var comingDueDays = parseInt(localStorage.getItem('coming_due_days') || '5', 10);
      if (typeof window.getCalendarDayDiff === 'function') {
        dueDayDiff = dueDayDiff !== null ? dueDayDiff : window.getCalendarDayDiff(caseData.dueDate);
        if (dueDayDiff !== null && dueDayDiff >= 0 && dueDayDiff <= comingDueDays) {
          if (typeof window.getDueWarningText === 'function') {
            var dueText = window.getDueWarningText(dueDayDiff);
            if (dueText && dueText.trim()) {
              badges.push({
                className: 'mobile-case-badge mobile-case-badge-due-soon',
                text: dueText.trim(),
                label: tOr('filter.due_soon', 'Due soon')
              });
            }
          }
        }
      }
    }

    var revisionCount = parseInt(caseData.revisionCount, 10) || 0;
    if (revisionCount > 0) {
      badges.push({
        className: 'mobile-case-badge mobile-case-badge-revisions',
        text: tOr('cases.revisions_count', 'Revisions: {count}').replace('{count}', revisionCount),
        label: tOr('cases.history.title', 'Revisions')
      });
    }

    return badges;
  }

  // ===========================================
  // Section headings (no reparenting of controls)
  // ===========================================
  function removeSectionHeadings() {
    var form = getElement('createCaseForm');
    if (!form) return;
    // Remove headings that were dynamically inserted.
    var inserted = form.querySelectorAll('.mobile-section-heading[data-inserted="1"]');
    inserted.forEach(function(h) { h.remove(); });
    // Remove the mobile class from headings that already existed in the markup.
    var styled = form.querySelectorAll('.mobile-section-heading:not([data-inserted="1"])');
    styled.forEach(function(h) {
      h.classList.remove('mobile-section-heading');
      h.removeAttribute('data-section');
    });
    delete form.dataset.mobileSectionsBound;
  }

  function insertSectionHeading(refEl, key, label, mode) {
    if (!refEl) return;
    var form = getElement('createCaseForm');
    if (form && form.querySelector('.mobile-section-heading[data-section="' + key + '"]')) return;

    var heading = document.createElement('h3');
    heading.className = 'mobile-section-heading';
    heading.setAttribute('data-section', key);
    heading.setAttribute('data-inserted', '1');
    heading.textContent = label;

    var target = refEl;
    if (mode === 'beforeparent') {
      target = refEl.closest('.form-field') || refEl;
    }

    if (target && target.parentNode) {
      target.parentNode.insertBefore(heading, target);
    }
  }

  function styleExistingHeading(refEl, key) {
    if (!refEl) return;
    refEl.classList.add('mobile-section-heading');
    refEl.setAttribute('data-section', key);
  }

  function organizeSections() {
    var form = getElement('createCaseForm');
    if (!form || !isPhoneViewport()) {
      removeSectionHeadings();
      return;
    }
    if (form.dataset.mobileSectionsBound === '1') return;

    // Patient / Dentist — before the first form grid
    var firstGrid = form.querySelector('.modal-form-grid');
    insertSectionHeading(firstGrid, 'patient', tOr('cases.patient_information', 'Patient and Dentist'), 'before');

    // Case Details — before the case type field (inside the first grid)
    var caseType = getElement('caseType');
    insertSectionHeading(caseType, 'case', tOr('cases.case_details', 'Case Details'), 'beforeparent');

    // Clinical Details — style the existing conditional section title
    var clinicalTitle = form.querySelector('.clinical-details-title');
    styleExistingHeading(clinicalTitle, 'clinical');

    // Scheduling and Assignment — before the date/status grid
    var dateGrid = form.querySelector('.modal-form-grid.date-status-row');
    insertSectionHeading(dateGrid, 'scheduling', tOr('cases.scheduling', 'Scheduling and Assignment'), 'before');

    // Notes — before the notes field (inside the scheduling grid)
    var notes = getElement('notes');
    insertSectionHeading(notes, 'notes', tOr('cases.notes', 'Notes'), 'beforeparent');

    // Shipping — style the existing shipping title
    var shippingTitle = form.querySelector('.shipping-title');
    styleExistingHeading(shippingTitle, 'shipping');

    // Attachments — style the existing attachments title
    var attachmentsTitle = form.querySelector('.attachments-title');
    styleExistingHeading(attachmentsTitle, 'attachments');

    // Case Metadata — before the creator meta block
    var meta = form.querySelector('.case-creator-meta');
    insertSectionHeading(meta, 'metadata', tOr('cases.created_by', 'Case Metadata'), 'before');

    addSectionNavigator();

    form.dataset.mobileSectionsBound = '1';
  }

  // ===========================================
  // Summary
  // ===========================================
  function buildSummary(caseData) {
    var patientFirst = caseData.patientFirstName || caseData.patient_first_name || '';
    var patientLast = caseData.patientLastName || caseData.patient_last_name || '';
    var patientName = escapeHtml((patientFirst + ' ' + patientLast).trim());
    var caseType = escapeHtml(caseData.caseType || caseData.case_type || '');
    var status = caseData.status || '';
    var statusLabel = (typeof window.getStageLabel === 'function') ? window.getStageLabel(status) : status;
    var dueDate = formatShortDate(caseData.dueDate || caseData.due_date);

    var assignedTo = getElement('assignedTo');
    var assignedName = getSelectedText(assignedTo);
    if (assignedName === t('assignments.none')) assignedName = '';

    var badges = computeRiskBadges(caseData);
    var badgeHtml = badges.map(function(b) {
      return '<span class="' + b.className + '" role="status" aria-label="' + escapeHtml(b.label) + '">' + escapeHtml(b.text) + '</span>';
    }).join(' ');

    return '' +
      '<div class="mobile-case-summary-card">' +
        '<div class="mobile-case-summary-row">' +
          '<span class="mobile-case-summary-label">' + escapeHtml(tOr('archive.fields.patient_name', 'Patient Name')) + '</span>' +
          '<span class="mobile-case-summary-value mobile-case-summary-patient" data-summary-field="patient">' + (patientName || '—') + '</span>' +
        '</div>' +
        '<div class="mobile-case-summary-row">' +
          '<span class="mobile-case-summary-label">' + escapeHtml(tOr('cases.case_type', 'Case Type')) + '</span>' +
          '<span class="mobile-case-summary-value" data-summary-field="caseType">' + (caseType || '—') + '</span>' +
        '</div>' +
        '<div class="mobile-case-summary-row">' +
          '<span class="mobile-case-summary-label">' + escapeHtml(tOr('cases.status_label', 'Status')) + '</span>' +
          '<span class="mobile-case-summary-value" data-summary-field="status">' + (statusLabel || '—') + '</span>' +
        '</div>' +
        '<div class="mobile-case-summary-row">' +
          '<span class="mobile-case-summary-label">' + escapeHtml(tOr('cases.assigned_to', 'Assigned To')) + '</span>' +
          '<span class="mobile-case-summary-value mobile-case-summary-assigned" data-summary-field="assigned">' + (assignedName || '—') + '</span>' +
        '</div>' +
        '<div class="mobile-case-summary-row">' +
          '<span class="mobile-case-summary-label">' + escapeHtml(tOr('cases.due_date', 'Due Date')) + '</span>' +
          '<span class="mobile-case-summary-value" data-summary-field="dueDate">' + (dueDate || '—') + '</span>' +
        '</div>' +
        (badgeHtml ? '<div class="mobile-case-summary-badges" data-summary-field="badges" aria-live="polite">' + badgeHtml + '</div>' : '') +
      '</div>';
  }

  function removeSummary() {
    var existing = getElement(SUMMARY_ID);
    if (existing) existing.remove();
    if (ASSIGNED_OBSERVER) {
      ASSIGNED_OBSERVER.disconnect();
      ASSIGNED_OBSERVER = null;
    }
    CURRENT_SUMMARY_DATA = null;
    var form = getElement('createCaseForm');
    if (form) {
      delete form.dataset.mobileSummarySync;
    }
  }

  function setSummaryField(field, value) {
    var summary = getElement(SUMMARY_ID);
    if (!summary) return;
    var el = summary.querySelector('[data-summary-field="' + field + '"]');
    if (el) el.textContent = value;
  }

  function updateSummaryBadges() {
    var summary = getElement(SUMMARY_ID);
    if (!summary || !CURRENT_SUMMARY_DATA) return;

    var patientFirstEl = getElement('patientFirstName');
    var patientLastEl = getElement('patientLastName');
    var caseTypeEl = getElement('caseType');
    var statusEl = getElement('status');
    var dueDateEl = getElement('dueDate');
    var apptDateEl = getElement('patientAppointmentDate');

    var updated = {
      status: statusEl ? statusEl.value : CURRENT_SUMMARY_DATA.status,
      dueDate: dueDateEl ? dueDateEl.value : CURRENT_SUMMARY_DATA.dueDate,
      patientAppointmentDate: apptDateEl ? apptDateEl.value : CURRENT_SUMMARY_DATA.patientAppointmentDate,
      revisionCount: CURRENT_SUMMARY_DATA.revisionCount
    };

    var badges = computeRiskBadges(updated);
    var badgesEl = summary.querySelector('[data-summary-field="badges"]');
    if (badges.length === 0) {
      if (badgesEl) badgesEl.remove();
      return;
    }

    var badgeHtml = badges.map(function(b) {
      return '<span class="' + b.className + '" role="status" aria-label="' + escapeHtml(b.label) + '">' + escapeHtml(b.text) + '</span>';
    }).join(' ');

    if (badgesEl) {
      badgesEl.innerHTML = badgeHtml;
    } else {
      var newBadges = document.createElement('div');
      newBadges.className = 'mobile-case-summary-badges';
      newBadges.setAttribute('data-summary-field', 'badges');
      newBadges.setAttribute('aria-live', 'polite');
      newBadges.innerHTML = badgeHtml;
      summary.querySelector('.mobile-case-summary-card').appendChild(newBadges);
    }
  }

  function updateSummaryFromForm() {
    if (!isPhoneViewport()) return;

    var patientFirstEl = getElement('patientFirstName');
    var patientLastEl = getElement('patientLastName');
    var caseTypeEl = getElement('caseType');
    var statusEl = getElement('status');
    var dueDateEl = getElement('dueDate');
    var assignedToEl = getElement('assignedTo');

    var patientName = ((patientFirstEl ? patientFirstEl.value : '') + ' ' + (patientLastEl ? patientLastEl.value : '')).trim();
    setSummaryField('patient', patientName || '—');

    var caseType = caseTypeEl ? (caseTypeEl.options[caseTypeEl.selectedIndex] ? caseTypeEl.options[caseTypeEl.selectedIndex].textContent : caseTypeEl.value) : '';
    setSummaryField('caseType', caseType || '—');

    var statusValue = statusEl ? statusEl.value : '';
    var statusLabel = (typeof window.getStageLabel === 'function') ? window.getStageLabel(statusValue) : statusValue;
    setSummaryField('status', statusLabel || '—');

    var assignedName = getSelectedText(assignedToEl);
    if (assignedName === t('assignments.none')) assignedName = '';
    setSummaryField('assigned', assignedName || '—');

    var due = dueDateEl ? formatShortDate(dueDateEl.value) : '';
    setSummaryField('dueDate', due || '—');

    updateSummaryBadges();
  }

  function onAssignedOptionsChanged() {
    // The dropdown may be rebuilt asynchronously; update summary once options settle.
    setTimeout(updateSummaryFromForm, 0);
  }

  function bindSummarySync() {
    var form = getElement('createCaseForm');
    if (!form || form.dataset.mobileSummarySync === '1') return;
    form.addEventListener('input', updateSummaryFromForm);
    form.addEventListener('change', updateSummaryFromForm);
    form.dataset.mobileSummarySync = '1';

    var assignedTo = getElement('assignedTo');
    if (assignedTo) {
      if (typeof MutationObserver !== 'undefined') {
        ASSIGNED_OBSERVER = new MutationObserver(onAssignedOptionsChanged);
        ASSIGNED_OBSERVER.observe(assignedTo, { childList: true, subtree: true });
      }
      assignedTo.addEventListener('change', updateSummaryFromForm);
    }
  }

  function addSectionNavigator() {
    var form = getElement('createCaseForm');
    if (!form || form.querySelector('.mobile-section-navigator')) return;

    var headings = Array.from(form.querySelectorAll('.mobile-section-heading'));
    if (!headings.length) return;

    var label = tOr('cases.jump_to_section', 'Jump to section');
    var nav = document.createElement('div');
    nav.className = 'mobile-section-navigator';
    nav.setAttribute('data-inserted', '1');

    var select = document.createElement('select');
    select.className = 'mobile-section-navigator-select';
    select.setAttribute('aria-label', label);
    select.style.width = '100%';

    var defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = label;
    select.appendChild(defaultOpt);

    headings.forEach(function(h) {
      var opt = document.createElement('option');
      opt.value = h.getAttribute('data-section') || '';
      opt.textContent = h.textContent;
      select.appendChild(opt);
    });

    select.addEventListener('change', function() {
      if (!select.value) return;
      var target = form.querySelector('.mobile-section-heading[data-section="' + select.value + '"]');
      if (target) {
        // Scroll the heading to just beneath the sticky tab bar.
        var modalBody = form.closest('.modal-body');
        var tabs = document.getElementById('caseViewTabs');
        var tabHeight = tabs ? tabs.getBoundingClientRect().height + 12 : 72;
        if (modalBody) {
          var targetTop = target.getBoundingClientRect().top - modalBody.getBoundingClientRect().top;
          modalBody.scrollTop += targetTop - tabHeight;
        } else {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
      setTimeout(function() {
        select.value = '';
      }, 0);
    });

    nav.appendChild(select);

    var summary = getElement(SUMMARY_ID);
    if (summary && summary.nextSibling) {
      form.insertBefore(nav, summary.nextSibling);
    } else if (summary) {
      summary.parentNode.appendChild(nav);
    } else {
      var firstChild = form.firstChild;
      while (firstChild && firstChild.nodeType !== 1) {
        firstChild = firstChild.nextSibling;
      }
      form.insertBefore(nav, firstChild || form.firstChild);
    }
  }

  function removeSectionNavigator() {
    var form = getElement('createCaseForm');
    if (!form) return;
    var nav = form.querySelector('.mobile-section-navigator');
    if (nav) nav.remove();
  }

  function clearMobileState() {
    removeSummary();
    removeSectionHeadings();
    removeSectionNavigator();
    var form = getElement('createCaseForm');
    if (form) {
      delete form.dataset.mobileSummarySync;
    }
  }

  // ===========================================
  // Public API
  // ===========================================
  function renderSummary(caseData) {
    var form = getElement('createCaseForm');
    if (!form) return;

    if (!isPhoneViewport()) {
      clearMobileState();
      return;
    }

    removeSummary();
    removeSectionHeadings();

    organizeSections();

    if (caseData && (caseData.id || caseData.case_id)) {
      CURRENT_SUMMARY_DATA = {
        patientFirstName: caseData.patientFirstName || caseData.patient_first_name || '',
        patientLastName: caseData.patientLastName || caseData.patient_last_name || '',
        caseType: caseData.caseType || caseData.case_type || '',
        status: caseData.status || '',
        dueDate: caseData.dueDate || caseData.due_date || '',
        patientAppointmentDate: caseData.patientAppointmentDate || caseData.patient_appointment_date || '',
        assignedTo: caseData.assignedTo || '',
        revisionCount: parseInt(caseData.revisionCount, 10) || 0
      };

      var summary = document.createElement('div');
      summary.id = SUMMARY_ID;
      summary.className = 'mobile-case-summary';
      summary.setAttribute('aria-label', tOr('cases.details', 'Case summary'));
      summary.innerHTML = buildSummary(CURRENT_SUMMARY_DATA);

      var firstChild = form.firstChild;
      while (firstChild && firstChild.nodeType !== 1) {
        firstChild = firstChild.nextSibling;
      }
      form.insertBefore(summary, firstChild || form.firstChild);

      bindSummarySync();

      // Initial sync after dropdown has a chance to populate.
      setTimeout(updateSummaryFromForm, 100);
    }
  }

  window.MobileCaseModal = {
    renderSummary: renderSummary,
    organizeSections: organizeSections,
    updateSummaryFromForm: updateSummaryFromForm,
    clearMobileState: clearMobileState
  };
})();
