// The public verification of one competition, as a pure function of its published record
// (Verity::verification() on the host, or GET /verity/competitions/{ref}/verification, with its audit events):
// spec §7, in order. Used by verify-page/ and tested in tests/verify-record.test.mjs.
//
// Fails closed (draw-engine review REV-7): a check the record can't support is a failure, never a
// silent skip. Which checks run is decided by what the competition is (launch record, prizes, winners), not
// by optional fields a server could leave out. A draw that didn't use drand is "unverifiable"
// (local demo randomness can't be checked independently): never reported as passing.
//
// Each check: { key, ok: true | false | null, note }  (null = can't be checked independently).
// Verdict: 'ok' (every check true), 'bad' (any false), 'unverifiable' (otherwise). A redraw that is
// scheduled and not yet drawn (protocol R2) is listed in `scheduled`: its commitment is checked now, its result later.
import { sha256, runDraw, entriesHash, allocateInstantWins, instantTableHash, SPECS, SPEC_3, runRedrawR2, redrawRound, REDRAW_R2, eligibleEntries, eligibleHash, carryProblem } from './verity.js';
import { verifyRound, roundTime, QUICKNET } from './drand.js';

/** The first drand round published at or after Unix time t (spec §4.1). */
export const roundAt = (t) => Math.max(1, Math.ceil((t - QUICKNET.genesisTime) / QUICKNET.period) + 1);

/** Canonical JSON (spec appendix A): keys sorted recursively, slashes and Unicode not escaped. */
export function canonicalJson(value) {
  const sort = (x) =>
    Array.isArray(x)
      ? x.map(sort)
      : x && typeof x === 'object'
        ? Object.fromEntries(
            Object.keys(x)
              .sort()
              .map((k) => [k, sort(x[k])]),
          )
        : x;
  return JSON.stringify(sort(value));
}

/** An audit event's hash, as AuditChain::hash. */
export const auditHash = (e) => sha256(`${e.prev_hash}|${e.seq}|${e.type}|${canonicalJson(e.payload)}|${e.occurred_at}`);

const HEX64 = /^[0-9a-f]{64}$/;
const isTicket = (t) => Number.isSafeInteger(t) && t >= 1;
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/**
 * A draw that closed below its minimum and was carried over (D2, spec §13.3): never drawn, so there is
 * no seed to check. Its frozen list must hash to the published value, have fewer entries than the
 * minimum, and match the competition.carried record on the audit chain (when given).
 */
export async function verifyCarried(v) {
  const checks = [];
  const add = (key, ok, note) => (checks.push({ key, ok, note }), ok);
  const c = v?.competition ?? {};
  const vt = v?.verity ?? {};
  const to = v?.carried_to ?? {};
  const entries = Array.isArray(v?.entries) ? v.entries : null;
  if (vt.spec !== SPEC_3 || c.status !== 'carried' || !entries || !HEX64.test(String(vt.entries_hash ?? ''))) {
    add('entries', false, 'The carried-over record, its entry list or its hash is missing or malformed.');
    return { checks, verdict: 'bad' };
  }
  let eh = null;
  try {
    eh = await entriesHash(c.ref, entries, vt.spec);
  } catch {
    eh = null;
  }
  const okList = eh === vt.entries_hash && Number(vt.entries_count) === entries.length;
  add('entries', okList, okList ? `${entries.length.toLocaleString('en-GB')} entries hash to the published value.` : 'The entry list differs from the one frozen at close.');
  const min = Number(vt.min_entries ?? to.min);
  add(
    'minimum',
    Number.isSafeInteger(min) && min > 0 && entries.length < min,
    entries.length < min
      ? `${entries.length.toLocaleString('en-GB')} entries, under the minimum of ${min.toLocaleString('en-GB')}: not drawn, carried to ${to.ref}.`
      : 'It reached its minimum, so it should have been drawn.',
  );
  const audit = Array.isArray(v?.audit) ? v.audit : null;
  if (audit) {
    const e = [...audit]
      .sort((a, b) => a.seq - b.seq)
      .reverse()
      .find((x) => x.type === 'competition.carried');
    const p = e?.payload ?? {};
    const ok = !!e && p.entries_hash === vt.entries_hash && p.entries_count === entries.length && p.carried_to === to.ref && p.min_entries === min;
    add('carried', ok, ok ? `The carry-over to ${to.ref} is on the audit chain, as recorded at close.` : 'The carry-over isn’t the one recorded on the audit chain.');
  }
  const verdict = checks.some((x) => x.ok === false) ? 'bad' : checks.some((x) => x.ok === null) ? 'unverifiable' : 'ok';
  return { checks, verdict };
}

