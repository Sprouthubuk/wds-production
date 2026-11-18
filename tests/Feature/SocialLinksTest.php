<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Settings → Social links: Instagram @wds_originals, YouTube @wds_originals, Instagram @wds_competitions,
 * Facebook. Empty URL = hidden. A data migration fills empty settings once with the defaults.
 */
class SocialLinksTest extends TestCase
{
    use RefreshDatabase;

    private function runSeedMigration(): void
    {
        (require database_path('migrations/2026_11_23_000001_seed_social_links.php'))->up();
    }

    public function test_the_defaults_in_order_with_the_correct_youtube_link_and_facebook_hidden(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.socialLinks', [
            ['network' => 'instagram', 'label' => '@wds_originals', 'url' => 'https://www.instagram.com/wds_originals/'],
            ['network' => 'youtube', 'label' => '@wds_originals', 'url' => 'https://www.youtube.com/@wds_originals'],
            ['network' => 'instagram', 'label' => '@wds_competitions', 'url' => 'https://www.instagram.com/wds_competitions/'],
        ])->assertJsonPath('data.drawsInstagram', 'wds_competitions'); // the live-draw handle is unchanged
    }

    public function test_the_seed_only_fills_empty_settings_and_fixes_the_old_youtube_link(): void
    {
        Setting::put('site', ['socialYoutubeUrl' => 'https://www.youtube.com/@our_own', 'socialFacebookUrl' => '', 'youtubeUrl' => 'https://youtube.com/@wdsoriginals']);
        $this->runSeedMigration();
        $site = Setting::get('site');
        $this->assertSame('https://www.youtube.com/@our_own', $site['socialYoutubeUrl'], 'kept');
        $this->assertSame('https://www.instagram.com/wds_originals/', $site['socialInstagramOriginalsUrl'], 'filled');
        $this->assertSame('', $site['socialFacebookUrl'], 'Facebook stays hidden');
        $this->assertSame('https://www.youtube.com/@wds_originals', $site['youtubeUrl'], 'old wrong link corrected');
    }

    public function test_an_admin_sets_facebook_and_clears_a_link_to_hide_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/settings', [
            'socialFacebookUrl' => ' https://www.facebook.com/wdsoriginals ', 'socialFacebookLabel' => '',
            'socialInstagramCompetitionsUrl' => null,
        ])->assertOk();
        $links = $this->getJson('/api/settings')->json('data.socialLinks');
        $this->assertSame(['instagram', 'youtube', 'facebook'], array_column($links, 'network'));
        $this->assertSame(['label' => 'Facebook', 'url' => 'https://www.facebook.com/wdsoriginals'], array_intersect_key($links[2], ['label' => 1, 'url' => 1]), 'empty label: the network name');

        $this->actingAs($admin)->putJson('/api/admin/settings', ['socialFacebookUrl' => 'not a url'])->assertStatus(422)->assertJsonValidationErrors('socialFacebookUrl');
        $this->actingAs($admin)->putJson('/api/admin/settings', ['socialFacebookUrl' => 'javascript:alert(1)'])->assertStatus(422);
    }

    public function test_only_admins_can_change_them(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->putJson('/api/admin/settings', ['socialFacebookUrl' => 'https://facebook.com/x'])->assertForbidden();
        $this->assertSame('', SettingsPayload::get()['socialFacebookUrl']);
    }
}
