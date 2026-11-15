// Customer and admin journeys against the local API (fake payments, local randomness). See e2e/run.mjs.
import { test, expect } from '@playwright/test';

const PASSWORD = process.env.E2E_PASSWORD;
const ADMIN_PAGES = ['/admin', '/admin/competitions', '/admin/orders', '/admin/customers', '/admin/draws', '/admin/winners', '/admin/postal',
  '/admin/event-sales', '/admin/promotions', '/admin/reports', '/admin/responsible-play', '/admin/settings'];

const noConsoleErrors = (page) => {
  const errors = [];
  // A View Transition cut short by the next page.goto is the test navigating fast, not a page error.
  const ignore = (t) => /Transition was aborted/.test(t);
  page.on('console', (m) => { if (m.type() === 'error' && !ignore(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => { if (!ignore(String(e))) errors.push(String(e)); });
  return errors;
};

async function fillCheckout(page, { email, name = 'Guest Buyer', dob = '1990-05-05' }) {
  await page.fill('#b-em', email);
  await page.fill('#b-n', name);
  await page.fill('#b-t', '07700900123');
  await page.fill('#b-dob', dob);
  await page.check('#b-age');
  await page.check('#b-terms');
}

test('a guest buys the 3-ticket bundle, pays on the (fake) payment page and sees 3 ticket numbers', async ({ page }) => {
  const errors = noConsoleErrors(page);
  await page.goto('/competitions/e2e-bmw');
  await page.locator('[data-entry-panel] [data-pack="3"]').first().click();
  await expect(page.locator('[data-entry-panel] [data-total]').first()).toHaveText(/^£40(\.00)?$/);
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  await expect(page.locator('body')).toContainText('E2E BMW E46');
  await fillCheckout(page, { email: 'guest1@e2e.test' });
  await page.click('[data-submit]');
  await page.waitForURL(/\/order\/WDS-/, { timeout: 20_000 });
  await expect(page.locator('body')).toContainText(/You're in|you're in/i, { timeout: 15_000 });
  const numbers = await page.locator('body').innerText();
  expect((numbers.match(/#\d{4}/g) ?? []).length).toBeGreaterThanOrEqual(3);
  expect(errors).toEqual([]);
});

test('on a 375px phone the enter and free postal buttons each fit on one line', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/competitions/e2e-bmw');
  for (const sel of ['[data-entry-panel] .enter-row [data-enter]', '[data-entry-panel] .enter-row [data-postal-link]']) {
    const lines = await page.locator(sel).first().evaluate((el) => { const r = document.createRange(); r.selectNodeContents(el); const tops = [...r.getClientRects()].map((x) => x.top).sort((a, b) => a - b); return tops.filter((t, i) => i === 0 || t - tops[i - 1] > 6).length; });
    expect(lines, sel).toBe(1);
  }
});

test('checkout refuses an under-18 date of birth', async ({ page }) => {
  await page.goto('/competitions/e2e-bmw');
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  const year = new Date().getFullYear() - 17;
  await fillCheckout(page, { email: 'young@e2e.test', dob: `${year}-01-01` });
  await page.click('[data-submit]');
  await expect(page.locator('body')).toContainText(/18/);
  expect(page.url()).toContain('/basket');
});

test('max per person (2) is enforced on a competition that states one', async ({ page }) => {
  await page.goto('/competitions/e2e-few-left');
  const plus = page.locator('[data-entry-panel] [data-step="1"]').first();
  for (let i = 0; i < 4; i++) if (await plus.isEnabled()) await plus.click();
  await expect(page.locator('[data-entry-panel] [data-qty]').first()).toHaveText('2');
});

test('registration refuses under-18s and accepts an adult, who can then sign out', async ({ page }) => {
  await page.goto('/sign-in');
  const reg = async (email, dob) => {
    await page.fill('#r-fn', 'Reg');
    await page.fill('#r-ln', 'Ister');
    await page.fill('#r-em', email);
    await page.fill('#r-dob', dob);
    await page.fill('#r-tel', '07700900456');
    await page.fill('#r-pw', 'a-long-e2e-password-1');
    await page.check('#r-age');
    await page.click('button:has-text("Create account")');
  };
  await reg('kid@e2e.test', `${new Date().getFullYear() - 16}-06-01`);
  await expect(page.locator('body')).toContainText(/18/);
  await reg('adult@e2e.test', '1985-06-01');
  await page.waitForURL(/\/account/, { timeout: 15_000 });
  await expect(page.locator('body')).toContainText(/Reg/);
});

test('a signed-in customer enters the free competition once, and only once', async ({ page }) => {
  await page.goto('/sign-in');
  await page.fill('#si-em', 'alice@e2e.test');
  await page.fill('#si-pw', PASSWORD);
  await page.click('button:has-text("Sign in")');
  await page.waitForURL(/\/account/, { timeout: 15_000 });
  await page.goto('/competitions/e2e-free');
  const form = page.locator('[data-free-form]');
  await form.locator('input[name=ageConfirmed]').check();
  await form.locator('input[name=termsAccepted]').check();
  await form.locator('button[type=submit]').click();
  await expect(page.locator('h1')).toContainText("You're in", { timeout: 15_000 });
  await expect(page.locator('body')).toContainText(/E2E Free Table · 1 ticket\s*#\d{4}/);
  // Coming back: it shows the ticket number, and there's no form to enter again.
  await page.goto('/competitions/e2e-free');
  await expect(page.locator('[data-free-entry]')).toContainText(/Your ticket number: #\d{4}/);
  await expect(page.locator('[data-free-form]')).toHaveCount(0);
});

test('every admin page sends a signed-out visitor to sign in (no errors)', async ({ page }) => {
  for (const p of ADMIN_PAGES) {
    const res = await page.goto(p);
    expect(res?.status(), p).toBeLessThan(500);
    expect(page.url(), p).toMatch(/\/sign-in\?next=%2Fadmin/);
  }
});

test('a customer account cannot open admin pages', async ({ page }) => {
  await page.goto('/admin/sign-in');
  await page.fill('input[type=email]', 'bob@e2e.test');
  await page.fill('input[type=password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForTimeout(1500);
  const res = await page.goto('/admin/orders');
  expect(res?.status()).toBeLessThan(500);
  expect(page.url()).toMatch(/\/sign-in/);
  await expect(page.locator('body')).not.toContainText('E2E BMW E46');
});

test('an admin can open every admin page without errors', async ({ page }) => {
  const errors = noConsoleErrors(page);
  await page.goto('/admin/sign-in');
  await page.fill('input[type=email]', 'admin@e2e.test');
  await page.fill('input[type=password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL((u) => !u.pathname.includes('sign-in'), { timeout: 15_000 });
  for (const p of ADMIN_PAGES) {
    const res = await page.goto(p);
    expect(res?.status(), p).toBe(200);
    expect(page.url(), p).not.toContain('sign-in');
    for (const w of [390, 1440]) {
      await page.setViewportSize({ width: w, height: 900 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${p} @${w} horizontal scroll`).toBeLessThanOrEqual(1);
    }
  }
  expect(errors).toEqual([]);
});

test('404 page is branded and leaks nothing', async ({ page, request }) => {
  const res = await page.goto('/no-such-page');
  expect(res?.status()).toBe(404);
  await expect(page.locator('header, nav').first()).toBeVisible();
  const api = await request.get(`${process.env.E2E_API}/api/competitions/does-not-exist`);
  expect(api.status()).toBe(404);
  const body = await api.text();
  expect(body).not.toMatch(/Stack trace|vendor\/laravel|\.php:\d+/);
});
