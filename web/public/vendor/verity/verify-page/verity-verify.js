// <verity-verify>: a drop-in, framework-free verification panel for one competition.
//
//   <script type="module" src="/vendor/verity/verify-page/verity-verify.js"></script>
//   <verity-verify src="/verity/competitions/VTY-ABC123/verification"></verity-verify>
//
// It downloads the public verification record (spec §7), re-runs every check in the visitor's own browser
// with the browser verifier (BLS signature of the drand round included) and lists the results. Nothing is
// trusted from the server's verdict: the server only supplies the record. Attributes:
//   src       the record's URL (a {"data": …} wrapper or the bare record)
//   previous  optional, space-separated URLs of the records of competitions carried into this one
// Styling: the element uses CSS custom properties --verity-ok, --verity-bad, --verity-muted, --verity-font.
// Text is plain; record values are always inserted as text, never as HTML.
import { verifyRecord, verifyCarried } from '../verify-record.js';

const LABELS = {
  spec: 'Spec version',
  commitment: 'Seed matches the commitment published at launch',
  instant: 'Instant-win table re-derived from the seed',
  entries: 'Entry list matches the hash frozen at close',
  minimum: 'Below its minimum, so carried over',
  beacon: 'drand beacon round (BLS signature, published after the close)',
  seed: 'Draw seed recomputed from the published inputs',
  winners: 'Winners re-drawn from the published inputs',
  audit: 'Audit records match the published hashes',
  witness: 'Independent timestamp of the close',
  carried: 'Carry-over recorded on the audit chain',
};
const label = (key) => LABELS[key] ?? (key.startsWith('redraw-') ? `Redraw ${key.slice(7)} (protocol R2)` : key.startsWith('carried-') ? `Entries carried from ${key.slice(8)}` : key);

const getJson = async (url) => {
  const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'omit' });
  if (!res.ok) throw new Error(`${url}: HTTP ${res.status}`);
  const body = await res.json();
  return body && body.data ? body.data : body;
};

class VerityVerify extends HTMLElement {
  connectedCallback() {
    if (this.shadowRoot) return;
    const root = this.attachShadow({ mode: 'open' });
    root.innerHTML = `<style>
      :host { display: block; font: inherit; font-family: var(--verity-font, inherit); }
      .verdict { font-weight: 700; margin: 0 0 .75rem; }
      .ok { color: var(--verity-ok, #137333); } .bad { color: var(--verity-bad, #b3261e); } .muted { color: var(--verity-muted, #5f6368); }
      ul { list-style: none; padding: 0; margin: 0; } li { padding: .4rem 0; border-top: 1px solid rgba(127,127,127,.25); }
      .mark { display: inline-block; width: 1.5em; font-weight: 700; } .note { display: block; margin-left: 1.5em; font-size: .9em; }
      dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem .75rem; margin: 1rem 0 0; font-size: .9em; } dd { margin: 0; word-break: break-all; }
    </style><p class="verdict muted" part="verdict">Checking in your browser…</p><ul part="checks"></ul><dl part="facts"></dl>`;
    this.run().catch((e) => this.render({ verdict: 'error', checks: [{ key: 'error', ok: false, note: String(e?.message ?? e) }] }, null));
  }

  async run() {
    const src = this.getAttribute('src');
    if (!src) throw new Error('<verity-verify> needs a src attribute');
    const record = await getJson(src);
    const previous = await Promise.all((this.getAttribute('previous') ?? '').split(/\s+/).filter(Boolean).map(getJson));
    const status = record?.competition?.status;
    if (status !== 'drawn' && status !== 'carried') {
      this.render({ verdict: 'pending', checks: [] }, record);
      return;
    }
    const result = status === 'carried' ? await verifyCarried(record) : await verifyRecord(record, { previous });
    this.render(result, record);
    this.dispatchEvent(new CustomEvent('verity-verified', { detail: result, bubbles: true }));
  }

  render(result, record) {
    const root = this.shadowRoot;
    const verdict = root.querySelector('.verdict');
    const text = {
      ok: 'Verified: every check passed in your browser.',
      bad: 'Verification FAILED: at least one check does not match.',
      unverifiable: 'Not independently verifiable (local demo randomness).',
      pending: 'Not drawn yet: the seed and the entry list are published once it is.',
      error: 'The record could not be checked.',
    }[result.verdict] ?? result.verdict;
    verdict.textContent = text;
    verdict.className = `verdict ${result.verdict === 'ok' ? 'ok' : result.verdict === 'bad' || result.verdict === 'error' ? 'bad' : 'muted'}`;
    const list = root.querySelector('ul');
    list.replaceChildren(
      ...result.checks.map((c) => {
        const li = document.createElement('li');
        const mark = document.createElement('span');
        mark.className = `mark ${c.ok === true ? 'ok' : c.ok === false ? 'bad' : 'muted'}`;
        mark.textContent = c.ok === true ? '✓' : c.ok === false ? '✗' : '–';
        const name = document.createElement('span');
        name.textContent = label(c.key);
        const note = document.createElement('span');
        note.className = 'note muted';
        note.textContent = c.note ?? '';
        li.append(mark, name, note);
        return li;
      }),
    );
    const facts = root.querySelector('dl');
    const v = record?.verity ?? {};
    const rows = [
      ['Competition', record?.competition?.ref],
      ['Spec', v.spec],
      ['Commitment', v.commitment],
      ['Entries', v.entries_count],
      ['Entries hash', v.entries_hash],
      ['drand round', v.beacon_round],
      ['Revealed seed', v.revealed_seed],
      ['Winning tickets', (record?.winning_tickets ?? []).join(', ')],
      ['Engine', record?.engine ? `${record.engine.version} · ${record.engine.engine_sha256}` : null],
    ].filter(([, x]) => x !== null && x !== undefined && x !== '');
    facts.replaceChildren(
      ...rows.flatMap(([k, x]) => {
        const dt = document.createElement('dt');
        dt.textContent = k;
        const dd = document.createElement('dd');
        dd.textContent = String(x);
        return [dt, dd];
      }),
    );
  }
}

if (!customElements.get('verity-verify')) customElements.define('verity-verify', VerityVerify);
export { VerityVerify };
