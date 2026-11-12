import type { APIRoute } from 'astro';
import { usingSampleData } from '../../../lib/api';
import { forward, json } from '../../../lib/session';

export const POST: APIRoute = async ({ request, cookies }) => {
  if (usingSampleData) return json({ message: "If there's an account for that email, we've sent a link to set a new password." });
  return forward('/auth/forgot-password', request, cookies);
};
