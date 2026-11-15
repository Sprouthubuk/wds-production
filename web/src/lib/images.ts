/**
 * The 720px version of a photo, for cards, thumbnails and the basket. Only the photos shipped with the site
 * (/images/*.jpg) have one (name-720.jpg); photos uploaded in Admin (/storage/…) are used as they are, so
 * they never point at a file that doesn't exist.
 */
export function thumb(src: string | null | undefined): string {
  if (!src) return '';
  return /^\/images\/[^?#]+\.jpg$/.test(src) && !src.endsWith('-720.jpg') ? src.replace(/\.jpg$/, '-720.jpg') : src;
}
