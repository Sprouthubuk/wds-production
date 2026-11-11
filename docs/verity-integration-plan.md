# Verity integration plan (WDS Competitions)

Status: **built on PR #2 with `sprouthub/verity-engine` v3.0.3** (sections 1-9 below); the admin
forfeit / redraw controls are on the stacked PR (feature/forfeit-redraw). See `VERITY.md` for how the
pieces fit together.

Package facts were first read from v3.0.1 and confirmed against the installed v3.0.3.

## Decisions (agreed)

| Topic | Decision |
|---|---|
| Numbering | Verity numbers every ticket, `ticket_allocation = sequential`. WDS `tickets` is a mirror. |
| Database | MySQL 8.4 in production (a new Forge server before launch; the current one runs PostgreSQL with test data only), SQLite for tests. Verity's integrity triggers exist only for MySQL/MariaDB and SQLite (`IntegrityGuards::supported()`; INTEGRATION.md section 1), so PostgreSQL is out. The `pgsql` connection stays until the old server is deleted. *(done: docs, CI, race test, move checklist in forge/README.md section 4)* |
| Owner reference | `owner_ref = "entrant:{id}"`, one entrant per normalised email across online, postal and event; the per-person cap counts per entrant. *(done: `entrants` table, `Entrant::forEmail`, cap in `TicketAllocator::heldBy`)* |
| Launch data | No real sales yet. `php artisan wds:reset-test-data` clears test orders, tickets, postal entries and draws; it refuses if any paid order didn't go through the fake gateway. The competitions are then published on Verity. *(done)* |
| Postal deadline | `competitions.postal_closes_at`, default three days before `closes_at`; later envelopes are rejected. Shown on the competition, Free Postal Entry and FAQ pages. Real dates are placeholders until Jason confirms. *(done)* |
| Draw flow | Verity's lifecycle: round fixed at publish, close freezes entries, `verity:process-due` draws. |
| Void | Verity's rules: only an empty competition is voided. After close, only R2 redraw or forfeit. |
| Engines | Verity only. The commit-reveal engine and `DRAW_ENGINE` are removed. Production refuses a non-drand beacon (WDS at web boot, Verity at close/draw/redraw). *(done)* |
| Instant wins | Not in phase 1 (sequential + instant wins is refused by the package). |

## 1. TicketAllocator becomes a thin wrapper

```
CheckoutService::markPaid / PostalEntryService::log / event sale (markPaid)
  └─ DB::transaction (existing)
       ├─ WDS checks first (unchanged): open, caps, responsible play, reservations, pricing
       ├─ TicketAllocator::allocate()
       │    ├─ lock WDS competitions row FOR UPDATE (as now; keeps reservation maths correct)
       │    ├─ Verity::addEntries($verityRef, $ownerRef, $qty, [source, host_ref, ordered_at, total_pence])
       │    │     or Verity::addPostalEntry($verityRef, $ownerRef, [host_ref, ordered_at])
       │    ├─ insert mirror rows into tickets with Verity's numbers (+ verity_entry_id)
       │    └─ increment competitions.tickets_allocated
       └─ any VerityException → EntryException → whole transaction rolls back
```

Options passed to `addEntries` are only the documented ones: `source`, `host_ref`, `label`,
`ordered_at`, `total_pence` (v3.0.1 refuses anything else).

**Mapping**

| WDS | Verity |
|---|---|
| channel `online` | `source = paid` |
| channel `event` | `source = paid` |
| channel `postal` | `Verity::addPostalEntry()` (`source = postal`) |
| order line id | `host_ref = "order_line:{id}"` (idempotent: a retried webhook allocates nothing twice) |
| postal entry id | `host_ref = "postal:{id}"` |
| order `created_at` | `ordered_at` (a webhook that lands after the closing time but before the close runs still counts) |
| line total | `total_pence` |

