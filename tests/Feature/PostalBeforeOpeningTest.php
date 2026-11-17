<?php

namespace Tests\Feature;

use App\Mail\PostalEntryLogged;
use App\Models\Competition;
use App\Models\CompetitionEvent;
use App\Models\PostalEntry;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Free postal entry before a competition opens. The website says "How to enter by post" on a coming-soon
 * page, so an envelope can arrive before 1 December:
 *  - while coming soon it's logged as HELD (checked, emailed, no ticket, nothing sent to Verity);
 *  - when the competition opens (at its time or by Open now), the same step that opens online entry gives
 *    every held envelope its ticket number, earliest received first, after checking it again;
 *  - an envelope received before opening but only logged after it opened is accepted as normal.
 */
class PostalBeforeOpeningTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comingSoon(array $overrides = []): Competition
    {
        return app(VerityDrawEngine::class)->publish(Competition::create(array_merge([
            'slug' => 'coilovers', 'title' => 'BC Racing coilovers', 'category' => 'parts', 'status' => 'draft',
            'images' => [['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Coilovers']],
            'ticket_price_pence' => 500, 'bundles' => [['qty' => 5, 'price_pence' => 2000]], 'total_tickets' => 495, 'max_per_person' => null,
            'opens_at' => '2026-12-01 09:00:00', 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00',
            'draw_method' => Competition::DRAW_PHYSICAL, 'postal_entry_enabled' => true,
        ], $overrides)));
    }

    private function logPostal(Competition $c, string $email, string $received, string $name = 'Sam Lee')
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/postal', [
            'competitionId' => $c->id, 'name' => $name, 'address' => '2 High St, Camberley', 'dateOfBirth' => '1990-01-01',
            'email' => $email, 'receivedOn' => $received,
        ]);
    }

    private function verityTickets(Competition $c): int
    {
        return (int) VerityCompetition::where('ref', $c->verity_ref)->value('tickets_sold');
    }

    public function test_an_envelope_received_while_coming_soon_is_held_then_numbered_when_it_opens(): void
    {
        $c = $this->comingSoon();

        // Admin can choose it on the postal logging page.
        $this->actingAs($this->admin)->getJson('/api/admin/postal/competitions')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id);

        $this->logPostal($c, 'sam@example.com', '2026-11-20T09:15:00Z')->assertCreated()
            ->assertJsonPath('status', 'held')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'held') && str_contains($m, 'Tuesday 1 December 2026, 9:00am'));

        $entry = PostalEntry::sole();
        $this->assertSame('held', $entry->status);
        $this->assertSame('2026-11-20 09:15:00', $entry->received_at->format('Y-m-d H:i:s'), 'the received time is recorded as normal');
        $this->assertNull($entry->ticket, 'no ticket number yet');
        $this->assertSame(0, $c->tickets()->count());
        $this->assertSame(0, $this->verityTickets($c), 'nothing sent to Verity before it opens');

        // The entrant is told it's received and counted when entries open.
        Mail::assertQueued(PostalEntryLogged::class, function (PostalEntryLogged $m) use ($entry) {
            $text = $m->render();

            return $m->entry->is($entry) && $m->hasTo('sam@example.com')
                && $m->envelope()->subject === "We've received your postal entry: BC Racing coilovers"
                && str_contains($text, 'Entries open <strong>Tuesday 1 December 2026, 9:00am</strong> (UK time)')
                && str_contains($text, "we'll email you your ticket number");
        });

        // Not before its time.
        $this->artisan('competitions:open-due')->assertSuccessful();
        $this->assertSame('held', $entry->fresh()->status);

        // At the opening time: online entry opens and the held envelope gets its number in the same step.
        Carbon::setTestNow('2026-12-01 09:00:20');
        $this->artisan('competitions:open-due')->assertSuccessful();
        $this->assertSame('live', $c->fresh()->status);
        $entry->refresh();
        $this->assertSame('allocated', $entry->status);
        $this->assertSame(1, $entry->ticket->number, 'first in the sequence');
        $this->assertSame(1, $this->verityTickets($c), 'in the Verity draw');
        Mail::assertQueued(PostalEntryLogged::class, fn (PostalEntryLogged $m) => $m->entry->is($entry) && $m->entry->status === 'allocated'
            && $m->envelope()->subject === 'Your free postal entry is in: BC Racing coilovers');
        $this->assertStringContainsString('1 postal entry received before opening added to the draw',
            CompetitionEvent::where('competition_id', $c->id)->where('action', 'postal_held_added')->value('summary'));

        // Running again doesn't number it twice.
        $this->artisan('competitions:open-due')->assertSuccessful();
        $this->assertSame(1, $c->tickets()->count());
        $this->assertSame(1, $this->verityTickets($c));
    }

    public function test_open_now_numbers_held_entries_in_the_order_received_and_checks_them_again(): void
    {
        $c = $this->comingSoon(['max_per_person' => 1]);
        $blocked = User::factory()->create(['email' => 'blocked@example.com', 'role' => 'customer']);

        // Logged out of order: the later envelope is typed in first.
        $this->logPostal($c, 'late@example.com', '2026-11-19T15:00:00Z', 'Lou Late')->assertJsonPath('status', 'held');
        $this->logPostal($c, 'early@example.com', '2026-11-18T10:00:00Z', 'Eve Early')->assertJsonPath('status', 'held');
        $this->logPostal($c, 'blocked@example.com', '2026-11-18T11:00:00Z', 'Bo Blocked')->assertJsonPath('status', 'held');
        // A second envelope from the same person, with max 1 per person.
        $this->logPostal($c, 'early@example.com', '2026-11-19T16:00:00Z', 'Eve Early')->assertJsonPath('status', 'held');
        // Rules that apply at logging still apply: an under-18 is rejected at once, not held.
        $this->actingAs($this->admin)->postJson('/api/admin/postal', [
            'competitionId' => $c->id, 'name' => 'Kid', 'address' => '1 Road', 'dateOfBirth' => now()->subYears(16)->toDateString(),
            'email' => 'kid@example.com', 'receivedOn' => '2026-11-19T10:00:00Z',
        ])->assertJsonPath('status', 'rejected');

        // Between logging and opening, one account self-excludes.
        $blocked->update(['self_excluded_until' => now()->addMonths(6)]);

        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/open", ['confirm' => true])->assertOk();

        $byEmail = fn (string $email) => PostalEntry::where('email', $email)->orderBy('received_at')->get();
        [$eve1, $eve2] = $byEmail('early@example.com')->all();
        $lou = $byEmail('late@example.com')->first();
        $bo = $byEmail('blocked@example.com')->first();

        $this->assertSame(['allocated', 1], [$eve1->status, $eve1->ticket->number], 'earliest received gets the first number');
        $this->assertSame(['allocated', 2], [$lou->status, $lou->ticket->number]);
        $this->assertSame(['rejected', 'Maximum entries per person reached'], [$eve2->status, $eve2->rejection_reason]);
        $this->assertSame('rejected', $bo->status);
        $this->assertTrue($bo->isAccountRejection(), 'checked again at opening: self-excluded now');
        $this->assertSame(0, PostalEntry::where('status', 'held')->count());
        $this->assertSame(2, $this->verityTickets($c));
        $this->assertStringContainsString('2 postal entries received before opening added to the draw, 2 rejected',
            CompetitionEvent::where('action', 'postal_held_added')->value('summary'));
    }

    public function test_an_envelope_received_before_opening_but_logged_after_is_accepted_up_to_the_postal_deadline(): void
    {
        $c = $this->comingSoon();
        Carbon::setTestNow('2026-12-01 09:00:20');
        $this->artisan('competitions:open-due')->assertSuccessful();
        Carbon::setTestNow('2026-12-03 10:00:00');

        // Received on 25 November (before opening), typed in after opening: normal entry, numbered at once.
        $this->logPostal($c, 'sam@example.com', '2026-11-25T09:00:00Z')->assertCreated()->assertJsonPath('status', 'allocated');
        $this->assertSame(1, PostalEntry::sole()->ticket->number);

        // The postal deadline still applies (48 hours before close: 22 Dec 19:00).
        Carbon::setTestNow('2026-12-23 10:00:00');
        $this->logPostal($c, 'pat@example.com', '2026-12-22T19:00:01Z')->assertJsonPath('status', 'rejected');
        $this->assertSame(Competition::POSTAL_LATE_REASON, PostalEntry::where('email', 'pat@example.com')->value('rejection_reason'));
    }

    public function test_an_unpublished_draft_still_takes_no_postal_entries(): void
    {
        $draft = Competition::create([
            'slug' => 'secret', 'title' => 'Secret', 'category' => 'cars', 'status' => 'draft', 'images' => [],
            'ticket_price_pence' => 500, 'total_tickets' => 100, 'opens_at' => '2026-12-01 09:00:00',
            'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'postal_entry_enabled' => true,
        ]);
        $this->actingAs($this->admin)->getJson('/api/admin/postal/competitions')->assertOk()->assertJsonCount(0, 'data');
        $this->logPostal($draft, 'sam@example.com', '2026-11-20T09:00:00Z')->assertStatus(422);
        $this->assertSame(0, PostalEntry::count());
    }
}
