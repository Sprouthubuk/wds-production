# Production readiness

Checked on **17 Nov 2026** from outside the server only: public URLs, TLS and DNS. Nothing on Forge, in `.env` or
in the database was read or changed. Anything that needs the server is marked **Can't verify from here**, with the
check for you to run.

Production today: website `https://wds-competitions.on-forge.com`, API `https://wds-competitions-api.on-forge.com`.
The real domain isn't pointed yet (`docs/dns-cutover.md`).

| | Meaning |
|---|---|
| **PASS** | checked and right |
| **FAIL** | checked and needs fixing before launch |
| **TODO** | not done yet, expected (it's a launch-day step) |
| **CAN'T VERIFY** | needs the server or an account; run the check given |

## 1. App environment

| Item | Status | Evidence / what you do |
|---|---|---|
| `APP_DEBUG=false` | **PASS (indirect)** | An unknown API route returns `{"message": "The route … could not be found."}` with no exception, file or trace; `/_ignition/health-check` is 404. Confirm in Forge → API → Environment. |
| `APP_ENV=production` | CAN'T VERIFY | Forge → API → Environment. `php8.5 artisan about` shows *Environment: production*. |
| `APP_URL` correct | TODO | Today it should be the on-forge API URL; at cutover it becomes `https://api.wdscompetitions.co.uk` (dns-cutover.md, Forge step 4). Same for `FRONTEND_URL` and `VERITY_VERIFY_URL`. |
| Push-to-deploy **off** on production | CAN'T VERIFY | Forge → production API site and website → Deployments: *Quick deploy* off. Deploy only by "Deploy now" after a merge to `main`. |
| API health | **PASS** | `/up` → 200. |
| `/admin` protected | **PASS** | `/admin` → 302 to sign-in when signed out. |
| `robots.txt` | **FAIL (minor)** | `/robots.txt` is 404 on production; the repo has none. Not a launch blocker; add one (allow all, `Disallow: /admin`, `/account`, `/basket`, sitemap link) in a follow-up PR if you want it. |

## 2. Payments: Cashflows LIVE on production, integration on staging

| Item | Status | What you do |
|---|---|---|
| `PAYMENT_DRIVER=cashflows`, `CASHFLOWS_BASE_URL=https://gateway.cashflows.com` | CAN'T VERIFY | `docs/cashflows-testing.md` → Go-live checklist, steps 7–10. |
| `CASHFLOWS_WEBHOOK_IPS=54.74.58.255,52.215.48.101` | CAN'T VERIFY | Step 7. **Only works once the notification URL is `api.wdscompetitions.co.uk`**: the on-forge names go through Cloudflare, so the API would see Cloudflare's IP and refuse Cashflows. Don't set the allowlist while the Portal points at an on-forge URL. |
| `CASHFLOWS_UNKNOWN_CARD_AS_CREDIT=false` | CAN'T VERIFY | Step 9. Note what `false` means: an unreadable card type doesn't count towards the £250 credit cap. |
| New (rotated) live API key | CAN'T VERIFY | Step 4. |
| Live Portal return + notification URLs with production's own token | TODO | Steps 11–16, on cutover day. |
| Staging on the integration environment | CAN'T VERIFY | Staging `.env`: `CASHFLOWS_BASE_URL` is `gateway-int…`, no live ID or key. |
| Cashflows sign-off (accreditation) | CAN'T VERIFY | Step 2. |

## 3. Email: Resend

| Item | Status | Evidence / what you do |
|---|---|---|
| Domain verified (DKIM) | **PASS** | `resend._domainkey.wdscompetitions.co.uk` publishes Resend's DKIM key; `send.` points at Resend's return path. |
| DMARC record | **PASS (weak)** | `_dmarc` = `v=DMARC1; p=none`: present, but no `rua`, so no reports. Add `rua` at cutover (dns-cutover.md), move to `p=quarantine` once reports look clean. |
| Production's own `RESEND_API_KEY` | CAN'T VERIFY | Resend → API keys: a key named for production, used nowhere else. Forge → API → Environment. |
| `MAIL_FROM_ADDRESS=noreply@wdscompetitions.co.uk`, `MAIL_FROM_NAME="WDS Competitions"` | CAN'T VERIFY | Forge → API → Environment; then `php8.5 artisan wds:mail-test <you>` prints the sender. Check SPF, DKIM and DMARC **pass** in "show original". |
| Launch alert sending | CAN'T VERIFY | After deploy: Admin → Launch alerts → Send test (to yourself). Check the Unsubscribe link and that Gmail shows its own "Unsubscribe" button (one-click header). |
| `LAUNCH_ALERTS_PER_SECOND` | n/a | Leave unset (2/second) unless Resend's plan limit differs. |

## 4. Queue worker and scheduler (production)

| Item | Status | What you do |
|---|---|---|
| Queue worker running | CAN'T VERIFY | Forge → server → Background processes: `php8.5 …/artisan queue:work --sleep=3 --tries=3 --max-time=3600` shows *running*. Without it no email is sent at all (orders, launch alerts). |
| Scheduler running | CAN'T VERIFY | Forge → Scheduler: `schedule:run` every minute. Then `php8.5 artisan schedule:list` lists `competitions:open-due` (every minute), `orders:expire`, `payments:reconcile` and the Verity commands. **Without the scheduler, coming-soon competitions never open.** |

## 5. Verity

| Item | Status | What you do |
|---|---|---|
| `verity:check --strict` all OK | CAN'T VERIFY | Forge → Commands: `php8.5 artisan verity:check --strict`. Every line OK. |
| Own `VERITY_SEED_KEY` and `VERITY_OWNER_PEPPER` (never staging's) | CAN'T VERIFY | Generated on the production server (`forge/README.md` section 5) and in the password manager; staging's were generated separately (section 6). Never paste either into chat to compare. Never change the pepper once anything is published. |
| `VERITY_ANCHOR_EMAILS` = independent witness | **TODO (TBC)** | Witness not chosen yet. Must be someone outside WDS and outside RaffleLabs. Set before the first competition is published on production, because the publish anchor emails go there. |
| `VERITY_BEACON=drand` | CAN'T VERIFY | `verity:check` reports it. |
| No Verity data before launch | **PASS (indirect)** | `/api/draws` is empty and no competition is published. |

## 6. Legal pages and the free route

| Item | Status | Evidence |
|---|---|---|
| T&Cs, website terms, privacy, cookies, refunds, complaints, responsible play | **PASS** | All return 200 on production. Content was signed off by Jason. |
| Free postal route shown, no skill question | **PASS** | `/free-postal-entry` is live; nothing asks a question. |
| 48-hour postal deadline shown | **PASS** | "Postal entries must be received at least 48 hours before the competition closes. Envelopes received less than 48 hours before close are rejected." Each competition page shows its exact date. |
| Postal address shown | **PASS** | WDS Competitions – Free Entry, Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY. |
| Company details | **PASS** | WDS Originals Limited, company no. 13330886, registered office shown in the footer. |

## 7. Domain, SSL, backups, admin, data

| Item | Status | Evidence / what you do |
|---|---|---|
| SSL on the on-forge names | **PASS** | Valid (Google Trust Services, `*.on-forge.com`, until 22 Dec 2026, managed by Forge); http redirects to https. |
| Real domain and its SSL | **TODO** | Today `wdscompetitions.co.uk` and `www` point at **Shopify**. `docs/dns-cutover.md`, date [TBC]. |
| DB backups | CAN'T VERIFY | Forge → server → Database Backups: a schedule for `wds` to off-server storage, and one test restore to a scratch database (never to staging). |
| Admin login works | CAN'T VERIFY | Sign in at `/admin` with the production admin; change the seeded password if not done. |
| No test competitions | **PASS** | `/api/competitions` is empty (nothing published). The BMW draft is teased via `/api/upcoming`. |
| No test orders or users | CAN'T VERIFY | Admin → Orders and Admin → Customers: empty, or only your own admin. |
| Winners page | **CHECK** | Shows "Mark Robinson, Alpina D3 Coupé" (from the seeder). Confirm with Jason that this is a real past winner who agreed to be shown; if not, untick *Show on site* in Admin → Winners. |
| BMW draft's `opens_at` | **FAIL → fixed by this PR** | The BMW draft has `opens_at` 11 Nov 2026 20:41 (in the past) and the other two none. Publishing would have opened them at once. After merge, `wds:client-drafts --apply` sets all three to the confirmed opening time, Tue 1 Dec 2026 9:00am UK, and publishing now refuses a past opening time unless an admin confirms "open immediately". |

## Before you publish on production

1. Every row above is PASS or done.
2. The opening time is confirmed by Jason: Tue 1 Dec 2026, 9:00am UK (`App\Support\ClientDrafts::OPENS_AT`). If it
   ever changes, change the constant in a PR, then run `wds:client-drafts --apply` again (unpublished drafts only).
3. The witness is set (`VERITY_ANCHOR_EMAILS`).
4. You've read the per-competition table of what publishing locks (in the PR description).