Note: Verity's `source` values are `paid | free | postal | bonus`, not `online | event`. The channel
stays in WDS's `tickets.channel`; Verity only needs to know paid vs postal (equal odds either way).

**Owner reference** *(done)*: Verity wants a stable, non-personal id, enforces `max_per_person` per
owner reference, and records winners against it. WDS has an `entrants` table (`id`, `email` unique) and
passes `Entrant::ownerRef()` = `"entrant:{id}"`, so Verity groups exactly as WDS's cap does and no email
reaches the engine. Every ticket, order and postal entry carries `entrant_id`.

**Lock order**: WDS competition row, then Verity's own locks (its competition row and the single
`verity_locks.audit` row). Always in that order, so no deadlock. The audit lock serialises every
entry across all competitions; fine at WDS's volume.

**Race test**: `TicketAllocationRaceTest` *(done)* already races two processes through the current
allocator on SQLite and MySQL 8.4. After the switch it runs unchanged through the Verity path.

## 2. Linking competitions (additive migrations)

- `competitions.verity_ref` (string, unique, nullable), `competitions.verity_status` (string, nullable),
  `competitions.published_at` (timestamp, nullable). WDS's `status` enum stays as it is.
- `tickets.verity_entry_id` (unsigned big int, unique, nullable), `tickets.withdrawn_at` (timestamp, nullable).
- `draws.verity_ref`, `draws.beacon_round`, `draws.beacon_round_at` (all nullable).
- *(done)* `entrants` table; `entrant_id` on `tickets`, `orders` and `postal_entries`; `competitions.postal_closes_at`.

## 3. Publish is one-way

Admin → Competitions → **Publish** calls `Verity::publish()` with `title`, `max_tickets = total_tickets`,
`ticket_price_pence`, `closes_at`, `max_per_person`, `ticket_allocation = sequential`, `meta.wds_id`, and
one end prize (the competition title, value = cash alternative). That fixes the drand round.

The admin form says, before the button: *"Publishing locks the draw in: the closing time, ticket count,
price and per-person maximum can't change afterwards, and the draw runs automatically on the drand
round one minute after closing."* After publish:
- `CompetitionController::update` refuses `total_tickets`, `ticket_price_pence`, `max_per_person`,
  `closes_at`. Copy, photos and specs stay editable.
- A later closing time only through `Verity::extend($c, $until, $reason)` (public reason), from its own admin action.
- `closes_at` becomes required for publishing.
- `draw_at` is no longer chosen: it's shown as the committed round's time.

## 4. Refunds

What the package supports: `Verity::withdrawEntries($hostRef, $reason)` **while the competition is open**.
It is refused once it has closed (or if an entry was played, n/a without instant wins). The group is
marked withdrawn; its numbers are never reused (`tickets_sold` never goes down). After the close a
refund is money only: the entries stay in the frozen list and in the draw.

