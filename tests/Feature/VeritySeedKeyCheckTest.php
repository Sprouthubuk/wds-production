<?php

namespace Tests\Feature;

use App\Console\Commands\VerityCheck;
use App\Models\Competition;
use App\Support\VerityKeyChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * verity:check catches a malformed VERITY_SEED_KEY (on staging it was missing its "base64:" prefix: the
 * check passed, then publishing crashed with "Unsupported cipher or incorrect key length").
 */
class VeritySeedKeyCheckTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function keys(): array
    {
        $raw = random_bytes(32);

        return [
            'good' => 'base64:'.base64_encode($raw),
            'no_prefix' => base64_encode($raw),
            'short' => 'base64:'.base64_encode(random_bytes(16)),
            'not_base64' => 'base64:%%%not-base64%%%',
            'plain_text' => 'my-secret-seed-key',
        ];
    }

    private function row(): array
    {
        return VerityKeyChecks::run()[0];
    }

    public function test_the_key_format_row(): void
    {
        $k = $this->keys();
        config(['verity.seed_key' => $k['good']]);
        $this->assertTrue($this->row()['ok']);

        config(['verity.seed_key' => $k['no_prefix']]);
        $this->assertFalse($this->row()['ok']);
        $this->assertStringContainsString('missing the "base64:" prefix', $this->row()['note']);
        $this->assertStringNotContainsString($k['no_prefix'], $this->row()['note'], 'The key itself is never shown');

        config(['verity.seed_key' => $k['short']]);
        $this->assertStringContainsString('decodes to 16 bytes; AES-256-CBC needs 32', $this->row()['note']);
        config(['verity.seed_key' => $k['not_base64']]);
        $this->assertStringContainsString("isn't valid base64", $this->row()['note']);
        config(['verity.seed_key' => $k['plain_text']]);
        $this->assertFalse($this->row()['ok']);

        // Not set: Verity's own seed_key row FAILs for that; this row has nothing to check.
        config(['verity.seed_key' => null]);
        $this->assertTrue($this->row()['ok']);
    }

    public function test_verity_check_strict_fails_on_a_bad_key_and_passes_its_row_on_a_good_one(): void
    {
        $this->assertInstanceOf(VerityCheck::class, Artisan::all()['verity:check']);
        $k = $this->keys();

        config(['verity.seed_key' => $k['no_prefix']]);
        $this->artisan('verity:check --strict')->expectsOutputToContain('VERITY_SEED_KEY is a valid key')->assertFailed();
        Artisan::call('verity:check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertFalse($json['ok']);
        $row = collect($json['checks'])->firstWhere('key', 'seed_key_format');
        $this->assertFalse($row['ok']);
        $this->assertNotNull(collect($json['checks'])->firstWhere('key', 'beacon'), "Verity's own rows still run");

        config(['verity.seed_key' => $k['good']]);
        Artisan::call('verity:check', ['--json' => true]);
        $this->assertTrue(collect(json_decode(Artisan::output(), true)['checks'])->firstWhere('key', 'seed_key_format')['ok']);
    }

    public function test_publish_is_refused_with_a_clear_message_instead_of_crashing(): void
    {
        $this->fakeVerity();
        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['verity.seed_key' => $this->keys()['no_prefix']]);
        $c = Competition::create(['slug' => 'k', 'title' => 'K', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 100, 'bundles' => [],
            'total_tickets' => 10, 'closes_at' => '2026-10-30 20:00:00', 'draw_at' => '2026-10-30 20:30:00', 'images' => [['src' => '/images/p.jpg', 'alt' => 'P']]]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/publish")->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Publishing is blocked') && str_contains($m, 'base64:'));
        $this->assertFalse($c->fresh()->isPublished());

        config(['verity.seed_key' => $this->keys()['good']]);
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/publish")->assertOk();
    }
}
