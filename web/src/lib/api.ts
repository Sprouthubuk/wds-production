import type { Competition, DrawResult, SiteSettings, Winner } from './types';
import { sampleCompetitions, sampleDraws, sampleSettings, sampleWinners } from '../data/sample';

/**
 * Server-side client for the Laravel API.
 * Set API_URL (e.g. https://api.wdscompetitions.co.uk) in the environment.
 * Without it the site runs on sample data so it can be previewed on its own.
 */
export const API_URL: string = (import.meta.env.API_URL || process.env.API_URL || '').replace(/\/$/, '');
export const usingSampleData = !API_URL;

export class ApiError extends Error {
  constructor(public status: number, public body: any) {
    super(typeof body?.message === 'string' ? body.message : `API error ${status}`);
  }
}

export async function api<T>(path: string, init: RequestInit & { token?: string | null } = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  if (init.token) headers.set('Authorization', `Bearer ${init.token}`);
  const res = await fetch(`${API_URL}/api${path}`, { ...init, headers });
  const text = await res.text();
  const body = text ? JSON.parse(text) : null;
  if (!res.ok) throw new ApiError(res.status, body);
  return body as T;
}

export async function getCompetitions(): Promise<Competition[]> {
  if (usingSampleData) return sampleCompetitions.filter((c) => c.status === 'live');
  const res = await api<{ data: Competition[] }>('/competitions');
  return res.data;
}

export async function getCompetition(slug: string): Promise<Competition | null> {
  if (usingSampleData) return sampleCompetitions.find((c) => c.slug === slug) ?? null;
  try {
    const res = await api<{ data: Competition }>(`/competitions/${encodeURIComponent(slug)}`);
    return res.data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export async function getWinners(): Promise<Winner[]> {
  if (usingSampleData) return sampleWinners;
  return (await api<{ data: Winner[] }>('/winners')).data;
}

export async function getDraws(): Promise<DrawResult[]> {
  if (usingSampleData) return sampleDraws;
  return (await api<{ data: DrawResult[] }>('/draws')).data;
}

export async function getSettings(): Promise<SiteSettings> {
  if (usingSampleData) return sampleSettings;
  try {
    return (await api<{ data: SiteSettings }>('/settings')).data;
  } catch {
    return sampleSettings;
  }
}

export interface DrawCertificate {
  competition: string; winningTicket: string | null; drawnAt: string | null; publishedAt: string | null;
  reference: string; algorithm: string | null; ticketAllocation: string | null; commitment: string | null; revealedSeed: string | null;
  entriesCount: number | null; entriesHash: string | null; drandChainHash: string; drandRound: number | null; drandRoundAt: string | null;
  drandRandomness: string | null; drandSignature: string | null; drawSeed: string | null; winningTickets: number[];
  auditEvent: { seq: number; hash: string } | null; closeTimestamp: { authority: string; witnessedAt: string; token: string } | null;
  engine: { version: string; engine_sha256: string; draw_path_sha256: string } | null; howToVerify: string;
}

/** A published draw's certificate summary (null until the result is published). */
export async function getCertificate(id: string): Promise<{ certificate: DrawCertificate; url: string } | null> {
  if (usingSampleData) return null;
  try {
    const res = await api<{ certificate: DrawCertificate }>(`/draws/${encodeURIComponent(id)}/certificate`);
    return { certificate: res.certificate, url: `${API_URL}/api/draws/${encodeURIComponent(id)}/certificate` };
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}
