<?php

namespace Tests\Feature;

use App\Exceptions\EntryException;
use App\Jobs\SendLaunchAlert;
use App\Models\Competition;
use App\Models\CompetitionEvent;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\PreviewLink;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\PaymentResult;
use App\Services\TicketAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Coming soon: published on Verity with the terms locked, visible, never enterable until opens_at. Every way
 * in refuses it (not just the website's button), competitions:open-due opens it at its time, and "Open now"
 * opens it early with a confirmation. None of this depends on staging data.
 */
class ComingSoonTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private const OPENS = '2026-12-01 09:00:00';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(array $overrides = []): Competition
    {
        return Competition::create(array_merge([
            'slug' => 'soon-'.substr(md5((string) microtime(true)), 0, 6), 'title' => 'Soon car', 'category' => 'cars', 'status' => 'draft',
            'images' => [['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Car']],
            'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000]], 'total_tickets' => 750, 'max_per_person' => null,
            'opens_at' => self::OPENS, 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00',
            'draw_method' => Competition::DRAW_PHYSICAL, 'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ], $overrides));
    }

    private function comingSoon(array $overrides = []): Competition
    {
        return app(VerityDrawEngine::class)->publish($this->draft($overrides));
    }

    private function checkout(Competition $c)
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => 'ann@example.com', 'name' => 'Ann A', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
    }

    public function test_publishing_with_a_future_opening_time_makes_it_coming_soon_with_the_terms_locked(): void
    {
        $c = $this->comingSoon();

        $this->assertSame('coming_soon', $c->status);
        $this->assertNotNull($c->verity_ref);
        $vc = VerityCompetition::where('ref', $c->verity_ref)->firstOrFail();
        $this->assertSame('draft', $vc->status, 'a Verity draft: terms locked, not open');
        $this->assertFalse($vc->isOpenForEntries());
        $this->assertSame(750, (int) $vc->max_tickets);
        $this->assertSame(1495, (int) $vc->ticket_price_pence);
        $this->assertTrue(Carbon::parse('2026-12-24 19:00:00')->equalTo($vc->closes_at));
        $this->assertNotNull($c->notOpenYetReason());
    }

    public function test_publishing_without_a_future_opening_time_needs_an_explicit_open_now_which_is_recorded(): void
    {
        foreach ([null, '2026-11-11 20:41:00'] as $opens) { // empty, or the BMW's old seed time (in the past)
            $c = $this->draft(['opens_at' => $opens]);
            $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/publish")->assertStatus(422)
                ->assertJsonPath('message', fn ($m) => str_contains($m, 'Open for entries immediately'));
            $this->assertNull($c->fresh()->verity_ref, 'nothing published');
        }

        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/publish", ['openNow' => true])->assertOk()
            ->assertJsonPath('data.status', 'live');
        $this->assertTrue($c->fresh()->isOpen());
        $event = CompetitionEvent::where('competition_id', $c->id)->sole();
        $this->assertSame('published_open_now', $event->action);
        $this->assertSame($this->admin->id, $event->user_id);
        $this->assertTrue($event->details['confirmed_open_now']);
        $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/history")->assertOk()
            ->assertJsonPath('data.0.by', $this->admin->email);
    }

    public function test_coming_soon_is_public_with_its_terms_and_a_draft_is_not(): void
    {
        $soon = $this->comingSoon(['slug' => 'soon']);
        $this->draft(['slug' => 'secret']);

        $list = $this->getJson('/api/competitions')->assertOk()->json('data');
        $this->assertSame(['soon'], array_column($list, 'slug'));
        $this->getJson('/api/competitions/soon')->assertOk()
            ->assertJsonPath('data.status', 'coming_soon')
            ->assertJsonPath('data.totalTickets', 750)
            ->assertJsonPath('data.ticketPrice', 14.95)
            ->assertJsonPath('data.opensAt', Carbon::parse(self::OPENS)->toIso8601String())
            ->assertJsonPath('data.notOpenYet', "Soon car isn't open yet: entries open Tuesday 1 December 2026, 9:00am (UK time).");
        $this->getJson('/api/competitions/secret')->assertNotFound();
        // The draft keeps only its teaser; a coming-soon competition has its full page instead.
        $this->getJson('/api/upcoming/secret')->assertOk();
        $this->getJson('/api/upcoming/soon')->assertNotFound();
        $this->assertNotNull($soon->fresh()->verity_ref);
    }

    public function test_every_way_in_refuses_a_coming_soon_competition(): void
    {
        $c = $this->comingSoon();
        $free = $this->comingSoon(['slug' => 'free-soon', 'ticket_price_pence' => 0, 'bundles' => [], 'total_tickets' => 500, 'max_per_person' => 1, 'postal_entry_enabled' => false]);
        $notOpen = "isn't open yet: entries open Tuesday 1 December 2026, 9:00am (UK time).";

        // The basket's price/availability check.
        $this->postJson('/api/basket/quote', ['items' => [['competitionId' => $c->id, 'qty' => 3]]])->assertOk()
            ->assertJsonPath('data.0.open', false)->assertJsonPath('data.0.state', 'not_open_yet')
            ->assertJsonPath('data.0.message', fn ($m) => str_contains($m, $notOpen));
        // Checkout session (online).
        $this->checkout($c)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, $notOpen));
        // Event sales.
        $this->actingAs($this->admin)->getJson('/api/admin/event-sales/competitions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->postJson('/api/admin/event-sales', [
            'competitionId' => $c->id, 'qty' => 1, 'name' => 'Pat Jones', 'email' => 'pat@example.com', 'dateOfBirth' => '1990-01-01', 'paymentMethod' => 'cash',
        ])->assertStatus(422);
        // Postal entry logging: the envelope is logged but HELD, no ticket (PostalBeforeOpeningTest covers it).
        $this->actingAs($this->admin)->postJson('/api/admin/postal', [
            'competitionId' => $c->id, 'name' => 'Sam Lee', 'address' => '2 High St', 'dateOfBirth' => '1990-01-01',
            'email' => 'sam@example.com', 'receivedOn' => '2026-11-20T09:00:00Z',
        ])->assertCreated()->assertJsonPath('status', 'held');
        // Free entry (signed-in, verified account).
        $user = User::factory()->create(['role' => 'customer', 'date_of_birth' => '1990-01-01', 'email_verified_at' => now()]);
        $this->actingAs($user)->postJson("/api/competitions/{$free->id}/free-entry", ['ageConfirmed' => true, 'termsAccepted' => true])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, $notOpen));
        // TicketAllocator itself (every channel ends up here).
        try {
            app(TicketAllocator::class)->allocate($c, 1, ['channel' => 'online', 'email' => 'x@example.com', 'name' => 'X']);
            $this->fail('allocated before opening');
        } catch (EntryException $e) {
            $this->assertStringContainsString($notOpen, $e->getMessage());
        }

        $this->assertSame(0, $c->tickets()->count() + $free->tickets()->count());
        $this->assertSame(0, (int) VerityCompetition::where('ref', $c->verity_ref)->value('tickets_sold'));
    }

    public function test_a_payment_notification_or_reconcile_for_a_coming_soon_competition_issues_nothing_and_refunds(): void
    {
        $c = $this->comingSoon();
        // An order that should never exist (checkout refuses it), as if made some other way: the payment
        // notification (and reconcile, which calls the same handler) must still refuse it.
        $order = Order::create([
            'reference' => 'WDS-SOON01', 'access_key' => str_repeat('k', 40), 'email' => 'ann@example.com', 'name' => 'Ann A',
            'date_of_birth' => '1990-01-01', 'channel' => 'online', 'status' => 'pending', 'subtotal_pence' => 1495,
            'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => 1495,
            'payment_provider' => 'fake', 'entrant_id' => Entrant::forEmail('ann@example.com')->id, 'reserved_until' => now()->addMinutes(15),
        ]);
        $order->lines()->create(['competition_id' => $c->id, 'qty' => 1, 'unit_price_pence' => 1495, 'line_total_pence' => 1495]);

        app(CheckoutService::class)->handlePaymentResult(new PaymentResult('WDS-SOON01', PaymentResult::PAID, 'p-1', 1495, 'card'));

        $order->refresh();
        $this->assertContains($order->status, ['refunded', 'cancelled']);
        $this->assertNotNull($order->paid_at, 'money taken is recorded, so it shows as refunded / refund needed');
        $this->assertStringContainsString("isn't open for entries yet", (string) $order->status_note);
        $this->assertSame(0, $c->tickets()->count());
    }

    public function test_open_due_opens_it_at_its_time_and_queues_the_launch_email_once(): void
    {
        $c = $this->comingSoon();
        app(\App\Services\LaunchAlerts::class)->subscribe('fan@example.com', null, 'home');

        $this->artisan('competitions:open-due')->assertSuccessful();
        $this->assertSame('coming_soon', $c->fresh()->status, 'not before its time');

        Carbon::setTestNow('2026-12-01 09:00:30');
        $this->artisan('competitions:open-due')->expectsOutputToContain('Opened Soon car')->assertSuccessful();
        $c->refresh();
        $this->assertSame('live', $c->status);
        $this->assertTrue($c->isOpen());
        $this->assertSame('live', VerityCompetition::where('ref', $c->verity_ref)->value('status'));
        $this->assertSame(['opened', 'launch_alerts'], CompetitionEvent::where('competition_id', $c->id)->orderBy('id')->pluck('action')->all());
        Queue::assertPushed(SendLaunchAlert::class, 1);

        // Running again opens nothing and sends nothing more.
        $this->artisan('competitions:open-due')->assertSuccessful();
        Queue::assertPushed(SendLaunchAlert::class, 1);
        $this->checkout($c)->assertOk();
    }

    public function test_open_now_needs_confirmation_and_opens_early(): void
    {
        $c = $this->comingSoon();

        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/open")->assertStatus(422);
        $this->assertSame('coming_soon', $c->fresh()->status);

        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/open", ['confirm' => true])->assertOk()
            ->assertJsonPath('data.status', 'live');
        $c->refresh();
        $this->assertTrue($c->isOpen());
        $this->assertTrue($c->opens_at->equalTo(now()), 'the real opening time');
        $this->assertStringContainsString('was due to open Tuesday 1 December 2026, 9:00am', CompetitionEvent::where('action', 'opened_now')->value('summary'));
        // A live competition can't be opened again.
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/open", ['confirm' => true])->assertStatus(422);
    }

    public function test_a_coming_soon_competition_can_be_previewed_as_live_until_it_opens(): void
    {
        $c = $this->comingSoon(['slug' => 'soon']);
        $token = PreviewLink::issue($c, $this->admin)->token();

        $this->withHeader('X-Preview-Token', $token)->getJson('/api/competitions/soon')->assertOk()
            ->assertJsonPath('data.preview', true)->assertJsonPath('data.status', 'live')->assertJsonPath('data.notOpenYet', null);
        // Still not enterable through the preview.
        $this->withHeader('X-Preview-Token', $token)->postJson('/api/checkout', [
            'customer' => ['email' => 'ann@example.com', 'name' => 'Ann A', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertStatus(422);

        app(\App\Services\CompetitionLauncher::class)->open($c, $this->admin);
        $this->withHeader('X-Preview-Token', $token)->getJson('/api/competitions/soon')->assertOk()->assertJsonPath('data.preview', false);
    }

    public function test_the_three_client_drafts_get_the_launch_opening_time(): void
    {
        $this->artisan('wds:client-drafts --apply')->assertSuccessful();

        foreach ([\App\Support\ClientDrafts::MAIN_EVENT, \App\Support\ClientDrafts::COILOVERS, \App\Support\ClientDrafts::ENGINE_TABLE] as $slug) {
            $c = Competition::where('slug', $slug)->first();
            if (! $c) {
                continue; // the BMW is created by the seeder, not this command
            }
            $this->assertTrue(Carbon::parse(\App\Support\ClientDrafts::OPENS_AT)->equalTo($c->opens_at), $slug);
            $this->assertSame('draft', $c->status);
        }
        $this->assertSame(2, Competition::whereNotNull('opens_at')->count());
    }
}
