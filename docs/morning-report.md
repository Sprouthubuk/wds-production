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
3. **Admin dashboard scrolled sideways on a phone.** Fixed in #64, which also adds the end-to-end harness.
4. **Scheduled `verity:check` on staging.** Root cause not confirmed: I can't read staging logs or `.env`. Its
   output now goes to `storage/logs/verity-check-schedule.log` (#60). Outside production a FAIL row exits 0, so on
   staging an "exit code 1" means an exception. Read that log after the next deploy.
5. **The footer says "Powered by RaffleLabs".** Is that intended? See the questions for Jason.

## Baseline → now

| | Baseline (main @ 6be81a3) | Now |
|---|---|---|
| PHP tests | 186 (184 passed, 2 skipped) | 193 (191 passed, 2 skipped) |
| Web tests (node) | 19/19 | 21/21 |
| Website build | OK | OK, plus `astro check` (strict): 0 errors |
| Larastan | not installed | level 5, 227 pre-existing findings in a baseline, 0 new |
| `composer audit` / `npm audit` | clean / clean | clean / clean |
| End-to-end (Playwright, local fakes) | none | 36 tests, all passing |

## Merged PRs

- **#59:** Larastan (level 5 + baseline) and `astro check` (strict) in CI. Fixed the sample data's types, and its
  BMW draw time is now 19:30.
- **#60:** the scheduled `verity:check` keeps its output in a log, with a test of the schedule and exit rules.
- **#61:** security: the fake payment webhook is locked to non-production, with the fake driver only.
- **#62:** an authorization sweep over every API route. Guests get 401, customers and staff can't reach admin
  routes, and no customer can see another's orders. All passed on the first run.
- **#63:** uploaded photos no longer turn into broken `-720` links, and there's no empty `<img src>`.

## Open PRs

- **#64:** the end-to-end harness plus the admin `.panels` phone fix. It merges when CI is green.

## Draft PRs needing your decision

None so far.

## Bugs found but not fixed

- **Low, ESLint not added.** `typescript-eslint` brings in a minimatch version with a moderate advisory; strict
  `astro check` covers types instead.
- **Low, uploaded photos are full size on cards.** Suggested fix: generate a 720px version on upload, so mobile
  pages are lighter.
- **Low, tap targets.** These sit below the 24px WCAG 2.2 minimum or a comfortable 44px:
  - the top banner link is 12px high;
  - breadcrumbs are 12px;
  - footer links are about 21px.

  Suggested fix: padding on those links.
- **Low, the generic gateway default.** An unknown `PAYMENT_DRIVER` value, such as a typo, silently becomes the
  fake driver. Suggested fix: refuse to boot in production unless the driver is `cashflows`. This needs a
  decision first, because production runs `fake` today.

## Needs Forge / .env / Cashflows / Resend changes from you

- **After the next staging deploy:** read `storage/logs/verity-check-schedule.log` (#60).
- **Cashflows (from earlier today):** questions on card funding type are in `docs/cashflows-testing.md`.

## Screenshot review (qa/screenshots, at 375/390/768/1280/1920)

- **Every public page:** no horizontal scroll at any width, no console errors, and a title, description,
  canonical, OG title and favicon on every page. All internal links resolve.
- **axe:** one serious contrast hit on the free competition's countdown (`.roll`). It's most likely the digit
  roll animation caught mid-fade, a false positive; worth a look.
- **Competition page at 375px:** "Enter now · £40" and "Free postal entry" sit side by side and wrap onto
  three lines. Consider stacking them under 400px.
- **Full-page screenshots:** a long blank area appears below the entry panel. That's the scroll-reveal
  animations, which don't run in a full-page capture, not missing content.

## Questions for Jason

1. The footer says **"Powered by RaffleLabs"**. Is that the intended credit, or left over from a template?
2. Bundle prices show whole pounds ("£40", "£125") but single tickets show pence ("£14.95"). Is that the
   intended style?

## Decisions made

- No ESLint, because of the advisory above.
- The end-to-end suite isn't in CI, because it needs PHP plus Chrome on one runner. Run it locally with
  `cd web && npm run build && npm run e2e`.
- Larastan runs at level 5 with a baseline, rather than a higher level with mass churn.
