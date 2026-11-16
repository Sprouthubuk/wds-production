# Morning report: overnight QA, 15–16 November 2026

Nothing was deployed, published or reset. Nothing on Forge, in `.env`, in secrets, or on staging or production
was touched. No real Cashflows, Resend or drand call was made. PR #25 was left alone. No Verity or draw-path code
was changed.

## Top 5 things to know

1. **Security hole closed (#61).** Production runs `PAYMENT_DRIVER=fake` until Cashflows goes live. Until now,
   anyone could `POST /api/payments/webhook {"order":"WDS-…"}` and have their own order marked paid. That endpoint
   now only exists off production with the fake driver. #68 also makes a mistyped driver an error.
2. **Money fixes, approved by Jason and merged:**
   - **#72:** site credit could be spent twice by two checkouts at once.
   - **#69:** a payment for the wrong amount is now "refund needed" instead of a silent failure.
   - **#77:** discount code usage limits count unpaid orders still holding the code.
3. **Responsible play:**
   - **#70:** spend limits now run on UK days, not UTC ones.
   - **#71:** postal entries from closed, suspended, self-excluded or paused accounts are rejected. The entrant's
     email gives a neutral reason and Admin sees the specific one.
4. **Photos (#63, #75).** Uploaded prize photos would all have been broken images (#63). They now also get a 720px
   copy for cards and thumbnails (#75).
5. **Production must switch to `PAYMENT_DRIVER=cashflows` before launch,** and Forge's PHP needs `gd` for the photo
   copies. It normally has it.

**Scheduled `verity:check` "exit 1" on staging:** explained by Jason. It's the deliberate local-randomness test
draw WDST-PT11VI on staging, which the check correctly flags. No code change is needed. Its output is kept in
`storage/logs/verity-check-schedule.log` (#60).

## Baseline → now

| | Baseline (main @ 6be81a3) | Now (main @ 846b7f1) |
|---|---|---|
| PHP tests | 186 (184 passed, 2 skipped) | 202 (200 passed, 2 skipped) |
| Web tests (node) | 19/19 | 23/23 |
| Website build | OK | OK, plus `astro check` (strict): 0 errors |
| Larastan | not installed | level 5, 0 new errors. Pre-existing findings are in a baseline, which shrank in #71 when one false positive was fixed. |
| `composer audit` / `npm audit` | clean / clean | clean / clean |
| End-to-end (Playwright, local fakes) | none | 41 tests, all passing (`cd web && npm run build && npm run e2e`) |

## Merged PRs

- **#59:** Larastan and strict `astro check` in CI. Sample data types fixed.
- **#60:** the scheduled `verity:check` keeps its output in a log.
- **#61:** security: the fake payment webhook is locked to non-production, with the fake driver only.
- **#62:** an authorization sweep over every API route.
- **#63:** uploaded photos no longer turn into broken `-720` links.
- **#64:** the Playwright end-to-end harness, and the Admin dashboard no longer scrolls sideways on phones.
- **#65, #76, #78 and this PR:** this report.
- **#66:** the enter and free postal buttons stack on narrow phones.
- **#67:** a branded 500 page instead of a blank one.
- **#68:** an unknown `PAYMENT_DRIVER` is an error.
- **#69:** a paid-but-wrong-amount order shows as "refund needed", with no tickets and a critical log. There's no
  automatic refund, as agreed.
- **#70:** spend limits run on UK days, weeks and months.
- **#71:** postal entries refuse closed, suspended, self-excluded and paused accounts. The entrant's email says only
  "We couldn't accept this postal entry because of the status of the account linked to this email address. If you
  think this is a mistake, contact us." Admin shows the specific reason, and the test checks that neither email
  reveals it.
- **#72:** credit already on another unpaid order is held back, so it can't be spent twice.
- **#73:** the account Orders tab no longer widens the page on phones.
- **#74:** small text links are 24px tap targets.
- **#75:** uploaded photos get a 720px copy for cards and thumbnails.
- **#77:** discount code usage limits count pending, unexpired orders holding the code.
- **#79:** on phones, the selected account tab is centred in the tab strip on load, on a click, after a resize and
  on a hash change (e.g. `/account#limits`), which didn't switch tabs before. Also a colour contrast check for the
  countdown with animations stopped.

## Draft PRs needing your decision

None.

## Bugs found but not fixed

- **Waiting on your decision: max per person and limits are per email address.** A guest can use
  `name+1@gmail.com` to get round them. Unchanged, as asked.
- **Low: a late payment for an expired order.** If the customer reused that order's credit or discount use on a new
  order in the meantime, the late payment is still honoured and uses them again. The credit balance can go
  negative, or a code can go one past its limit. Rare: it needs a payment to arrive after the reservation ended.
  Suggested fix: on that late-payment path, refuse and refund if the credit or code is no longer available.
- **Low: postal date with no time.** A date-only "received" value via the API counts from midnight UTC. Admin
  always sends a date and time, so this doesn't affect normal use.
- **Low: ESLint not added**, because of a moderate advisory in a `typescript-eslint` dependency. Strict
  `astro check` covers types instead.

## Checked and found fine

- **Countdown contrast (axe `.roll`):** a false positive. Each digit rolls in from opacity 0, and axe caught it
  mid-fade. With animations running and stopped, six samples showed no failure. #79 adds a check with animations
  stopped, so a real failure would show.
- **Payments:** notification, reconcile, late payment, the credit card cap, and full and partial refunds are
  covered by tests.
- **Allocation under concurrency:** covered by `TicketAllocationRaceTest`.
- **Draw lifecycle:** flow, forfeit and redraw, reveal, verify, postpone, and seal without auto-draw are covered.
- **Authorization:** checked over every route (#62).

## Needs Forge / .env / Cashflows / Resend changes from you

- **Production, before launch:** `PAYMENT_DRIVER=cashflows` with the live account, after the go-live checklist in
  `docs/cashflows-testing.md`.
- **Forge PHP needs the `gd` extension** for the 720px photo copies. Without it, uploads still work, just without
  the copy.
- **Cashflows:** the questions on card funding type are in `docs/cashflows-testing.md`.

## Screenshot review (qa/screenshots, at 375/390/768/1280/1920)

- **Every public page, the paid order page and every account tab:** no sideways scroll at any width and no console
  errors. Every page has a title, description, canonical, OG title and favicon, all internal links resolve, and
  404 and 500 pages are branded.
- **Admin:** every page opens for an admin with no console errors, and none scrolls sideways at 390 or 1440.
- **Full-page screenshots:** blank areas below the fold are the scroll-reveal animations, which don't run in a
  full-page capture.

## Copy and consistency

- **Postal deadline:** reads "Tuesday 22 December 2026, 7:00pm" everywhere. Close is 24 Dec 19:00 and the draw
  19:30.
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
5. Should the late-payment edge case above be fixed? If yes, a payment arriving for an expired order would be
   refused and refunded when its credit or code use is no longer available.

## Next session starts here

**State:** main @ 846b7f1 is green. The only open PR is #25 (leave alone). The working tree is clean and no local
servers are running. The overnight QA list is finished.

**Possible next work, waiting on Jason:**
1. The late-payment edge case (question 5).
2. The guest and email spend-limit design.
3. The copy questions 1–4.

**How to run:**
- PHP: `php artisan test` (PowerShell).
- Web: `cd web && npm run build && npm test`.
- End-to-end: `$env:PHP_BINARY=(php -r "echo PHP_BINARY;"); cd web; npm run build; node e2e/run.mjs`.
