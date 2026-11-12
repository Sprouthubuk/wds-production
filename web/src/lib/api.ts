import type { Competition, CompetitionTeaser, DrawResult, SiteSettings, Winner } from './types';
import { AsyncLocalStorage } from 'node:async_hooks';
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

/** The draft preview token for the request being rendered (set by src/middleware.ts). */
const previewToken = new AsyncLocalStorage<string>();
export function withPreviewToken<T>(token: string, fn: () => T): T {
  return previewToken.run(token, fn);
}

export async function api<T>(path: string, init: RequestInit & { token?: string | null } = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  // Reads made while a draft preview is active see the draft as if live (the API never lets it be entered).
  const preview = previewToken.getStore();
  if (preview) headers.set('X-Preview-Token', preview);
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

/** Pre-launch: the featured draft to tease while nothing is live (null when there's none). */
export async function getUpcoming(): Promise<CompetitionTeaser | null> {
  if (usingSampleData) return null;
  try {
    return (await api<{ data: CompetitionTeaser | null }>('/upcoming')).data;
  } catch {
    return null;
  }
}

/** "Launching soon" for a draft's slug; null when the slug isn't an upcoming competition. */
export async function getUpcomingBySlug(slug: string): Promise<CompetitionTeaser | null> {
  if (usingSampleData) return null;
  try {
    return (await api<{ data: CompetitionTeaser }>(`/upcoming/${encodeURIComponent(slug)}`)).data;
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
  /** Draft (draw methods): 'external' = drawn on the livestream from the sealed list. */
  method?: 'verity' | 'external'; drawNow?: boolean; livestreamUrl?: string | null; recordedAt?: string | null;
  /** External draws: the random number tool, sealed with the entry list at close, and the plain statement. */
  drawTool?: { name: string; url: string } | null; seal?: string | null; statement?: string | null;
  /** External draws: how the pick was made, a random number generator or numbered tokens drawn by hand. */
  externalKind?: 'rng' | 'physical' | null;
  auditEvent: { seq: number; hash: string } | null; closeTimestamp: { authority: string; witnessedAt: string; token: string } | null;
  engine: { version: string; engine_sha256: string; draw_path_sha256: string } | null; howToVerify: string;
  /** Original draw, then Redraw 1, 2 …; a forfeited winner is kept (ticket and reason only). */
  winners: { label: string; ticket: string | null; name: string | null; status: 'winner' | 'forfeited'; reason: string | null }[];
  redrawsPending: { redraw: number; forfeitedTicket: number; drandRound: number | null; drandRoundAt: string | null }[];
}

/** A published draw's certificate summary (null until the result is published). */
/** The /verify lookup: a draw reference or certificate ID as typed; null when no published draw matches. */
export async function lookupDraw(q: string): Promise<{ id: number; reference: string } | null> {
  if (usingSampleData) return null;
  try {
    return (await api<{ data: { id: number; reference: string } }>(`/draws/lookup?q=${encodeURIComponent(q)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 404 || e.status === 422)) return null;
    throw e;
  }
}

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
