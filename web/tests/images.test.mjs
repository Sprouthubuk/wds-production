// Photos uploaded in Admin (/storage/…) have no -720 version, so cards, the gallery thumbnails, the sticky bar and
// winners must use them as they are; only the site's own /images/*.jpg have a -720 version. A competition with one
// photo must not render an empty <img src="">. Run: npm test (after a build).
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { sampleCompetitions } from '../src/data/sample.ts';
import { thumb } from '../src/lib/images.ts';

const UPLOADED = 'http://127.0.0.1:8000/storage/competitions/Zx81abc.jpg';
const COMP = { ...sampleCompetitions[0], id: 7, slug: 'uploaded', title: 'Uploaded photo car', images: [{ src: UPLOADED, alt: 'The car' }], buildVideoUrl: null };
const WINNER = { name: 'Sam Winner', prize: 'A car', image: 'http://127.0.0.1:8000/storage/winners/Qq9.jpg', drawnAt: null, ticketNumber: null };

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
let api, site, base;
before(async () => {
  api = createServer((req, res) => {
    const path = req.url.split('?')[0];
    if (path === '/api/competitions') return send(res, 200, { data: [COMP] });
    if (path === `/api/competitions/${COMP.slug}`) return send(res, 200, { data: COMP });
    if (path === '/api/winners') return send(res, 200, { data: [WINNER] });
    if (path === '/api/draws') return send(res, 200, { data: [] });
    if (path === '/api/upcoming') return send(res, 200, { data: null });
    return send(res, 404, { message: 'Not found.' });
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

test('thumb() only rewrites the site\'s own photos', () => {
  assert.equal(thumb('/images/e46-clubsport-34.jpg'), '/images/e46-clubsport-34-720.jpg');
  assert.equal(thumb('/images/e46-clubsport-34-720.jpg'), '/images/e46-clubsport-34-720.jpg');
  assert.equal(thumb(UPLOADED), UPLOADED);
  assert.equal(thumb('/storage/competitions/a.jpg'), '/storage/competitions/a.jpg');
  assert.equal(thumb(undefined), '');
});

test('pages use uploaded photos as they are, and never render an empty src', async () => {
  for (const page of ['/', '/competitions', `/competitions/${COMP.slug}`, '/winners']) {
    const html = await (await fetch(base + page)).text();
    assert.ok(!html.includes('Zx81abc-720.jpg') && !html.includes('Qq9-720.jpg'), `${page} points at a -720 file that doesn't exist`);
    assert.ok(!/<img[^>]*\ssrc=""/.test(html), `${page} has an empty <img src>`);
  }
  assert.ok((await (await fetch(base + '/competitions')).text()).includes(UPLOADED));
});

test('thumb() uses the 720px copy of an Admin upload stored with one, and nothing else', () => {
  assert.equal(thumb('https://api.example.com/storage/competitions/Ab12-full.jpg'), 'https://api.example.com/storage/competitions/Ab12-720.jpg');
  assert.equal(thumb('/storage/winners/Ab12-full.PNG'), '/storage/winners/Ab12-720.jpg');
  assert.equal(thumb('/storage/competitions/Ab12.jpg'), '/storage/competitions/Ab12.jpg');
  assert.equal(thumb('https://example.com/elsewhere/a-full.jpg'), 'https://example.com/elsewhere/a-full.jpg');
});