/**
 * VERITY-3 (spec §13.4): every draw carried into this one, checked against its own published record:
 * its list hashes to its frozen hash, and each of its entries is in this list once, with its owner and origin.
 */
async function carriedChecks(v, entries, previous, add) {
  const ref = v?.competition?.ref;
  for (const p of Array.isArray(v?.carried_in) ? v.carried_in : []) {
    const rec = previous.find((r) => r?.competition?.ref === p.ref);
    if (!rec || !Array.isArray(rec.entries)) {
      add(`carried-${p.ref}`, false, `The record of ${p.ref}, whose entries were carried into this draw, couldn’t be read.`);
      continue;
    }
    let why;
    try {
      const h = await entriesHash(p.ref, rec.entries, SPEC_3);
      why = h !== p.entries_hash || h !== rec.verity?.entries_hash ? `${p.ref}’s list doesn’t match the hash frozen when it was carried over` : carryProblem(p.ref, rec.entries, ref, entries);
    } catch (e) {
      why = e.message;
    }
    add(
      `carried-${p.ref}`,
      !why,
      why ? `${why}.` : `All ${p.entries_count.toLocaleString('en-GB')} entries carried from ${p.ref} are in this list once each, with their owner and the month they were bought in.`,
    );
  }
}

export async function verifyRecord(v, { previous = [] } = {}) {
  const checks = [];
  const add = (key, ok, note) => {
    checks.push({ key, ok, note });
    return ok;
  };
  const c = v?.competition ?? {};
  const vt = v?.verity ?? {};
  const spec = vt.spec ?? null;
  const ref = c.ref ?? vt.competition_ref;
  const seed = vt.revealed_seed;
  const audit = Array.isArray(v?.audit) ? [...v.audit].sort((a, b) => a.seq - b.seq) : null;
  const last = (types) => audit && [...audit].reverse().find((e) => types.includes(e.type));

  if (spec !== null && !SPECS.includes(spec)) {
    add('spec', false, `Unknown spec ${spec}.`);
    return { checks, verdict: 'bad' };
  }

  // 1. The seed, against the commitment published at launch.
  const okSeed = typeof seed === 'string' && HEX64.test(seed) && HEX64.test(String(vt.commitment ?? ''));
  const commit = okSeed ? await sha256(seed) : null;
  add(
    'commitment',
    okSeed && commit === vt.commitment,
    !okSeed ? 'The revealed seed or the commitment is missing or malformed.' : commit === vt.commitment ? 'SHA-256 of the revealed seed matches.' : `Mismatch: got ${commit.slice(0, 16)}…`,
  );

  // 2. The instant-win table, whenever the competition has instant prizes.
  const prizes = Array.isArray(v?.instant_prizes) ? v.instant_prizes : [];
  if (prizes.length || vt.instant_table_hash) {
    const total = prizes.reduce((s, p) => s + (Number.isSafeInteger(p.quantity) && p.quantity >= 0 ? p.quantity : Infinity), 0);
    const max = Number(c.max_tickets);
    if (!okSeed || !prizes.length || !HEX64.test(String(vt.instant_table_hash ?? '')) || !Array.isArray(v.instant_table) || !Number.isSafeInteger(max) || !(total <= max)) {
      add('instant', false, 'The instant-win table, its hash or its prize list is missing or malformed.');
    } else {
      const table = await allocateInstantWins({ ref, serverSeed: seed, maxTickets: max, prizes, spec });
      const th = await instantTableHash(ref, table, spec, max);
      const ok =
        th === vt.instant_table_hash &&
        same(
          table.map((t) => [t.ticket, t.prize]),
          v.instant_table.map((t) => [t.ticket, t.prize]),
        );
      add('instant', ok, ok ? `All ${table.length} instant-win tickets re-derived from the seed.` : 'The instant-win table does not match the seed.');
    }
  }

  // 3. The entry list (every closed competition hashes it, instant-only ones too).
  const entries = Array.isArray(v?.entries) ? v.entries : null;
  const winningTickets = Array.isArray(v?.winning_tickets) ? v.winning_tickets : [];
  const winnerCount = vt.winner_count;
  // An end draw is due for anything launched with end prizes (the launch record says so, and the card lists
  // them), anything with a winner count, and anything that publishes winners.
  const launched = Number(last(['competition.published'])?.payload?.winner_count ?? 0);
  const hasDraw = launched > 0 || (Array.isArray(c.end_prizes) && c.end_prizes.length > 0) || (winnerCount ?? 0) > 0 || winningTickets.length > 0;
  const okEntries = entries !== null && entries.every((e) => isTicket(e?.ticket) && /^[0-9a-f]{1,64}$/.test(String(e?.owner)));
  // (VERITY-3 entries also carry origin and from; entriesHash checks them.)
  if (!okEntries || !HEX64.test(String(vt.entries_hash ?? ''))) {
    add('entries', false, 'The entry list or its hash is missing or malformed.');
  } else if (hasDraw && !(Number.isSafeInteger(winnerCount) && winnerCount >= 1 && winnerCount <= entries.length)) {
    add('entries', false, 'This competition has an end draw, but its winner count is missing or impossible.');
  } else {
    const eh = await entriesHash(ref, entries, spec).catch(() => null); // a malformed VERITY-3 origin is refused
    const ok = eh === vt.entries_hash && Number(vt.entries_count) === entries.length;
    add(
      'entries',
      ok,
      ok
        ? `${entries.length.toLocaleString('en-GB')} entries hash to the published value.`
        : Number(vt.entries_count) !== entries.length
          ? `The record says ${vt.entries_count} entries but lists ${entries.length}.`
          : 'The entry list differs from the one locked at close.',
    );
  }

  // D2 (VERITY-3): a draw with a minimum is drawn only once it's reached; carried entries match the draws they came from.
  if (okEntries && Number(vt.min_entries) > 0) {
    add(
      'minimum',
      entries.length >= Number(vt.min_entries),
      entries.length >= Number(vt.min_entries)
        ? `${entries.length.toLocaleString('en-GB')} entries, at least the minimum of ${Number(vt.min_entries).toLocaleString('en-GB')}.`
        : `Only ${entries.length} entries, under the minimum of ${vt.min_entries}: it should have been carried over, not drawn.`,
    );
  }
  if (okEntries && spec === SPEC_3) await carriedChecks(v, entries, previous, add);

  let draw = null;
  if (hasDraw) {
    // 4. The beacon: drand only, BLS-verified, published after entries froze, the round fixed at launch.
    const closedEvt = last(['competition.closed']);
    const closedAt = Math.max(Date.parse(vt.closed_at) / 1000 || 0, closedEvt ? Date.parse(closedEvt.occurred_at) / 1000 : 0);
    const round = vt.beacon_round,
      planned = vt.beacon_planned_round,
      latest = vt.drand_latest_round ?? null;
    if (vt.beacon_source !== 'drand-quicknet') {
      add('beacon', null, 'This draw used local demo randomness, not the drand beacon, so it can’t be checked independently.');
    } else if (!Number.isSafeInteger(round) || !Number.isSafeInteger(planned) || !(closedAt > 0)) {
      add('beacon', false, 'The drand round, the round planned at launch or the closing time is missing.');
    } else {
      const sig = vt.beacon_signature
        ? await verifyRound({ round, randomness: vt.beacon_randomness, signature: vt.beacon_signature }) // our own copy of the key
        : { valid: false, reason: 'No signature was published for this round.' };
      const future = roundTime(round) > closedAt && (latest === null || round > latest);
      // Fixed at launch from the advertised closing time (spec §4.2): always a round after it. (The exact
      // delay can change between competitions, so the round is matched to the chain's launch record below.)
      const plannedFromClose = roundTime(planned) > Date.parse(c.closes_at) / 1000;
      const whichRound = vt.beacon_late ? round > planned : round === planned;
      const ok = sig.valid && future && plannedFromClose && whichRound;
      const late = vt.beacon_late ? ' Entries closed late, so a fresh future round was used instead of the one planned at launch.' : '';
      add(
        'beacon',
        ok,
        ok
          ? `drand round ${round}: signature verified against the drand network’s public key, and published after entries closed.${late}`
          : !sig.valid
            ? sig.reason
            : !future
              ? 'This round was already public when entries closed.'
              : !plannedFromClose
                ? 'The round planned at launch isn’t after the advertised closing time.'
                : 'This is not the round fixed when the draw was launched.',
      );
    }

    // 5. The draw itself: draw seed and winners, in order (redraws included: the winner count grows).
    try {
      if (!okSeed || !okEntries) throw new Error('missing inputs');
      draw = await runDraw({ ref, serverSeed: seed, entries, beacon: vt.beacon_randomness, winnerCount, spec });
    } catch {
      draw = null;
    }
    add(
      'seed',
      !!draw && draw.drawSeed === vt.draw_seed,
      draw ? (draw.drawSeed === vt.draw_seed ? 'Recomputed draw seed matches.' : 'Draw seed does not match.') : 'The draw couldn’t be re-run from the published inputs.',
    );
    const got = draw ? draw.winners.map((w) => w.ticket) : [];
    const okW = !!draw && same(got, winningTickets);
    add(
      'winners',
      okW,
      okW ? `Your browser picked ticket ${got.map((t) => String(t).padStart(6, '0')).join(', ')}: the same result.` : `Your browser picked ${got.join(', ') || 'nothing'}: this does not match.`,
    );
  }

  // 6. The audit chain: each of this competition's events hashes to its published hash, and the record
  // says what the chain said at the time (commitment at launch, entries at close, seed at the draw).
  if (!audit) {
    add('audit', null, 'The audit events weren’t available, so the record wasn’t matched against the chain.');
  } else {
    const problems = [];
    for (const e of audit) {
      if (e.payload?.withheld) continue; // a field kept back until it's public: its hash re-checks then
      if ((await auditHash(e)) !== e.hash) problems.push(`event ${e.seq} (${e.type}) doesn’t hash to its published hash`);
    }
    const published = last(['competition.published']);
    if (!published) problems.push('there is no launch record');
    else {
      if (published.payload.commitment !== vt.commitment) problems.push('the commitment isn’t the one published at launch');
      if ((published.payload.spec ?? 'VERITY-1') !== (spec ?? 'VERITY-1')) problems.push('the spec isn’t the one published at launch');
    }
    const tableEvt = last(['competition.published', 'competition.prizes_changed']);
    if (vt.instant_table_hash && tableEvt && !tableEvt.payload.withheld && tableEvt.payload.instant_table_hash !== vt.instant_table_hash)
      problems.push('the instant-win table hash isn’t the one published at launch');
    const plannedEvt = [...(audit ?? [])].reverse().find((e) => ['competition.published', 'competition.extended', 'competition.updated'].includes(e.type) && e.payload.beacon_planned_round != null);
    if (hasDraw && plannedEvt && plannedEvt.payload.beacon_planned_round !== vt.beacon_planned_round) problems.push('the planned drand round isn’t the one on the chain');
    const closed = last(['competition.closed']);
    if (!closed) problems.push('there is no close record');
    else {
      const p = closed.payload;
      if (p.entries_hash !== vt.entries_hash || p.entries_count !== vt.entries_count) problems.push('the entry list isn’t the one recorded at close');
      if (hasDraw && ((p.beacon_round ?? null) !== (vt.beacon_round ?? null) || !!p.beacon_late !== !!vt.beacon_late)) problems.push('the drand round isn’t the one committed at close');
      if ((p.drand_latest_round ?? null) !== (vt.drand_latest_round ?? null)) problems.push('drand’s latest round at close doesn’t match');
    }
    if (hasDraw) {
      const done = last(['draw.completed']);
      if (!done) problems.push('there is no draw record');
      else if (!done.payload.withheld) {
        // R1 redraws were extra positions of the draw; R2 redraws (protocol field) are checked in step 8.
        const redrawn = audit.filter((e) => e.type === 'draw.redrawn' && !e.payload.protocol).map((e) => e.payload.winning_ticket);
        if (v?.redraw_r2_since && audit.some((e) => e.type === 'draw.redrawn' && !e.payload.protocol && !(e.occurred_at < v.redraw_r2_since)))
          problems.push(`a redraw after ${v.redraw_r2_since} wasn’t made under protocol R2`);
        const r2 = audit.filter((e) => e.type === 'draw.redrawn' && e.payload.protocol).length;
        if (r2 !== (Array.isArray(v?.redraws) ? v.redraws.filter((r) => r.protocol === REDRAW_R2 && r.status === 'done').length : 0))
          problems.push('the redraws on the chain aren’t the ones in the record');
        if (done.payload.draw_seed !== vt.draw_seed || done.payload.server_seed !== seed) problems.push('the draw seed or revealed seed isn’t the one recorded at the draw');
        if (!same([...done.payload.winning_tickets, ...redrawn], winningTickets)) problems.push('the winners aren’t the ones recorded at the draw and redraws');
      }
    } else {
      const fin = last(['competition.finished']);
      if (fin && fin.payload.server_seed !== seed) problems.push('the revealed seed isn’t the one recorded when it finished');
    }
    add(
      'audit',
      problems.length === 0,
      problems.length ? `On the audit chain: ${problems.join('; ')}.` : `${audit.length} audit event${audit.length === 1 ? '' : 's'} re-hashed and matched to this record.`,
    );
  }

  // 7. Independent witnesses of the close (threat model G-8): a timestamp authority's time for the close
  // must be before the drand round was published. (Its signature: openssl ts -verify on the token.)
  const witnesses = Array.isArray(v?.close_witnesses) ? v.close_witnesses.filter((w) => w.kind === 'rfc3161' && w.ok && w.witnessed_at) : [];
  if (witnesses.length && vt.beacon_round && vt.beacon_source === 'drand-quicknet') {
    const roundAtSec = roundTime(vt.beacon_round);
    const early = witnesses.every((w) => Date.parse(w.witnessed_at) / 1000 < roundAtSec);
    add(
      'witness',
      early,
      early
        ? `Timestamped by an independent authority before drand round ${vt.beacon_round} was published.`
        : `The independent timestamp of the close is after drand round ${vt.beacon_round} was published.`,
    );
  }

  // 8. Redraws (spec §5.6.3), in order. R1: an extra position of the draw (step 5). R2: bound to the forfeit on
  // the chain, its eligible list rebuilt, its drand round still in the future when the forfeit was recorded,
  // then (once drawn) the round BLS-verified and the replacement re-derived.
  const scheduled = [];
  const redraws = Array.isArray(v?.redraws) ? [...v.redraws].sort((a, b) => a.redraw - b.redraw) : [];
  const won = [...winningTickets];
  const forfeited = []; // step 1: a forfeited ticket is a winner not already forfeited
  for (const r of redraws) {
    const key = `redraw-${r.redraw}`;
    if (r.protocol !== REDRAW_R2) {
      if (Number.isSafeInteger(r.forfeited_ticket)) forfeited.push(r.forfeited_ticket);
      // R1 only before R2 came in: a later one would be a downgrade to a replacement known in advance.
      const r1Evt = (audit ?? []).filter((e) => e.type === 'draw.redrawn' && !e.payload?.protocol && e.payload?.winning_ticket === r.winning_ticket).at(-1);
      const tooLate = v?.redraw_r2_since && r1Evt && !(r1Evt.occurred_at < v.redraw_r2_since);
      const ok = r.protocol === 'R1' && winningTickets.includes(r.winning_ticket) && !tooLate;
      add(
        key,
        ok,
        ok
          ? `Redraw ${r.redraw} (protocol R1, made before R2): position ${winningTickets.indexOf(r.winning_ticket) + 1} of the draw above.`
          : tooLate
            ? `Redraw ${r.redraw} was made after ${v.redraw_r2_since} without protocol R2.`
            : `Redraw ${r.redraw}: protocol ${r.protocol} isn’t known, or its ticket isn’t part of the draw.`,
      );
      continue;
    }
    const problems = [];
    const byType = (type) => (audit ?? []).filter((e) => e.type === type && e.payload?.redraw === r.redraw);
    const forfeit = (audit ?? []).find((e) => e.seq === r.forfeit_seq);
    const sched = byType('draw.redraw_scheduled').at(-1);
    if (!audit) problems.push('the audit events weren’t available');
    if (!forfeit || forfeit.type !== 'draw.winner_forfeited' || forfeit.hash !== r.forfeit_hash || forfeit.payload.redraw !== r.redraw || forfeit.payload.forfeited_ticket !== r.forfeited_ticket)
      problems.push('its forfeit isn’t the record on the audit chain');
    if (!won.includes(r.forfeited_ticket)) problems.push('the forfeited ticket wasn’t a winner');
    else if (forfeited.includes(r.forfeited_ticket)) problems.push('the forfeited ticket had already been forfeited');
    forfeited.push(r.forfeited_ticket);
    if (
      !sched ||
      sched.seq !== r.schedule_seq ||
      sched.payload.forfeit_hash !== r.forfeit_hash ||
      sched.payload.forfeit_seq !== r.forfeit_seq ||
      sched.payload.eligible_hash !== r.eligible_hash ||
      sched.payload.eligible_count !== r.eligible_count ||
      sched.payload.beacon_round !== (r.beacon_round ?? null) ||
      sched.payload.scheduled_at !== r.scheduled_at ||
      !!sched.payload.beacon_late !== !!r.beacon_late ||
      sched.payload.protocol !== REDRAW_R2
    )
      problems.push('its schedule isn’t the record on the audit chain');
    let eligibleOk = false;
    try {
      if (!okEntries) throw new Error('no entries');
      // Only the list, not the pick: the same eligible list the draw below re-derives.
      const el = eligibleEntries(entries, won);
      eligibleOk = el.length === r.eligible_count && (await eligibleHash(ref, r.redraw, el)) === r.eligible_hash;
    } catch {
      eligibleOk = false;
    }
    if (!eligibleOk) problems.push('the eligible list isn’t the entries without the tickets that had already won');
    const drand = r.beacon_source === 'drand-quicknet';
    if (drand) {
      // The rule re-run from the schedule record alone: its time, its margins, drand's latest round (§5.6.1).
      const sp = sched?.payload ?? {};
      const at = Date.parse(sp.scheduled_at) / 1000;
      const margins = Number.isSafeInteger(sp.close_delay_seconds) && sp.close_delay_seconds >= 60 && Number.isSafeInteger(sp.commit_ahead_seconds) && sp.commit_ahead_seconds >= 3;
      const rule = Number.isFinite(at) && margins ? redrawRound(at, sp.close_delay_seconds, sp.commit_ahead_seconds, sp.drand_latest_round ?? null, (sp.ms_since_latest ?? 0) / 1000) : null;
      const round = r.beacon_round;
      if (
        !rule ||
        !Number.isSafeInteger(round) ||
        r.beacon_planned_round !== rule.planned ||
        round !== rule.round ||
        !!r.beacon_late !== rule.late ||
        (sp.drand_latest_round ?? null) !== (r.drand_latest_round ?? null) ||
        !(roundTime(round) > Date.parse(sched.occurred_at) / 1000)
      ) {
        problems.push('its drand round wasn’t safely in the future when the forfeit was recorded');
      }
      for (const w of (r.witnesses ?? []).filter((w) => w.kind === 'rfc3161' && w.ok && w.witnessed_at)) {
        if (!(Date.parse(w.witnessed_at) / 1000 < roundTime(round))) problems.push('its independent timestamp is after the round was published');
      }
    }
    const roundNote =
      drand && Number.isSafeInteger(r.beacon_round)
        ? `drand round ${r.beacon_round.toLocaleString('en-GB')}, available at about ${new Date(roundTime(r.beacon_round) * 1000).toLocaleTimeString('en-GB', { timeZone: 'Europe/London', hour: '2-digit', minute: '2-digit' })}`
        : 'the local demo beacon';
    if (r.status !== 'done') {
      scheduled.push({ redraw: r.redraw, round: r.beacon_round ?? null, at: drand ? roundTime(r.beacon_round) : null, late: !!r.beacon_late });
      add(
        key,
        problems.length ? false : true,
        problems.length
          ? `Redraw ${r.redraw}: ${problems.join('; ')}.`
          : `Redraw ${r.redraw} scheduled: ${roundNote}. It was fixed when the forfeit was approved, before anyone could know that round, so nobody can know the new winner yet.${r.beacon_late ? ' (The server’s clock was behind drand’s, so a later round was used.)' : ''}`,
      );
      break; // one at a time: nothing after a scheduled redraw
    }
    let pick = null;
    if (drand) {
      const sig = r.beacon_signature
        ? await verifyRound({ round: r.beacon_round, randomness: r.beacon_randomness, signature: r.beacon_signature })
        : { valid: false, reason: 'No signature was published for this round.' };
      if (!sig.valid) problems.push(`drand round ${r.beacon_round}: ${sig.reason}`);
    }
    try {
      pick = await runRedrawR2({ ref, drawSeed: vt.draw_seed, n: r.redraw, forfeitHash: r.forfeit_hash, entries, won, beacon: r.beacon_randomness });
    } catch {
      pick = null;
    }
    const done = byType('draw.redrawn').at(-1);
    if (!pick || pick.ticket !== r.winning_ticket || pick.redrawSeed !== r.redraw_seed || pick.index !== r.index) problems.push('re-running it doesn’t give the published winner');
    if (
      !done ||
      done.payload.protocol !== REDRAW_R2 ||
      done.payload.winning_ticket !== r.winning_ticket ||
      done.payload.beacon_randomness !== r.beacon_randomness ||
      done.payload.redraw_seed !== r.redraw_seed ||
      done.payload.schedule_seq !== r.schedule_seq
    )
      problems.push('its result isn’t the record on the audit chain');
    const late = r.ran_late_seconds ? ` The scheduler drew it ${Math.round(r.ran_late_seconds / 60)} minutes after the round came out; the result is the same.` : '';
    if (!drand && !problems.length) {
      add(key, null, `Redraw ${r.redraw} (protocol R2) used local demo randomness, so it can’t be checked independently.`);
    } else {
      add(
        key,
        problems.length === 0,
        problems.length
          ? `Redraw ${r.redraw}: ${problems.join('; ')}.`
          : `Redraw ${r.redraw} (protocol R2): fixed to ${roundNote} when the forfeit was approved; your browser re-drew ticket ${String(pick.ticket).padStart(6, '0')} from ${pick.eligibleCount.toLocaleString('en-GB')} eligible entries.${late}`,
      );
    }
    won.push(r.winning_ticket);
  }

  const verdict = checks.some((x) => x.ok === false) ? 'bad' : checks.some((x) => x.ok === null) ? 'unverifiable' : 'ok';
  return { checks, verdict, draw, scheduled };
}
