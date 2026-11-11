/**
 * Verity verifier — a byte-for-byte port of backend/app/Verity, for every spec:
 * VERITY-1 (everything published before VERITY-2), VERITY-2 (HMAC_DRBG, NIST SP 800-90A),
 * VERITY-3 (VERITY-2's construction; entries carry their origin, VerityThree.php)
 * and redraw protocol R2 (RedrawR2.php). A competition says which spec it was made under
 * (verity.spec); pass it as `spec`.
 * Runs in the browser (and Node 20+) using Web Crypto, so anyone can re-run
 * a draw without trusting our server. Spec: docs/verity/VERITY-1-SPEC.md.
 */
const enc = new TextEncoder();
const SPEC = 'VERITY-1';
export const SPEC_2 = 'VERITY-2';
export const SPEC_3 = 'VERITY-3';
export const SPECS = [SPEC, SPEC_2, SPEC_3];
/** VERITY-3: the `from` of an entry bought in the draw itself. */
export const OWN = '-';
const SAMPLE_SPACE = 281474976710656; // 2^48

const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
const unhex = (h) => {
  if (!/^(?:[0-9a-f]{2})*$/.test(h)) throw new Error('Expected lowercase hex');
  const out = new Uint8Array(h.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(h.slice(2 * i, 2 * i + 2), 16);
  return out;
};
const concat = (...parts) => {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) { out.set(p, at); at += p.length; }
  return out;
};
const specOf = (spec) => {
  const s = spec ?? SPEC;
  if (!SPECS.includes(s)) throw new Error(`Unknown spec ${s}`);
  return s;
};

export async function sha256(text) {
  return hex(await crypto.subtle.digest('SHA-256', enc.encode(text)));
}

