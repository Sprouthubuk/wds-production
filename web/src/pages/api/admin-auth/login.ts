import type { APIRoute } from 'astro';
import { api, ApiError, usingSampleData } from '../../../lib/api';
import { json, setAdminToken } from '../../../lib/session';

export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ ok: true, redirect: '/admin' });
  const { email, password } = await request.json().catch(() => ({}));
  try {
    const res = await api<{ token: string; isStaff: boolean; isAdmin: boolean }>('/auth/login', {
      method: 'POST', body: JSON.stringify({ email, password, admin: true }),
    });
    if (!res.isStaff) return json({ message: 'This account does not have admin access.' }, 403);
    setAdminToken(cookies, res.token);
    return json({ ok: true, redirect: res.isAdmin ? '/admin' : '/admin/event-sales' });
  } catch (e) {
    if (e instanceof ApiError) return json(e.body, e.status);
    return json({ message: 'The server could not be reached.' }, 502);
  }
};
