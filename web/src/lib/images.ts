/**
 * The 720px version of a photo, for cards, thumbnails and the basket. Only the photos shipped with the site
 * (/images/*.jpg) and Admin uploads stored as name-full.ext have one (name-720.jpg); older uploads (/storage/…
 * without -full) are used as they are, so they never point at a file that doesn't exist.
 */
export function thumb(src: string | null | undefined): string {
  if (!src) return '';
  // An Admin upload stored with its 720px copy (app/Support/UploadedPhoto.php): name-full.ext → name-720.jpg.
  if (/\/storage\/[^?#]*-full\.(jpe?g|png|webp|gif)$/i.test(src)) return src.replace(/-full\.[a-z]+$/i, '-720.jpg');
  return /^\/images\/[^?#]+\.jpg$/.test(src) && !src.endsWith('-720.jpg') ? src.replace(/\.jpg$/, '-720.jpg') : src;
}
