/**
 * Which pages need a signed-in visitor, and where to send them when they aren't (src/middleware.ts).
 * Kept free of Astro imports so the rules are easy to read and test.
 */

export type Gate = 'admin' | 'account';

/** Old and alternative sign-in addresses → /sign-in (keeping ?next=). The admin ones default to /admin. */
export const SIGN_IN_ALIASES: Record<string, string> = {
  '/login': '/account',
  '/admin/login': '/admin',
  '/admin/sign-in': '/admin',
};

export function gateFor(pathname: string): Gate | null {
  const p = pathname.replace(/\/+$/, '') || '/';
  if (p === '/admin' || p.startsWith('/admin/')) return 'admin';
  if (p === '/account' || p.startsWith('/account/')) return 'account';
  return null;
}

/** A same-site path only (no //host or scheme), or the fallback. */
export function safeNext(next: string | null | undefined, fallback = '/account'): string {
  return next && next.startsWith('/') && !next.startsWith('//') && !next.startsWith('/\\') ? next : fallback;
}

export function signInUrl(next: string): string {
  return `/sign-in?next=${encodeURIComponent(safeNext(next))}`;
}

export const isAdminNext = (next: string): boolean => gateFor(next.split(/[?#]/)[0]) === 'admin';
