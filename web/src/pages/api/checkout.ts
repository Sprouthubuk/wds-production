import type { APIRoute } from 'astro';
import { usingSampleData } from '../../lib/api';
import { forward, json } from '../../lib/session';

/**
 * Creates a pending order on the API and returns the payment provider's
 * hosted checkout URL. Prices, limits and availability are re-checked by the API.
 */
export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ reference: 'DEMO-1001', redirectUrl: '/order/DEMO-1001?key=demo' });
  return forward('/checkout', request, cookies);
};
