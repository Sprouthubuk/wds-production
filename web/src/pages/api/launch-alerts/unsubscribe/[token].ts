import type { APIRoute } from 'astro';
import { usingSampleData } from '../../../../lib/api';
import { forward, json } from '../../../../lib/session';

/** The unsubscribe page's button (the email's one-click header goes straight to the API). */
export const POST: APIRoute = async ({ params, request, cookies }) => {
  if (usingSampleData) return json({ message: "You're unsubscribed." });
  return forward(`/launch-alerts/unsubscribe/${encodeURIComponent(params.token ?? '')}`, request, cookies);
};
