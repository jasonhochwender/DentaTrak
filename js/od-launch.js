/**
 * Open Dental patient launch - client side.
 *
 * The OD toolbar bridge (od-launch.php?pat=<PatNum>) lands the user on
 * main.php or login.php with an `od_pat` hint. Both pages stash it as
 * `pendingOdPatient` in sessionStorage (same pattern as pendingNotification
 * for notification deep links), so the hint survives sign-in, 2FA, and the
 * practice chooser untouched.
 *
 * On main.php, processPendingOdPatient() resolves the hint through
 * api/od-patient-cases.php - which does all authorization and practice
 * scoping server-side - then constrains the kanban/list fetches to the
 * resolved case ids via window.odPatientScope and shows a banner with an
 * obvious way back to the full list.
 */
(function () {
  'use strict';

  var scope = {
    caseIds: null,    // string[] while a patient view is active, else null
    patientName: '',
    status: ''
  };

  function activeCaseIds() {
    return Array.isArray(scope.caseIds) && scope.caseIds.length ? scope.caseIds.slice() : null;
  }

  function banner() { return document.getElementById('odPatientBanner'); }
  function bannerText() { return document.getElementById('odPatientBannerText'); }
  function clearButton() { return document.getElementById('odPatientClear'); }

  function tr(key, params) {
    return (typeof t === 'function') ? t(key, params) : key;
  }

  function stripUrlParam() {
    try {
      var url = new URL(window.location.href);
      if (url.searchParams.has('od_pat')) {
        url.searchParams.delete('od_pat');
        history.replaceState(null, '', url.pathname + (url.search || '') + url.hash);
      }
    } catch (e) {}
  }

  function showBanner(text, clearLabel) {
    var el = banner(), txt = bannerText(), btn = clearButton();
    if (!el || !txt) return;
    txt.textContent = text;
    if (btn) btn.textContent = clearLabel || tr('od_launch.show_all_cases');
    el.hidden = false;
  }

  function hideBanner() {
    var el = banner();
    if (el) el.hidden = true;
  }

  function reloadBoard() {
    if (typeof window.applyFilters === 'function') {
      window.applyFilters();
    }
  }

  function applyScope(caseIds, patientName) {
    scope.caseIds = caseIds;
    scope.patientName = patientName || '';
    scope.status = 'ok';
    var text = scope.patientName
      ? tr('od_launch.viewing_patient', { name: scope.patientName })
      : tr('od_launch.viewing_patient_generic');
    showBanner(text, tr('od_launch.show_all_cases'));
    reloadBoard();
  }

  function showNotice(status) {
    var key = 'od_launch.not_found';
    if (status === 'no_integration') key = 'od_launch.not_connected';
    else if (status === 'no_cases') key = 'od_launch.no_cases';
    else if (status === 'invalid' || status === 'resolve_failed') key = 'od_launch.unavailable';
    showBanner(tr(key), tr('od_launch.dismiss'));
  }

  function clearScope() {
    scope.caseIds = null;
    scope.patientName = '';
    scope.status = '';
    hideBanner();
    stripUrlParam();
    reloadBoard();
  }

  function processPendingOdPatient() {
    var raw = null;
    try { raw = sessionStorage.getItem('pendingOdPatient'); } catch (e) {}
    if (!raw) return;
    try { sessionStorage.removeItem('pendingOdPatient'); } catch (e) {}

    var pat = '';
    try { pat = String((JSON.parse(raw) || {}).pat || ''); } catch (e) {}
    if (!/^\d{1,20}$/.test(pat)) return;

    fetch('api/od-patient-cases.php?pat=' + encodeURIComponent(pat), {
      method: 'GET',
      credentials: 'same-origin'
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.success) {
          showNotice('resolve_failed');
          return;
        }
        if (data.status === 'ok' && Array.isArray(data.case_ids) && data.case_ids.length) {
          applyScope(data.case_ids, data.patient_name || '');
        } else {
          showNotice(data.status || 'no_patient');
        }
      })
      .catch(function () {
        showNotice('resolve_failed');
      });
  }

  // Global scope handle read by the case-fetch code paths (app.js
  // applyFilters and case-filter-sort.js query()).
  window.odPatientScope = {
    caseIds: activeCaseIds,
    clear: clearScope,
    isActive: function () { return activeCaseIds() !== null; }
  };
  window.processPendingOdPatient = processPendingOdPatient;

  document.addEventListener('DOMContentLoaded', function () {
    var btn = clearButton();
    if (btn) btn.addEventListener('click', clearScope);
  });
})();
