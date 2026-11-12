# Morning report: 12 November 2026

All ten items are done. Items 1–7 are merged and live on production (nothing published there). Items 8–9 are **draft PRs for your review**. Item 10 is a plan. Every PR was green on all three checks.

| # | Item | Status | PR |
|---|---|---|---|
| 1 | Postal 48-hour rule, received date + time, entrant emails | Merged | #5 |
| 2 | Draws channels (Instagram primary, optional YouTube) | Merged | #6 |
| 3 | Pre-launch state (teaser, countdown, no entry buttons) | Merged | #7 |
| 4 | Draft preview link for the client | Merged | #8 |
| 5 | Email via Resend; every customer email reviewed | Merged | #9 |
| 6 | Admin Reports (date range, CSV exports) | Merged | #10 |
| 7 | Event sales stand mode | Merged | #11 |
| 8 | Two draw methods (Verity live draw / external) | **Draft** | #12 + verity-engine#4 |
| 9 | Live draw reveal screen | **Draft**, stacked on #12 | #13 |
| 10 | Instant wins investigation | Plan, PR not merged | #14 |

Links: github.com/Sprouthubuk/wds-production/pull/N · github.com/Sprouthubuk/verity-engine/pull/4

## Decisions worth knowing (all in docs/decisions-log.md)
- Postal: staff log the time received as well as the date (the rule is exact to the minute). Every logged entry now emails the entrant.
- Live draws on YouTube use a new optional setting, `drawsYoutubeUrl`, so the builds channel stays as it is.
- Pre-launch teasers use a separate endpoint, so draft prices and ticket counts never leave the API.
- Email: fixed a broken forgot/set-password flow (the link pointed at a page that didn't exist). Added a winner email, sent when you press Publish result.
- Reports: the customer export holds marketing opt-ins only. Self-excluded and suspended accounts are always left out. CSVs are protected against formula injection.
- CLAUDE.md now records: free postal entry only, no skill question.

## Items 8–9: what to review
- v3.0.3 couldn't do either method. It can close early, but the close still commits to the round planned at publish, and it can't seal without drawing.
- Engine changes are on a draft branch (verity-engine#4). `src/Engine` and the vectors are untouched, so the engine fingerprint is still `bba741fd…f4eaff`. 305 engine tests pass, plus 10/10 CI checks.
- **The main call:** method B (external draw) adds `recordExternalResult`, the one engine API that names a winner. Verity proves the list was sealed and the ticket was in it; the randomness of the pick rests on the livestream. See docs/draw-methods-plan.md.
- Redraws after a forfeit on an external draw use drand (R2), not a second livestream pick.
- Reveal screen: the presenter presses Reveal (or Space). `?rehearse=1` lets you set up OBS. Checked at 390/1440, and it respects reduced motion.

## Blocked / not done
- Engine still needs: JS and Python verifier support for external records, and a THREAT-MODEL.md row. Until then /verify shows external draws without the in-browser re-run.
- WDS #12 pins `dev-draw-methods`. Release the engine (v3.1.0) and pin the tag before merging.

## Needs you
1. **Forge env:** `RESEND_API_KEY`, then `config:cache`, `queue:restart` and `wds:mail-test` (forge/README §7).
2. **Resend DNS:** verify wdsoriginals.com (DKIM/SPF/DMARC records from Resend).
3. **Admin Settings:** `drawsYoutubeUrl`, if draws will also go out on YouTube.
4. **Review:** verity-engine#4, then #12 and #13. Decide whether to keep method B.
5. **Client input, instant wins** (docs/instant-wins-plan.md):
   - press-to-reveal vs automatic;
   - random ticket numbers (the engine requires them);
   - refunds after a reveal are money only;
   - small-prize fulfilment.

Nothing was published, reset, deployed by hand, or changed on Forge or in secrets.
