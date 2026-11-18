// FAQs open only what a link points at: plain /faqs is all closed; /faqs#free-entry opens just that question.
import { test, expect } from '@playwright/test';

test('/faqs shows every question closed', async ({ page }) => {
  await page.goto('/faqs');
  const faqs = page.locator('details[data-faq]');
  expect(await faqs.count()).toBeGreaterThan(10);
  expect(await faqs.evaluateAll((ds) => ds.filter((d) => d.open).length)).toBe(0);
});

test('/faqs#free-entry opens only "Can I enter for free?", and a later hash link opens that one', async ({ page }) => {
  await page.goto('/faqs#free-entry');
  const open = () => page.locator('details[data-faq]').evaluateAll((ds) => ds.filter((d) => d.open).map((d) => d.id));
  await expect.poll(open).toEqual(['free-entry']);
  await expect(page.locator('#free-entry summary')).toHaveText('Can I enter for free?');
  await expect(page.locator('#free-entry')).toBeInViewport();

  await page.goto('/faqs#how-do-i-pay');
  await expect.poll(open).toEqual(['how-do-i-pay']);
});

test('the competition page links to the FAQ page', async ({ page }) => {
  await page.goto('/competitions/e2e-bmw');
  await page.getByRole('link', { name: 'All FAQs →' }).click();
  await expect(page).toHaveURL(/\/faqs$/);
  expect(await page.locator('details[data-faq]').evaluateAll((ds) => ds.filter((d) => d.open).length)).toBe(0);
});
