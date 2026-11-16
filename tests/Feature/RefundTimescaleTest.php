<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use App\Support\RefundCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Every refund message a customer sees on the order page carries the Refund policy's timescale (RefundCopy, one
 * shared string): payments refused and refunded automatically, and full or partial refunds made in Admin. Cash
 * refunds at the event stand don't, as they never touch a bank statement.
 */
class RefundTimescaleTest extends TestCase
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

    private function paidOrder(array $comp = []): Order
    {
        $c = $this->publishedCompetition($comp + ['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'rae@example.com', 'name' => 'Rae R', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function message(Order $order): ?string
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/orders/{$order->reference}?key={$order->access_key}")->assertOk()->json('data.message');
    }

    public function test_admin_partial_then_full_refunds_tell_the_customer_the_timescale(): void
    {
        $order = $this->paidOrder();
        $this->assertNull($this->message($order), 'a paid order with no refund has no message');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Goodwill'])->assertOk();
        $this->assertSame("We've refunded £5.00 of your payment. Your tickets are unchanged. ".RefundCopy::TIMESCALE, $this->message($order));

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['reason' => 'Customer asked'])->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame("We've refunded your payment of £30.00 in full. ".RefundCopy::TIMESCALE, $this->message($order));
    }

    public function test_an_order_marked_refunded_by_hand_says_so_with_the_timescale(): void
    {
        $order = $this->paidOrder();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson("/api/admin/orders/{$order->id}/refunded")->assertSuccessful();
        $this->assertSame("We've refunded your payment in full. ".RefundCopy::TIMESCALE, $this->message($order));
    }

    public function test_a_cash_sale_at_the_event_stand_marked_refunded_has_no_bank_timescale(): void
    {
        $c = $this->publishedCompetition(['slug' => 'stand', 'event_sales_enabled' => true, 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $admin = User::factory()->create(['role' => 'admin']);
        $ref = $this->actingAs($admin)->postJson('/api/admin/event-sales', [
            'competitionId' => $c->id, 'qty' => 1, 'name' => 'Pat Jones', 'email' => 'pat@example.com', 'dateOfBirth' => '1990-01-01', 'paymentMethod' => 'cash',
        ])->assertOk()->json('data.reference');
        $order = Order::where('reference', $ref)->firstOrFail();
        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refunded")->assertSuccessful();

        $this->assertSame("We've refunded your payment in full.", $this->message($order));
    }

    public function test_a_payment_refused_and_refunded_automatically_carries_the_timescale(): void
    {
        $c = $this->publishedCompetition(['slug' => 'iw', 'instant_win' => true, 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'cc@example.com', 'name' => 'Cee C', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        // A credit card on an instant-win competition: refused and refunded in full automatically.
        $refused = app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, PaymentResult::PAID, 'p_'.$ref, null, 'card', 'credit'));

        $this->assertSame('refunded', $refused->status);
        $this->assertStringContainsString("can't be used on instant-win", (string) $this->message($refused));
        $this->assertStringEndsWith(' '.RefundCopy::TIMESCALE, (string) $this->message($refused));
        $this->assertSame(1, substr_count((string) $refused->status_note, RefundCopy::TIMESCALE));
    }
}
