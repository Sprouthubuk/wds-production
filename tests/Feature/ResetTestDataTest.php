<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResetTestDataTest extends TestCase
{
    use RefreshDatabase;

    private Competition $competition;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->competition = Competition::create([
            'slug' => 'test', 'title' => 'Test car', 'category' => 'cars', 'status' => 'live',
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 50,
            'closes_at' => now()->addWeeks(2), 'draw_at' => now()->addWeeks(2)->addHour(), 'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ]);
    }

    /** An online order paid through the fake gateway (as in local testing). */
    private function fakeGatewayOrder(string $email = 'a@example.com'): Order
    {
        $reference = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $this->competition->id, 'qty' => 2]],
            'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($reference, 'paid'));

        return Order::where('reference', $reference)->firstOrFail();
    }

    public function test_clears_test_data_paid_through_the_fake_gateway(): void
    {
        $order = $this->fakeGatewayOrder();
        $this->assertSame('fake', $order->payment_provider);
        $this->assertSame('paid', $order->status);
        $draw = Draw::create(['competition_id' => $this->competition->id, 'engine' => 'commit-reveal', 'status' => 'drawn', 'entries_count' => 2, 'entries_hash' => str_repeat('a', 64), 'winning_ticket_id' => Ticket::first()->id]);
        Winner::create(['competition_id' => $this->competition->id, 'draw_id' => $draw->id, 'ticket_id' => Ticket::first()->id, 'display_name' => 'Alex S.', 'prize' => 'Test car']);
        $handAdded = Winner::create(['display_name' => 'Mark Robinson', 'prize' => 'Alpina D3 Coupé']);
        $user = User::factory()->create();

        $this->artisan('wds:reset-test-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Draw::count());
        $this->assertSame(0, Entrant::count());
        $this->assertSame([$handAdded->id], Winner::pluck('id')->all());
        $this->assertSame(0, $this->competition->fresh()->tickets_allocated);
        $this->assertTrue($user->fresh()->exists);
        $this->assertSame(1, Competition::count());
    }

    public function test_refuses_when_an_order_was_paid_through_a_real_gateway(): void
    {
        $this->fakeGatewayOrder();
        $real = $this->fakeGatewayOrder('b@example.com');
        $real->update(['payment_provider' => 'cashflows']);

        $this->artisan('wds:reset-test-data', ['--force' => true])
            ->expectsOutputToContain('Refusing')
            ->assertFailed();

        $this->assertSame(2, Order::count());
        $this->assertSame(4, Ticket::count());
    }

    public function test_refuses_when_an_event_sale_was_paid_with_no_gateway(): void
    {
        app(CheckoutService::class)->start([
            'customer' => ['email' => 'c@example.com', 'name' => 'Cash Buyer', 'phone' => null, 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $this->competition->id, 'qty' => 1]],
        ], null, 'event', User::factory()->create(['role' => 'staff']), 'cash');
        $this->assertNull(Order::first()->payment_provider);

        $this->artisan('wds:reset-test-data', ['--force' => true])->assertFailed();

        $this->assertSame(1, Order::count());
    }

    public function test_unpaid_orders_from_other_gateways_do_not_block(): void
    {
        $this->fakeGatewayOrder();
        Order::create([
            'reference' => 'WDS-PEND01', 'access_key' => str_repeat('k', 40), 'email' => 'p@example.com', 'name' => 'Pending',
            'date_of_birth' => '1990-05-05', 'channel' => 'online', 'status' => 'failed', 'subtotal_pence' => 1495,
            'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => 1495,
            'payment_provider' => 'cashflows',
        ]);

        $this->artisan('wds:reset-test-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::count());
    }
}
