# Forge setup — server `wds-production`

One repo, two sites. Laravel lives at the repo root; the Astro website lives in `/web`.

## 1. API site
| Setting | Value |
|---|---|
| Domain | `wds-competitions-api.on-forge.com` (later `api.wdscompetitions.co.uk`) |
| Type | Laravel · PHP 8.5 |
| Repository | `Sprouthubuk/wds-production`, branch `main` |
| Web directory | `/public` |
| Database | create `wds` (MySQL) |
| Deploy script | `forge/api-deploy.sh` |

**Environment** (start from `.env.example`):
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wds-competitions-api.on-forge.com
FRONTEND_URL=https://wds-competitions.on-forge.com
DB_CONNECTION=mysql
DB_DATABASE=wds
DB_USERNAME=…  DB_PASSWORD=…
QUEUE_CONNECTION=database
PAYMENT_DRIVER=fake          # cashflows once the gateway is finished
DRAW_ENGINE=commit-reveal    # verity once connected
SEED_ADMIN_EMAIL=…  SEED_ADMIN_PASSWORD=…
MAIL_… (Postmark/SES)
```
Then:
- **Background process**: `php8.5 /home/forge/<api-site>/artisan queue:work --sleep=3 --tries=3 --max-time=3600`
- **Scheduled job**: `php8.5 /home/forge/<api-site>/artisan schedule:run` every minute
- First deploy, then run once from Commands: `php artisan key:generate --force` (if APP_KEY empty) and `php artisan db:seed --force`
- SSL: Let's Encrypt

## 2. Website site
| Setting | Value |
|---|---|
| Domain | `wds-competitions.on-forge.com` (later `wdscompetitions.co.uk` + `www`) |
| Type | Static HTML / Other |
| Repository | same repo + branch |
| Web directory | `/web/public` |
| Nginx | replace `location /` with `forge/web-nginx.conf` |
| Deploy script | `forge/web-deploy.sh` |

**Background process** `wds-web` (directory `/home/forge/<web-site>/web`):
```
node dist/server/entry.mjs
```
with environment `HOST=127.0.0.1 PORT=4321 NODE_ENV=production API_URL=https://wds-competitions-api.on-forge.com`.
(If Forge's background-process form has no environment field, use:
`env HOST=127.0.0.1 PORT=4321 NODE_ENV=production API_URL=https://… node dist/server/entry.mjs`.)

Node must be **22.23 or newer** (`node -v` in Forge's Commands). If it's older, upgrade Node on the server first.

Deploy the website **after** the first deploy has built `web/dist`, then start the background process.

## 3. Test
- `https://wds-competitions-api.on-forge.com/api/competitions` → JSON with the BMW
- `https://wds-competitions.on-forge.com` → site; buy tickets → fake payment → "You're in"
- `/admin` → sign in with the seeded admin
- Payment webhook URL (when live): `https://<api>/api/payments/webhook`
