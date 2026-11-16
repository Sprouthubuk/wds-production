# Morning report: overnight QA, 15–16 November 2026

Nothing was deployed, published or reset. Nothing on Forge, in `.env`, in secrets, or on staging or production
was touched. No real Cashflows, Resend or drand call was made. PR #25 was left alone. No Verity or draw-path code
was changed.

## Update, 16 November (daytime): #83–#93

All merged with the three checks green. Nothing was deployed. Forge, `.env`, staging and production were not
touched, and #25 is still open and left alone.

**Refunds and contact**
- **#83, #84:** refund messages say how long a refund takes, and customers are emailed when an order is refunded
  in Admin.
- **#85 (staging bug, order WDS-QOYF9F):** ticking "take tickets out" on a partial refund is now refused.
  - Orders refunded in full whose tickets are still in the draw show "Refunded in full but tickets are still in
    the draw.", with a confirmed **Take tickets out of the draw** action. It works only while the competition is
    open, is recorded in history, and sends the customer a short email.
  - Use it on WDS-QOYF9F once deployed.
- **#86–#88:** one contact address, `config('wds.contact_email')`, defaulting to sales@wdsoriginals.com.
  - Contact-form enquiries are queued from noreply@wdscompetitions.co.uk with Reply-To the customer. Their
    recipient can be changed with `ENQUIRY_EMAIL` (staging: a developer).
  - The mail settings for production and staging are in `forge/README.md`.
- **#89:** footer polish.
  - The logo is no longer stretched.
  - New blocks: contact, responsible play (GamCare, GambleAware), payments, and a legal line. The ICO number
    shows only if `ICO_NUMBER` is set.
  - The Instagram/YouTube links and "Powered by RaffleLabs" are config-driven (`SHOW_POWERED_BY`).

