'use strict';
/**
 * Practice-wide 2FA enforcement e2e test.
 *
 * Fixtures (own + shared practices):
 *   owner  - owns practice A, enrolls 2FA via the real API
 *   member - owns practice B AND is a plain member of practice A
 *   owner2 - owns practice C, never configures 2FA
 *
 * Covers: actor lockout protection, enable/disable, admin/member gating,
 * mid-session enforcement on protected APIs, setup-required routing,
 * enrollment completing access, multi-practice isolation, remember-me
 * session challenge, and the blocking page UI.
 *
 * Run: node tests/practice-2fa-enforcement-e2e-test.js
 */
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { chromium } = require('playwright');
const { randomUUID } = require('node:crypto');

const BASE = 'http://localhost/DentaTrak';
const suffix = randomUUID().slice(0, 8);
const ownerEmail = `e2e-2fa-owner-${suffix}@example.test`;
const memberEmail = `e2e-2fa-member-${suffix}@example.test`;
const owner2Email = `e2e-2fa-owner2-${suffix}@example.test`;
const password = 'LocalTest-' + randomUUID() + '!';
let checks = 0;
function check(name, value) { assert.ok(value, name); checks++; console.log('PASS ' + name); }

// RFC 6238 TOTP (SHA-1, 6 digits, 30s step) for completing real enrollment.
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

