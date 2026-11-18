<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → Settings → Top announcement bar: empty by default (no bar), saved trimmed, at most 140 characters. */
class AnnouncementBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_empty_by_default_and_saved_trimmed_by_an_admin(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.announcementBar', '');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['announcementBar' => '  Multi-buy bundles available · Low ticket numbers, better odds  '])->assertOk();
        $this->getJson('/api/settings')->assertJsonPath('data.announcementBar', 'Multi-buy bundles available · Low ticket numbers, better odds');

        $this->actingAs($admin)->putJson('/api/admin/settings', ['announcementBar' => null])->assertOk();
        $this->getJson('/api/settings')->assertJsonPath('data.announcementBar', '');

        $this->actingAs($admin)->putJson('/api/admin/settings', ['announcementBar' => str_repeat('x', 141)])->assertStatus(422)->assertJsonValidationErrors('announcementBar');
    }

    public function test_only_admins_can_set_it(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->putJson('/api/admin/settings', ['announcementBar' => 'Hi'])->assertForbidden();
    }
}
