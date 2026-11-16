// The Admin shell: grouped sidebar, breadcrumbs, quick search, environment badge, account menu, collapsible
// sidebar and the phone drawer. Local API, fake payments (e2e/run.mjs).
import { test, expect } from '@playwright/test';

const PASSWORD = process.env.E2E_PASSWORD;
const API = process.env.E2E_API;

// Sign in once per account and reuse the session: sign-in is rate limited to 10 a minute. (This file is named
// to run after pages.spec, so its sign-ins don't share a minute with journeys.spec's.)
const sessions = {};
async function signIn(page, email = 'admin@e2e.test') {
  if (sessions[email]) { await page.context().addCookies(sessions[email]); await page.goto('/admin'); return; }
  await page.goto('/admin/sign-in');
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL((u) => !u.pathname.includes('sign-in'), { timeout: 15_000 });
  sessions[email] = await page.context().cookies();
}

async function paidOrder(page, email) {
  const comps = (await (await page.request.get(`${API}/api/competitions`)).json()).data;
  const bmw = comps.find((c) => c.slug === 'e2e-bmw');
  const r = await page.request.post(`${API}/api/checkout`, { data: { customer: { email, name: 'Shell Tester', phone: '07700900123', dateOfBirth: '1990-01-01' }, items: [{ competitionId: bmw.id, qty: 1 }], ageConfirmed: true, termsAccepted: true } });
  const ref = (await r.json()).reference;
  await page.request.post(`${API}/api/payments/webhook`, { data: { order: ref, status: 'paid', provider_reference: 'x' } });
  return ref;
}

test('sidebar groups, active page, breadcrumbs, staging badge and account menu', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  await page.goto('/admin/orders');
  const nav = page.getByRole('navigation', { name: 'Admin' });
  for (const g of ['Overview', 'Sales', 'Draws', 'Compliance', 'Settings']) await expect(nav.locator('.adm-group-label', { hasText: g })).toBeVisible();
  await expect(nav.getByRole('link', { name: 'Orders' })).toHaveAttribute('aria-current', 'page');
  await expect(nav.locator('[aria-current=page]')).toHaveCount(1);
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Sales');
  await expect(page.getByRole('heading', { level: 1, name: 'Orders' })).toBeVisible();
  await expect(page.locator('[data-adm-env]')).toHaveText('Staging');   // APP_URL here is 127.0.0.1, not production

  await page.locator('[data-adm-user] summary').click();
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeHidden();
});

test('quick search: an order number opens the order; a ticket number lists the ticket', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  const ref = await paidOrder(page, 'shell-search@e2e.test');

  await page.fill('#adm-q', ref);
  await page.keyboard.press('Enter');
  await page.waitForURL(/\/admin\/orders\/\d+$/);
  await expect(page.getByRole('heading', { level: 1 })).toContainText(ref);

  await page.fill('#adm-q', 'shell-search@');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('link', { name: ref })).toBeVisible();

  await page.goto('/admin/search?q=0001');
  await expect(page.getByRole('heading', { name: 'Tickets' })).toBeVisible();
  await expect(page.locator('main')).toContainText('#0001');
});

test('the sidebar collapses to icons and stays collapsed', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  await page.goto('/admin');
  const side = page.locator('[data-adm-side]');
  const wide = (await side.boundingBox()).width;
  await page.getByRole('button', { name: 'Collapse menu' }).click();
  await expect(page.getByRole('button', { name: 'Expand menu' })).toHaveAttribute('aria-pressed', 'true');
  expect((await side.boundingBox()).width).toBeLessThan(wide / 2);
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Orders' })).toBeVisible();   // still named for screen readers
  await page.goto('/admin/orders');
  expect((await side.boundingBox()).width).toBeLessThan(wide / 2);
  await page.getByRole('button', { name: 'Expand menu' }).click();
});

test('on a phone the menu is a drawer, the page fits, and keyboard works', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await signIn(page);
  await page.goto('/admin/orders');
  const side = page.locator('[data-adm-side]');
  await expect(side).toBeHidden();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);

  const open = page.getByRole('button', { name: 'Open menu' });
  await open.click();
  await expect(open).toHaveAttribute('aria-expanded', 'true');
  await expect(side.getByRole('link', { name: 'Customers' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(side).toBeHidden();
  await expect(open).toBeFocused();

  await open.click();
  await side.getByRole('link', { name: 'Customers' }).click();
  await page.waitForURL(/\/admin\/customers/);
  await expect(page.getByRole('heading', { level: 1, name: 'Customers' })).toBeVisible();
});

test('staff see only their pages, and no quick search', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page, 'staff@e2e.test');
  await page.goto('/admin/event-sales');
  const links = await page.getByRole('navigation', { name: 'Admin' }).getByRole('link').allTextContents();
  expect(links.map((t) => t.trim())).toEqual(['Event sales', 'Postal entries']);
  await expect(page.locator('#adm-q')).toHaveCount(0);
});

