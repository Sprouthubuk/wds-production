import type { APIRoute } from 'astro';
import { usingSampleData } from '../../lib/api';
import { forward, json } from '../../lib/session';

export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ ok: true });
  return forward('/contact', request, cookies);
};
