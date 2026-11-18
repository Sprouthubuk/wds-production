// The site footer (every block, its links, phones) and the WDS logo's shape wherever it's used. See e2e/run.mjs.
import { test, expect } from '@playwright/test';

const PASSWORD = process.env.E2E_PASSWORD;

/** Every visible WDS logo on the page keeps its natural aspect ratio (900×126) within 2%. */
async function expectLogosUnstretched(page, where) {
  const logos = page.locator('img[src*="wds-logo"]');
  await expect(logos.first()).toBeAttached();
  const shapes = await logos.evaluateAll(async (imgs) => {
    const out = [];
    for (const img of imgs) {
      img.loading = 'eager';
      img.scrollIntoView();
      if (!img.complete || !img.naturalWidth) await img.decode().catch(() => {});
      const r = img.getBoundingClientRect();
      if (r.width && r.height) out.push({ rendered: r.width / r.height, natural: img.naturalWidth / img.naturalHeight, w: Math.round(r.width), h: Math.round(r.height) });
    }
    return out;
  });
  expect(shapes.length, `${where}: a visible logo`).toBeGreaterThan(0);
  for (const s of shapes) {
    expect(s.natural, `${where}: logo loaded`).toBeGreaterThan(0);
    expect(Math.abs(s.rendered / s.natural - 1), `${where}: logo rendered ${s.w}×${s.h}`).toBeLessThanOrEqual(0.02);
  }
}

test('the logo keeps its natural shape on the site and in admin, at phone and desktop widths', async ({ page }) => {
  for (const [w, h] of [[375, 812], [1440, 900]]) {
    await page.setViewportSize({ width: w, height: h });
    for (const p of ['/', '/about', '/competitions/e2e-bmw']) {
      await page.goto(p);
      await expectLogosUnstretched(page, `${p} @${w}`);
    }
  }
  await page.goto('/admin/sign-in');
  await page.fill('input[type=email]', 'admin@e2e.test');
  await page.fill('input[type=password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL((u) => !u.pathname.includes('sign-in'), { timeout: 15_000 });
  for (const p of ['/admin', '/admin/event-sales/stand']) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(p);
    await expectLogosUnstretched(page, p);
  }
});

test('the footer has every block, with the right links', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/about');
  const f = page.locator('footer[data-footer]');

  // Brand row: logo (no tagline), then the social links as icon + handle, in order, opening in a new tab.
  await expect(f.locator('[data-footer-brand] img[data-logo]')).toHaveAttribute('alt', 'WDS Competitions');
  await expect(f.locator('[data-footer-brand] .strap')).toHaveCount(0);
  const socials = f.locator('[data-socials] a');
  await expect(socials).toHaveText(['Instagram @wds_originals', 'YouTube @wds_originals', 'Instagram @wds_competitions']);
  expect(await socials.evaluateAll((as) => as.map((a) => [a.href, a.target, a.rel]))).toEqual([
    ['https://www.instagram.com/wds_originals/', '_blank', 'noopener'],
    ['https://www.youtube.com/@wds_originals', '_blank', 'noopener'],
    ['https://www.instagram.com/wds_competitions/', '_blank', 'noopener'],
  ]);
  await expect(f.locator('[data-social="facebook"]')).toHaveCount(0);

  // Link columns.
  for (const label of ['Competitions', 'Help', 'Company', 'Policies']) await expect(f.getByRole('navigation', { name: label })).toBeVisible();
  for (const [name, href] of [['Terms & conditions', '/terms'], ['Free postal entry', '/free-postal-entry'], ['Verify a draw', '/verify']]) {
    await expect(f.getByRole('link', { name, exact: true }).first()).toHaveAttribute('href', href);
  }

  // Contact: the config address (mailto) and the contact page.
  const contact = f.locator('[data-footer-contact]');
  await expect(contact.getByRole('heading', { name: 'Contact us' })).toBeVisible();
  await expect(contact.getByRole('link', { name: 'sales@wdsoriginals.com' })).toHaveAttribute('href', 'mailto:sales@wdsoriginals.com');
  await expect(contact.getByRole('link', { name: /Send us a message/ })).toHaveAttribute('href', '/contact');

  // Responsible play: 18+, our page, and independent support in a new tab.
  const rp = f.locator('[data-footer-rp]');
  await expect(rp.locator('.badge')).toHaveText('18+');
  await expect(rp.getByRole('heading', { name: 'Play responsibly' })).toBeVisible();
  await expect(rp.getByRole('link', { name: 'Responsible play', exact: true })).toHaveAttribute('href', '/responsible-play');
  for (const [name, href] of [['GamCare', 'https://www.gamcare.org.uk'], ['GambleAware', 'https://www.gambleaware.org']]) {
    const a = rp.getByRole('link', { name: new RegExp(name) });
    await expect(a).toHaveAttribute('href', href);
    await expect(a).toHaveAttribute('target', '_blank');
    await expect(a).toHaveAttribute('rel', 'noopener');
  }

  // Payments: Cashflows, four marks (loaded, with alt text, 20–24px tall), debit only.
  const pay = f.locator('[data-footer-payments]');
  await expect(pay).toContainText('Secure payments by Cashflows');
  await expect(pay).toContainText('Debit cards only');
  const marks = await pay.locator('img').evaluateAll(async (imgs) => Promise.all(imgs.map(async (i) => {
    i.loading = 'eager'; await i.decode().catch(() => {});
    return { alt: i.alt, loaded: i.naturalWidth > 0, h: i.getBoundingClientRect().height };
  })));
  expect(marks.map((m) => m.alt)).toEqual(['Visa', 'Mastercard', 'Apple Pay', 'Google Pay']);
  for (const m of marks) { expect(m.loaded, m.alt).toBe(true); expect(m.h, m.alt).toBeGreaterThanOrEqual(20); expect(m.h, m.alt).toBeLessThanOrEqual(24); }

  // Legal line; no ICO line while config('wds.ico_number') is unset; "Powered by RaffleLabs" (on by default).
  const legal = f.locator('[data-footer-legal]');
  await expect(legal).toContainText(`© ${new Date().getFullYear()} WDS Originals Limited`);
  await expect(legal).toContainText('Registered in England & Wales, company no. 13330886');
  await expect(legal).not.toContainText('Knoll'); // no address in the footer: it's in the T&Cs
  await expect(legal.getByRole('link', { name: 'Terms' })).toHaveAttribute('href', '/terms#company-details');
  await expect(legal).toContainText('18+ UK residents only.');
  await expect(legal).not.toContainText('ICO registration');
  await expect(legal.locator('[data-powered-by]')).toHaveText('Powered by RaffleLabs');
});

test('on a phone the footer fits, and every footer link is at least 44px tall', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/about');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  const small = await page.locator('footer[data-footer] a').evaluateAll((as) => as
    .map((a) => ({ text: a.textContent.trim() || a.getAttribute('aria-label'), h: a.getBoundingClientRect().height, w: a.getBoundingClientRect().width, right: a.getBoundingClientRect().right }))
    .filter((r) => r.h < 44 || r.right > window.innerWidth));
  expect(small).toEqual([]);
});
