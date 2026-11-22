// Uploads in a real browser, website to Laravel: winner ID and selfie, and an Admin competition image, as the pages send
// them. (Local API, see e2e/run.mjs. web/tests/uploads.test.mjs covers the live site's Origin and the refusals.)
import { test, expect } from '@playwright/test';

const PASSWORD = process.env.E2E_PASSWORD;
const png = { name: 'photo.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') };

async function signIn(page, email, next) {
  await page.goto(`/sign-in?next=${encodeURIComponent(next)}`);
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', PASSWORD);
  await page.click('main button[type=submit]');
  await page.waitForURL((u) => !u.pathname.includes('sign-in'), { timeout: 15_000 });
}

test('a winner uploads their ID and selfie', async ({ page }) => {
  await signIn(page, 'bob@e2e.test', '/account');
  await page.goto('/account#verify');
  await page.setInputFiles('#v-id', png);
  await page.setInputFiles('#v-self', png);
  const form = page.locator('[data-account-form="id-upload"]');
  await form.getByRole('button', { name: 'Upload securely' }).click();
  await expect(form.locator('[data-msg]')).toContainText('review your ID');
});

test('staff upload a competition image', async ({ page }) => {
  await signIn(page, 'admin@e2e.test', '/admin');
  await page.goto('/admin/competitions/new');
  await page.setInputFiles('[data-upload]', png);
  await expect(page.locator('[data-images] [data-src]')).toHaveCount(1);
  await expect(page.locator('[data-images] [data-src]')).toHaveValue(/\/storage\/competitions\//);
});

test('staff upload a winner photo', async ({ page }) => {
  await signIn(page, 'admin@e2e.test', '/admin');
  await page.goto('/admin/winners');
  const add = page.locator('form[data-admin-form="/api/admin/winners"]');
  await add.locator('[name=displayName]').fill('Photo W.');
  await add.locator('[name=prize]').fill('Test prize');
  await add.locator('[name=drawnAt]').fill('2025-01-01');
  await add.locator('button[type=submit]').click();
  const form = page.locator('tr', { has: page.locator('input[value="Photo W."]') }).locator('form[data-admin-form$="/image"]');
  await expect(form).toBeVisible({ timeout: 10_000 });
  await form.locator('input[type=file]').setInputFiles(png);
  const saved = page.waitForResponse((r) => r.url().endsWith('/image') && r.request().method() === 'POST');
  await form.getByRole('button', { name: 'Upload winner photo' }).click();
  expect((await saved).status()).toBe(200);
});
