<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Ticket;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * The provider says the order was paid, but for a different amount. Money was taken, so the order must not look
 * like an ordinary failed payment: it issues no tickets and shows in Admin as "refund needed", where it can be
 * refunded. A repeat notification changes nothing.
 */
class PaymentAmountMismatchTest extends TestCase
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

    public function test_a_payment_for_the_wrong_amount_issues_nothing_and_is_flagged_refund_needed(): void
    {
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'pat@example.com', 'name' => 'Pat P', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');

        $checkout = app(CheckoutService::class);
        $checkout->handlePaymentResult(new PaymentResult($ref, PaymentResult::PAID, 'prov-1', 1000));

        $order = Order::where('reference', $ref)->firstOrFail();
        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(0, Ticket::count());
        $this->assertStringContainsString('£10.00', $order->status_note);
        $this->assertStringContainsString('£30.00', $order->status_note);
        $this->assertGreaterThan(0, $order->refundablePence(), 'Admin must be able to refund it');

        // A repeat notification, even one for the right amount, does not now issue tickets.
        $checkout->handlePaymentResult(new PaymentResult($ref, PaymentResult::PAID, 'prov-1', 3000));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, Ticket::count());
    }
}
