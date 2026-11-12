import type { APIRoute } from 'astro';
import { API_URL, usingSampleData } from '../../../lib/api';
import { forward, getToken, json } from '../../../lib/session';

const routes: Record<string, string> = {
  details: '/me',
  limits: '/me/limits',
  break: '/me/break',
  'self-exclude': '/me/self-exclude',
  'id-upload': '/me/verification',
  'verify-email': '/me/verify-email',
};

export const POST: APIRoute = async ({ params, request, cookies }) => {
  const path = routes[params.action ?? ''];
  if (!path) return json({ message: 'Not found' }, 404);
  if (usingSampleData) return json({ ok: true, preview: true });

  if (params.action === 'id-upload') {
    // Multipart upload: pass the form data straight through.
    const form = await request.formData();
    const res = await fetch(`${API_URL}/api${path}`, {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${getToken(cookies) ?? ''}` },
      body: form,
    });
    return new Response(await res.text(), { status: res.status, headers: { 'Content-Type': 'application/json' } });
  }
  return forward(path, request, cookies, params.action === 'details' ? 'PUT' : 'POST');
};
