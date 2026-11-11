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
VERITY_BEACON=drand          # required in production (section 5); local randomness is refused
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

**Background process** `wds-web` (Forge → server → Background processes; one per website site):

| Field | Value |
|---|---|
| Name | `wds-web` |
| Directory | `/home/forge/<web-site>/web` |
| Command | `env HOST=127.0.0.1 PORT=4321 NODE_ENV=production API_URL=https://<api-domain> node /home/forge/<web-site>/web/dist/server/entry.mjs` |
| User | `forge` |

The **absolute path** in the command is what lets `forge/web-deploy.sh` restart only this site's process:
it runs `pkill -x -f "node $FORGE_SITE_PATH/web/dist/server/entry.mjs"` (the whole command line must match),
and Forge's supervisor starts it again. Start the command with `node`, not `/usr/bin/node`, or it won't match.
Staging has its own process, `wds-web-staging` on port 4322 (section 6). Never `supervisorctl restart all`.

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
- [ ] **MySQL: allow the site user to create Verity's triggers** (below), before the first deploy.
- [ ] Composer access to the private Verity package (section 5, Prerequisites).

**1a. MySQL setting for Verity's triggers** (once per server, before the first `migrate`)

Verity protects its records with database triggers, created by its migration. MySQL 8.4 has binary
logging on, and then only a SUPER user may create triggers unless `log_bin_trust_function_creators`
is 1. Without it the first migrate stops with:
`SQLSTATE[HY000]: General error: 1419 You do not have the SUPER privilege and binary logging is enabled`.
Keep binary logging on (backups and point-in-time recovery need it); change only this setting.

Since Verity v3.0.3 the migration checks this **before creating anything** (`IntegrityGuards::assertInstallable()`):
without the setting (and without SUPER) it stops cleanly, creates no tables, and says how to fix it, so a
re-run after 1a just works. `verity:check` also has a `trigger_privilege` row for it. Step 1b is only for a
database where an older version (v3.0.2 or earlier) already stopped halfway.

*Option A, SSH (recommended).* Forge → server → copy the SSH command, sign in as `forge`, then:
```
sudo mysql                                   # MySQL root over the local socket (needs the forge user's sudo password)
# If that says "Access denied", use the MySQL root password instead:
#   mysql -u root -p                         # the database password Forge showed (and emailed) when the server was created
```
At the `mysql>` prompt:
```
SET PERSIST log_bin_trust_function_creators = 1;
SELECT @@log_bin, @@log_bin_trust_function_creators;   -- expect 1 and 1
exit
```
`SET PERSIST` writes the setting to `/var/lib/mysql/mysqld-auto.cnf`, so it survives restarts and reboots.
Check it again after a restart: `sudo service mysql restart`, then `sudo mysql -e "SELECT @@log_bin_trust_function_creators"` shows `1`.

*Option B, Forge Recipe.* Forge → Recipes → New recipe, user **root**:
```
mysql -e "SET PERSIST log_bin_trust_function_creators = 1; SELECT @@log_bin, @@log_bin_trust_function_creators;"
```
Run it on the server and read the output in the recipe log (expect `1 1`). This works when MySQL's root
user signs in over the local socket as the system root user, which is the usual setup on Ubuntu. If the
log shows `Access denied for user 'root'@'localhost'`, use option A with the root password (don't put the
password in a recipe).

**1b. If a migration ever stops halfway** (for example the error above, before 1a was done)