const keyCache = new Map();
export async function hmac(key, message) {
  let k = keyCache.get(key);
  if (!k) {
    k = await crypto.subtle.importKey('raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    if (keyCache.size > 50) keyCache.clear();
    keyCache.set(key, k);
  }
  return hex(await crypto.subtle.sign('HMAC', k, enc.encode(message)));
}

/** Unbiased sample in [0, n) — see Picker.php (VERITY-1). */
export async function sample(key, label, n) {
  if (!(n >= 1 && n <= SAMPLE_SPACE)) throw new Error('Cannot sample from that range');
  const limit = Math.floor(SAMPLE_SPACE / n) * n;
  for (let c = 0; c < 1000; c++) {
    const h = await hmac(key, `${label}|${c}`);
    const x = parseInt(h.slice(0, 12), 16);
    if (x < limit) return { value: x % n, counter: c, hmac: h };
  }
  throw new Error('Sampling did not converge');
}

// ---------------------------------------------------------------------------
// VERITY-2: HMAC_DRBG (SP 800-90A Rev. 1 §10.1.2), Simple Discard (Appendix A.5.1), Fisher–Yates.

async function hmacRaw(key, data) {
  const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return new Uint8Array(await crypto.subtle.sign('HMAC', k, data));
}

/** HMAC_DRBG-SHA256, no prediction resistance, no reseed, no additional input. Mirrors HmacDrbg.php. */
export class HmacDrbg {
  static async create(entropyInput, nonce, personalisation = new Uint8Array(0)) {
    if (entropyInput.length < 32) throw new Error('HMAC_DRBG entropy input must be at least 256 bits');
    const d = new HmacDrbg();
    d.key = new Uint8Array(32);
    d.v = new Uint8Array(32).fill(1);
    await d.update(concat(entropyInput, nonce, personalisation));
    d.reseedCounter = 1;
    return d;
  }

  async update(provided) {
    this.key = await hmacRaw(this.key, concat(this.v, Uint8Array.of(0), provided));
    this.v = await hmacRaw(this.key, this.v);
    if (provided.length === 0) return;
    this.key = await hmacRaw(this.key, concat(this.v, Uint8Array.of(1), provided));
    this.v = await hmacRaw(this.key, this.v);
  }

  async generate(bytes) {
    if (!(bytes >= 1 && bytes <= 65536)) throw new Error('HMAC_DRBG request must be 1 to 65536 bytes');
    const parts = [];
    let have = 0;
    while (have < bytes) {
      this.v = await hmacRaw(this.key, this.v);
      parts.push(this.v);
      have += this.v.length;
    }
    await this.update(new Uint8Array(0));
    this.reseedCounter++;
    return concat(...parts).slice(0, bytes);
  }
}

const bitLength = (x) => { let b = 0; while (x > 0) { b++; x = Math.floor(x / 2); } return b; };

/** A value in [0, n): the leftmost m bits of each Generate, kept if below n (Sampler::below). */
export async function sampleBelow(drbg, n) {
  if (!(Number.isInteger(n) && n >= 1 && n <= SAMPLE_SPACE)) throw new Error('Cannot sample from that range');
  if (n === 1) return { value: 0, discarded: 0 };
  const m = bitLength(n - 1);
  const bytes = Math.ceil(m / 8);
  const drop = 2 ** (8 * bytes - m);
  for (let a = 0; a < 256; a++) {
    let c = 0;
    for (const b of await drbg.generate(bytes)) c = c * 256 + b;
    c = Math.floor(c / drop);
    if (c < n) return { value: c, discarded: a };
  }
  throw new Error('Sampling did not converge');
}

/** The first k values of a forward Fisher–Yates shuffle of 0 .. n-1 (Sampler::firstK). */
export async function firstK(drbg, n, k) {
  if (!(k >= 0 && k <= n)) throw new Error('Cannot choose more values than there are');
  const swapped = new Map();
  const out = [];
  for (let i = 0; i < k; i++) {
    const s = await sampleBelow(drbg, n - i);
    const j = i + s.value;
    const atI = swapped.has(i) ? swapped.get(i) : i;
    const atJ = swapped.has(j) ? swapped.get(j) : j;
    swapped.set(j, atI);
    swapped.set(i, atJ);
    out.push({ value: atJ, discarded: s.discarded });
  }
  return out;
}

// ---------------------------------------------------------------------------

export function normaliseEntries(entries) {
  const seen = new Map();
  for (const e of entries) {
    const t = Number(e.ticket);
    if (seen.has(t)) throw new Error(`Duplicate ticket ${t}`);
    // Same canonical-form checks as EntrySnapshot.php.
    if (!(t >= 1) || !/^[0-9a-f]{1,64}$/.test(String(e.owner))) throw new Error(`Malformed entry for ticket ${t}`);
    seen.set(t, { ticket: t, owner: String(e.owner) });
  }
  return [...seen.values()].sort((a, b) => a.ticket - b.ticket);
}

const REF3 = /^[A-Za-z0-9-]{1,64}$/;
const FROM3 = /^([A-Za-z0-9-]{1,64}):([1-9][0-9]{0,14})$/;

/** VERITY-3's canonical list (VerityThree::normalise): {ticket, owner, origin, from}, ascending ticket. */
export function normaliseEntries3(ref, entries) {
  if (!REF3.test(ref)) throw new Error('Malformed competition ref');
  const seen = new Map();
  for (const e of entries) {
    const t = Number(e.ticket);
    if (seen.has(t)) throw new Error(`Duplicate ticket ${t}`);
    const origin = String(e.origin ?? ref);
    const from = String(e.from ?? OWN);
    if (!(t >= 1) || !/^[0-9a-f]{1,64}$/.test(String(e.owner)) || !REF3.test(origin)) throw new Error(`Malformed entry for ticket ${t}`);
    const m = from === OWN ? null : FROM3.exec(from);
    if (from === OWN ? origin !== ref : !m || m[1] === ref || origin === ref) throw new Error(`Malformed origin for ticket ${t}`);
    seen.set(t, { ticket: t, owner: String(e.owner), origin, from });
  }
  return [...seen.values()].sort((a, b) => a.ticket - b.ticket);
}

export async function entriesHash(ref, entries, spec) {
  const s = specOf(spec);
  const lines = s === SPEC_3
    ? normaliseEntries3(ref, entries).map((e) => `${e.ticket}|${e.owner}|${e.origin}|${e.from}`)
    : normaliseEntries(entries).map((e) => `${e.ticket}|${e.owner}`);
  return sha256(`${s}|entries|${ref}\n${lines.join('\n')}`);
}

/**
 * VERITY-3 carry-over check (VerityThree::carryProblem, spec §13.4): the entries of `next` carried from
 * `prevRef` are exactly the whole of `prev`'s list, ticket for ticket, with the same owner and origin.
 * Null when they are; otherwise what's wrong.
 */
export function carryProblem(prevRef, prev, nextRef, next) {
  const want = new Map(normaliseEntries3(prevRef, prev).map((e) => [e.ticket, `${e.owner}|${e.origin}`]));
  const got = new Set();
  for (const e of normaliseEntries3(nextRef, next)) {
    if (!e.from.startsWith(`${prevRef}:`)) continue;
    const t = Number(e.from.slice(prevRef.length + 1));
    if (got.has(t)) return `${prevRef} ticket ${t} is carried twice`;
    if (!want.has(t)) return `${prevRef} has no ticket ${t} to carry`;
    if (want.get(t) !== `${e.owner}|${e.origin}`) return `${prevRef} ticket ${t} was carried with another owner or origin`;
    got.add(t);
  }
  const missing = want.size - got.size;
  return missing > 0 ? `${missing} of ${prevRef}'s ${want.size} entries were not carried` : null;
}

export async function ownerTag(orderReference) {
  return (await sha256(`owner|${orderReference}`)).slice(0, 12);
}

export async function runDraw({ ref, serverSeed, entries, beacon, winnerCount = 1, spec }) {
  if (specOf(spec) === SPEC_2) return runDraw2({ ref, serverSeed, entries, beacon, winnerCount });
  if (specOf(spec) === SPEC_3) return runDraw2({ ref, serverSeed, entries, beacon, winnerCount, label: SPEC_3 });
  const pool = normaliseEntries(entries);
  const eh = await entriesHash(ref, pool);
  const drawSeed = await hmac(serverSeed, `${SPEC}|draw|${ref}|${eh}|${beacon}`);
  const winners = [];
  for (let p = 1; p <= winnerCount; p++) {
    const s = await sample(drawSeed, `pick|${p}`, pool.length);
    const chosen = pool[s.value];
    winners.push({ position: p, ticket: chosen.ticket, owner: chosen.owner, index: s.value, counter: s.counter });
    pool.splice(s.value, 1);
  }
  return { spec: SPEC, commitment: await sha256(serverSeed), entriesHash: eh, drawSeed, winners };
}

/** VERITY-2's draw; VERITY-3 is the same construction under its own label, over its own entry list. */
async function runDraw2({ ref, serverSeed, entries, beacon, winnerCount, label = SPEC_2 }) {
  if (!/^[0-9a-f]{64}$/.test(serverSeed)) throw new Error('Server seed must be 64 lowercase hex characters');
  if (!/^(?:[0-9a-f]{2}){16,64}$/.test(beacon)) throw new Error('Beacon randomness must be lowercase hex (16 to 64 bytes)');
  const pool = label === SPEC_3 ? normaliseEntries3(ref, entries) : normaliseEntries(entries);
  if (pool.length === 0) throw new Error('No entries to draw from');
  if (!(winnerCount >= 1 && winnerCount <= pool.length)) throw new Error('Invalid number of winners');
  const eh = await entriesHash(ref, pool, label);
  const entropy = unhex(serverSeed);
  const nonce = concat(unhex(beacon), unhex(eh));
  const personalisation = enc.encode(`${label}|main|${ref}`);
  const drbg = await HmacDrbg.create(entropy, nonce, personalisation);
  const winners = (await firstK(drbg, pool.length, winnerCount)).map((s, i) => ({
    position: i + 1, ticket: pool[s.value].ticket, owner: pool[s.value].owner, index: s.value, counter: s.discarded,
  }));
  const drawSeed = hex(await crypto.subtle.digest('SHA-256', concat(entropy, nonce, personalisation)));
  return { spec: label, commitment: await sha256(serverSeed), entriesHash: eh, drawSeed, winners };
}

export async function allocateInstantWins({ ref, serverSeed, maxTickets, prizes, spec }) {
  if (specOf(spec) === SPEC_2) return allocateInstantWins2({ ref, serverSeed, maxTickets, prizes });
  if (specOf(spec) === SPEC_3) return allocateInstantWins2({ ref, serverSeed, maxTickets, prizes, label: SPEC_3 });
  const key = await hmac(serverSeed, `${SPEC}|instant|${ref}`);
  const used = new Set();
  const out = [];
  let slot = 0;
  for (const prize of prizes) {
    for (let q = 0; q < Number(prize.quantity); q++) {
      slot++;
      for (let a = 0; ; a++) {
        const s = await sample(key, `slot|${slot}|${a}`, maxTickets);
        const ticket = s.value + 1;
        if (!used.has(ticket)) {
          used.add(ticket);
          out.push({ ticket, prize: String(prize.prize), slot });
          break;
        }
      }
    }
  }
  return out.sort((x, y) => x.ticket - y.ticket);
}

async function allocateInstantWins2({ ref, serverSeed, maxTickets, prizes, label = SPEC_2 }) {
  if (!/^[0-9a-f]{64}$/.test(serverSeed)) throw new Error('Server seed must be 64 lowercase hex characters');
  const slots = [];
  for (const p of prizes) for (let q = 0; q < Number(p.quantity); q++) slots.push(String(p.prize));
  if (!(maxTickets >= 1) || slots.length > maxTickets) throw new Error('More instant-win prizes than tickets');
  const nonce = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(`${label}|instant-nonce|${ref}`)));
  const drbg = await HmacDrbg.create(unhex(serverSeed), nonce, enc.encode(`${label}|instant|${ref}`));
  const out = (await firstK(drbg, maxTickets, slots.length)).map((s, i) => ({ ticket: s.value + 1, prize: slots[i], slot: i + 1 }));
  return out.sort((x, y) => x.ticket - y.ticket);
}

