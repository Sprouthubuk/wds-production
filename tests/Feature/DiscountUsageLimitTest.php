<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** A code's usage limit counts unpaid orders still holding it, not only paid ones (times_used). */
class DiscountUsageLimitTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_one_use_code_cannot_go_on_two_checkouts_at_once_but_is_free_again_when_one_expires(): void
    {
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        DiscountCode::create(['code' => 'ONCE', 'type' => 'amount', 'value' => 500, 'usage_limit' => 1, 'times_used' => 0, 'active' => true]);
        $checkout = fn (string $email) => $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Dee D', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true, 'discountCode' => 'once',
        ]);

        $checkout('a@example.com')->assertOk();
        $checkout('b@example.com')->assertStatus(422)->assertJsonPath('message', 'That discount code has been fully used.');

        Carbon::setTestNow(now()->addMinutes((int) config('raffle.reservation_minutes') + 1));
        $ref = $checkout('b@example.com')->assertOk()->json('reference');
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
        $this->assertSame(1, DiscountCode::where('code', 'ONCE')->value('times_used'));
        $checkout('c@example.com')->assertStatus(422);
    }
}
