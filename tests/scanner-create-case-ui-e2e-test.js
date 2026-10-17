'use strict';
// Scanner "Create case from scan" UI end-to-end verification (development
// only). Drives the real browser flow: simulate -> Incoming scans inbox ->
// Create case -> prefilled normal create-case modal -> required-field
// validation -> submit through api/create-case.php -> scan links itself to
// the new case via the real inbox API.
//
// Requires: SCANNER_INBOX + SCANNER_SIMULATOR enabled in local .env,
// dev MySQL on 3308, Apache running. Cleans up the scan, the created case,
// and its stored scan objects afterwards.
//
// Run: node tests/scanner-create-case-ui-e2e-test.js
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const ADMIN = process.env.DENTATRAK_TEST_EMAIL || 'e2e_test_browser2@dentatrak.com';
const PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'TestPass123!';

let pass = 0, fail = 0;
function check(cond, msg) {
  if (cond) { pass++; console.log('PASS ' + msg); }
  else { fail++; console.log('FAIL ' + msg); }
}

function dbPhp(code) {
  const tmp = path.join(os.tmpdir(), 'dt-scanui-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.php');
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

(async () => {
  const browser = await chromium.launch(
    process.env.DT_CHROME_EXE ? { executablePath: process.env.DT_CHROME_EXE } : {}
  );
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  let scanId = 0, createdCaseId = '', practiceId = 0;
  try {
    // ---- Login + fixture scan through the real endpoints ------------------
    const loginResp = await context.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: ADMIN, password: PASSWORD },
    });
    assert.equal((await loginResp.json()).success, true, 'login failed');

    const mainHtml = await (await context.request.get(BASE + '/main.php')).text();
    const csrf = (mainHtml.match(/meta name="csrf-token" content="([^"]+)"/) || [])[1];
    assert.ok(csrf, 'csrf token');

    practiceId = Number(dbPhp("$s=$pdo->prepare('SELECT pu.practice_id FROM practice_users pu JOIN users u ON u.id=pu.user_id WHERE u.email=? AND pu.is_owner=1 LIMIT 1');$s->execute(['" + ADMIN + "']);echo $s->fetchColumn() ?: 0;"));
    check(practiceId > 0, 'admin practice resolved (' + practiceId + ')');

    const sim = await context.request.post(BASE + '/api/scanner-simulate.php', {
      headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
      data: { scenario: 'multi_arch' },
    });
    const simJson = await sim.json().catch(() => null);
    scanId = Number((simJson && simJson.ingested && simJson.ingested[0] && simJson.ingested[0].scan_id) || 0);
    check(sim.status() === 200 && scanId > 0, 'multi_arch simulation ingested (scan ' + scanId + ')');

    const casesBefore = Number(dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE practice_id=?');$s->execute([" + practiceId + "]);echo $s->fetchColumn();"));

    // ---- UI: open the inbox, click Create case ----------------------------
    await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#kanbanBoard.loaded', { state: 'attached', timeout: 30000 });
    await page.locator('[data-tab="incoming-scans"]').first().click();
    await page.waitForSelector('.scan-card', { timeout: 15000 });

    const card = page.locator('.scan-card', { hasText: 'sim-scan-5005' }).last();
    check(await card.count() === 1, 'scan card rendered in inbox');
    await card.locator('.scan-action-create').click();
    await page.locator('#confirmModalOk').click();

    // ---- Modal opens with editable defaults from the scan -----------------
    await page.waitForSelector('#createCaseModal', { state: 'visible', timeout: 10000 });
    await page.waitForTimeout(400); // prefill lands ~250ms after modal opens
    check(await page.locator('#patientFirstName').inputValue() === 'Simulation', 'first name prefilled from scan');
    check(await page.locator('#patientLastName').inputValue() === 'PatientFive', 'last name prefilled from scan');
    check(await page.locator('#patientDOB').inputValue() === '1982-11-02', 'DOB prefilled from scan');
    check((await page.locator('#notes').inputValue()).indexOf('SYNTHETIC multi-file scan') !== -1, 'notes prefilled with sanitized instructions');

    // ---- Required-field validation blocks an empty submit ----------------
    await page.locator('#createCaseSubmit').click();
    await page.waitForTimeout(400);
    check(await page.locator('.field-error').count() > 0, 'required-field errors highlighted on empty submit');
    check(await page.locator('#createCaseModal').isVisible(), 'modal stays open after failed validation');
    const casesAfterEmpty = Number(dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE practice_id=?');$s->execute([" + practiceId + "]);echo $s->fetchColumn();"));
    check(casesAfterEmpty === casesBefore, 'no case row created on failed validation');

    // ---- Fill the remaining required fields and submit -------------------
    // Edit one prefilled default to prove the values are editable.
    await page.locator('#patientLastName').fill('PatientFiveEdited');
    await page.locator('#patientGender').selectOption('Female');
    await page.locator('#dentistName').fill('Dr UI E2E');
    await page.locator('#caseType').selectOption({ label: 'Needs Classification' });
    await page.locator('#createCaseSubmit').click();

    // Submit is async; double-click while in flight must not create a second
    // case (guarded by isSubmitting).
    await page.locator('#createCaseSubmit').click().catch(() => {});
    await page.waitForSelector('#createCaseModal', { state: 'hidden', timeout: 30000 });
    await page.waitForTimeout(800);

    // ---- Verify the created case + link ----------------------------------
    const linked = dbPhp("$s=$pdo->prepare('SELECT status, case_id FROM scanner_incoming_scans WHERE id=?');$s->execute([" + scanId + "]);$r=$s->fetch(PDO::FETCH_ASSOC);echo $r['status'].'|'.$r['case_id'];");
    const [scanStatus, linkedCaseId] = linked.split('|');
    createdCaseId = linkedCaseId || '';
    check(scanStatus === 'linked', 'scan transitioned to linked');
    check(!!createdCaseId, 'scan references the created case (' + String(createdCaseId).slice(0, 40) + ')');

    const caseCount = dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE practice_id=? AND case_id=?');$s->execute([" + practiceId + ",'" + createdCaseId + "']);echo $s->fetchColumn();");
    check(caseCount === '1', 'exactly one case row created and persisted');
    const totalAfter = Number(dbPhp("$s=$pdo->prepare('SELECT COUNT(*) FROM cases_cache WHERE practice_id=?');$s->execute([" + practiceId + "]);echo $s->fetchColumn();"));
    check(totalAfter === casesBefore + 1, 'double-submit created no duplicate case (' + casesBefore + '->' + totalAfter + ')');

    const atts = dbPhp("$s=$pdo->prepare('SELECT attachments_json FROM cases_cache WHERE case_id=?');$s->execute(['" + createdCaseId + "']);$a=json_decode((string)$s->fetchColumn(),true) ?: [];$src=array_filter($a,function($x){return ($x['source']??'')==='scanner';});echo count($src).'|'.(count($src)&&($src[array_key_first($src)]['vendorLabel']??'')==='Simulated connector'?'prov-ok':'prov-bad');");
    check(atts === '4|prov-ok', 'case carries four scanner attachments with provenance (' + atts + ')');

    const lastName = dbPhp("require 'api/encryption.php';$s=$pdo->prepare('SELECT patient_last_name FROM cases_cache WHERE case_id=?');$s->execute(['" + createdCaseId + "']);echo PIIEncryption::decrypt((string)$s->fetchColumn());");
    check(lastName === 'PatientFiveEdited', 'edited scan detail persisted to the case (got ' + lastName + ')');

    // ---- The linked card no longer offers create-case --------------------
    const linkedCard = page.locator('.scan-card', { hasText: 'sim-scan-5005' }).last();
    check(await linkedCard.locator('.scan-action-create').count() === 0, 'linked scan no longer offers Create case');
    check((await linkedCard.textContent()).length > 0, 'linked scan card still renders');

    console.log('\n--- ' + pass + ' passed, ' + fail + ' failed ---');
    process.exitCode = fail > 0 ? 1 : 0;
  } catch (e) {
    fail++;
    console.log('FATAL ' + e.message);
    process.exitCode = 1;
  } finally {
    try {
      if (createdCaseId) {
        dbPhp("require 'api/integrations/scanners/ScanFileStore.php';$s=$pdo->prepare('SELECT attachments_json FROM cases_cache WHERE case_id=?');$s->execute(['" + createdCaseId + "']);$atts=json_decode((string)$s->fetchColumn(),true) ?: [];foreach($atts as $a){if(!empty($a['storagePath'])){try{ScanFileStore::delete($a['storageType'] ?? 'local', $a['storagePath']);}catch(Throwable $e){}}}$pdo->prepare('DELETE FROM cases_cache WHERE case_id=?')->execute(['" + createdCaseId + "']);");
      }
      dbPhp("$pdo->exec(\"DELETE s FROM scanner_incoming_scans s WHERE s.vendor='simulated' AND s.practice_id = " + practiceId + "\");");
    } catch (cleanupErr) {
      console.log('CLEANUP WARNING: ' + cleanupErr.message);
    }
    await browser.close();
  }
})();
