// Every public page: loads (200), no console errors, no horizontal scroll at any width, no serious/critical axe
// issues, internal links all resolve. Screenshots go to qa/screenshots/ (gitignored) for the visual review.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const PAGES = [
  '/', '/competitions', '/competitions/e2e-bmw', '/competitions/e2e-few-left', '/competitions/e2e-free',
  '/basket', '/how-it-works', '/about', '/faqs', '/winners', '/live-draws', '/verify', '/free-postal-entry', '/contact',
  '/responsible-play', '/terms', '/privacy', '/refunds', '/complaints', '/website-terms', '/draw-process', '/our-commitment', '/cookies',
  '/sign-in', '/forgot-password', '/reset-password?token=x&email=a%40b.test',
];
const VIEWPORTS = [[375, 812], [390, 844], [768, 1024], [1280, 800], [1920, 1080]];
const shots = path.join(process.env.E2E_ROOT ?? '..', 'qa', 'screenshots');
mkdirSync(shots, { recursive: true });
const name = (p) => (p === '/' ? 'home' : p.replace(/^\//, '').replace(/[^a-z0-9]+/gi, '-'));
const findings = [];

for (const p of PAGES) {
  test(`page ${p}`, async ({ page }) => {
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    const res = await page.goto(p, { waitUntil: 'networkidle' });
    expect(res?.status(), `${p} status`).toBe(200);

    for (const [w, h] of VIEWPORTS) {
      await page.setViewportSize({ width: w, height: h });
      await page.waitForTimeout(100);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      if (overflow > 1) findings.push(`${p} @${w}: horizontal scroll ${overflow}px`);
      await page.screenshot({ path: path.join(shots, `${name(p)}-${w}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 844 });

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    for (const v of axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))) {
      findings.push(`${p}: axe ${v.impact} ${v.id} (${v.nodes.length}) ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(' | ')}`);
    }
    const small = await page.evaluate(() => [...document.querySelectorAll('a, button, input, select, textarea')]
      .filter((el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && (r.height < 24 || r.width < 24) && !el.closest('p, li, .body, .prose'); })
      .slice(0, 5).map((el) => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} "${(el.textContent ?? '').trim().slice(0, 20)}" ${Math.round(el.getBoundingClientRect().width)}x${Math.round(el.getBoundingClientRect().height)}`));
    if (small.length) findings.push(`${p}: small tap targets ${small.join('; ')}`);
    const meta = await page.evaluate(() => ({ title: document.title, desc: document.querySelector('meta[name=description]')?.getAttribute('content') ?? '', canonical: document.querySelector('link[rel=canonical]')?.getAttribute('href') ?? '', og: document.querySelector('meta[property="og:title"]')?.getAttribute('content') ?? '', icon: !!document.querySelector('link[rel~=icon]'), imgsNoAlt: [...document.images].filter((i) => !i.hasAttribute('alt')).length, broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.src).slice(0, 3) }));
    if (!meta.title) findings.push(`${p}: no <title>`);
    if (!meta.desc) findings.push(`${p}: no meta description`);
    if (!meta.canonical) findings.push(`${p}: no canonical`);
    if (!meta.og) findings.push(`${p}: no og:title`);
    if (!meta.icon) findings.push(`${p}: no favicon link`);
    if (meta.imgsNoAlt) findings.push(`${p}: ${meta.imgsNoAlt} images without alt`);
    if (meta.broken.length) findings.push(`${p}: broken images ${meta.broken.join(', ')}`);
    if (errors.length) findings.push(`${p}: console errors: ${errors.slice(0, 3).join(' | ')}`);
    expect(errors, `${p} console errors`).toEqual([]);
  });
}

test('internal links all resolve', async ({ page, request }) => {
  const seen = new Set();
  const bad = [];
  for (const p of PAGES) {
    await page.goto(p);
    const hrefs = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')));
    for (const h of hrefs) {
      if (!h || !h.startsWith('/') || h.startsWith('//') || h.startsWith('/admin') || h.startsWith('/api/')) continue;
      const u = h.split('#')[0];
      if (!u || seen.has(u)) continue;
      seen.add(u);
      const r = await request.get(u, { maxRedirects: 0 });
      if (r.status() >= 400) bad.push(`${u} (${r.status()}) linked from ${p}`);
    }
  }
  findings.push(...bad.map((b) => `broken link: ${b}`));
  expect(bad).toEqual([]);
});

test.afterAll(() => {
  writeFileSync(path.join(shots, 'findings.txt'), findings.join('\n') + '\n');
});
