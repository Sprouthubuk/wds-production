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
        $this->assertSame('Instagram @wds_originals', SettingsPayload::drawnLiveOn());
    }

    public function test_with_youtube_too_it_says_our_socials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => 'https://youtube.com/@wdscompetitions'])
            ->assertOk()
            ->assertJsonPath('data.drawsYoutubeUrl', 'https://youtube.com/@wdscompetitions')
            ->assertJsonPath('data.drawsInstagram', 'wds_originals');

        $this->assertSame('our socials', SettingsPayload::drawnLiveOn());
        $this->getJson('/api/settings')->assertJsonPath('data.drawsYoutubeUrl', 'https://youtube.com/@wdscompetitions');
    }

    public function test_the_youtube_url_is_optional_and_must_be_https(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => 'javascript:alert(1)'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['drawsYoutubeUrl' => null])->assertOk()->assertJsonPath('data.drawsYoutubeUrl', null);
        $this->assertSame('Instagram @wds_originals', SettingsPayload::drawnLiveOn());
    }
}
