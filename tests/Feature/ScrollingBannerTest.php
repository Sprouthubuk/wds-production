<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Settings → Scrolling banner messages: the home page ticker, one message per line, in order.
 * Empty = no ticker. A data migration seeds the defaults once, only when no messages are set.
 */
class ScrollingBannerTest extends TestCase
{
    use RefreshDatabase;

    private function runSeedMigration(): void
    {
        (require database_path('migrations/2026_11_22_000001_seed_scrolling_banner_messages.php'))->up();
    }

    public function test_the_defaults_are_seeded_and_say_nothing_about_free_post(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.bannerMessages', [
            'Ticket bundles available',
            'Low ticket numbers, better odds',
            'Numbers emailed instantly',
            'Drawn live on Instagram @wds_competitions',
        ]);
        foreach (SettingsPayload::BANNER_DEFAULTS as $m) {
            $this->assertDoesNotMatchRegularExpression('/free|post/i', $m);
        }
        $this->assertSame([], SettingsPayload::DEFAULTS['bannerMessages'], 'without the seed: no ticker');
    }

    public function test_the_seed_only_fills_an_empty_setting(): void
    {
        // Messages an admin saved are kept.
        Setting::put('site', ['tradingName' => 'WDS', 'bannerMessages' => ['Our own message']]);
        $this->runSeedMigration();
        $this->assertSame(['Our own message'], Setting::get('site')['bannerMessages']);
        $this->assertSame('WDS', Setting::get('site')['tradingName']);

        // Cleared (or never set): the defaults.
        Setting::put('site', ['tradingName' => 'WDS', 'bannerMessages' => []]);
        $this->runSeedMigration();
        $this->assertSame(SettingsPayload::BANNER_DEFAULTS, Setting::get('site')['bannerMessages']);

        // Text typed into the old "Top announcement bar" becomes the message; the old key goes.
        Setting::put('site', ['announcementBar' => '  Multi-buy bundles available  ']);
        $this->runSeedMigration();
        $this->assertSame(['Multi-buy bundles available'], Setting::get('site')['bannerMessages']);
        $this->assertArrayNotHasKey('announcementBar', Setting::get('site'));
    }

    public function test_an_admin_saves_messages_in_order_and_can_clear_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['bannerMessages' => ['  Second chance draw  ', '', 'Ticket bundles available']])->assertOk();
        $this->getJson('/api/settings')->assertJsonPath('data.bannerMessages', ['Second chance draw', 'Ticket bundles available'])
            ->assertJsonMissingPath('data.announcementBar');

        $this->actingAs($admin)->putJson('/api/admin/settings', ['bannerMessages' => []])->assertOk();
        $this->getJson('/api/settings')->assertJsonPath('data.bannerMessages', []);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['bannerMessages' => null])->assertOk();
        $this->getJson('/api/settings')->assertJsonPath('data.bannerMessages', []);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['bannerMessages' => [str_repeat('x', 121)]])->assertStatus(422)->assertJsonValidationErrors('bannerMessages.0');
        $this->actingAs($admin)->putJson('/api/admin/settings', ['bannerMessages' => array_fill(0, 13, 'x')])->assertStatus(422)->assertJsonValidationErrors('bannerMessages');
    }

    public function test_only_admins_can_set_them(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->putJson('/api/admin/settings', ['bannerMessages' => ['Hi']])->assertForbidden();
    }
}
