<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Forge's own *.on-forge.com names are served through Cloudflare, which says in X-Forwarded-Proto that the visitor
 * used https. Signed links (email confirmation) are checked against the full URL, so the scheme has to be right.
 * Only the scheme is taken: the visitor's IP is never believed from here (bootstrap/app.php, trustProxies).
 */
class OnForgeHttps
{
    public function handle(Request $request, Closure $next)
    {
        if (str_ends_with($request->host(), '.on-forge.com') && $request->headers->get('X-Forwarded-Proto') === 'https') {
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }
}
