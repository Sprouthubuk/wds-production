<?php

namespace Tests\Feature;

use App\Mail\LatePaymentRefunded;
use App\Models\Competition;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * A payment that arrives after its order expired, when that order's site credit or discount-code use has since gone
 * on another order: refused and refunded in full, with a plain email and the specific reason in Admin's history.
 * With no clash, a late payment is honoured as before.
 */
class LatePaymentClashTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
        $this->competition = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkout(?User $user, string $email, array $extra = []): Order
    {
        $user ? $this->actingAs($user) : $this->app['auth']->forgetGuards();
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Lee L', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $this->competition->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ] + $extra)->assertOk()->json('reference');

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function pay(Order $order): void
    {
        $this->postJson('/api/payments/webhook', ['order' => $order->reference, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
    }

    private function expire(): void
    {
        Carbon::setTestNow(now()->addMinutes((int) config('raffle.reservation_minutes') + 1));
        $this->artisan('orders:expire')->assertSuccessful();
    }

    private function assertRefusedAndRefunded(Order $order, string $reason): void
    {
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame($order->total_pence, $order->refunded_pence);
        $this->assertSame(0, Ticket::whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))->count());
        $this->assertSame(LatePaymentRefunded::MESSAGE, $order->status_note);
        Mail::assertQueued(LatePaymentRefunded::class, fn ($m) => $m->order->is($order) && $m->hasTo($order->email));
        $mail = new LatePaymentRefunded($order);
        $this->assertStringContainsString("You're welcome to enter again.", html_entity_decode($mail->render(), ENT_QUOTES));
        $this->assertTrue(PaymentEvent::where('order_id', $order->id)->where('summary', 'like', "Late payment refused: {$reason}%")->exists(),
            'Admin history gives the specific reason');
    }

    public function test_credit_clash_the_late_payment_is_refused_and_refunded(): void
    {
        $user = User::factory()->create(['email' => 'lee@example.com', 'date_of_birth' => '1990-01-01']);
        CreditTransaction::create(['user_id' => $user->id, 'amount_pence' => 2000, 'reason' => 'Goodwill']);
        $first = $this->checkout($user, 'lee@example.com', ['useCredit' => true]);
        $this->assertSame(2000, $first->credit_used_pence);
        $this->expire();
        $second = $this->checkout($user, 'lee@example.com', ['useCredit' => true]);
        $this->pay($second);
        $this->assertSame(0, $user->creditBalancePence());

        $this->pay($first);   // the first order's payment arrives late

        $this->assertRefusedAndRefunded($first, 'the £20.00 site credit it used had already gone on another order');
        $this->assertSame(0, $user->creditBalancePence(), 'the balance never goes negative');
        $this->assertSame('paid', $second->fresh()->status);
    }

    public function test_code_clash_the_late_payment_is_refused_and_refunded(): void
    {
        DiscountCode::create(['code' => 'ONCE', 'type' => 'amount', 'value' => 500, 'usage_limit' => 1, 'times_used' => 0, 'active' => true]);
        $first = $this->checkout(null, 'a@example.com', ['discountCode' => 'ONCE']);
        $this->expire();
        $this->pay($this->checkout(null, 'b@example.com', ['discountCode' => 'ONCE']));

        $this->pay($first);

        $this->assertRefusedAndRefunded($first, 'discount code ONCE had already reached its usage limit (1)');
        $this->assertSame(1, DiscountCode::where('code', 'ONCE')->value('times_used'));
    }

    public function test_with_no_clash_a_late_payment_is_honoured_as_before(): void
    {
        $user = User::factory()->create(['email' => 'lee@example.com', 'date_of_birth' => '1990-01-01']);
        CreditTransaction::create(['user_id' => $user->id, 'amount_pence' => 2000, 'reason' => 'Goodwill']);
        DiscountCode::create(['code' => 'TWICE', 'type' => 'amount', 'value' => 500, 'usage_limit' => 2, 'times_used' => 0, 'active' => true]);
        $order = $this->checkout($user, 'lee@example.com', ['useCredit' => true, 'discountCode' => 'TWICE']);
        $this->expire();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->pay($order);

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(3, Ticket::whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))->count());
        $this->assertSame(0, $user->creditBalancePence());
        $this->assertSame(1, DiscountCode::where('code', 'TWICE')->value('times_used'));
        Mail::assertNotQueued(LatePaymentRefunded::class);
    }
}
