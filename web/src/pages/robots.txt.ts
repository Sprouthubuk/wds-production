import type { APIRoute } from 'astro';

/** Points crawlers at the sitemap and keeps them out of private and transactional pages. */
export const GET: APIRoute = ({ site }) => {
  const base = (site ?? new URL('https://wdscompetitions.co.uk')).toString().replace(/\/$/, '');
  const body = ['User-agent: *', 'Disallow: /admin', 'Disallow: /account', 'Disallow: /basket', 'Disallow: /order', 'Disallow: /preview', 'Disallow: /api/', '', `Sitemap: ${base}/sitemap.xml`, ''].join('\n');
  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
