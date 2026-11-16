<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The site footer's config (config/wds.php) reaches the website through /api/settings, and Admin can't change it. */
class FooterConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_socials_no_ico_line_and_powered_by_on(): void
    {
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.footer.instagram', ['handle' => 'wds_originals', 'url' => 'https://instagram.com/wds_originals'])
            ->assertJsonPath('data.footer.youtube', ['name' => 'WDS Originals', 'url' => 'https://youtube.com/@wdsoriginals'])
            ->assertJsonPath('data.footer.icoNumber', null)
            ->assertJsonPath('data.footer.poweredBy', true);
    }

    public function test_the_ico_number_and_powered_by_come_from_config_only(): void
    {
        Setting::put('site', ['footer' => ['icoNumber' => 'FROM-ADMIN', 'poweredBy' => true]]);
        config(['wds.ico_number' => 'ZB123456', 'wds.powered_by' => false]);

        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.footer.icoNumber', 'ZB123456')
            ->assertJsonPath('data.footer.poweredBy', false);
    }
}
