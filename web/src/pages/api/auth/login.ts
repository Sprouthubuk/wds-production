import type { APIRoute } from 'astro';
import { api, ApiError, usingSampleData } from '../../../lib/api';
import { isAdminNext, safeNext } from '../../../lib/auth-gate';
import { json, setAdminToken, setToken } from '../../../lib/session';

/**
 * Sign in from /sign-in. With `next` pointing into /admin, it's an admin sign-in: staff only, and the
 * token goes in the admin cookie (shorter-lived). Otherwise the customer session cookie.
 */
export const POST: APIRoute = async ({ request, cookies }) => {
  const { email, password, next } = await request.json().catch(() => ({}));
  const target = safeNext(typeof next === 'string' ? next : null);
  const admin = isAdminNext(target);
  if (usingSampleData) return json({ ok: true, redirect: target });
  try {
    const res = await api<{ token: string; isStaff: boolean; isAdmin: boolean }>('/auth/login', {
      method: 'POST', body: JSON.stringify({ email, password, ...(admin ? { admin: true } : {}) }),
    });
    if (admin) {
      if (!res.isStaff) return json({ message: 'This account does not have admin access.' }, 403);
      setAdminToken(cookies, res.token);
    } else {
      setToken(cookies, res.token);
    }
    return json({ ok: true, redirect: target });
  } catch (e) {
    if (e instanceof ApiError) return json(e.body, e.status);
    return json({ message: 'The server could not be reached.' }, 502);
  }
};
