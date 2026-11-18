// The footer's legal line: the parts in order, and the ICO registration only when config('wds.ico_number') is set.
// Run: npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { legalParts, footerConfig, DEFAULT_FOOTER } from '../src/lib/footer.ts';
import { sampleSettings } from '../src/data/sample.ts';

test('legal line without an ICO number', () => {
  assert.deepEqual(legalParts({ ...sampleSettings, footer: { ...DEFAULT_FOOTER, icoNumber: null } }, 2026), [
    '© 2026 WDS Originals Limited',
    'Registered in England & Wales, company no. 13330886',
    '18+ UK residents only.',
  ]);
});

test('legal line with an ICO number', () => {
  const parts = legalParts({ ...sampleSettings, footer: { ...DEFAULT_FOOTER, icoNumber: 'ZB123456' } }, 2026);
  assert.equal(parts[2], 'ICO registration: ZB123456');
  assert.equal(parts.at(-1), '18+ UK residents only.');
});

test('an API without footer config still gets the confirmed socials and Powered by', () => {
  const { footer, ...older } = { ...sampleSettings, footer: undefined };
  const f = footerConfig(older);
  assert.equal(f.instagram.url, 'https://www.instagram.com/wds_competitions/');
  assert.equal(f.youtube.url, 'https://youtube.com/@wdsoriginals');
  assert.equal(f.icoNumber, null);
  assert.equal(f.poweredBy, true);
});
