<?php

return [
    // Minutes a pending online order holds its tickets while the customer is on the hosted checkout.
    'reservation_minutes' => (int) env('RAFFLE_RESERVATION_MINUTES', 15),

    // Minimum entrant age.
    'minimum_age' => 18,

    // Hours before a responsible-play limit INCREASE takes effect (decreases are immediate).
    'limit_increase_delay_hours' => 24,

    // Where to send the customer after the hosted checkout.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:4321'),

    // Admin account created by `php artisan db:seed`. Read via config() so it works with config:cache.
    'seed_admin' => [
        'email' => env('SEED_ADMIN_EMAIL'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

    'payments' => [
        // fake (local/testing) | cashflows
        'driver' => env('PAYMENT_DRIVER', 'fake'),
        'cashflows' => [
            'configuration_id' => env('CASHFLOWS_CONFIGURATION_ID'),
            'api_key' => env('CASHFLOWS_API_KEY'),
            'base_url' => env('CASHFLOWS_BASE_URL'),
            'webhook_secret' => env('CASHFLOWS_WEBHOOK_SECRET'),
        ],
    ],

    // Draws run on the Verity engine (package sprouthub/verity-engine); its settings are in config/verity.php.
    'draws' => [
        // The public page where anyone re-checks a draw in their own browser (web/src/pages/verify/[id].astro).
        'verify_page' => rtrim((string) env('FRONTEND_URL', 'http://localhost:4321'), '/').'/verify',
        // End-prize value recorded at publish when a competition has no cash alternative.
        'default_prize_value_pence' => 0,
    ],
];
