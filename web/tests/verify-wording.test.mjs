// The /verify page tidies the wording of Verity's verifier (src/lib/verify-wording.ts). The texts below are
// the verifier's own templates (web/public/vendor/verity/verify-record.js); the last test fails if Verity
// changes them, so the tidying never silently stops working. Run: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { tidyLine, tidyPhysicalLine, ticketLabel } from '../src/lib/verify-wording.ts';

const pad6 = (t) => String(t).padStart(6, '0');

test('ticket numbers read #0001, like everywhere else', () => {
  assert.equal(ticketLabel(1), '#0001');
  assert.equal(ticketLabel('000417'), '#0417');
  assert.equal(ticketLabel(12345), '#12345');
  assert.equal(tidyLine(`Ticket ${1} is in the sealed entry list.`), 'Ticket #0001 is in the sealed entry list.');
  assert.equal(tidyLine(`Your browser picked ticket ${[417, 962].map(pad6).join(', ')}: the same result.`), 'Your browser picked ticket #0417, #0962: the same result.');
  assert.equal(
    tidyLine(`Redraw 1 (protocol R2): fixed to drand round 5123999 when the forfeit was approved; your browser re-drew ticket ${pad6(962)} from ${(464).toLocaleString('en-GB')} eligible entries.`),
    'Redraw 1 (protocol R2): fixed to drand round 5123999 when the forfeit was approved; your browser re-drew ticket #0962 from 464 eligible entries.',
  );
  assert.equal(tidyLine('The winning ticket isn’t in the sealed entry list.'), 'The winning ticket isn’t in the sealed entry list.');
});

test('singular entry', () => {
  assert.equal(tidyLine(`${(1).toLocaleString('en-GB')} entries hash to the published value.`), '1 entry hashes to the published value.');
  assert.equal(tidyLine(`${(2).toLocaleString('en-GB')} entries hash to the published value.`), '2 entries hash to the published value.');
});

test('physical draws: the draw method is what was sealed', () => {
  const tool = { name: 'Physical draw (numbered tokens)', url: 'https://wdscompetitions.co.uk/live-draws#physical-draw' };
  assert.equal(tidyPhysicalLine('Random number tool sealed with the entry list'), 'Draw method sealed with the entry list');
  assert.match(tidyPhysicalLine(`The random number tool (${tool.name}, ${tool.url}) was sealed with the entry list when entries closed, before the pick.`), /^The draw method \(a physical draw by hand/);
  assert.equal(tidyPhysicalLine(`Ticket ${6} is in the sealed entry list.`), 'Ticket #0006 is in the sealed entry list.');
});

test('the verifier still uses the texts being tidied', () => {
  const src = readFileSync(new URL('../public/vendor/verity/verify-record.js', import.meta.url), 'utf8');
  const page = readFileSync(new URL('../public/vendor/verity/verify-page/verity-verify.js', import.meta.url), 'utf8');
  for (const s of ['`Ticket ${ticket} is in the sealed entry list.`', 'Your browser picked ticket ${got.map((t) => String(t).padStart(6, \'0\')).join(\', \')}: the same result.',
    'your browser re-drew ticket ${String(pick.ticket).padStart(6, \'0\')} from', 'entries hash to the published value.', 'The random number tool (${tool.name}, ${tool.url}) was sealed with the entry list when entries closed, before the pick.']) {
    assert.ok(src.includes(s), s);
  }
  for (const s of ["'external-tool': 'Random number tool sealed with the entry list'", "['Winning tickets',"]) assert.ok(page.includes(s), s);
});