WDS change (`OrderController::markRefunded`, `void_tickets = true`):
- open → `withdrawEntries("order_line:{id}", "Refunded")` per line, mirror rows get `withdrawn_at`
  (not deleted, so the customer's history and the numbers stay consistent). Withdrawn numbers leave gaps.
- closed/drawn → refused: *"Entries are frozen once the competition has closed. You can refund the money,
  but the tickets stay in the draw."* Marking the order refunded (money) still works.
- Whole order lines only (a group is one `addEntries` call).

## 5. Draw flow → adapters

- `VerityDrawEngine` and `DrawService` stop running draws. They mirror Verity's state onto `draws`
  from its events (all dispatched after commit):
  - `CompetitionClosed` → `draws` row `status = prepared` with `entries_count`, `entries_hash`,
    `commitment`, `beacon_round`, `beacon_round_at`; `competitions.status = closed`.
  - `CompetitionDrawn` / `WinnerDrawn` → `status = drawn`, `winning_number`, `winning_ticket_id` (by
    `verity_entry_id`), `proof` (the verification record), WDS `winners` row.
  - `RedrawScheduled`, `PrizeForfeited` → audit trail shown in admin.
- `DrawService::publish` stays: it's the **reveal** for the live show. Until then the certificate is a 404
  and `VERITY_ROUTES=false`, so nothing outside the admin shows the result.
- Routes `POST /admin/competitions/{id}/draw`, `/draws/{id}/run`, `/draws/{id}/void` are removed.
- `CommitRevealDrawEngine` is removed: nothing needs it (local development uses Verity too). It kept its
  seed in the database between prepare and draw, which is exactly the risk Verity removes.

**Timing**: Verity draws on the first drand round at least 60 s after `closes_at`. The Instagram live show
becomes a reveal of a result that already exists (and is verifiable), not a button press. Schedule the
close a few minutes before the show.

## 6. Public proof

- `GET /api/draws/{id}/certificate`: 404 until published, then `Verity::verification($ref)` (drand round
  and signature, entries hash, spec/algorithm, audit events and hashes, close witness with the RFC 3161
  token) plus a "how to verify" link.
- `web/src/pages/verify/[id].astro` hosts the package's `<verity-verify>` component (re-checks everything
  in the browser), linked from `live-draws.astro` and `winners.astro`.

## 7. Admin (web/src/pages/admin/draws.astro)

Replace Lock entries / Draw winner / Void with a read-only status view per competition: status, committed
round and its expected time, entries count and hash once closed, proof link, audit trail (closes,
redraws, forfeits). Only actions left: **Publish result** (reveal) and **Forfeit / redraw** for a winner,
which needs two different admins (`by` / `approvedBy`) and a reason. Nothing changes a result.

## 8. Ops

- `routes/console.php`: `Verity::schedule(app(Schedule::class))` next to `orders:expire`.
- Alerts: the package emails `VERITY_ALERT_EMAILS` (stuck draw, late close G-11, chain, anchor,
  witness, invariants) and dispatches `AlertRaised`; WDS logs it at `critical`.
- Env names *(done in .env.example)*: `VERITY_BEACON`, `VERITY_SEED_KEY`, `VERITY_OWNER_PEPPER`,
  `VERITY_TSA_URL`, `VERITY_ALERT_EMAILS`, `VERITY_ANCHOR_EMAILS`, `VERITY_VERIFY_URL`.
- Server steps *(done in forge/README.md, sections 4-5)*.

## 9. Tests to add (with the package)

- Online + postal + event entries get Verity numbers 1..N, mirrored; draw verified with the package's PHP
  record verifier.
- Verity refusal rolls back the WDS order/postal entry (nothing half-written).
- Prepare/close stores nothing that predicts the winner (no seed in plaintext, no randomness before the round).
- Void after close refused; forfeit/redraw recorded in the audit chain with `by` / `approvedBy`.
- Refund with void after close refused; before close, withdrawn in Verity and mirrored.
- Certificate 404 before publish, full proof after.
- drand and TSA faked; one live test behind `VERITY_LIVE_TEST=1`.
- Race test on MySQL through the Verity path.

## Phase 2: instant wins (not built)

Only if the client wants them. Those competitions are published with `ticket_allocation = random` and a
prize table committed at publish (`instantPrizes`). Every other competition stays sequential. Mixing
sequential numbering with instant wins is refused by the package and must not be worked around.
WDS calls `Verity::play()` before any animation and never shows an outcome Verity didn't return.
Instant wins can't be combined with a minimum-entries carry-over.

## Answered

1. Owner reference: `entrants` table, `owner_ref = "entrant:{id}"`.
2. BMW competition: no real sales; cleared with `wds:reset-test-data` and re-created on Verity before launch.
3. Postal: `postal_closes_at` (default three days before close) leaves time to log every envelope
   before Verity freezes the entries at `closes_at`.
4. Database: MySQL 8.4 on a new server; PostgreSQL isn't supported by Verity.
