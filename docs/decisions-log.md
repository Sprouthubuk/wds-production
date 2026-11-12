# Decisions log

Decisions taken while working autonomously; each one can be revisited.

| Date | Item | Decision | Reason |
|---|---|---|---|
| 2026-11-12 | 1 Postal | Staff now log the **date and time** an envelope was received (new additive column `postal_entries.received_at`; `received_on` keeps the date). A date alone counts as the start of that day. | The 48-hour rule is exact to the minute (47h59m rejected, 48h01m accepted), which a date can't express. Start of day is the entrant-favourable reading for older rows. |
| 2026-11-12 | 1 Postal | When an admin overrides the postal deadline, late envelopes keep the reason "Received after the postal closing date"; the default deadline uses "Received less than 48 hours before close". | The 48-hour wording would be untrue for a custom deadline. |
| 2026-11-12 | 1 Postal | A received time more than 5 minutes in the future is refused (422). | Catches typing mistakes that would otherwise pass the deadline check. |
| 2026-11-12 | 1 Postal | Every logged postal entry now emails the entrant (ticket number, or the rejection reason), with the deadline sentence. | The site already promised "Postal entries are emailed once logged", but no email existed. |
| 2026-11-12 | 1 Postal | The seeder writes the Knoll House free entry address into Settings only when it's empty or still a `[placeholder]`. | Never overwrite an address an admin has set. |
| 2026-11-12 | 2 Channels | Added a separate optional setting `drawsYoutubeUrl` for live draws, instead of reusing `youtubeUrl`. | `youtubeUrl` is already the WDS Originals builds channel (footer, About page) and is always set; reusing it would switch every page to "our socials" immediately. |
| 2026-11-12 | 2 Channels | With both set, short copy says "Drawn live on our socials"; places with room (competition FAQ, terms, home stat) name both, and Instagram stays the first/primary button with YouTube as a secondary one. | "Instagram stays primary." |
| 2026-11-12 | 2 Channels | Also fixed the home page and terms postal wording to the 48-hour rule (missed in item 1). | Consistency. |
| 2026-11-12 | 3 Pre-launch | The teaser comes from new public endpoints `GET /api/upcoming` and `/api/upcoming/{slug}` that return only slug, title, first image and draw date (`CompetitionTeaser`); the full competition endpoints still 404 for drafts. | Draft prices, ticket counts and bundles never leave the API, so the website can't leak them by mistake. |
| 2026-11-12 | 3 Pre-launch | "Featured draft" = a draft (or scheduled) competition whose draw is ahead, badge first, then sort order, then draw date. Pre-launch mode = no competition with status `live`. | Same rule the home page already uses for the featured live competition. |
| 2026-11-12 | 3 Pre-launch | The countdown counts down to the **live draw** date. | It's the only date the teaser shows; there's no launch date field. |
| 2026-11-12 | 3 Pre-launch | A draft's slug renders a "Launching soon" page (noindex) with the teaser and sign-up; unknown slugs still 404. Generic "Enter online" buttons become "Get first access" while pre-launch. | "Never expose entry buttons" before launch. |
