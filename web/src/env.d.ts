/// <reference types="astro/client" />

declare namespace App {
  interface Locals {
    /** Set by src/middleware.ts while a valid draft preview cookie is held; null otherwise. */
    preview: import('./lib/preview').PreviewInfo | null;
    /** The signed-in staff member on /admin pages (set by src/middleware.ts). */
    admin: import('./lib/admin').AdminUser | null;
    /** The signed-in customer on /account pages (set by src/middleware.ts). */
    user: import('./lib/session').Me | null;
  }
}
