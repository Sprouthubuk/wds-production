// Coming soon: the card, the competition page and the home page show the badge, the countdown to opening,
// the odds, tickets and price, and "Notify me when it's live" in place of Enter / Add to basket. Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const COMP = {
  id: 9, slug: 'bmw-e46-330ci-clubsport', title: 'BMW E46 330Ci Clubsport', strapline: 'Estoril Blue', category: 'cars', badge: 'Main event', status: 'coming_soon',
  images: [{ src: '/images/e46-clubsport-34.jpg', alt: 'BMW' }], description: ['A Clubsport.'], specs: [],
  ticketPrice: 14.95, totalTickets: 750, ticketsSold: 0, maxPerPerson: null, bundles: [{ qty: 3, price: 40 }, { qty: 10, price: 125 }],
  opensAt: '2026-12-01T19:00:00+00:00', notOpenYet: "BMW E46 330Ci Clubsport isn't open yet: entries open Tuesday 1 December 2026, 7:00pm (UK time).",
  closesAt: '2026-12-24T19:00:00+00:00', postalClosesAt: '2026-12-22T19:00:00+00:00', postalClosesAtCustom: null,
  drawAt: '2026-12-24T19:30:00+00:00', postalEntryEnabled: true, eventSalesEnabled: false, cashAlternative: 5000, buildVideoUrl: null,
  verityRef: 'VTY-SOON01', drawMethod: 'physical', postponement: null,
};

const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const EARLY = 'Entries open Tuesday 1 December 2026, 7:00pm (UK time). Envelopes received before then are counted once entries open.';

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const path = req.url.split('?')[0];
    if (path === '/api/me') return (req.headers.authorization ?? '') === 'Bearer admin' ? send(res, 200, { data: ADMIN }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/admin/postal/competitions') return send(res, 200, { data: [COMP] });
    if (path === '/api/admin/postal') return send(res, 200, { data: [{ id: 1, competition: COMP.title, name: 'Sam Lee', email: 'sam@example.com', receivedOn: '2026-11-20', receivedAt: '2026-11-20T09:15:00+00:00', status: 'held', reason: null, ticket: null }] });
    if (path === '/api/competitions') return send(res, 200, { data: [COMP] });
    if (path === `/api/competitions/${COMP.slug}`) return send(res, 200, { data: COMP });
    if (path === '/api/upcoming') return send(res, 200, { data: null });
    if (path === '/api/winners') return send(res, 200, { data: [] });
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 5200 + Math.floor(Math.random() * 300);
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
  return (await res.text()).replace(/<!--.*?-->/gs, '');
};

test('the competition page: badge, countdown, odds, prices and Notify me instead of Enter', async () => {
  const html = await page(`/competitions/${COMP.slug}`);
  assert.ok(html.includes('Coming soon'), 'badge');
  assert.ok(html.includes('Entries open in'), 'countdown');
  assert.ok(html.includes('data-countdown="2026-12-01T19:00:00+00:00"'), 'counts to opensAt');
  assert.ok(html.includes('1 in 750'), 'odds');
  assert.ok(html.includes('£14.95') && html.includes('£40<') && html.includes('£125<'), 'price and bundles');
  assert.ok(html.includes("Notify me when it&#39;s live") || html.includes("Notify me when it's live"), 'notify button');
  assert.ok(html.includes('Email me when WDS Competitions launch. Unsubscribe anytime.'), 'consent line');
  assert.ok(html.includes(`data-competition="${COMP.id}"`), 'the sign-up is for this competition');
  assert.ok(!html.includes('data-entry-panel'), 'no entry panel / Add to basket');
  assert.ok(html.includes('Tuesday 1 December 2026, 7:00pm'), 'opening time in UK time');
});

test('the home page: Launching 1st December, site-wide sign-up, and a coming-soon card with odds', async () => {
  const html = await page('/');
  assert.ok(html.includes('Launching 1st December'), 'hero headline');
  assert.ok(html.includes('id="first-access"'), 'site-wide sign-up');
  assert.ok(/First up: <strong[^>]*>BMW E46 330Ci Clubsport<\/strong>/.test(html), 'features the competition');
  assert.ok(html.includes('1 in 750'), 'odds on the card');
  assert.ok(html.includes(`/competitions/${COMP.slug}#notify`), 'card button goes to Notify me');
  assert.ok(!/Enter online<\/a>/.test(html.split('Two ways to enter')[0]), 'no Enter button on the card');
});

test('the competitions list shows the coming-soon card', async () => {
  const html = await page('/competitions');
  assert.ok(html.includes('Coming soon'));
  assert.ok(html.includes('Entries open'));
  assert.ok(html.includes('1 in 750'));
});

test('postal entry before opening: the competition page and the Free Postal Entry page say envelopes count once entries open', async () => {
  const comp = await page(`/competitions/${COMP.slug}`);
  assert.ok(comp.includes('How to enter by post'), 'the postal route is still offered');
  assert.ok(comp.includes(EARLY), 'competition page wording');
  const postal = await page('/free-postal-entry');
  assert.ok(postal.includes('BMW E46 330Ci Clubsport: must be received by'), 'its deadline is listed while coming soon');
  assert.ok(postal.includes('Tuesday 22 December 2026, 7:00pm'), 'the postal deadline');
  assert.ok(postal.includes(EARLY), 'Free Postal Entry page wording');
});

test('admin postal logging: a coming-soon competition can be chosen and the hint says the entry is held', async () => {
  const html = await page('/admin/postal', 'wds_admin=admin');
  assert.ok(html.includes('BMW E46 330Ci Clubsport (coming soon)'), 'offered, marked coming soon');
  assert.ok(html.includes('this entry is held and gets its ticket number when entries open (Tuesday 1 December 2026, 7:00pm)'), 'the hint');
  assert.ok(html.includes('pill--held'), 'held shows in the list');
});
