// Guards against mojibake: UTF-8 text saved through a Windows (cp1252) round trip turns "·" into "Â·" and
// "–" into "â€“". Every source file must be valid UTF-8 with no BOM, and contain none of those sequences.
// Run: npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const DIRS = ['web/src', 'web/tests', 'web/e2e', 'web/public', 'resources', 'lang', 'database', 'app', 'config', 'routes', 'tests'];
const TEXT = /\.(astro|ts|mjs|js|css|json|php|txt|md|svg|html|xml|webmanifest)$/;
// "Ã" and "Â" followed by a Latin-1 continuation char, or "â€": how cp1252 shows UTF-8 bytes.
const MOJIBAKE = /Â[ -¿]|Ã[\u0080-¿]|â€/;

function* files(dir) {
  let entries;
  try { entries = readdirSync(dir); } catch { return; }
  for (const name of entries) {
    if (name === 'node_modules' || name === 'dist' || name === 'vendor') continue;
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* files(p);
    else if (TEXT.test(name)) yield p;
  }
}

test('source files are UTF-8 without a BOM, and have no mojibake', () => {
  const bad = [];
  for (const d of DIRS) {
    for (const p of files(join(ROOT, d))) {
      if (p.endsWith('encoding.test.mjs')) continue;
      const buf = readFileSync(p);
      const rel = relative(ROOT, p);
      if (buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf) bad.push(`${rel}: BOM`);
      const text = new TextDecoder('utf-8', { fatal: false }).decode(buf);
      if (text.includes('�')) bad.push(`${rel}: not valid UTF-8`);
      const line = text.split('\n').findIndex((l) => MOJIBAKE.test(l));
      if (line >= 0) bad.push(`${rel}:${line + 1}: mojibake`);
    }
  }
  assert.deepEqual(bad, []);
});
