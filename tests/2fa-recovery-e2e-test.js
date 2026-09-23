'use strict';
/**
 * Lost-authenticator / 2FA recovery e2e test.
 *
 * Fixtures:
 *   owner  - owns enforcing practice A, 2FA enrolled (admin actor)
 *   member - member of practice A (also owns optional practice B), 2FA enrolled
 *   solo   - owns optional practice C, 2FA enrolled (self-service target)
 *   plain  - owns optional practice D, NO 2FA (negative target)
 *
 * Covers: anti-enumeration, token lifecycle (issue/expiry/reuse/malformed),
 * password re-verification, old-TOTP invalidation, remember-me revocation,
 * pending-session recovery (email field ignored), required-practice
 * re-enrollment routing, admin initiation + authorization, CSRF, email hygiene.
 *
 * Run: node tests/2fa-recovery-e2e-test.js
 */
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { chromium } = require('playwright');
const { randomUUID } = require('node:crypto');

const BASE = 'http://localhost/DentaTrak';
const suffix = randomUUID().slice(0, 8);
const ownerEmail = `e2e-rec-owner-${suffix}@example.test`;
const memberEmail = `e2e-rec-member-${suffix}@example.test`;
const soloEmail = `e2e-rec-solo-${suffix}@example.test`;
const plainEmail = `e2e-rec-plain-${suffix}@example.test`;
const password = 'LocalTest-' + randomUUID() + '!';
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; console.log('PASS ' + name); }

function base32Decode(s) {
  const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = 0, val = 0; const out = [];
  for (const c of s.replace(/=+$/g, '').toUpperCase()) {
    val = (val << 5) | A.indexOf(c); bits += 5;
    if (bits >= 8) { out.push((val >> (bits - 8)) & 0xff); bits -= 8; }
  }
  return Buffer.from(out);
}
function totpCode(secret, stepOffset = 0) {
  const key = base32Decode(secret);
  const counter = Math.floor(Date.now() / 1000 / 30) + stepOffset;
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(counter));
  const hmac = crypto.createHmac('sha1', key).update(buf).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000;
  return String(code).padStart(6, '0');
}
function extractCsrf(html) {
  const m = html.match(/name="csrf-token" content="([^"]+)"/) || html.match(/name="csrf_token" value="([^"]+)"/);
  return m ? m[1] : null;
}
function tokenFromEmail(email) {
  if (!email) return null;
  const m = JSON.stringify(email).match(/2fa-reset\.php\?token=([a-f0-9]{64})/);
  return m ? m[1] : null;
}

