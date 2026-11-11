# Connecting the Verity draw engine

The API talks to any draw engine through one interface: `backend/app/Services/Draws/DrawEngine.php`.
Until Verity is connected, the built-in `CommitRevealDrawEngine` runs draws (good for testing and
as a fallback, but Verity should be live before the 24 December draw).

## What the engine receives

| Call | Input | Must return |
|---|---|---|
| `prepare()` | competition, ordered list of every valid ticket number, SHA-256 `entriesHash` of that list | `DrawPreparation(commitment, data)` — `commitment` is shown in Admin → Draws and can be read out on the Instagram live; `data` is private state kept until the draw |
| `draw()` | same list + hash + the preparation | `DrawOutcome(winningNumber, proof[], externalId)` — `winningNumber` **must be one of the ticket numbers passed in** |
| `verificationUrl()` | the outcome | public URL to Verity's proof page (or `null`) |

Engines never receive names, emails or addresses — only ticket numbers and the hash.

`entriesHash = sha256("1,2,3,…")` — ticket numbers ascending, comma-joined, no spaces.
Anyone can rebuild it from the published entry list.

## Steps

1. Fill in `backend/app/Services/Draws/VerityDrawEngine.php` (`prepare`, `draw`, `verificationUrl`) using
   Verity's API. Credentials come from `config('raffle.draws.verity')` (`VERITY_BASE_URL`, `VERITY_API_KEY`).
2. Set `DRAW_ENGINE=verity` in the API `.env`. `RaffleServiceProvider` swaps it in — nothing else changes.
3. Run a test draw on a draft competition with a few postal entries: Admin → Draws → Lock entries → Draw winner → Publish.
4. Check the public proof at `GET /api/draws/{id}/certificate` and the Live draws page.

## Draw flow in the admin

1. **Lock entries** → competition closes, entries fingerprinted, engine `prepare()` called.
2. **Draw winner** → re-checks the fingerprint (refuses if any ticket changed), calls `draw()`,
   creates the Winner record (awaiting ID).
3. **Publish** → result + proof appear on /live-draws.
