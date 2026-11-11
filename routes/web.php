<?php

use Illuminate\Support\Facades\Route;

// This app is an API only — the website is the Astro app in /web.
Route::get('/', fn () => response()->json(['app' => 'WDS Competitions API', 'ok' => true]));
