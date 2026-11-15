// When the API errors, pages answer 500 with the branded error page, not an empty body or a stack trace.
// Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let api, site, base;
before(async () => {
  api = createServer((req, res) => { res.writeHead(500, { 'Content-Type': 'application/json' }); res.end('{"message":"Server Error","exception":"SecretInternalThing"}'); });
  await new Promise((r) => api.listen(0, '127.0.0.1', r));
  const port = 6200 + Math.floor(Math.random() * 300);
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

test('API errors show the branded 500 page, with nothing internal in it', async () => {
  for (const p of ['/', '/competitions', '/competitions/x', '/winners']) {
    const res = await fetch(base + p);
    assert.equal(res.status, 500, p);
    const html = await res.text();
    assert.match(html, /Something went wrong/, p);
    assert.match(html, /href="\/contact"/, p);
    assert.doesNotMatch(html, /SecretInternalThing|Server Error|at async|\.mjs:\d+|127\.0\.0\.1:\d+\/api/, p);
  }
});
