<?php

return [
    // Minutes a pending online order holds its tickets while the customer is on the hosted checkout.
    'reservation_minutes' => (int) env('RAFFLE_RESERVATION_MINUTES', 15),

    // Minimum entrant age.
    'minimum_age' => 18,

    // Hours before a responsible-play limit INCREASE takes effect (decreases are immediate). Policy: 72 hours.
    'limit_increase_delay_hours' => 72,

    // DCMS Voluntary Code: credit card spending per customer per calendar month (UK), across all competitions.
    'credit_card_monthly_cap_pence' => 25000,

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
            // Notifications aren't signed: the URL carries this secret (.../api/payments/cashflows/notify?token=...).
            'webhook_token' => env('CASHFLOWS_WEBHOOK_TOKEN'),
            // Optional: only accept notifications from these IPs (comma separated). Off when empty.
            // Integration: 54.75.5.171,54.73.83.234   Production: 54.74.58.255,52.215.48.101
            'webhook_ips' => env('CASHFLOWS_WEBHOOK_IPS', ''),
            // Until card type detection is confirmed on real payments, a card of unknown type counts towards
            // the GBP 250/month credit card cap.
            'unknown_card_as_credit' => (bool) env('CASHFLOWS_UNKNOWN_CARD_AS_CREDIT', true),
            // The payment page expires when the ticket reservation does (expirationDateTimeUtc on the payment job).
            'send_expiry' => (bool) env('CASHFLOWS_SEND_EXPIRY', true),
            // Log the whole (redacted) response of the first N paid payments, to see the real card fields.
            'log_first_payments' => (int) env('CASHFLOWS_LOG_FIRST_PAYMENTS', 20),
            'timeout' => (int) env('CASHFLOWS_TIMEOUT', 20),
        ],
    ],

    // Draws run on the Verity engine (package sprouthub/verity-engine); its settings are in config/verity.php.
    'draws' => [
        // The public page where anyone re-checks a draw in their own browser (web/src/pages/verify/[id].astro).
        'verify_page' => rtrim((string) env('FRONTEND_URL', 'http://localhost:4321'), '/').'/verify',
        // End-prize value recorded at publish when a competition has no cash alternative.
        'default_prize_value_pence' => 0,
        // Days a winner has to complete the claim (ID check, contact) before the prize can be forfeited.
        'claim_days' => (int) env('RAFFLE_CLAIM_DAYS', 14),
        // Forfeit reasons. Only the label goes on Verity's public audit chain (permanent), so it must never
        // hold personal details; a free-text note stays in WDS. ":days" is claim_days.
        'forfeit_reasons' => [
            'failed_id' => 'Failed ID check',
            'no_response' => 'No response within :days days',
            'ineligible' => 'Ineligible under the terms',
            'other' => 'Other reason under the terms',
        ],
    ],
];
