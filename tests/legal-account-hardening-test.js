const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

const BASE = 'http://localhost/DentaTrak';
const SCREEN_DIR = path.join(__dirname, '..', 'screenshots');

const PROTECTED_FILES = [
  path.join(__dirname, '..', 'api', 'accept-baa.php'),
  path.join(__dirname, '..', 'api', 'accept-terms.php'),
  path.join(__dirname, '..', 'api', 'admin-practices.php'),
  path.join(__dirname, '..', 'api', 'practice-creation-policy.php'),
  path.join(__dirname, '..', 'api', 'schema-helpers.php'),
];

async function callHelper(page, action, extra = {}) {
  const payload = { action, ...extra };
  return page.evaluate(async ({ url, payload }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const t = await r.text();
    try { return { status: r.status, body: JSON.parse(t), raw: t }; } catch (e) { return { status: r.status, raw: t, parseError: e.message }; }
  }, { url: `${BASE}/api/test-helpers.php`, payload });
}

async function createOwner(page, email) {
  for (let i = 0; i < 3; i++) {
    const res = await callHelper(page, 'setup_test_user', {
      email,
      password: 'TestPass123!',
      firstName: 'DentaTrakTest',
      lastName: 'HardeningOwner',
      practiceName: 'DentaTrakTest Hardening Practice'
    });
    if (res.body && res.body.success) return res.body;
    console.log(`setup_test_user ${email} attempt ${i + 1} failed:`, res.body || res.raw);
    await page.waitForTimeout(500);
  }
  throw new Error(`setup_test_user ${email} failed`);
}

async function createMember(page, email, practiceId, role = 'user') {
  for (let i = 0; i < 3; i++) {
    const res = await callHelper(page, 'setup_practice_member', {
      email,
      password: 'TestPass123!',
      firstName: 'DentaTrakTest',
      lastName: 'HardeningMember',
      role,
      practiceId,
      canViewAnalytics: 1,
      canEditCases: 1,
      limitedVisibility: 0
    });
    if (res.body && res.body.success) return res.body;
    console.log(`setup_practice_member ${email} attempt ${i + 1} failed:`, res.body || res.raw);
    await page.waitForTimeout(500);
  }
  throw new Error(`setup_practice_member ${email} failed`);
}

async function login(page, email) {
  const result = await page.evaluate(async ({ url, email, password }) => {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'login', email, password })
    });
    const text = await r.text();
    try { return JSON.parse(text); } catch (e) { return { success: false, raw: text }; }
  }, { url: `${BASE}/api/auth-email.php`, email, password: 'TestPass123!' });
  if (!result.success) throw new Error(`login failed for ${email}: ${JSON.stringify(result)}`);
}

async function getCsrfFromAcceptTerms(page, email) {
  await login(page, email);
  await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
  // main.php redirects owners/admins to accept-terms.php when terms not accepted
  if (!page.url().includes('accept-terms.php')) {
    throw new Error(`Expected redirect to accept-terms.php, got ${page.url()}`);
  }
  return await page.locator('meta[name="csrf-token"]').getAttribute('content');
}

async function setUserClassification(page, csrf, targetUserId, fields) {
  const r = await page.request.post(`${BASE}/api/admin-practices.php?action=set_user_classification`, {
    data: { ...fields, user_id: targetUserId, csrf_token: csrf },
    headers: { 'Content-Type': 'application/json' }
  });
  const body = await r.json().catch(async () => await r.text());
  return { status: r.status(), body };
}

