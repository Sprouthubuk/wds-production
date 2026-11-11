<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

class ResetTestDataTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
    }

    private function draft(): Competition
    {
        return Competition::create([
            'slug' => 'draft', 'title' => 'Draft car', 'category' => 'cars', 'status' => 'draft',
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 50,
            'closes_at' => now()->addWeeks(2), 'draw_at' => now()->addWeeks(2)->addHour(),
        ]);
    }

    /** An order row with no tickets (as a checkout leaves before payment, or a hand-made test row). */
    private function order(string $reference, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'reference' => $reference, 'access_key' => str_repeat('k', 40), 'email' => strtolower($reference).'@example.com', 'name' => 'Test',
            'date_of_birth' => '1990-05-05', 'channel' => 'online', 'status' => 'pending', 'subtotal_pence' => 1495,
            'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => 1495,
            'entrant_id' => Entrant::forEmail(strtolower($reference).'@example.com')->id,
        ], $attributes));
    }

    public function test_clears_test_orders_before_anything_is_on_verity(): void
    {
        $c = $this->draft();
        $this->order('WDS-PEND01');
        $this->order('WDS-FAKE01', ['status' => 'paid', 'paid_at' => now(), 'payment_provider' => 'fake']);
        $this->order('WDS-FAIL01', ['status' => 'failed', 'payment_provider' => 'cashflows']);
        $handAdded = Winner::create(['display_name' => 'Mark Robinson', 'prize' => 'Alpina D3 Coupé']);
        $user = User::factory()->create();

        $this->artisan('wds:reset-test-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Entrant::count());
        $this->assertSame([$handAdded->id], Winner::pluck('id')->all());
        $this->assertTrue($user->fresh()->exists);
        $this->assertSame(1, Competition::whereKey($c->id)->count());
    }

    public function test_refuses_when_an_order_was_paid_through_a_real_gateway(): void
    {
        $this->order('WDS-REAL01', ['status' => 'paid', 'paid_at' => now(), 'payment_provider' => 'cashflows']);

        $this->artisan('wds:reset-test-data', ['--force' => true])
            ->expectsOutputToContain('did not go through the fake gateway')
            ->assertFailed();

        $this->assertSame(1, Order::count());
    }

    public function test_refuses_when_an_event_sale_was_paid_with_no_gateway(): void
    {
        $this->order('WDS-CASH01', ['channel' => 'event', 'status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);

        $this->artisan('wds:reset-test-data', ['--force' => true])->assertFailed();

        $this->assertSame(1, Order::count());
    }

    public function test_refuses_once_anything_is_on_verity(): void
    {
        $c = $this->publishedCompetition();
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 2]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        $this->artisan('wds:reset-test-data', ['--force' => true])
            ->expectsOutputToContain('the Verity draw engine already holds data')
            ->assertFailed();

        $this->assertSame(1, Order::count());
        $this->assertSame(2, Ticket::count());
    }
}
