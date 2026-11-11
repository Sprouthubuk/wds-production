import type { APIRoute } from 'astro';
import { api, ApiError, usingSampleData } from '../../../lib/api';
import { json, setToken } from '../../../lib/session';

export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ ok: true });
  try {
    const res = await api<{ token: string }>('/auth/login', { method: 'POST', body: await request.text() });
    setToken(cookies, res.token);
    return json({ ok: true });
  } catch (e) {
    if (e instanceof ApiError) return json(e.body, e.status);
    return json({ message: 'The server could not be reached.' }, 502);
  }
};