/** VERITY-2's and VERITY-3's hashes also cover maxTickets (VERITY-1's doesn't). */
export async function instantTableHash(ref, table, spec, maxTickets) {
  const lines = table.map((r) => `${r.ticket}|${r.prize}`);
  const s = specOf(spec);
  if (s !== SPEC && !(Number.isInteger(maxTickets) && maxTickets >= 1)) throw new Error(`${s} table hash needs maxTickets`);
  const head = s !== SPEC ? `${s}|instant-table|${ref}|${maxTickets}` : `${s}|instant-table|${ref}`;
  return sha256(`${head}\n${lines.join('\n')}`);
}

// ---------------------------------------------------------------------------
// Redraw protocol R2 (spec §5.6, RedrawR2.php): a fresh HMAC_DRBG stream per redraw, seeded with a
// drand round committed when the forfeit was final. R1 redraws are the next position of runDraw.

export const REDRAW_R2 = 'R2';
const DRAND_GENESIS = 1692803367;
const DRAND_PERIOD = 3;

/** The first drand quicknet round published at or after Unix time t. */
export const roundAt = (t) => Math.max(1, Math.ceil((t - DRAND_GENESIS) / DRAND_PERIOD) + 1);

/** The frozen entries without every ticket that has won a place so far, in ticket order. */
export function eligibleEntries(entries, won) {
  const out = new Set(won.map(Number));
  return normaliseEntries(entries).filter((e) => !out.has(e.ticket));
}

