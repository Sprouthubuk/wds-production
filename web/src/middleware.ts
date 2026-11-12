import { defineMiddleware } from 'astro:middleware';
import type { APIContext } from 'astro';
import { api, ApiError, usingSampleData, withPreviewToken } from './lib/api';
import { PREVIEW_COOKIE } from './lib/preview';
import { gateFor, safeNext, signInUrl, SIGN_IN_ALIASES, type Gate } from './lib/auth-gate';
import { clearAdminToken, clearToken, getAdminToken, getToken, type Me } from './lib/session';
import type { AdminUser } from './lib/admin';

/**
 * 1. Sign-in (every admin and account page, in one place: never per page). No token, or one the API
 *    rejects (401), clears the cookie and redirects to /sign-in?next=<path>. A signed-in customer on
 *    /admin goes to /account. A 401 or 403 thrown by an API call while a page renders is handled the
 *    same way, so these pages never 500 for a missing or expired session.
 * 2. Draft preview (Admin → competition → Preview link). While the visitor holds a valid preview
 *    cookie, every API read made for this request carries the token, so the whole site renders the
 *    draft as if it were live; the layout shows the "Preview: not live yet" banner. Previewed pages
 *    are never cached or indexed. Entries stay impossible: the API refuses drafts on every entry
 *    route, whatever the cookie says.
 */
export const onRequest = defineMiddleware(async (context, next) => {
  context.locals.preview = null;
  context.locals.admin = null;
  context.locals.user = null;
  const { pathname, search } = context.url;
  const here = pathname + search;

  const alias = SIGN_IN_ALIASES[pathname.replace(/\/+$/, '')];
  if (alias) {
    return context.redirect(signInUrl(safeNext(context.url.searchParams.get('next'), alias)), 302);
  }

  const gate = gateFor(pathname);
  if (gate && !usingSampleData) {
    const stop = await signIn(context, gate, here);
    if (stop) return stop;
    try {
      const response = await next();
      response.headers.set('Cache-Control', 'private, no-store');
      return response;
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) return signOutAndRedirect(context, gate, here);
      if (e instanceof ApiError && e.status === 403) return context.redirect(forbiddenTarget(context.locals.admin), 302);
      throw e;
    }
  }

  return preview(context, next);
});

async function signIn(context: APIContext, gate: Gate, here: string): Promise<Response | null> {
  const token = gate === 'admin' ? getAdminToken(context.cookies) : getToken(context.cookies);
  if (!token) {
    // A signed-in customer who opens /admin: their account, not a sign-in form.
    if (gate === 'admin' && getToken(context.cookies)) {
      const me = await me_<Me>(getToken(context.cookies)!).catch(() => null);
      if (me && !(me as Partial<AdminUser>).isStaff) return context.redirect('/account', 302);
    }
    return context.redirect(signInUrl(here), 302);
  }
  let me: AdminUser;
  try {
    me = await me_<AdminUser>(token);
  } catch (e) {
    if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return signOutAndRedirect(context, gate, here);
    throw e;
  }
  if (gate === 'admin') {
    if (!me.isStaff) {
      clearAdminToken(context.cookies);
      return context.redirect('/account', 302);
    }
    context.locals.admin = me;
  } else {
    context.locals.user = me;
  }
  return null;
}

const me_ = async <T>(token: string): Promise<T> => (await api<{ data: T }>('/me', { token })).data;

function signOutAndRedirect(context: APIContext, gate: Gate, here: string): Response {
  if (gate === 'admin') clearAdminToken(context.cookies);
  else clearToken(context.cookies);
  return context.redirect(signInUrl(here), 302);
}

/** Staff who aren't admins work the event stand and postal entries; anyone else goes to their account. */
const forbiddenTarget = (admin: AdminUser | null): string => (admin?.isStaff ? '/admin/event-sales' : '/account');

async function preview(context: APIContext, next: () => Promise<Response>): Promise<Response> {
  const token = context.cookies.get(PREVIEW_COOKIE)?.value;
  if (!token || usingSampleData || context.url.pathname.startsWith('/api/admin')) {
    return next();
  }

  try {
    context.locals.preview = (await api<{ data: App.Locals['preview'] }>(`/preview/${encodeURIComponent(token)}`)).data;
  } catch {
    context.locals.preview = null;
  }
  if (!context.locals.preview) {
    context.cookies.delete(PREVIEW_COOKIE, { path: '/' });
    return next();
  }

  const response = await withPreviewToken(token, () => next());
  response.headers.set('Cache-Control', 'private, no-store');
  response.headers.set('X-Robots-Tag', 'noindex, nofollow');
  return response;
}
