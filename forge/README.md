# Forge setup — server `wds-production`

One repo, two sites. Laravel lives at the repo root; the Astro website lives in `/web`.

## 1. API site
| Setting | Value |
|---|---|
| Domain | `wds-competitions-api.on-forge.com` (later `api.wdscompetitions.co.uk`) |
| Type | Laravel · PHP 8.5 |
| Repository | `Sprouthubuk/wds-production`, branch `main` |
| Web directory | `/public` |
| Database | create `wds` (MySQL 8.4, see section 4) |
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

Production runs **MySQL 8.4**. The Verity draw engine protects its records with database triggers that
exist only for MySQL/MariaDB and SQLite (`vendor/sprouthub/verity-engine/INTEGRATION.md`, section 1).
On PostgreSQL they're silently skipped and `verity:check` fails, so PostgreSQL can't be used. SQLite is
for tests (in memory) and local development only. The `pgsql` connection stays in `config/database.php`
only so the current server keeps working until it's replaced.

### Moving to a new MySQL server (before launch)

The current server runs PostgreSQL and holds test data only, so nothing is copied across: the new
server starts with an empty database.

**1. New server**
- [ ] Forge → Create server: app server, **PHP 8.5**, database **MySQL 8.4**, Node 22.23 or newer.
- [ ] SSH as root: `sudo apt-get install -y php8.5-gmp && sudo service php8.5-fpm reload`;
      `php8.5 -m | grep gmp` shows `gmp`.
- [ ] Forge → server → Database: create database `wds` and a user that can only access `wds`.
- [ ] Composer access to the private Verity package (section 5, Prerequisites).

**2. API site** (as in section 1)
- [ ] New site, Laravel, PHP 8.5, web directory `/public`, repository `Sprouthubuk/wds-production`, branch `main`.
- [ ] Deploy script: contents of `forge/api-deploy.sh`.
- [ ] Environment: the full list below.
- [ ] Background process (queue worker): `php8.5 /home/forge/<api-site>/artisan queue:work --sleep=3 --tries=3 --max-time=3600`.
- [ ] Scheduler: `php8.5 /home/forge/<api-site>/artisan schedule:run` every minute.
- [ ] SSL: Let's Encrypt.

**3. Website site** (as in section 2)
- [ ] New site, Static HTML / Other, web directory `/web/public`, same repository and branch.
- [ ] Nginx: replace the `location /` block with `forge/web-nginx.conf`.
- [ ] Deploy script: contents of `forge/web-deploy.sh`.
- [ ] Background process `wds-web`, directory `/home/forge/<web-site>/web`:
      `env HOST=127.0.0.1 PORT=4321 NODE_ENV=production API_URL=https://<api-domain> node dist/server/entry.mjs`
      (start it after the first deploy has built `web/dist`).
- [ ] SSL: Let's Encrypt.

**4. API environment** (Site → Environment). Values in `<>` are yours; generate secrets on the server.
```
APP_NAME="WDS Competitions"
APP_ENV=production
APP_KEY=                                 # php8.5 artisan key:generate --force after the first deploy
APP_DEBUG=false
APP_URL=https://<api-domain>
FRONTEND_URL=https://<website-domain>
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wds
DB_USERNAME=<database user>
DB_PASSWORD=<database password>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_DOMAIN=<website-domain>
SANCTUM_STATEFUL_DOMAINS=<website-domain>
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

MAIL_MAILER=smtp                         # or postmark / ses
MAIL_HOST=<smtp host>
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=competitions@wdsoriginals.com
MAIL_FROM_NAME="WDS Competitions"

RAFFLE_RESERVATION_MINUTES=15
PAYMENT_DRIVER=fake                      # cashflows once the gateway is finished
CASHFLOWS_CONFIGURATION_ID=
CASHFLOWS_API_KEY=
CASHFLOWS_BASE_URL=
CASHFLOWS_WEBHOOK_SECRET=

DRAW_ENGINE=verity
VERITY_BEACON=drand
VERITY_SEED_KEY=<from verity:seal-seeds --new-key, section 5>
VERITY_OWNER_PEPPER=<64 hex characters, section 5>
VERITY_TSA_URL=http://timestamp.digicert.com
VERITY_ALERT_EMAILS=<people on call>
VERITY_ANCHOR_EMAILS=<independent people, not the operator>
VERITY_VERIFY_URL=https://<website-domain>/verify

SEED_ADMIN_EMAIL=<first admin's email>
SEED_ADMIN_PASSWORD=                     # empty: a random one is printed once by db:seed
```

**5. First deploy** (Site → Commands, in order)
```
php8.5 artisan key:generate --force      # only if APP_KEY is empty
php8.5 artisan config:cache
php8.5 artisan migrate --seed --force    # empty database: creates every table, the admin and the content
php8.5 artisan queue:restart
php8.5 artisan db:show                   # says MySQL 8.4 and database wds
```
Then section 5 (Verity keys, `verity:check`). Never copy a database from the old server, staging or a laptop.

**6. Smoke tests**
- [ ] `https://<api-domain>/up` returns 200 and `https://<api-domain>/api/competitions` returns JSON.
- [ ] The website loads, shows the competitions, and a test purchase with the fake gateway ends at "You're in".
- [ ] `/admin` signs in with the seeded admin. Change the password.
- [ ] A queued email arrives (the order confirmation from the test purchase).
- [ ] `php8.5 artisan schedule:list` lists `orders:expire` (and the Verity commands once installed).
- [ ] `php8.5 artisan wds:reset-test-data` clears the smoke-test order (it refuses if any order went through a real gateway).

**7. Switch over and remove the old server**
- [ ] Point the domains (DNS) at the new server and issue the certificates.
- [ ] Update the payment provider's webhook URL to `https://<api-domain>/api/payments/webhook`.
- [ ] After a day with no traffic on the old server: Forge → old server → Delete. Its PostgreSQL data is test data only.
- [ ] Remove the `pgsql` connection from `config/database.php` in a later PR.

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
- MySQL 8.4 (section 4; not PostgreSQL) and the scheduler running `schedule:run` every minute (section 1).

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
