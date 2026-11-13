// Cashflows pages against the built site and a mock API: the Portal's default return URL forwards to the order page,
// the order page says "Confirming your payment…" while pending (and keeps the payment job reference so a guest
// without the key can see it), and Admin → order shows the Payment panel. Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const ORDER = { reference: 'WDS-ABC123', status: 'pending', message: null, total: 30, createdAt: '2026-12-10T12:00:00Z', lines: [{ competition: 'BMW E46', slug: 'bmw', qty: 3, drawAt: '2026-12-24T19:30:00Z', ticketNumbers: [] }] };
const PAYMENT = {
  id: 9, status: 'paid', provider: 'cashflows', method: 'card', channel: 'online', paymentJobReference: '210121017238589452', paymentReference: '210121117372807180',
  paidAt: '2026-12-10T12:03:00Z', reservedUntil: null, cardType: 'debit', cardTypeSource: 'gateway', countsAsCredit: false,
  total: 30, refunded: 5, refundable: 25, refundNeeded: false,
  history: [{ at: '2026-12-10T12:00:00Z', kind: 'created', status: 'Pending', summary: 'Payment page created', payload: { request: { amountToCollect: '30.00' } } }],
  refunds: [{ number: 'WDS-ABC123-R1', amount: 5, status: 'completed', reason: 'Goodwill', automatic: false, by: 'Jo Admin', reference: 'RF1', errorCode: null, error: null, at: '2026-12-10T13:00:00Z' }],
};
const seen = [];

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    seen.push(req.url);
    const auth = req.headers.authorization ?? '';
    const [path, qs] = req.url.split('?');
    const q = new URLSearchParams(qs ?? '');
    if (path === '/api/me') return auth === 'Bearer admin' ? send(res, 200, { data: ADMIN }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/settings') return send(res, 404, {});
    if (path === `/api/orders/${ORDER.reference}`) {
      return q.get('paymentjobref') === PAYMENT.paymentJobReference || q.get('key') === 'k' ? send(res, 200, { data: ORDER }) : send(res, 404, { message: 'Not found.' });
    }
    if (path === '/api/admin/orders/9') return auth === 'Bearer admin' ? send(res, 200, { data: { ...ORDER, status: 'paid' }, customer: { name: 'Luke Skywalker', email: 'luke@example.com', phone: null }, payment: PAYMENT }) : send(res, 401, {});
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 5600 + Math.floor(Math.random() * 300);
  base = `http://127.0.0.1:${port}`;
  site = spawn(process.execPath, [fileURLToPath(new URL('../dist/server/entry.mjs', import.meta.url))], {
    env: { ...process.env, API_URL: `http://127.0.0.1:${api.address().port}`, HOST: '127.0.0.1', PORT: String(port) },
    stdio: 'ignore',
  });
  for (let i = 0; i < 100; i++) {
    try { await fetch(`${base}/robots.txt`); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('site did not start');
});
after(() => { site?.kill(); api?.close(); });

const get = (path, cookie) => fetch(base + path, { redirect: 'manual', headers: cookie ? { cookie } : {} });

test('the Portal default return URL forwards to the order page with the payment job reference', async () => {
  const res = await get(`/order/return?result=cancelled&paymentjobref=${PAYMENT.paymentJobReference}&paymentref=${PAYMENT.paymentReference}&ordernumber=WDS-ABC123&status=cancelled`);
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), `/order/WDS-ABC123?paymentjobref=${PAYMENT.paymentJobReference}&result=cancelled`);
  const junk = await get('/order/return?ordernumber=../../admin');
  assert.equal(junk.headers.get('location'), '/basket');
});

test('a pending order says "Confirming your payment…" and reloads; the payment job reference opens it', async () => {
  const res = await get(`/order/WDS-ABC123?paymentjobref=${PAYMENT.paymentJobReference}&result=success`);
  assert.equal(res.status, 200);
  const html = await res.text();
  assert.match(html, /Confirming your payment…/);
  assert.match(html, /http-equiv="refresh"/);
  assert.ok(seen.some((u) => u.startsWith('/api/orders/WDS-ABC123?') && u.includes(`paymentjobref=${PAYMENT.paymentJobReference}`)));
  assert.equal((await get('/order/WDS-ABC123?paymentjobref=wrong')).status, 404);
});

test('Admin → order shows the Payment panel: references, card type and source, refunds, history, refund form', async () => {
  const res = await get('/admin/orders/9', 'wds_admin=admin');
  assert.equal(res.status, 200);
  const html = await res.text();
  for (const s of [PAYMENT.paymentJobReference, PAYMENT.paymentReference, 'Debit, from the payment (Cashflows)', 'WDS-ABC123-R1', 'Goodwill', 'Payment page created', 'Raw (redacted)', '/api/admin/orders/9/refund', '£25.00 refundable']) {
    assert.ok(html.includes(s), s);
  }
});