**Admin redesign (#90–#93), no behaviour changes.** Screenshots at 375 and 1440 are in `docs/screenshots/admin/`.
- **#90, shell:**
  - grouped sidebar with icons; collapsible; a drawer on phones;
  - top bar with breadcrumbs;
  - quick search for an order number, email or ticket number;
  - account menu;
  - an amber **STAGING** badge unless `APP_URL` is a production address.
- **#91, dashboard:**
  - KPIs net of refunds, on UK days;
  - "Needs attention", each item linking to it: refund needed, payments pending over 15 minutes, refunded
    orders with tickets still in the draw, postal deadlines, competitions closing within 48 hours, draws
    awaiting a result, responsible-play alerts, winners to contact, ID checks;
  - live competition cards with a countdown;
  - the last 10 orders;
  - a 30-day sales chart.
- **#92, components:**
  - sticky table headers, status pills, right-aligned money, empty states, skeletons;
  - numbered pagination, now also on Customers and Postal entries, which only ever showed the first 50;
  - button variants;
  - confirmations in a styled dialog, with the same question and the same yes/no;
  - restyled toasts.
- **#93, order and competition pages:**
  - order summary header, Tickets / Payment / Refunds / History cards, and withdrawn tickets struck through;
  - competition tabs (Details, Pricing, Photos, Draw, Entries) with exactly the same fields, saved together.
- **New read-only, admin-only API:** `/api/admin/environment` (staff), `/api/admin/search`, the new
  `/api/admin/dashboard` shape and `payment.withdrawnTickets` on an order. All have tests.

**Things to know**
- **Environment badge:** "production" means `APP_URL` is `https://api.wdscompetitions.co.uk` or
  `https://wds-competitions-api.on-forge.com`. The second is production's current address per `forge/README.md`.
  Override with `PRODUCTION_APP_URLS` (comma-separated) if that changes. Anything else shows STAGING.
- **The frontend-design skill wasn't available,** so the redesign follows the site's own design system: black and
  white, the site's type, and amber for warnings.
- **Sign-in rate limit in local E2E:** sign-in allows 10 a minute, so running the full E2E suite twice within a
  minute can fail a sign-in with "Too Many Attempts". Wait a minute and re-run. CI doesn't run E2E.

## Top 5 things to know (overnight)

1. **Security hole closed (#61).** Production runs `PAYMENT_DRIVER=fake` until Cashflows goes live. Until now,
   anyone could `POST /api/payments/webhook {"order":"WDS-…"}` and have their own order marked paid. That endpoint
   now only exists off production with the fake driver. #68 also makes a mistyped driver an error.
2. **Money fixes, approved by Jason and merged:**
   - **#72:** site credit could be spent twice by two checkouts at once.
   - **#69:** a payment for the wrong amount is now "refund needed" instead of a silent failure.
   - **#77:** discount code usage limits count unpaid orders still holding the code.
   - **#81:** a late payment whose credit or code use has since gone on another order is refused and refunded.
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

| | Baseline (main @ 6be81a3) | Overnight (main @ c56cfa8) | Now (main @ 1074a5c) |
|---|---|---|---|
| PHP tests | 186 (184 passed, 2 skipped) | 205 (203 passed, 2 skipped) | 233 (231 passed, 2 skipped) |
| Web tests (node) | 19/19 | 23/23 | 26/26 |
| Website build | OK | OK, plus `astro check` (strict): 0 errors | OK |
| Larastan | not installed | level 5, 0 new errors. Pre-existing findings are in a baseline, which shrank in #71 when one false positive was fixed. | 0 errors; the baseline shrank by 27 entries in #91 when model date and image types were added |
| `composer audit` / `npm audit` | clean / clean | clean / clean | not re-run |
| End-to-end (Playwright, local fakes) | none | 41 tests, all passing (`cd web && npm run build && npm run e2e`) | 53 tests, all passing |

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
- **#81:** a payment arriving after its order expired is refused and refunded in full if that order's site credit
  or discount-code use has since gone on another order. The customer gets a plain email: "Your payment arrived
  after your order had expired, and the credit or discount it used had already been applied to another order, so
  we've refunded it in full. You're welcome to enter again." Admin's payment history gives the specific reason.
  All other late payments are unchanged. Tests cover a credit clash, a code clash and a late payment with no clash.
- **#79:** on phones, the selected account tab is centred in the tab strip on load, on a click, after a resize and
  on a hash change (e.g. `/account#limits`), which didn't switch tabs before. Also a colour contrast check for the
  countdown with animations stopped.

## Draft PRs needing your decision

None.

## Bugs found but not fixed

- **Waiting on your decision: max per person and limits are per email address.** A guest can use
  `name+1@gmail.com` to get round them. Unchanged, as asked.
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
- **Instagram handle:** settled. Only `@wds_originals` is used (#86).

## Questions for Jason

1. The footer says **"Powered by RaffleLabs"**. Is that the intended credit? It can be turned off with
   `SHOW_POWERED_BY=false` (#89).
2. Bundle prices show whole pounds ("£40") but single tickets show pence ("£14.95"). Is that intended?
3. When will the legal pages' final text and "last updated" date be ready?
4. Is `https://wds-competitions-api.on-forge.com` still production's API address? The Admin STAGING badge relies
   on it (see "Things to know" above).

## Next session starts here

**State:** main @ 1074a5c is green. The only open PR is #25 (leave alone). The working tree is clean and no local
servers are running. The Admin redesign (#90–#93) is finished.

**Possible next work, waiting on Jason:**
1. The guest and email spend-limit design.
2. Questions 1–4.
3. After deploy: clean up WDS-QOYF9F with "Take tickets out of the draw".

**How to run:**
- PHP: `php artisan test` (PowerShell).
- Web: `cd web && npm run build && npm test`.
- End-to-end: `$env:PHP_BINARY=(php -r "echo PHP_BINARY;"); cd web; npm run build; node e2e/run.mjs`.