async function attemptBaa(page, email, orgType) {
  await login(page, email);
  await page.goto(`${BASE}/baa-acceptance.php?new=1`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#baaForm', { timeout: 15000 });

  await page.selectOption('#organizationType', orgType);
  await page.fill('#legalName', 'DentaTrakTest Hardening Practice');
  await page.fill('#practiceAddress', '123 Test Street, Test City, TS 12345');
  await page.fill('#signerName', 'DentaTrakTest Signer');
  await page.fill('#signerTitle', 'Authorized Agent');
  await page.check('#authorizedToBind');
  await page.check('#practiceAuthorityAck');

  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content') || '';
  const data = {
    legalName: await page.inputValue('#legalName'),
    practiceAddress: await page.inputValue('#practiceAddress'),
    signerName: await page.inputValue('#signerName'),
    signerTitle: await page.inputValue('#signerTitle'),
    authorizedToBind: await page.isChecked('#authorizedToBind'),
    practiceAuthorityAck: await page.isChecked('#practiceAuthorityAck'),
    organizationType: orgType,
    new: true
  };

  const result = await page.evaluate(async ({ url, data }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(data)
    });
    const text = await r.text();
    let body;
    try { body = JSON.parse(text); } catch (e) { body = text; }
    return { status: r.status, body };
  }, { url: `${BASE}/api/accept-baa.php`, data });
  return { status: result.status, body: result.body };
}

async function getUserRecord(page, email) {
  const res = await callHelper(page, 'get_test_user_record', { email });
  return res;
}

async function getPracticeRecord(page, practiceId) {
  const res = await callHelper(page, 'get_test_practice_record', { practice_id: practiceId });
  return res;
}

