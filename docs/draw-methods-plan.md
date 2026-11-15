# Draw methods: findings and plan (item 8, DRAFT)

Status: **draft for the owner's review.** Nothing here is merged or released.
- Verity: [Sprouthubuk/verity-engine#4](https://github.com/Sprouthubuk/verity-engine/pull/4), branch `draw-methods`.
- WDS: the draft PR that builds against it (`composer.json`: `dev-draw-methods as 3.0.99`).

The goal is two ways to draw, chosen per competition before publish:
- **A. Verity live draw:** press "Close & draw now" on stream; the winner appears about a minute later.
- **B. External draw:** Verity seals the entries, we draw on stream with our own RNG, then record the ticket and the livestream link.

## What verity-engine v3.0.3 supports

| Need | v3.0.3 | Notes |
|---|---|---|
| Close early | **Yes**: `Verity::close($c, $reason)` | The public reason (15+ characters) is recorded first as `competition.closed_early` and becomes the public notice. |
| Draw about a minute after an early close | **No** | The close commits to the round **planned at publish** (`closes_at` + 60s). SPEC §4: "Either way the close commits to the planned round". An early close would wait until the original closing time. |
| Seal and fingerprint without drawing | **No** | Every close of a drawn competition commits to a drand round, and `verity:process-due` draws it. |
| Record an external result | **No, by design** | The API has no way to name a winner (`ThreatModelControlsTest::test_the_api_has_no_way_to_name_a_winner`); the triggers refuse hand-written winners. |
| Forfeit / redraw | **Yes**: R2 from a future drand round | It needs a `draw.completed` event with a `draw_seed` to start from. |

**Conclusion:** both methods need engine changes. None of them are in `src/Engine` or the vectors, so the engine fingerprint stays `bba741fd…f4eaff`. Only the draw-path fingerprint changes.

## Engine changes (verity-engine#4, spec §16)

- **A. `Verity::closeAndDrawNow($c, $reason)`:**
  - Same close as before, but it commits to the **first round safely after the close**, with the margins of a late close: `commit_ahead_seconds` past now and past drand's latest round. The planned round stays on record.
  - `competition.closed` carries `draw_now: true`. `process-due` draws it as normal, and `closes_at` remains the backstop.
  - Verifiers check the round came no more than ahead + 30s after the close, so we can't pick a later round.
- **B. `draw_method = external` at publish:**
  - One end prize, no instant wins, no minimum. Fixed by the guard `verity_comp_method_fixed`.
  - The close **seals**: entries are frozen and `entries_hash` is set, with no round committed. `draw()` refuses it, and `process-due` seals it at `closes_at` but never draws it.
  - `Verity::recordExternalResult($c, $ticket, $httpsUrl, $by)` records the result, once. The ticket must be in the sealed list. It writes `draw.completed {method: external, entries_hash, winning_tickets, livestream_url, recorded_by, …}` and reveals the seed.
  - `draw_seed = SHA-256("VERITY-EXTERNAL|ref|entries_hash|ticket")` feeds **R2**, so forfeit and redraw work unchanged.
- **Verification record:**
  - **The random number tool** (name + https link, e.g. random.org) is required at publish, can change with `Verity::setExternalTool()` until the close (each change on the chain as `competition.external_tool`), and is then **sealed with the entry fingerprint**: `external_seal = SHA-256("VERITY-EXTERNAL-SEAL|ref|entries_hash|name|url")` on `competition.closed`. A result is refused without it; the livestream link is required.
  - `verity.draw_now`, `verity.draw_method` and `verity.external` appear only when they apply, so old records are byte-identical.
  - All three verifiers (PHP, browser JS, Python) handle both methods, with shared known-answer vectors. The pick itself shows as a **WARN** (browser: verdict `external`) with the statement: "Verity verified the sealed entry list and that the winning ticket was on it. The random selection was made externally on the linked livestream." Risks are in the engine's THREAT-MODEL.md §5.12.
- **Tests:** 305 engine tests pass (5 new), plus PHPStan level 8, Pint, the production suite and `manifest --check`.
- **Still to do before a release:**
  - the browser (JS) and Python verifiers;
  - a THREAT-MODEL.md row for method B;
  - a version bump and tag, then WDS pins the tag instead of the branch.

## WDS changes (draft PR)

- **Data:** `competitions.draw_method`, `competitions.draw_tool_name` / `draw_tool_url`, and `draws.method`, `draws.livestream_url` (additive migration). The method is sent to Verity at publish and locked afterwards (422 if changed).
- **Admin → Competition:** "How it's drawn" radio (Verity live draw / External draw), editable only while a draft.
- **Admin → Draws, for a live competition:**
  - **Close & draw now** (method A) or **Close entries** (method B), each with a confirmation. The public reason is automatic: "Entries closed live on the draw stream at 8:02pm on 30 October 2026 (UK time)."
- **Admin → Draws, for a sealed external competition:**
  - Download the sealed entry CSV: `ticket,name,source`, with first name + initial; the filename and an `X-Entries-Hash` header carry the fingerprint.
  - Enter the winning ticket and the https livestream link, then **Record result**. **Publish result** is unchanged.
- **Admin → competition form:** choosing External shows the random number tool fields (required to publish).
- **Admin → Draws, while live:** the tool is shown and can be changed ("Change the random number tool"); **Close entries** is disabled until a tool is named, and its confirmation names the tool it seals.
- **Endpoints (admin only):** `POST /api/admin/competitions/{id}/close-now`, `/close-entries`, `/draw-tool` and `/external-result`.
- **Certificate and `/verify/{id}`:**
  - The certificate gets `method`, `drawNow`, `livestreamUrl`, `recordedAt`, `drawTool`, `seal` and `statement`.
  - External draws show "Drawn externally on livestream" with the link and the **sealed entry fingerprint**, and their own "How it works".
  - /verify states the statement above plainly, shows the tool and the seal, and runs the in-browser verifier for external draws too.
- **Emails:** the winner email (item 5) is unchanged; it goes out on Publish result for both methods.
- **Tests:** `DrawMethodsTest` covers method A end to end on a drand round, method B end to end (seal, CSV, refusals, record, publish, certificate) and admin-only access.

## Decisions for the owner

1. **Method B is a trust trade-off.** Verity can prove *when* the list was sealed and that the winner was on it, but not that the pick was random. That rests on the livestream. `recordExternalResult` is the one API method that names a winner; it's limited to competitions published as external, from the sealed list, once. The alternative is to drop B and use A on stream.
2. **Redraws for an external draw use drand (R2),** not a second livestream pick. They stay verifiable and need no extra admin step. The alternative is an external redraw; that would need a second engine API.
3. **One prize per external competition** (as WDS runs today). Several prizes would need a list of tickets in order.
4. After review: merge #4, release `verity-v3.1.0`, re-point WDS at `^3.1`, run `verity:check` on staging, then merge the WDS PR.
