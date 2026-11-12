<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Physical and random-number-generator draws: at closes_at the scheduler closes and seals the entry list,
 * and nothing more. The winner is only ever the ticket recorded from the live draw; no drand round draws it.
 */
class LiveDrawsNeverAutoDrawTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function methods(): array
    {
        return [
            'physical' => [['draw_method' => Competition::DRAW_PHYSICAL]],
            'random number generator' => [['draw_method' => Competition::DRAW_EXTERNAL, 'draw_tool_name' => 'random.org', 'draw_tool_url' => 'https://www.random.org/integers/']],
        ];
    }

    #[DataProvider('methods')]
    public function test_sealed_at_closes_at_but_never_drawn_automatically(array $method): void
    {
        $this->fakeVerity();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $c = $this->publishedCompetition($method + ['closes_at' => self::CLOSES_AT, 'draw_at' => '2026-10-30 20:30:00', 'postal_closes_at' => '2026-10-28 20:00:00']);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        // The scheduler at the closing time, a minute later (when a Verity draw would run), and the next day.
        foreach (['2026-10-30 20:00:05', '2026-10-30 20:01:05', '2026-10-30 20:30:00', '2026-10-31 20:00:00'] as $t) {
            Carbon::setTestNow($t);
            $this->artisan('verity:process-due')->assertSuccessful();
        }

        $vc = app(VerityDrawEngine::class)->verityCompetition($c->fresh());
        $this->assertSame('closed', $vc->status, 'Closed and sealed by the scheduler');
        $this->assertNotEmpty($vc->entries_hash);
        $this->assertNull($vc->beacon_round, 'No drand round is committed for a live draw');
        $this->assertNull($vc->drawn_at);
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertSame('prepared', $draw->status);
        $this->assertNull($draw->winning_ticket_id);
        $this->assertSame(0, Winner::where('competition_id', $c->id)->count());
    }
}
