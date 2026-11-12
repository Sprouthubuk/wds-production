<?php

namespace App\Http\Resources;

use App\Models\Competition;

/**
 * What the public may see of a competition that isn't live yet: the title, the first image and the
 * entries-open and draw dates. Never prices, ticket counts, bundles, limits or anything that lets someone enter.
 */
final class CompetitionTeaser
{
    public const FIELDS = ['slug', 'title', 'image', 'opensAt', 'drawAt'];

    public static function make(Competition $c): array
    {
        $image = $c->images[0] ?? null;

        return [
            'slug' => $c->slug,
            'title' => $c->title,
            'image' => $image ? ['src' => (string) ($image['src'] ?? ''), 'alt' => (string) ($image['alt'] ?? '')] : null,
            'opensAt' => $c->opens_at?->toIso8601String(), // optional: the pre-launch countdown targets it while it's ahead
            'drawAt' => $c->draw_at?->toIso8601String(),
        ];
    }
}
