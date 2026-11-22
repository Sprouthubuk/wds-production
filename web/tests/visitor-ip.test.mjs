// The website tells the API which visitor each request is for (X-Forwarded-For), from nginx's X-Real-IP, on every
// call: page reads, layout reads, JSON proxies, uploads and the admin proxy. What the visitor sends is never passed on.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

let api, site, base;
let calls = [];

before(async () => {
  api = createServer((req, res) => {
    const path = req.url.split('?')[0];
    calls.push({ path, method: req.method, xff: req.headers['x-forwarded-for'], real: req.headers['x-real-ip'] });
    req.resume();
    const send = (status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
    const me = { id: 1, firstName: 'A', lastName: 'B', email: 'a@example.com', isStaff: true, isAdmin: true, limits: {} };
    if (path === '/api/me') return send(200, { data: me });
    if (path === '/api/settings' || path.startsWith('/api/upcoming') || path.startsWith('/api/competitions/')) return send(404, { message: 'Not found' });
    // Slow enough that requests for two visitors overlap.
    setTimeout(() => send(200, { data: [] }), 60);
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 5900 + Math.floor(Math.random() * 300);
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

/** As nginx sends it: X-Real-IP is the visitor; X-Forwarded-For has whatever the visitor sent in front. */
const visit = async (ip, path, init = {}) => {
  calls = [];
  const res = await fetch(base + path, { redirect: 'manual', ...init, headers: { 'X-Real-IP': ip, 'X-Forwarded-For': `192.0.2.66, ${ip}`, ...init.headers } });
  await res.text();
  assert.ok(calls.length, `${path} called the API`);
  return calls;
};
const all = (list, ip, what) => assert.deepEqual([...new Set(list.map((c) => c.xff))], [ip], `${what}: ${JSON.stringify(list)}`);
const post = (body, cookie) => ({ method: 'POST', body: JSON.stringify(body), headers: { 'Content-Type': 'application/json', Origin: base, ...(cookie ? { cookie } : {}) } });

test('every API call a page makes carries the visitor (middleware, page, layout and components)', async () => {
  for (const path of ['/', '/competitions', '/winners', '/live-draws', '/faqs', '/terms', '/free-postal-entry', '/competitions/nope', '/basket', '/sign-in']) {
    const list = await visit('198.51.100.1', path);
    all(list, '198.51.100.1', path);
  }
  all(await visit('198.51.100.2', '/account', { headers: { cookie: 'wds_session=t' } }), '198.51.100.2', '/account (sign-in check)');
  all(await visit('198.51.100.3', '/admin', { headers: { cookie: 'wds_admin=t' } }), '198.51.100.3', '/admin');
  all(await visit('2001:db8::7', '/competitions'), '2001:db8::7', 'IPv6');
});

test('every proxied request carries the visitor: JSON, sign-in, upload and the admin proxy', async () => {
  for (const path of ['/api/basket/quote', '/api/checkout', '/api/newsletter', '/api/contact', '/api/auth/login', '/api/auth/register', '/api/auth/forgot-password', '/api/account/limits', '/api/free-entry/1']) {
    all(await visit('198.51.100.4', path, post({ email: 'a@example.com' }, 'wds_session=t')), '198.51.100.4', path);
  }
  const form = new FormData();
  form.set('file', new Blob(['x']), 'id.png');
  const upload = await visit('198.51.100.5', '/api/account/id-upload', { method: 'POST', body: form, headers: { cookie: 'wds_session=t' } });
  assert.ok(upload.some((c) => c.path === '/api/me/verification'));
  all(upload, '198.51.100.5', 'upload');
  const admin = await visit('198.51.100.6', '/api/admin/orders', { headers: { cookie: 'wds_admin=t' } });
  assert.ok(admin.some((c) => c.path === '/api/admin/orders'));
  all(admin, '198.51.100.6', 'admin proxy');
});

test('two visitors at the same time are never mixed up', async () => {
  calls = [];
  const ips = Array.from({ length: 12 }, (_, i) => `198.51.100.${100 + i}`);
  await Promise.all(ips.map((ip, i) => fetch(`${base}/api/basket/quote?n=${i}`, { ...post({ items: [] }), headers: { 'Content-Type': 'application/json', Origin: base, 'X-Real-IP': ip } }).then((r) => r.text())));
  assert.deepEqual(calls.filter((c) => c.path === '/api/basket/quote').map((c) => c.xff).sort(), [...ips].sort());
});

test('what the visitor sends is never passed on', async () => {
  // No X-Real-IP (nothing in front): the connection's own address, not the X-Forwarded-For they sent.
  calls = [];
  await fetch(`${base}/competitions`, { headers: { 'X-Forwarded-For': '192.0.2.66', 'X-Client-IP': '192.0.2.66', Forwarded: 'for=192.0.2.66' } }).then((r) => r.text());
  all(calls, '127.0.0.1', 'spoofed X-Forwarded-For');
  for (const bad of ['not-an-ip', '1.2.3.4, 5.6.7.8', '1.2.3.4:80']) {
    calls = [];
    await fetch(`${base}/competitions`, { headers: { 'X-Real-IP': bad } }).then((r) => r.text());
    all(calls, '127.0.0.1', bad);
  }
});

test('X-Real-IP is believed only when the connection is from this machine (nginx)', async () => {
  const { visitorIp } = await import('../src/lib/client-ip.ts');
  const h = new Headers({ 'x-real-ip': '198.51.100.1' });
  for (const nginx of ['127.0.0.1', '::1', '::ffff:127.0.0.1']) assert.equal(visitorIp(nginx, h), '198.51.100.1', nginx);
  for (const direct of ['203.0.113.9', '10.0.0.5', '2001:db8::1']) assert.equal(visitorIp(direct, h), direct, direct);
  assert.equal(visitorIp(null, h), null);
});

test("Astro takes no address from the visitor: allowedDomains isn't set", () => {
  // With security.allowedDomains, clientAddress becomes the first X-Forwarded-For entry, which the visitor chooses:
  // src/middleware.ts would then need the connection's address from somewhere else.
  const config = readFileSync(new URL('../astro.config.mjs', import.meta.url), 'utf8');
  assert.ok(!config.includes('allowedDomains'), 'see src/lib/client-ip.ts first');
});
