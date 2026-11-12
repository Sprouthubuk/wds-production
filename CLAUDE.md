# Working on this repository

## Branch → PR → green → squash-merge

`main` is protected: every change goes through a pull request, and three checks must pass on a branch that
is up to date with `main`: **API (PHP 8.5): SQLite**, **API (PHP 8.5, MySQL 8.4)** and **Website build**.
Repository admins can bypass this in an emergency; don't, unless the owner says so.

1. Branch from an up-to-date `main` (`git switch main && git pull && git switch -c <short-topic>`). Never commit to `main`.
2. Commit in small, logical steps. Run `php artisan test` locally (and `cd web && npm run build` for website changes).
3. Push and open a PR to `main` (`gh pr create --draft` while work is in progress). Stacked work targets the parent PR's branch.
4. Wait for all three checks to be green. If `main` moved, update the branch (`git merge main` or rebase) and let CI run again.
5. When it's green and reviewed, **squash-merge** (`gh pr merge <n> --squash --delete-branch`), only when the owner asks.
   Don't merge drafts. Don't skip or weaken checks to make them pass.
6. Deploy follows `main` on Forge. Never deploy by pushing anywhere else.

## Things that must never happen

- No secrets in git, chat, PRs or logs: `.env`, `auth.json`, `VERITY_SEED_KEY`, `VERITY_OWNER_PEPPER`, tokens.
- Never edit `vendor/sprouthub/verity-engine`: its fingerprints must match the reviewed release (`verity:check`).
- Never write to `verity_*` tables directly, and never copy a database between staging and production.
- Migrations are additive. A draw's result is only changed through Verity (forfeit / redraw), never by hand.

## Business rules

- **Free postal entry only, no skill question.** The client confirmed that the free route is free postal
  entry; competitions don't have a skill/knowledge question. Don't add one.
- Postal entries must be **received at least 48 hours before `closes_at`** (`Competition::postalClosesAt()`;
  an admin can override per competition). Later ones are rejected: "Received less than 48 hours before close".

## Where things are

- `VERITY.md`: how WDS uses the Verity draw engine. `forge/README.md`: servers, deploy, go-live, staging.
- Tests: `php artisan test` (SQLite); CI also runs the whole suite on MySQL 8.4 as a non-root user.
