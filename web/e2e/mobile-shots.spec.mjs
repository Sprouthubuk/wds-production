// Before/after screenshots of every public page at 375px, for a PR's visual review. Only runs when SHOTS is set
// (a folder name under qa/, e.g. SHOTS=before npm run e2e -- mobile-shots.spec.mjs). Full-page and first-screen.
import { test } from '@playwright/test';
import path from 'node:path';

const PASSWORD = process.env.E2E_PASSWORD;
const dir = process.env.SHOTS ? path.join(process.env.E2E_ROOT ?? '..', 'qa', 'mobile', process.env.SHOTS) : null;
const PAGES = [['home', '/'], ['competition', '/competitions/e2e-bmw'], ['competition-free', '/competitions/e2e-free'], ['faqs', '/faqs'],
  ['how-it-works', '/how-it-works'], ['verify', '/verify'], ['free-postal-entry', '/free-postal-entry']];

test.skip(!dir, 'set SHOTS to take screenshots');
test.use({ viewport: { width: 375, height: 812 } });

async function shoot(page, name) {
  await page.waitForTimeout(400);
  // Reveal-on-scroll content: show it all so the full-page shot isn't half empty.
  await page.addStyleTag({ content: '[data-reveal],[data-reveal-group] > *{opacity:1!important;transform:none!important}' });
  await page.screenshot({ path: path.join(dir, `${name}-fold.png`) });
  await page.screenshot({ path: path.join(dir, `${name}-full.png`), fullPage: true });
}

test('public pages at 375px', async ({ page }) => {
  test.setTimeout(120_000);
  for (const [name, url] of PAGES) {
    await page.goto(url);
    await shoot(page, name);
  }
  // Basket with tickets in it, then the account page (signed in).
  await page.goto('/competitions/e2e-bmw');
  await page.locator('[data-entry-panel] [data-enter]').first().click();
  await page.waitForURL(/\/basket/);
  // The skill question arrives with the basket check; wait for it (if this competition has one) before the shot.
  await page.locator('[data-skill] input').first().waitFor({ timeout: 5000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  await shoot(page, 'basket');
  await page.goto('/sign-in');
  await page.fill('#si-em', 'alice@e2e.test');
  await page.fill('#si-pw', PASSWORD);
  await page.click('button:has-text("Sign in")');
  await page.waitForURL(/\/account/, { timeout: 15_000 });
  await shoot(page, 'account');
});