(async () => {
  const browser = await chromium.launch();
  const owner = await browser.newContext();
  const member = await browser.newContext();
  const solo = await browser.newContext();
  let created = false;

  async function helper(data) {
    const r = await owner.request.post(BASE + '/api/test-helpers.php', { data });
    const d = await r.json();
    assert.equal(d.success, true, JSON.stringify(d));
    return d;
  }
  async function login(ctx, email, extra = {}) {
    const r = await ctx.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email, password, ...extra }
    });
    return r.json();
  }
  async function acceptTerms(ctx) {
    const page = await ctx.request.get(BASE + '/accept-terms.php', { maxRedirects: 10 });
    const html = await page.text();
    const token = extractCsrf(html);
    const vm = html.match(/name="terms-version" content="([^"]+)"/);
    if (!token || !vm) return;
    await ctx.request.post(BASE + '/api/accept-terms.php', {
      headers: { 'X-CSRF-Token': token },
      data: { accepted: true, terms_version: vm[1] }
    });
  }
  async function csrfFor(ctx, url = BASE + '/main.php') {
    const r = await ctx.request.get(url, { maxRedirects: 10 });
    const html = await r.text();
    const token = extractCsrf(html);
    assert.ok(token, 'csrf token from ' + url);
    return token;
  }
  async function enroll2FA(ctx, pageUrl) {
    const token = await csrfFor(ctx, pageUrl);
    const setup = await ctx.request.post(BASE + '/api/2fa-setup.php?action=setup', {
      headers: { 'X-CSRF-Token': token }
    });
    const setupData = await setup.json();
    assert.equal(setupData.success, true, JSON.stringify(setupData));
    const verify = await ctx.request.post(BASE + '/api/2fa-setup.php?action=verify', {
      headers: { 'X-CSRF-Token': token },
      data: { code: totpCode(setupData.secret) }
    });
    const verifyData = await verify.json();
    assert.equal(verifyData.success, true, JSON.stringify(verifyData));
    return setupData.secret;
  }
  async function lastEmail() {
    const r = await owner.request.post(BASE + '/api/test-helpers.php', { data: { action: 'get_last_app_email' } });
    if (r.status() === 404) return null;
    const d = await r.json();
    return d.email || null;
  }
  async function requestRecovery(ctx, csrf, email) {
    const r = await ctx.request.post(BASE + '/api/2fa-recovery.php', {
      headers: { 'X-CSRF-Token': csrf },
      data: { action: 'request', email }
    });
    return r.json();
  }
  async function completeRecovery(ctx, csrf, token, pw) {
    const r = await ctx.request.post(BASE + '/api/2fa-recovery.php', {
      headers: { 'X-CSRF-Token': csrf },
      data: { action: 'complete', token, password: pw }
    });
    return { status: r.status(), data: await r.json() };
  }
  async function recoveryPageCtx() {
    const ctx = await browser.newContext();
    const csrf = await csrfFor(ctx, BASE + '/2fa-recovery.php');
    return { ctx, csrf };
  }

  try {
    // ---- Fixtures ----
    const a = await helper({ action: 'setup_test_user', email: ownerEmail, password });
    const b = await helper({ action: 'setup_test_user', email: memberEmail, password });
    const s = await helper({ action: 'setup_test_user', email: soloEmail, password });
    const p = await helper({ action: 'setup_test_user', email: plainEmail, password });
    created = true;
    const practiceA = Number(a.practice_id);
    const memberId = Number(b.user_id);
    // Add member to practice A (member keeps own optional practice too).
    await helper({ action: 'setup_practice_member', practiceId: practiceA, email: memberEmail, password, role: 'user' });

    await login(owner, ownerEmail);
    await acceptTerms(owner);
    await enroll2FA(owner, BASE + '/main.php');

    await login(member, memberEmail);
    await acceptTerms(member);
    const memberSecret = await enroll2FA(member, BASE + '/main.php');
    // Move member into enforcing practice A as their active practice.
    const memberSwCsrf = await csrfFor(member, BASE + '/practice-setup.php');

    await login(solo, soloEmail);
    await acceptTerms(solo);
    const soloSecret = await enroll2FA(solo, BASE + '/main.php');

    // Turn practice A enforcement ON (owner has 2FA so actor check passes).
    const ownerCsrf = await csrfFor(owner);
    const en = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': ownerCsrf },
      data: { enabled: true }
    });
    assert.equal((await en.json()).success, true, 'enable practice 2FA');
    const swA = await member.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': memberSwCsrf },
      data: { practice_id: practiceA }
    });
    check('verified member can enter enforcing practice', (await swA.json()).success === true);

    // ---- CSRF ----
    const csrfProbe = await browser.newContext();
    const noCsrf = await csrfProbe.request.post(BASE + '/api/2fa-recovery.php', {
      data: { action: 'request', email: soloEmail }
    });
    check('request without CSRF is 403', noCsrf.status() === 403);
    const noCsrfComplete = await csrfProbe.request.post(BASE + '/api/2fa-recovery.php', {
      data: { action: 'complete', token: 'a'.repeat(64), password: 'x' }
    });
    check('complete without CSRF is 403', noCsrfComplete.status() === 403);
    const getReq = await csrfProbe.request.get(BASE + '/api/2fa-recovery.php?action=request');
    check('GET request is 405', getReq.status() === 405);
    await csrfProbe.close();

    // ---- Anti-enumeration (fresh context per request: session rate limit is 1/min) ----
    const { ctx: anon1, csrf: anon1Csrf } = await recoveryPageCtx();
    await helper({ action: 'clear_test_email_log' });
    const unknown = await requestRecovery(anon1, anon1Csrf, `nobody-${suffix}@example.test`);
    check('unknown email returns neutral success', unknown.success === true && /eligible account/i.test(unknown.message));
    check('unknown email sends nothing', (await lastEmail()) === null);
    await anon1.close();

    const { ctx: anon2, csrf: anon2Csrf } = await recoveryPageCtx();
    const noTwoFa = await requestRecovery(anon2, anon2Csrf, plainEmail);
    check('non-2FA account returns identical neutral response', noTwoFa.success === true && noTwoFa.message === unknown.message);
    check('non-2FA account sends nothing', (await lastEmail()) === null);
    await anon2.close();

    // ---- Self-service request for a real 2FA user ----
    const { ctx: anon3, csrf: anon3Csrf } = await recoveryPageCtx();
    const req1 = await requestRecovery(anon3, anon3Csrf, soloEmail);
    check('2FA user request returns neutral success', req1.success === true && req1.message === unknown.message);
    const mail1 = await lastEmail();
    const soloToken = tokenFromEmail(mail1);
    check('recovery email recorded with reset link', !!mail1 && !!soloToken);
    check('email has no TOTP secret material', !/otpauth|totp_secret|BEGIN SECRET/i.test(JSON.stringify(mail1)));
    check('email states expiry + no-action-needed', /expire/i.test(JSON.stringify(mail1)) && /didn't request|ignore/i.test(JSON.stringify(mail1)));

    // ---- Token lifecycle: malformed -> wrong password -> valid -> replay ----
    const resetCsrf = await csrfFor(anon3, BASE + '/2fa-reset.php?token=' + soloToken);
    const malformed = await completeRecovery(anon3, resetCsrf, 'not-a-token', 'x');
    check('malformed token rejected', malformed.status === 400 && malformed.data.success === false);

    const wrongPw = await completeRecovery(anon3, resetCsrf, soloToken, 'WrongPass-123!');
    check('wrong password rejected 401', wrongPw.status === 401 && wrongPw.data.success === false);
    const st1 = (await helper({ action: 'get_2fa_reset_token_state', email: soloEmail })).token;
    check('token not consumed by wrong password', st1 && Number(st1.used) === 0);

    // Give solo a remember-me cookie so revocation is provable: a 2FA login
    // completes in one call when the TOTP code rides with rememberMe.
    const soloRm = await browser.newContext();
    const rmLogin = await soloRm.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: soloEmail, password, rememberMe: true, totpCode: totpCode(soloSecret) }
    });
    check('2FA login with remember-me succeeds', (await rmLogin.json()).success === true);
    const rmCookie = (await soloRm.cookies(BASE)).find(c => c.name === 'remember_token');
    assert.ok(rmCookie, 'remember_token cookie issued');
    await soloRm.close();

    const done = await completeRecovery(anon3, resetCsrf, soloToken, password);
    check('valid token + password succeeds', done.status === 200 && done.data.success === true);
    check('optional-practice user not forced to re-enroll', done.data.requires_reenrollment === false);

    // The old remember-me cookie must restore NOTHING after the reset -
    // solo now has no 2FA, so a surviving cookie would be a full bypass.
    const stolen = await browser.newContext();
    await stolen.addCookies([{ name: 'remember_token', value: rmCookie.value, url: BASE }]);
    await stolen.request.get(BASE + '/login.php', { maxRedirects: 10 });
    const stolenApi = await stolen.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    check('revoked remember-me cookie restores no session', stolenApi.status() === 401);
    await stolen.close();

    // Cookies issued AFTER the reset still work - revocation is a
    // watermark, not a global kill of the feature. The watermark is
    // second-precision: wait past the second boundary so this cookie
    // provably post-dates it.
    await new Promise(r => setTimeout(r, 1100));
    const rm2 = await browser.newContext();
    const rm2Login = await rm2.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: soloEmail, password, rememberMe: true }
    });
    check('post-reset login succeeds without 2FA', (await rm2Login.json()).success === true);
    const rm2Cookie = (await rm2.cookies(BASE)).find(c => c.name === 'remember_token');
    const fresh = await browser.newContext();
    await fresh.addCookies([{ name: 'remember_token', value: rm2Cookie.value, url: BASE }]);
    await fresh.request.get(BASE + '/login.php', { maxRedirects: 10 });
    const freshApi = await fresh.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    check('post-reset remember-me cookie restores session', freshApi.status() !== 401);
    await rm2.close();
    await fresh.close();
    const st2full = await helper({ action: 'get_2fa_reset_token_state', email: soloEmail });
    const st2 = st2full.token;
    check('token consumed after success', Number(st2.used) === 1);
    check('remember-me watermark stamped at reset', !!st2full.remember_me_revoked_after);

    const replay = await completeRecovery(anon3, resetCsrf, soloToken, password);
    check('reused token rejected', replay.status === 400 && replay.data.success === false);

    const loginAfter = await login(await browser.newContext(), soloEmail);
    check('2FA no longer required after reset', loginAfter.success === true && !loginAfter.requires_2fa);

    const notice = await lastEmail();
    check('security notification email sent', !!notice && /two-factor/i.test(JSON.stringify(notice)) && !tokenFromEmail(notice));

    // ---- Expired token ----
    // Solo re-enrolls (optional practice allows it), then requests again.
    await enroll2FA(solo, BASE + '/main.php');
    const { ctx: anon4, csrf: anon4Csrf } = await recoveryPageCtx();
    await requestRecovery(anon4, anon4Csrf, soloEmail);
    const soloToken2 = tokenFromEmail(await lastEmail());
    check('second request issues a fresh token', !!soloToken2 && soloToken2 !== soloToken);
    await helper({ action: 'expire_2fa_reset_tokens', email: soloEmail });
    const expired = await completeRecovery(anon4, await csrfFor(anon4, BASE + '/2fa-reset.php?token=' + soloToken2), soloToken2, password);
    check('expired token rejected', expired.status === 400 && expired.data.success === false);
    await anon4.close();

    // ---- Partial-failure rollback (fault injection) ----
    // If a required DB write fails AFTER the token claim, the whole reset
    // must roll back: token stays active AND 2FA stays enabled.
    const { ctx: anon5, csrf: anon5Csrf } = await recoveryPageCtx();
    await requestRecovery(anon5, anon5Csrf, soloEmail);
    const soloToken3 = tokenFromEmail(await lastEmail());
    assert.ok(soloToken3, 'token for fault-injection run');
    const failCsrf = await csrfFor(anon5, BASE + '/2fa-reset.php?token=' + soloToken3);
    await helper({ action: 'force_2fa_reset_failure' });
    const failed = await completeRecovery(anon5, failCsrf, soloToken3, password);
    check('injected failure returns 500', failed.status === 500 && failed.data.success === false);
    const stFail = (await helper({ action: 'get_2fa_reset_token_state', email: soloEmail })).token;
    check('failed reset rolls back token claim', stFail && Number(stFail.used) === 0);
    const loginStill2fa = await login(await browser.newContext(), soloEmail);
    check('failed reset leaves 2FA enabled', loginStill2fa.requires_2fa === true);
    await helper({ action: 'clear_2fa_reset_failure' });
    const retry = await completeRecovery(anon5, failCsrf, soloToken3, password);
    check('same link retries successfully after rollback', retry.data.success === true);
    await anon5.close();

    // ---- Pending-session recovery: the email field is ignored ----
    const pendingCtx = await browser.newContext();
    const pendLogin = await pendingCtx.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: memberEmail, password }
    });
    check('member login held at pending 2FA', (await pendLogin.json()).requires_2fa === true);
    await helper({ action: 'clear_test_email_log' });
    const pendCsrf = await csrfFor(pendingCtx, BASE + '/2fa-recovery.php');
    const pendReq = await pendingCtx.request.post(BASE + '/api/2fa-recovery.php', {
      headers: { 'X-CSRF-Token': pendCsrf },
      data: { action: 'request', email: `attacker-${suffix}@example.test` }
    });
    const pendReqData = await pendReq.json();
    check('pending session request returns neutral success', pendReqData.success === true);
    const pendMail = await lastEmail();
    const memberToken = tokenFromEmail(pendMail);
    check('pending-session request sent the recovery link', !!memberToken);
    // The link must belong to the member (the pending account), not the
    // attacker-supplied email - prove the token state exists for member.
    const memberTokState = (await helper({ action: 'get_2fa_reset_token_state', email: memberEmail })).token;
    check('token issued for pending account only', !!memberTokState && Number(memberTokState.used) === 0);
    await pendingCtx.close();

    // ---- Admin-initiated recovery ----
    const adminCsrf = await csrfFor(owner);
    const memberSend = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=send_member_recovery', {
      headers: { 'X-CSRF-Token': adminCsrf },
      data: { member_id: memberId }
    });
    check('owner can send member recovery', (await memberSend.json()).success === true);

    const adminTokState = (await helper({ action: 'get_2fa_reset_token_state', email: memberEmail })).token;
    check('admin request records initiator', adminTokState && Number(adminTokState.requested_by_user_id) > 0);

    const statusAfter = await owner.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    const memberRow = ((await statusAfter.json()).members || []).find(m => Number(m.id) === memberId);
    check('admin action does not disable member 2FA', memberRow && memberRow.totp_enabled === true);

    // Member is active in practice A as a plain member -> admin gate denies.
    const memberDenied = await member.request.post(BASE + '/api/practice-2fa-policy.php?action=send_member_recovery', {
      headers: { 'X-CSRF-Token': await csrfFor(member) },
      data: { member_id: memberId }
    });
    check('normal member cannot send recovery', memberDenied.status() === 403);

    // Cross-practice: owner cannot target solo (not a member of practice A).
    const soloId = Number(s.user_id);
    const crossSend = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=send_member_recovery', {
      headers: { 'X-CSRF-Token': adminCsrf },
      data: { member_id: soloId }
    });
    check('cross-practice targeting denied', crossSend.status() === 404);

    const mail3 = await lastEmail();
    const memberToken2 = tokenFromEmail(mail3);
    check('admin-initiated email carries reset link', !!memberToken2);

    // Member completes admin-initiated recovery with mailbox token + own password.
    const memberAnon = await browser.newContext();
    const memberResetCsrf = await csrfFor(memberAnon, BASE + '/2fa-reset.php?token=' + memberToken2);
    const memberDone = await completeRecovery(memberAnon, memberResetCsrf, memberToken2, password);
    check('member completes admin-initiated recovery', memberDone.data.success === true);
    check('enforcing-practice member flagged for re-enrollment', memberDone.data.requires_reenrollment === true);
    await memberAnon.close();

    // ---- Required-practice behavior after reset (fresh session - the
    // pre-existing member session is unaffected by design; other live
    // sessions are not keyed by user and cannot be revoked today) ----
    const member2 = await browser.newContext();
    const relogin = await login(member2, memberEmail);
    check('member signs in after reset (no 2FA left)', relogin.success === true && !relogin.requires_2fa);
    const member2Csrf = await csrfFor(member2, BASE + '/practice-setup.php');
    const swBlocked = await member2.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': member2Csrf },
      data: { practice_id: practiceA }
    });
    const swBlockedData = await swBlocked.json();
    check('enforcing practice blocks unenrolled member', swBlockedData.success !== true
      && /2fa|two-factor/i.test(JSON.stringify(swBlockedData)));

    // Fresh enrollment restores access.
    const enrollCsrf = await csrfFor(member2, BASE + '/2fa-required.php');
    const enroll2 = await member2.request.post(BASE + '/api/2fa-setup.php?action=setup', {
      headers: { 'X-CSRF-Token': enrollCsrf }
    });
    const enroll2Data = await enroll2.json();
    assert.equal(enroll2Data.success, true, 're-enrollment setup');
    const verify2 = await member2.request.post(BASE + '/api/2fa-setup.php?action=verify', {
      headers: { 'X-CSRF-Token': enrollCsrf },
      data: { code: totpCode(enroll2Data.secret) }
    });
    assert.equal((await verify2.json()).success, true, 're-enrollment verify');
    const swAfter = await member2.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': enrollCsrf },
      data: { practice_id: practiceA }
    });
    check('re-enrollment restores practice access', (await swAfter.json()).success === true);

    // Old TOTP secret is gone: a code from the pre-reset secret must not verify.
    const oldCodeStillWorks = await member2.request.post(BASE + '/api/2fa-challenge.php', {
      headers: { 'X-CSRF-Token': enrollCsrf },
      data: { code: totpCode(memberSecret) }
    });
    const oldCodeData = await oldCodeStillWorks.json().catch(() => ({}));
    check('old TOTP secret no longer verifies', oldCodeData.success !== true);
    await member2.close();

    console.log(`\n${checks} checks passed`);
    await browser.close();
  } catch (e) {
    console.error('FAIL', e);
    process.exitCode = 1;
    try { await browser.close(); } catch (_) {}
  } finally {
    if (created) {
      const cleanup = await chromium.launch();
      const c = await cleanup.newContext();
      for (const email of [ownerEmail, memberEmail, soloEmail, plainEmail]) {
        await c.request.post(BASE + '/api/test-helpers.php', {
          data: { action: 'cleanup_test_user', email }
        }).catch(() => {});
      }
      await cleanup.close();
    }
  }
})();