const assertRedrawNumber = (n) => {
  if (!(Number.isInteger(n) && n >= 1 && n <= 4294967295)) throw new Error('Redraw number must be 1 to 2^32 - 1');
};

export async function eligibleHash(ref, n, eligible) {
  assertRedrawNumber(n);
  const lines = normaliseEntries(eligible).map((e) => `${e.ticket}|${e.owner}`);
  return sha256(`VERITY-R2|eligible|${ref}|${n}\n${lines.join('\n')}`);
}

/** The R2 redraw: { eligibleCount, eligibleHash, redrawSeed, index, counter, ticket, owner }. */
export async function runRedrawR2({ ref, drawSeed, n, forfeitHash, entries, won, beacon }) {
  assertRedrawNumber(n);
  for (const [name, h] of [['Draw seed', drawSeed], ['Forfeit hash', forfeitHash], ['Beacon randomness', beacon]]) {
    if (!/^[0-9a-f]{64}$/.test(h ?? '')) throw new Error(`${name} must be 64 lowercase hex characters`);
  }
  const eligible = eligibleEntries(entries, won);
  if (eligible.length === 0) throw new Error('No entries left to redraw from');
  const eh = await eligibleHash(ref, n, eligible);
  const be32 = new Uint8Array(4);
  new DataView(be32.buffer).setUint32(0, n);
  const entropy = concat(unhex(drawSeed), enc.encode('redraw'), be32, unhex(forfeitHash), unhex(eh), unhex(beacon));
  const nonce = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(`VERITY-R2|redraw-nonce|${ref}|${n}`)));
  const personalisation = enc.encode(`VERITY-R2|redraw|${ref}|${n}`);
  const pick = await sampleBelow(await HmacDrbg.create(entropy, nonce, personalisation), eligible.length);
  return {
    eligibleCount: eligible.length,
    eligibleHash: eh,
    redrawSeed: hex(await crypto.subtle.digest('SHA-256', concat(entropy, nonce, personalisation))),
    index: pick.value,
    counter: pick.discarded,
    ticket: eligible[pick.value].ticket,
    owner: eligible[pick.value].owner,
  };
}

/** The round a forfeit at scheduledAt (Unix seconds) commits to (spec §5.6.1), as RedrawR2::round. */
export function redrawRound(scheduledAt, closeDelay, ahead, latest = null, sinceLatest = 0) {
  const a = Math.max(3, ahead);
  const planned = roundAt(scheduledAt + Math.max(0, closeDelay));
  let earliest = roundAt(scheduledAt + a);
  if (latest !== null && latest !== undefined) earliest = Math.max(earliest, latest + 1 + Math.ceil((a + Math.max(0, sinceLatest)) / DRAND_PERIOD));
  const late = planned < earliest;
  return { planned, round: late ? earliest : planned, late };
}
