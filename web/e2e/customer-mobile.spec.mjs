// The signed-in and order pages at every width: no sideways scroll, no console errors, and screenshots for the
// visual review (qa/screenshots, gitignored). Runs against the local API with fake payments. See e2e/run.mjs.
import { test, expect } from '@playwright/test';
import path from 'node:path';

const PASSWORD = process.env.E2E_PASSWORD;
const WIDTHS = [[375, 812], [390, 844], [768, 1024], [1280, 800], [1920, 1080]];
const TABS = ['overview', 'entries', 'orders', 'wallet', 'details', 'verify', 'limits'];
const shots = path.join(process.env.E2E_ROOT ?? '..', 'qa', 'screenshots');

async function check(page, name, errors) {
  for (const [w, h] of WIDTHS) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(150);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `${name} scrolls sideways at ${w}px`).toBeLessThanOrEqual(0);
    await page.screenshot({ path: path.join(shots, `${name}-${w}.png`) });
  }
  expect(errors, name).toEqual([]);
}

test('order page and every account tab fit every width', async ({ page }) => {
  test.setTimeout(120_000);
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/Transition was aborted/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));

  await page.goto('/sign-in');
  await page.fill('#si-em', 'bob@e2e.test');
  await page.fill('#si-pw', PASSWORD);
  await page.click('button:has-text("Sign in")');
  await page.waitForURL(/\/account/, { timeout: 15_000 });

  // A paid order, so the order page, entries and orders tabs have rows in them.
  await page.goto('/competitions/e2e-bmw');
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  for (const [sel, v] of [['#b-t', '07700900123'], ['#b-dob', '1990-01-01']]) {
    if (await page.locator(sel).isEditable().catch(() => false) && !(await page.inputValue(sel))) await page.fill(sel, v);
  }
  await page.check('#b-age');
  await page.check('#b-terms');
  await page.click('[data-submit]');
  await page.waitForURL(/\/order\/WDS-/, { timeout: 20_000 });
  await expect(page.locator('body')).toContainText(/You're in/i, { timeout: 15_000 });
  await check(page, 'order-paid', errors);

  for (const tab of TABS) {
    await page.goto(`/account#${tab}`);
    await page.locator(`[data-tab="${tab}"]`).click();
    await check(page, `account-${tab}`, errors);
  }
});

test('small text links are still 24px tap targets on a phone (WCAG 2.2)', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/competitions/e2e-bmw');
  for (const sel of ['.announce a', '.crumbs a', '.site-footer nav a']) {
    const heights = await page.locator(sel).evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
    expect(heights.length, sel).toBeGreaterThan(0);
    for (const h of heights) expect(h, `${sel} is ${h}px tall`).toBeGreaterThanOrEqual(24);
  }
});
