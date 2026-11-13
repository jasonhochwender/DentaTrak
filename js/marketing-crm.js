/**
 * Marketing Outreach - Phase 3 CRM prospect management.
 *
 * Loaded by marketing-outreach.php only when MARKETING_CRM_READS is on.
 * All data goes through api/marketing-admin.php (session + CSRF). The
 * Google Sheet is never touched by this module; campaign additions are
 * draft-only and never generate/approve/send.
 */
(function () {
    'use strict';

    var cfg = null;
    var state = {
        page: 1, per: 50, sort: 'sheet_row', dir: 'asc',
        q: '', status: '', priority: '', org_type: '', eligible: '', review: 0,
        flag: '', bucket: '', selected: {}, detail: null, orgs: []
    };

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function apiGet(action, params) {
        var qs = '?action=' + encodeURIComponent(action);
        if (params) Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) qs += '&' + k + '=' + encodeURIComponent(params[k]);
        });
        return fetch(cfg.api + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function apiPost(action, payload) {
        payload = payload || {}; payload.action = action; payload.csrf_token = cfg.csrf;
        return fetch(cfg.api, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json(); });
    }
    function showErr(m) { cfg.showErr(m); }
    function showOk(m) { cfg.showOk(m); }
    function fmtDate(ts) { return ts ? new Date(ts * 1000).toISOString().slice(0, 10) : ''; }
    function fmtDT(s) { if (!s) return ''; var d = new Date(s.replace(' ', 'T') + 'Z'); return isNaN(d) ? s : d.toISOString().slice(0, 16).replace('T', ' '); }
    function debounce(fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; }

    /* ---------------- modal helper ---------------- */
    function openModal(html) {
        var m = $('moModal');
        m.innerHTML = '<div class="mo-modal-back" data-close="1"><div class="mo-modal" role="dialog" aria-modal="true">' + html + '</div></div>';
        m.classList.remove('mo-hidden');
        m.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function (e) { if (e.target === el || el.dataset.close === '1' && e.target.closest('[data-close-btn]')) closeModal(); });
        });
        document.addEventListener('keydown', escClose);
    }
    function escClose(e) { if (e.key === 'Escape') closeModal(); }
    function closeModal() {
        $('moModal').classList.add('mo-hidden');
        $('moModal').innerHTML = '';
        document.removeEventListener('keydown', escClose);
    }
    function modalShell(title, bodyHtml, actionsHtml) {
        return '<div class="mo-modal-head"><h3>' + esc(title) + '</h3>' +
            '<button type="button" class="mo-modal-x" data-close-btn aria-label="Close">&times;</button></div>' +
            '<div class="mo-modal-body">' + bodyHtml + '</div>' +
            (actionsHtml ? '<div class="mo-modal-foot">' + actionsHtml + '</div>' : '');
    }

    /* ---------------- list ---------------- */
    function listParams() {
        return {
            q: state.q, flag: state.flag, bucket: state.bucket,
            status: state.status, priority: state.priority, org_type: state.org_type,
            eligible: state.eligible, review: state.review ? 1 : '',
            sort: state.sort, dir: state.dir, page: state.page, per: state.per
        };
    }
    function load() {
        var host = $('moCrmProspectList');
        host.innerHTML = '<div class="pa-card">' + esc(cfg.str.loading) + '</div>';
        apiGet('prospects', listParams()).then(function (d) {
            if (!d.success) { host.innerHTML = ''; showErr(d.error || cfg.str.loadFailed); return; }
            renderList(d);
        }).catch(function () { host.innerHTML = ''; showErr(cfg.str.loadFailed); });
    }

    // Labels resolve lazily: cfg is null until init() runs.
    var COLS = [
        { key: 'select', lk: '' },
        { key: 'org', lk: 'colOrg', sort: 'org' },
        { key: 'contact', lk: 'colContact', sort: 'contact' },
        { key: 'email', lk: 'colEmail', sort: 'email' },
        { key: 'status', lk: 'colStatus', sort: 'status' },
        { key: 'priority', lk: 'colPriority', sort: 'priority' },
        { key: 'followup', lk: 'colFollowup', sort: 'followup' },
        { key: 'elig', lk: 'colEligibility' },
        { key: 'flags', lk: 'colFlags' }
    ];
    function colLabel(c) { return c.lk ? cfg.str[c.lk] : ''; }

    function renderList(d) {
        var host = $('moCrmProspectList');
        if (!d.prospects.length) {
            host.innerHTML = '<div class="pa-card">' + esc(cfg.str.noProspects) + '</div>';
            $('moCrmPager').innerHTML = '';
            updateBulkBar();
            return;
        }
        var html = '<table class="mo-table mo-crm-table"><colgroup>' +
            '<col style="width:30px"><col style="width:17%"><col style="width:15%"><col style="width:19%">' +
            '<col style="width:9%"><col style="width:10%"><col style="width:11%"><col style="width:8%"><col>' +
            '</colgroup><thead><tr>';
        COLS.forEach(function (c) {
            if (c.key === 'select') {
                html += '<th class="mo-crm-check"><input type="checkbox" id="moCrmCheckAll" aria-label="Select all on page"></th>';
            } else if (c.sort) {
                var arrow = state.sort === c.sort ? (state.dir === 'asc' ? ' ▲' : ' ▼') : '';
                html += '<th data-sort="' + c.sort + '" class="mo-sortable" role="button" tabindex="0">' + esc(colLabel(c)) + arrow + '</th>';
            } else {
                html += '<th>' + esc(colLabel(c)) + '</th>';
            }
        });
        html += '</tr></thead><tbody>';
        d.prospects.forEach(function (p) {
            var f = p.fields || {};
            var cid = f.contact_id || p.row;
            var checked = state.selected[cid] ? ' checked' : '';
            var flags = (p.flags || []).map(function (fl) {
                return '<span class="mo-flag ' + fl + '">' + esc(fl.replace(/_/g, ' ')) + '</span>';
            }).join('');
            if (f.needs_review) flags += '<span class="mo-flag missing_contact">' + esc(cfg.str.needsReview) + '</span>';
            var elig = '';
            if (!empty(p.eligible)) elig = '<span class="mo-pill eligible">' + esc(cfg.str.eligible) + '</span>';
            else {
                var reasons = (p.initial_flags || []).concat(p.followup_flags || []);
                elig = '<span class="mo-pill suppressed" title="' + esc(reasons.join(', ')) + '">' + esc(cfg.str.excluded) + '</span>';
            }
            html += '<tr class="clickable" data-id="' + cid + '">' +
                '<td class="mo-crm-check" data-stop><input type="checkbox" class="moCrmSel" data-id="' + cid + '"' + checked + ' aria-label="Select prospect"></td>' +
                '<td><strong>' + esc(f.org || '(no org)') + '</strong>' +
                (f.parent_org ? '<br><span class="mo-sub">' + esc(f.parent_org) + '</span>' : '') + '</td>' +
                '<td>' + esc(p.contact_name || '—') +
                (f.title ? '<br><span class="mo-sub">' + esc(f.title) + '</span>' : '') + '</td>' +
                '<td><span class="mo-sub">' + esc(f.email || '(missing)') + '</span></td>' +
                '<td class="mo-nowrap"><span class="mo-pill">' + esc(f.status || '—') + '</span></td>' +
                '<td class="mo-nowrap">' + esc(f.priority || '—') + '</td>' +
                '<td class="mo-nowrap">' + esc(fmtDate(p.followup_ts)) + '<br><span class="mo-pill ' + p.bucket + '">' + esc(p.bucket.replace(/_/g, ' ')) + '</span></td>' +
                '<td class="mo-nowrap">' + elig + '</td>' +
                '<td>' + flags + '</td></tr>';
        });
        html += '</tbody></table>';
        host.innerHTML = html;

        host.querySelectorAll('th[data-sort]').forEach(function (th) {
            var go = function () {
                var s = th.dataset.sort;
                if (state.sort === s) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
                else { state.sort = s; state.dir = 'asc'; }
                load();
            };
            th.addEventListener('click', go);
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
        });
        host.querySelectorAll('tr.clickable').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('[data-stop]')) return;
                openDrawer(parseInt(tr.dataset.id, 10));
            });
        });
        host.querySelectorAll('.moCrmSel').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = parseInt(cb.dataset.id, 10);
                if (cb.checked) state.selected[id] = true; else delete state.selected[id];
                updateBulkBar();
            });
        });
        var all = $('moCrmCheckAll');
        if (all) {
            all.addEventListener('change', function () {
                d.prospects.forEach(function (p) {
                    var cid = (p.fields && p.fields.contact_id) || p.row;
                    if (all.checked) state.selected[cid] = true; else delete state.selected[cid];
                });
                load();
            });
        }
        var pages = Math.max(1, Math.ceil(d.total / d.per));
        $('moCrmPager').innerHTML =
            '<button class="mo-btn secondary small" id="moPgPrev" ' + (state.page <= 1 ? 'disabled' : '') + '>←</button>' +
            '<span>' + state.page + ' / ' + pages + ' (' + d.total + ')</span>' +
            '<button class="mo-btn secondary small" id="moPgNext" ' + (state.page >= pages ? 'disabled' : '') + '>→</button>';
        $('moPgPrev').onclick = function () { state.page--; load(); };
        $('moPgNext').onclick = function () { state.page++; load(); };
        updateBulkBar();
    }
    function empty(v) { return v === null || v === undefined || v === false || v === ''; }

    /* ---------------- bulk bar ---------------- */
    function selectedIds() { return Object.keys(state.selected).map(function (k) { return parseInt(k, 10); }); }
    function updateBulkBar() {
        var bar = $('moBulkBar');
        var n = selectedIds().length;
        bar.classList.toggle('mo-hidden', n === 0);
        $('moBulkCount').textContent = n + ' ' + cfg.str.selected;
        // Keep every bulk control inert while nothing is selected so the
        // bar can never act on an empty selection.
        ['moBulkPriority', 'moBulkOwner', 'moBulkTags', 'moBulkCampaign', 'moBulkExport', 'moBulkClear']
            .forEach(function (id) {
                var el = $(id);
                if (el) el.disabled = (n === 0);
            });
    }
    function bulkUpdate(fields) {
        var ids = selectedIds();
        if (!ids.length) return;
        apiPost('bulk_update', { contact_ids: ids, fields: fields }).then(function (d) {
            if (!d.success) { showErr(d.error || cfg.str.loadFailed); return; }
            showOk(cfg.str.bulkDone.replace('{n}', d.updated));
            state.selected = {}; load();
        });
    }

    /* ---------------- detail drawer ---------------- */
    function openDrawer(id) {
        apiGet('prospect', { id: id }).then(function (d) {
            if (!d.success) { showErr(d.error || cfg.str.loadFailed); return; }
            state.detail = d;
            renderDrawer(d);
        }).catch(function () { showErr(cfg.str.loadFailed); });
    }
    function closeDrawer() { $('moDrawer').classList.add('mo-hidden'); $('moDrawer').innerHTML = ''; state.detail = null; }

    function renderDrawer(d) {
        var p = d.prospect, f = (p.fields) || {};
        var el = $('moDrawer');
        var eligHtml = '';
        if (!empty(p.eligible)) {
            eligHtml = '<span class="mo-pill eligible">' + esc(cfg.str.eligible) + '</span>';
        } else {
            var reasons = (p.eligibility && p.eligibility.initial && p.eligibility.initial.flags || [])
                .concat(p.eligibility && p.eligibility.followup && p.eligibility.followup.flags || []);
            eligHtml = '<span class="mo-pill suppressed">' + esc(cfg.str.excluded) + '</span>' +
                (reasons.length ? ' <span class="mo-sub">' + esc(reasons.join(', ')) + '</span>' : '');
        }
        var flags = (p.flags || []).map(function (fl) {
            return '<span class="mo-flag ' + fl + '">' + esc(fl.replace(/_/g, ' ')) + '</span>';
        }).join(' ');
        var review = '';
        if (f.needs_review) {
            review = '<div class="mo-warn">' + esc(cfg.str.needsReview) +
                (f.review_reason ? ': ' + esc(f.review_reason.replace(/,/g, ', ')) : '') +
                ' <button class="mo-btn secondary small" id="moResolveReview">' + esc(cfg.str.markReviewed) + '</button></div>';
        }
        var items = [
            [cfg.str.colOrg, f.org], [cfg.str.parentOrg, f.parent_org], [cfg.str.colType, f.practice_type],
            [cfg.str.colContact, p.contact_name || ''], [cfg.str.colEmail, f.email], [cfg.str.colTitle, f.title],
            [cfg.str.colPhone, f.phone], [cfg.str.colLocation, f.location], [cfg.str.colWebsite, f.website],
            [cfg.str.colStatus, f.status], [cfg.str.colResponse, f.response], [cfg.str.colPriority, f.priority],
            [cfg.str.colFit, f.fit], [cfg.str.colOwner, f.owner], [cfg.str.colSource, f.source],
            [cfg.str.colLastContact, f.last_contact], [cfg.str.colFollowup, fmtDate(p.followup_ts)],
            [cfg.str.colCampaign, f.campaign], [cfg.str.colMethod, f.method],
            [cfg.str.demoStatus, f.demo_status], [cfg.str.trialStatus, f.trial_status],
            [cfg.str.outcome, f.outcome], [cfg.str.objection, f.objection_reason]
        ];
        var grid = '<div class="mo-detail-grid">';
        items.forEach(function (r) {
            if (empty(r[1])) return;
            grid += '<div class="mo-detail-item"><div class="k">' + esc(r[0]) + '</div><div class="v">' + esc(r[1]) + '</div></div>';
        });
        grid += '</div>';
        var orgHtml = '';
        if (d.org) {
            orgHtml = '<h3>' + esc(cfg.str.organization) + '</h3><div class="mo-detail-grid">' +
                '<div class="mo-detail-item"><div class="k">' + esc(cfg.str.colType) + '</div><div class="v">' + esc(d.org.org_type || '—') + '</div></div>' +
                '<div class="mo-detail-item"><div class="k">' + esc(cfg.str.colDomain) + '</div><div class="v">' + esc(d.org.domain || '—') + '</div></div>' +
                '<div class="mo-detail-item"><div class="k">' + esc(cfg.str.colLocation) + '</div><div class="v">' + esc(d.org.location || '—') + '</div></div>' +
                '<div class="mo-detail-item"><div class="k">' + esc(cfg.str.colWebsite) + '</div><div class="v">' + esc(d.org.website || '—') + '</div></div>' +
                '</div><button class="mo-btn secondary small" id="moEditOrg" data-org="' + d.org.id + '">' + esc(cfg.str.editOrg) + '</button>';
        }
        var campHtml = '';
        if (d.campaigns && d.campaigns.length) {
            campHtml = '<h3>' + esc(cfg.str.campaignMembership) + '</h3><table class="mo-table"><tbody>';
            d.campaigns.forEach(function (c) {
                campHtml += '<tr><td>' + esc(c.name) + '</td><td>' + esc(c.kind) + '</td><td><span class="mo-pill ' + c.status + '">' + esc(c.status) + '</span></td><td><span class="mo-pill ' + (c.recipient_status || '') + '">' + esc(c.recipient_status || '') + '</span></td><td>' + esc(fmtDT(c.sent_at)) + '</td></tr>';
            });
            campHtml += '</tbody></table>';
        }
        var histHtml = '';
        if (d.history && d.history.length) {
            histHtml = '<h3>' + esc(cfg.str.sendHistory) + '</h3><table class="mo-table"><tbody>';
            d.history.forEach(function (h) {
                histHtml += '<tr><td>' + esc(h.campaign_ref) + '</td><td><span class="mo-pill ' + h.status + '">' + esc(h.status) + '</span></td><td>' + esc(fmtDT(h.sent_at || h.created_at)) + (h.gmail_message_id ? ' <span class="mo-sub">' + esc(h.gmail_message_id) + '</span>' : '') + '</td></tr>';
            });
            histHtml += '</tbody></table>';
        }
        var evHtml = '<h3>' + esc(cfg.str.activity) + '</h3>';
        evHtml += '<div class="mo-activity-add"><select id="moActType">' +
            ['note', 'call', 'meeting', 'manual_email', 'status_update'].map(function (t) {
                return '<option value="' + t + '">' + esc(cfg.str['act_' + t] || t) + '</option>';
            }).join('') + '</select>' +
            '<textarea id="moActText" rows="2" placeholder="' + esc(cfg.str.activityPlaceholder) + '"></textarea>' +
            '<button class="mo-btn secondary small" id="moActAdd">' + esc(cfg.str.addActivity) + '</button></div>';
        evHtml += '<ul class="mo-events">';
        (d.events || []).forEach(function (ev) {
            var det = '';
            try { det = JSON.parse(ev.details || '{}'); } catch (e) { det = {}; }
            var label = ev.event_type.replace(/_/g, ' ');
            var manual = ev.event_type === 'activity_manual_email' ? ' <span class="mo-pill awaiting_response">' + esc(cfg.str.manualNotProof) + '</span>' : '';
            var detailText = det.text || (det.changed ? Object.keys(det.changed).join(', ') : '');
            evHtml += '<li><span class="mo-sub">' + esc(fmtDT(ev.created_at)) + '</span> ' +
                '<strong>' + esc(label) + '</strong>' + manual +
                (detailText ? '<div class="mo-sub">' + esc(String(detailText).slice(0, 300)) + '</div>' : '') +
                '<div class="mo-sub">' + esc(ev.actor || '') + '</div></li>';
        });
        evHtml += '</ul>';

        el.innerHTML =
            '<div class="mo-drawer-head"><h2>' + esc(f.org || p.contact_name || cfg.str.prospect) + '</h2>' +
            '<div><button class="mo-btn secondary small" id="moDrawerCampaign">' + esc(cfg.str.addToCampaign) + '</button> ' +
            '<button class="mo-btn secondary small" id="moEditProspect">' + esc(cfg.str.edit) + '</button> ' +
            '<button class="mo-btn secondary small" id="moDrawerClose" aria-label="Close">&times;</button></div></div>' +
            '<div class="mo-drawer-body">' +
            '<p>' + eligHtml + ' ' + flags + '</p>' + review + grid + orgHtml + campHtml + histHtml + evHtml +
            '</div>';
        el.classList.remove('mo-hidden');

        $('moDrawerClose').onclick = closeDrawer;
        $('moDrawerCampaign').onclick = function () { openCampaignAdd([f.contact_id]); };
        $('moEditProspect').onclick = function () { openProspectForm(f.contact_id); };
        var er = $('moResolveReview');
        if (er) er.onclick = function () {
            apiPost('prospect_review_resolve', { contact_id: f.contact_id }).then(function (r) {
                if (r.success) { showOk(cfg.str.reviewResolved); openDrawer(f.contact_id); load(); }
                else showErr(r.error || cfg.str.loadFailed);
            });
        };
        var eo = $('moEditOrg');
        if (eo) eo.onclick = function () { openOrgForm(parseInt(eo.dataset.org, 10)); };
        $('moActAdd').onclick = function () {
            var type = $('moActType').value, text = $('moActText').value.trim();
            if (!text) { showErr(cfg.str.activityRequired); return; }
            apiPost('prospect_activity', { contact_id: f.contact_id, type: type, details: text }).then(function (r) {
                if (r.success) { showOk(cfg.str.activityAdded); openDrawer(f.contact_id); }
                else showErr(r.error || cfg.str.loadFailed);
            });
        };
    }

    /* ---------------- prospect form (create/edit) ---------------- */
    function field(label, inner) {
        return '<label class="mo-field"><span>' + esc(label) + '</span>' + inner + '</label>';
    }
    function openProspectForm(contactId) {
        var isEdit = !!contactId;
        var fill = function (d) {
            var p = d.prospect, f = p.fields || {};
            var orgOpts = '<option value="">' + esc(cfg.str.noOrg) + '</option>';
            state.orgs.forEach(function (o) {
                orgOpts += '<option value="' + o.id + '"' + ((f.org_id === o.id) ? ' selected' : '') + '>' + esc(o.name) + (o.org_type ? ' (' + esc(o.org_type) + ')' : '') + '</option>';
            });
            var body =
                '<div class="mo-form-grid">' +
                field(cfg.str.colOrg, '<input type="search" id="pfOrgSearch" placeholder="' + esc(cfg.str.orgSearchPh) + '" style="margin-bottom:4px;">' +
                    '<select id="pfOrg" size="6" style="min-height:90px;">' + orgOpts + '</select>' +
                    '<div class="mo-sub" style="margin-top:4px;"><label><input type="checkbox" id="pfNewOrgToggle"> ' + esc(cfg.str.createNewOrg) + '</label></div>' +
                    '<div id="pfNewOrgFields" class="mo-hidden"><input id="pfNewOrgName" placeholder="' + esc(cfg.str.orgName) + '">' +
                    '<input id="pfNewOrgType" placeholder="' + esc(cfg.str.orgTypePh) + '">' +
                    '<input id="pfNewOrgDomain" placeholder="' + esc(cfg.str.domainPh) + '"></div>') +
                field(cfg.str.colContact, '<input id="pfName" value="' + esc(p.contact_name || '') + '">') +
                field(cfg.str.firstName, '<input id="pfFirst" value="' + esc(f.contact_first || '') + '">') +
                field(cfg.str.lastName, '<input id="pfLast" value="' + esc(f.contact_last || '') + '">') +
                field(cfg.str.colEmail, '<input id="pfEmail" type="email" value="' + esc(f.email || '') + '">') +
                field(cfg.str.colTitle, '<input id="pfTitle" value="' + esc(f.title || '') + '">') +
                field(cfg.str.colPhone, '<input id="pfPhone" value="' + esc(f.phone || '') + '">') +
                field(cfg.str.colStatus, '<input id="pfStatus" list="moStatuses" value="' + esc(f.status || 'Not Contacted') + '">') +
                field(cfg.str.colPriority, '<select id="pfPriority"><option value="">—</option>' +
                    ['1 - Highest Priority','2 - Strong Priority','3 - Worth Contacting','B - Enrich Next'].map(function (pr) {
                        return '<option' + (f.priority === pr ? ' selected' : '') + '>' + esc(pr) + '</option>';
                    }).join('') + '</select>') +
                field(cfg.str.colOwner, '<input id="pfOwner" type="email" value="' + esc(f.owner || '') + '">') +
                field(cfg.str.colFollowup, '<input id="pfFollowup" type="date" value="' + esc(fmtDate(p.followup_ts) || (f.followup_date || '').slice(0,10)) + '">') +
                field(cfg.str.colTags, '<input id="pfTags" value="' + esc(tagsToStr(f.tags)) + '" placeholder="' + esc(cfg.str.tagsPh) + '">') +
                field(cfg.str.colSource, '<input id="pfSource" value="' + esc(f.source || 'manual') + '">') +
                field(cfg.str.demoStatus, '<input id="pfDemo" value="' + esc(f.demo_status || '') + '">') +
                field(cfg.str.trialStatus, '<input id="pfTrial" value="' + esc(f.trial_status || '') + '">') +
                field(cfg.str.outcome, '<input id="pfOutcome" value="' + esc(f.outcome || '') + '">') +
                field(cfg.str.colNotes, '<textarea id="pfNotes" rows="3">' + esc(f.notes || '') + '</textarea>') +
                '</div>' +
                '<datalist id="moStatuses"><option>Not Contacted</option><option>Sent</option><option>Do Not Contact</option></datalist>' +
                '<div id="pfWarnings"></div>';
            openModal(modalShell(isEdit ? cfg.str.editProspect : cfg.str.addProspect, body,
                '<button class="mo-btn" id="pfSave">' + esc(cfg.str.save) + '</button>' +
                '<button class="mo-btn secondary" data-close-btn>' + esc(cfg.str.cancel) + '</button>'));
            bindOrgPicker('pfOrg', 'pfOrgSearch');
            bindForm(p, isEdit ? f.version : null);
        };
        if (isEdit) {
            apiGet('prospect', { id: contactId }).then(function (d) {
                if (!d.success) { showErr(d.error || cfg.str.loadFailed); return; }
                fill(d);
            });
        } else {
            fill({ prospect: { fields: {} } });
        }
    }
    /* Org picker: server-side search so all orgs are reachable (there are
     * far more than one page of them). */
    function bindOrgPicker(selectId, searchId) {
        var sel = $(selectId), search = $(searchId);
        var load = debounce(function () {
            apiGet('orgs', { q: search.value, per: 50 }).then(function (d) {
                if (!d.success) return;
                var keep = sel.value;
                var html = '<option value="">' + esc(cfg.str.noOrg) + '</option>';
                (d.orgs || []).forEach(function (o) {
                    html += '<option value="' + o.id + '"' + (String(o.id) === keep ? ' selected' : '') + '>' +
                        esc(o.name) + (o.org_type ? ' (' + esc(o.org_type) + ')' : '') + '</option>';
                });
                sel.innerHTML = html;
            });
        }, 300);
        search.addEventListener('input', load);
    }
    function tagsToStr(tags) {
        if (Array.isArray(tags)) return tags.join(', ');
        if (typeof tags === 'string' && tags) { try { return (JSON.parse(tags) || []).join(', '); } catch (e) { return tags; } }
        return '';
    }
    function bindForm(p, version) {
        var tog = $('pfNewOrgToggle');
        tog.onchange = function () { $('pfNewOrgFields').classList.toggle('mo-hidden', !tog.checked); };
        $('pfSave').onclick = function () {
            var orgId = parseInt(($('pfOrg').value || '0'), 10);
            var payload = {
                first_name: $('pfFirst').value, last_name: $('pfLast').value,
                full_name: $('pfName').value, email: $('pfEmail').value,
                title: $('pfTitle').value, phone: $('pfPhone').value,
                status: $('pfStatus').value, priority: $('pfPriority').value,
                owner_email: $('pfOwner').value, followup_date: $('pfFollowup').value,
                tags: $('pfTags').value, source: $('pfSource').value,
                demo_status: $('pfDemo').value, trial_status: $('pfTrial').value,
                outcome: $('pfOutcome').value, notes: $('pfNotes').value,
                org_id: orgId || null
            };
            if (tog.checked) {
                payload.new_org = { name: $('pfNewOrgName').value, org_type: $('pfNewOrgType').value, domain: $('pfNewOrgDomain').value };
                payload.org_id = null;
            }
            var req = version === null
                ? apiPost('prospect_create', payload)
                : apiPost('prospect_update', Object.assign({ contact_id: p.fields.contact_id, version: version }, payload));
            req.then(function (d) {
                if (!d.success) {
                    if (d.error === 'contact was modified by another session (version mismatch)' || (d.error || '').indexOf('version') >= 0) {
                        showErr(cfg.str.conflict);
                    } else showErr(d.error || cfg.str.loadFailed);
                    return;
                }
                var warnMsgs = (d.warnings || []).map(function (w) { return w.message || w.code; });
                $('pfWarnings').innerHTML = warnMsgs.map(function (m) { return '<div class="mo-warn">' + esc(m) + '</div>'; }).join('');
                showOk(warnMsgs.length ? cfg.str.savedWithWarnings + ' ' + warnMsgs.join('; ') : cfg.str.saved);
                closeModal(); load();
                if (state.detail && p.fields && p.fields.contact_id) openDrawer(p.fields.contact_id);
            });
        };
    }

    /* ---------------- org form ---------------- */
    function openOrgForm(orgId) {
        var isEdit = !!orgId;
        var fill = function (d) {
            var o = d.org || {};
            var parentOpts = '<option value="">—</option>';
            state.orgs.forEach(function (x) {
                if (x.id === o.id) return;
                parentOpts += '<option value="' + x.id + '"' + ((o.parent_org_id === x.id) ? ' selected' : '') + '>' + esc(x.name) + '</option>';
            });
            var body = '<div class="mo-form-grid">' +
                field(cfg.str.orgName, '<input id="ofName" value="' + esc(o.name || '') + '">') +
                field(cfg.str.orgTypePh, '<input id="ofType" value="' + esc(o.org_type || '') + '">') +
                field(cfg.str.parentOrg, '<input type="search" id="ofParentSearch" placeholder="' + esc(cfg.str.orgSearchPh) + '" style="margin-bottom:4px;">' +
                    '<select id="ofParent">' + parentOpts + '</select>') +
                field(cfg.str.colWebsite, '<input id="ofWebsite" value="' + esc(o.website || '') + '">') +
                field(cfg.str.colDomain, '<input id="ofDomain" value="' + esc(o.domain || '') + '">') +
                field(cfg.str.colLocation, '<input id="ofLocation" value="' + esc(o.location || '') + '">') +
                field(cfg.str.colPhone, '<input id="ofPhone" value="' + esc(o.phone || '') + '">') +
                field(cfg.str.colStatus, '<input id="ofStatus" value="' + esc(o.status || 'active') + '">') +
                '</div><div id="ofWarnings"></div>';
            openModal(modalShell(isEdit ? cfg.str.editOrg : cfg.str.addOrg, body,
                '<button class="mo-btn" id="ofSave">' + esc(cfg.str.save) + '</button>' +
                '<button class="mo-btn secondary" data-close-btn>' + esc(cfg.str.cancel) + '</button>'));
            bindOrgPicker('ofParent', 'ofParentSearch');
            // Seed the parent search with the current parent's name so the
            // selected value survives the first server-side reload.
            if (isEdit && d.parent && d.parent.name) {
                $('ofParentSearch').value = d.parent.name;
                $('ofParentSearch').dispatchEvent(new Event('input'));
            }
            $('ofSave').onclick = function () {
                var payload = {
                    name: $('ofName').value, org_type: $('ofType').value,
                    parent_org_id: parseInt($('ofParent').value || '0', 10) || null,
                    website: $('ofWebsite').value, domain: $('ofDomain').value,
                    location: $('ofLocation').value, phone: $('ofPhone').value,
                    status: $('ofStatus').value
                };
                var req = isEdit ? apiPost('org_update', Object.assign({ org_id: orgId }, payload))
                                 : apiPost('org_create', payload);
                req.then(function (d) {
                    if (!d.success) { showErr(d.error || cfg.str.loadFailed); return; }
                    var warnHtml = (d.warnings || []).map(function (w) {
                        var extra = (w.matches || []).map(function (m) { return esc(m.name || m); }).join(', ');
                        return '<div class="mo-warn">' + esc(w.message || w.code) + (extra ? ': ' + extra : '') + '</div>';
                    }).join('');
                    $('ofWarnings').innerHTML = warnHtml;
                    if (!warnHtml) { showOk(cfg.str.saved); closeModal(); refreshOrgs(); }
                    else showOk(cfg.str.savedWithWarnings);
                });
            };
        };
        if (isEdit) apiGet('org', { id: orgId }).then(fill);
        else fill({ org: {} });
    }

    /* ---------------- add to campaign ---------------- */
    function openCampaignAdd(forIds, kindPreset) {
        var ids = forIds || selectedIds();
        if (!ids.length) return;
        var kindFixed = kindPreset || null;
        apiGet('campaigns').then(function (d) {
            var drafts = (d.campaigns || []).filter(function (c) { return c.status === 'draft'; });
            var opts = '<option value="">' + esc(cfg.str.newDraftCampaign) + '</option>';
            drafts.forEach(function (c) {
                opts += '<option value="' + esc(c.id) + '">' + esc(c.name) + ' (' + esc(c.kind) + ', ' + c.recipients + '/' + cfg.str.maxRecipients + ')</option>';
            });
            var body = '<p>' + esc(cfg.str.addToCampaignNote.replace('{n}', ids.length)) + '</p>' +
                field(cfg.str.colCampaign, '<select id="caCampaign">' + opts + '</select>') +
                '<div id="caNewName" class="mo-hidden">' + field(cfg.str.campaignName, '<input id="caName" placeholder="' + esc(cfg.str.campaignNamePh) + '">') +
                field(cfg.str.colKind, '<select id="caKind"><option value="initial"' + (kindFixed === 'initial' ? ' selected' : '') + '>' + esc(cfg.str.kindInitial) + '</option><option value="followup"' + (kindFixed === 'followup' ? ' selected' : '') + '>' + esc(cfg.str.kindFollowup) + '</option></select>') + '</div>' +
                '<div id="caPreview"></div>';
            openModal(modalShell(cfg.str.addToCampaign, body,
                '<button class="mo-btn" id="caConfirm">' + esc(cfg.str.confirmAdd) + '</button>' +
                '<button class="mo-btn secondary" data-close-btn>' + esc(cfg.str.cancel) + '</button>'));
            function payload(dry) {
                var p = { contact_ids: ids, dry_run: !!dry };
                var cid = $('caCampaign').value;
                if (cid) p.campaign_id = cid;
                else { p.new_campaign_name = $('caName').value; p.kind = $('caKind').value; }
                return p;
            }
            var previewTimer = null;
            function refreshPreview() {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(function () {
                    var camEl = $('caCampaign'), nameEl = $('caName'), prevEl = $('caPreview');
                    if (!camEl || !nameEl || !prevEl) return; // modal closed
                    var cid = camEl.value;
                    if (!cid && !nameEl.value.trim()) { prevEl.innerHTML = ''; return; }
                    apiPost('campaign_add_recipients', payload(true)).then(function (r) {
                        if (!prevEl.isConnected) return;
                        if (!r.success) { prevEl.innerHTML = '<div class="mo-warn">' + esc(r.error || cfg.str.loadFailed) + '</div>'; return; }
                        var html = '<table class="mo-table" style="margin-top:8px;"><thead><tr><th>' + esc(cfg.str.colContact) + '</th><th>' + esc(cfg.str.colOrg) + '</th><th>' + esc(cfg.str.colEmail) + '</th><th>' + esc(cfg.str.colEligibility) + '</th></tr></thead><tbody>';
                        (r.results || []).forEach(function (x) {
                            var status = x.ok ? ('<span class="mo-pill ' + (x.status === 'draft' ? 'draft' : 'suppressed') + '">' + esc(x.status) + '</span>')
                                              : '<span class="mo-pill excluded">' + esc(x.reason || 'skipped') + '</span>';
                            html += '<tr><td>' + esc(x.contact_name || '—') + '</td><td>' + esc(x.org || '—') + '</td><td>' + esc(x.email || '—') + '</td><td>' + status + '</td></tr>';
                        });
                        prevEl.innerHTML = html + '</tbody></table>' +
                            '<p class="mo-sub">' + esc(cfg.str.previewNote.replace('{n}', r.added)) + '</p>';
                    });
                }, 250);
            }
            $('caCampaign').onchange = function () {
                var isNew = $('caCampaign').value === '';
                $('caNewName').classList.toggle('mo-hidden', !isNew);
                refreshPreview();
            };
            $('caKind').onchange = refreshPreview;
            $('caName').addEventListener('input', refreshPreview);
            refreshPreview();
            $('caConfirm').onclick = function () {
                if (!$('caCampaign').value && !$('caName').value.trim()) { showErr(cfg.str.campaignNameRequired); return; }
                apiPost('campaign_add_recipients', payload(false)).then(function (r) {
                    if (!r.success) { showErr(r.error || cfg.str.loadFailed); return; }
                    var added = (r.results || []).filter(function (x) { return x.ok; }).length;
                    var skipped = (r.results || []).filter(function (x) { return !x.ok; })
                        .map(function (x) { return x.email + ': ' + x.reason; }).join('; ');
                    showOk(cfg.str.campaignAddDone.replace('{n}', added) + (skipped ? ' — ' + skipped : ''));
                    closeModal(); state.selected = {}; updateBulkBar(); load();
                });
            };
        });
    }

    /* ---------------- CSV import ---------------- */
    function openImport() {
        var body = '<p>' + esc(cfg.str.importNote) + '</p>' +
            '<input type="file" id="impFile" accept=".csv,text/csv">' +
            '<div id="impPreview"></div>';
        openModal(modalShell(cfg.str.importCsv, body,
            '<button class="mo-btn" id="impPreviewBtn">' + esc(cfg.str.preview) + '</button>' +
            '<button class="mo-btn mo-hidden" id="impCommitBtn">' + esc(cfg.str.confirmImport) + '</button>' +
            '<button class="mo-btn secondary" data-close-btn>' + esc(cfg.str.cancel) + '</button>'));
        var pending = null;
        $('impPreviewBtn').onclick = function () {
            var f = $('impFile').files[0];
            if (!f) { showErr(cfg.str.importNoFile); return; }
            var reader = new FileReader();
            reader.onload = function () {
                apiPost('import_preview', { csv: String(reader.result) }).then(function (d) {
                    if (!d.success) { showErr(d.error || cfg.str.loadFailed); return; }
                    pending = d.rows;
                    var counts = { new: 0, update: 0, invalid: 0 };
                    d.rows.forEach(function (r) { counts[r.disposition] = (counts[r.disposition] || 0) + 1; });
                    var unmapped = d.unmapped_columns || [];
                    var html = (unmapped.length
                        ? '<div class="mo-warn">' + esc(S('unmappedColumns') + ': ' + unmapped.join(', ')) + '</div>' : '') +
                        '<p>' + esc(cfg.str.importCounts
                        .replace('{new}', counts.new || 0).replace('{upd}', counts.update || 0).replace('{inv}', counts.invalid || 0)) + '</p>' +
                        '<table class="mo-table"><thead><tr><th>' + esc(cfg.str.colLine) + '</th><th>' + esc(cfg.str.colOrg) + '</th><th>' + esc(cfg.str.colContact) + '</th><th>' + esc(cfg.str.colEmail) + '</th><th>' + esc(cfg.str.colStatus) + '</th><th>' + esc(cfg.str.disposition) + '</th></tr></thead><tbody>';
                    d.rows.slice(0, 100).forEach(function (r) {
                        html += '<tr><td>' + r.line + '</td><td>' + esc(r.org) + '</td><td>' + esc(r.contact_name) + '</td><td>' + esc(r.email) + '</td><td>' + esc(r.status) + '</td><td>' + esc(r.disposition) + (r.warnings.length ? ' <span class="mo-sub">' + esc(r.warnings.join(', ')) + '</span>' : '') + '</td></tr>';
                    });
                    html += '</tbody></table>' + (d.rows.length > 100 ? '<p class="mo-sub">… ' + (d.rows.length - 100) + ' more</p>' : '');
                    $('impPreview').innerHTML = html;
                    $('impCommitBtn').classList.remove('mo-hidden');
                });
            };
            reader.readAsText(f);
        };
        $('impCommitBtn').onclick = function () {
            if (!pending) return;
            apiPost('import_commit', { records: pending }).then(function (r) {
                if (!r.success) { showErr(r.error || cfg.str.loadFailed); return; }
                showOk(cfg.str.importDone.replace('{c}', r.created).replace('{u}', r.updated).replace('{s}', r.skipped));
                closeModal(); load();
            });
        };
    }

    /* ---------------- export ---------------- */
    function exportCsv(ids) {
        var qs = '?action=export';
        if (ids && ids.length) qs += '&ids=' + ids.join(',');
        else {
            var p = listParams();
            Object.keys(p).forEach(function (k) { if (p[k] !== '' && p[k] != null) qs += '&' + k + '=' + encodeURIComponent(p[k]); });
        }
        fetch(cfg.api + qs, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'export failed'); });
            return r.blob();
        }).then(function (blob) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'prospects-' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
        }).catch(function (e) { showErr(e.message || cfg.str.loadFailed); });
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: campaign wizard + management                               */
    /* ------------------------------------------------------------------ */

    var campState = { list: [], current: null, sending: null, templates: [] };

    function S(k) { return cfg.str[k] || k; }

    function loadCampaigns() {
        var host = $('moCampaignList');
        host.classList.remove('mo-hidden');
        $('moCampaignDetail').classList.add('mo-hidden');
        campState.current = null;
        host.innerHTML = '<div class="pa-card">' + esc(S('loading')) + '</div>';
        apiGet('campaigns').then(function (d) {
            if (!d.success) { host.innerHTML = ''; showErr(d.error || S('loadFailed')); return; }
            campState.list = d.campaigns || [];
            if (!campState.list.length) {
                host.innerHTML = '<div class="pa-card">' + esc(S('noCampaigns')) + '</div>';
                return;
            }
            var html = '<table class="mo-table"><thead><tr><th>' + esc(S('colName')) + '</th><th>' + esc(S('colKind')) + '</th><th>' + esc(S('colStatus')) + '</th><th>' + esc(S('colRecipients')) + '</th><th>' + esc(S('colSent')) + '</th><th>' + esc(S('colCreated')) + '</th></tr></thead><tbody>';
            campState.list.forEach(function (c) {
                html += '<tr class="clickable" data-id="' + esc(c.id) + '"><td><strong>' + esc(c.name) + '</strong>' +
                    (c.description ? '<br><span class="mo-sub">' + esc(c.description) + '</span>' : '') + '</td>' +
                    '<td>' + esc(c.kind) + '</td><td><span class="mo-pill ' + esc(c.status) + '">' + esc(c.status) + '</span></td>' +
                    '<td>' + c.recipients + '</td><td>' + c.sent + '</td><td>' + esc(fmtDT(c.created_at)) + '</td></tr>';
            });
            host.innerHTML = html + '</tbody></table>';
            host.querySelectorAll('tr.clickable').forEach(function (tr) {
                tr.addEventListener('click', function () { openCampaign(tr.dataset.id); });
            });
        }).catch(function () { host.innerHTML = ''; showErr(S('loadFailed')); });
    }

    function campBack() {
        $('moCampaignDetail').classList.add('mo-hidden');
        $('moCampaignList').classList.remove('mo-hidden');
        campState.current = null;
        loadCampaigns();
    }

    /* Prospect picker: server-side search + multi-select, shared by the
       wizard (step 2) and the add-recipients flow. onConfirm(ids). */
    function prospectPicker(opts) {
        var sel = {}; var page = 1; var q = '';
        var body = '<div class="mo-toolbar" style="margin-bottom:8px;">' +
            '<input type="search" id="pkQ" placeholder="' + esc(S('searchProspects')) + '" style="flex:1;">' +
            '<select id="pkElig"><option value="">' + esc(S('allEligibility')) + '</option>' +
            '<option value="initial">' + esc(S('kindInitial')) + '</option>' +
            '<option value="followup">' + esc(S('kindFollowup')) + '</option></select></div>' +
            '<div id="pkList"></div>' +
            '<div id="pkSel" class="mo-sub" style="margin-top:8px;"></div>';
        openModal(modalShell(opts.title || S('selectRecipients'), body,
            '<button class="mo-btn" id="pkConfirm">' + esc(S('addSelected')) + '</button>' +
            '<button class="mo-btn secondary" data-close-btn>' + esc(S('cancel')) + '</button>'));
        var eligKind = opts.kind || 'initial';
        function renderSel() {
            var ids = Object.keys(sel);
            $('pkSel').textContent = S('selectedCount').replace('{n}', ids.length) + (ids.length > cfg.str.maxRecipients ? ' — ' + S('overLimit') : '');
            $('pkConfirm').disabled = !ids.length || ids.length > cfg.str.maxRecipients;
        }
        function load() {
            $('pkList').innerHTML = '<div class="pa-card">' + esc(S('loading')) + '</div>';
            apiGet('prospects', { q: q, eligible: eligKind, page: page, per: 25 }).then(function (d) {
                if (!d.success) { $('pkList').innerHTML = ''; showErr(d.error || S('loadFailed')); return; }
                var html = '<table class="mo-table"><thead><tr><th></th><th>' + esc(S('colOrg')) + '</th><th>' + esc(S('colContact')) + '</th><th>' + esc(S('colEmail')) + '</th><th>' + esc(S('colEligibility')) + '</th></tr></thead><tbody>';
                (d.prospects || []).forEach(function (p) {
                    var f = p.fields || {};
                    var cid = f.contact_id || p.row;
                    var elig = (p.eligible || []).length ? '<span class="mo-pill eligible">' + esc(S('eligible')) + '</span>'
                        : '<span class="mo-pill suppressed" title="' + esc((p.initial_flags || []).concat(p.followup_flags || []).join(', ')) + '">' + esc(S('excluded')) + '</span>';
                    html += '<tr><td><input type="checkbox" class="pkCb" data-id="' + cid + '"' + (sel[cid] ? ' checked' : '') + '></td>' +
                        '<td>' + esc(f.org || '—') + '</td><td>' + esc(p.contact_name || '—') + '</td>' +
                        '<td><span class="mo-sub">' + esc(f.email || '(missing)') + '</span></td><td>' + elig + '</td></tr>';
                });
                if (!(d.prospects || []).length) html += '<tr><td colspan="5" class="mo-sub">' + esc(S('noProspects')) + '</td></tr>';
                html += '</tbody></table>';
                var pages = Math.max(1, Math.ceil((d.total || 0) / 25));
                html += '<div class="mo-toolbar"><button class="mo-btn secondary small" id="pkPrev" ' + (page <= 1 ? 'disabled' : '') + '>←</button>' +
                    '<span class="mo-sub">' + page + ' / ' + pages + '</span>' +
                    '<button class="mo-btn secondary small" id="pkNext" ' + (page >= pages ? 'disabled' : '') + '>→</button></div>';
                $('pkList').innerHTML = html;
                $('pkList').querySelectorAll('.pkCb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var id = parseInt(cb.dataset.id, 10);
                        if (cb.checked) sel[id] = true; else delete sel[id];
                        renderSel();
                    });
                });
                $('pkPrev').onclick = function () { page--; load(); };
                $('pkNext').onclick = function () { page++; load(); };
            }).catch(function () { $('pkList').innerHTML = ''; showErr(S('loadFailed')); });
        }
        var deb = debounce(function () { q = $('pkQ').value; page = 1; load(); }, 350);
        $('pkQ').addEventListener('input', deb);
        $('pkElig').addEventListener('change', function () { eligKind = this.value || opts.kind || 'initial'; page = 1; load(); });
        $('pkConfirm').onclick = function () {
            var ids = Object.keys(sel).map(function (k) { return parseInt(k, 10); });
            if (!ids.length) return;
            closeModal();
            opts.onConfirm(ids);
        };
        load(); renderSel();
    }

    /* Step 1+2 of the wizard: details then recipients, then open detail.
       Picking recipients closes the modal, so form state is kept in
       wzForm and restored when the wizard reopens. */
    var wzForm = { picked: {}, name: '', kind: 'initial', tpl: '', desc: '' };
    function newCampaignWizard() {
        apiGet('templates').then(function (d) {
            campState.templates = (d && d.templates) || [];
            var tOpts = '<option value="">' + esc(S('tplDefault')) + '</option>';
            campState.templates.forEach(function (t) {
                tOpts += '<option value="' + t.id + '">' + esc(t.name) + ' (' + esc(t.kind) + ')</option>';
            });
            var body =
                '<div class="mo-wizard-step">' + esc(S('stepDetails')) + '</div>' +
                field(S('campaignName'), '<input id="wzName" value="' + esc(wzForm.name) + '" placeholder="' + esc(S('campaignNamePh')) + '">') +
                field(S('colKind'), '<select id="wzKind"><option value="initial"' + (wzForm.kind === 'initial' ? ' selected' : '') + '>' + esc(S('kindInitial')) + '</option><option value="followup"' + (wzForm.kind === 'followup' ? ' selected' : '') + '>' + esc(S('kindFollowup')) + '</option></select>') +
                field(S('template'), '<select id="wzTpl">' + tOpts.replace('value="' + esc(wzForm.tpl) + '"', 'value="' + esc(wzForm.tpl) + '" selected') + '</select>') +
                field(S('description'), '<textarea id="wzDesc" rows="2">' + esc(wzForm.desc) + '</textarea>') +
                '<div class="mo-wizard-step">' + esc(S('stepRecipients')) + '</div>' +
                '<div id="wzPicked" class="mo-sub"></div>' +
                '<button class="mo-btn secondary small" id="wzPick">' + esc(S('chooseRecipients')) + '</button>';
            openModal(modalShell(S('newCampaign'), body,
                '<button class="mo-btn" id="wzCreate">' + esc(S('createDraft')) + '</button>' +
                '<button class="mo-btn secondary" data-close-btn>' + esc(S('cancel')) + '</button>'));
            ['wzName','wzKind','wzTpl','wzDesc'].forEach(function (id) {
                $(id).addEventListener('input', function () {
                    wzForm[id === 'wzName' ? 'name' : id === 'wzKind' ? 'kind' : id === 'wzTpl' ? 'tpl' : 'desc'] = $(id).value;
                });
                $(id).addEventListener('change', function () {
                    wzForm[id === 'wzName' ? 'name' : id === 'wzKind' ? 'kind' : id === 'wzTpl' ? 'tpl' : 'desc'] = $(id).value;
                });
            });
            function renderPicked() {
                var n = Object.keys(wzForm.picked).length;
                $('wzPicked').textContent = n ? S('selectedCount').replace('{n}', n) + (n > cfg.str.maxRecipients ? ' — ' + S('overLimit') : '') : S('noRecipientsYet');
            }
            $('wzPick').onclick = function () {
                wzForm.name = $('wzName').value; wzForm.kind = $('wzKind').value;
                wzForm.tpl = $('wzTpl').value; wzForm.desc = $('wzDesc').value;
                prospectPicker({
                    title: S('selectRecipients'), kind: wzForm.kind,
                    onConfirm: function (ids) {
                        ids.forEach(function (i) { wzForm.picked[i] = true; });
                        newCampaignWizard();
                    }
                });
            };
            $('wzCreate').onclick = function () {
                var name = $('wzName').value.trim();
                if (!name) { showErr(S('campaignNameRequired')); return; }
                var ids = Object.keys(wzForm.picked).map(function (k) { return parseInt(k, 10); });
                if (ids.length > cfg.str.maxRecipients) { showErr(S('overLimit')); return; }
                apiPost('campaign_create', {
                    name: name, kind: $('wzKind').value,
                    template_id: $('wzTpl').value || null,
                    description: $('wzDesc').value || null,
                    contact_ids: ids
                }).then(function (r) {
                    if (!r.success) { showErr(r.error || S('loadFailed')); return; }
                    closeModal(); wzForm = { picked: {}, name: '', kind: 'initial', tpl: '', desc: '' };
                    showOk(S('campaignCreated'));
                    campBack();
                    openCampaign(r.campaign_id);
                });
            };
            renderPicked();
        });
    }

    function openCampaign(id) {
        campState.current = id;
        $('moCampaignList').classList.add('mo-hidden');
        $('moCampaignDetail').classList.remove('mo-hidden');
        apiGet('campaign', { id: id }).then(function (d) {
            if (!d.success) { showErr(d.error || S('loadFailed')); return; }
            var c = d.campaign;
            campState.campaign = c;
            campState.sending = d.sending || null;
            var canSend = !!(campState.sending && campState.sending.enabled);
            var recips = d.recipients || [];
            var editable = (c.status === 'draft' || c.status === 'ready');
            var retryable = (c.status === 'partial');
            $('moCampTitle').textContent = c.name;
            $('moCampMeta').textContent = c.kind + ' · ' + c.status +
                (c.created_by ? ' · ' + c.created_by : '') +
                (c.approved_by ? ' · ' + S('approvedBy') + ' ' + c.approved_by : '') +
                (c.description ? ' — ' + c.description : '');
            var warn = $('moCampApproveWarn');
            if (c.status === 'approved' || c.status === 'partial') {
                warn.textContent = S('approveWarn'); warn.classList.remove('mo-hidden');
            } else warn.classList.add('mo-hidden');
            $('moCampApproveCrm').textContent = canSend ? S('approveAndSend') : S('approveOnly');
            $('moCampApproveCrm').disabled = !(c.status === 'draft' || c.status === 'ready');
            $('moCampApproveCrm').classList.toggle('mo-hidden', retryable);
            $('moCampRetryCrm').classList.toggle('mo-hidden', !retryable);
            $('moCampRetryCrm').disabled = !canSend;
            $('moCampSendCrm').classList.toggle('mo-hidden', !(canSend && (c.status === 'approved' || c.status === 'partial')));
            $('moCampEditCrm').classList.toggle('mo-hidden', !editable);
            $('moCampAddRecipCrm').classList.toggle('mo-hidden', !editable);
            $('moCampThreadsCrm').classList.toggle('mo-hidden',
                !(c.kind === 'followup' && (c.status === 'draft' || c.status === 'ready')));
            var stepEl = $('moCampStepCrm') || $('moCampStep');
            if (stepEl) stepEl.textContent = editable ? S('stepEdit') : '';
            // Template selector for editable campaigns.
            var ts = $('moCampTplCrm');
            ts.innerHTML = '<option value="">' + esc(S('tplDefault')) + '</option>';
            (campState.templates.length ? campState.templates : []).forEach(function (t) {
                if (t.kind === c.kind) ts.innerHTML += '<option value="' + t.id + '"' + ((c.template_id | 0) === (t.id | 0) ? ' selected' : '') + '>' + esc(t.name) + '</option>';
            });
            ts.disabled = !editable;
            renderCampMessages(recips, editable, c);
        }).catch(function (e) { if (e) console.warn('[MOCrm] campaign load', e); showErr(S('loadFailed')); });
    }

    function renderCampMessages(recips, editable, camp) {
        var host = $('moCampMessages');
        host.innerHTML = '';
        recips.forEach(function (r) {
            var div = document.createElement('div');
            div.className = 'mo-msg';
            var flags = (r.status === 'suppressed' || r.status === 'skipped')
                ? '<span class="mo-pill ' + r.status + '">' + esc(r.status + (r.skip_reason ? ': ' + r.skip_reason : '')) + '</span>' : '';
            var canEdit = editable && r.status !== 'suppressed';
            var canRemove = editable && !['sent', 'sending', 'uncertain'].includes(r.status);
            var notes = '';
            var locked = r.thread_status === 'resolved';
            if (camp.kind === 'followup' && r.thread_status) {
                flags += '<span class="mo-pill ' + (locked ? 'thread-resolved' : 'thread-new') + '">' + esc(r.thread_status.replace(/_/g, ' ')) + '</span> ';
            }
            if (r.status === 'uncertain') {
                notes += '<div class="mo-msg-note">' + esc(S('uncertainNote')) + '</div>' +
                    '<div style="margin:6px 0;display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button class="mo-btn secondary small" data-resolve="not_sent">' + esc(S('markNotSent')) + '</button>' +
                    '<button class="mo-btn secondary small" data-resolve="sent">' + esc(S('markSent')) + '</button></div>';
            }
            div.innerHTML =
                '<div class="mo-msg-head"><strong>' + esc(r.prospect_contact || r.prospect_email) + '</strong>' +
                '<span>' + flags + '<span class="mo-pill ' + r.status + '">' + esc(r.status) + '</span>' +
                (r.is_edited == 1 ? ' <span class="mo-pill">' + esc(S('edited')) + '</span>' : '') + '</span></div>' +
                '<div class="mo-msg-sub">' + esc(r.prospect_org || '') + ' · ' + esc(r.prospect_email) +
                (r.contact_id ? ' · #' + r.contact_id : '') +
                (r.error ? ' · <span style="color:#b91c1c;">' + esc(r.error) + '</span>' : '') + '</div>' + notes +
                (canEdit
                    ? '<input type="text" class="mo-subj"' + (locked ? ' readonly' : '') + ' value="' + esc(r.subject || '') + '" placeholder="' + esc(S('subjectPh')) + '">' +
                      '<textarea class="mo-body">' + esc(r.body_html || '') + '</textarea>' +
                      '<div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">' +
                      '<button class="mo-btn small" data-act="save">' + esc(S('save')) + '</button>' +
                      '<button class="mo-btn secondary small" data-act="regen">' + esc(S('regen')) + '</button>' +
                      '<button class="mo-btn secondary small" data-act="preview">' + esc(S('preview')) + '</button>' +
                      (canRemove ? '<button class="mo-btn danger small" data-act="remove">' + esc(S('remove')) + '</button>' : '') + '</div>' +
                      '<div class="mo-preview mo-hidden" style="margin-top:8px;"></div>'
                    : '<div class="mo-preview">' + (r.body_html || '<em>' + esc(S('noContent')) + '</em>') + '</div>');
            host.appendChild(div);
            div.querySelectorAll('button[data-resolve]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var outcome = b.dataset.resolve;
                    confirmModal(outcome === 'sent' ? S('confirmMarkSent') : S('confirmMarkNotSent'), function () {
                        apiPost('resolve_uncertain', { campaign_id: campState.current, recipient_id: r.id, outcome: outcome })
                            .then(function (d2) { if (!d2.success) showErr(d2.error || S('loadFailed')); openCampaign(campState.current); });
                    });
                });
            });
            div.querySelectorAll('button[data-act]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var act = b.dataset.act;
                    if (act === 'preview') {
                        var pv = div.querySelector('.mo-preview');
                        pv.classList.toggle('mo-hidden');
                        if (!pv.classList.contains('mo-hidden')) pv.innerHTML = div.querySelector('.mo-body').value || '';
                    } else if (act === 'save') {
                        apiPost('save_message', { campaign_id: campState.current, recipient_id: r.id,
                            subject: div.querySelector('.mo-subj').value, body_html: div.querySelector('.mo-body').value })
                            .then(function (d2) { if (d2.success) { showOk(S('saved')); openCampaign(campState.current); } else showErr(d2.error); });
                    } else if (act === 'regen') {
                        apiPost('generate', { campaign_id: campState.current, recipient_ids: [r.id], force: true })
                            .then(function (d2) { if (d2.success) { showOk(S('generatedOk').replace('{n}', d2.generated)); openCampaign(campState.current); } else showErr(d2.error); });
                    } else if (act === 'remove') {
                        confirmModal(S('confirmRemoveRecipient'), function () {
                            apiPost('campaign_recipient_remove', { campaign_id: campState.current, recipient_id: r.id })
                                .then(function (d2) { if (d2.success) { showOk(S('removed')); openCampaign(campState.current); } else showErr(d2.error); });
                        });
                    }
                });
            });
        });
        if (!recips.length) host.innerHTML = '<div class="pa-card mo-sub">' + esc(S('noRecipientsYet')) + '</div>';
    }

    function confirmModal(msg, onYes) {
        openModal(modalShell(S('confirm'), '<p>' + esc(msg) + '</p>',
            '<button class="mo-btn danger" id="cfYes">' + esc(S('yes')) + '</button>' +
            '<button class="mo-btn secondary" data-close-btn>' + esc(S('cancel')) + '</button>'));
        $('cfYes').onclick = function () { closeModal(); onYes(); };
    }

    function openCampaignEdit(c) {
        var tOpts = '<option value="">' + esc(S('tplDefault')) + '</option>';
        (campState.templates || []).forEach(function (t) {
            if (t.kind === c.kind) tOpts += '<option value="' + t.id + '"' + ((c.template_id | 0) === (t.id | 0) ? ' selected' : '') + '>' + esc(t.name) + '</option>';
        });
        openModal(modalShell(S('editCampaign'),
            field(S('campaignName'), '<input id="edName" value="' + esc(c.name) + '">') +
            field(S('description'), '<textarea id="edDesc" rows="2">' + esc(c.description || '') + '</textarea>') +
            field(S('template'), '<select id="edTpl">' + tOpts + '</select>'),
            '<button class="mo-btn" id="edSave">' + esc(S('save')) + '</button>' +
            '<button class="mo-btn secondary" data-close-btn>' + esc(S('cancel')) + '</button>'));
        $('edSave').onclick = function () {
            apiPost('campaign_update', { campaign_id: c.id, name: $('edName').value,
                description: $('edDesc').value, template_id: $('edTpl').value || null })
                .then(function (d) { if (d.success) { closeModal(); showOk(S('saved')); openCampaign(c.id); } else showErr(d.error); });
        };
    }

    function addRecipientsFlow(c) {
        prospectPicker({
            title: S('addRecipients'), kind: c.kind,
            onConfirm: function (ids) {
                apiPost('campaign_add_recipients', { campaign_id: c.id, contact_ids: ids })
                    .then(function (r) {
                        if (!r.success) { showErr(r.error || S('loadFailed')); return; }
                        var added = (r.results || []).filter(function (x) { return x.ok; }).length;
                        var skipped = (r.results || []).filter(function (x) { return !x.ok; })
                            .map(function (x) { return x.email + ': ' + x.reason; }).join('; ');
                        showOk(S('addedN').replace('{n}', added) + (skipped ? ' — ' + skipped : ''));
                        openCampaign(c.id);
                    });
            }
        });
    }

    function sendCampaign() {
        var c = campState.current;
        confirmModal(S('confirmSendCampaign'), function () {
            apiPost('send', { campaign_id: c }).then(function (d) {
                if (!d.success) { showErr(d.error || S('loadFailed')); openCampaign(c); return; }
                showOk(S('sendSummary').replace('{s}', d.sent).replace('{k}', d.skipped).replace('{f}', d.failed).replace('{u}', d.uncertain));
                openCampaign(c);
            });
        });
    }

    function campGenerate() {
        var btn = $('moCampGenerateCrm'); if (btn) { btn.disabled = true; }
        apiPost('generate', { campaign_id: campState.current }).then(function (d) {
            if (btn) btn.disabled = false;
            if (!d.success) { showErr(d.error || S('loadFailed')); return; }
            showOk(S('generatedOk').replace('{n}', d.generated));
            openCampaign(campState.current);
        });
    }
    function campApprove() {
        // Approve only - never sends. Sending is the separate Send button.
        var c = campState.current;
        confirmModal(S('confirmApproveOnly'), function () {
            apiPost('approve', { campaign_id: c }).then(function (d) {
                if (!d.success) { showErr(d.error || S('loadFailed')); openCampaign(c); return; }
                showOk(S('approvedNotSent'));
                openCampaign(c);
            });
        });
    }
    function campRetry() {
        var c = campState.current;
        confirmModal(S('confirmSendCampaign'), function () {
            apiPost('send', { campaign_id: c }).then(function (d) {
                if (!d.success) { showErr(d.error || S('loadFailed')); openCampaign(c); return; }
                showOk(S('sendSummary').replace('{s}', d.sent).replace('{k}', d.skipped).replace('{f}', d.failed).replace('{u}', d.uncertain));
                openCampaign(c);
            });
        });
    }
    function campThreads() {
        apiPost('resolve_threads', { campaign_id: campState.current }).then(function (d) {
            if (!d.success) showErr(d.error || S('loadFailed'));
            openCampaign(campState.current);
        });
    }
    function campReconcile() {
        apiPost('reconcile', { campaign_id: campState.current }).then(function (d) {
            if (!d.success) showErr(d.error || S('loadFailed'));
            else showOk(S('reconcileResult').replace('{sent}', d.sent).replace('{not_sent}', d.not_sent).replace('{unknown}', d.unknown));
            openCampaign(campState.current);
        });
    }

    /* ---------------- init ---------------- */
    function refreshOrgs() {
        apiGet('orgs', { per: 200 }).then(function (d) {
            if (d.success) state.orgs = d.orgs || [];
        });
    }

    window.MOCrm = {
        load: load,
        loadCampaigns: loadCampaigns,
        newCampaignWizard: newCampaignWizard,
        openCampaign: openCampaign,
        addContactsToCampaign: function (ids, kind) { openCampaignAdd(ids, kind); },
        addRecipientsToCampaign: function (c) { addRecipientsFlow(c); },
        editCampaign: function (c) { openCampaignEdit(c); },
        sendCampaign: sendCampaign,
        campBack: campBack,
        campGenerate: campGenerate,
        campApprove: campApprove,
        campRetry: campRetry,
        campThreads: campThreads,
        campReconcile: campReconcile,
        templates: function () { return campState.templates; },
        init: function (c) {
            cfg = c;
            // Wire toolbar
            var qt = $('moCrmQ');
            qt.addEventListener('input', debounce(function () { state.q = qt.value; state.page = 1; load(); }, 350));
            $('moCrmSearchBtn').onclick = function () { state.q = qt.value; state.page = 1; load(); };
            ['moCrmStatus','moCrmPriority','moCrmOrgType','moCrmEligible'].forEach(function (id) {
                $(id).addEventListener('change', function () {
                    state[id.replace('moCrm', '').toLowerCase()] = $(id).value;
                    state.page = 1; load();
                });
            });
            $('moCrmReviewOnly').addEventListener('change', function () { state.review = this.checked ? 1 : 0; state.page = 1; load(); });
            $('moCrmFlag').addEventListener('change', function () { state.flag = this.value; state.page = 1; load(); });
            $('moCrmBucket').addEventListener('change', function () { state.bucket = this.value; state.page = 1; load(); });
            $('moCrmAdd').onclick = function () { openProspectForm(null); };
            $('moCrmNewOrg').onclick = function () { openOrgForm(null); };
            $('moCrmImportBtn').onclick = openImport;
            $('moCrmExportBtn').onclick = function () { exportCsv(null); };
            $('moBulkPriority').onchange = function () {
                var v = this.value; if (!v) return;
                bulkUpdate({ priority: v }); this.value = '';
            };
            $('moBulkOwner').onchange = function () {
                var v = this.value.trim(); if (!v) return;
                bulkUpdate({ owner_email: v }); this.value = '';
            };
            $('moBulkTags').onchange = function () {
                var v = this.value.trim(); if (!v) return;
                bulkUpdate({ tags_add: v }); this.value = '';
            };
            $('moBulkCampaign').onclick = function () { openCampaignAdd(); };
            // Campaign detail bar (CRM mode).
            if ($('moCampBackCrm')) $('moCampBackCrm').onclick = campBack;
            if ($('moCampGenerateCrm')) $('moCampGenerateCrm').onclick = campGenerate;
            if ($('moCampAddRecipCrm')) $('moCampAddRecipCrm').onclick = function () {
                if (campState.campaign) addRecipientsFlow(campState.campaign);
            };
            if ($('moCampEditCrm')) $('moCampEditCrm').onclick = function () {
                if (campState.campaign) openCampaignEdit(campState.campaign);
            };
            if ($('moCampApproveCrm')) $('moCampApproveCrm').onclick = campApprove;
            if ($('moCampSendCrm')) $('moCampSendCrm').onclick = sendCampaign;
            if ($('moCampRetryCrm')) $('moCampRetryCrm').onclick = campRetry;
            if ($('moCampThreadsCrm')) $('moCampThreadsCrm').onclick = campThreads;
            if ($('moCampReconcileCrm')) $('moCampReconcileCrm').onclick = campReconcile;
            if ($('moCampTplCrm')) $('moCampTplCrm').onchange = function () {
                if (!campState.campaign) return;
                apiPost('campaign_update', { campaign_id: campState.campaign.id, template_id: this.value || null })
                    .then(function (d) { if (!d.success) showErr(d.error || S('loadFailed')); });
            };
            $('moBulkExport').onclick = function () { exportCsv(selectedIds()); };
            $('moBulkClear').onclick = function () { state.selected = {}; updateBulkBar(); load(); };
            refreshOrgs();
            apiGet('templates').then(function (d) { campState.templates = (d && d.templates) || []; });
            load();
        }
    };
}());
