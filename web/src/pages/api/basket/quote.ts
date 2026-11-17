import type { APIRoute } from 'astro';
import { usingSampleData } from '../../../lib/api';
import { forward, json } from '../../../lib/session';

/** The basket's price/availability check: the API says which lines can be entered now ("not open yet"). */
export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ data: [] });
  return forward('/basket/quote', request, cookies);
};