MySQL commits each `CREATE TABLE` at once, so a failed Verity migration leaves its `verity_*` tables in
place without their triggers, and re-running `migrate` then fails with "table already exists". At that
point they hold no engine data: nothing has been published. Check, then drop them and run again:
```
cd /home/forge/<api-site>
php8.5 artisan migrate:status | grep verity                      # 2026_01_01_000100_create_verity_tables: Pending
php8.5 artisan tinker --execute="foreach (['verity_competitions','verity_entry_groups','verity_entries','verity_winners'] as \$t) { echo \$t, ' ', Schema::hasTable(\$t) ? DB::table(\$t)->count() : '-', PHP_EOL; }"
```
Only if the migration is **Pending** and every count is `0` or `-`, drop the tables (as the site's MySQL user):
```
mysql -u <database user> -p wds -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS verity_markers, verity_locks, verity_close_witnesses, verity_audit_events, verity_redraws, verity_winners, verity_entries, verity_entry_groups, verity_end_prizes, verity_instant_tickets, verity_instant_prizes, verity_competitions; SET FOREIGN_KEY_CHECKS=1;"
```
Do 1a if it isn't done, then `php8.5 artisan migrate --force` and `php8.5 artisan verity:check --strict`
(the "Database integrity guards are installed" row must be OK). If the migration shows **Ran**, or any count
is above 0, stop: don't drop anything, and ask for help, because Verity's records are permanent by design.

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
- [ ] Background process `wds-web`, directory `/home/forge/<web-site>/web` (section 2, absolute path):
      `env HOST=127.0.0.1 PORT=4321 NODE_ENV=production API_URL=https://<api-domain> node /home/forge/<web-site>/web/dist/server/entry.mjs`
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

**5. First deploy** (Site → Commands, in order; 1a must be done first)
```
php8.5 artisan key:generate --force      # only if APP_KEY is empty
php8.5 artisan config:cache
php8.5 artisan migrate --seed --force    # empty database: creates every table, the admin and the content
php8.5 artisan queue:restart
php8.5 artisan db:show                   # says MySQL 8.4 and database wds
```
Then section 5 (Verity keys, `verity:check`). Never copy a database from the old server, staging or a laptop.

**6. Smoke tests**

The live database must start with **no Verity data**, and Verity's records can't be deleted. So on
production, don't publish a competition or buy a ticket as a test: do purchase and draw tests on a
staging site (its own server or database), and only read-only checks here.
- [ ] `https://<api-domain>/up` returns 200 and `https://<api-domain>/api/competitions` returns JSON.
- [ ] The website loads (the seeded competition is a draft, so the list is empty until it's published).
- [ ] `/admin` signs in with the seeded admin. Change the password. The competition opens in the editor.
- [ ] Mail works: a password reset to yourself arrives. (The Verity alert test runs on staging, section 5.)
- [ ] `php8.5 artisan schedule:list` lists `orders:expire` and the six Verity commands.
- [ ] `php8.5 artisan verity:check --strict` is all OK (section 5).
- [ ] On staging: publish a test competition, buy online, log a postal entry, let it close and draw, publish
      the result, and open `/verify/{id}`: every check shows as passed.

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
- MySQL 8.4 (section 4; not PostgreSQL) with `log_bin_trust_function_creators = 1` (section 4, step 1a), and the scheduler running `schedule:run` every minute (section 1).

**Keys and settings** (Site → Environment)

Generate both **on the server** (Forge → site → Commands, or SSH as `forge` in the site directory), once,
before the first competition is published:
```
php8.5 artisan verity:seal-seeds --new-key              # prints VERITY_SEED_KEY=base64:… (must differ from APP_KEY)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"       # → VERITY_OWNER_PEPPER
```
Before you publish anything:
1. Paste both into Site → Environment, then `php8.5 artisan config:cache`.
2. Store both in the **password manager** the owners control (one entry per site: production and staging differ).
3. Keep an **offline copy** too (printed or on an encrypted USB stick, kept somewhere safe away from the server).
   Without `VERITY_SEED_KEY` no sealed seed can be revealed, so no draw can run; a restored server needs it.
4. **Never change `VERITY_OWNER_PEPPER`** once anything is published: every owner hash on the public audit chain
   uses it, and a new pepper would make them unverifiable. Rotating the seed key is possible only with
   `verity:seal-seeds` (INTEGRATION.md); never just replace it.
5. Clear the terminal/command output afterwards; never paste either value into chat, email, tickets or git.
```
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

The website serves the browser verifier from `web/public/vendor/verity/` (a copy of the package's files,
checked by the test suite), so nothing extra is published on the API site.

**Fingerprints** for the release this site runs (`verity:check` prints them on its first line):

| Release | Engine (`src/Engine`) | Draw path |
|---|---|---|
| v3.0.3 (current) | `bba741fdda0bb9e7623c2a5b5b3af2a56120f4d881aade527428c9b826f4eaff` | `377eeb5d50f92e6342246b880d484ed852416094ad63fad4f6a7f1b8873380e3` |
| v3.0.2 | `bba741fd…f4eaff` (same) | `e9b267847d1bf981c5aa29e70337ec0133a87ef6275f8e88059ca8db5a51d115` |

The engine fingerprint must stay `bba741fd…f4eaff` (the reviewed engine is unchanged since v3.0.1); the
draw-path one changes with the Laravel layer and must equal the one in the installed tag's `SOURCE-MANIFEST.txt`.

**Apply and check**
```
php8.5 artisan config:cache
php8.5 artisan migrate --force
php8.5 artisan queue:restart
php8.5 artisan verity:check --strict      # every row OK, including "local_draws" and "manifest"
php8.5 artisan verity:invariants
php8.5 artisan verity:verify-chain
php8.5 artisan schedule:list | grep verity   # the six Verity entries plus wds:verity-check-alert (daily 07:00)
```
Every API deploy also runs `verity:check` after `migrate` (`forge/api-deploy.sh`): the output is in Forge's
deploy log and `storage/logs/verity-check-deploy.log`, and it never stops a deploy. Verity runs `verity:check`
hourly but doesn't alert on it, so `wds:verity-check-alert` emails `VERITY_ALERT_EMAILS` daily while any row
is FAIL (with a reminder every 24 hours and an all-clear when fixed).

**Go-live checks** (from the package's INTEGRATION.md)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `VERITY_BEACON=drand` (the site refuses to serve otherwise).
- [ ] **The live database starts with no Verity data**: every `verity_*` table is empty before the first
      real competition is published. Never copy a database from staging, demos or tests; promote code, not
      data. `verity:check` FAILs (`local_draws`) on any draw made on local randomness.
- [ ] `verity:check --strict` shows every row OK, the guards installed (`trigger_privilege` OK), and the engine
      `bba741fd…f4eaff` and draw path matching the table above.
- [ ] `VERITY_SEED_KEY` and `VERITY_OWNER_PEPPER` were generated on the server and are in the password manager and
      the offline copy, **before** the first publish.
- [ ] **A test alert reaches a real person**: on staging, close a test competition three minutes late and
      confirm the `late-close` email arrives in a VERITY_ALERT_EMAILS inbox.
- [ ] A test competition drawn on drand on staging shows "Verified" on the verify page.
- [ ] Backups include the `verity_*` tables; after any restore run `verity:anchor --after-restore="<why>"`.

## 6. Staging (test purchases and draws)

Production's database must never hold test data (Verity's records are permanent), so every test purchase,
publish and draw happens on staging: its **own API site and its own MySQL database** on the same server,
plus its own website site. Nothing is shared with production except the server and the code.

**Database**
- [ ] Forge → server → Database: create database `wds_staging` and a user with access to `wds_staging` only.
      `log_bin_trust_function_creators` is server-wide, so section 4 step 1a already covers it.

**Staging API site**
- [ ] New site, e.g. `staging-api.wdscompetitions.co.uk` (or `*-staging-api.on-forge.com`): Laravel, PHP 8.5,
      web directory `/public`, same repository. Branch `main`, or the branch under test.
- [ ] Deploy script: `forge/api-deploy.sh`.
- [ ] Its own queue worker and scheduler (`schedule:run` every minute): without the scheduler nothing closes or draws.
- [ ] Environment: as section 4 step 4, with these differences:
```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://<staging-api-domain>
FRONTEND_URL=https://<staging-website-domain>
DB_DATABASE=wds_staging
DB_USERNAME=<staging database user>
DB_PASSWORD=<its password>
SESSION_DOMAIN=<staging-website-domain>
SANCTUM_STATEFUL_DOMAINS=<staging-website-domain>
PAYMENT_DRIVER=fake                         # never a live payment account on staging
VERITY_BEACON=drand                         # real drand rounds, so staging draws are verifiable
VERITY_SEED_KEY=<a new key: php8.5 artisan verity:seal-seeds --new-key on the staging site>
VERITY_OWNER_PEPPER=<a new value: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;">
VERITY_TSA_URL=http://timestamp.digicert.com
VERITY_ALERT_EMAILS=liam@rafflelabs.co.uk
VERITY_ANCHOR_EMAILS=liam@rafflelabs.co.uk
VERITY_VERIFY_URL=https://<staging-website-domain>/verify
SEED_ADMIN_EMAIL=<staging admin's email>
```
  The seed key and pepper must be **different from production's**: never copy production's secrets to staging.
  Staging alerts and anchors go only to liam@rafflelabs.co.uk, so the independent production recipients never
  receive test records.
- [ ] First deploy: `php8.5 artisan config:cache`, `php8.5 artisan migrate --seed --force`, `php8.5 artisan queue:restart`,
      then `php8.5 artisan verity:check` (rows OK apart from settings that are production-only).

**Staging website site**
- [ ] New site, e.g. `staging.wdscompetitions.co.uk`: Static HTML / Other, web directory `/web/public`, same repository
      and branch, Nginx block `forge/web-nginx.conf`, deploy script `forge/web-deploy.sh`.
- [ ] Background process `wds-web-staging`, directory `/home/forge/<staging-website-site>/web`, on a port of its own,
      with this site's **absolute** path:
      `env HOST=127.0.0.1 PORT=4322 NODE_ENV=production API_URL=https://<staging-api-domain> node /home/forge/<staging-website-site>/web/dist/server/entry.mjs`
      and change `proxy_pass` in that site's Nginx block from port 4321 to **4322**.
- [ ] Each website's deploy (`forge/web-deploy.sh`) restarts only the process whose command is exactly
      `node <that site's path>/web/dist/server/entry.mjs`, so deploying staging never touches `wds-web`, and the
      reverse. Check after the first deploy of each: the deploy log says "Restarted the Node server for …".
- [ ] Protect it: Forge → site → Security → HTTP basic auth (staging is for testers only, and search engines stay out).

**What to test there**
- [ ] Publish a test competition closing in ~10 minutes; buy online (fake gateway), log a postal entry, sell at an event.
- [ ] Refund one order before close (tickets withdrawn) and try one after close (refused, money-only refund works).
- [ ] After the close and the drand round: Admin → Draws shows it drawn; Publish result; `/verify/{id}` shows every check passed.
- [ ] The late-close alert reaches liam@rafflelabs.co.uk (section 5, go-live checks).
- [ ] Staging data stays on staging: never copy `wds_staging` into `wds`.
