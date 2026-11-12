/**
 * The free postal entry deadline, worded the same everywhere (competition page, entry panel,
 * Free Postal Entry page, FAQs). Postal entries must be RECEIVED at least 48 hours before the
 * competition closes, unless an admin set its own postal deadline (postalClosesAtCustom).
 * The API's emails use the same sentence (Competition::postalDeadlineSentence()).
 */
export const POSTAL_HOURS_BEFORE_CLOSE = 48;

/** The general rule, for pages that aren't about one competition. */
export const POSTAL_RULE = `Postal entries must be received at least ${POSTAL_HOURS_BEFORE_CLOSE} hours before the competition closes.`;

/** "6:00pm on Tuesday 22 December 2026", in UK time. */
export function ukDateTime(iso: string): string {
  const d = new Date(iso);
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/London', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })
      .formatToParts(d).map((p) => [p.type, p.value]),
  );
  return `${parts.hour}:${parts.minute}${String(parts.dayPeriod).toLowerCase().replace(/\./g, '')} on ${parts.weekday} ${parts.day} ${parts.month} ${parts.year}`;
}

interface HasPostalClose { postalClosesAt: string | null; postalClosesAtCustom?: string | null }

/** The exact deadline ("6:00pm on Tuesday 22 December 2026"), or null when there's no closing time. */
export function postalBy(c: HasPostalClose): string | null {
  return c.postalClosesAt ? ukDateTime(c.postalClosesAt) : null;
}

/** "Postal entries must be received at least 48 hours before close: by 6:00pm on Tuesday 22 December 2026 (UK time)." */
export function postalDeadlineSentence(c: HasPostalClose): string {
  const by = postalBy(c);
  if (!by) return POSTAL_RULE;
  return c.postalClosesAtCustom
    ? `Postal entries must be received by ${by} (UK time).`
    : `Postal entries must be received at least ${POSTAL_HOURS_BEFORE_CLOSE} hours before close: by ${by} (UK time).`;
}
