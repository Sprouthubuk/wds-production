# Morning report: overnight QA, 15–16 November 2026

Nothing was deployed, published or reset. Nothing on Forge, in `.env`, in secrets, or on staging or production
was touched. No real Cashflows, Resend or drand call was made. PR #25 was left alone. No Verity or draw-path code
was changed.

## Top 5 things to know

1. **Security hole closed (#61).** Production runs `PAYMENT_DRIVER=fake` until Cashflows goes live. Until now,
   anyone could `POST /api/payments/webhook {"order":"WDS-…"}` and have their own order marked paid, so tickets
   cost nothing. That endpoint now only exists off production with the fake driver. It wasn't exploitable
   tonight only because nothing is published on production.
2. **Uploaded prize photos would all have been broken images (#63).** Cards, thumbnails, the basket, winners and
   the event stand pointed every photo at a `-720.jpg` copy. Only the photos bundled in `/images` have those
   copies, and uploads in Admin don't. Fixed.
3. **An API error gave visitors a blank white page (#67).** The home, competition, winners and order pages
   answered 500 with an empty body. There is now a branded "Something went wrong" page that leaks nothing.
4. **Production must switch to `PAYMENT_DRIVER=cashflows` before launch.** With `fake` on production, checkout
   sends customers to the fake payment page, which correctly 404s there. #68 also turns a mistyped driver, such
   as `cashflow`, into an error instead of a silent fake gateway.
5. **Scheduled `verity:check` on staging.** Root cause not confirmed: I can't read staging logs or `.env`. Its
   output now goes to `storage/logs/verity-check-schedule.log` (#60). Outside production a FAIL row exits 0, so on
   staging an "exit code 1" means an exception. Read that log after the next deploy.

## Baseline → now

| | Baseline (main @ 6be81a3) | Now |
|---|---|---|
| PHP tests | 186 (184 passed, 2 skipped) | 194 (192 passed, 2 skipped) |
| Web tests (node) | 19/19 | 22/22 |
| Website build | OK | OK, plus `astro check` (strict): 0 errors |
| Larastan | not installed | level 5, 227 pre-existing findings in a baseline, 0 new |
| `composer audit` / `npm audit` | clean / clean | clean / clean |
| End-to-end (Playwright, local fakes) | none | 37 tests, all passing (`cd web && npm run build && npm run e2e`) |

## Merged PRs

- **#59:** Larastan (level 5 + baseline) and `astro check` (strict) in CI. Fixed the sample data's types, and its
  BMW draw time is now 19:30.
- **#60:** the scheduled `verity:check` keeps its output in a log, with a test of the schedule and exit rules.
- **#61:** security: the fake payment webhook is locked to non-production, with the fake driver only.
- **#62:** an authorization sweep over every API route. Guests get 401, customers and staff can't reach admin
  routes, and no customer can see another's orders. All passed on the first run.
- **#63:** uploaded photos no longer turn into broken `-720` links, and there's no empty `<img src>`.
- **#64:** the Playwright end-to-end harness (local fakes only) and the Admin dashboard fix (it scrolled sideways
  on phones).
- **#66:** competition page: on phones under 420px, "Enter now · £40" and "Free postal entry" stack instead of
  wrapping onto 2–3 lines.
- **#67:** the branded 500 page.
- **#68:** an unknown `PAYMENT_DRIVER` is an error. (Merging if CI is green; if this line is still here, check
  the PR.)

## Draft PRs needing your decision

None. Nothing found needed a judgement call on payments, draws or legal copy.

## Bugs found but not fixed

- **Medium, max per person and limits are per email address.** A guest can use `name+1@gmail.com` to get round
  "max per person" and an account's limits. Self-exclusion, closure and limits do follow the exact email into
  guest checkout. This is normal for the industry, but worth knowing. A possible mitigation is to match on card
  reference or address; that's a product decision.
- **Low, uploaded photos are full size on cards.** Suggested fix: generate a 720px version on upload, so mobile
  pages are lighter.
- **Low, tap targets.** These sit below the 24px WCAG 2.2 minimum: the top banner link (12px high), breadcrumbs
  (12px) and footer links (about 21px). Suggested fix: padding on those links.
- **Low, ESLint not added.** `typescript-eslint` brings in a minimatch version with a moderate advisory; strict
  `astro check` covers types instead.

## Needs Forge / .env / Cashflows / Resend changes from you

- **Production, before launch:** `PAYMENT_DRIVER=cashflows` with the live account, after the go-live checklist
  in `docs/cashflows-testing.md`.
- **After the next staging deploy:** read `storage/logs/verity-check-schedule.log` (#60).
- **Cashflows:** the questions on card funding type are in `docs/cashflows-testing.md`.

## Screenshot review (qa/screenshots, at 375/390/768/1280/1920)

- **Every public page:** no horizontal scroll at any width, no console errors, and a title, description,
  canonical, OG title and favicon on every page. All internal links resolve. 404s are branded.
- **Admin:** every page opens for an admin with no console errors, and none scrolls sideways at 390 or 1440
  after #64.
- **axe:** one serious contrast hit on the free competition's countdown (`.roll`). It's most likely the digit
  roll animation caught mid-fade, a false positive; worth a look.
- **Full-page screenshots:** a long blank area appears below the entry panel. That's the scroll-reveal
  animations, which don't run in a full-page capture, not missing content.

## Copy and consistency

- **Postal deadline:** reads "Tuesday 22 December 2026, 7:00pm" everywhere. Close is 24 Dec 19:00 and the draw
  19:30. No lorem ipsum or visible TODOs.
- **Legal pages:** they show "Draft for legal review" and "Last updated [DATE]" (the `LegalPage` default). This is
  deliberate until the legal text is approved, so it's flagged, not rewritten.
- **Instagram handle, two variants:**
  - `@wds_originals` in the site copy and draws;
  - `@wds_competitions` in the default social links (`SettingsPayload`, which Admin → Settings can override).

## Questions for Jason

1. The footer says **"Powered by RaffleLabs"**. Is that the intended credit, or left over from a template?
2. **Instagram:** is the competitions account `@wds_competitions` or `@wds_originals`? The site uses both.
3. Bundle prices show whole pounds ("£40", "£125") but single tickets show pence ("£14.95"). Is that the
   intended style?
4. When will the legal pages' final text and "last updated" date be ready?

## Decisions made

- No ESLint, because of the advisory above.
- The end-to-end suite isn't in CI, because it needs PHP plus Chrome on one runner. Run it locally.
- Larastan runs at level 5 with a baseline, rather than a higher level with mass churn.
- The fake webhook is locked rather than deleted: local development and tests still use it.
