# WDS Competitions — website, customer accounts & admin

Built by RaffleLabs for WDS Originals Limited (t/a WDS Competitions).

```
/           Laravel 13 API — competitions, checkout, sequential tickets, postal entries, draws, admin API
web/        Astro 7 site (SSR, Node adapter) — public site, basket, customer account, /admin
forge/      Forge deploy scripts, Nginx config and setup steps
VERITY.md   How to plug the Verity draw engine in
```

## How it fits together

```
Browser ──► Astro (wdscompetitions.co.uk)
              │  pages render server-side; /api/* routes are a thin proxy that keeps
              │  the customer/admin token in an httpOnly cookie
              ▼
           Laravel API (api.wdscompetitions.co.uk) ──► MySQL
              │                    ├─► Payment provider (hosted checkout + webhook)
              │                    └─► DrawEngine (Verity)
```

### Key rules the API enforces
- **Sequential tickets** — numbers 1…N in the order payments are confirmed, across online, free postal and event sales. Allocation locks the competition row; a unique index is the backstop. No lucky dips or number picking.
- **Reservations** — starting checkout holds the tickets for 15 min (`orders:expire` releases them). Late payments are still honoured if tickets remain; otherwise they show as *Refunds needed* on the dashboard.
- **Pricing** — bundles applied biggest-first (e.g. £14.95, 3 for £40, 10 for £125). `PricingService` is authoritative; `frontend/src/lib/pricing.ts` mirrors it for display.
- **Per-person max** — counts paid, pending and postal entries by email.
- **Free postal entry** — staff log envelopes in Admin → Postal entries; valid ones get the next number immediately.
- **18+, responsible play** — DOB check on every order; daily/weekly/monthly limits (raises wait 24h), breaks, self-exclusion.
- **Hosted checkout only** — basket → provider's payment page → signed webhook → tickets + email.

## Run locally

```bash
# API
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed       # BMW E46 competition, Mark Robinson winner, admin login
php artisan test
php artisan serve                # http://localhost:8000  (+ queue:work, schedule:work)

# Website
cd web && npm install
API_URL=http://localhost:8000 npm run dev   # http://localhost:4321 — sample data if API_URL is unset
```
Locally `PAYMENT_DRIVER=fake` sends checkout to a signed test URL that marks the order paid.

## Deploy
See `forge/README.md` (server `wds-production`: one API site + one website site from this repo).

## Still to do before launch
- [ ] **Verity** — connect via `VERITY.md` and set `DRAW_ENGINE=verity`.
- [ ] **Payments** — complete `CashflowsGateway` (or chosen provider) from their integration guide; set `PAYMENT_DRIVER`.
- [ ] Jason to confirm: free-entry postal address, closing time, cash alternative, max per person (50 set), unsold-ticket and refund policy, Mark Robinson's consent to be shown.
- [ ] Legal review of Terms / Privacy / Responsible play copy.
- [ ] Mail provider (Postmark/SES) + SPF/DKIM for wdsoriginals.com.

## Where Jason edits content
Admin → **Competitions** (title, copy, photos, highlights, price, bundles, dates, status), **Winners** (photos, names, show on site), **Settings** (postal address, company details, Instagram handle). No code needed.
