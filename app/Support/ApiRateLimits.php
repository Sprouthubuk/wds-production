<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limits, each with its own counter (throttle:{name}). Inline limits (throttle:10,1) all share one counter per
 * visitor, so a few basket checks used up someone's sign-in attempts.
 *
 * Signed in: per account. Otherwise per visitor IP, which the website passes on (bootstrap/app.php, trustProxies).
 */
class ApiRateLimits
{
    public static function register(): void
    {
        // Guessing passwords, making accounts: always by IP, signed in or not.
        RateLimiter::for('sign-in', fn (Request $r) => Limit::perMinute(10)->by('ip:'.$r->ip()));

        // Generous for one person in a launch rush.
        RateLimiter::for('basket', fn (Request $r) => Limit::perMinute(60)->by(self::visitor($r)));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(60)->by(self::visitor($r)));

        RateLimiter::for('forms', fn (Request $r) => Limit::perMinute(10)->by(self::visitor($r)));
        RateLimiter::for('free-entry', fn (Request $r) => Limit::perMinute(10)->by(self::visitor($r)));
        RateLimiter::for('draw-lookup', fn (Request $r) => Limit::perMinute(60)->by(self::visitor($r)));
        RateLimiter::for('preview', fn (Request $r) => Limit::perMinute(30)->by(self::visitor($r)));
        RateLimiter::for('verify-email', fn (Request $r) => Limit::perMinute(20)->by(self::visitor($r)));
        RateLimiter::for('verify-email-resend', fn (Request $r) => Limit::perMinutes(10, 3)->by(self::visitor($r)));
        RateLimiter::for('launch-alert-test', fn (Request $r) => Limit::perMinute(10)->by(self::visitor($r)));
        RateLimiter::for('cashflows', fn (Request $r) => Limit::perMinute(120)->by('ip:'.$r->ip()));
    }

    private static function visitor(Request $request): string
    {
        $id = $request->user('sanctum')?->getAuthIdentifier();

        return $id === null ? 'ip:'.$request->ip() : 'user:'.$id;
    }
}