(async () => {
  const browser = await chromium.launch();
  const owner = await browser.newContext();
  const member = await browser.newContext();
  const owner2 = await browser.newContext();
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
  // Owners/admins must accept current Terms before admin APIs respond.
  async function acceptTerms(ctx) {
    const page = await ctx.request.get(BASE + '/accept-terms.php', { maxRedirects: 10 });
    const html = await page.text();
    const token = extractCsrf(html);
    const vm = html.match(/name="terms-version" content="([^"]+)"/);
    if (!token || !vm) return; // terms not required for this user
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
  // Complete the real enrollment flow for a context: setup -> compute code -> verify.
  async function enroll2FA(ctx, pageUrl) {
    const token = await csrfFor(ctx, pageUrl);
    const setup = await ctx.request.post(BASE + '/api/2fa-setup.php?action=setup', {
      headers: { 'X-CSRF-Token': token }
    });
    const setupData = await setup.json();
    assert.equal(setupData.success, true, JSON.stringify(setupData));
    assert.ok(setupData.secret && setupData.qrCode, 'setup returns secret + qr');
    const verify = await ctx.request.post(BASE + '/api/2fa-setup.php?action=verify', {
      headers: { 'X-CSRF-Token': token },
      data: { code: totpCode(setupData.secret) }
    });
    const verifyData = await verify.json();
    assert.equal(verifyData.success, true, JSON.stringify(verifyData));
    return token;
  }

  try {
    // ---- Fixtures ----
    const a = await helper({ action: 'setup_test_user', email: ownerEmail, password });
    const b = await helper({ action: 'setup_test_user', email: memberEmail, password });
    const c = await helper({ action: 'setup_test_user', email: owner2Email, password });
    created = true;
    const practiceA = Number(a.practice_id);
    const practiceB = Number(b.practice_id);
    const practiceC = Number(c.practice_id);
    await helper({ action: 'setup_practice_member', practiceId: practiceA, email: memberEmail, password, role: 'user' });

    const ownerLogin = await login(owner, ownerEmail);
    check('owner baseline login', ownerLogin.success === true);
    await acceptTerms(owner);
    const ownerToken = await csrfFor(owner);
    const memberLogin = await login(member, memberEmail);
    check('member baseline login', memberLogin.success === true);
    await acceptTerms(member);
    const owner2Login = await login(owner2, owner2Email);
    check('owner2 baseline login', owner2Login.success === true);
    await acceptTerms(owner2);
    const owner2Token = await csrfFor(owner2);

    // ---- Baseline: enforcement OFF ----
    const memberToken0 = await csrfFor(member, BASE + '/practice-setup.php');
    const selA0 = await member.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': memberToken0 },
      data: { practice_id: practiceA }
    });
    check('member selects required-to-be practice while OFF', (await selA0.json()).success === true);
    const memberSettings0 = await member.request.get(BASE + '/api/get-settings.php');
    check('member reaches settings while OFF', (await memberSettings0.json()).success === true);

    // ---- Actor lockout protection ----
    const owner2Enable = await owner2.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': owner2Token },
      data: { enabled: true }
    });
    const owner2EnableData = await owner2Enable.json();
    check('admin without 2FA gets ACTOR_2FA_REQUIRED', owner2Enable.status() === 428
      && owner2EnableData.error_code === 'ACTOR_2FA_REQUIRED');
    const cStillOff = await owner2.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    check('policy remains off after refused enable', (await cStillOff.json()).required === false);

    // ---- Member cannot administer ----
    const memberEnable = await member.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': memberToken0 },
      data: { enabled: true }
    });
    check('ordinary member cannot enable', memberEnable.status() === 403);
    const memberStatus = await member.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    check('ordinary member cannot read policy status', memberStatus.status() === 403);

    // ---- Owner enrolls 2FA, then enables ----
    await enroll2FA(owner, BASE + '/main.php');
    const enable = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': ownerToken },
      data: { enabled: true }
    });
    check('owner with 2FA enables requirement', (await enable.json()).success === true);

    const status = await owner.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    const statusData = await status.json();
    check('status counts correct', statusData.counts.total === 2
      && statusData.counts.enabled === 1 && statusData.counts.needs_setup === 1);
    const memberRow = statusData.members.find(m => m.email === memberEmail);
    check('member flagged as needing setup', memberRow && memberRow.totp_enabled === false);
    check('status exposes no secrets', !('totp_secret' in (statusData.members[0] || {})));

    // ---- Mid-session enforcement on the member's existing session ----
    const memberSettingsBlocked = await member.request.get(BASE + '/api/get-settings.php');
    const blockedData = await memberSettingsBlocked.json();
    check('protected API blocked mid-session', memberSettingsBlocked.status() === 403
      && blockedData.error_code === 'PRACTICE_2FA_SETUP_REQUIRED');
    check('block response carries redirect', /2fa-required\.php/.test(blockedData.redirect || ''));

    // ---- Enrollment from the blocking page state ----
    const reqPage = await member.request.get(BASE + '/2fa-required.php?practice_id=' + practiceA);
    const reqHtml = await reqPage.text();
    check('blocking page shows enroll flow', reqHtml.includes('enrollFlow') && reqHtml.includes('enrollBeginBtn'));
    check('blocking page hides challenge flow for unenrolled user', !reqHtml.includes('id="challengeFlow"'));
    const memberToken = extractCsrf(reqHtml);
    assert.ok(memberToken, 'csrf token from 2fa-required page');

    const mSetup = await member.request.post(BASE + '/api/2fa-setup.php?action=setup', {
      headers: { 'X-CSRF-Token': memberToken }
    });
    const mSetupData = await mSetup.json();
    check('blocked member can reach setup endpoint', mSetupData.success === true);
    const mVerify = await member.request.post(BASE + '/api/2fa-setup.php?action=verify', {
      headers: { 'X-CSRF-Token': memberToken },
      data: { code: totpCode(mSetupData.secret) }
    });
    check('enrollment verify succeeds', (await mVerify.json()).success === true);

    const memberSettingsAfter = await member.request.get(BASE + '/api/get-settings.php');
    check('setup completion restores practice access', (await memberSettingsAfter.json()).success === true);

    // ---- Multi-practice: switch out to own (non-required) practice ----
    const toB = await member.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': memberToken },
      data: { practice_id: practiceB }
    });
    check('member switches to own non-required practice', (await toB.json()).success === true);
    // Member owns B, so they can read its policy - proving the flag is
    // scoped to practice A only and untouched by A's setting.
    const bStatus = await member.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    const bStatusData = await bStatus.json();
    check('practice B policy untouched by practice A', bStatusData.success === true
      && bStatusData.required === false);
    const backToA = await member.request.post(BASE + '/api/switch-practice.php', {
      headers: { 'X-CSRF-Token': memberToken },
      data: { practice_id: practiceA }
    });
    check('satisfied session re-enters required practice', (await backToA.json()).success === true);

    // ---- Owner-only practice unaffected ----
    const ownerA = await owner.request.get(BASE + '/api/get-settings.php');
    check('owner keeps normal access with 2FA', (await ownerA.json()).success === true);

    // ---- Disable preserves user-level 2FA ----
    const disable = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': ownerToken },
      data: { enabled: false }
    });
    check('owner disables requirement', (await disable.json()).success === true);
    const member2faStatus = await member.request.get(BASE + '/api/2fa-setup.php?action=status');
    check('member personal 2FA survives policy disable', (await member2faStatus.json()).enabled === true);
    const owner2faStatus = await owner.request.get(BASE + '/api/2fa-setup.php?action=status');
    check('owner personal 2FA survives policy disable', (await owner2faStatus.json()).enabled === true);

    // ---- Re-enable for remember-me challenge flow ----
    const reEnable = await owner.request.post(BASE + '/api/practice-2fa-policy.php?action=update', {
      headers: { 'X-CSRF-Token': ownerToken },
      data: { enabled: true }
    });
    check('owner re-enables requirement', (await reEnable.json()).success === true);

    // ---- Remember Me restore: enrolled member must re-prove 2FA ----
    // Personal 2FA is a login-time control: the persistent cookie may
    // remember identity but must never create a session that already
    // satisfies (or skips) the second factor - regardless of practice
    // policy.
    const rmCtx = await browser.newContext();
    const rmLogin = await login(rmCtx, memberEmail, {
      rememberMe: true,
      totpCode: totpCode(mSetupData.secret)
    });
    check('member remember-me login with 2FA succeeds', rmLogin.success === true);
    const rmCookies = await rmCtx.cookies(BASE);
    const rememberCookie = rmCookies.find(ck => ck.name === 'remember_token');
    assert.ok(rememberCookie, 'remember_token cookie issued');

    const restore = await browser.newContext();
    await restore.addCookies([{ name: 'remember_token', value: rememberCookie.value, url: BASE }]);
    // attemptRememberMeLogin() only runs on the login page. For a user
    // with totp_enabled it must now leave a PENDING 2FA state - no
    // authenticated session is created at all.
    const loginResp = await restore.request.get(BASE + '/login.php', { maxRedirects: 10 });
    const loginHtml = await loginResp.text();
    check('remember-me restore stays on login for 2FA user', loginResp.url().includes('login.php'));
    check('remember-me restore renders the 2FA challenge', loginHtml.includes('pendingRememberMe = true'));

    // Pending state is not an authenticated session: practice APIs deny it.
    const pendingApi = await restore.request.get(BASE + '/api/practice-2fa-policy.php?action=status');
    check('pending remember-me session has no authenticated access', pendingApi.status() === 401);

    // Wrong-format code is rejected; the real code completes the sign-in.
    const badCode = await restore.request.post(BASE + '/api/verify-google-2fa.php', {
      data: { totpCode: '1234' }
    });
    check('pending challenge rejects malformed code', badCode.status() === 400);
    const pendingVerify = await restore.request.post(BASE + '/api/verify-google-2fa.php', {
      data: { totpCode: totpCode(mSetupData.secret) }
    });
    const pendingVerifyData = await pendingVerify.json();
    check('pending challenge accepts valid TOTP', pendingVerifyData.success === true);

    // The completed session carries the proof flag, so the required
    // practice admits it without a second challenge.
    const chooserPage = await restore.request.get(BASE + '/practice-setup.php', { maxRedirects: 10 });
    const restoreToken = extractCsrf(await chooserPage.text());
    assert.ok(restoreToken, 'csrf token after remember-me 2FA completion');
    const selAVerified = await restore.request.post(BASE + '/api/select-practice.php', {
      headers: { 'X-CSRF-Token': restoreToken, 'Accept': 'application/json' },
      data: { practice_id: practiceA }
    });
    check('2FA-completed session enters required practice', (await selAVerified.json()).success === true);

    // ---- Session challenge path: authenticated but unproven session ----
    // Simulates sessions that predate the proof flag (helper clears it):
    // they are challenged at the required-practice boundary but keep
    // access to non-required practices.
    await restore.request.post(BASE + '/api/test-helpers.php', {
      data: { action: 'clear_session_totp_verified' }
    });
    const selB = await restore.request.post(BASE + '/api/select-practice.php', {
      headers: { 'X-CSRF-Token': restoreToken, 'Accept': 'application/json' },
      data: { practice_id: practiceB }
    });
    check('unproven session can enter non-required practice', (await selB.json()).success === true);
    const selBlocked = await restore.request.post(BASE + '/api/select-practice.php', {
      headers: { 'X-CSRF-Token': restoreToken, 'Accept': 'application/json' },
      data: { practice_id: practiceA }
    });
    const selBlockedData = await selBlocked.json();
    check('unproven session challenged for required practice',
      selBlockedData.error_code === 'PRACTICE_2FA_CHALLENGE_REQUIRED');

    // The blocking page shows the challenge (not enrollment) flow for an
    // already-enrolled account.
    const chalPage = await restore.request.get(BASE + '/2fa-required.php?practice_id=' + practiceA);
    const chalHtml = await chalPage.text();
    check('blocking page shows challenge flow for enrolled user',
      chalHtml.includes('challengeFlow') && chalHtml.includes('challengeVerifyBtn'));

    const chal = await restore.request.post(BASE + '/api/2fa-challenge.php', {
      headers: { 'X-CSRF-Token': restoreToken },
      data: { code: totpCode(mSetupData.secret) }
    });
    check('session challenge accepts valid TOTP', (await chal.json()).success === true);
    const selAAfter = await restore.request.post(BASE + '/api/select-practice.php', {
      headers: { 'X-CSRF-Token': restoreToken, 'Accept': 'application/json' },
      data: { practice_id: practiceA }
    });
    check('challenge completion admits required practice', (await selAAfter.json()).success === true);

    // ---- Isolation: non-member cannot activate the required practice ----
    const rm2 = await browser.newContext();
    const rm2Login = await login(rm2, owner2Email, { rememberMe: true });
    check('owner2 fresh login', rm2Login.success === true);

    // A user WITHOUT personal 2FA keeps the classic remember-me restore:
    // no pending challenge, straight into an authenticated session.
    const rm2Cookies = await rm2.cookies(BASE);
    const rm2Cookie = rm2Cookies.find(ck => ck.name === 'remember_token');
    assert.ok(rm2Cookie, 'remember_token cookie issued for non-2FA user');
    const restore2 = await browser.newContext();
    await restore2.addCookies([{ name: 'remember_token', value: rm2Cookie.value, url: BASE }]);
    const loginResp2 = await restore2.request.get(BASE + '/login.php', { maxRedirects: 10 });
    check('non-2FA remember-me restore auto-enters app', !loginResp2.url().includes('login.php'));
    const rm2Token = await csrfFor(rm2);
    const foreignSel = await rm2.request.post(BASE + '/api/select-practice.php', {
      headers: { 'X-CSRF-Token': rm2Token, 'Accept': 'application/json' },
      data: { practice_id: practiceA }
    });
    check('non-member cannot enter required practice at all', foreignSel.status() === 403);

    // ---- Browser check: non-required practice routes past the page ----
    const uiPage = await rm2.newPage();
    await uiPage.goto(BASE + '/2fa-required.php?practice_id=' + practiceC, { waitUntil: 'domcontentloaded' });
    check('non-required practice param routes past page', !uiPage.url().includes('2fa-required.php'));

    // ---- Cleanup ----
    await helper({ action: 'delete_test_users', marker: 'DentaTrakTest', emails: [ownerEmail, memberEmail, owner2Email] });
    console.log(`\n${checks} checks passed`);
    await browser.close();
  } catch (err) {
    console.error('E2E FAILURE:', err);
    if (created) {
      try {
        await owner.request.post(BASE + '/api/test-helpers.php', {
          data: { action: 'delete_test_users', marker: 'DentaTrakTest', emails: [ownerEmail, memberEmail, owner2Email] }
        });
      } catch (e) { /* best-effort cleanup */ }
    }
    await browser.close();
    process.exit(1);
  }
})();
