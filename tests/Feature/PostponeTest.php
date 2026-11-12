<?php

namespace Tests\Feature;

use App\Mail\CompetitionPostponed;
use App\Models\Competition;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Models\AuditEvent;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Postpone (emergency only): reason ≥ 20 characters and a confirmation; audit chain, notice, email to every entrant. */
class PostponeTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private const REASON = 'The website was down for three hours on Tuesday';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_postponing_needs_a_long_reason_and_a_confirmation(): void
    {
        [$c, $admin] = $this->setUpCompetition();
        $new = '2026-12-27 19:00';
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/extend", ['closesAt' => $new, 'reason' => 'Site was down', 'confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/extend", ['closesAt' => $new, 'reason' => self::REASON])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame('2026-12-24 19:00', $c->fresh()->closes_at->utc()->format('Y-m-d H:i'));
        Mail::assertNothingQueued();
    }

    public function test_postponing_records_it_moves_the_draw_emails_every_entrant_and_shows_a_notice(): void
    {
        [$c, $admin] = $this->setUpCompetition();
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/extend", ['closesAt' => '2026-12-27 19:00', 'reason' => self::REASON, 'confirm' => true])
            ->assertOk()->assertJsonPath('message', 'Postponed. The reason is on the public audit record, the page shows a notice, and 2 entrants are being emailed.');

        $c->refresh();
        $this->assertSame(['2026-12-27 19:00', '2026-12-27 19:30', '2026-12-24 19:00'], [
            $c->closes_at->utc()->format('Y-m-d H:i'), $c->draw_at->utc()->format('Y-m-d H:i'), $c->postponed_from->utc()->format('Y-m-d H:i'),
        ]);
        $event = AuditEvent::query()->where('type', 'competition.extended')->firstOrFail();
        $this->assertSame(self::REASON, $event->payload['reason']);

        Mail::assertQueued(CompetitionPostponed::class, 2);   // two entrants, three orders
        Mail::assertQueued(CompetitionPostponed::class, fn ($m) => $m->hasTo('a@example.com'));
        $html = (new CompetitionPostponed($c))->render();
        $this->assertStringContainsString('Sunday 27 December 2026, 7:00pm', $html);
        $this->assertStringContainsString(self::REASON, $html);

        $this->getJson("/api/competitions/{$c->slug}")->assertOk()
            ->assertJsonPath('data.postponement.reason', self::REASON)
            ->assertJsonPath('data.postponement.from', $c->postponed_from->toIso8601String());
    }

    /** @return array{0: Competition, 1: User} */
    private function setUpCompetition(): array
    {
        Mail::fake();
        Carbon::setTestNow('2026-12-20 12:00:00');
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'max_per_person' => null, 'ticket_price_pence' => 500, 'bundles' => []]);
        foreach (['a@example.com', 'b@example.com', 'a@example.com'] as $email) {
            $ref = $this->postJson('/api/checkout', [
                'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
                'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
            ])->assertOk()->json('reference');
            app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));
        }
        Mail::fake(); // only the postponement emails from here

        return [$c->fresh(), User::factory()->create(['role' => 'admin'])];
    }
}
