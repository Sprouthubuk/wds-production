# Morning report: overnight QA, 15–16 November 2026

Nothing was deployed, published or reset. Nothing on Forge, in `.env`, in secrets, or on staging or production
was touched. No real Cashflows, Resend or drand call was made. PR #25 was left alone. No Verity or draw-path code
was changed.

## Top 5 things to know

1. **Three draft PRs need your decision (money and compliance).** Each has a regression test and is explained
   below:
   - **#72:** site credit could be spent twice.
   - **#69:** a payment for the wrong amount was taken but left unrefundable.
   - **#71:** self-excluded or closed accounts could still enter by post.
2. **Security hole closed (#61).** Production runs `PAYMENT_DRIVER=fake` until Cashflows goes live. Until now,
   anyone could `POST /api/payments/webhook {"order":"WDS-…"}` and have their own order marked paid. That endpoint
   now only exists off production with the fake driver. #68 also makes a mistyped driver an error instead of a
   silent fake gateway.
3. **Spend limits reset at the wrong time in summer (#70, fixed).** Daily, weekly and monthly limits used UTC days,
   so in summer a customer could spend a second day's limit between midnight and 1am UK time. They now run on UK
   time, like the site-wide cap.
4. **Photos (#63, #75).** Uploaded prize photos would all have been broken images (#63). They also get a 720px
   copy for cards and thumbnails now (#75), so phones don't download full-size originals.
5. **Production must switch to `PAYMENT_DRIVER=cashflows` before launch.** With `fake` there, checkout sends
   customers to the fake payment page, which correctly 404s on production.

**Scheduled `verity:check` "exit 1" on staging:** explained by Jason. It's the deliberate local-randomness test
draw WDST-PT11VI on staging, which the check correctly flags. No code change is needed. Its output is kept in
`storage/logs/verity-check-schedule.log` (#60).

## Baseline → now

| | Baseline (main @ 6be81a3) | Now (main @ 364a55a) |
|---|---|---|
| PHP tests | 186 (184 passed, 2 skipped) | 198 (196 passed, 2 skipped), plus 3 more in the draft PRs |
| Web tests (node) | 19/19 | 23/23 |
| Website build | OK | OK, plus `astro check` (strict): 0 errors |
| Larastan | not installed | level 5, 227 pre-existing findings in a baseline, 0 new |
| `composer audit` / `npm audit` | clean / clean | clean / clean |
| End-to-end (Playwright, local fakes) | none | 39 tests, all passing (`cd web && npm run build && npm run e2e`) |

## Merged PRs

- **#59:** Larastan (level 5 + baseline) and `astro check` (strict) in CI. Fixed the sample data's types, and its
  BMW draw time is now 19:30.
- **#60:** the scheduled `verity:check` keeps its output in a log, with a test.
- **#61:** security: the fake payment webhook is locked to non-production, with the fake driver only.
- **#62:** an authorization sweep over every API route (guests, customers, staff, other customers' orders). All
  passed.
- **#63:** uploaded photos no longer turn into broken `-720` links, and there's no empty `<img src>`.
- **#64:** the Playwright end-to-end harness (local fakes only) and the Admin dashboard fix (it scrolled sideways
  on phones).
- **#65:** this report.
- **#66:** competition page: on phones under 420px the enter and free postal buttons stack.
- **#67:** a branded 500 page instead of a blank one.
- **#68:** an unknown `PAYMENT_DRIVER` is an error.
- **#70:** spend limits run on UK days, weeks and months.
- **#73:** the account **Orders** tab widened the page to 640px on a 375px phone. A hidden header label escaped
  the table's scroll box. Fixed. A new end-to-end spec checks the paid order page and all 7 account tabs at 5 widths.
- **#74:** the banner link, breadcrumbs and footer links are now at least 24px tap targets (WCAG 2.2), with nothing
  moving visually.
- **#75:** uploaded competition and winner photos get a 720px JPEG copy, which the cards use.

## Draft PRs needing your decision

- **#69, payment for the wrong amount.** If the provider reports a payment that doesn't match the order total, the
  order was only marked "failed". The money was taken, Admin showed an ordinary failed order, and the Refund button
  had nothing to refund. Now the order shows as **"refund needed"**, with a note giving both amounts and a critical
  log. The judgement call: no automatic refund, because the amount taken is the thing in doubt.
- **#71, postal entries from blocked accounts.** Free online entry refuses closed, suspended, self-excluded and
  paused accounts; postal entry didn't look. Now those postal entries are logged as rejected with a reason, which
  is also shown in the entrant's email. Please check that this is wanted on the free route, and the wording.
- **#72, site credit spent twice.** Credit only comes off the balance once an order is paid. Two checkouts started
  together (two tabs) each used the same £20, leaving the balance at −£20 once both were paid. Now credit on
  another unpaid order is held back. Not covered: a payment that arrives late for an expired order whose credit
  was reused in the meantime. The PR suggests an option.

## Bugs found but not fixed

- **Waiting on your decision: max per person and limits are per email address.** A guest can use
  `name+1@gmail.com` to get round them. Unchanged, as asked.
- **Low: discount code usage limits can be exceeded.** `times_used` only goes up when an order is paid, so a code
  limited to 1 use can be used by several checkouts at once. Suggested fix: count pending, unexpired orders with the
  code, as #72 does for credit.
- **Low: postal date with no time.** A date-only "received" value via the API counts from midnight UTC, which is
  01:00 in summer. Admin always sends a date and time, so this doesn't affect normal use.
- **Low: account tab strip on phones.** The selected tab, for example "Responsible play", can be scrolled out of
  view in the tab strip. Suggested fix: scroll the active tab into view.
- **Low: ESLint not added.** `typescript-eslint` brings in a minimatch version with a moderate advisory; strict
  `astro check` covers types instead.

## Needs Forge / .env / Cashflows / Resend changes from you

- **Production, before launch:** `PAYMENT_DRIVER=cashflows` with the live account, after the go-live checklist in
  `docs/cashflows-testing.md`.
- **Forge PHP needs the `gd` extension** for the 720px photo copies (#75). It's normally installed. Without it,
  uploads still work, just without the copy.
- **Cashflows:** the questions on card funding type are in `docs/cashflows-testing.md`.

## Screenshot review (qa/screenshots, at 375/390/768/1280/1920)

- **Every public page, the paid order page and every account tab:** no sideways scroll at any width (after #73) and
  no console errors. Every page has a title, description, canonical, OG title and favicon, all internal links
  resolve, and 404 and 500 pages are branded.
- **Admin:** every page opens for an admin with no console errors, and none scrolls sideways at 390 or 1440.
- **axe:** one serious contrast hit on the free competition's countdown (`.roll`). It's most likely the digit-roll
  animation caught mid-fade, a false positive; worth a look.
- **Full-page screenshots:** blank areas below the fold are the scroll-reveal animations, which don't run in a
  full-page capture.

## Copy and consistency

- **Postal deadline:** reads "Tuesday 22 December 2026, 7:00pm" everywhere. Close is 24 Dec 19:00 and the draw
  19:30. No lorem ipsum or visible TODOs.
- **Legal pages:** they show "Draft for legal review" and "Last updated [DATE]". This is deliberate until the legal
  text is approved, so it's flagged, not rewritten.
- **Instagram handle, two variants:**
  - `@wds_originals` in the site copy and draws;
  - `@wds_competitions` in the default social links (Admin → Settings can override).

## Questions for Jason

1. The footer says **"Powered by RaffleLabs"**. Is that the intended credit?
2. **Instagram:** is the competitions account `@wds_competitions` or `@wds_originals`?
3. Bundle prices show whole pounds ("£40") but single tickets show pence ("£14.95"). Is that intended?
4. When will the legal pages' final text and "last updated" date be ready?
5. Draft PRs #69, #71 and #72: see above.

## Decisions made

- No ESLint, because of the advisory above.
- The end-to-end suite isn't in CI, because it needs PHP plus Chrome on one runner. Run it locally.
- Larastan runs at level 5 with a baseline.
- The fake webhook is locked rather than deleted: local development and tests use it.
- Money and compliance fixes (#69, #71, #72) are drafts. Bug fixes that are clearly correct and only tighten a
  rule (#70) were merged.

## Next session starts here

**State:** main @ 364a55a is green. Open PRs: #25 (leave alone) and drafts #69, #71 and #72 (waiting for Jason).
The working tree is clean, and no local servers are running.

**Phase 7 coverage so far:**

| Area | Status |
|---|---|
| Payments: notification, reconcile, late payment, refunds | Read and reasoned through. Found #69. Existing tests cover idempotency, late payment, the credit card cap and partial or full refunds. |
| Allocation under concurrency | Covered by `TicketAllocationRaceTest`. |
| Spend limits, pause, suspension, closure | Found #70 (UTC days). Guest checkout follows the account. |
| Postal entries | Found #71. |
| Credit | Found #72. |
| Draw lifecycle | Already well tested (flow, forfeit/redraw, reveal, verify, postpone, seal without auto-draw). No changes, as asked. |
| Mobile customer pages | All covered by end-to-end tests at 5 widths. |

**Still to do:**
1. Discount code usage limit under concurrency: fix as a draft, the same pattern as #72.
2. Scroll the active account tab into view on phones.
3. Look into the axe `.roll` contrast hit.
4. If Jason approves #72, extend it to cover a late payment after the credit was reused.
5. Once Jason decides, merge or adjust #69, #71 and #72 (update each branch with main first).

**How to run:**
- PHP: `php artisan test` (PowerShell).
- Web: `cd web && npm run build && npm test`.
- End-to-end: `$env:PHP_BINARY=(php -r "echo PHP_BINARY;"); cd web; npm run build; node e2e/run.mjs`.
