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
DRAW_ENGINE=verity           # required in production (section 5); commit-reveal is refused
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

## 4. Database: MySQL 8.4

Production runs **MySQL 8.4** (Forge's MySQL server). SQLite is only for tests (in memory) and local dev.
PostgreSQL isn't supported: the Verity engine's integrity guards are MySQL/SQLite triggers.

**Check what the server runs now** (prints driver, version and database name, not the password):
```
cd /home/forge/<api-site> && php8.5 artisan db:show
```

**Moving to a new, empty MySQL database** (only if `db:show` says it isn't MySQL 8.4, or you want a clean start):
1. Forge → server → Database: create database `wds` and a user with access to it only. MySQL 8.4 is
   chosen when the server is created; if the server has an older MySQL, ask Forge support or build a new server.
2. Site → Environment:
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=wds
   DB_USERNAME=<the new user>
   DB_PASSWORD=<its password>
   ```
3. Commands (the database is empty, so this creates every table, the Verity ones included):
   ```
   php8.5 artisan config:cache
   php8.5 artisan migrate --force
   php8.5 artisan db:seed --force      # admin + content; creates no Verity data
   php8.5 artisan queue:restart
   ```
4. Old data is not copied. If the old database has real orders, export them first and decide
   separately what to bring across: never copy Verity tables (`verity_*`) between databases.

## 5. Verity go-live

The draw engine is the private package `sprouthub/verity-engine`. Do these on the server, in order.
Keys are generated **on the server** and pasted into Forge's Environment screen. They never go in git,
chat, tickets or the PR. Keep an offline copy of each (a password manager the owners control).

**Prerequisites**
- **ext-gmp** for PHP 8.5 (drand BLS signatures; production refuses to draw without it):
  `sudo apt-get install -y php8.5-gmp && sudo service php8.5-fpm reload`, then `php8.5 -m | grep gmp`.
  Needed on every server that runs the scheduler or queue.
- **Composer access to the private repository.** Create a fine-grained GitHub token with read-only
  *Contents* access to `Sprouthubuk/verity-engine` only, then as the `forge` user:
  `composer config --global github-oauth.github.com <token>`
  (or set `COMPOSER_AUTH={"github-oauth":{"github.com":"<token>"}}` in the deploy environment).
  Never commit `auth.json`.
- MySQL 8.4 (section 4) and the scheduler running `schedule:run` every minute (section 1).

**Keys and settings** (Site → Environment)
```
php8.5 artisan verity:seal-seeds --new-key              # prints VERITY_SEED_KEY=base64:… (must differ from APP_KEY)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"       # → VERITY_OWNER_PEPPER (never change it)
```
```
DRAW_ENGINE=verity
VERITY_BEACON=drand
VERITY_SEED_KEY=<from above>
VERITY_OWNER_PEPPER=<from above>
VERITY_TSA_URL=http://timestamp.digicert.com
VERITY_ALERT_EMAILS=<the people on call>
VERITY_ANCHOR_EMAILS=<independent people>
VERITY_VERIFY_URL=https://wdscompetitions.co.uk/verify
```
`VERITY_ANCHOR_EMAILS` must be **independent people, not the operator** (e.g. an accountant, a solicitor
or a trustee): they receive the daily audit-chain anchor and every close/redraw witness, so a rewritten
history would contradict what they already hold. Leave `VERITY_BEACON_FALLBACK` unset and
`VERITY_DRAND_CLOCK_CHECK` on.

**Apply and check**
```
php8.5 artisan config:cache
php8.5 artisan migrate --force
php8.5 artisan queue:restart
php8.5 artisan verity:check --strict      # every row OK, including "local_draws" and "manifest"
php8.5 artisan verity:invariants
php8.5 artisan verity:verify-chain
php8.5 artisan schedule:list | grep verity   # the six Verity entries
```

**Go-live checks** (from the package's INTEGRATION.md)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `DRAW_ENGINE=verity` (the site refuses to serve otherwise).
- [ ] **The live database starts with no Verity data**: every `verity_*` table is empty before the first
      real competition is published. Never copy a database from staging, demos or tests; promote code, not
      data. `verity:check` FAILs (`local_draws`) on any draw made on local randomness.
- [ ] `verity:check --strict` shows every row OK, the guards installed, and the engine matching the tag's `SOURCE-MANIFEST.txt`.
- [ ] **A test alert reaches a real person**: on staging, close a test competition three minutes late and
      confirm the `late-close` email arrives in a VERITY_ALERT_EMAILS inbox.
- [ ] A test competition drawn on drand on staging shows "Verified" on the verify page.
- [ ] Backups include the `verity_*` tables; after any restore run `verity:anchor --after-restore="<why>"`.
