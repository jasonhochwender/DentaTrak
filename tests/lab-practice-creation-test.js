const { chromium } = require('playwright');
const BASE = 'http://localhost/DentaTrak';
const SCREEN_DIR = require('path').join(__dirname, '..', 'screenshots');

async function callHelper(page, action, extra = {}) {
  const payload = { action, ...extra };
  return page.evaluate(async ({ url, payload }) => {
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const t = await r.text();
    try { return { status: r.status, body: JSON.parse(t), raw: t }; } catch (e) { return { status: r.status, raw: t, parseError: e.message }; }
  }, { url: `${BASE}/api/test-helpers.php`, payload });
}

async function createTestUser(page, email, firstName) {
  for (let i = 0; i < 3; i++) {
    const res = await callHelper(page, 'setup_practice_member', {
      email,
      password: 'TestPass123!',
      firstName,
      lastName: 'OrgTypeTest',
      role: 'user',
      canViewAnalytics: 1,
      canEditCases: 1,
      limitedVisibility: 0,
      practiceId: 1404,
      adminEmail: 'e2e_test_browser2@dentatrak.com'
    });
    if (res.body && res.body.success) return res.body;
    console.log(`setup ${email} attempt ${i + 1} failed:`, res.body || res.raw);
    await page.waitForTimeout(1000);
  }
  throw new Error(`setup ${email} failed`);
}

async function attemptBaa(page, email, orgType) {
  const context = await page.context();
  const login = await page.request.post(`${BASE}/api/auth-email.php`, {
    data: { action: 'login', email, password: 'TestPass123!' },
    headers: { 'Content-Type': 'application/json' }
  });
  const loginBody = await login.json();
  if (!loginBody.success) throw new Error(`login failed for ${email}: ${JSON.stringify(loginBody)}`);

  await page.goto(`${BASE}/baa-acceptance.php?new=1`);
  await page.waitForSelector('#baaForm', { timeout: 15000 });

  if (orgType === 'lab') {
    await page.selectOption('#organizationType', 'lab');
    await page.waitForTimeout(200);
  } else if (orgType === 'dental_practice') {
    await page.selectOption('#organizationType', 'dental_practice');
  } else if (orgType === 'dso') {
    await page.selectOption('#organizationType', 'dso');
  }

  await page.fill('#legalName', 'DentaTrakTest OrgType Practice');
  await page.fill('#practiceAddress', '123 Test Street, Test City, TS 12345');
  await page.fill('#signerName', 'DentaTrakTest Signer');
  await page.fill('#signerTitle', 'Authorized Agent');
  await page.check('#authorizedToBind');
  await page.check('#practiceAuthorityAck');

  const acceptBtn = await page.locator('#acceptBtn');
  if (await acceptBtn.isDisabled()) return { disabled: true };

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

  const r = await page.request.post(`${BASE}/api/accept-baa.php`, {
    data,
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }
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
  const emails = [
    `e2e.lab-creator-${Date.now()}@dentatrak.com`,
    `e2e.dental-creator-${Date.now()}@dentatrak.com`
  ];

  try {
    for (const email of emails) {
      await createTestUser(page, email, 'DentaTrakTest');
    }

    // Lab user must be blocked
    const labRes = await attemptBaa(page, emails[0], 'lab');
    console.log('Lab creator result:', labRes);
    if (labRes.status !== 403 || (labRes.body && labRes.body.success)) {
      failures.push(`Lab user was not blocked: ${JSON.stringify(labRes)}`);
    }

    // Dental practice user must succeed
    const dentalRes = await attemptBaa(page, emails[1], 'dental_practice');
    console.log('Dental creator result:', dentalRes);
    if (dentalRes.status !== 200 || !dentalRes.body || !dentalRes.body.success) {
      failures.push(`Dental user could not create practice: ${JSON.stringify(dentalRes)}`);
    }

    // Cleanup test users (and their owned practices)
    const del = await callHelper(page, 'delete_test_users', { marker: 'DentaTrakTest', emails });
    console.log('cleanup:', del);
  } catch (e) {
    failures.push(`Unexpected error: ${e.message}`);
    console.error(e);
    await page.screenshot({ path: require('path').join(SCREEN_DIR, 'lab-practice-creation-error.png') });
  } finally {
    await browser.close();
  }

  if (failures.length) {
    console.log('FAILURES:', failures);
    process.exit(1);
  }
  console.log('Lab practice creation block test passed.');
})();
