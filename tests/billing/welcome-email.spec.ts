import { test, expect, request as playwrightRequest } from '@playwright/test';
import { getUrl, BASE_URL } from '../helpers/login';

const TEST_PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'D3n7@Tr@k!9Zf#Qm2xL8V';

test.describe.serial('Welcome email on first owned practice', () => {
  const RUN_ID = Date.now();
  const NEW_OWNER_EMAIL = `e2e.welcome.${RUN_ID}@dentatrak.com`;

  test('initial BAA / practice creation sends a welcome email', async ({ page, request }) => {
    test.setTimeout(60000);
    // Register a brand-new account
    const reg = await request.post(getUrl('api/auth-email.php'), {
      data: {
        action: 'register',
        email: NEW_OWNER_EMAIL,
        password: TEST_PASSWORD,
        confirmPassword: TEST_PASSWORD,
        firstName: 'E2E',
        lastName: 'Welcome',
      },
    });
    await expect(reg).toBeOK();

    // Verify the new account through the test helper
    const verify = await request.post(getUrl('api/test-helpers.php'), {
      data: { action: 'verify_email', email: NEW_OWNER_EMAIL },
    });
    await expect(verify).toBeOK();

    // Log in through the UI so a session and CSRF token are established
    await page.goto(getUrl('login.php'));
    await page.locator('#showEmailSignIn').click();
    await page.locator('#checkEmail').fill(NEW_OWNER_EMAIL);
    await page.locator('#emailContinueBtn').click();
    await page.locator('#loginPassword').waitFor({ state: 'visible' });
    await page.locator('#loginPassword').fill(TEST_PASSWORD);
    await page.locator('#loginSubmitBtn').click();

    // First-time accounts are routed to the BAA acceptance page
    await page.waitForURL(/baa-acceptance\.php/, { timeout: 15000 });

    // Clear any email recorded so far (e.g. verification email)
    await request.post(getUrl('api/test-helpers.php'), { data: { action: 'clear_test_email_log' } });

    // Accept the BAA and create the first owned practice
    const practiceName = `Welcome Practice ${RUN_ID}`;
    await page.locator('#legalName').fill(practiceName);
    await page.locator('#practiceAddress').fill('123 Test Street, Test City, TS 12345');
    await page.locator('#signerName').fill('E2E Welcome');
    await page.locator('#signerTitle').fill('Owner');
    await page.locator('#authorizedToBind').check();
    await page.locator('#acceptBtn').click();

    await page.waitForURL(/main\.php/, { timeout: 30000 });

    // Verify the recorded welcome email
    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    await expect(emailResp).toBeOK();
    const email = await emailResp.json();
    expect(email.success).toBe(true);
    expect(email.email.subject).toBe('Welcome to DentaTrak');
    expect(email.email.to).toContain(NEW_OWNER_EMAIL);
    expect(email.email.html).toContain('User Guide');
    expect(email.email.text).toContain('User Guide');
  });

  test('additional practice creation does not send a welcome email', async ({ page, request }) => {
    const OWNER_EMAIL = `e2e.welcome.additional.${RUN_ID}@dentatrak.com`;
    const ctx = await playwrightRequest.newContext();

    const setup = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: {
        action: 'setup_test_user',
        email: OWNER_EMAIL,
        password: TEST_PASSWORD,
        practiceName: 'Additional Welcome Home Practice',
        firstName: 'E2E',
        lastName: 'Additional',
      },
    });
    const setupResult = await setup.json();
    expect(setupResult.success, `setup_test_user failed: ${JSON.stringify(setupResult)}`).toBe(true);

    // Upgrade to Control so a second owned practice is allowed
    const plan = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: { action: 'set_subscription_plan', email: OWNER_EMAIL, plan: 'control' },
    });
    expect(plan.status()).toBe(200);

    await ctx.dispose();

    // Log in as the existing owner
    await page.goto(getUrl('login.php'));
    await page.locator('#showEmailSignIn').click();
    await page.locator('#checkEmail').fill(OWNER_EMAIL);
    await page.locator('#emailContinueBtn').click();
    await page.locator('#loginPassword').waitFor({ state: 'visible' });
    await page.locator('#loginPassword').fill(TEST_PASSWORD);
    await page.locator('#loginSubmitBtn').click();
    await expect(page.locator('.main-header')).toBeVisible({ timeout: 15000 });

    // Start the additional-practice creation flow
    await page.locator('#practiceSwitcherBtn').click();
    await page.locator('#practiceSwitcherDropdown').waitFor({ state: 'visible' });
    await page.locator('#createNewPracticeItem').click();
    await page.waitForURL(/baa-acceptance\.php/);

    await request.post(getUrl('api/test-helpers.php'), { data: { action: 'clear_test_email_log' } });

    await page.locator('#legalName').fill(`Additional Practice ${RUN_ID}`);
    await page.locator('#practiceAddress').fill('456 Another Ave, Test City, TS 54321');
    await page.locator('#signerName').fill('E2E Additional');
    await page.locator('#signerTitle').fill('Owner');
    await page.locator('#authorizedToBind').check();
    await page.locator('#acceptBtn').click();

    await page.waitForURL(/main\.php/, { timeout: 30000 });

    // No welcome email should have been recorded for the additional practice
    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    expect(emailResp.status()).toBe(404);
  });
});

test.describe('In-app User Guide link', () => {
  test('appears in the user menu for an authenticated user', async ({ page }) => {
    const TEST_EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e.test@dentatrak.com';
    const TEST_PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'D3n7@Tr@k!9Zf#Qm2xL8V';

    await page.goto(getUrl('login.php'));
    await page.locator('#showEmailSignIn').click();
    await page.locator('#checkEmail').fill(TEST_EMAIL);
    await page.locator('#emailContinueBtn').click();
    await page.locator('#loginPassword').waitFor({ state: 'visible' });
    await page.locator('#loginPassword').fill(TEST_PASSWORD);
    await page.locator('#loginSubmitBtn').click();
    await expect(page.locator('.main-header')).toBeVisible({ timeout: 15000 });

    await page.locator('#userMenuToggle').click();
    const guideLink = page.locator('#userGuideLink');
    await expect(guideLink).toBeVisible();
    await expect(guideLink).toHaveAttribute('href', /resources\/user-guide/);
    await expect(guideLink).toHaveAttribute('target', '_blank');
  });
});
