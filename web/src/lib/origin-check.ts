const FORM_TYPES = ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'];

/**
 * A form or upload sent from another website (cross-site request forgery). Same rules as Astro's own check
 * (security.checkOrigin, off in astro.config.mjs), except which Origin is ours. Astro compares it with the address it
 * thinks it has, http://localhost:4321 behind nginx, so it refused every upload and sign-out from a browser that
 * doesn't send Sec-Fetch-Site (Safari before 16.4, some in-app browsers). Its own fix, allowedDomains, would let
 * visitors choose their IP (src/lib/client-ip.ts).
 *
 * `ours`: the one origin allowed, https://wdscompetitions.co.uk. Nothing the visitor sends (Host, X-Forwarded-Host)
 * changes it. On staging and locally, browsers that send Sec-Fetch-Site (all current ones) work as before.
 */
export function isCrossSite(request: Request, ours: string): boolean {
  if (['GET', 'HEAD', 'OPTIONS'].includes(request.method)) return false;
  // JSON from another site needs the browser's CORS permission first, which is never given.
  const type = request.headers.get('content-type')?.toLowerCase();
  if (type && !FORM_TYPES.some((t) => type.includes(t))) return false;

  // Set by the browser itself: a page can't fake it.
  const site = request.headers.get('sec-fetch-site');
  if (site) return site !== 'same-origin' && site !== 'none';

  const origin = request.headers.get('origin');
  return origin !== null && origin !== '' && origin !== ours;
}
