<?php

/**
 * Seeds the throwaway end-to-end database (web/e2e/run.mjs runs this, then the servers, then Playwright).
 *
 * Refuses to run unless everything points at fakes: the e2e SQLite file, the log mailer, the fake payment driver,
 * local (non-drand) randomness and unreachable drand/Cashflows hosts. Never run it any other way.
 */

use App\Models\Competition;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$guards = [
    'database is the e2e SQLite file' => config('database.default') === 'sqlite' && str_ends_with(str_replace('\\', '/', (string) config('database.connections.sqlite.database')), 'storage/e2e/e2e.sqlite'),
    'mail goes to the log' => config('mail.default') === 'log',
    'payments are fake' => config('raffle.payments.driver') === 'fake',
    'randomness is local, not drand' => config('verity.beacon') !== 'drand',
    'drand is unreachable' => str_starts_with((string) config('verity.drand.base_url'), 'http://127.0.0.1:9'),
    'no timestamp authority' => (string) config('verity.tsa_url') === '',
    'not production' => ! app()->isProduction(),
    'config is not cached' => ! app()->configurationIsCached(),
];
foreach ($guards as $what => $ok) {
    if (! $ok) {
        fwrite(STDERR, "E2E seed refused: {$what} is not true.\n");
        exit(1);
    }
}

Artisan::call('migrate:fresh', ['--force' => true]);

$password = (string) getenv('E2E_PASSWORD');
$user = fn (string $email, string $role, array $extra = []) => User::create($extra + [
    'name' => ucfirst(strtok($email, '@')).' Tester', 'first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'Tester',
    'email' => $email, 'password' => $password, 'role' => $role, 'date_of_birth' => '1990-01-01', 'email_verified_at' => now(),
]);
$user('admin@e2e.test', 'admin');
$user('staff@e2e.test', 'staff');
$user('alice@e2e.test', 'customer');
$user('bob@e2e.test', 'customer');

$publish = function (array $attrs) {
    $c = Competition::create($attrs + [
        'category' => 'cars', 'status' => 'draft', 'strapline' => 'An end-to-end test competition.',
        'images' => [['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Test car']],
        'description' => ['A competition created for the end-to-end tests.'],
        'specs' => [['label' => 'Gearbox', 'value' => 'Manual']],
        'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
        'total_tickets' => 750, 'max_per_person' => null, 'opens_at' => now()->subDay(),
        'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00',
        'postal_entry_enabled' => true, 'event_sales_enabled' => true,
    ]);

    return app(VerityDrawEngine::class)->publish($c);
};

$publish(['slug' => 'e2e-bmw', 'title' => 'E2E BMW E46', 'badge' => 'Main event', 'cash_alternative_pence' => 500000, 'sort_order' => 1]);
$publish(['slug' => 'e2e-few-left', 'title' => 'E2E Few Left', 'total_tickets' => 4, 'max_per_person' => 2, 'bundles' => [], 'sort_order' => 2]);
$publish(['slug' => 'e2e-free', 'title' => 'E2E Free Table', 'category' => 'merch', 'ticket_price_pence' => 0, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 1, 'event_sales_enabled' => false, 'postal_entry_enabled' => false, 'sort_order' => 3]);
Competition::create(['slug' => 'e2e-draft', 'title' => 'E2E Draft (never public)', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 100, 'bundles' => [],
    'total_tickets' => 10, 'closes_at' => '2026-12-30 19:00:00', 'draw_at' => '2026-12-30 19:30:00', 'images' => []]);

fwrite(STDOUT, "E2E seed done.\n");
