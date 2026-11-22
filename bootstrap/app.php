<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The website server (same box) says which visitor each request is for, in X-Forwarded-For. Only loopback is
        // believed, and only that header: from anywhere else it's ignored, so nobody can pick their own IP. Without
        // an explicit list Laravel trusts every caller's forwarded headers on *.on-forge.com. See forge/README.md, section 2.
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_FOR);
        $middleware->prepend(\App\Http\Middleware\OnForgeHttps::class);
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always answer API requests with JSON.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
