<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * POST /api/payments/webhook is the fake driver's "provider" callback and believes whatever it's sent. On production
 * (where PAYMENT_DRIVER=fake until Cashflows goes live) it let anyone mark their own order paid and get tickets for
 * nothing. It now only exists where the fake payment page does: PAYMENT_DRIVER=fake and not production.
 */
class PaymentWebhookLockdownTest extends TestCase
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

    private function order(): Order
    {
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'chancer@example.com', 'name' => 'Chancer', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 5]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');

        return Order::where('reference', $ref)->firstOrFail();
    }

    public function test_on_production_the_fake_webhook_does_not_exist_and_cannot_mark_an_order_paid(): void
    {
        $order = $this->order();
        $this->app['env'] = 'production';
        try {
            $this->postJson('/api/payments/webhook', ['order' => $order->reference, 'status' => 'paid', 'provider_reference' => 'x'])->assertNotFound();
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_with_cashflows_the_generic_webhook_does_not_exist(): void
    {
        $order = $this->order();
        config(['raffle.payments.driver' => 'cashflows']);
        $this->postJson('/api/payments/webhook', ['order' => $order->reference, 'status' => 'paid'])->assertNotFound();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_off_production_with_the_fake_driver_it_still_works_for_testing(): void
    {
        $order = $this->order();
        $this->postJson('/api/payments/webhook', ['order' => $order->reference, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(5, Ticket::count());
    }
}
