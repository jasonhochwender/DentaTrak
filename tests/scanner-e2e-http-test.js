'use strict';
// Scanner foundation HTTP end-to-end verification (development only).
// Exercises the real API surface over HTTP against the local dev stack:
//   simulate -> inbox list -> real create-case.php fixture -> attach ->
//   attachment download paths -> direct-assign and label-based lab access
//   -> interrupted-attach recovery (missing destination bytes -> storage_error
//   -> retry -> recovered) -> instruction-append choice -> unauthorized and
//   other-practice denial -> flag-off behavior for attached vs pending files.
//
// Requires: SCANNER_INBOX + SCANNER_SIMULATOR enabled in local .env,
// dev MySQL on 3308, Apache running. Creates a lab fixture user, an
// assignment label, a real case via the create endpoint, and scans via the
// dev simulator; cleans them all up afterwards.
//
// Run: node tests/scanner-e2e-http-test.js
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const ADMIN = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';
const LAB = 'labscan.e2e@dentatrak.com';
const LAB_PW = 'TestPass123!';

let pass = 0, fail = 0;
function check(cond, msg) {
  if (cond) { pass++; console.log('PASS ' + msg); }
  else { fail++; console.log('FAIL ' + msg); }
}

// Run a PHP snippet against the app's own config (fixture setup/cleanup
// only). Written to a temp file - php -r quoting is unreliable on Windows.
const fs = require('fs');
const os = require('os');
const path = require('path');
function dbPhp(code) {
  const tmp = path.join(os.tmpdir(), 'dt-scan-e2e-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.php');
  fs.writeFileSync(tmp, "<?php\nchdir('" + (__dirname + '/..').replace(/\\/g, '/') + "');\nrequire 'api/appConfig.php';\nglobal $pdo, $appConfig;\n" + code + "\n");
  try {
    return execSync('php "' + tmp + '"', { encoding: 'utf8' }).trim();
  } catch (e) {
    console.log('--- generated PHP (' + tmp + ') ---');
    console.log(fs.readFileSync(tmp, 'utf8'));
    throw e;
  } finally {
    try { fs.unlinkSync(tmp); } catch (e) {}
  }
}

async function login(ctx, email, pw) {
  const r = await ctx.request.post(BASE + '/api/auth-email.php', { data: { action: 'login', email, password: pw } });
  const d = await r.json();
  assert.equal(d.success, true, 'login ' + email + ': ' + JSON.stringify(d));
}

async function csrf(ctx) {
  const html = await (await ctx.request.get(BASE + '/main.php')).text();
  const m = html.match(/meta name="csrf-token" content="([^"]+)"/);
  assert.ok(m, 'csrf token in main.php');
  return m[1];
}

async function api(ctx, token, action, body) {
  const r = await ctx.request.post(BASE + '/api/scan-inbox.php', {
    headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
    data: Object.assign({ action }, body || {}),
  });
  return { status: r.status(), json: await r.json().catch(() => null) };
}

