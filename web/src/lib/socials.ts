import type { SiteSettings, SocialLink } from './types';

/** Used when the API doesn't send socialLinks (an older API, or sample data). Matches SettingsPayload::SOCIAL_DEFAULTS. */
export const DEFAULT_SOCIALS: SocialLink[] = [
  { network: 'instagram', label: '@wds_originals', url: 'https://www.instagram.com/wds_originals/' },
  { network: 'youtube', label: '@wds_originals', url: 'https://www.youtube.com/@wds_originals' },
  { network: 'instagram', label: '@wds_competitions', url: 'https://www.instagram.com/wds_competitions/' },
];

export const NETWORK_NAMES: Record<SocialLink['network'], string> = { instagram: 'Instagram', youtube: 'YouTube', facebook: 'Facebook' };

/** The social links to show, in order (Admin → Settings → Social links; empty URL = hidden). */
export const socialLinks = (s: SiteSettings): SocialLink[] => (s.socialLinks ?? DEFAULT_SOCIALS).filter((l) => l.url);

/** The first YouTube link (the WDS Originals channel), for "Watch the builds". */
export const youtubeLink = (s: SiteSettings): string | null => socialLinks(s).find((l) => l.network === 'youtube')?.url ?? null;
