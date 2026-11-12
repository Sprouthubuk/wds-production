<?php

namespace Database\Seeders;

use App\Http\Resources\SettingsPayload;
use App\Models\Competition;
use App\Models\Setting;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedSiteSettings();

        Competition::updateOrCreate(['slug' => 'bmw-e46-330ci-clubsport'], [
            'title' => 'BMW E46 330Ci Clubsport',
            'strapline' => 'Estoril Blue. ZF manual. One of an estimated 600 ever built, refreshed in the WDS Originals workshop and drawn live on Christmas Eve.',
            'category' => 'cars',
            'badge' => 'Main event',
            // A draft until an admin publishes it on Verity (Admin → Competitions → Publish), which locks the draw in.
            'status' => 'draft',
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
            'cash_alternative_pence' => 500000,
            'opens_at' => now(),
            // Confirmed by the client: entries close Thu 24 Dec 2026 at 19:00 UK time (GMT, so UTC), live
            // draw at 19:30. Postal entries must be received 48 hours before closes_at
            // (Competition::postalClosesAt()): by 19:00 on Tue 22 Dec.
            'closes_at' => '2026-12-24 19:00:00',
            'postal_closes_at' => null,
            'draw_at' => '2026-12-24 19:30:00',
            'postal_entry_enabled' => true,
            'event_sales_enabled' => true,
        ]);

        Winner::firstOrCreate(['display_name' => 'Mark Robinson', 'prize' => 'Alpina D3 Coupé'], [
            'image' => '/images/winner-mark-robinson.jpg', 'verification' => 'approved', 'handover' => 'delivered', 'show_on_site' => true,
        ]);
    }

    /** The free entry address, unless an admin has already set a real one in Settings (placeholders are replaced). */
    private function seedSiteSettings(): void
    {
        $site = Setting::get('site', []) ?? [];
        $address = implode(' ', (array) ($site['postalEntryAddress'] ?? []));
        if ($address === '' || str_contains($address, '[')) {
            Setting::put('site', array_merge($site, ['postalEntryAddress' => SettingsPayload::DEFAULTS['postalEntryAddress']]));
        }
    }

    /**
     * Credentials come from config (not env()) so seeding still works after config:cache.
     * An existing admin's password is only replaced when SEED_ADMIN_PASSWORD is set.
     */
    private function seedAdmin(): void
    {
        $email = config('raffle.seed_admin.email');
        if (! $email) {
            $this->command?->warn('SEED_ADMIN_EMAIL is not set; skipping admin account.');

            return;
        }

        $configuredPassword = config('raffle.seed_admin.password');
        $attributes = [
            'name' => 'WDS Admin', 'first_name' => 'WDS', 'last_name' => 'Admin',
            'role' => 'admin', 'date_of_birth' => '1990-01-01',
        ];

        $generatedPassword = null;
        if ($configuredPassword) {
            $attributes['password'] = $configuredPassword;
        } elseif (! User::where('email', $email)->exists()) {
            $generatedPassword = Str::password(16);
            $attributes['password'] = $generatedPassword;
        }

        User::updateOrCreate(['email' => $email], $attributes);

        if ($generatedPassword) {
            $this->command?->warn("Admin password: {$generatedPassword}  (change it after first sign-in)");
        }
    }
}