(async () => {
  const browser = await chromium.launch(
    process.env.DT_CHROME_EXE ? { executablePath: process.env.DT_CHROME_EXE } : {}
  );
  const admin = await browser.newContext();
  const lab = await browser.newContext();
  const anon = await browser.newContext();

  let labUserId = 0, fixtureCaseId = null, scanIds = [], practiceId = 0;
  try {
    // ---- Fixtures -------------------------------------------------------
    await login(admin, ADMIN, PASSWORD);
    const adminCsrf = await csrf(admin);
    practiceId = Number(dbPhp("$s=$pdo->prepare('SELECT pu.practice_id FROM practice_users pu JOIN users u ON u.id=pu.user_id WHERE u.email=? AND pu.is_owner=1 LIMIT 1');$s->execute(['" + ADMIN + "']);echo $s->fetchColumn() ?: 0;"));
    check(practiceId > 0, 'admin practice resolved (' + practiceId + ')');

    // Lab collaborator fixture: real user + membership; is_lab set via the
    // app's own PDO (the helper API has no is_lab input).
    const setup = await admin.request.post(BASE + '/api/test-helpers.php', {
      data: { action: 'setup_practice_member', email: LAB, password: LAB_PW, adminEmail: ADMIN, role: 'user', limitedVisibility: true, canEditCases: false },
    });
    check((await setup.json()).success === true, 'lab fixture user created');
    labUserId = Number(dbPhp("$s=$pdo->prepare('SELECT id FROM users WHERE email=?');$s->execute(['" + LAB + "']);echo $s->fetchColumn() ?: 0;"));
    check(labUserId > 0, 'lab fixture user id resolved');
    dbPhp("$pdo->prepare('UPDATE practice_users pu JOIN users u ON u.id=pu.user_id SET pu.is_lab=1, pu.limited_visibility=1 WHERE u.email=? AND pu.practice_id=?')->execute(['" + LAB + "', " + practiceId + "]);");

    // Fixture case through the REAL create-case endpoint (dev storage falls
    // back inside CaseService but persists a canonical cases_cache row).
    // 'Needs Classification' needs no material/tooth fields.
    const created = await admin.request.post(BASE + '/api/create-case.php', {
      headers: { 'X-CSRF-Token': adminCsrf },
      form: {
        patientFirstName: 'ScanE2E', patientLastName: 'Fixture',
        patientDOB: '1990-01-01', patientGender: 'Other',
        dentistName: 'Dr Fixture', caseType: 'Needs Classification',
        notes: 'EXISTING CASE NOTES - must survive',
      },
    });
    const createdJson = await created.json();
    fixtureCaseId = (createdJson.caseData && createdJson.caseData.id) || createdJson.caseId || null;
    check(!!fixtureCaseId, 'fixture case created via create-case API (' + String(fixtureCaseId).slice(0, 40) + ')');
    const casePersisted = dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE case_id=? AND practice_id=?');$s->execute(['" + fixtureCaseId + "', " + practiceId + "]);echo $s->fetchColumn();");
    check(casePersisted === '1', 'created case persisted to cases_cache');

    // ---- Simulate + inbox ------------------------------------------------
    const sim = await admin.request.post(BASE + '/api/scanner-simulate.php', {
      headers: { 'X-CSRF-Token': adminCsrf, 'Content-Type': 'application/json' },
      data: { scenario: 'multi_arch' },
    });
    const simJson = await sim.json();
    check(sim.status() === 200 && simJson.success === true, 'multi_arch simulation ingested over HTTP');
    const scanId = simJson.ingested && simJson.ingested[0] && simJson.ingested[0].scan_id;
    check(scanId > 0, 'scan id returned');
    scanIds.push(scanId);

    const list = await (await admin.request.get(BASE + '/api/scan-inbox.php?action=list')).json();
    const scan = (list.scans || []).find((s) => s.id === scanId);
    check(!!scan, 'scan appears in inbox list');
    check(scan && scan.status === 'ready', 'scan status ready');
    check(scan && scan.files && scan.files.length === 4, 'four files listed');
    const roles = {};
    (scan ? scan.files : []).forEach((f) => { roles[f.fileName] = f.fileRole || null; });
    check(roles['SYNTHETIC-sim-scan-5005-upper.stl'] === 'upper', 'role surfaced: upper');
    check(roles['SYNTHETIC-sim-scan-5005-lower.stl'] === 'lower', 'role surfaced: lower');
    check(roles['SYNTHETIC-sim-scan-5005-bite.ply'] === 'bite', 'role surfaced: bite');
    check(roles['SYNTHETIC-sim-scan-5005-notes.pdf'] === null, 'role-less file has no role');

    // Pending-file download while unattached (admin, flag on).
    const upperFile = scan.files.find((f) => f.fileName.indexOf('upper.stl') !== -1);
    const dl = await admin.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(dl.status() === 200, 'pending file downloads for authorized admin');
    const dlBody = await dl.body();
    check(dlBody.slice(0, 5).toString() === 'solid', 'downloaded bytes are the ASCII STL fixture');

    // ---- Attach ----------------------------------------------------------
    // Notification side-effect guard: snapshot the email queue for this
    // case, then confirm linking adds nothing.
    const queueBefore = Number(dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM notification_email_queue q JOIN user_notifications n ON n.id=q.notification_id WHERE n.case_id=?');$s->execute(['" + fixtureCaseId + "']);echo $s->fetchColumn() ?: 0;"));
    const link = await api(admin, adminCsrf, 'link', { scan_id: scanId, case_id: fixtureCaseId, append_instructions: true });
    check(link.status === 200 && link.json.success === true, 'attach to case over HTTP: ' + JSON.stringify(link.json));
    const linked = await api(admin, adminCsrf, 'link', { scan_id: scanId, case_id: fixtureCaseId });
    check(linked.status === 200 && linked.json.success === true, 'repeated link is idempotent');

    const caseResp = await (await admin.request.get(BASE + '/api/get-case.php?id=' + encodeURIComponent(fixtureCaseId))).json();
    const caseData = caseResp.case || caseResp.data || caseResp;
    const atts = caseData.attachments || [];
    check(atts.length === 4, 'case carries four scan attachments (got ' + atts.length + ')');
    const stlAtt = atts.find((a) => (a.fileName || '').indexOf('upper.stl') !== -1);
    check(!!stlAtt && stlAtt.source === 'scanner', 'attachment provenance: source=scanner');
    check(!!stlAtt && stlAtt.vendorLabel === 'Simulated connector', 'attachment provenance: vendorLabel');
    check(!!stlAtt && !!stlAtt.importedAt, 'attachment provenance: importedAt present');
    check(!!stlAtt && !!stlAtt.scanAt && stlAtt.scanAt !== stlAtt.importedAt, 'scan date stored distinctly from import date');
    check(!!stlAtt && stlAtt.fileRole === 'upper', 'attachment keeps file role');
    const notesPlain = dbPhp("require 'api/integrations/scanners/ScanIngestion.php';$s=$pdo->prepare('SELECT notes FROM cases_cache WHERE case_id=?');$s->execute(['" + fixtureCaseId + "']);echo ScanIngestion::decryptField($s->fetchColumn());");
    check(notesPlain.indexOf('EXISTING CASE NOTES') === 0, 'existing case notes preserved');
    check(notesPlain.indexOf('SYNTHETIC multi-file scan') !== -1, 'opt-in appended imported instructions');
    const queueAfter = Number(dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM notification_email_queue q JOIN user_notifications n ON n.id=q.notification_id WHERE n.case_id=?');$s->execute(['" + fixtureCaseId + "']);echo $s->fetchColumn() ?: 0;"));
    check(queueAfter === queueBefore, 'link queued zero notification emails (' + queueBefore + '->' + queueAfter + ')');

    // ---- Download paths for attached files -------------------------------
    const storagePath = stlAtt.storagePath;
    const signed = await admin.request.post(BASE + '/api/download-signed-url.php', {
      headers: { 'X-CSRF-Token': adminCsrf, 'Content-Type': 'application/json' },
      data: { storage_path: storagePath, filename: stlAtt.fileName },
    });
    const signedJson = await signed.json();
    check(signed.status() === 200 && signedJson.success === true && typeof signedJson.signed_url === 'string', 'download-signed-url returns a URL for the local-mirror object');
    const dl2 = await admin.request.get(BASE + '/' + signedJson.signed_url.replace(/^\//, ''));
    check(dl2.status() === 200, 'attached scan file downloads through the signed-url flow');
    check((await dl2.body()).slice(0, 5).toString() === 'solid', 'attached bytes intact');

    const contentResp = await admin.request.post(BASE + '/api/attachment-content.php', {
      headers: { 'X-CSRF-Token': adminCsrf, 'Content-Type': 'application/json' },
      data: { storage_path: storagePath },
    });
    check(contentResp.status() === 200 && (await contentResp.body()).slice(0, 5).toString() === 'solid', 'attachment-content viewer path streams the file');

    // ---- Authorization matrix --------------------------------------------
    await login(lab, LAB, LAB_PW);
    const labCsrf = await csrf(lab);
    // Login does not bind a practice; pick the fixture practice explicitly.
    const switchResp = await lab.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': labCsrf, 'Content-Type': 'application/json' },
      data: { practice_id: practiceId },
    });
    const switchJson = await switchResp.json().catch(() => null);
    check(switchResp.status() === 200 && switchJson && switchJson.success === true, 'lab session bound to fixture practice (' + switchResp.status() + ')');
    const labFileDl = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labFileDl.status() === 403 || labFileDl.status() === 404, 'lab collaborator denied before case assignment (' + labFileDl.status() + ')');
    const labInbox = await lab.request.get(BASE + '/api/scan-inbox.php?action=list');
    check(labInbox.status() === 403, 'lab collaborator cannot use the inbox API (' + labInbox.status() + ')');

    // Assign the case to the lab user's email -> attached files download.
    dbPhp("$pdo->prepare('UPDATE cases_cache SET assigned_to=? WHERE case_id=?')->execute(['" + LAB + "','" + fixtureCaseId + "']);");
    const labFileDl2 = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labFileDl2.status() === 200, 'assigned lab collaborator downloads the attached scan file');
    const labSigned = await lab.request.post(BASE + '/api/download-signed-url.php', {
      headers: { 'X-CSRF-Token': labCsrf, 'Content-Type': 'application/json' },
      data: { storage_path: storagePath, filename: stlAtt.fileName },
    });
    check(labSigned.status() === 200 && (await labSigned.json()).success === true, 'assigned lab collaborator gets a download URL');

    // Unauthenticated request -> 401.
    const anonDl = await anon.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(anonDl.status() === 401, 'unauthenticated download denied');

    // Unassigned lab -> denied again (unassign the case).
    dbPhp("$pdo->prepare('UPDATE cases_cache SET assigned_to=NULL WHERE case_id=?')->execute(['" + fixtureCaseId + "']);");
    const labFileDl3 = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labFileDl3.status() === 403 || labFileDl3.status() === 404, 'unassigned lab collaborator denied (' + labFileDl3.status() + ')');

    // ---- Label-based lab access ------------------------------------------
    // The existing case-sharing path also grants access when the case is
    // assigned to a label the limited-visibility user is mapped to.
    const labelResp = await admin.request.post(BASE + '/api/test-helpers.php', {
      data: { action: 'ensure_assignment_label', label: 'ScanE2E Lab' },
    });
    const labelJson = await labelResp.json();
    const labelId = labelJson.label_id || 0;
    check(labelId > 0, 'assignment label fixture created');
    // Map the lab user to the label (the helper's labelIds path is part of
    // setup_practice_member; direct insert keeps this focused).
    dbPhp("$pdo->prepare('INSERT IGNORE INTO practice_assignment_label_recipients (practice_id, label_id, user_id) VALUES (?,?,?)')->execute([" + practiceId + ", " + labelId + ", " + labUserId + "]);");
    dbPhp("$pdo->prepare('UPDATE cases_cache SET assigned_to=? WHERE case_id=?')->execute(['ScanE2E Lab','" + fixtureCaseId + "']);");
    const labLabelDl = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labLabelDl.status() === 200, 'label-mapped lab collaborator downloads attached file');
    // Revoke: remove the recipient mapping -> access denied.
    dbPhp("$pdo->prepare('DELETE FROM practice_assignment_label_recipients WHERE practice_id=? AND label_id=? AND user_id=?')->execute([" + practiceId + ", " + labelId + ", " + labUserId + "]);");
    const labLabelDl2 = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labLabelDl2.status() === 403 || labLabelDl2.status() === 404, 'revoked label mapping denies access (' + labLabelDl2.status() + ')');
    // Label without mapping is also denied (label assigned, user unmapped).
    const labLabelDl3 = await lab.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
    check(labLabelDl3.status() === 403 || labLabelDl3.status() === 404, 'unmapped lab denied despite label assignment');
    dbPhp("$pdo->prepare('UPDATE cases_cache SET assigned_to=NULL WHERE case_id=?')->execute(['" + fixtureCaseId + "']);");

    // ---- Interrupted-attach recovery over HTTP -----------------------------
    // A second scan whose rows are already claimed by this case but whose
    // destination bytes do not exist must fail recoverably (storage_error),
    // record no phantom attachment, stay linkable, and complete after Retry
    // re-downloads the objects.
    const sim2 = await admin.request.post(BASE + '/api/scanner-simulate.php', {
      headers: { 'X-CSRF-Token': adminCsrf, 'Content-Type': 'application/json' },
      data: { scenario: 'success' },
    });
    const sim2Json = await sim2.json().catch(() => null);
    const scan2Id = Number((sim2Json && sim2Json.ingested && sim2Json.ingested[0] && sim2Json.ingested[0].scan_id) || 0);
    check(scan2Id > 0, 'second scan ingested for recovery test (' + scan2Id + ')');
    scanIds.push(scan2Id);

    // Notes snapshot: the second attach uses the default (no append), which
    // must leave the case instructions untouched.
    const notesBefore = dbPhp("require 'api/integrations/scanners/ScanIngestion.php';$s=$pdo->prepare('SELECT notes FROM cases_cache WHERE case_id=?');$s->execute(['" + fixtureCaseId + "']);echo ScanIngestion::decryptField($s->fetchColumn());");

    // Claim every file of scan2 to the fixture case with a storage_path that
    // points at a case-key object that was never written.
    dbPhp("$pdo->prepare(\"UPDATE scanner_scan_files SET attached_case_id=?, attached_at=NOW(), storage_driver='local', storage_path=CONCAT('cases/" + practiceId + "/" + fixtureCaseId + "/intraoralScans/missing-',external_file_id,'.stl') WHERE scan_id=?\")->execute(['" + fixtureCaseId + "', " + scan2Id + "]);");

    const linkMissing = await api(admin, adminCsrf, 'link', { scan_id: scan2Id, case_id: fixtureCaseId });
    check(linkMissing.status === 500 && linkMissing.json && linkMissing.json.error_code === 'storage_error', 'missing destination bytes -> recoverable storage_error (' + linkMissing.status + ')');
    const scan2State = dbPhp("$s=$pdo->prepare('SELECT status, case_id FROM scanner_incoming_scans WHERE id=?');$s->execute([" + scan2Id + "]);$r=$s->fetch(PDO::FETCH_ASSOC);echo $r['status'].'|'.$r['case_id'];");
    check(scan2State === 'ready|', 'scan stays ready/unlinked after storage failure (' + scan2State + ')');
    const pendingRows = Number(dbPhp("$s=$pdo->prepare(\"SELECT COUNT(*) FROM scanner_scan_files WHERE scan_id=? AND status='pending' AND attached_case_id IS NULL\");$s->execute([" + scan2Id + "]);echo $s->fetchColumn();"));
    check(pendingRows > 0, 'missing-bytes files reset to pending for retry (' + pendingRows + ')');
    const noPhantom = Number(dbPhp("$s=$pdo->prepare('SELECT attachments_json FROM cases_cache WHERE case_id=?');$s->execute(['" + fixtureCaseId + "']);$a=json_decode((string)$s->fetchColumn(),true) ?: [];echo count(array_filter($a,function($x){return strpos((string)($x['storagePath']??''),'missing-')!==false;}));"));
    check(noPhantom === 0, 'no phantom attachment metadata for missing bytes');

    // Retry re-downloads the reset files, then a fresh link completes.
    const retryResp = await api(admin, adminCsrf, 'retry', { scan_id: scan2Id });
    check(retryResp.status === 200 && retryResp.json.success === true, 'retry action re-downloads reset files');
    const readyRows = Number(dbPhp("$s=$pdo->prepare(\"SELECT COUNT(*) FROM scanner_scan_files WHERE scan_id=? AND status='ready' AND attached_case_id IS NULL\");$s->execute([" + scan2Id + "]);echo $s->fetchColumn();"));
    check(readyRows === pendingRows, 'retry restored all reset files to ready (' + readyRows + ')');
    const linkRecovered = await api(admin, adminCsrf, 'link', { scan_id: scan2Id, case_id: fixtureCaseId });
    check(linkRecovered.status === 200 && linkRecovered.json.success === true, 'link succeeds after byte recovery');
    const scan2Linked = dbPhp("$s=$pdo->prepare('SELECT status, case_id FROM scanner_incoming_scans WHERE id=?');$s->execute([" + scan2Id + "]);$r=$s->fetch(PDO::FETCH_ASSOC);echo $r['status'];");
    check(scan2Linked === 'linked', 'scan2 linked after recovery');

    // Default link (append_instructions unset) must not touch the notes.
    const notesAfter = dbPhp("require 'api/integrations/scanners/ScanIngestion.php';$s=$pdo->prepare('SELECT notes FROM cases_cache WHERE case_id=?');$s->execute(['" + fixtureCaseId + "']);echo ScanIngestion::decryptField($s->fetchColumn());");
    check(notesAfter === notesBefore, 'no instruction import without explicit append_instructions');

    // Other practice's file id: fabricate a scan in a DIFFERENT practice
    // directly (DB fixture - not via the API, which stays practice-scoped).
    const foreignPractice = Number(dbPhp("echo (int)$pdo->query('SELECT id FROM practices WHERE id <> " + practiceId + " ORDER BY id LIMIT 1')->fetchColumn();"));
    const foreignFileId = foreignPractice > 0 ? Number(dbPhp("require_once 'api/integrations/scanners/ScanIngestion.php';require_once 'api/integrations/scanners/ScanFileStore.php';$p=$pdo->prepare(\"INSERT INTO scanner_incoming_scans (practice_id,vendor,external_scan_id,status,dedup_key) VALUES (" + foreignPractice + ",'simulated','e2e-foreign','ready',?)\");$p->execute([hash('sha256','e2e-foreign-'.microtime())]);$sid=$pdo->lastInsertId();$pdo->prepare(\"INSERT INTO scanner_scan_files (scan_id,external_file_id,file_name,status,storage_driver,storage_path,declared_size) VALUES (?,?,?,'ready','local','cases/" + foreignPractice + "/x/intraoralScans/f.stl',10)\")->execute([$sid,'f1','f.stl']);echo $pdo->lastInsertId();")) : 0;
    if (foreignFileId > 0) {
      const crossDl = await admin.request.get(BASE + '/api/scan-file.php?file_id=' + foreignFileId + '&download=1');
      check(crossDl.status() === 404, 'other-practice file id returns 404');
      const crossAttach = await api(admin, adminCsrf, 'link', { scan_id: 0, case_id: fixtureCaseId });
      check(crossAttach.status === 400, 'scan_id 0 rejected');
    }

    // ---- Flag-off boundary ------------------------------------------------
    // Flip SCANNER_INBOX off at runtime via env for the running PHP process.
    // apache reads .env per request, so edit then restore.
    const fs = require('fs');
    const envPath = require('path').join(__dirname, '..', '.env');
    const envOrig = fs.readFileSync(envPath, 'utf8');
    fs.writeFileSync(envPath, envOrig.replace('SCANNER_INBOX=true', 'SCANNER_INBOX=false'));
    try {
      const inboxOff = await admin.request.get(BASE + '/api/scan-inbox.php?action=list');
      check(inboxOff.status() === 404, 'inbox API 404s when flag off');
      // Attached file remains downloadable: it is a normal case attachment.
      const attachedOff = await admin.request.get(BASE + '/api/scan-file.php?file_id=' + upperFile.id + '&download=1');
      check(attachedOff.status() === 200, 'attached file still downloads with flag off (case ACL governs)');
      // A fresh unattached file is unreachable.
      const pendingOff = Number(dbPhp("$s=$pdo->prepare(\"SELECT f.id FROM scanner_scan_files f JOIN scanner_incoming_scans s ON s.id=f.scan_id WHERE s.practice_id=" + practiceId + " AND f.attached_case_id IS NULL AND f.status='ready' LIMIT 1\");$s->execute();echo $s->fetchColumn() ?: 0;"));
      if (pendingOff > 0) {
        const pendingResp = await admin.request.get(BASE + '/api/scan-file.php?file_id=' + pendingOff + '&download=1');
        check(pendingResp.status() === 404, 'unattached inbox file 404s when flag off');
      }
      // The simulator gates on SCANNER_SIMULATOR (separate flag), so it stays
      // reachable here - but SCANNER_INBOX off must not expose inbox actions.
    } finally {
      fs.writeFileSync(envPath, envOrig.replace('SCANNER_INBOX=false', 'SCANNER_INBOX=true'));
    }
    // Simulator blocked when its own flag is off.
    fs.writeFileSync(envPath, envOrig.replace('SCANNER_SIMULATOR=true', 'SCANNER_SIMULATOR=false'));
    try {
      const simOff = await admin.request.post(BASE + '/api/scanner-simulate.php', {
        headers: { 'X-CSRF-Token': adminCsrf, 'Content-Type': 'application/json' },
        data: { scenario: 'empty' },
      });
      check(simOff.status() === 404, 'simulator endpoint 404s when SCANNER_SIMULATOR off');
    } finally {
      fs.writeFileSync(envPath, envOrig);
    }

    console.log('\n--- ' + pass + ' passed, ' + fail + ' failed ---');
    process.exitCode = fail > 0 ? 1 : 0;
  } catch (e) {
    fail++;
    console.log('FATAL ' + e.message);
    process.exitCode = 1;
  } finally {
    // Cleanup: fixture case, fixture scans (cascade files/events), lab user
    // membership, and the foreign-practice scan.
    try {
      if (fixtureCaseId) {
        dbPhp("require 'api/integrations/scanners/ScanFileStore.php';$s=$pdo->prepare('SELECT attachments_json FROM cases_cache WHERE case_id=?');$s->execute(['" + fixtureCaseId + "']);$atts=json_decode((string)$s->fetchColumn(),true) ?: [];foreach($atts as $a){if(!empty($a['storagePath']) && strpos($a['storagePath'],'cases/" + practiceId + "/')===0){try{ScanFileStore::delete($a['storageType'] ?? 'local', $a['storagePath']);}catch(Throwable $e){}}}$pdo->prepare('DELETE FROM cases_cache WHERE case_id=?')->execute(['" + fixtureCaseId + "']);");
      }
      dbPhp("$pdo->exec(\"DELETE s FROM scanner_incoming_scans s WHERE s.vendor='simulated' AND (s.practice_id = " + practiceId + " OR s.external_scan_id = 'e2e-foreign')\");");
      dbPhp("$pdo->prepare('DELETE pu FROM practice_users pu JOIN users u ON u.id=pu.user_id WHERE u.email=?')->execute(['" + LAB + "']);$pdo->prepare('DELETE FROM users WHERE email=?')->execute(['" + LAB + "']);");
      dbPhp("$s=$pdo->prepare('SELECT id FROM practice_assignment_labels WHERE practice_id=? AND label=?');$s->execute([" + practiceId + ",'ScanE2E Lab']);$lid=$s->fetchColumn();if($lid){$pdo->prepare('DELETE FROM practice_assignment_label_recipients WHERE label_id=?')->execute([$lid]);$pdo->prepare('DELETE FROM practice_assignment_labels WHERE id=?')->execute([$lid]);}");
    } catch (cleanupErr) {
      console.log('CLEANUP WARNING: ' + cleanupErr.message);
    }
    await browser.close();
  }
})();
