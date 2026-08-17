import { test, expect } from '@playwright/test';
import { getUrl, BASE_URL } from './helpers/login';
import { request as playwrightRequest } from '@playwright/test';

const TEST_PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'D3n7@Tr@k!9Zf#Qm2xL8V';

test.describe.serial('Practice invitation email', () => {
  const RUN_ID = Date.now();
  const OWNER_EMAIL = `e2e.invite.owner.${RUN_ID}@dentatrak.com`;
  const INVITED_EMAIL = `e2e.invite.member.${RUN_ID}@dentatrak.com`;
  let testPracticeId: any = null;

  async function setupOwner() {
    const ctx = await playwrightRequest.newContext();
    const setup = await ctx.post(`${BASE_URL}/api/test-helpers.php`, {
      data: {
        action: 'setup_test_user',
        email: OWNER_EMAIL,
        password: TEST_PASSWORD,
        practiceName: `Invite Practice ${RUN_ID}`,
        firstName: 'E2E',
        lastName: 'Owner',
      },
    });
    const result = await setup.json();
    expect(result.success, `setup_test_user failed: ${JSON.stringify(result)}`).toBe(true);
    testPracticeId = result.practice_id;
    await ctx.dispose();
    return result;
  }

  async function clearTestArtifacts(request: any) {
    await request.post(getUrl('api/test-helpers.php'), { data: { action: 'clear_email_failure' } });
    await request.post(getUrl('api/test-helpers.php'), { data: { action: 'clear_test_email_log' } });
  }

  test.beforeEach(async ({ request }) => {
    await clearTestArtifacts(request);
  });

  async function loginAsOwner(page: any) {
    await page.goto(getUrl('login.php'));
    await page.locator('#showEmailSignIn').click();
    await page.locator('#checkEmail').fill(OWNER_EMAIL);
    await page.locator('#emailContinueBtn').click();
    await page.locator('#loginPassword').waitFor({ state: 'visible' });
    await page.locator('#loginPassword').fill(TEST_PASSWORD);
    await page.locator('#loginSubmitBtn').click();
    await expect(page.locator('.main-header')).toBeVisible({ timeout: 15000 });
  }

  async function callSaveSettings(page: any, payload: any) {
    const csrfToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const result = await page.evaluate(async ({ url, body, token }: any) => {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify(body),
      });
      return { status: res.status, json: await res.json() };
    }, { url: getUrl('api/save-settings.php'), body: payload, token: csrfToken });
    return result;
  }

  test('adding a new practice user sends one notification email', async ({ page, request }) => {
    await setupOwner();
    await loginAsOwner(page);


    const payload = {
      adminUsers: [],
      gmailUsers: [INVITED_EMAIL],
    };

    const result = await callSaveSettings(page, payload);
    expect(result.json.success).toBe(true);

    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    await expect(emailResp).toBeOK();
    const email = await emailResp.json();
    expect(email.success).toBe(true);
    expect(email.email.to).toContain(INVITED_EMAIL);
    expect(email.email.subject).toContain(`Invite Practice ${RUN_ID}`);
    expect(email.email.html).toContain(`Invite Practice ${RUN_ID}`);
    expect(email.email.text).toContain(`Invite Practice ${RUN_ID}`);
    expect(email.email.html).toContain(BASE_URL);
    expect(email.email.html).toContain('resources/user-guide');
    expect(email.email.html).toContain('support@dentatrak.com');
  });

  test('saving the same practice user again does not send another notification', async ({ page, request }) => {
    await loginAsOwner(page);


    const payload = {
      adminUsers: [],
      gmailUsers: [INVITED_EMAIL],
    };

    const result = await callSaveSettings(page, payload);
    expect(result.json.success).toBe(true);

    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    expect(emailResp.status()).toBe(404);
  });

  test('adding a user already registered with DentaTrak also sends a notification', async ({ page, request }) => {
    const existingEmail = `e2e.invite.existing.${RUN_ID}@dentatrak.com`;

    // Create a standalone registered user
    const ctx = await playwrightRequest.newContext();
    const register = await ctx.post(`${BASE_URL}/api/auth-email.php`, {
      data: {
        action: 'register',
        email: existingEmail,
        password: TEST_PASSWORD,
        confirmPassword: TEST_PASSWORD,
        firstName: 'E2E',
        lastName: 'Existing',
      },
    });
    await expect(register).toBeOK();
    await ctx.post(`${BASE_URL}/api/test-helpers.php`, { data: { action: 'verify_email', email: existingEmail } });
    await ctx.dispose();

    await loginAsOwner(page);


    const payload = {
      adminUsers: [existingEmail],
      gmailUsers: [],
    };

    const result = await callSaveSettings(page, payload);
    expect(result.json.success).toBe(true);

    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    await expect(emailResp).toBeOK();
    const email = await emailResp.json();
    expect(email.success).toBe(true);
    expect(email.email.to).toContain(existingEmail);
    expect(email.email.subject).toContain(`Invite Practice ${RUN_ID}`);
    expect(email.email.html).toContain('Hi E2E,');
  });

  test('email-send failure does not undo the successfully created practice membership', async ({ page, request }) => {
    const failureEmail = `e2e.invite.failure.${RUN_ID}@dentatrak.com`;
    await setupOwner();
    await loginAsOwner(page);

    await request.post(getUrl('api/test-helpers.php'), { data: { action: 'force_email_failure' } });

    const payload = {
      adminUsers: [],
      gmailUsers: [failureEmail],
    };

    const result = await callSaveSettings(page, payload);
    expect(result.json.success).toBe(true);

    const emailResp = await request.post(getUrl('api/test-helpers.php'), { data: { action: 'get_last_app_email' } });
    expect(emailResp.status()).toBe(404);

    const membership = await request.post(getUrl('api/test-helpers.php'), {
      data: { action: 'get_practice_user', email: failureEmail, practice_id: testPracticeId },
    });
    await expect(membership).toBeOK();
    const membershipJson = await membership.json();
    expect(membershipJson.success).toBe(true);
    expect(membershipJson.practice_user).toBeDefined();
  });
});
