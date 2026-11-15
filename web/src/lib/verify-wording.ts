/**
 * Wording fixes for Verity's <verity-verify> panel on /verify/[id]. The verifier is Verity's file, kept
 * byte-for-byte (tests/Unit/WebVerifierCopyTest.php), so its text is tidied after it renders; the checks
 * themselves are untouched. Tested in web/tests/verify-wording.test.mjs.
 */

/** "#0001": the ticket format used everywhere else (at least 4 digits; the verifier pads to 6 or not at all). */
export const ticketLabel = (n: string | number): string => `#${String(Number(n)).padStart(4, '0')}`;

/** One check's name or note, for any draw. */
export function tidyLine(t: string): string {
  return t
    .replace(/^1 entries hash to /, '1 entry hashes to ')
    .replace(/^All 1 entries carried /, 'The 1 entry carried ')
    .replace(/says 1 entries but/, 'says 1 entry but')
    // Ticket numbers: "Ticket 1 is in …", "picked ticket 000417, 000962: …", "re-drew ticket 000962 from …".
    .replace(/^Ticket (\d+) is in the sealed entry list\.$/, (_: string, n: string) => `Ticket ${ticketLabel(n)} is in the sealed entry list.`)
    .replace(/picked ticket ((?:\d+)(?:, \d+)*):/, (_: string, list: string) => `picked ticket ${list.split(', ').map(ticketLabel).join(', ')}:`)
    .replace(/re-drew ticket (\d+) from/, (_: string, n: string) => `re-drew ticket ${ticketLabel(n)} from`);
}

/** A physical draw has no random number tool: the item sealed with the entry list is the draw method. */
export function tidyPhysicalLine(t: string): string {
  return tidyLine(t)
    .replace(/^Random number tool sealed with the entry list$/, 'Draw method sealed with the entry list')
    .replace(/^The random number tool \(Physical draw \(numbered tokens\), [^)]*\) was sealed with the entry list when entries closed, before the pick\.$/,
      'The draw method (a physical draw by hand from numbered tokens, with an independent witness) was sealed with the entry list when entries closed, before the draw.')
    .replace(/^The random number tool isn’t the one sealed with the entry list at the close\.$/, 'The draw method isn’t the one sealed with the entry list at the close.');
}

/** Applies the fixes to a rendered panel (its open shadow root). */
export function tidyPanel(root: ShadowRoot, physical: boolean): void {
  root.querySelectorAll('li > span:not(.mark)').forEach((el) => {
    const t = el.textContent ?? '';
    const fixed = physical ? tidyPhysicalLine(t) : tidyLine(t);
    if (fixed !== t) el.textContent = fixed;
  });
  root.querySelectorAll('dt').forEach((dt) => {
    const dd = dt.nextElementSibling as HTMLElement | null;
    if (dt.textContent === 'Winning tickets' && dd?.textContent) dd.textContent = dd.textContent.split(', ').map(ticketLabel).join(', ');
    if (!physical) return;
    // One "Draw method" row, and the seal covers the list and the method.
    if (dt.textContent === 'Draw method' && dd) dd.textContent = 'Physical draw by hand from numbered tokens, on a livestream';
    if (dt.textContent === 'Random number tool') { dd?.remove(); dt.remove(); }
    if (dt.textContent === 'Sealed (list + tool)') dt.textContent = 'Sealed (list + method)';
  });
}
