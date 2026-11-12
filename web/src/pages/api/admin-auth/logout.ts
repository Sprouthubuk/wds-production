import type { APIRoute } from 'astro';
import { api, usingSampleData } from '../../../lib/api';
import { clearAdminToken, getAdminToken } from '../../../lib/session';

export const POST: APIRoute = async ({ cookies, redirect }) => {
  const token = getAdminToken(cookies);
  if (!usingSampleData && token) await api('/auth/logout', { method: 'POST', token }).catch(() => null);
  clearAdminToken(cookies);
  return redirect('/sign-in?next=%2Fadmin', 303);
};
