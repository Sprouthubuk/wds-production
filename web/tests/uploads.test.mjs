// Uploads through the built website with the live site's Origin: winner ID and selfie, Admin competition images and
// Admin winner photos. They arrive at the API whole; the same thing sent from another website is refused.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const LIVE = 'https://wdscompetitions.co.uk';
const UPLOADS = [
  { name: 'winner ID and selfie', path: '/api/account/id-upload', api: '/api/me/verification', auth: 'Bearer customer', files: { document: 'id.png', selfie: 'selfie.jpg' } },
  { name: 'Admin competition image', path: '/api/admin/uploads/competition-image', api: '/api/admin/uploads/competition-image', auth: 'Bearer staff', files: { image: 'car.jpg' } },
  { name: 'Admin winner photo', path: '/api/admin/winners/7/image', api: '/api/admin/winners/7/image', auth: 'Bearer staff', files: { image: 'winner.jpg' } },
];
const SIZE = 10 * 1024 * 1024; // the API's largest file; the ID upload has two

let api, site, base;
let calls = [];

before(async () => {
  api = createServer(async (req, res) => {
    const chunks = [];
    for await (const c of req) chunks.push(c);
    calls.push({ method: req.method, path: req.url, headers: req.headers, body: Buffer.concat(chunks) });
    const ok = req.method === 'POST'; // page reads: 404, so pages use their defaults
    res.writeHead(ok ? 200 : 404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ message: ok ? 'Uploaded.' : 'Not found' }));
  });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 6300 + Math.floor(Math.random() * 300);
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

const files = Object.fromEntries(UPLOADS.flatMap((u) => Object.values(u.files)).map((f) => [f, randomBytes(SIZE)]));

/** As it arrives from nginx (forge/web-nginx.conf), with whatever the browser sent. */
async function send(upload, headers, form) {
  calls = [];
  if (!form) {
    form = new FormData();
    for (const [field, name] of Object.entries(upload.files)) form.set(field, new Blob([files[name]], { type: 'image/jpeg' }), name);
  }
  const res = await fetch(base + upload.path, {
    method: 'POST', body: form, redirect: 'manual',
    headers: { cookie: 'wds_session=customer; wds_admin=staff', 'X-Real-IP': '198.51.100.9', 'X-Forwarded-For': '192.0.2.66, 198.51.100.9', 'X-Forwarded-Proto': 'https', ...headers },
  });
  return { status: res.status, body: await res.text(), sent: calls.filter((c) => c.method === 'POST') };
}

for (const upload of UPLOADS) {
  test(`${upload.name}: uploads with the live site's Origin, whole, for the right visitor`, async () => {
    // A browser without Sec-Fetch-Site (Safari before 16.4, some in-app browsers), then every current one.
    for (const headers of [{ Origin: LIVE }, { Origin: LIVE, 'Sec-Fetch-Site': 'same-origin' }]) {
      const { status, body, sent } = await send(upload, headers);
      assert.equal(status, 200, `${JSON.stringify(headers)}: ${body}`);
      assert.equal(sent.length, 1);
      const [call] = sent;
      assert.equal(call.path, upload.api);
      assert.equal(call.headers.authorization, upload.auth);
      assert.equal(call.headers['x-forwarded-for'], '198.51.100.9');
      assert.match(call.headers['content-type'], /^multipart\/form-data; boundary=/);
      const got = await new Response(call.body, { headers: { 'Content-Type': call.headers['content-type'] } }).formData();
      assert.deepEqual([...got.keys()].sort(), Object.keys(upload.files).sort());
      for (const [field, name] of Object.entries(upload.files)) {
        assert.equal(got.get(field).name, name);
        assert.ok(files[name].equals(Buffer.from(await got.get(field).arrayBuffer())), `${field} arrived unchanged`);
      }
    }
  });

  test(`${upload.name}: refused from anywhere else, before the API is called`, async () => {
    const form = () => { const f = new FormData(); f.set('image', new Blob(['x']), 'x.png'); return f; };
    const foreign = ['https://evil.example', 'null', 'http://wdscompetitions.co.uk', 'https://wdscompetitions.co.uk.evil.example', 'https://evil-wdscompetitions.co.uk', 'https://api.wdscompetitions.co.uk', `${LIVE}:8443`, 'http://localhost:4321', base];
    const refused = [
      ...foreign.map((Origin) => ({ Origin })),
      { Origin: 'https://evil.example', 'Sec-Fetch-Site': 'cross-site' },
      { Origin: LIVE, 'Sec-Fetch-Site': 'cross-site' },
      { Origin: 'https://api.wdscompetitions.co.uk', 'Sec-Fetch-Site': 'same-site' },
      // Headers the visitor can set don't add an origin.
      { Origin: 'https://evil.example', 'X-Forwarded-Host': 'evil.example', Forwarded: 'host=evil.example' },
    ];
    for (const headers of refused) {
      const { status, body } = await send(upload, headers, form());
      assert.equal(status, 403, JSON.stringify(headers));
      assert.match(body, /another website/);
      assert.deepEqual(calls, [], `${JSON.stringify(headers)}: the API was not called`);
    }
  });
}

test('every way a form on another website can post is refused; signing out and reading pages still work', async () => {
  for (const path of ['/api/auth/logout', '/api/admin-auth/logout', '/api/contact', '/api/checkout', '/api/admin/orders/1/refund']) {
    for (const type of ['application/x-www-form-urlencoded', 'text/plain;charset=UTF-8', 'multipart/form-data; boundary=x', 'Application/X-WWW-Form-Urlencoded', 'application/json, text/plain']) {
      calls = [];
      const res = await fetch(base + path, { method: 'POST', body: 'a=b', redirect: 'manual', headers: { 'Content-Type': type, Origin: 'https://evil.example', cookie: 'wds_session=t; wds_admin=t' } });
      assert.equal(res.status, 403, `${path} ${type}`);
      assert.deepEqual(calls, [], `${path} ${type}`);
    }
  }
  // Signing out is a plain form: an older browser on the live site can do it.
  const out = await fetch(`${base}/api/auth/logout`, { method: 'POST', body: '', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Origin: LIVE } });
  assert.equal(out.status, 303);
  const page = await fetch(`${base}/faqs`, { headers: { Origin: 'https://evil.example', 'Sec-Fetch-Site': 'cross-site' } });
  assert.equal(page.status, 200);
  await page.text();
});
