# Draw time separate from the close: findings and plan

Status: **draft, for review.** Engine: [verity-engine#5](https://github.com/Sprouthubuk/verity-engine/pull/5) (draft).
WDS: the `draw-time` branch (draft PR). Nothing is released or tagged.

## The goal

Entries close at `closes_at` (e.g. 19:00 on 24 December). Verity seals the entry list then, and commits to the drand
round that falls at the live draw time `draw_at` (19:30), so the winner can't be known by anyone, us included, until
the live stream.

WDS already stores the two times separately (`competitions.closes_at`, `competitions.draw_at`). The question was the
engine.

## Finding: verity-engine v3.1.0 can't do this per competition

- The planned round is fixed at publish as `round_at(closes_at + VERITY_DRAND_CLOSE_DELAY)`
  (`Beacon::plannedRound()`, spec §4.2). With the default 60 seconds, a 19:00 close commits to the round at about
  19:01, so the winner can be worked out about 29 minutes before the 19:30 stream.
- `VERITY_DRAND_CLOSE_DELAY` is a single global setting. `verity:check` enforces a minimum (60) but no maximum, so
  setting it to 1800 would technically give a 19:30 round. It's still the wrong tool:
  - **It's global.** Every competition would need exactly the same 30-minute gap.
  - **It slows redraws.** R2 redraws use the same delay (spec §5.6.1), so a forfeit redraw would wait 30 minutes.
  - **It misdescribes old draws.** The verification record publishes the *current* delay for every draw, so
    changing it would misdescribe draws made under the old value.
  - **It's a production `.env` change**, which isn't mine to make.
- Nothing in the engine records a per-competition draw time.

So the engine needs a small change. As agreed, it's a draft PR with `src/Engine/` and the vectors untouched.

## Engine change (verity-engine#5, draft)

Spec §4.2.1, new:

- **Publishing.** `Verity::publish()` takes an optional `draw_at`, from the close to 24 hours after it. The planned
  round is `round_at(max(closes_at + delay, draw_at))`: the round at the draw time, never sooner than before.
  Without `draw_at`, the old rule applies.
- **Sealing is unchanged.** Entries are still frozen, fingerprinted and TSA-timestamped at the close (§4.3), and
  the round is still fixed at publish. The round is simply later.
- **Records.** `draw_at` is recorded on `competition.published` (with the planned round) and in the verification
  record (`competition.draw_at`).
- **Extensions.** An extension moves `draw_at` by the same amount as the close (`draw_to` on
  `competition.extended`) and re-plans the round.
- **New database guard.** `verity_comp_draw_at_fixed` means the draw time can't change once published, except
  through an extension. It's registered in `NEEDS_COLUMN`, so hosts that migrate in steps work too.
- **Additive migration.** `verity_competitions.draw_at`, nullable.
- **Verifiers.** No new rule is needed. They already check that the round used is the planned round recorded on the
  chain and that it was published after the close.
- **Fingerprints.** The engine fingerprint is unchanged (`bba741fd…f4eaff`). The draw-path fingerprint changes,
  because Beacon, Publisher and DrawService are in the draw path, as in v3.1.0. `SOURCE-MANIFEST.txt` is
  regenerated. VERSION is not bumped.
- **Tests.**
  - the round is at the draw time: sealed at close, and `BeaconNotReady` until 19:30, then drawn and re-verified
  - validation
  - an extension moves both times
  - the guard refuses a direct update

  The full engine suite (320 PHP tests) and the JS tests pass.

## WDS change (`draw-time`, draft PR against the engine branch)

- **Composer.** `composer.json` requires `dev-draw-time as 3.1.99` for now. This goes back to a version
  constraint once the engine is tagged.
- **Publishing.** `VerityDrawEngine::publish()` passes `draw_at`. Publishing already requires a draw time no
  earlier than the close (WDS #23).
- **Extending.** `extend()` copies the engine's moved `draw_at` back to the competition.
- **Reveal screen.**
  - While entries are open, it counts down to the live draw (`h:mm:ss`).
  - After the close, it counts down to the committed round, which is the one at `draw_at` (or a redraw's round).
- **Copy.** The Admin hints, the Draws page explainer and the Live draws tile now say the round is the one at the
  live draw time, not "about a minute after closing".
- **Tests.**
  - `DrawTimeTest`: publishing commits to the round at `draw_at` and records it; an extension moves both times.
  - Two older test fixtures now set `draw_at` equal to the close, because they use real drand rounds recorded
    for "close + 60 s".

## How the BMW night runs with this (method A)

1. **Publish.** Publishing locks in the 19:00 close and the 19:30 round.
2. **19:00.** Entries close automatically (`verity:process-due`, every minute). The entry list is sealed and
   fingerprinted, and the reveal screen shows "Sealed" and counts down to the round.
3. **19:30.** drand publishes the round, the engine draws within a few seconds, and the presenter presses "Reveal
   the winner".

Don't press **"Close & draw now"** on a competition with a later draw time. That button (spec §16.1) commits to the
next round straight away, so it's only for draws meant to happen at the moment of closing.

Method B (external draw) is unaffected: it has no drand round.

## Before release (your review)

1. Review engine #5, then mark it ready.
2. Release it as **v3.2.0**. It's a feature, so a minor version: set VERSION, date the CHANGELOG, rewrite the
   manifest, tag, and create the GitHub release. The engine fingerprint stays `bba741fd…f4eaff`.
3. Point WDS at `^3.2`, run `composer update sprouthub/verity-engine`, and check that `composer.lock` points at the
   tag. The verifier JS is unchanged, so `WebVerifierCopyTest` stays green.
4. **Publish the BMW only after this is deployed.** Publishing fixes the round, so a BMW published on v3.1.0
   would be locked to the 19:01 round.

## Possible follow-up (not in this change)

An explicit verifier check that the round is at or after the advertised `draw_at`, in all three verifiers (PHP, JS,
Python), with new test vectors. It isn't needed for correctness, because the chain already fixes the planned round,
but it would make the draw time visible on /verify.
