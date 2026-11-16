<?php

namespace Tests\Feature;

use App\Mail\OrderRefunded;
use App\Models\Order;
use App\Models\User;
use App\Support\RefundCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * A refund made in Admin emails the customer: what was refunded, what happens to their tickets, and (for card
 * refunds) the Refund policy's timescale. Staff's reason stays internal. A refused refund sends nothing.
 */
class AdminRefundEmailTest extends TestCase
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
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function competition(string $slug = 'car'): int
    {
        return $this->publishedCompetition(['slug' => $slug, 'event_sales_enabled' => true, 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []])->id;
    }

    private function paidOnline(): Order
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'rae@example.com', 'name' => 'Rae R', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $this->competition(), 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();

        return Order::where('reference', $ref)->firstOrFail();
    }

    private function eventSale(string $method): Order
    {
        $ref = $this->actingAs($this->admin)->postJson('/api/admin/event-sales', [
            'competitionId' => $this->competition('stand-'.$method), 'qty' => 1, 'name' => 'Pat Jones', 'email' => 'pat@example.com', 'dateOfBirth' => '1990-01-01', 'paymentMethod' => $method,
        ])->assertOk()->json('data.reference');

        return Order::where('reference', $ref)->firstOrFail();
    }

    /** @return list<string> */
    private function sent(Order $order): array
    {
        $mails = [];
        Mail::assertQueued(OrderRefunded::class, function (OrderRefunded $m) use ($order, &$mails) {
            if ($m->order->is($order) && $m->hasTo($order->email)) {
                $content = $m->content();
                $html = html_entity_decode($m->render(), ENT_QUOTES);
                $text = view($content->text, $content->with)->render();
                foreach ($m->lines() as $line) {
                    $this->assertStringContainsString($line, $html);
                    $this->assertStringContainsString($line, $text);
                }
                $this->assertStringNotContainsString('Internal note', $html.$text, "staff's reason stays internal");
                $mails[] = implode(' ', $m->lines());
            }

            return true;
        });

        return $mails;
    }

    public function test_partial_then_full_refunds_each_email_the_customer(): void
    {
        $order = $this->paidOnline();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Internal note: goodwill'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['reason' => 'Internal note: asked', 'void_tickets' => true])->assertOk();

        $this->assertSame([
            "We've refunded £5.00 of your payment. ".OrderRefunded::TICKETS_STAY.' '.RefundCopy::TIMESCALE,
            "We've refunded your payment of £30.00 in full. ".OrderRefunded::TICKETS_OUT.' '.RefundCopy::TIMESCALE,
        ], $this->sent($order));
    }

    public function test_a_refused_refund_sends_nothing(): void
    {
        $order = $this->paidOnline();
        config(['raffle.payments.fake_refund_fails' => true]);
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$order->id}/refund", ['reason' => 'Internal note'])->assertStatus(422);
        Mail::assertNotQueued(OrderRefunded::class);
    }

    public function test_marking_refunded_emails_once_with_the_timescale_only_for_card_payments(): void
    {
        $cash = $this->eventSale('cash');
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$cash->id}/refunded")->assertSuccessful();
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$cash->id}/refunded", ['void_tickets' => true])->assertSuccessful();
        $this->assertSame(["We've refunded your payment of £10.00 in full. ".OrderRefunded::TICKETS_STAY], $this->sent($cash), 'cash: no bank timescale, and only one email');

        $reader = $this->eventSale('card_reader');
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$reader->id}/refunded", ['void_tickets' => true])->assertSuccessful();
        $this->assertSame(["We've refunded your payment of £10.00 in full. ".OrderRefunded::TICKETS_OUT.' '.RefundCopy::TIMESCALE], $this->sent($reader));
    }
}
