import { defineMiddleware } from 'astro:middleware';
import { api, usingSampleData, withPreviewToken } from './lib/api';
import { PREVIEW_COOKIE } from './lib/preview';

/**
 * Draft preview (Admin → competition → Preview link). While the visitor holds a valid preview
 * cookie, every API read made for this request carries the token, so the whole site renders the
 * draft as if it were live; the layout shows the "Preview: not live yet" banner. Previewed pages
 * are never cached or indexed. Entries stay impossible: the API refuses drafts on every entry
 * route, whatever the cookie says.
 */
export const onRequest = defineMiddleware(async (context, next) => {
  context.locals.preview = null;
  const token = context.cookies.get(PREVIEW_COOKIE)?.value;
  if (!token || usingSampleData || context.url.pathname.startsWith('/admin') || context.url.pathname.startsWith('/api/admin')) {
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
});
