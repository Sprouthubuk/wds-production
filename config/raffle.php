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

    'draws' => [
        // verity (Liam's Verity engine) | commit-reveal (built-in interim engine)
        'engine' => env('DRAW_ENGINE', 'commit-reveal'),
        'verity' => [
            'base_url' => env('VERITY_BASE_URL'),
            'api_key' => env('VERITY_API_KEY'),
        ],
    ],
];
