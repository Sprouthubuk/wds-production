import type { AstroCookies } from 'astro';
import { api, usingSampleData } from './api';
import { getToken } from './session';
import type { Competition } from './types';

/** A signed-in customer's place against a per-person maximum: what they hold (online and postal) and what's left. */
export interface Allowance { max: number; held: number; remaining: number }

/** Null for a guest, a competition with no maximum, or if it can't be read: the selector then uses the maximum (checkout checks it anyway). */
export async function getAllowance(cookies: AstroCookies, c: Pick<Competition, 'slug' | 'maxPerPerson'>): Promise<Allowance | null> {
  const token = c.maxPerPerson && !usingSampleData ? getToken(cookies) : null;
  if (!token) return null;
  try {
    return (await api<{ data: Allowance | null }>(`/me/allowance/${encodeURIComponent(c.slug)}`, { token })).data;
  } catch {
    return null;
  }
}
