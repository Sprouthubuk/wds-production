<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_alone_is_named(): void
    {
        $this->assertNull(SettingsPayload::get()['drawsYoutubeUrl']);
        $this->assertSame('Instagram @wds_competitions', SettingsPayload::drawnLiveOn());
    }

    public function test_with_youtube_too_it_says_our_socials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => 'https://youtube.com/@wdscompetitions'])
            ->assertOk()
            ->assertJsonPath('data.drawsYoutubeUrl', 'https://youtube.com/@wdscompetitions')
            ->assertJsonPath('data.drawsInstagram', 'wds_competitions');

        $this->assertSame('our socials', SettingsPayload::drawnLiveOn());
        $this->getJson('/api/settings')->assertJsonPath('data.drawsYoutubeUrl', 'https://youtube.com/@wdscompetitions');
    }

    public function test_the_youtube_url_is_optional_and_must_be_https(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => 'javascript:alert(1)'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => null])->assertOk()->assertJsonPath('data.drawsYoutubeUrl', null);
        $this->assertSame('Instagram @wds_competitions', SettingsPayload::drawnLiveOn());
    }

    public function test_a_saved_setting_with_the_old_handle_is_moved_to_wds_competitions_and_nothing_else_changes(): void
    {
        \App\Models\Setting::put('site', [
            'drawsInstagram' => '@wds_originals', 'drawsYoutubeUrl' => 'https://youtube.com/@wdscompetitions',
            'socials' => [['label' => 'Instagram @wds_originals', 'url' => 'https://instagram.com/wds_originals'], ['label' => 'TikTok', 'url' => 'https://tiktok.com/@wds']],
        ]);
        (require database_path('migrations/2026_11_21_000002_instagram_handle_wds_competitions.php'))->up();

        $site = SettingsPayload::get();
        $this->assertSame('wds_competitions', $site['drawsInstagram']);
        $this->assertSame([['label' => 'Instagram @wds_competitions', 'url' => 'https://www.instagram.com/wds_competitions/'], ['label' => 'TikTok', 'url' => 'https://tiktok.com/@wds']], $site['socials']);
        $this->assertSame('https://youtube.com/@wdscompetitions', $site['drawsYoutubeUrl']);
        $this->assertStringNotContainsString('wds_originals', json_encode($site));

        // A handle an admin chose themselves is left alone.
        \App\Models\Setting::put('site', ['drawsInstagram' => 'someone_else']);
        (require database_path('migrations/2026_11_21_000002_instagram_handle_wds_competitions.php'))->up();
        $this->assertSame('someone_else', SettingsPayload::get()['drawsInstagram']);
    }
}
