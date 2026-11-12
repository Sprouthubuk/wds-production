/** The cookie a draft preview link sets (see src/middleware.ts and /preview/[token]). */
export const PREVIEW_COOKIE = 'wds_preview';

export interface PreviewInfo {
  slug: string;
  title: string;
  expiresAt: string;
}
