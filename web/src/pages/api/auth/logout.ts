import type { APIRoute } from 'astro';
import { api, usingSampleData } from '../../../lib/api';
import { clearToken, getToken } from '../../../lib/session';

export const POST: APIRoute = async ({ cookies, redirect }) => {
  const token = getToken(cookies);
  if (!usingSampleData && token) {
    try { await api('/auth/logout', { method: 'POST', token }); } catch { /* token already invalid */ }
  }
  clearToken(cookies);
  return redirect('/', 303);
};
