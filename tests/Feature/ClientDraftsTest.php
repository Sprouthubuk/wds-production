<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use App\Exceptions\EntryException;
use App\Support\ClientDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** wds:client-drafts, and drafts saved before their dates are confirmed. */
class ClientDraftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dry_run_writes_nothing(): void
    {
        $bmw = $this->mainEvent();
        $this->artisan('wds:client-drafts')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(1000, $bmw->fresh()->ticket_price_pence);
        $this->assertFalse(Competition::where('slug', 'bc-racing-br-series-coilovers')->exists());
    }

    public function test_apply_sets_the_main_event_and_creates_the_new_drafts_once(): void
    {
        $bmw = $this->mainEvent();
        $this->artisan('wds:client-drafts --apply')->assertSuccessful();

        $bmw->refresh();
        $this->assertSame([1495, 750, 500000], [$bmw->ticket_price_pence, $bmw->total_tickets, $bmw->cash_alternative_pence]);
        $this->assertSame('2026-12-24 19:00', $bmw->closes_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-12-24 19:30', $bmw->draw_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-12-22 19:00', $bmw->postalClosesAt()->utc()->format('Y-m-d H:i'));   // the 48-hour rule
        $this->assertSame('draft', $bmw->status);

        $bc = Competition::where('slug', 'bc-racing-br-series-coilovers')->firstOrFail();
        $this->assertSame(['draft', 500, 495, 50000], [$bc->status, $bc->ticket_price_pence, $bc->total_tickets, $bc->cash_alternative_pence]);
        $this->assertSame([['qty' => 5, 'price_pence' => 2000]], $bc->bundles);
        $this->assertStringContainsString('cash alternative instead', implode(' ', $bc->description));

        // All three on Christmas Eve: close 19:00, live draws from 19:30; paid postal by 19:00 on 22 Dec.
        foreach ([ClientDrafts::COILOVERS, ClientDrafts::ENGINE_TABLE] as $slug) {
            $c = Competition::where('slug', $slug)->firstOrFail();
            $this->assertSame(['2026-12-24 19:00', '2026-12-24 19:30', 'draft'], [$c->closes_at->utc()->format('Y-m-d H:i'), $c->draw_at->utc()->format('Y-m-d H:i'), $c->status]);
        }
        $this->assertSame('2026-12-22 19:00', $bc->postalClosesAt()->utc()->format('Y-m-d H:i'));
        $table = Competition::where('slug', ClientDrafts::ENGINE_TABLE)->firstOrFail();
        $this->assertSame([0, 500, 1, false, false], [$table->ticket_price_pence, $table->total_tickets, $table->max_per_person, (bool) $table->postal_entry_enabled, $table->hasPostalRoute()]);

        // Run again: nothing to do, and edits made in Admin are kept.
        $bc->update(['title' => 'Edited in Admin']);
        $this->artisan('wds:client-drafts --apply')->expectsOutputToContain('already up to date')->assertSuccessful();
        $this->assertSame('Edited in Admin', $bc->fresh()->title);
        $this->assertSame(1, Competition::where('slug', 'like', 'bc-racing%')->count());
    }

    public function test_a_published_main_event_is_never_changed(): void
    {
        $bmw = $this->mainEvent(['status' => 'live', 'verity_ref' => 'WDS-LOCKED']);
        $this->artisan('wds:client-drafts --apply')->expectsOutputToContain('not changed')->assertSuccessful();
        $this->assertSame(1000, $bmw->fresh()->ticket_price_pence);
    }

    public function test_admin_can_save_a_draft_without_dates_but_not_publish_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($admin)->postJson('/api/admin/competitions', [
            'title' => 'Undated draft', 'category' => 'parts', 'status' => 'draft', 'ticketPrice' => 5, 'totalTickets' => 495, 'maxPerPerson' => 50,
        ])->assertCreated()->assertJsonPath('data.drawAt', null)->json('data.id');

        $this->expectException(EntryException::class);
        app(VerityDrawEngine::class)->publish(Competition::findOrFail($id));
    }

    public function test_publishing_needs_a_draw_time_no_earlier_than_the_close(): void
    {
        $c = $this->mainEvent(['closes_at' => now()->addDays(3), 'draw_at' => null]);
        try {
            app(VerityDrawEngine::class)->publish($c);
            $this->fail('published without a draw time');
        } catch (EntryException $e) {
            $this->assertStringContainsString('live draw time', $e->getMessage());
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$c->id}", ['closesAt' => '2026-12-24 19:00:00', 'drawAt' => '2026-12-24 18:30:00'])
            ->assertStatus(422);
    }

    public function test_an_existing_undated_draft_gets_its_confirmed_dates_but_keeps_its_admin_edits(): void
    {
        Competition::create(['slug' => ClientDrafts::COILOVERS] + array_merge(ClientDrafts::newDrafts()[ClientDrafts::COILOVERS], ['title' => 'Coilovers (edited)']));
        $this->artisan('wds:client-drafts')->expectsOutputToContain('closes_at (empty) → 2026-12-24 19:00 UTC')->assertSuccessful();
        $this->assertNull(Competition::where('slug', ClientDrafts::COILOVERS)->value('closes_at'));

        $this->artisan('wds:client-drafts --apply')->assertSuccessful();
        $c = Competition::where('slug', ClientDrafts::COILOVERS)->firstOrFail();
        $this->assertSame(['Coilovers (edited)', '2026-12-24 19:30'], [$c->title, $c->draw_at->utc()->format('Y-m-d H:i')]);
    }

    public function test_a_published_draft_is_never_changed(): void
    {
        $c = Competition::create(['slug' => ClientDrafts::ENGINE_TABLE, 'status' => 'live', 'verity_ref' => 'WDS-T'] + ClientDrafts::newDrafts()[ClientDrafts::ENGINE_TABLE]);
        $this->artisan('wds:client-drafts --apply')->expectsOutputToContain('not changed')->assertSuccessful();
        $this->assertNull($c->fresh()->closes_at);
    }

    private function mainEvent(array $overrides = []): Competition
    {
        return Competition::create($overrides + [
            'slug' => ClientDrafts::MAIN_EVENT, 'title' => 'BMW E46 330Ci Clubsport', 'category' => 'cars', 'status' => 'draft',
            'ticket_price_pence' => 1000, 'bundles' => [], 'total_tickets' => 500, 'max_per_person' => 50,
            'closes_at' => '2026-12-24 18:00:00', 'draw_at' => '2026-12-24 20:00:00',
        ]);
    }
}
