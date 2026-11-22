<?php

namespace Tests\Feature;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\CompetitionEvent;
use App\Models\Draw;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PostalEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\CompetitionLauncher;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\PaymentResult;
use App\Services\PostalEntryService;
use App\Services\PreOpeningAmendments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Sprouthub\Verity\Console\ProcessDueCommand;
use Sprouthub\Verity\Mail\PlainNotice;
use Sprouthub\Verity\Models\AuditEvent;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Sprouthub\Verity\Services\AuditLog;
use Sprouthub\Verity\Verifier\RecordVerifier;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * competitions:amend-cap: the maximum per person of a published competition can be set or lowered before it
 * opens, while nobody has entered, and only with a public record (audit chain, witness email, anchor, history).
 */
class PreOpeningAmendmentTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private const REASON = 'The client asked for a limit per person before launch.';

    private User $admin;

    private string $anchorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Queue::fake();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->anchorLog = tempnam(sys_get_temp_dir(), 'anchor');
        config([
            'raffle.payments.driver' => 'fake',
            'verity.anchor_recipients' => ['witness@example.test'],
            'verity.anchor_log' => $this->anchorLog,
            'verity.anchor_log_check' => true,
            'verity.drand.clock_check_at_close' => true,
            'verity.seed_key' => 'base64:'.base64_encode(random_bytes(32)),
            'verity.owner_pepper' => 'test-pepper',
        ]);
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        @unlink($this->anchorLog);
        parent::tearDown();
    }

    private function comingSoon(array $overrides = []): Competition
    {
        return app(VerityDrawEngine::class)->publish(Competition::create(array_merge([
            'slug' => 'soon-car', 'title' => 'Soon car', 'category' => 'cars', 'status' => 'draft',
            'images' => [['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Car']],
            'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000]], 'total_tickets' => 750, 'max_per_person' => null,
            'opens_at' => '2026-10-21 09:00:00', 'closes_at' => '2026-10-30 20:00:00', 'draw_at' => '2026-10-30 20:30:00',
            'draw_method' => Competition::DRAW_PHYSICAL, 'postal_entry_enabled' => true, 'event_sales_enabled' => true, 'skill_question' => self::skillQ(),
        ], $overrides)));
    }

    /** @return array{0: int, 1: string} exit code and output */
    private function amend(Competition|string $c, int|string $max, bool $apply = true, array $options = []): array
    {
        $code = Artisan::call('competitions:amend-cap', $options + ['slug' => is_string($c) ? $c : $c->slug, 'max' => (string) $max,
            '--reason' => self::REASON, '--by' => $this->admin->email, '--apply' => $apply]);

        return [$code, Artisan::output()];
    }

    private function assertRefused(Competition $c, int|string $max, string $why, array $options = []): void
    {
        $was = $c->fresh()->max_per_person;
        foreach ([false, true] as $apply) {
            [$code, $out] = $this->amend($c, $max, $apply, $options);
            $this->assertSame(1, $code, $out);
            $this->assertStringContainsString($why, $out);
        }
        $this->assertSame($was, $c->fresh()->max_per_person);
        $this->assertSame(0, AuditEvent::where('type', VerityDrawEngine::AMENDED)->count());
        $this->assertSame(0, CompetitionEvent::where('action', 'terms_amended')->count());
        Mail::assertNotSent(PlainNotice::class);
    }

    private function checkout(Competition $c, int $qty, string $email, ?User $user = null)
    {
        $user ? $this->actingAs($user, 'sanctum') : $this->app['auth']->forgetGuards();

        return $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Ann A', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
            'skillAnswers' => [$c->id => self::skillQ()['correct']],
        ]);
    }

    private function buy(Competition $c, int $qty, string $email, ?User $user = null): Order
    {
        $ref = $this->checkout($c, $qty, $email, $user)->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function logPostal(Competition $c, string $email): PostalEntry
    {
        return app(PostalEntryService::class)->log($c, ['name' => 'Ann A', 'address' => '1 High St', 'date_of_birth' => '1990-01-01',
            'email' => $email, 'received_on' => now()->toDateString()], $this->admin);
    }

    private function open(Competition $c): Competition
    {
        Carbon::setTestNow('2026-10-21 09:00:05');

        return app(CompetitionLauncher::class)->open($c->fresh(), null)['competition'];
    }

    // ----- the record -----

    public function test_a_dry_run_writes_nothing(): void
    {
        $c = $this->comingSoon();
        $events = AuditEvent::count();
        [$code, $out] = $this->amend($c, 150, apply: false);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Dry run', $out);
        $this->assertStringContainsString('Max per person: no limit → 150', $out);
        $this->assertNull($c->fresh()->max_per_person);
        $this->assertSame($events, AuditEvent::count());
        $this->assertSame(0, CompetitionEvent::where('action', 'terms_amended')->count());
        $this->assertSame('', (string) file_get_contents($this->anchorLog));
        Mail::assertNothingSent();
    }

    public function test_it_is_recorded_on_the_audit_chain_emailed_anchored_and_in_the_history(): void
    {
        $c = $this->comingSoon();
        $before = $c->fresh()->getAttributes();
        $engine = DB::table('verity_competitions')->where('ref', $c->verity_ref)->first();
        [$code, $out] = $this->amend($c, 150);
        $this->assertSame(0, $code, $out);

        // Only the maximum changed; Verity's own row is untouched.
        $after = $c->fresh()->getAttributes();
        $this->assertSame(150, (int) $after['max_per_person']);
        unset($before['max_per_person'], $after['max_per_person'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertEquals($engine, DB::table('verity_competitions')->where('ref', $c->verity_ref)->first());

        $event = AuditEvent::where('type', 'competition.amended')->sole();
        $payload = (array) $event->payload;
        ksort($payload);
        $this->assertSame([
            'before_opening' => true, 'by' => 'admin:'.$this->admin->id, 'competition' => $c->verity_ref, 'entries' => 0, 'field' => 'max_per_person',
            'from' => null, 'opens_at' => '2026-10-21T09:00:00Z', 'reason' => self::REASON, 'to' => 150,
        ], $payload);
        $this->assertSame('2026-10-20T12:00:00Z', $event->occurred_at);
        $this->assertTrue(AuditLog::verifyChain()['valid']);

        // The witnesses get the amendment, then the anchor of the chain that has it.
        $sent = Mail::sent(PlainNotice::class);
        $this->assertCount(2, $sent);
        $this->assertTrue($sent->every(fn ($m) => $m->hasTo('witness@example.test')));
        $this->assertSame("Verity audit record {$event->seq}", $sent[0]->subjectLine);
        $this->assertStringContainsString('Terms amended before opening: max per person set to 150 (was no limit)', $sent[0]->body);
        $this->assertStringContainsString("seq {$event->seq}, hash {$event->hash}", $sent[0]->body);
        $this->assertStringNotContainsString($this->admin->email, $sent[0]->body);
        $this->assertStringContainsString("seq={$event->seq} head={$event->hash}", $sent[1]->body);
        $this->assertStringContainsString("seq={$event->seq} head={$event->hash}", (string) file_get_contents($this->anchorLog));

        $history = $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/history")->assertOk()->json('data.0');
        $this->assertSame(['terms_amended', $this->admin->email], [$history['action'], $history['by']]);
        $this->assertStringStartsWith('Terms amended before opening: max per person set to 150 (was no limit)', $history['summary']);
        $this->assertStringContainsString(self::REASON, $history['summary']);
        $trail = collect($this->actingAs($this->admin)->getJson('/api/admin/draws')->json())->firstWhere('competitionId', $c->id)['trail'] ?? [];
        $this->assertContains('competition.amended', array_column($trail, 'type'));

        $this->getJson("/api/competitions/{$c->slug}")->assertJsonPath('data.maxPerPerson', 150)->assertJsonPath('data.status', 'coming_soon');
    }

    public function test_it_can_be_lowered_again_and_each_change_is_its_own_record(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 150);
        $this->assertSame(0, $this->amend($c, 99)[0]);
        $this->assertSame(99, (int) $c->fresh()->max_per_person);
        $this->assertSame([[null, 150], [150, 99]], AuditEvent::where('type', 'competition.amended')->orderBy('seq')->get()
            ->map(fn ($e) => [$e->payload['from'], $e->payload['to']])->all());
    }

    public function test_verity_check_strict_the_chain_and_the_invariants_still_pass_after_it(): void
    {
        $this->comingSoon();
        $this->comingSoon(['slug' => 'coilovers', 'title' => 'Coilovers', 'total_tickets' => 495]);
        $free = $this->comingSoon(['slug' => 'table', 'title' => 'Engine table', 'ticket_price_pence' => 0, 'bundles' => [], 'max_per_person' => 1,
            'postal_entry_enabled' => false, 'event_sales_enabled' => false, 'skill_question' => null]);
        cache()->put(ProcessDueCommand::HEARTBEAT, now()->getTimestamp());
        $this->artisan('verity:anchor')->assertSuccessful();
        $this->artisan('verity:check --strict')->assertSuccessful();
        $anchored = AuditEvent::orderByDesc('seq')->first();

        $this->assertSame(0, $this->amend('soon-car', 150)[0]);
        $this->assertSame(0, $this->amend('coilovers', 99)[0]);
        $this->assertSame(1, (int) $free->fresh()->max_per_person);

        $code = Artisan::call('verity:check', ['--strict' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('FAIL', $out);
        $this->assertStringContainsString('ok  The audit chain re-hashes from genesis', $out);
        $this->assertStringContainsString('ok  The last anchor is still in the chain', $out);
        $this->assertStringContainsString('ok  The engine and draw path match SOURCE-MANIFEST.txt', $out);
        $this->artisan('verity:verify-chain', ['--expect' => ["{$anchored->seq}:{$anchored->hash}"]])->assertSuccessful();
        $this->artisan('verity:invariants')->assertSuccessful();
    }

    /** The whole life of an amended competition: opens, sells to the new maximum, closes, is drawn, and verifies. */
    public function test_an_amended_competition_draws_and_its_public_record_verifies_and_shows_the_amendment(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 5);
        $c = $this->open($c);
        $this->buy($c, 3, 'ann@example.com');
        $this->buy($c, 2, 'ben@example.com');
        config(['verity.drand.clock_check_at_close' => false]);
        Carbon::setTestNow('2026-10-30 19:30:00');
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-entries")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 4, 'livestreamUrl' => 'https://www.youtube.com/live/abc'])->assertOk();
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/admin/draws/{$draw->id}/publish")->assertOk();

        $res = $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk()
            ->assertJsonPath('certificate.amendments.0.summary', 'Terms amended before opening: max per person set to 5')
            ->assertJsonPath('certificate.amendments.0.from', null)
            ->assertJsonPath('certificate.amendments.0.to', 5)
            ->assertJsonPath('certificate.amendments.0.reason', self::REASON)
            ->assertJsonPath('certificate.amendments.0.at', '2026-10-20T12:00:00Z');
        $event = AuditEvent::where('type', 'competition.amended')->sole();
        $res->assertJsonPath('certificate.amendments.0.seq', (int) $event->seq)->assertJsonPath('certificate.amendments.0.hash', $event->hash);
        $this->assertContains('competition.amended', array_column($res->json('data.audit'), 'type'), 'in the raw public record');
        $this->assertStringNotContainsString($this->admin->email, (string) $res->getContent());

        $rows = RecordVerifier::verify($res->json('data'));
        $this->assertFalse(RecordVerifier::failed($rows), json_encode(array_values(array_filter($rows, fn ($r) => $r[0] === 'FAIL'))));
        $this->artisan('verity:invariants')->assertSuccessful();
    }

    // ----- refusals -----

    public function test_it_refuses_any_entry_of_any_kind(): void
    {
        // Held postal entry.
        $c = $this->comingSoon(['slug' => 'held']);
        $this->assertSame('held', $this->logPostal($c, 'ann@example.com')->status);
        Mail::fake();
        $this->assertRefused($c, 150, 'It already has postal entries (held or entered): 1.');

        // A ticket (event, paid, free: all are tickets), even a withdrawn one.
        $c = $this->comingSoon(['slug' => 'ticket']);
        Ticket::forceCreate(['competition_id' => $c->id, 'number' => 1, 'verity_entry_id' => null, 'channel' => 'event',
            'entrant_id' => Entrant::forEmail('ann@example.com')->id, 'email' => 'ann@example.com', 'name' => 'Ann', 'withdrawn_at' => now()]);
        $this->assertRefused($c, 150, 'It already has tickets: 1.');

        // Any order: pending (reserved), paid, or cancelled (a late payment can still revive it).
        foreach (['pending', 'paid', 'cancelled'] as $status) {
            $c = $this->comingSoon(['slug' => "order-{$status}"]);
            $order = Order::forceCreate(['reference' => 'WDS-'.strtoupper($status), 'access_key' => 'k', 'entrant_id' => Entrant::forEmail('ann@example.com')->id,
                'email' => 'ann@example.com', 'name' => 'Ann', 'date_of_birth' => '1990-01-01', 'channel' => 'online', 'status' => $status,
                'subtotal_pence' => 1495, 'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => 1495, 'reserved_until' => now()->addMinutes(15)]);
            OrderLine::forceCreate(['order_id' => $order->id, 'competition_id' => $c->id, 'qty' => 1, 'unit_price_pence' => 1495, 'line_total_pence' => 1495]);
            $this->assertRefused($c, 150, 'It already has orders (paid, pending or ended): 1.');
        }

        // A number once issued (it's never given back).
        $c = $this->comingSoon(['slug' => 'allocated']);
        $c->forceFill(['tickets_allocated' => 2])->save();
        $this->assertRefused($c, 150, 'It already has tickets: 2.');
    }

    public function test_it_refuses_once_open_at_opening_time_and_when_not_published(): void
    {
        $c = $this->comingSoon();
        // Opened early on the engine, whatever WDS's status says.
        $live = $this->comingSoon(['slug' => 'live-engine']);
        \Sprouthub\Verity\Facades\Verity::goLive($live->verity_ref);
        $this->assertRefused($live, 150, 'The draw engine has it as live');

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->assertRefused($c, 150, 'Its opening time has passed.');
        $open = $this->open($c);
        $this->assertRefused($open, 150, "It's live, not coming soon");

        $draft = Competition::create(['slug' => 'draft', 'title' => 'Draft', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 100, 'bundles' => [],
            'total_tickets' => 10, 'opens_at' => '2026-10-25 09:00:00']);
        $this->assertRefused($draft, 5, "It's draft, not coming soon");
    }

    public function test_it_refuses_loosening_and_values_that_are_not_a_tighter_whole_number(): void
    {
        $c = $this->comingSoon(['max_per_person' => 20]);
        $this->assertRefused($c, 21, 'would loosen it');
        $this->assertRefused($c, 20, 'already 20');
        $this->assertRefused($c, 0, 'must be at least 1');
        foreach (['null', '-5', '1.5', 'none', ''] as $bad) {
            [$code, $out] = $this->amend($c, $bad);
            $this->assertSame(1, $code, $out);
        }
        $this->assertSame(20, (int) $c->fresh()->max_per_person);

        $open = $this->comingSoon(['slug' => 'no-cap']);
        $this->assertRefused($open, 750, "wouldn't tighten anything");
        [$code] = $this->amend('no-such-slug', 5);
        $this->assertSame(1, $code);
    }

    public function test_it_needs_a_reason_and_an_active_admin(): void
    {
        $c = $this->comingSoon();
        $this->assertRefused($c, 150, 'Give a reason', ['--reason' => 'short']);
        $this->assertRefused($c, 150, 'Give a reason', ['--reason' => str_repeat('x', 301)]);
        $this->assertRefused($c, 150, '--by= an active admin', ['--by' => '']);
        User::factory()->create(['role' => 'staff', 'email' => 'staff@example.test']);
        $this->assertRefused($c, 150, '--by= an active admin', ['--by' => 'staff@example.test']);
        $this->admin->forceFill(['suspended_at' => now()])->save();
        $this->assertRefused($c, 150, '--by= an active admin');
    }

    public function test_it_refuses_when_it_could_not_be_witnessed_or_the_chain_is_not_intact(): void
    {
        $c = $this->comingSoon();
        config(['verity.anchor_recipients' => []]);
        $this->assertRefused($c, 150, 'VERITY_ANCHOR_EMAILS is not set');

        config(['verity.anchor_recipients' => ['witness@example.test']]);
        file_put_contents($this->anchorLog, '2026-10-19T00:00:00Z seq=9999 head='.str_repeat('a', 64).PHP_EOL);
        $this->assertRefused($c, 150, 'restored from an older copy');
    }

    public function test_nothing_else_can_change_this_way(): void
    {
        $c = $this->comingSoon();
        $definition = Artisan::all()['competitions:amend-cap']->getDefinition();
        $this->assertSame(['slug', 'max'], array_keys($definition->getArguments()));
        $own = array_diff(array_keys($definition->getOptions()), array_keys(Artisan::all()['competitions:open-due']->getDefinition()->getOptions()));
        $this->assertSame(['reason', 'by', 'apply'], array_values($own));

        // Admin still can't change any locked term, the maximum included.
        foreach (['maxPerPerson' => 150, 'ticketPrice' => 1, 'totalTickets' => 10, 'closesAt' => '2026-11-30T20:00:00Z', 'opensAt' => '2026-10-22T09:00:00Z',
            'drawMethod' => 'verity', 'skillQuestion' => ['question' => 'Other?', 'options' => ['a', 'b', 'c'], 'correct' => 0]] as $field => $value) {
            $this->actingAs($this->admin)->putJson("/api/admin/competitions/{$c->id}", [$field => $value])->assertStatus(422);
        }
        $this->assertNull($c->fresh()->max_per_person);
    }

    public function test_an_entry_between_the_check_and_the_write_is_caught_and_nothing_is_written(): void
    {
        $c = $this->comingSoon();
        $this->logPostal($c, 'ann@example.com');
        $events = AuditEvent::count();
        try {
            app(PreOpeningAmendments::class)->apply($c, 150, self::REASON, $this->admin);
            $this->fail('refused inside the transaction');
        } catch (EntryException $e) {
            $this->assertStringContainsString('postal entries', $e->getMessage());
        }
        $this->assertSame($events, AuditEvent::count());
        $this->assertNull($c->fresh()->max_per_person);
    }

    public function test_a_failed_witness_email_is_reported_with_the_text_to_forward(): void
    {
        $c = $this->comingSoon();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail is down'));
        [$code, $out] = $this->amend($c, 150);

        $this->assertSame(1, $code);
        $this->assertSame(150, (int) $c->fresh()->max_per_person, 'saved and on the chain');
        $this->assertStringContainsString('could not be sent', $out);
        $this->assertStringContainsString('Audit record: seq ', $out);
    }

    // ----- the maximum at checkout -----

    public function test_the_amended_maximum_is_enforced_on_every_route(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 5);
        $this->assertNull(VerityCompetition::where('ref', $c->verity_ref)->value('max_per_person'), 'the engine keeps what it was published with');
        $c = $this->open($c);

        $this->checkout($c, 6, 'ann@example.com')->assertStatus(422)->assertJsonFragment(['message' => 'The maximum is 5 tickets per person for Soon car. You can add 5 more.']);
        $this->buy($c, 3, 'ann@example.com');
        $this->assertSame('allocated', $this->logPostal($c, 'Ann@Example.com')->status);
        $this->checkout($c, 2, 'ann@example.com')->assertStatus(422)->assertJsonFragment(['message' => 'The maximum is 5 tickets per person for Soon car. You can add 1 more.']);

        // Event sales count towards it and are held to it.
        $event = fn () => app(CheckoutService::class)->start(['customer' => ['email' => 'ann@example.com', 'name' => 'Ann', 'phone' => null, 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]]], null, 'event', $this->admin, 'cash');
        $event();
        try {
            $event();
            $this->fail('a sixth ticket');
        } catch (EntryException $e) {
            $this->assertStringContainsString('The maximum is 5 tickets per person', $e->getMessage());
        }
        $this->assertSame('Maximum entries per person reached', $this->logPostal($c, 'ann@example.com')->rejection_reason);
        $this->assertSame(5, Ticket::where('competition_id', $c->id)->count());
    }

    public function test_a_late_payment_cannot_take_someone_over_the_maximum(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 5);
        $c = $this->open($c);
        // Four reserved, never paid in time; then five bought; then the first payment arrives late.
        $late = $this->checkout($c, 4, 'ann@example.com')->assertOk()->json('reference');
        Carbon::setTestNow(now()->addHours(2));
        Order::where('reference', $late)->update(['status' => 'cancelled', 'reserved_until' => null]);
        $this->buy($c, 5, 'ann@example.com');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($late, 'paid'));

        $this->assertSame(5, Ticket::where('competition_id', $c->id)->count());
        $order = Order::where('reference', $late)->firstOrFail();
        $this->assertSame('refunded', $order->status);
        $this->assertStringContainsString('The maximum is 5 tickets per person', (string) $order->status_note);
    }

    public function test_a_signed_in_customer_cannot_get_round_it_with_another_email(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 5);
        $c = $this->open($c);
        $ann = User::factory()->create(['email' => 'ann@example.com', 'date_of_birth' => '1990-01-01']);
        $this->buy($c, 4, 'other@example.com', $ann);

        $this->checkout($c, 2, 'ann@example.com', $ann)->assertStatus(422)->assertJsonFragment(['message' => 'The maximum is 5 tickets per person for Soon car. You can add 1 more.']);
        $this->checkout($c, 2, 'third@example.com', $ann)->assertStatus(422);
        $this->checkout($c, 2, 'ann@example.com')->assertStatus(422); // as a guest, with the account's email
        $this->checkout($c, 1, 'ann@example.com', $ann)->assertOk();
    }

    public function test_the_remaining_allowance_for_the_selector_and_basket(): void
    {
        $c = $this->comingSoon();
        $this->amend($c, 5);
        $c = $this->open($c);
        $ann = User::factory()->create(['email' => 'ann@example.com', 'date_of_birth' => '1990-01-01']);
        $this->buy($c, 2, 'ann@example.com');   // bought as a guest
        $this->logPostal($c, 'ann@example.com'); // and one by post

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/me/allowance/{$c->slug}")->assertUnauthorized();
        $quote = fn () => $this->postJson('/api/basket/quote', ['items' => [['competitionId' => $c->id, 'qty' => 1]]])->assertOk();
        $quote()->assertJsonPath('data.0.maxPerPerson', 5)->assertJsonPath('data.0.held', null); // a guest: not shown

        $this->actingAs($ann, 'sanctum');
        $this->getJson("/api/me/allowance/{$c->slug}")->assertOk()->assertExactJson(['data' => ['max' => 5, 'held' => 3, 'remaining' => 2]]);
        $quote()->assertJsonPath('data.0.maxPerPerson', 5)->assertJsonPath('data.0.held', 3);

        // A pending checkout is held too, as checkout counts it.
        $this->checkout($c, 2, 'ann@example.com', $ann)->assertOk();
        $this->getJson("/api/me/allowance/{$c->slug}")->assertExactJson(['data' => ['max' => 5, 'held' => 5, 'remaining' => 0]]);

        $free = $this->publishedCompetition(['slug' => 'no-max', 'max_per_person' => null]);
        $this->getJson("/api/me/allowance/{$free->slug}")->assertExactJson(['data' => null]);
        $this->getJson('/api/me/allowance/nope')->assertNotFound();
    }
}
