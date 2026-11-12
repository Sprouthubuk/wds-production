<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\PreviewLink;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreviewLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        // Nothing in this test may reach the draw engine: a draft never calls Verity.
        $this->mock(VerityDrawEngine::class, function ($m) {
            $m->shouldNotReceive('addEntries');
            $m->shouldNotReceive('addPostalEntry');
            $m->shouldNotReceive('publish');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(array $overrides = []): Competition
    {
        return Competition::create(array_merge([
            'slug' => 'bmw-e46', 'title' => 'BMW E46', 'category' => 'cars', 'status' => 'draft',
            'images' => [['src' => '/images/e46.jpg', 'alt' => '']], 'ticket_price_pence' => 1495, 'bundles' => [],
            'total_tickets' => 750, 'max_per_person' => 50, 'closes_at' => now()->addMonth(), 'draw_at' => now()->addMonth()->addHour(),
            'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ], $overrides));
    }

    private function link(Competition $c): string
    {
        $url = $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/preview-link")
            ->assertCreated()->json('data.url');
        $this->assertMatchesRegularExpression('#/preview/[A-Za-z0-9]{48}$#', $url);

        return basename($url);
    }

    public function test_admin_creates_shows_regenerates_and_revokes_a_14_day_link(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $c = $this->draft();
        $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/preview-link")->assertOk()->assertJsonPath('data', null);

        $first = $this->link($c);
        $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/preview-link")
            ->assertJsonPath('data.url', rtrim(config('raffle.frontend_url'), '/').'/preview/'.$first)
            ->assertJsonPath('data.expiresAt', Carbon::parse('2026-11-26 10:00:00')->toIso8601String());
        $this->getJson("/api/preview/{$first}")->assertOk()->assertJsonPath('data.slug', 'bmw-e46')->assertHeader('Cache-Control', 'no-store, private');

        $second = $this->link($c);
        $this->assertNotSame($first, $second);
        $this->getJson("/api/preview/{$first}")->assertNotFound();   // regenerating revokes the old link
        $this->getJson("/api/preview/{$second}")->assertOk();

        $this->actingAs($this->admin)->deleteJson("/api/admin/competitions/{$c->id}/preview-link")->assertOk();
        $this->getJson("/api/preview/{$second}")->assertNotFound();
        $this->assertNull(PreviewLink::current($c));
    }

    public function test_the_link_expires_after_14_days(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $token = $this->link($this->draft());

        Carbon::setTestNow('2026-11-26 09:59:00');
        $this->getJson("/api/preview/{$token}")->assertOk();
        Carbon::setTestNow('2026-11-26 10:00:01');
        $this->getJson("/api/preview/{$token}")->assertNotFound();
    }

    public function test_only_admins_manage_links_and_only_for_drafts(): void
    {
        $c = $this->draft();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->postJson("/api/admin/competitions/{$c->id}/preview-link")->assertForbidden();

        $live = $this->draft(['slug' => 'live', 'status' => 'live', 'verity_ref' => 'vc_test']);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$live->id}/preview-link")->assertStatus(422);
    }

    public function test_with_the_token_the_draft_reads_as_live_and_is_flagged_preview(): void
    {
        $c = $this->draft();
        $token = $this->link($c);

        $this->getJson('/api/competitions')->assertJsonCount(0, 'data');
        $this->getJson('/api/competitions/bmw-e46')->assertNotFound();
        $this->getJson('/api/competitions/bmw-e46', ['X-Preview-Token' => 'nope'])->assertNotFound();

        $this->getJson('/api/competitions', ['X-Preview-Token' => $token])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'live')->assertJsonPath('data.0.preview', true)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex');
        $this->getJson('/api/competitions/bmw-e46', ['X-Preview-Token' => $token])
            ->assertOk()->assertJsonPath('data.preview', true)->assertJsonPath('data.ticketPrice', 14.95);

        $this->assertSame('draft', $c->fresh()->status); // nothing was saved
    }

    public function test_every_entry_route_rejects_the_draft_even_with_the_preview_token(): void
    {
        $c = $this->draft();
        $headers = ['X-Preview-Token' => $this->link($c)];
        $customer = ['email' => 'alex@example.com', 'name' => 'Alex Smith', 'phone' => '07000000000', 'dateOfBirth' => '1990-05-05'];

        // Basket → checkout (online)
        $this->postJson('/api/checkout', [
            'customer' => $customer, 'items' => [['competitionId' => $c->id, 'qty' => 2]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ], $headers)->assertStatus(422)->assertJsonPath('message', "BMW E46 isn't open for entries yet.");

        $staff = User::factory()->create(['role' => 'staff']);
        // Event sales
        $this->actingAs($staff)->postJson('/api/admin/event-sales', [
            'competitionId' => $c->id, 'qty' => 1, 'name' => 'Alex Smith', 'email' => 'alex@example.com',
            'dateOfBirth' => '1990-05-05', 'paymentMethod' => 'cash',
        ], $headers)->assertStatus(422);
        // Postal
        $this->actingAs($staff)->postJson('/api/admin/postal', [
            'competitionId' => $c->id, 'name' => 'Alex Smith', 'address' => '1 High St', 'dateOfBirth' => '1990-05-05',
            'email' => 'alex@example.com', 'receivedOn' => now()->toDateTimeString(),
        ], $headers)->assertStatus(422)->assertJsonPath('message', "BMW E46 isn't open for entries yet.");

        $this->assertSame(0, Order::count());
        $this->assertSame(0, PostalEntry::count());
        $this->assertSame(0, $c->fresh()->tickets_allocated);
    }

    public function test_a_published_competition_ends_its_preview(): void
    {
        $c = $this->draft();
        $token = $this->link($c);
        $c->forceFill(['verity_ref' => 'vc_test', 'status' => 'live'])->save();

        $this->getJson("/api/preview/{$token}")->assertNotFound();
    }
}
