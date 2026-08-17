import { test, expect } from '@playwright/test';
import { getUrl, BASE_URL } from './helpers/login';

test.describe('Public User Guide endpoint', () => {
  test('clean /resources/user-guide serves the PDF with noindex nofollow headers and no authentication required', async ({ request }) => {
    const response = await request.get(getUrl('resources/user-guide'));

    await expect(response).toBeOK();
    expect(response.headers()['content-type']).toBe('application/pdf');
    expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
    expect(response.headers()['content-disposition']).toContain('inline; filename="DentaTrak User Guide.pdf"');
  });

  test('direct /resources/DentaTrak User Guide.pdf is also noindex nofollow', async ({ request }) => {
    const response = await request.get(BASE_URL + '/resources/DentaTrak%20User%20Guide.pdf');

    await expect(response).toBeOK();
    expect(response.headers()['content-type']).toBe('application/pdf');
    expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
  });
});
