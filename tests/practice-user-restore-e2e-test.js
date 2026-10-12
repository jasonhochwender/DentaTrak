'use strict';
/**
 * Pending-removal / re-add / undo e2e for Settings > Practice Users.
 *
 * Verifies against a live dev server:
 *  - removing a persisted member stages a visible pending-removal row (Undo + note)
 *  - re-adding the same address (different casing/whitespace) cancels the removal,
 *    restores the member with prior role/access flags, and shows the confirmation
 *  - Undo restores the member
 *  - adding an already-listed member shows "This user is already in your practice."
 *  - saving after restore preserves the member + flags and sends no invitation
 *  - saving a real removal drops the membership row
 *  - canceling unsaved changes discards staged adds/removals
 *  - normal new-user additions still work
 */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { randomUUID } = require('node:crypto');

const BASE = process.env.BASE_URL || 'http://localhost/DentaTrak';
const admin = `e2e-userrestore-${randomUUID().slice(0, 8)}@example.test`;
const member = `e2e-member-${randomUUID().slice(0, 8)}@example.test`;
const password = 'LocalTest-' + randomUUID() + '!';
const memberPassword = 'LocalTest-' + randomUUID() + '!';
let practiceId = 0;
let checks = 0;
let failures = 0;

function check(name, value) {
  try { assert.ok(value, name); checks++; console.log('PASS ' + name); }
  catch (e) { failures++; console.log('FAIL ' + name + ' :: ' + e.message); }
}

async function helper(ctx, data) {
  const r = await ctx.request.post(BASE + '/api/test-helpers.php', { data });
  let body = {};
  try { body = await r.json(); } catch (e) { /* non-JSON */ }
  return { status: r.status(), body };
}

async function openSettings(page) {
  await page.click('#userMenuToggle');
  await page.click('#settingsMenuItem');
  await page.waitForSelector('#settingsBillingModal[style*="block"], #settingsBillingModal.dt-modal-open', { timeout: 10000 });
  await page.click('[data-nav-target="authorized"]');
  await page.waitForSelector('#gmailUsersList .practice-user-row', { timeout: 10000 });
}

// Row helpers operate on the rendered grid.
const memberRowSel = `#gmailUsersList .practice-user-row:has(.user-email-text:text-is("${member}"))`;
const pendingSel = '#gmailUsersList .practice-user-row.pending-removal';

async function closeSettingsDiscard(page) {
  await page.click('#settingsCancel');
  // Unsaved-changes warning appears when staged changes exist.
  try {
    await page.waitForSelector('#settings-close-btn', { timeout: 3000 });
    await page.click('#settings-close-btn');
  } catch (e) { /* no staged changes -> closed directly */ }
  await page.waitForSelector('#settingsBillingModal[style*="block"], #settingsBillingModal.dt-modal-open', { state: 'detached', timeout: 10000 }).catch(async () => {
    // display:none path
    await page.waitForFunction(() => {
      const m = document.getElementById('settingsBillingModal');
      return m && m.style.display !== 'block';
    }, null, { timeout: 10000 });
  });
}

async function saveSettings(page) {
  await page.click('#saveSettings');
  await page.waitForFunction(() => {
    const m = document.getElementById('settingsBillingModal');
    return m && m.style.display !== 'block';
  }, null, { timeout: 15000 });
}

