# Instant wins: what Verity supports, and a proposed design (item 10)

Status: **investigation only, no code.** Based on verity-engine v3.0.3:
- `src/Engine/InstantWinAllocator.php` and the VERITY-2/3 table in `Specs`;
- `src/Services/InstantWins.php`, `Publisher.php` and `Support/Verification.php`;
- SPEC §6, §7, §11 and §13.2.

## What the engine does

| Question | Answer |
|---|---|
| **How are prizes set up?** | Pass them at publish: `Verity::publish($terms, $endPrizes, $instantPrizes)`. Each instant prize has `name` and `quantity`, optionally `value_pence` and `meta`. A competition can have instant prizes only, or instant prizes plus an end prize. |
| **When are prizes assigned to tickets?** | **At publish, before any sale.** The table (which ticket numbers win which prize) is a Fisher–Yates shuffle on an HMAC_DRBG stream from the server seed. The seed commitment published at launch therefore locks the table. DB guards stop the table and prizes changing after publish. |
| **When is a win known?** | Only when the entry is **played**: `Verity::play($entry)`, `playNext($comp, $owner)` or `playGroup($group)` (max 100 per call). Playing records `instant.won` on the audit chain and fires `InstantWinRevealed`. Unplayed outcomes are never loaded. Playing is idempotent and can't be undone. |
| **What proof is there?** | At publish: the seed commitment. The table hash is recorded too, but **withheld from everyone, admin included, until the competition is drawn/finished**, because an unsalted hash of a small table could be brute-forced. At the end, the record publishes the seed, `instant_prizes` and the full `instant_table`. The browser, Python and PHP verifiers re-derive the table from the seed and check its hash against the hash on the chain at publish. |
| **Can the public verify it?** | Yes, after close, on the same `/verify/{id}` page and with the same verifiers we already ship. Before close, we can show only the commitment, the prize list and how many prizes have been won. |
| **How are tickets numbered?** | **Random allocation is required.** The engine refuses `ticket_allocation = sequential` with instant prizes (`SEQUENTIAL_WITH_INSTANT`). Otherwise anyone who knew the table could time a purchase to land on a winning number. WDS uses sequential today, so instant-win competitions would hand out random numbers from the unsold pool. |
| **Refunds** | `withdrawEntries` is refused once **any** entry in the order has been played. After that, a refund is money only and the entries stay in. |
| **Closing** | An instant-only competition (`winner_count = 0`) closes when every winning number has sold (or at `closes_at`). It then finishes without drand. With an end prize, the draw is as now. |
| **Restrictions** | Not allowed with instant prizes: a minimum entry threshold, or the external draw method (item 8). |
| **Postal and event entries** | They get numbers from the same random pool and can win the same prizes (equal odds is a threat-model test). Someone still has to *play* them. The engine never plays anything by itself. |

## Proposed design

**Admin**
- Competition form: an "Instant wins" table with prize name, quantity, value and an image (WDS side). Editable while a draft and locked at publish, like the ticket terms. It shows "1 in N chance per ticket" and the total prize value. When instant prizes are present, ticket numbering switches to random automatically, with a note.
- A new competition page, "Instant wins":
  - prizes won and left, with each winner's ticket, name and time;
  - the claim and handover status, reusing the existing Winners workflow (verification → handover → show on site).
  - No admin can see which numbers win before close: the engine doesn't give it to us.
- Postal entries: when an envelope is logged, play its entry straight away and email or post the result. Event sales: play at the stand and show the result on the stand-mode confirmation screen.
- Backstop job at close: play every entry that's still unplayed, so every winning ticket that sold is revealed, and email the winners.

**Customer**
- Competition page: a prize grid with won/left counters ("12 of 40 instant prizes left"). The text says the prizes were fixed in a sealed table before sales opened.
- After payment, the order confirmation becomes **"Reveal your tickets"**:
  - "Reveal one" or "Reveal all" (`playGroup`), with a short black-and-white card flip. It's off with reduced motion.
  - The page only ever shows what `play()` returned; it doesn't animate towards a result it doesn't know yet.
  - A win shows the prize and next steps, and sends the win email.
- My account → tickets: the result for each ticket, plus "Reveal" for any not yet played. Unplayed tickets are played automatically at close (backstop above).
- `/verify/{id}`, after close: the full table and the re-run in the browser. Each customer can check their own ticket.

**WDS build outline:** about 1–1.5 weeks.
- Additive tables: `instant_prizes` (mirror), and `winners.type`.
- Publish the prizes, and use random allocation when prizes are present.
- A "Reveal" API (`play` / `playGroup`, scoped to the customer's own order).
- The `InstantWinRevealed` listener: create the winner and send the email.
- Stand mode and postal hooks, and the backstop at close.
- UI as above; tests against the engine's fakes.
- No engine changes needed.

## Needs the owner / client

1. **Auto-reveal or press-to-reveal?** Recommended: press to reveal (it's the experience customers expect), with an automatic reveal at close as the backstop.
2. **Random ticket numbers** for instant-win competitions are mandatory. Check the client is happy with that (for example, ticket "0417" rather than "0001").
3. **Refunds after reveal** are money only (entries stay in). The terms need a line on this.
4. Prize fulfilment for small prizes (site credit, cash, physical items) and whether wins under a threshold skip ID verification.
5. Legal/terms check that instant wins sit fine with the free postal route. Postal entries get the same odds and are revealed when logged.
