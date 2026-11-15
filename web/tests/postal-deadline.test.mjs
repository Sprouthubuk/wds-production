// A competition closing 24 December 2026 at 7:00pm UK time, no postal override: every page shows the postal
// deadline as "Tuesday 22 December 2026, 7:00pm" (the API's emails: tests/Feature/PostalDeadlineDisplayTest.php).
// The site runs with TZ=America/New_York so a server not on UK time can't shift it. Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const EXPECTED = 'Tuesday 22 December 2026, 7:00pm';

// What the API sends for it (CompetitionResource): UTC; 24 Dec is GMT, so 19:00 UK = 19:00Z.
const COMP = {
  id: 7, slug: 'bc-racing-coilovers', title: 'BC Racing coilovers', strapline: 'Fully adjustable', category: 'parts', badge: null, status: 'live',
  images: [{ src: '/images/bmw-front.jpg', alt: 'Coilovers' }], description: ['A set of coilovers.'], specs: [],
  ticketPrice: 2, totalTickets: 1000, ticketsSold: 10, maxPerPerson: null, bundles: [], opensAt: null,
  closesAt: '2026-12-24T19:00:00+00:00', postalClosesAt: '2026-12-22T19:00:00+00:00', postalClosesAtCustom: null,
  drawAt: '2026-12-24T19:30:00+00:00', postalEntryEnabled: true, eventSalesEnabled: false, cashAlternative: null, buildVideoUrl: null,
  verityRef: 'VTY-TEST01', drawMethod: 'physical', postponement: null,
};

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const auth = req.headers.authorization ?? '';
    const path = req.url.split('?')[0];
    if (path === '/api/me') return auth === 'Bearer admin' ? send(res, 200, { data: ADMIN }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/competitions') return send(res, 200, { data: [COMP] });
    if (path === `/api/competitions/${COMP.slug}`) return send(res, 200, { data: COMP });
    if (path === '/api/admin/postal/competitions') return send(res, 200, { data: [COMP] });
    if (path === '/api/admin/postal') return send(res, 200, { data: [] });
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 4900 + Math.floor(Math.random() * 300);
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

test('the competition page shows Tuesday 22 December 2026, 7:00pm', async () => {
  const html = await page(`/competitions/${COMP.slug}`);
  assert.ok(html.includes(EXPECTED), 'deadline on the competition page');
  assert.ok(!/6:00pm|12:00am|2:00pm|23 December/.test(html), 'no shifted time');
});

test('the Free Postal Entry page shows it', async () => {
  const html = await page('/free-postal-entry');
  assert.ok(html.includes(EXPECTED));
  assert.ok(html.includes(`at least 48 hours before close: by ${EXPECTED} (UK time).`));
});

test('the admin postal logging hint shows it', async () => {
  const html = await page('/admin/postal', 'wds_admin=admin');
  assert.ok(html.includes(`Must be on or before the postal deadline (${EXPECTED}).`));
});
