<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    private function competition(array $overrides = []): Competition
    {
        return Competition::create(array_merge([
            'slug' => 'test', 'title' => 'Test car', 'category' => 'cars', 'status' => 'live',
            'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
            'total_tickets' => 20, 'max_per_person' => 15, 'draw_at' => now()->addWeek(), 'postal_entry_enabled' => true,
        ], $overrides));
    }

    private function checkout(Competition $c, int $qty, string $email = 'a@example.com')
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '07000000000', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
            'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
    }

    public function test_paid_orders_get_sequential_numbers(): void
    {
        Mail::fake();
        $c = $this->competition();
        $this->checkout($c, 3)->assertOk()->assertJsonStructure(['reference', 'redirectUrl']);
        $this->checkout($c, 2, 'b@example.com')->assertOk();

        [$first, $second] = Order::orderBy('id')->get()->all();
        $this->assertSame(4000, $first->total_pence);

        // Second order pays first: it still gets the next numbers in payment order.
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($second->reference, 'paid'));
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($first->reference, 'paid'));

        $this->assertSame([1, 2], Ticket::where('email', 'b@example.com')->orderBy('number')->pluck('number')->all());
        $this->assertSame([3, 4, 5], Ticket::where('email', 'a@example.com')->orderBy('number')->pluck('number')->all());
        $this->assertSame(5, $c->fresh()->tickets_allocated);
    }

    public function test_webhook_is_idempotent(): void
    {
        Mail::fake();
        $c = $this->competition();
        $this->checkout($c, 1)->assertOk();
        $order = Order::first();
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($order->reference, 'paid'));
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($order->reference, 'paid'));
        $this->assertSame(1, Ticket::count());
    }

    public function test_pending_orders_reserve_capacity_and_per_person_cap_applies(): void
    {
        $c = $this->competition();
        $this->checkout($c, 15)->assertOk();
        $this->checkout($c, 1)->assertStatus(422);              // over the per-person max
        $this->checkout($c, 6, 'b@example.com')->assertStatus(422); // only 5 left
        $this->checkout($c, 5, 'b@example.com')->assertOk();
    }

    public function test_under_18s_are_rejected(): void
    {
        $c = $this->competition();
        $this->postJson('/api/checkout', [
            'customer' => ['email' => 'k@example.com', 'name' => 'Kid', 'phone' => '0', 'dateOfBirth' => now()->subYears(17)->toDateString()],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertStatus(422);
    }
}
