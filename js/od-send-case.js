/**
 * "Send to Open Dental" dialog (Phase 3 outbound create).
 *
 * Flow: case actions menu -> open(caseId) -> POST check -> render the
 * case's sync state plus pickers for any entity the server could not
 * resolve (patient/provider/laboratory) -> POST send -> the worker
 * performs the actual LabCase POST; this UI only queues it.
 *
 * The dialog is rendered hidden unless window.dtOdSendEnabled was set by
 * main.php (both feature flags + a create-enabled connection), and the
 * endpoint re-enforces every gate server-side.
 */
(function () {
  'use strict';

  var t = window.t || function (k) { return k; };
  var csrfToken = document.querySelector('meta[name="csrf-token"]')
    ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';

  var modal, confirmBtn, statusEl, errorEl, summaryEl;
  var currentCaseId = null;
  var selectedPatNum = null;
  var needs = [];

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
  }

  function post(payload) {
    return fetch('api/od-send-case.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function show(el, visible) { if (el) el.style.display = visible ? '' : 'none'; }
  function setError(msg) {
    if (!errorEl) return;
    if (msg) { errorEl.textContent = msg; show(errorEl, true); }
    else { errorEl.textContent = ''; show(errorEl, false); }
  }
  function setStatus(msg, cls) {
    if (!statusEl) return;
    if (msg) {
      statusEl.textContent = msg;
      statusEl.className = 'od-send-status ' + (cls || '');
      show(statusEl, true);
    } else {
      statusEl.textContent = ''; show(statusEl, false);
    }
  }

  function renderCandidates(list) {
    var box = document.getElementById('odSendPatCandidates');
    if (!box) return;
    if (!list || !list.length) {
      box.innerHTML = '<div class="od-send-empty">' + esc(t('od_send.no_candidates')) + '</div>';
      return;
    }
    box.innerHTML = list.map(function (p) {
      var label = (p.LName || '') + ', ' + (p.FName || '') +
        (p.Birthdate && p.Birthdate !== '0001-01-01' ? ' · ' + esc(p.Birthdate) : '') +
        (p.PatStatus ? ' · ' + esc(p.PatStatus) : '');
      return '<button type="button" class="od-send-candidate" role="option" data-patnum="' +
        esc(p.PatNum) + '" aria-selected="false">' + esc(label) + '</button>';
    }).join('');
  }

  function renderSelect(id, rows, labelFn, placeholder) {
    var sel = document.getElementById(id);
    if (!sel) return;
    var opts = '<option value="">' + esc(placeholder) + '</option>';
    (rows || []).forEach(function (r) { opts += '<option value="' + esc(r.value) + '">' + esc(r.label) + '</option>'; });
    sel.innerHTML = opts;
  }

  function refreshConfirmState() {
    if (!confirmBtn) return;
    var ready = needs.indexOf('patient') < 0 || !!selectedPatNum;
    if (needs.indexOf('provider') >= 0) {
      var pv = document.getElementById('odSendProviderSelect');
      ready = ready && pv && pv.value !== '';
    }
    if (needs.indexOf('laboratory') >= 0) {
      var lv = document.getElementById('odSendLabSelect');
      ready = ready && lv && lv.value !== '';
    }
    confirmBtn.disabled = !ready;
  }

  function applyCheck(res) {
    needs = res.needs || [];
    selectedPatNum = null;
    var pk = res.pickers || {};

    show(document.getElementById('odSendPickers'), needs.length > 0);
    show(document.getElementById('odSendPatientPicker'), needs.indexOf('patient') >= 0);
    show(document.getElementById('odSendProviderPicker'), needs.indexOf('provider') >= 0);
    show(document.getElementById('odSendLabPicker'), needs.indexOf('laboratory') >= 0);

    if (needs.indexOf('patient') >= 0) {
      var hint = res.patient_name || {};
      var ln = document.getElementById('odSendPatLName');
      var fn = document.getElementById('odSendPatFName');
      var db = document.getElementById('odSendPatDob');
      if (ln) ln.value = hint.lname || '';
      if (fn) fn.value = hint.fname || '';
      if (db) {
        var ts = hint.birthdate ? Date.parse(hint.birthdate) : NaN;
        db.value = isNaN(ts) ? '' : new Date(ts).toISOString().slice(0, 10);
      }
      renderCandidates(pk.patient_candidates || []);
    }
    if (needs.indexOf('provider') >= 0) {
      var hintEl = document.getElementById('odSendProviderHint');
      if (hintEl) {
        hintEl.textContent = res.provider_name
          ? t('od_send.provider_hint_named', { name: res.provider_name })
          : t('od_send.provider_hint');
      }
      renderSelect('odSendProviderSelect', (pk.providers || []).map(function (p) {
        var nm = ((p.FName || '') + ' ' + (p.LName || '')).trim();
        return { value: p.ProvNum, label: (p.Abbr ? p.Abbr + ' - ' : '') + (nm || ('#' + p.ProvNum)) };
      }), t('od_send.select_provider'));
    }
    if (needs.indexOf('laboratory') >= 0) {
      renderSelect('odSendLabSelect', (pk.laboratories || []).map(function (l) {
        return { value: l.LaboratoryNum, label: l.Description || ('#' + l.LaboratoryNum) };
      }), t('od_send.select_laboratory'));
      // Suggestion order: the admin-configured practice default first, then
      // this browser's last-used lab. A suggestion only PRE-SELECTS the
      // dropdown - the user still sees and confirms the choice (the lab is
      // always in `needs`, so Confirm stays gated on a non-empty value).
      var lv = document.getElementById('odSendLabSelect');
      if (lv) {
        var suggested = (res.resolved && res.resolved.laboratory_num) || null;
        if (!suggested) {
          try { suggested = localStorage.getItem('dtOdLastLab'); } catch (e) { suggested = null; }
        }
        if (suggested) lv.value = String(suggested);
      }
    }

    if (pk.remote_error) {
      setError(t('od_send.remote_unreachable'));
    }
    refreshConfirmState();
  }

  function open(caseId) {
    if (!modal) initDom();
    if (!modal) return;
    currentCaseId = caseId;
    selectedPatNum = null;
    needs = [];
    setError(null);
    setStatus(null);
    summaryEl.textContent = t('od_send.checking');
    confirmBtn.disabled = true;
    modal.style.display = 'block';
    modal.classList.add('show');

    post({ action: 'check', case_id: caseId }).then(function (res) {
      if (!res || res.success !== true) {
        summaryEl.textContent = '';
        setError((res && res.message) || t('od_send.server_error'));
        return;
      }
      switch (res.state) {
        case 'linked':
          summaryEl.textContent = t('od_send.already_linked');
          setStatus(t('od_send.state_linked'), 'ok');
          break;
        case 'pending':
          summaryEl.textContent = t('od_send.pending_hint');
          setStatus(t('od_send.state_pending'), 'pending');
          break;
        case 'unavailable':
          summaryEl.textContent = t('od_send.unavailable');
          break;
        default:
          summaryEl.textContent = t('od_send.summary');
          if (res.prior_state === 'conflict' || res.prior_state === 'failed') {
            setStatus(t('od_send.state_attention'), 'attention');
            setError(res.prior_error || null);
          } else {
            setStatus(t('od_send.state_unlinked'), '');
          }
          applyCheck(res);
      }
      // unlinked: confirm unlocks once every missing entity is picked;
      // other states are informational and keep it disabled.
      if (res.state !== 'unlinked') {
        confirmBtn.disabled = true;
      } else {
        refreshConfirmState();
      }
    }).catch(function () {
      summaryEl.textContent = '';
      setError(t('od_send.server_error'));
    });
  }

  function send() {
    if (!currentCaseId || confirmBtn.disabled) return;
    confirmBtn.disabled = true;
    setError(null);
    var payload = { action: 'send', case_id: currentCaseId };
    if (selectedPatNum) payload.pat_num = selectedPatNum;
    var pv = document.getElementById('odSendProviderSelect');
    var lv = document.getElementById('odSendLabSelect');
    if (pv && pv.value) payload.prov_num = parseInt(pv.value, 10);
    if (lv && lv.value) payload.laboratory_num = parseInt(lv.value, 10);

    post(payload).then(function (res) {
      if (!res || res.success !== true) {
        setError((res && res.message) || t('od_send.server_error'));
        confirmBtn.disabled = false;
        return;
      }
      if (res.queued) {
        // Convenience memory only: the pick is never persisted server-side
        // and never overwrites the practice default laboratory.
        if (payload.laboratory_num) {
          try { localStorage.setItem('dtOdLastLab', String(payload.laboratory_num)); } catch (e) {}
        }
        summaryEl.textContent = t('od_send.queued');
        setStatus(t('od_send.state_pending'), 'pending');
        show(document.getElementById('odSendPickers'), false);
      } else {
        var msg;
        switch (res.reason) {
          case 'already_linked':  msg = t('od_send.already_linked'); break;
          case 'invalid_selection': msg = t('od_send.invalid_selection'); break;
          case 'timezone_unconfigured': msg = t('od_send.tz_missing'); break;
          case 'needs_mapping':   msg = t('od_send.still_missing'); break;
          default:                msg = t('od_send.not_queued'); break;
        }
        setError(msg);
        confirmBtn.disabled = false;
      }
    }).catch(function () {
      setError(t('od_send.server_error'));
      confirmBtn.disabled = false;
    });
  }

  function searchPatients() {
    var ln = document.getElementById('odSendPatLName');
    var fn = document.getElementById('odSendPatFName');
    var db = document.getElementById('odSendPatDob');
    post({
      action: 'search_patients', case_id: currentCaseId,
      lname: ln ? ln.value : '', fname: fn ? fn.value : '',
      birthdate: db ? db.value : ''
    }).then(function (res) {
      renderCandidates((res && res.candidates) || []);
    }).catch(function () { renderCandidates([]); });
  }

  function close() {
    if (!modal) return;
    modal.classList.remove('show');
    modal.style.display = 'none';
    currentCaseId = null;
  }

  function initDom() {
    modal = document.getElementById('odSendCaseModal');
    if (!modal) return;
    // The markup lives inside #settingsBillingModal for template grouping;
    // the dialog must open from the case board while Settings is closed,
    // so reparent it to <body> on first use (fixed-position modal anyway).
    if (modal.parentElement && modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
    confirmBtn = document.getElementById('odSendCaseConfirm');
    statusEl = document.getElementById('odSendCaseStatus');
    errorEl = document.getElementById('odSendCaseError');
    summaryEl = document.getElementById('odSendCaseSummary');

    document.getElementById('odSendCaseClose').addEventListener('click', close);
    document.getElementById('odSendCaseCancel').addEventListener('click', close);
    confirmBtn.addEventListener('click', send);
    document.getElementById('odSendPatSearch').addEventListener('click', searchPatients);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) close();
      var cand = e.target.closest ? e.target.closest('.od-send-candidate') : null;
      if (cand) {
        selectedPatNum = parseInt(cand.getAttribute('data-patnum'), 10) || null;
        modal.querySelectorAll('.od-send-candidate').forEach(function (c) {
          c.classList.toggle('selected', c === cand);
          c.setAttribute('aria-selected', c === cand ? 'true' : 'false');
        });
        refreshConfirmState();
      }
    });
    ['odSendProviderSelect', 'odSendLabSelect'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('change', refreshConfirmState);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && modal.classList.contains('show')) close();
    });
  }

  window.odSendCase = { open: open, close: close };
})();
