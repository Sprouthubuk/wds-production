/// <reference types="astro/client" />

declare namespace App {
  interface Locals {
    /** Set by src/middleware.ts while a valid draft preview cookie is held; null otherwise. */
    preview: import('./lib/preview').PreviewInfo | null;
  }
}
