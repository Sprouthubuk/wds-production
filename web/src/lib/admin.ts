import type { AstroCookies } from 'astro';
import { api, ApiError, usingSampleData } from './api';
import { getAdminToken, clearAdminToken, type Me, sampleMe } from './session';
import { sampleAdmin } from '../data/sample-admin';

export interface AdminUser extends Me { isStaff: boolean }

/** The signed-in staff member, or null (src/middleware.ts redirects to /sign-in?next=… first). */
export async function currentAdmin(cookies: AstroCookies): Promise<AdminUser | null> {
  if (usingSampleData) return { ...sampleMe, firstName: 'Jason', lastName: 'Wood', isAdmin: true, isStaff: true };
  const token = getAdminToken(cookies);
  if (!token) return null;
  try {
    const me = (await api<{ data: AdminUser }>('/me', { token })).data;
    return me.isStaff ? me : null;
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) clearAdminToken(cookies);
    return null;
  }
}

/** Server-side GET against /api/admin/*. Falls back to sample data in preview mode. */
export async function adminGet<T>(cookies: AstroCookies, path: string): Promise<T> {
  if (usingSampleData) {
    const key = path.split('?')[0];
    const hit = (sampleAdmin as Record<string, unknown>)[key];
    if (hit === undefined) throw new Error(`No sample admin data for ${key}`);
    return hit as T;
  }
  return api<T>(`/admin${path}`, { token: getAdminToken(cookies) });
}

export const gbp = (n: number | null | undefined) =>
  n == null ? '—' : `£${n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
export const when = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London' }) : '—';
export const day = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Europe/London' }) : '—';
/** ISO → value for <input type="datetime-local"> in UK time. */
export const localInput = (iso: string | null | undefined) => {
  if (!iso) return '';
  const d = new Date(iso);
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/London', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(d);
  const g = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}`;
};
