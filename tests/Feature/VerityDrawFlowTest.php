<?php

namespace Tests\Feature;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\DrawService;
use App\Services\Payments\PaymentResult;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Sprouthub\Verity\Facades\Verity;
use Sprouthub\Verity\Models\AuditEvent;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Sprouthub\Verity\Models\Entry;
use Sprouthub\Verity\Models\EntryGroup;
use Sprouthub\Verity\Verifier\RecordVerifier;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

class VerityDrawFlowTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->fakeVerity();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function competition(): Competition
    {
        // The tests run on the closing day, so postal entries close that afternoon (the default is three days before).
        return $this->publishedCompetition(['closes_at' => self::CLOSES_AT, 'postal_closes_at' => '2026-10-30 18:00:00', 'draw_at' => '2026-10-30 20:30:00']);
    }

    private function buyOnline(Competition $c, int $qty, string $email): Order
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
            'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function buyAtEvent(Competition $c, int $qty, string $email): Order
    {
        return app(CheckoutService::class)->start([
            'customer' => ['email' => $email, 'name' => 'Sam Jones', 'phone' => null, 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
        ], null, 'event', User::factory()->create(['role' => 'staff']), 'cash')['order'];
    }

    private function enterByPost(Competition $c, string $email): PostalEntry
    {
        return app(PostalEntryService::class)->log($c, [
            'name' => 'Pat Post', 'address' => '1 High St', 'date_of_birth' => '1990-05-05',
            'email' => $email, 'received_on' => now()->toDateString(),
        ], User::factory()->create(['role' => 'staff']));
    }

    private function processDueAt(string $time): void
    {
        Carbon::setTestNow($time);
        $this->artisan('verity:process-due')->assertSuccessful();
    }

    public function test_online_postal_and_event_entries_are_numbered_by_verity_and_the_draw_verifies(): void
    {
        $c = $this->competition();
        $this->assertMatchesRegularExpression('/^VTY-[A-Z0-9]{6}$/', $c->verity_ref);
        $this->assertSame('live', $c->status);

        $online = $this->buyOnline($c, 3, 'alex@example.com');
        $postal = $this->enterByPost($c, 'pat@example.com');
        $event = $this->buyAtEvent($c, 2, 'sam@example.com');

        // WDS's tickets mirror Verity's numbers exactly: 1..6 in confirmation order, every channel in one run.
        $tickets = Ticket::orderBy('number')->get();
        $this->assertSame([1, 2, 3, 4, 5, 6], $tickets->pluck('number')->all());
        $this->assertSame(['online', 'online', 'online', 'postal', 'event', 'event'], $tickets->pluck('channel')->all());
        $vc = VerityCompetition::where('ref', $c->verity_ref)->firstOrFail();
        foreach ($tickets as $t) {
            $entry = Entry::with('group')->findOrFail($t->verity_entry_id);
            $this->assertSame($t->number, (int) $entry->number);
            $this->assertSame($vc->id, (int) $entry->competition_id);
            $this->assertSame('entrant:'.$t->entrant_id, $entry->group->owner_ref);
        }
        $this->assertSame(['paid', 'postal', 'paid'], EntryGroup::orderBy('id')->pluck('source')->all());
        // Ids aren't reset between tests on MySQL, so build the expected host refs from the real rows.
        $this->assertSame([
            'order_line:'.$online->lines()->first()->id, 'postal:'.$postal->id, 'order_line:'.$event->lines()->first()->id,
        ], EntryGroup::orderBy('id')->pluck('host_ref')->all());
        $this->assertSame(6, (int) $vc->fresh()->tickets_sold);

        // The close: entries frozen, a future drand round committed, and the close timestamped.
        $this->processDueAt('2026-10-30 20:00:05');
        $c->refresh();
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertSame('closed', $c->status);
        $this->assertSame('prepared', $draw->status);
        $this->assertSame(self::ROUND, (int) $draw->beacon_round);
        $this->assertSame('2026-10-30 20:01:00', $draw->beacon_round_at->utc()->toDateTimeString());
        $this->assertSame(6, $draw->entries_count);
        $this->assertSame($vc->fresh()->entries_hash, $draw->entries_hash);
        $this->assertNull($draw->winning_number);
        $this->getJson("/api/draws/{$draw->id}/certificate")->assertNotFound();

        // Drawn once the round is out, BLS-verified, mirrored onto WDS's draws and winners.
        $this->processDueAt('2026-10-30 20:01:05');
        $draw->refresh();
        $vc->refresh();
        $this->assertSame('drawn', $vc->status);
        $this->assertSame('drawn', $c->fresh()->status);
        $this->assertSame('drawn', $draw->status);
        $winningEntry = \Sprouthub\Verity\Models\Winner::where('competition_id', $vc->id)->firstOrFail()->entry;
        $this->assertSame((int) $winningEntry->number, $draw->winning_number);
        $this->assertSame($winningEntry->id, $draw->winningTicket->verity_entry_id);
        $this->assertSame(1, Winner::where('draw_id', $draw->id)->count());

        // Not public until WDS publishes (the reveal).
        $this->getJson("/api/draws/{$draw->id}/certificate")->assertNotFound();
        app(DrawService::class)->publish($draw);

        $res = $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk();
        $cert = $res->json('certificate');
        $this->assertSame(self::ROUND, $cert['drandRound']);
        $this->assertSame(self::SIGNATURE, $cert['drandSignature']);
        $this->assertSame(self::RANDOMNESS, $cert['drandRandomness']);
        $this->assertSame($vc->commitment, $cert['commitment']);
        $this->assertSame($vc->entries_hash, $cert['entriesHash']);
        $this->assertSame('VERITY-3', $cert['algorithm']);
        $this->assertSame('sequential', $cert['ticketAllocation']);
        $this->assertNotEmpty($cert['auditEvent']['hash']);
        $this->assertNotEmpty($cert['closeTimestamp']['token']);
        $this->assertStringEndsWith("/verify/{$draw->id}", $cert['howToVerify']);
        $this->assertSame([(int) $winningEntry->number], $cert['winningTickets']);

        // Verity's own reference verifier re-runs everything from the published record.
        $rows = RecordVerifier::verify($res->json('data'));
        $this->assertFalse(RecordVerifier::failed($rows), json_encode(array_filter($rows, fn ($r) => $r[0] === 'FAIL')));
        $passed = array_column(array_filter($rows, fn ($r) => $r[0] === 'PASS'), 1);
        foreach (['drand BLS signature', 'close timestamped before the drand round', 'winners re-drawn', 'draw seed recomputed'] as $check) {
            $this->assertContains($check, $passed);
        }
    }

    public function test_closing_stores_nothing_that_predicts_the_winner(): void
    {
        $c = $this->competition();
        $this->buyOnline($c, 2, 'alex@example.com');
        $this->processDueAt('2026-10-30 20:00:05');

        $vc = VerityCompetition::where('ref', $c->verity_ref)->firstOrFail();
        $this->assertSame('closed', $vc->status);
        // The round isn't out, the seed is sealed (not the hex seed behind the commitment), nothing is revealed.
        $this->assertGreaterThan(now()->getTimestamp(), \Sprouthub\Verity\Engine\Drand::roundTime((int) $vc->beacon_round));
        $this->assertNull($vc->beacon_randomness);
        $this->assertNull($vc->beacon_signature);
        $this->assertNull($vc->draw_seed);
        $this->assertNull($vc->revealed_seed);
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{64}$/', (string) $vc->getRawOriginal('server_seed'));
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertNull($draw->proof);
        $this->assertNull($draw->winning_number);
        $this->assertNull($draw->winning_ticket_id);
        $this->assertSame(0, Winner::count());

        // Before the round is out, a draw attempt just waits.
        $this->processDueAt('2026-10-30 20:00:30');
        $this->assertSame('closed', $vc->fresh()->status);
    }

    public function test_a_verity_refusal_rolls_the_whole_fulfilment_back(): void
    {
        $c = $this->competition();
        $order = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'late@example.com', 'name' => 'Late Payer', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');

        $this->buyOnline($c, 1, 'early@example.com');
        // Verity stops taking entries (closed early, with the public reason it requires).
        Verity::close($c->verity_ref, 'Closed early for the test of a refused entry');

        try {
            $this->enterByPost($c, 'pat@example.com');
            $this->fail('Expected the postal entry to be refused');
        } catch (EntryException) {
        }
        $this->assertSame(0, PostalEntry::count());

        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($order, 'paid'));
        $o = Order::where('reference', $order)->firstOrFail();
        $this->assertSame('cancelled', $o->status);   // money taken, no tickets: shown as "refund needed"
        $this->assertNotNull($o->paid_at);
        $this->assertSame(0, Ticket::where('order_line_id', $o->lines()->first()->id)->count());
        $this->assertSame(1, EntryGroup::count());       // only the order fulfilled before the close
        $this->assertSame(1, Ticket::count());
    }

    public function test_refunds_withdraw_entries_while_open_and_are_refused_after_close(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $c = $this->competition();
        $first = $this->buyOnline($c, 2, 'alex@example.com');
        $second = $this->buyOnline($c, 3, 'sam@example.com');

        $this->actingAs($admin)->postJson("/api/admin/orders/{$first->id}/refunded", ['void_tickets' => true])->assertOk();
        $this->assertSame(2, Ticket::whereNotNull('withdrawn_at')->count());
        $group = EntryGroup::where('host_ref', 'order_line:'.$first->lines()->first()->id)->firstOrFail();
        $this->assertNotNull($group->withdrawn_at);
        $this->assertSame(0, Entry::where('entry_group_id', $group->id)->count());
        $this->assertTrue(AuditEvent::where('type', 'order.withdrawn')->exists());

        // A later buyer gets the next number: withdrawn numbers are never reused.
        $third = $this->buyOnline($c, 1, 'jo@example.com');
        $this->assertSame([6], Ticket::where('order_line_id', $third->lines()->first()->id)->pluck('number')->all());

        $this->processDueAt('2026-10-30 20:00:05');
        $this->actingAs($admin)->postJson("/api/admin/orders/{$second->id}/refunded", ['void_tickets' => true])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'entries are frozen'));
        $this->assertSame(0, Ticket::where('order_line_id', $second->lines()->first()->id)->whereNotNull('withdrawn_at')->count());
        $this->assertSame('paid', $second->fresh()->status);

        // Money-only refund still works; the tickets stay in the draw.
        $this->actingAs($admin)->postJson("/api/admin/orders/{$second->id}/refunded", ['void_tickets' => false])->assertOk();
        $this->assertSame('refunded', $second->fresh()->status);
        $this->assertSame(4, (int) Draw::where('competition_id', $c->id)->value('entries_count'));
    }

    public function test_void_only_for_an_empty_competition_and_recorded_on_the_audit_chain(): void
    {
        $empty = $this->publishedCompetition(['slug' => 'empty', 'closes_at' => self::CLOSES_AT, 'draw_at' => '2026-10-30 20:30:00']);
        $full = $this->competition();
        $this->buyOnline($full, 1, 'alex@example.com');

        $this->processDueAt('2026-10-30 20:00:05');

        $this->assertSame('void', $empty->fresh()->verity_status);
        $this->assertSame('closed', $empty->fresh()->status);
        $this->assertSame(0, Draw::where('competition_id', $empty->id)->count());
        $vEmpty = VerityCompetition::where('ref', $empty->verity_ref)->firstOrFail();
        $this->assertTrue(AuditEvent::where('competition_id', $vEmpty->id)->where('type', 'competition.void')->exists());

        // After close a competition with entries can't be voided, and WDS has no void action at all.
        $this->assertFalse(Verity::void($full->verity_ref, 'trying to void after close'));
        $admin = User::factory()->create(['role' => 'admin']);
        $draw = Draw::where('competition_id', $full->id)->firstOrFail();
        $this->actingAs($admin)->postJson("/api/admin/draws/{$draw->id}/void")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/admin/draws/{$draw->id}/run")->assertNotFound();
        $this->assertSame('prepared', $draw->fresh()->status);
    }

    public function test_publish_is_one_way_and_locks_the_draw_terms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = Competition::create([
            'slug' => 'draft', 'title' => 'Draft car', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 1495,
            'total_tickets' => 100, 'max_per_person' => 10, 'closes_at' => self::CLOSES_AT, 'draw_at' => '2026-10-30 20:30:00',
        ]);

        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['status' => 'live'])->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$draft->id}/publish")->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'locked in'));
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$draft->id}/publish")->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['totalTickets' => 200])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['ticketPrice' => 2])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['closesAt' => '2026-10-31 20:00:00'])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['strapline' => 'New words are fine'])->assertOk();

        // The closing time only moves later, through extend, with a public reason.
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$draft->id}/extend", ['closesAt' => '2026-10-31 20:00:00', 'reason' => 'Show moved by a day'])->assertOk();
        $this->assertSame('2026-10-31 20:00:00', $draft->fresh()->closes_at->toDateTimeString());
        $this->assertTrue(AuditEvent::where('type', 'competition.extended')->exists());
    }
}