async function listCases(page) {
  const r = await page.request.get(`${BASE}/api/list-cases.php?type=all&page=1&limit=10`, {
    headers: { 'Content-Type': 'application/json' }
  });
  let body;
  try { body = await r.json(); } catch (e) { body = await r.text(); }
  return { status: r.status(), body };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.goto(`${BASE}/index.php`);

  const failures = [];
  const stamp = Date.now();
  const ownerEmail = `e2e.hardening-owner-${stamp}@dentatrak.com`;
  const adminLabEmail = `e2e.hardening-adminlab-${stamp}@dentatrak.com`;
  const approvedLabEmail = `e2e.hardening-approvedlab-${stamp}@dentatrak.com`;
  const invitedLabEmail = `e2e.hardening-invitedlab-${stamp}@dentatrak.com`;
  const emails = [];

  try {
    // 0. Migration is idempotent and preserves data.
    const migrationPath = path.join(__dirname, '..', 'migrations', '2026_09_01_account_classification.php');
    for (let i = 0; i < 2; i++) {
      const out = execSync(`php "${migrationPath}"`, { encoding: 'utf8', cwd: path.join(__dirname, '..') });
      let parsed;
      try { parsed = JSON.parse(out); } catch (e) { parsed = { raw: out }; }
      console.log(`Migration run ${i + 1}:`, parsed);
      if (!parsed.success || (parsed.errors && parsed.errors.length > 0)) {
        failures.push(`Migration run ${i + 1} failed: ${out}`);
      }
    }

    // 0b. Request handlers must not contain or execute ALTER TABLE.
    for (const filePath of PROTECTED_FILES) {
      const source = fs.readFileSync(filePath, 'utf8');
      if (source.toLowerCase().includes('alter table')) {
        failures.push(`Request handler still contains ALTER TABLE: ${filePath}`);
      }
    }

    // 1. Setup owner and derive a CSRF token from the accept-terms page.
    const ownerInfo = await createOwner(page, ownerEmail);
    emails.push(ownerEmail);
    const ownerCsrf = await getCsrfFromAcceptTerms(page, ownerEmail);

    // 2. A direct protected API call before accepting Terms must be rejected.
    const blockedRes = await setUserClassification(page, ownerCsrf, 0, {});
    console.log('Protected API before terms acceptance:', blockedRes);
    if (blockedRes.status !== 403 || blockedRes.body?.error_code !== 'TERMS_REQUIRED') {
      failures.push(`Protected API did not reject unaccepted Terms: ${JSON.stringify(blockedRes)}`);
    }

    // Capture pre-acceptance BAA state to prove Terms acceptance does not touch it.
    const prePractice = await getPracticeRecord(page, ownerInfo.practice_id);
    console.log('Pre-acceptance practice record:', prePractice.body);

    // 3. Existing owner/admin Terms acceptance is recorded and stops redirect loop.
    const currentTermsVersion = '2026-09-01';
    const acceptRes = await page.request.post(`${BASE}/api/accept-terms.php`, {
      data: { accepted: true, terms_version: currentTermsVersion },
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': ownerCsrf }
    });
    const acceptBody = await acceptRes.json().catch(async () => await acceptRes.text());
    console.log('Terms acceptance result:', acceptRes.status(), acceptBody);
    if (acceptRes.status() !== 200 || !acceptBody.success) {
      failures.push(`Terms acceptance failed: ${JSON.stringify(acceptBody)}`);
    }

    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    if (!page.url().includes('main.php')) {
      failures.push(`main.php did not load after accepting terms: ${page.url()}`);
    }

    // 3b. Acceptance recorded the current version and timestamp, and did not modify BAA.
    const userRecord = await getUserRecord(page, ownerEmail);
    console.log('User record after acceptance:', userRecord.body);
    if (userRecord.body?.user?.terms_accepted_version !== '2026-09-01' || !userRecord.body?.user?.terms_accepted_at) {
      failures.push(`Terms acceptance not recorded correctly: ${JSON.stringify(userRecord.body)}`);
    }

    const postPractice = await getPracticeRecord(page, ownerInfo.practice_id);
    console.log('Post-acceptance practice record:', postPractice.body);
    const preBaa = prePractice.body?.practice;
    const postBaa = postPractice.body?.practice;
    if (preBaa && postBaa) {
      if (
        preBaa.baa_accepted !== postBaa.baa_accepted ||
        preBaa.baa_version !== postBaa.baa_version ||
        preBaa.baa_accepted_by_user_id !== postBaa.baa_accepted_by_user_id
      ) {
        failures.push('Terms acceptance modified BAA acceptance records');
      }
    } else {
      failures.push('Could not verify BAA records were preserved');
    }

    // 3c. After acceptance, the previously blocked protected API now works.
    const allowedRes = await setUserClassification(page, ownerCsrf, 0, { organization_type: 'dental_practice' });
    console.log('Protected API after terms acceptance (dummy user_id):', allowedRes);
    // user_id 0 is invalid, but we should get past the Terms guard (400 is expected).
    if (allowedRes.status === 403 && allowedRes.body?.error_code === 'TERMS_REQUIRED') {
      failures.push('Protected API still rejected after Terms acceptance');
    }

    // 4. Create secondary members.
    const adminLab = await createMember(page, adminLabEmail, ownerInfo.practice_id, 'admin');
    emails.push(adminLabEmail);
    const approvedLab = await createMember(page, approvedLabEmail, ownerInfo.practice_id, 'user');
    emails.push(approvedLabEmail);
    const invitedLab = await createMember(page, invitedLabEmail, ownerInfo.practice_id, 'user');
    emails.push(invitedLabEmail);

    // 5. Classify them as laboratories, with approved lab explicitly approved.
    let setRes = await setUserClassification(page, ownerCsrf, adminLab.user_id, { organization_type: 'lab', lab_practice_creation_approved: 0 });
    console.log('Set admin-lab classification:', setRes);
    if (!setRes.body.success) failures.push(`Failed to set admin-lab classification: ${JSON.stringify(setRes.body)}`);

    setRes = await setUserClassification(page, ownerCsrf, approvedLab.user_id, { organization_type: 'lab', lab_practice_creation_approved: 1 });
    console.log('Set approved-lab classification:', setRes);
    if (!setRes.body.success) failures.push(`Failed to set approved-lab classification: ${JSON.stringify(setRes.body)}`);

    setRes = await setUserClassification(page, ownerCsrf, invitedLab.user_id, { organization_type: 'lab', lab_practice_creation_approved: 0 });
    console.log('Set invited-lab classification:', setRes);
    if (!setRes.body.success) failures.push(`Failed to set invited-lab classification: ${JSON.stringify(setRes.body)}`);

    // 6. Ordinary admin classified as a lab must still be blocked.
    const adminLabRes = await attemptBaa(page, adminLabEmail, 'lab');
    console.log('Admin-lab creation attempt:', adminLabRes);
    if (adminLabRes.status === 200 || (adminLabRes.body && adminLabRes.body.success)) {
      failures.push(`Admin-lab was not blocked: ${JSON.stringify(adminLabRes)}`);
    }

    // 7. Ordinary user cannot change organization_type via accept-baa after being classified.
    const bypassRes = await attemptBaa(page, adminLabEmail, 'dental_practice');
    console.log('Org-type bypass attempt:', bypassRes);
    if (bypassRes.status !== 403) {
      failures.push(`Ordinary lab user was allowed to change organization type: ${JSON.stringify(bypassRes)}`);
    }

    // 8. Explicit super-user-controlled approval must permit creation.
    const approvedLabRes = await attemptBaa(page, approvedLabEmail, 'lab');
    console.log('Approved-lab creation attempt:', approvedLabRes);
    if (approvedLabRes.status !== 200 || !approvedLabRes.body || !approvedLabRes.body.success) {
      failures.push(`Approved lab could not create practice: ${JSON.stringify(approvedLabRes)}`);
    }

    // 9. Invited lab user can access the Practice without being forced to accept
    // updated Terms (not an admin) or create a Practice, and can read authorized
    // case data.
    await login(page, invitedLabEmail);
    await page.goto(`${BASE}/main.php`, { waitUntil: 'domcontentloaded' });
    console.log('Invited lab initial URL:', page.url());
    if (page.url().includes('accept-terms.php')) {
      failures.push(`Invited lab user was incorrectly sent to accept-terms.php: ${page.url()}`);
    }
    if (page.url().includes('practice-setup.php')) {
      // No practice is selected yet; choose the authorized Practice.
      const selector = `button.select-btn[data-practice-id="${ownerInfo.practice_id}"]`;
      await page.locator(selector).first().click();
      await page.waitForURL(/main\.php/, { timeout: 10000 });
    }
    console.log('Invited lab final URL:', page.url());
    if (!page.url().includes('main.php')) {
      failures.push(`Invited lab user could not reach main.php: ${page.url()}`);
    }

    const casesRes = await listCases(page);
    console.log('Invited lab list-cases:', casesRes.status, casesRes.body?.success);
    if (casesRes.status !== 200) {
      failures.push(`Invited lab user could not list authorized cases: ${JSON.stringify(casesRes)}`);
    }

    // 10. A dental-practice user can create a Practice when otherwise authorized.
    const dentalEmail = `e2e.hardening-dental-${stamp}@dentatrak.com`;
    const dentalMember = await createMember(page, dentalEmail, ownerInfo.practice_id, 'user');
    emails.push(dentalEmail);
    const dentalRes = await attemptBaa(page, dentalEmail, 'dental_practice');
    console.log('Dental creation attempt:', dentalRes);
    if (dentalRes.status !== 200 || !dentalRes.body || !dentalRes.body.success) {
      failures.push(`Dental user could not create practice: ${JSON.stringify(dentalRes)}`);
    }

  } catch (e) {
    failures.push(`Unexpected error: ${e.message}`);
    console.error(e);
    try { await page.screenshot({ path: path.join(SCREEN_DIR, 'hardening-test-error.png') }); } catch {}
  } finally {
    const cleanup = await callHelper(page, 'delete_test_users', { marker: 'DentaTrakTest', emails });
    console.log('cleanup:', cleanup);
    await browser.close();
  }

  if (failures.length) {
    console.log('FAILURES:', failures);
    process.exit(1);
  }
  console.log('Legal/account hardening test passed.');
})();
