import type { APIRoute } from 'astro';
import { getCompetitions } from '../lib/api';

/**
 * The public pages search engines may list, plus every published competition (live or coming soon) from the API.
 * Private pages (account, basket, admin, order, preview) are never listed; robots.txt points here.
 */
const PUBLIC_PAGES = [
  '/', '/competitions', '/free-postal-entry', '/how-it-works', '/faqs', '/live-draws', '/winners', '/verify', '/draw-process',
  '/about', '/our-commitment', '/responsible-play', '/contact', '/terms', '/website-terms', '/privacy', '/cookies', '/refunds', '/complaints',
];

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c]!));

export const GET: APIRoute = async ({ site }) => {
  const base = (site ?? new URL('https://wdscompetitions.co.uk')).toString().replace(/\/$/, '');
  const competitions = await getCompetitions().catch(() => []);
  const urls = [...PUBLIC_PAGES, ...competitions.map((c) => `/competitions/${encodeURIComponent(c.slug)}`)];
  const body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map((u) => `  <url><loc>${esc(base + u)}</loc></url>`).join('\n')}\n</urlset>\n`;
  return new Response(body, { headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } });
};
