// Client feedback before launch: the free postal route as one short line (address only on /free-postal-entry), the
// skill question in the basket and in Admin, the Instagram handle, the sitemap, and the decluttered competition page
// (long information in closed accordions). Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const base0 = {
  strapline: 'Estoril Blue', category: 'cars', badge: null, images: [{ src: '/images/e46-clubsport-34.jpg', alt: 'BMW' }], description: ['A Clubsport.'],
  specs: [{ label: 'Gearbox', value: 'ZF manual' }], ticketsSold: 0, opensAt: '2026-11-01T09:00:00+00:00', notOpenYet: null,
  closesAt: '2026-12-24T19:00:00+00:00', postalClosesAtCustom: null, drawAt: '2026-12-24T19:30:00+00:00', eventSalesEnabled: false,
  buildVideoUrl: null, verityRef: 'VTY-1', drawMethod: 'physical', postponement: null, status: 'live',
};
const PAID = { ...base0, id: 1, slug: 'bmw', title: 'BMW E46 330Ci Clubsport', ticketPrice: 14.95, totalTickets: 750, maxPerPerson: null,
  bundles: [{ qty: 3, price: 40 }], postalClosesAt: '2026-12-22T19:00:00+00:00', postalEntryEnabled: true, cashAlternative: 5000,
  skillQuestion: { question: 'What does BMW stand for?', options: ['British Motor Works', 'Bayerische Motoren Werke', 'Berlin Motor Wagons'] } };
const FREE = { ...base0, id: 2, slug: 'engine-table', title: 'Bespoke Built Bedside Engine Table', category: 'merch', ticketPrice: 0, totalTickets: 500,
  maxPerPerson: 1, bundles: [], postalClosesAt: null, postalEntryEnabled: false, cashAlternative: null, skillQuestion: null };
const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const DRAFT = { ...PAID, id: 3, slug: 'coilovers', title: 'BC Racing BR Series Coilovers', status: 'draft', verityRef: null, ticketPrice: 5,
  skillQuestion: { question: 'What part of a car do coilovers replace?', options: ['The brakes', 'The exhaust', 'The suspension (springs and dampers)', 'The gearbox'], correct: 2 } };
