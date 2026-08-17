/**
 * Global Setup for Playwright Tests
 * 
 * This runs ONCE before all tests to ensure the test account exists.
 * It creates the e2e test user account with practice and BAA completed.
 */

import { chromium, FullConfig } from '@playwright/test';

process.env.DENTATRAK_TEST_RECORD_EMAILS = process.env.DENTATRAK_TEST_RECORD_EMAILS || 'true';

const TEST_EMAIL = process.env.DENTATRAK_TEST_EMAIL || 'e2e-test@example.com';
const TEST_PASSWORD = process.env.DENTATRAK_TEST_PASSWORD || 'D3n7@Tr@k!9Zf#Qm2xL8V';
const BASE_URL = process.env.BASE_URL || 'http://localhost/DentaTrak';

async function globalSetup(config: FullConfig) {
  console.log('\n🔧 Global Setup: Ensuring test account exists...');
  console.log(`   Test email: ${TEST_EMAIL}`);
  console.log(`   Base URL: ${BASE_URL}`);
  
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  
  try {
    // Use the test-helpers endpoint to set up everything in one call
    // This creates the user, verifies email, creates practice, and accepts BAA
    console.log('   Setting up test user with practice and BAA...');
    
    const setupResponse = await page.request.post(`${BASE_URL}/api/test-helpers.php`, {
      data: {
        action: 'setup_test_user',
        email: TEST_EMAIL,
        password: TEST_PASSWORD,
        firstName: 'E2E',
        lastName: 'Test',
        practiceName: 'E2E Test Practice'
      }
    });
    
    if (setupResponse.status() === 403) {
      console.log('   ⚠ Test helpers not available (production mode or test mode disabled)');
      console.log('   ℹ Assuming test user already exists and is configured');
    } else if (setupResponse.status() === 404) {
      console.log('   ⚠ Test helpers endpoint not found');
      console.log('   ℹ Make sure api/test-helpers.php exists');
    } else {
      const setupResult = await setupResponse.json().catch(() => ({}));
      
      if (setupResult.success) {
        console.log('   ✓ Test user setup complete');
        console.log(`     User ID: ${setupResult.user_id}`);
        console.log(`     Practice ID: ${setupResult.practice_id}`);
      } else {
        console.log(`   ⚠ Setup response: ${setupResult.message || 'Unknown error'}`);
      }
    }
    
    // Verify login works
    console.log('   Verifying login...');
    
    const loginResponse = await page.request.post(`${BASE_URL}/api/auth-email.php`, {
      data: {
        action: 'login',
        email: TEST_EMAIL,
        password: TEST_PASSWORD
      }
    });
    
    const loginResult = await loginResponse.json().catch(() => ({}));
    
    if (loginResult.success) {
      console.log('   ✓ Login verification successful');
    } else {
      console.log(`   ⚠ Login verification: ${loginResult.message || 'Failed'}`);
      
      if (loginResult.requires_verification) {
        // Try to verify email directly
        console.log('   Attempting direct email verification...');
        const verifyResponse = await page.request.post(`${BASE_URL}/api/test-helpers.php`, {
          data: {
            action: 'verify_email',
            email: TEST_EMAIL
          }
        });
        const verifyResult = await verifyResponse.json().catch(() => ({}));
        if (verifyResult.success) {
          console.log('   ✓ Email verified');
        }
      }
    }
    
    console.log('✅ Global Setup Complete\n');
    
  } catch (error) {
    console.error('❌ Global Setup Error:', error);
    // Don't throw - let tests attempt to run anyway
    // The test user might already exist from a previous run
  } finally {
    await browser.close();
  }
}

export default globalSetup;
