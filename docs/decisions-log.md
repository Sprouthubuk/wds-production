# Decisions log

Decisions taken while working autonomously; each one can be revisited.

| Date | Item | Decision | Reason |
|---|---|---|---|
| 2026-11-12 | 1 Postal | Staff now log the **date and time** an envelope was received (new additive column `postal_entries.received_at`; `received_on` keeps the date). A date alone counts as the start of that day. | The 48-hour rule is exact to the minute (47h59m rejected, 48h01m accepted), which a date can't express. Start of day is the entrant-favourable reading for older rows. |
| 2026-11-12 | 1 Postal | When an admin overrides the postal deadline, late envelopes keep the reason "Received after the postal closing date"; the default deadline uses "Received less than 48 hours before close". | The 48-hour wording would be untrue for a custom deadline. |
| 2026-11-12 | 1 Postal | A received time more than 5 minutes in the future is refused (422). | Catches typing mistakes that would otherwise pass the deadline check. |
| 2026-11-12 | 1 Postal | Every logged postal entry now emails the entrant (ticket number, or the rejection reason), with the deadline sentence. | The site already promised "Postal entries are emailed once logged", but no email existed. |
| 2026-11-12 | 1 Postal | The seeder writes the Knoll House free entry address into Settings only when it's empty or still a `[placeholder]`. | Never overwrite an address an admin has set. |