(async () => {
  const browser = await chromium.launch();
  const setupCtx = await browser.newContext();

  // Admin + member with distinctive flags to prove restore preservation.
  const adminSetup = await helper(setupCtx, { action: 'setup_test_user', email: admin, password });
  assert.equal(adminSetup.body.success, true, 'admin setup failed');
  practiceId = adminSetup.body.practice_id;
  const memberSetup = await helper(setupCtx, {
    action: 'setup_practice_member', email: member, password: memberPassword,
    practiceId, role: 'user', limitedVisibility: true, canViewAnalytics: false, canEditCases: true
  });
  assert.equal(memberSetup.body.success, true, 'member setup failed');
  await helper(setupCtx, { action: 'clear_test_email_log' });

  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const login = await ctx.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: admin, password }
    });
    assert.equal((await login.json()).success, true, 'login failed');
    const page = await ctx.newPage();
    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(e.message));
    await page.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    if (page.url().includes('accept-terms.php')) {
      await page.check('#termsAccepted'); await page.click('#acceptBtn');
      await page.waitForURL('**/main.php');
    }
    await page.waitForSelector('#userMenuToggle', { timeout: 15000 });

    // ── 1. Remove a persisted member -> pending row ────────────────────
    await openSettings(page);
    await page.waitForSelector(memberRowSel, { timeout: 10000 });
    check('member row visible in grid', true);

    // Member was seeded as Assigned Only + no Insights.
    check('member limited checkbox checked',
      await page.isChecked(`${memberRowSel} .practice-user-limited-cell input[type="checkbox"]`));
    check('member insights checkbox unchecked',
      !(await page.isChecked(`${memberRowSel} .practice-user-analytics-cell input[type="checkbox"]`)));

    await page.click(`${memberRowSel} .remove-gmail-user`);
    await page.waitForSelector(pendingSel, { timeout: 5000 });
    check('pending-removal row appears after remove', true);
    check('member no longer an active row',
      (await page.$$(memberRowSel + ':not(.pending-removal)')).length === 0);
    const note = await page.textContent(`${pendingSel} .pending-removal-note`);
    check('pending row shows save-scoped removal note', /save/i.test(note || ''));
    check('pending row has Undo button',
      (await page.$$(`${pendingSel} .practice-user-undo-btn`)).length === 1);
    check('pending row checkboxes frozen (limited stays checked)',
      await page.isChecked(`${pendingSel} .practice-user-limited-cell input[type="checkbox"]`) &&
      await page.isDisabled(`${pendingSel} .practice-user-limited-cell input[type="checkbox"]`));

    // ── 2. Re-add same address (uppercase + whitespace) -> restore ─────
    await page.fill('#newGmailUser', '  ' + member.toUpperCase() + '  ');
    await page.click('#addGmailUser');
    await page.waitForSelector(memberRowSel + ':not(.pending-removal)', { timeout: 5000 });
    check('re-added member restored as active row', true);
    check('no pending-removal rows after restore',
      (await page.$$(pendingSel)).length === 0);
    check('restored member keeps Assigned Only flag',
      await page.isChecked(`${memberRowSel} .practice-user-limited-cell input[type="checkbox"]`));
    check('restored member keeps Insights flag (off)',
      !(await page.isChecked(`${memberRowSel} .practice-user-analytics-cell input[type="checkbox"]`)));
    const toastText = await page.textContent('.toast .toast-message').catch(() => '');
    check('restore confirmation toast shown', /restored/i.test(toastText || ''));

    // ── 3. Undo a pending removal ──────────────────────────────────────
    await page.click(`${memberRowSel} .remove-gmail-user`);
    await page.waitForSelector(pendingSel, { timeout: 5000 });
    await page.click(`${pendingSel} .practice-user-undo-btn`);
    await page.waitForSelector(memberRowSel + ':not(.pending-removal)', { timeout: 5000 });
    check('Undo restores member to active grid', true);
    check('no pending rows after Undo', (await page.$$(pendingSel)).length === 0);
    check('Undo preserves Assigned Only flag',
      await page.isChecked(`${memberRowSel} .practice-user-limited-cell input[type="checkbox"]`));

    // ── 4. Duplicate add of listed member -> inline message ────────────
    await page.fill('#newGmailUser', member);
    await page.click('#addGmailUser');
    await page.waitForTimeout(400); // synchronous validation renders immediately
    const err = await page.textContent('#gmailError');
    check('duplicate add shows already-in-practice message',
      /already in your practice/i.test(err || ''));
    check('duplicate add does not create a second row',
      (await page.$$(memberRowSel)).length === 1);

    // ── 5. Save after restore: member + flags preserved, no invite ─────
    await page.fill('#newGmailUser', '');
    await saveSettings(page);
    const pu = await helper(setupCtx, { action: 'get_practice_user', email: member, practice_id: practiceId });
    check('server: member still in practice after restore+save', pu.status === 200 && pu.body.practice_user);
    if (pu.body.practice_user) {
      check('server: limited_visibility preserved', String(pu.body.practice_user.limited_visibility) === '1');
      check('server: can_view_analytics preserved', String(pu.body.practice_user.can_view_analytics) === '0');
    }
    const emails = await helper(setupCtx, { action: 'get_all_app_emails' });
    const sentToMember = (emails.body.emails || []).filter(e => JSON.stringify(e).toLowerCase().includes(member));
    check('no invitation email sent to restored member', sentToMember.length === 0);

    // ── 6. Real removal persists on save ───────────────────────────────
    await openSettings(page);
    await page.waitForSelector(memberRowSel, { timeout: 10000 });
    await page.click(`${memberRowSel} .remove-gmail-user`);
    await page.waitForSelector(pendingSel, { timeout: 5000 });
    await saveSettings(page);
    const gone = await helper(setupCtx, { action: 'get_practice_user', email: member, practice_id: practiceId });
    check('server: member removed after save', gone.status === 404);

    // ── 7. Re-add after real removal = normal new add; cancel discards ──
    await openSettings(page);
    await page.fill('#newGmailUser', member);
    await page.click('#addGmailUser');
    await page.waitForSelector(memberRowSel + ':not(.pending-removal)', { timeout: 8000 });
    check('re-add after saved removal creates normal new-user row', true);
    check('re-added row is not a pending-removal row',
      (await page.$$(pendingSel)).length === 0);
    await closeSettingsDiscard(page);
    await openSettings(page);
    check('cancel discards unsaved re-add',
      (await page.$$(memberRowSel)).length === 0);

    check('no page errors during flow', pageErrors.length === 0);
    await ctx.close();

    // ── 8. Tablet/touch: pending card + Undo at smallest supported width ──
    // Settings is intentionally unavailable below 768px (menu item hidden +
    // JS guard closes the modal), so the smallest real surface is 768px.
    // This leg exercises the touch path at that width.
    // Re-seed the member for this pass (it was really removed in step 6).
    const reSeed = await helper(setupCtx, {
      action: 'setup_practice_member', email: member, password: memberPassword,
      practiceId, role: 'user', limitedVisibility: true
    });
    assert.equal(reSeed.body.success, true, 'member re-seed failed');
    const mctx = await browser.newContext({ viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true });
    const mlogin = await mctx.request.post(BASE + '/api/auth-email.php', {
      data: { action: 'login', email: admin, password }
    });
    assert.equal((await mlogin.json()).success, true, 'mobile login failed');
    const mpage = await mctx.newPage();
    await mpage.goto(BASE + '/main.php', { waitUntil: 'domcontentloaded' });
    if (mpage.url().includes('accept-terms.php')) {
      await mpage.check('#termsAccepted'); await mpage.click('#acceptBtn');
      await mpage.waitForURL('**/main.php');
    }
    await mpage.waitForSelector('#userMenuToggle', { timeout: 15000 });
    await mpage.tap('#userMenuToggle');
    await mpage.waitForTimeout(400);
    await mpage.tap('#settingsMenuItem');
    await mpage.waitForSelector('#settingsBillingModal[style*="block"], #settingsBillingModal.dt-modal-open', { timeout: 10000 });
    await mpage.tap('[data-nav-target="authorized"]');
    await mpage.waitForSelector(memberRowSel, { timeout: 10000 });
    await mpage.tap(`${memberRowSel} .remove-gmail-user`);
    await mpage.waitForSelector(pendingSel, { timeout: 5000 });
    check('tablet: pending-removal row visible', await mpage.isVisible(pendingSel));
    check('tablet: pending note + Undo visible',
      await mpage.isVisible(`${pendingSel} .pending-removal-note`) &&
      await mpage.isVisible(`${pendingSel} .practice-user-undo-btn`));
    await mpage.tap(`${pendingSel} .practice-user-undo-btn`);
    await mpage.waitForSelector(memberRowSel + ':not(.pending-removal)', { timeout: 5000 });
    check('tablet: Undo restores member', true);
    await mctx.close();
  } finally {
    // ── 8. Cleanup fixtures ────────────────────────────────────────────
    const c1 = await helper(setupCtx, { action: 'cleanup_test_user', email: member });
    const c2 = await helper(setupCtx, { action: 'cleanup_test_user', email: admin });
    check('cleanup member fixture', c1.body.success === true);
    check('cleanup admin fixture', c2.body.success === true);
    await setupCtx.close();
    await browser.close();
  }

  console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILED'} — ${checks + failures} checks`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error('SUITE ERROR:', e); process.exit(1); });
