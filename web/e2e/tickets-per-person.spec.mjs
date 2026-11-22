import { test, expect } from '@playwright/test';

// Its own file, and last: basket checks and sign-ins share one per-IP rate limit, which journeys.spec nearly uses up.
const PASSWORD = process.env.E2E_PASSWORD;

test('the maximum is by the ticket selector, and the basket stops at it', async ({ page }) => {
  await page.goto('/competitions/e2e-few-left');
  const plus = page.locator('[data-entry-panel] [data-step="1"]').first();
  for (let i = 0; i < 4; i++) await plus.click();
  await expect(page.locator('[data-entry-panel] [data-qty]').first()).toHaveText('2');
  await expect(page.locator('[data-max-line]')).toHaveText('Max 2 tickets per person (online and postal combined)');
  // The basket stops there too, says so, and brings down a quantity set before (or around) the maximum.
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  await expect(page.locator('[data-line-max]')).toHaveText('Max 2 tickets per person (online and postal combined).');
  await expect(page.locator('.line [data-d="1"]')).toBeDisabled();
  await page.evaluate(() => { const l = JSON.parse(localStorage.getItem('wds_basket_v1')); l[0].qty = 4; l[0].maxPerPerson = null; localStorage.setItem('wds_basket_v1', JSON.stringify(l)); });
  await page.reload();
  await expect(page.locator('.line .step span')).toHaveText('2');
  await expect(page.locator('.line [data-d="1"]')).toBeDisabled();
});

test('a signed-in customer who holds some can only add what is left, on the page and in the basket', async ({ page }) => {
  await page.goto('/sign-in');
  await page.fill('#si-em', 'alice@e2e.test');
  await page.fill('#si-pw', PASSWORD);
  await page.click('button:has-text("Sign in")');
  await page.waitForURL(/\/account/, { timeout: 15_000 });
  await page.goto('/competitions/e2e-capped');
  await expect(page.locator('[data-max-line]')).toHaveText('Max 5 tickets per person (online and postal combined)');
  await expect(page.locator('[data-can-add]')).toHaveText('You can add up to 2 more'); // seeded with 2 online and 1 postal
  const plus = page.locator('[data-entry-panel] [data-step="1"]').first();
  for (let i = 0; i < 4; i++) await plus.click();
  await expect(page.locator('[data-entry-panel] [data-qty]').first()).toHaveText('2');
  // Added twice: the basket still stops at what's left.
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  await page.goto('/competitions/e2e-capped');
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  await expect(page.locator('.line .step span')).toHaveText('2');
  await expect(page.locator('[data-line-max]')).toHaveText('Max 5 tickets per person (online and postal combined). You can add up to 2 more.');
  await expect(page.locator('.line [data-d="1"]')).toBeDisabled();
});
