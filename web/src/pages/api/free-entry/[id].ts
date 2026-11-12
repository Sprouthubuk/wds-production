import type { APIRoute } from 'astro';
import { usingSampleData } from '../../../lib/api';
import { forward, getToken, json } from '../../../lib/session';

/**
 * "Enter free" on a free competition: the API checks the account (signed in, email confirmed, 18+,
 * not self-excluded), allows one online entry and returns the order page to go to.
 */
export const POST: APIRoute = async ({ params, request, cookies }) => {
  const id = String(params.id ?? '');
  if (!/^\d+$/.test(id)) return json({ message: 'Not found' }, 404);
  if (usingSampleData) return json({ reference: 'DEMO-1001', redirectUrl: '/order/DEMO-1001?key=demo' });
  if (!getToken(cookies)) return json({ message: 'Please sign in to enter.' }, 401);
  return forward(`/competitions/${id}/free-entry`, request, cookies);
};
