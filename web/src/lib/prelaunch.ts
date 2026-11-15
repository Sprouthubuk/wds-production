import { getCompetitions, getUpcoming } from './api';
import type { CompetitionTeaser } from './types';

export interface Prelaunch {
  /** True while no competition is live: entry buttons become "Get first access". */
  on: boolean;
  /** The featured draft being teased, if any. */
  upcoming: CompetitionTeaser | null;
  /** Where "Get first access" jumps: the hero sign-up when a draft is teased, else the home sign-up strip. */
  href: string;
}

/** Pre-launch state for this request (worked out once per request and kept on Astro.locals). */
export async function prelaunchState(locals: object): Promise<Prelaunch> {
  const store = locals as { __prelaunch?: Promise<Prelaunch> };
  store.__prelaunch ??= (async () => {
    const [competitions, upcoming] = await Promise.all([getCompetitions().catch(() => null), getUpcoming()]);
    // If the API can't be reached, keep the normal buttons rather than guess.
    const on = competitions !== null && !competitions.some((c) => c.status === 'live');
    return { on, upcoming: on ? upcoming : null, href: on && upcoming ? '/#first-access' : '/#notify' };
  })();
  return store.__prelaunch;
}

/**
 * What the pre-launch countdown counts to: the entries-open date while it's still ahead
 * ("Entries open in"), otherwise the live draw ("Live draw in").
 */
export function launchCountdown(t: CompetitionTeaser, now = Date.now()): { at: string; label: string; aria: string } | null {
  if (t.opensAt && Date.parse(t.opensAt) > now) return { at: t.opensAt, label: 'Entries open in', aria: 'Countdown to entries opening' };
  if (t.drawAt) return { at: t.drawAt, label: 'Live draw in', aria: 'Countdown to the live draw' };
  return null;
}
