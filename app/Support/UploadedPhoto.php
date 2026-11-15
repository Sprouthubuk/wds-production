<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores a photo uploaded in Admin on the public disk, plus a 720px-wide JPEG for cards, thumbnails, the basket and
 * winners, so phones don't download the full-size original for a small card.
 *
 * Naming tells the website which version exists: when the small copy was made, the original is "<name>-full.<ext>"
 * and the copy "<name>-720.jpg" (web/src/lib/images.ts thumb()). If it couldn't be made (no GD, an unreadable or
 * huge image), the original is stored under a plain name and is used everywhere, as before.
 */
class UploadedPhoto
{
    public const WIDTH = 720;
    private const MAX_PIXELS = 40_000_000;   // decoding needs ~4 bytes a pixel: skip anything that could exhaust memory

    /** @return string the original's path on the public disk */
    public static function store(UploadedFile $file, string $dir): string
    {
        $name = Str::random(40);
        $ext = strtolower($file->extension() ?: 'jpg');
        $small = self::resized($file);
        if ($small === null) {
            return $file->storeAs($dir, "{$name}.{$ext}", 'public');
        }
        Storage::disk('public')->put("{$dir}/{$name}-720.jpg", $small);

        return $file->storeAs($dir, "{$name}-full.{$ext}", 'public');
    }

    /** The 720px JPEG (never wider than the original), or null if it can't be made. */
    private static function resized(UploadedFile $file): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        try {
            $size = @getimagesize($file->getRealPath());
            if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
                return null;
            }
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if ($src === false) {
                return null;
            }
            $w = min(self::WIDTH, $size[0]);
            $h = max(1, (int) round($size[1] * $w / $size[0]));
            $dst = imagecreatetruecolor($w, $h);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0));   // transparent PNGs go on the site's black
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $size[0], $size[1]);
            ob_start();
            imagejpeg($dst, null, 82);
            $jpeg = (string) ob_get_clean();

            return $jpeg !== '' ? $jpeg : null;
        } catch (Throwable $e) {
            Log::warning('Could not make the 720px copy of an uploaded photo', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