const ADDRESS = 'WDS Competitions – Free Entry'; // the first line of the free entry address (sample settings)

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const path = req.url.split('?')[0];
    if (path === '/api/me') return (req.headers.authorization ?? '') === 'Bearer admin' ? send(res, 200, { data: ADMIN }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/competitions') return send(res, 200, { data: [PAID, FREE] });
    if (path === `/api/competitions/${PAID.slug}`) return send(res, 200, { data: PAID });
    if (path === `/api/competitions/${FREE.slug}`) return send(res, 200, { data: FREE });
    if (path === `/api/admin/competitions/${DRAFT.id}`) return send(res, 200, { data: DRAFT });
    if (path.startsWith(`/api/admin/competitions/${DRAFT.id}/`)) return send(res, 200, { data: path.endsWith('history') ? [] : null });
    if (path === '/api/upcoming') return send(res, 200, { data: null });
    if (path === '/api/winners') return send(res, 200, { data: [] });
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 5500 + Math.floor(Math.random() * 300);
  base = `http://127.0.0.1:${port}`;
  site = spawn(process.execPath, [fileURLToPath(new URL('../dist/server/entry.mjs', import.meta.url))], {
    env: { ...process.env, TZ: 'America/New_York', API_URL: `http://127.0.0.1:${api.address().port}`, HOST: '127.0.0.1', PORT: String(port) },
    stdio: 'ignore',
  });
  for (let i = 0; i < 100; i++) {
    try { await fetch(`${base}/robots.txt`); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('site did not start');
});
after(() => { site?.kill(); api?.close(); });

const page = async (path, cookie) => {
  const res = await fetch(base + path, { redirect: 'manual', headers: cookie ? { cookie } : {} });
  assert.equal(res.status, 200, path);
  return (await res.text()).replace(/<!--.*?-->/gs, '').replace(/ data-astro-cid-[a-z0-9]+(="")?/g, '');
};
const footer = (html) => html.slice(html.indexOf('<footer'));

test('a paid competition page: one short free-route line, no postal address, key terms kept', async () => {
  const html = await page(`/competitions/${PAID.slug}`);
  assert.ok(html.includes('Free entry route available – see <a href="/free-postal-entry">free postal entry</a>'), 'the short line');
  assert.ok(!html.includes(ADDRESS), 'no postal address on the competition page (footer included)');
  assert.ok(!html.includes('How to enter by post'), 'no postal box');
  // Legally required information stays, just secondary: closing date, max entries, the free route and the T&Cs link.
  const terms = html.slice(html.indexOf('data-key-terms'), html.indexOf('</p>', html.indexOf('data-key-terms')));
  assert.ok(terms.includes('Closes 24 December at 19:00') && terms.includes('No limit per person') && terms.includes('href="/terms"'), terms);
  assert.ok(html.includes('Tuesday 22 December 2026, 7:00pm'), 'the postal deadline is still on the page (Draw details)');
});

test('the competition page puts long information in accordions, closed by default', async () => {
  const html = await page(`/competitions/${PAID.slug}`);
  for (const k of ['draw', 'cash', 'verify', 'terms']) assert.match(html, new RegExp(`<details data-acc="${k}"><summary>`), k);
  assert.ok(!/<details[^>]*\sopen/.test(html), 'every accordion and FAQ starts closed');
  // Price, odds, countdown and ticket selector are in the entry panel, which phones show straight after the photo
  // (grid areas 'gallery' 'panel' 'main'); the accordions are in the main column below it.
  const panel = html.slice(html.indexOf('id="top-panel"'), html.indexOf('</aside>'));
  for (const marker of ['£14.95', '1 in 750', 'class="countdown"', 'data-pack="3"', 'data-enter', 'data-free-route']) assert.ok(panel.includes(marker), marker);
  assert.ok(!panel.includes('<details'), 'no accordions in the panel');
  assert.ok(html.indexOf('data-main') < html.indexOf('data-more-info'), 'photo first');
  assert.ok(html.includes('drawn live by hand from numbered tokens'), 'draw method');
  assert.ok(html.includes('The winner can choose £5,000 cash instead'), 'cash alternative');
  assert.ok(html.includes('answer a multiple-choice question correctly before paying'), 'the skill question in the terms summary');
  assert.ok(html.includes('href="https://www.instagram.com/wds_competitions/"'), 'Instagram link');
  assert.ok(!html.includes('wds_originals'));
});

test('the free competition gets no free-route line and no skill question', async () => {
  const html = await page(`/competitions/${FREE.slug}`);
  assert.ok(!html.includes('Free entry route available'));
  assert.ok(!html.includes('multiple-choice question'));
});

test('/free-postal-entry has the address and instructions; the footer links to it without the address', async () => {
  const html = await page('/free-postal-entry');
  assert.ok(html.includes(ADDRESS), 'the address');
  assert.ok(html.includes('One entry per postcard or envelope'), 'one entry per envelope');
  assert.ok(html.includes('Tuesday 22 December 2026, 7:00pm'), 'the deadline');
  assert.ok(html.includes('first or second class post'), 'postage, as written');
  const foot = footer(await page('/faqs'));
  assert.ok(foot.includes('<a href="/free-postal-entry">Free postal entry</a>'), 'footer link');
  assert.ok(!foot.includes(ADDRESS), 'no postal address in the footer');
});

test('the T&Cs keep the free-entry clause, point to /free-postal-entry and mention the skill question; the FAQs explain it', async () => {
  const terms = await page('/terms');
  assert.ok(terms.includes('Skill question: to enter a paid competition online you must answer a multiple-choice question correctly'));
  assert.ok(terms.includes('Free entry route: every paid competition can also be entered free by post') && terms.includes('href="/free-postal-entry"'));
  assert.ok((await page('/faqs')).includes('Why is there a question before I pay?'));
});

test('the sitemap lists /free-postal-entry and the competitions; robots.txt points to it', async () => {
  const res = await fetch(`${base}/sitemap.xml`);
  assert.equal(res.headers.get('content-type'), 'application/xml; charset=utf-8');
  const xml = await res.text();
  assert.ok(xml.includes('<loc>https://wdscompetitions.co.uk/free-postal-entry</loc>'));
  assert.ok(xml.includes('<loc>https://wdscompetitions.co.uk/competitions/bmw</loc>'));
  assert.ok(!xml.includes('/admin') && !xml.includes('/basket') && !xml.includes('/account'));
  const robots = await (await fetch(`${base}/robots.txt`)).text();
  assert.ok(robots.includes('Sitemap: https://wdscompetitions.co.uk/sitemap.xml') && robots.includes('Disallow: /admin'));
});

test('the basket has the skill question area and sends the answers to checkout', async () => {
  const html = await page('/basket');
  assert.ok(html.includes('data-skill') && html.includes('Answer the question'));
});

test('admin: the skill question is editable on a draft and shown in the publish confirm', async () => {
  const html = await page(`/admin/competitions/${DRAFT.id}`, 'wds_admin=admin');
  assert.ok(html.includes('name="sqQuestion"') && html.includes('value="What part of a car do coilovers replace?"'));
  assert.ok(html.includes('value="The suspension (springs and dampers)"'));
  assert.match(html, /name="sqCorrect" value="2" checked/);
  assert.ok(html.includes('maximum per person and skill question are fixed'));
  assert.ok(html.includes('Skill question: &quot;What part of a car do coilovers replace?&quot; (correct answer: &quot;The suspension (springs and dampers)&quot;)'), 'in the confirm');
});
