# The Verity draw engine

Draws run on **Verity** (`sprouthub/verity-engine`, a private Composer package, pinned to `^3.0.2`).
Verity owns the ticket numbers, the close, the draw, redraws and the proof. WDS decides who may enter
(caps, responsible play, reservations, pricing, payment) and mirrors what Verity decided.

The package's own `vendor/sprouthub/verity-engine/INTEGRATION.md` is the reference. This page says how WDS uses it.
Server setup and go-live checks: `forge/README.md`, sections 4 and 5.

## How a competition goes

| Step | Who | What happens |
|---|---|---|
| Publish | Admin → Competitions → **Publish** | `VerityDrawEngine::publish()` → `Verity::publish()` with sequential ticket numbers and one end prize. Terms (closing time, tickets, price, max per person, title) are fixed and the drand round is planned. One way. |
| Entries | checkout webhook, postal log, event sale | `TicketAllocator` checks WDS's rules first, then `Verity::addEntries` / `addPostalEntry` (`source` paid / postal, `host_ref` = `order_line:{id}` / `postal:{id}`, `owner_ref` = `entrant:{id}`) in the same transaction, and copies Verity's numbers into `tickets`. A refusal rolls everything back. |
| Refund | Admin → Orders | While open: `Verity::withdrawEntries` and the tickets get `withdrawn_at` (numbers are never reused). After close: money only, the tickets stay in the draw. |
| Close | `verity:process-due` at the closing time | Entries frozen and hashed, a future drand round committed, the close timestamped (RFC 3161). WDS mirrors it as a `draws` row (`prepared`). |
| Draw | `verity:process-due` once the round is out | BLS-verified drand round, winner drawn, seed revealed, audit chain written. WDS mirrors the winner (`drawn`). |
| Reveal | Admin → Draws → **Publish result** | The result and `GET /api/draws/{id}/certificate` go public; the public page `/verify/{id}` re-runs every check in the visitor's browser. |
| Forfeit / redraw | (not built in the admin yet) | `Verity::redraw()` / `forfeit()` with protocol R2 on a new future round; WDS mirrors it from the events. |

Nothing in WDS can pick a number or a winner, void a competition with entries, or change a result.

## Where it lives

- `app/Services/Draws/VerityDrawEngine.php`: the only class that calls Verity.
- `app/Services/DrawService.php`: mirrors Verity onto `competitions`, `draws` and `winners` (from Verity's events and after every `verity:process-due`), and the reveal.
- `app/Services/TicketAllocator.php`: entries.
- `routes/console.php`: `Verity::schedule()` (process-due every minute, chain checks, anchors, `verity:check`, invariants).
- `web/public/vendor/verity/`: the package's browser verifier, byte-for-byte (`tests/Unit/WebVerifierCopyTest.php`); copy it again after a package upgrade.
- Tests: `tests/Feature/VerityDrawFlowTest.php` (the whole flow, checked with Verity's `RecordVerifier`), `tests/Concerns/VerityFakes.php` (a real drand round served by `Http::fake`, a fake timestamp authority), `tests/Feature/VerityLiveTest.php` (real drand + TSA, `VERITY_LIVE_TEST=1`).

## Upgrading the package

1. `composer update sprouthub/verity-engine` to the new tag; read its CHANGELOG.
2. Copy `resources/js` into `web/public/vendor/verity/` (see `WebVerifierCopyTest`).
3. `php artisan test`, then `php artisan verity:check` shows the new version and fingerprints; compare them with the tag's `SOURCE-MANIFEST.txt`.
4. Never edit `vendor/sprouthub/verity-engine`: its fingerprints would no longer match the reviewed release.
