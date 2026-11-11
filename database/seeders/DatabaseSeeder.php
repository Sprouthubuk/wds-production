<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(16);
        User::updateOrCreate(['email' => env('SEED_ADMIN_EMAIL', 'admin@wdscompetitions.co.uk')], [
            'name' => 'WDS Admin', 'first_name' => 'WDS', 'last_name' => 'Admin',
            'password' => $password, 'role' => 'admin', 'date_of_birth' => '1990-01-01',
        ]);
        if (! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn("Admin password: {$password}  (change it after first sign-in)");
        }

        Competition::updateOrCreate(['slug' => 'bmw-e46-330ci-clubsport'], [
            'title' => 'BMW E46 330Ci Clubsport',
            'strapline' => 'Estoril Blue. ZF manual. One of an estimated 600 ever built, refreshed in the WDS Originals workshop and drawn live on Christmas Eve.',
            'category' => 'cars',
            'badge' => 'Main event',
            'status' => 'live',
            'images' => [
                ['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Estoril Blue BMW E46 330Ci Clubsport in the WDS Originals workshop'],
                ['src' => '/images/e46-clubsport-front.jpg', 'alt' => 'Clubsport front view'],
                ['src' => '/images/e46-clubsport-bonnet.jpg', 'alt' => 'Clubsport with the bonnet up'],
            ],
            'description' => [
                'With an estimated 600 Clubsports ever made, these cars are becoming more and more sought after, especially one presented like this. It\'s a low-owner example that has just been through extensive work on the WDS Originals YouTube channel.',
                'It has recently had a replacement engine with a light refresh, a ZF manual gearbox swap and a long list of upgrades. We\'ve also refurbished the wheels, headlights and weather trims to make it one of the nicest examples in the UK.',
            ],
            'specs' => [
                ['label' => 'Built', 'value' => '~600'],
                ['label' => 'Gearbox', 'value' => 'ZF manual'],
                ['label' => 'Paint', 'value' => 'Estoril Blue'],
                ['label' => 'Interior', 'value' => 'Heated black Nappa'],
                ['label' => 'Audio', 'value' => 'Harman Kardon'],
                ['label' => 'Engine', 'value' => 'Replacement, refreshed'],
            ],
            'ticket_price_pence' => 1495,
            'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
            'total_tickets' => 750,
            'max_per_person' => 50,
            'opens_at' => now(),
            'closes_at' => '2026-12-24 18:00:00',
            'draw_at' => '2026-12-24 20:00:00',
            'postal_entry_enabled' => true,
            'event_sales_enabled' => true,
        ]);

        Winner::firstOrCreate(['display_name' => 'Mark Robinson', 'prize' => 'Alpina D3 Coupé'], [
            'image' => '/images/winner-mark-robinson.jpg', 'verification' => 'approved', 'handover' => 'delivered', 'show_on_site' => true,
        ]);
    }
}
