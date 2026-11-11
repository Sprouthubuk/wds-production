<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PostalCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function competition(array $overrides = []): Competition
    {
        return Competition::create(array_merge([
            'slug' => 'test', 'title' => 'Test car', 'category' => 'cars', 'status' => 'live',
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 10,
            'closes_at' => '2026-12-24 18:00:00', 'draw_at' => '2026-12-24 20:00:00', 'postal_entry_enabled' => true,
        ], $overrides));
    }

    private function postalEntry(Competition $c, string $receivedOn, string $email = 'alex@example.com')
    {
        return app(PostalEntryService::class)->log($c, [
            'name' => 'Alex Smith', 'address' => '1 High St', 'date_of_birth' => '1990-05-05',
            'email' => $email, 'received_on' => $receivedOn,
        ], User::factory()->create(['role' => 'staff']));
    }

    public function test_postal_close_defaults_to_three_days_before_closing(): void
    {
        $c = $this->competition();

        $this->assertNull($c->postal_closes_at);
        $this->assertSame('2026-12-21 18:00:00', $c->postalClosesAt()->toDateTimeString());
    }

    public function test_an_explicit_postal_close_wins(): void
    {
        $c = $this->competition(['postal_closes_at' => '2026-12-20 09:00:00']);

        $this->assertSame('2026-12-20 09:00:00', $c->postalClosesAt()->toDateTimeString());
    }

    public function test_envelopes_received_after_the_postal_close_are_rejected(): void
    {
        $c = $this->competition();

        $onTheDay = $this->postalEntry($c, '2026-12-21', 'a@example.com');
        $this->assertSame('allocated', $onTheDay->status);

        $late = $this->postalEntry($c, '2026-12-22', 'b@example.com');
        $this->assertSame('rejected', $late->status);
        $this->assertSame('Received after the postal closing date', $late->rejection_reason);
        $this->assertNull($late->ticket);
    }

    public function test_the_api_shows_the_postal_close(): void
    {
        $c = $this->competition();

        $this->getJson("/api/competitions/{$c->slug}")
            ->assertOk()
            ->assertJsonPath('data.postalClosesAt', Carbon::parse('2026-12-21 18:00:00')->toIso8601String());
    }

    public function test_admin_can_set_a_postal_close_no_later_than_the_close(): void
    {
        $c = $this->competition();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson("/api/admin/competitions/{$c->id}", ['closesAt' => '2026-12-24 18:00:00', 'postalClosesAt' => '2026-12-25 09:00:00'])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$c->id}", ['closesAt' => '2026-12-24 18:00:00', 'postalClosesAt' => '2026-12-19 09:00:00'])
            ->assertOk()
            ->assertJsonPath('data.postalClosesAt', Carbon::parse('2026-12-19 09:00:00')->toIso8601String());
    }
}
