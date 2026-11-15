<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** The postal logging form lists every open, published, paid competition with postal entry on, event sales or not. */
class PostalCompetitionListTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_open_paid_competition_with_postal_entry_is_listed(): void
    {
        $this->fakeVerity();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $c = fn (string $slug, array $o = []) => $this->publishedCompetition(['slug' => $slug, 'title' => $slug] + $o);

        $c('no-event-sales', ['event_sales_enabled' => false]);
        $c('with-event-sales', ['event_sales_enabled' => true]);
        $c('postal-off', ['postal_entry_enabled' => false]);
        $c('free', ['ticket_price_pence' => 0, 'bundles' => [], 'max_per_person' => 1]);
        $closed = $c('closed', ['closes_at' => '2026-10-21 20:00:00', 'draw_at' => '2026-10-21 20:30:00']);
        Competition::create(['slug' => 'draft', 'title' => 'draft', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 100, 'bundles' => [],
            'total_tickets' => 10, 'closes_at' => '2026-11-20 20:00:00', 'postal_entry_enabled' => true]);

        Carbon::setTestNow('2026-10-22 12:00:00');
        $this->artisan('verity:process-due')->assertSuccessful();
        $this->assertNotSame('live', app(\App\Services\Draws\VerityDrawEngine::class)->verityCompetition($closed->fresh())->status);

        $staff = User::factory()->create(['role' => 'staff']);
        $titles = collect($this->actingAs($staff)->getJson('/api/admin/postal/competitions')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['no-event-sales', 'with-event-sales'], $titles);

        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/admin/postal/competitions')->assertForbidden();
    }
}
