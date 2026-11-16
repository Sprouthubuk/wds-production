<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Site credit comes off the balance when an order is paid. Credit already put on another order that is still
 * awaiting payment must not be offered again, or two checkouts at once (two tabs) spend it twice.
 */
class SiteCreditDoubleSpendTest extends TestCase
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

    public function test_credit_on_an_unpaid_order_is_not_offered_again_and_the_balance_never_goes_negative(): void
    {
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $user = User::factory()->create(['email' => 'cred@example.com', 'date_of_birth' => '1990-01-01']);
        CreditTransaction::create(['user_id' => $user->id, 'amount_pence' => 2000, 'reason' => 'Goodwill']);
        $checkout = fn () => $this->actingAs($user)->postJson('/api/checkout', [
            'customer' => ['email' => 'cred@example.com', 'name' => 'Cred C', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true, 'useCredit' => true,
        ])->assertOk()->json('reference');

        $first = Order::where('reference', $checkout())->firstOrFail();
        $second = Order::where('reference', $checkout())->firstOrFail();
        $this->assertSame(2000, $first->credit_used_pence);
        $this->assertSame(1000, $first->total_pence);
        $this->assertSame(0, $second->credit_used_pence, 'The £20 is already on the first order');
        $this->assertSame(3000, $second->total_pence);

        foreach ([$first, $second] as $o) {
            $this->postJson('/api/payments/webhook', ['order' => $o->reference, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
        }
        $this->assertSame(0, $user->fresh()->creditBalancePence());

        // Once an unpaid order's reservation has ended, its credit is available again.
        CreditTransaction::create(['user_id' => $user->id, 'amount_pence' => 500, 'reason' => 'Goodwill']);
        $third = Order::where('reference', $checkout())->firstOrFail();
        $this->assertSame(500, $third->credit_used_pence);
        Carbon::setTestNow(now()->addMinutes((int) config('raffle.reservation_minutes') + 1));
        $this->assertSame(500, Order::where('reference', $checkout())->firstOrFail()->credit_used_pence);
    }
}
