import type { SiteSettings } from './types';

/**
 * Where live draws are streamed. Instagram is the primary channel; an optional YouTube channel
 * (Settings → Live draws) can be added. With both set, the copy says "our socials" and the
 * Instagram link stays first.
 */
export interface DrawChannels {
  instagram: { handle: string; url: string } | null;
  youtube: { url: string } | null;
  /** "Drawn live on our socials" or "Drawn live on Instagram @wds_originals". */
  drawnLive: string;
  /** "our socials" / "Instagram @wds_originals", to finish a sentence ("Streamed live on …"). */
  where: string;
  /** The main follow/watch link (Instagram first). */
  primary: { label: string; url: string } | null;
}

export function drawChannels(s: Pick<SiteSettings, 'drawsInstagram' | 'drawsYoutubeUrl'>): DrawChannels {
  const handle = (s.drawsInstagram ?? '').replace(/^@/, '').trim();
  const instagram = handle ? { handle, url: `https://instagram.com/${handle}` } : null;
  const youtube = s.drawsYoutubeUrl ? { url: s.drawsYoutubeUrl } : null;
  const where = instagram && youtube ? 'our socials' : instagram ? `Instagram @${instagram.handle}` : youtube ? 'YouTube' : 'our socials';
  const primary = instagram ? { label: `Follow @${instagram.handle}`, url: instagram.url } : youtube ? { label: 'Watch on YouTube', url: youtube.url } : null;
  return { instagram, youtube, drawnLive: `Drawn live on ${where}`, where, primary };
}
