import type { APIRoute } from 'astro';
import { PREVIEW_COOKIE } from '../../lib/preview';

/** "Exit preview": drops the preview cookie and goes back to the live site. */
export const GET: APIRoute = ({ cookies, redirect }) => {
  cookies.delete(PREVIEW_COOKIE, { path: '/' });
  const res = redirect('/', 302);
  res.headers.set('Cache-Control', 'no-store');
  return res;
};
