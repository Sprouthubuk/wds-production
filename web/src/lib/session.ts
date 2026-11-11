import type { AstroCookies } from 'astro';
import { api, ApiError, usingSampleData } from './api';

const COOKIE = 'wds_session';
const ADMIN_COOKIE = 'wds_admin';

export interface Me {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  phone: string | null;
  dateOfBirth: string | null;
  address: string | null;
  marketingOptIn: boolean;
  siteCredit: number;
  limits: { daily: number | null; weekly: number | null; monthly: number | null };
  breakUntil: string | null;
  selfExcludedUntil: string | null;
  isAdmin: boolean;
}

const cookieOpts = { httpOnly: true, secure: import.meta.env.PROD, sameSite: 'lax' as const, path: '/', maxAge: 60 * 60 * 24 * 30 };

export function getToken(cookies: AstroCookies): string | null {
  return cookies.get(COOKIE)?.value ?? null;
}
export function setToken(cookies: AstroCookies, token: string): void {
  cookies.set(COOKIE, token, cookieOpts);
}
export function clearToken(cookies: AstroCookies): void {
  cookies.delete(COOKIE, { path: '/' });
}

export function getAdminToken(cookies: AstroCookies): string | null {
  return cookies.get(ADMIN_COOKIE)?.value ?? null;
}
export function setAdminToken(cookies: AstroCookies, token: string): void {
  cookies.set(ADMIN_COOKIE, token, { ...cookieOpts, maxAge: 60 * 60 * 12 });
}
export function clearAdminToken(cookies: AstroCookies): void {
  cookies.delete(ADMIN_COOKIE, { path: '/' });
}

export const sampleMe: Me = {
  id: 0, firstName: 'Preview', lastName: 'Customer', email: 'preview@example.com', phone: null, dateOfBirth: null, address: null,
  marketingOptIn: false, siteCredit: 0, limits: { daily: null, weekly: null, monthly: null }, breakUntil: null, selfExcludedUntil: null, isAdmin: false,
};

/** Returns the signed-in customer, or null. In sample mode returns a preview customer. */
export async function currentUser(cookies: AstroCookies): Promise<Me | null> {
  if (usingSampleData) return sampleMe;
  const token = getToken(cookies);
  if (!token) return null;
  try {
    return (await api<{ data: Me }>('/me', { token })).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) clearToken(cookies);
    return null;
  }
}

export function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** Forward a JSON request to the Laravel API, passing the customer's token. */
export async function forward(path: string, request: Request, cookies: AstroCookies, method = 'POST'): Promise<Response> {
  const body = method === 'GET' ? undefined : await request.text();
  try {
    const data = await api<unknown>(path, { method, body: body || undefined, token: getToken(cookies) });
    return json(data);
  } catch (e) {
    if (e instanceof ApiError) return json(e.body ?? { message: e.message }, e.status);
    return json({ message: 'The server could not be reached. Please try again.' }, 502);
  }
}
