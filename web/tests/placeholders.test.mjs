// No unfinished placeholder text in anything a customer can see: the website source and the API's emails and
// seeds. (The rendered pages are checked too, in launch-polish.test.mjs.) Run: npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const DIRS = ['web/src/pages', 'web/src/components', 'web/src/layouts', 'web/src/lib', 'resources/views', 'database/seeders', 'app/Mail'];
const files = (dir) => readdirSync(dir).flatMap((n) => { const p = join(dir, n); return statSync(p).isDirectory() ? files(p) : [p]; });
const BAD = [/\[Confirm/, /\[TBC/i, /\[Insert/, /\[To confirm/i, /lorem/i, /Placeholder: final text/, /\[DATE\]/, /Draft for legal review/];

test('no placeholder copy ([Confirm, [TBC, [Insert, lorem) in customer-facing source', () => {
  const hits = [];
  for (const d of DIRS) {
    let list = [];
    try { list = files(join(root, d)); } catch { continue; }
    for (const f of list.filter((p) => /\.(astro|ts|mjs|php|html)$/.test(p))) {
      readFileSync(f, 'utf8').split('\n').forEach((line, i) => {
        for (const re of BAD) if (re.test(line)) hits.push(`${f.slice(root.length)}:${i + 1}: ${line.trim().slice(0, 100)}`);
      });
    }
  }
  assert.deepEqual(hits, []);
});
