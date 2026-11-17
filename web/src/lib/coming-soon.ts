import { ukDateTime } from './postal';
import type { Competition } from './types';

/** Coming soon: published, shown with its terms, not enterable until opensAt (the API refuses entries too). */
export const isComingSoon = (c: Pick<Competition, 'status' | 'notOpenYet' | 'preview'>): boolean =>
  !c.preview && (c.status === 'coming_soon' || Boolean(c.notOpenYet));

const ordinal = (n: number) => {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return `${n}${s[(v - 20) % 10] ?? s[v] ?? s[0]}`;
};

/** "1st December" (UK time), for "Launching 1st December". */
export function launchDay(iso: string): string {
  const d = new Date(iso);
  const day = Number(d.toLocaleString('en-GB', { day: 'numeric', timeZone: 'Europe/London' }));
  const month = d.toLocaleString('en-GB', { month: 'long', timeZone: 'Europe/London' });
  return `${ordinal(day)} ${month}`;
}

/** "Tuesday 1 December 2026, 9:00am" (UK time): the site's deadline style (ukDateTime), as the API words it. */
export const opensLabel = (iso: string): string => ukDateTime(iso);

/** The earliest future opening time among coming-soon competitions, if any. */
export function nextOpening(list: Competition[], now = Date.now()): string | null {
  const times = list.filter(isComingSoon).map((c) => c.opensAt).filter((t): t is string => !!t && Date.parse(t) > now).sort();
  return times[0] ?? null;
}

/** The consent line shown with every launch alert sign-up (stored with it by the API). */
export const CONSENT_LINE = 'Email me when WDS Competitions launch. Unsubscribe anytime.';

/**
 * Free postal entry before opening (coming soon): "Entries open Tuesday 1 December 2026, 9:00am (UK time).
 * Envelopes received before then are counted once entries open." The API holds them and numbers them at opening.
 */
export function postalBeforeOpening(c: Pick<Competition, 'status' | 'notOpenYet' | 'preview' | 'opensAt'>): string | null {
  if (!isComingSoon(c) || !c.opensAt) return null;
  return `Entries open ${opensLabel(c.opensAt)} (UK time). Envelopes received before then are counted once entries open.`;
}
