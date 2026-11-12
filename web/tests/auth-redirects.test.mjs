// Sign-in redirects for admin and account pages (src/middleware.ts), against the built server
// (npm run build first) and a mock API. Run: npm test
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ADMIN = { id: 1, firstName: 'Jo', lastName: 'Admin', email: 'jo@example.com', isAdmin: true, isStaff: true };
const STAFF = { ...ADMIN, id: 2, isAdmin: false };
const CUSTOMER = { ...ADMIN, id: 3, isAdmin: false, isStaff: false };
const DASH = { revenueToday: 0, ordersToday: 0, revenueTotal: 0, customers: 0, postalThisWeek: 0, idToReview: 0, refundsNeeded: 0, daily: [], live: [], recentOrders: [] };

// Tokens: admin, staff, customer are valid; stale passes /me but is rejected by the admin API (expired mid-session).
const users = { 'Bearer admin': ADMIN, 'Bearer staff': STAFF, 'Bearer customer': CUSTOMER, 'Bearer stale': ADMIN };
const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };

let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const auth = req.headers.authorization ?? '';
    const path = req.url.split('?')[0];
    if (path === '/api/me') return users[auth] ? send(res, 200, { data: users[auth] }) : send(res, 401, { message: 'Unauthenticated.' });
    if (path === '/api/auth/login') {
      let raw = '';
      req.on('data', (c) => (raw += c));
      return req.on('end', () => {
        const { email } = JSON.parse(raw || '{}');
        const u = email === 'jo@example.com' ? ['admin', ADMIN] : email === 'cu@example.com' ? ['customer', CUSTOMER] : null;
        return u ? send(res, 200, { token: u[0], isStaff: u[1].isStaff, isAdmin: u[1].isAdmin }) : send(res, 422, { message: 'Those details are not right.' });
      });
    }
    if (path.startsWith('/api/admin/')) {
      if (auth === 'Bearer stale' || !users[auth]) return send(res, 401, { message: 'Unauthenticated.' });
      if (!users[auth].isAdmin) return send(res, 403, { message: 'Forbidden.' });
      if (path === '/api/admin/dashboard') return send(res, 200, { data: DASH });
    }
    return send(res, 404, { message: 'Not found.' });
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 4600 + Math.floor(Math.random() * 300);
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
const clears = (res, name) => res.headers.getSetCookie().some((c) => c.startsWith(`${name}=`) && /expires=Thu, 01 Jan 1970|max-age=0/i.test(c));

test('admin while logged out redirects to sign-in (not a 500), keeping the path and query', async () => {
  for (const [path, next] of [['/admin', '/admin'], ['/admin/', '/admin/'], ['/admin/competitions?status=draft', '/admin/competitions?status=draft'], ['/admin/draws/5/reveal', '/admin/draws/5/reveal']]) {
    const res = await get(path);
    assert.equal(res.status, 302, path);
    assert.equal(res.headers.get('location'), `/sign-in?next=${encodeURIComponent(next)}`, path);
  }
});

test('an invalid admin token is cleared and redirects', async () => {
  const res = await get('/admin', 'wds_admin=nonsense');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/sign-in?next=%2Fadmin');
  assert.ok(clears(res, 'wds_admin'), 'wds_admin cleared');
});

test('a 401 from the API while the page renders is cleared and redirects', async () => {
  const res = await get('/admin', 'wds_admin=stale');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/sign-in?next=%2Fadmin');
  assert.ok(clears(res, 'wds_admin'));
});

test('non-admins on /admin go to their account or the stand, never a 500', async () => {
  let res = await get('/admin', 'wds_admin=customer');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/account');
  res = await get('/admin', 'wds_session=customer');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/account');
  res = await get('/admin', 'wds_admin=staff'); // staff, not admin: the dashboard API says 403
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/admin/event-sales');
});

test('a signed-in admin sees the dashboard', async () => {
  const res = await get('/admin', 'wds_admin=admin');
  assert.equal(res.status, 200);
  assert.match(await res.text(), /Revenue today/);
});

test('account pages redirect when logged out or the token is invalid', async () => {
  let res = await get('/account');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/sign-in?next=%2Faccount');
  res = await get('/account?tab=limits', 'wds_session=nonsense');
  assert.equal(res.status, 302);
  assert.equal(res.headers.get('location'), '/sign-in?next=%2Faccount%3Ftab%3Dlimits');
  assert.ok(clears(res, 'wds_session'));
});

test('/login and /admin/login (and the old /admin/sign-in) go to /sign-in, keeping ?next', async () => {
  const cases = [
    ['/login', '/sign-in?next=%2Faccount'],
    ['/login?next=/basket', '/sign-in?next=%2Fbasket'],
    ['/admin/login', '/sign-in?next=%2Fadmin'],
    ['/admin/login?next=/admin/draws', '/sign-in?next=%2Fadmin%2Fdraws'],
    ['/admin/sign-in', '/sign-in?next=%2Fadmin'],
    ['/login?next=//evil.example', '/sign-in?next=%2Faccount'],
  ];
  for (const [path, location] of cases) {
    const res = await get(path);
    assert.equal(res.status, 302, path);
    assert.equal(res.headers.get('location'), location, path);
  }
});

test('signing in with next=/admin sets the admin cookie, and refuses customers', async () => {
  const post = (email) => fetch(`${base}/api/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Origin: base }, body: JSON.stringify({ email, password: 'x', next: '/admin/draws' }) });
  let res = await post('jo@example.com');
  assert.equal(res.status, 200);
  assert.equal((await res.json()).redirect, '/admin/draws');
  assert.ok(res.headers.getSetCookie().some((c) => c.startsWith('wds_admin=admin')));
  res = await post('cu@example.com');
  assert.equal(res.status, 403);
  assert.ok(!res.headers.getSetCookie().some((c) => c.startsWith('wds_admin=customer')));
});

test('the sign-in page itself renders', async () => {
  const res = await get('/sign-in?next=%2Fadmin');
  assert.equal(res.status, 200);
  assert.match(await res.text(), /Admin sign in/);
});
