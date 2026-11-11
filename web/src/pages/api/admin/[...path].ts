import type { APIRoute } from 'astro';
import { API_URL, usingSampleData } from '../../../lib/api';
import { getAdminToken, json } from '../../../lib/session';

/**
 * Proxies browser calls from the admin to the Laravel admin API using the httpOnly admin cookie.
 * JSON, multipart uploads and CSV downloads all pass straight through.
 */
const handler: APIRoute = async ({ params, request, cookies, url }) => {
  const path = params.path ?? '';
  if (usingSampleData) {
    if (request.method === 'GET') return json({ data: [] });
    return json({ message: 'Preview mode — connect the API (set API_URL) to save changes.', preview: true });
  }
  const token = getAdminToken(cookies);
  if (!token) return json({ message: 'Please sign in again.' }, 401);

  const headers = new Headers({ Accept: 'application/json', Authorization: `Bearer ${token}` });
  const type = request.headers.get('content-type');
  if (type) headers.set('Content-Type', type);
  const body = ['GET', 'HEAD'].includes(request.method) ? undefined : await request.arrayBuffer();
  try {
    const res = await fetch(`${API_URL}/api/admin/${path}${url.search}`, { method: request.method, headers, body });
    const out = new Headers();
    for (const h of ['content-type', 'content-disposition']) {
      const v = res.headers.get(h);
      if (v) out.set(h, v);
    }
    return new Response(res.body, { status: res.status, headers: out });
  } catch {
    return json({ message: 'The server could not be reached.' }, 502);
  }
};
export const GET = handler;
export const POST = handler;
export const PUT = handler;
export const DELETE = handler;
