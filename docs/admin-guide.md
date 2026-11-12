# WDS Competitions: admin guide

This is a plain-English guide to running the site day to day. You'll find everything in the admin area: sign in at
**wdscompetitions.co.uk/admin**, and use the menu on the left (Competitions, Postal entries, Draws, Winners, Event
sales and so on).

All times in the admin are **UK time**.

---

## 1. Adding or editing a competition

1. Go to **Competitions** and press **New competition**. To change one that's already there, click its title.
2. Fill in **The prize**:
   - **Title**: the prize name, as customers will see it.
   - **Web address**: the end of the page link (e.g. `bmw-e30-325i`). It's filled in from the title. Keep it short.
   - **Short description**: one line, shown under the title.
   - **Full description**: the full write-up. Leave a blank line between paragraphs.
   - **Category**, an optional **Badge** (e.g. "Hot"), and an optional **Build video** (a YouTube link).
   - **Cash alternative £** (optional): if you fill it in, the card, the competition page and the winner's email all
     say "or £5,000 cash alternative". Leave it empty if there isn't one.
   - **Highlights**: short bullet points (engine, mileage, extras…). Press **+ Add highlight** for each one.
3. Press **Create competition** (or **Save changes**). Nothing is public yet: a new competition is a **draft**.

### Uploading photos

- Under **Photos**, press **Upload photos** and choose one or more pictures.
- **The first photo is the main image**, used on the card and at the top of the page. Use **← Move** to change the
  order and **Remove** to take one out.
- Landscape photos at least 1600 pixels wide look best.
- Until you add photos, a plain placeholder picture shows.

## 2. Prices and bundles

Under **Tickets**:

- **Ticket price £**: the price of one ticket.
- **Total tickets**: the most that can ever be sold. This includes free postal entries.
- **Max per person**: the most tickets one person can hold, across all routes.
- **Bundles**: press **+ Add bundle** for each deal, e.g. 3 for £40 or 10 for £125. The site shows each bundle's
  saving and its odds, worked out from the total tickets (e.g. "1 in 250 chance" for 3 tickets out of 750).

**A free competition:** set the ticket price to **0**. The button then says "Enter free" and each verified
account can enter once online, plus free postal entry. Free competitions can't have bundles and aren't sold at
events.

## 3. Dates

Under **Dates & entry**:

- **Opens** (optional): when entries open. Before then, the page shows a countdown.
- **Closes**: when entries close. Entries stop automatically at this time, even if not every ticket has sold.
- **Postal entries close**: leave it empty to use the standard rule, 48 hours before the close. Envelopes
  received after this are rejected.
- **Live draw**: when you'll draw the winner on the stream. It's shown as the countdown on the site. A draft can
  leave it empty until it's confirmed, but you need it to publish.

## 4. The preview link (show a draft before it goes live)

On a draft, press **Create preview link**, then **Copy link**, and send it to whoever needs to check the page.

- It shows the real page as it will look, with a "Preview: not live yet" banner.
- Nobody can enter through it.
- It works for 14 days. It stops working when you make a new link (**Regenerate**), press **Revoke**, or publish.

## 5. Publishing (this is permanent)

When everything is right, press **Save changes**, then **Publish and lock the draw**.

**Publishing can't be undone.** It hands the competition to the draw system (Verity), which locks it in:

- The title, ticket price, total tickets, maximum per person and dates **can no longer be changed**.
- The draw method is fixed (see section 8).
- The competition goes live straight away (or at the **Opens** time, if that's set).

Photos, the description and highlights can still be updated afterwards.

If you really have to give people more time (for example, the site was down), there is **Extend closing time**. It
can only move the closing time later, it needs a public reason, and the reason is shown on the draw record. Our
terms say there are no extensions, so treat this as an emergency tool only.

Check the preview link first, every time.

## 6. Logging postal entries

Go to **Postal entries** and type in each envelope as it arrives:

1. Choose the competition.
2. Enter the name, address, email, phone and date of birth exactly as written on the entry.
3. Enter **Received (date and time)**: when the envelope actually arrived, not the date on it.
4. Press **Log entry & allocate ticket**.

If the entry is valid, it gets the next ticket number straight away, in the same draw as paid entries, and the
entrant is emailed.

The site rejects these entries and shows the reason:

- received **less than 48 hours before close**, or after the postal deadline you set;
- the entrant is **under 18**;
- the entrant is already at the **maximum per person**.

Keep the envelopes.

## 7. Event stand mode (selling tickets at a show)

1. On the competition, tick **Event ticket sales** and save. It must be a live, paid competition.
2. Go to **Event sales** and press **Open stand mode** on a tablet or phone. Press **Full screen** to hide
   everything else.
3. For each sale:
   - pick the competition and the number of tickets;
   - take the customer's name, email and date of birth (they must be 18 or over);
   - tick "Email me about new competitions" only if they ask;
   - choose **Cash** or **Card reader**.
4. The customer gets the next ticket numbers and they're emailed. Press **New sale** for the next customer.

The usual maximum per person applies, and every sale appears under **Event sales** for cashing up.

## 8. Running a live draw

Every published competition has a card on **Draws**, with its method. There are two methods, and you choose one
before publishing (**How it's drawn**).

On either method:

- Open the **reveal screen** for the stream with **Open reveal screen ↗**. It shows the entry count, the
  countdown, and then the winning ticket. Use **Rehearse** to practise with made-up data.
- **No button can change a result.** If a winner forfeits (for example, they can't be reached or don't pass the
  checks), the system draws a replacement on a new public draw. The original winner stays on the record.

### Method A: Verity draw (automatic)

- At the closing time, the system **seals the list of entries** and timestamps it, so no entry can be added or
  removed.
- The winner is then picked from a public random number (drand) that nobody, including us, can know in advance.
  It happens on its own, a short time after the close. Leave the reveal screen up on the stream: it counts down,
  then shows **Reveal the winner**. Press it (or the Space bar) when you're ready.
- **Close & draw now** closes entries immediately and draws on the next public number about a minute later. Only
  use it if you mean to close early, live on the stream.

### Method B: external draw (random number generator on the stream)

1. Before publishing, set the **Random number tool** you'll use and **Its link**.
2. On the stream, press **Close entries**. This seals the entry list.
3. Use that tool, on camera, to pick a number from 1 to the number of entries.
4. Enter the **Winning ticket number** and the **Livestream link**, and press **Record result**. The system checks
   the ticket is on the sealed list. It doesn't pick it for you.

## 9. Publishing the result

When you've revealed the winner on the live stream, press **Publish result** on the draw's card.

- That puts the winner and the draw certificate on the **Live draws** page.
- Anyone can check the draw at **/verify**.
- The winner is emailed separately about claiming the prize.

Under **Winners** you can **Upload winner photo** and add past winners. Only show a winner on the site once
they've agreed.

---

### Quick checklist for a new competition

- [ ] Prize details, photos (main photo first), highlights, cash alternative if any.
- [ ] Price, total tickets, max per person, bundles.
- [ ] Opens / Closes / Live draw. Postal deadline (leave empty for 48 hours before close).
- [ ] Draw method (and the tool, for an external draw).
- [ ] Preview link checked.
- [ ] **Publish and lock the draw**. Remember: permanent.