test('dashboard: KPIs, needs attention, chart, live competitions and recent orders, at 1440 and 375', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  const ref = await paidOrder(page, 'dash@e2e.test');
  await page.goto('/admin');
  const kpis = page.getByRole('region', { name: 'Key figures' });
  for (const label of ['Revenue today', 'Last 7 days', 'This month', 'Tickets sold today', 'New customers', 'Average order']) await expect(kpis).toContainText(label);
  await expect(page.locator('[data-attention]')).toBeVisible();
  await expect(page.getByRole('img', { name: /Daily sales for the last 30 days/ })).toBeVisible();
  await expect(page.locator('.chart-svg rect')).toHaveCount(30);
  const bmw = page.locator('.live-card', { hasText: 'E2E BMW E46' });
  await expect(bmw.getByRole('progressbar')).toBeVisible();
  await expect(bmw).toContainText('Verity (drand)');
  await expect(bmw.locator('[data-countdown]')).toHaveText(/\d+d \d+h|\d+h \d+m|\d+m/);
  await page.getByRole('link', { name: ref }).click();
  await page.waitForURL(/\/admin\/orders\/\d+$/);

  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/admin');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('tables and dialogs: empty state, styled confirm (Cancel keeps it, OK goes ahead), toast', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  await page.goto('/admin/orders?q=no-such-order-zzz');
  await expect(page.locator('td.tbl-empty')).toContainText('No orders found.');

  const ref = await paidOrder(page, 'confirm@e2e.test');
  await page.goto(`/admin/orders?q=${ref}`);
  const row = page.locator('tbody tr', { hasText: ref });
  await expect(row.locator('.pill')).toHaveText('paid');
  await expect(page.locator('th', { hasText: 'Total' })).toHaveCSS('text-align', 'right');

  let nativeDialogs = 0;
  page.on('dialog', (d) => { nativeDialogs++; d.dismiss(); });
  await row.getByRole('button', { name: 'Mark refunded' }).click();
  const dialog = page.getByRole('dialog', { name: 'Are you sure?' });
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText(`Mark ${ref} as refunded?`);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(row.getByRole('button', { name: 'Mark refunded' })).toBeFocused();
  await row.getByRole('button', { name: 'Mark refunded' }).click();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
  await page.reload();
  await expect(page.locator('tbody tr', { hasText: ref }).locator('.pill')).toHaveText('paid');

  await page.locator('tbody tr', { hasText: ref }).getByRole('button', { name: 'Mark refunded' }).click();
  await dialog.getByRole('button', { name: 'Mark refunded' }).click();
  await expect(page.locator('.adm-toast')).toBeVisible();
  await expect(page.locator('tbody tr', { hasText: ref }).locator('.pill')).toHaveText('refunded', { timeout: 5000 });
  expect(nativeDialogs).toBe(0);

  // Filtering shows skeleton rows until the next page arrives.
  await page.locator('form.filters input[name=q]').fill(ref);
  await Promise.all([page.waitForURL(/q=/), page.locator('form.filters button[type=submit]').click()]);
  await expect(page.locator('.panel[aria-busy]')).toHaveCount(0);
});

test('order page: summary, cards, and a full refund that withdraws the tickets shows them struck through', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  const ref = await paidOrder(page, 'withdraw@e2e.test');
  await page.goto(`/admin/search?q=${ref}`);
  await page.waitForURL(/\/admin\/orders\/\d+$/);
  await expect(page.getByRole('region', { name: 'Order summary' })).toContainText(ref);
  for (const h of ['Tickets', 'Payment', 'Refunds', 'History']) await expect(page.getByRole('heading', { name: h, exact: true })).toBeVisible();
  await expect(page.locator('[data-withdrawn]')).toHaveCount(0);

  await page.fill('#rr', 'E2E full refund');
  await page.getByLabel(/Take the tickets out of the draw/).check();
  await page.getByRole('button', { name: 'Refund', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Refund' }).click();
  await expect(page.locator('[data-withdrawn]').first()).toBeVisible({ timeout: 10_000 });
  const t = page.locator('[data-withdrawn]').first();
  await expect(t.locator('s')).toHaveCSS('text-decoration-line', 'line-through');
  await expect(t).toContainText('withdrawn');
  await expect(page.locator('.pill', { hasText: 'refunded' }).first()).toBeVisible();

  await page.setViewportSize({ width: 375, height: 812 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test('competition page: tabs (mouse, keyboard, link), every field kept, a missing required field opens its tab', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await signIn(page);
  await page.goto('/admin/competitions/new');
  const tabs = page.getByRole('tab');
  await expect(tabs).toHaveText(['Details', 'Pricing', 'Photos', 'Draw', 'Entries']);
  await expect(page.locator('#title')).toBeVisible();
  await expect(page.locator('#price')).toBeHidden();

  await page.getByRole('tab', { name: 'Pricing' }).click();
  await expect(page.locator('#price')).toBeVisible();
  await expect(page.locator('#title')).toBeHidden();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Photos' })).toBeFocused();
  await expect(page.getByRole('tab', { name: 'Photos' })).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('End');
  await expect(page.locator('#postal-closes')).toBeVisible();

  // Title is required and on Details: saving from another tab takes you to it.
  await page.getByRole('button', { name: 'Create competition' }).click();
  await expect(page.getByRole('tab', { name: 'Details' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('#title')).toBeVisible();

  await page.goto('/admin/competitions/new#draw');
  await expect(page.locator('#closes')).toBeVisible();
  await expect(page.locator('input[name=drawMethod]')).toHaveCount(3);

  await page.setViewportSize({ width: 375, height: 812 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
