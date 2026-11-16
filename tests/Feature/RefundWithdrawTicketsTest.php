<?php

namespace Tests\Feature;

use App\Mail\OrderRefunded;
use App\Mail\OrderTicketsWithdrawn;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Models\Entry;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Staging WDS-QOYF9F: £5.00 partial, then the £245.00 remainder with "Take the tickets out of the draw". The
 * remainder refund (with the amount typed in) must withdraw the tickets; ticking the box on a partial refund is
 * refused rather than ignored; and an order refunded in full with tickets still in the draw is flagged in Admin,
 * with an action that takes them out while the competition is open (recorded in history, customer told once).
 */
class RefundWithdrawTicketsTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Sam Admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paidOrder(int $qty = 20): Order
    {
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 1000, 'ticket_price_pence' => 1250, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'kim@example.com', 'name' => 'Kim K', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function inDraw(Order $order): int
    {
        return $order->tickets()->whereNull('tickets.withdrawn_at')->count();
    }

    private function panel(Order $order): array
    {
        return $this->actingAs($this->admin)->getJson("/api/admin/orders/{$order->id}")->assertOk()->json('payment');
    }

    public function test_partial_then_remainder_with_withdraw_takes_the_tickets_out(): void
    {
        $order = $this->paidOrder();
        $this->assertSame(25000, $order->total_pence);
        // What the Admin form sends: the box is a boolean either way, the amount a number (or null when left empty).
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Test partial', 'void_tickets' => false])->assertOk();
        $this->assertSame(20, $this->inDraw($order));

        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 245, 'reason' => 'Test remainder', 'void_tickets' => true])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'its tickets are out of the draw'));

        $order->refresh();
        $this->assertSame(['refunded', 25000], [$order->status, $order->refunded_pence]);
        $this->assertSame(0, $this->inDraw($order));
        $this->assertSame(0, Entry::count(), 'Verity withdrew the entries too');
        $this->assertTrue(PaymentEvent::where('order_id', $order->id)->where('kind', 'tickets')->where('summary', '20 tickets taken out of the draw by Sam Admin')->exists());
        $this->assertFalse($this->panel($order)['refundedButInDraw']);
    }

    public function test_ticking_the_box_on_a_partial_refund_is_refused_not_ignored(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Test', 'void_tickets' => true])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'only be taken out of the draw with a full refund of everything left (£250.00)'));
        $this->assertSame(['paid', 0], [$order->fresh()->status, $order->fresh()->refunded_pence]);
        $this->assertSame(20, $this->inDraw($order));
    }

    public function test_refunded_in_full_with_tickets_in_draw_is_flagged_and_can_be_cleaned_up_once(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Test'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 245, 'reason' => 'Test'])->assertOk();   // box not ticked
        $this->assertSame(['refunded', 20], [$order->fresh()->status, $this->inDraw($order)]);
        Mail::assertQueued(OrderRefunded::class, fn (OrderRefunded $m) => $m->full && in_array(OrderRefunded::TICKETS_STAY, $m->lines(), true));

        $p = $this->panel($order);
        $this->assertSame([20, true, true, []], [$p['ticketsInDraw'], $p['refundedButInDraw'], $p['canWithdrawTickets'], $p['withdrawnTickets']]);

        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/withdraw-tickets")->assertOk();
        $this->assertSame(0, $this->inDraw($order));
        $this->assertSame(0, Entry::count());
        $this->assertSame(['refunded', 25000], [$order->fresh()->status, $order->fresh()->refunded_pence], 'money unchanged');
        $this->assertTrue(PaymentEvent::where('order_id', $order->id)->where('kind', 'tickets')->where('status', 'Withdrawn')->exists());
        $p = $this->panel($order);
        $this->assertFalse($p['refundedButInDraw']);
        $this->assertCount(20, $p['withdrawnTickets'], 'the order page lists the withdrawn numbers');

        // The tickets line changed (stay → out), so one short note; no second refund email.
        Mail::assertQueued(OrderRefunded::class, 2);   // one per refund, none for the clean-up
        Mail::assertQueued(OrderTicketsWithdrawn::class, function (OrderTicketsWithdrawn $m) use ($order) {
            $html = html_entity_decode($m->render(), ENT_QUOTES);
            $content = $m->content();
            $text = view($content->text, $content->with)->render();

            return $m->hasTo($order->email) && str_contains($html, OrderRefunded::TICKETS_OUT) && str_contains($text, OrderRefunded::TICKETS_OUT)
                && ! str_contains($html.$text, 'refunded your payment');
        });

        // Running it again does nothing and sends nothing.
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/withdraw-tickets")->assertStatus(422);
        Mail::assertQueued(OrderTicketsWithdrawn::class, 1);
    }

    public function test_not_allowed_unless_refunded_or_after_close(): void
    {
        $paid = $this->paidOrder(2);
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$paid->id}/withdraw-tickets")->assertStatus(422);
        $this->assertSame(2, $this->inDraw($paid));

        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$paid->id}/refund", ['reason' => 'Test'])->assertOk();
        Carbon::setTestNow('2026-12-24 19:00:05');
        $this->artisan('verity:process-due')->assertSuccessful();

        $p = $this->panel($paid);
        $this->assertSame([true, false], [$p['refundedButInDraw'], $p['canWithdrawTickets']], 'still warned, but no action once closed');
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$paid->id}/withdraw-tickets")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'entries are frozen'));
        $this->assertSame(2, $this->inDraw($paid));
        Mail::assertNotQueued(OrderTicketsWithdrawn::class);
    }
}
