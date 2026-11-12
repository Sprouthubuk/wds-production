<?php

namespace Tests\Feature;

use App\Enums\EventPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Event sales stand mode: the API the full-screen till relies on. */
class EventStandTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeVerity();
    }

    public function test_competitions_feed_has_live_counts_and_payment_methods_and_isnt_cached(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $c = $this->publishedCompetition(['event_sales_enabled' => true, 'total_tickets' => 100, 'max_per_person' => 50]);
        $this->publishedCompetition(['slug' => 'online-only', 'event_sales_enabled' => false]);

        $res = $this->actingAs($staff)->getJson('/api/admin/event-sales/competitions')->assertOk();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.ticketsSold', 0)->assertJsonPath('data.0.totalTickets', 100)
            ->assertJsonPath('paymentMethods', [['value' => 'card_reader', 'label' => 'Card reader'], ['value' => 'cash', 'label' => 'Cash']]);

        $sale = $this->actingAs($staff)->postJson('/api/admin/event-sales', [
            'competitionId' => $c->id, 'qty' => 3, 'name' => 'Pat Jones', 'email' => 'pat@example.com', 'dateOfBirth' => '1990-01-01', 'paymentMethod' => 'cash',
        ])->assertOk();
        $this->assertCount(3, $sale->json('data.lines.0.ticketNumbers'));
        $this->assertDatabaseHas('orders', ['channel' => 'event', 'payment_method' => 'cash', 'status' => 'paid', 'staff_user_id' => $staff->id]);

        $this->actingAs($staff)->getJson('/api/admin/event-sales/competitions')->assertJsonPath('data.0.ticketsSold', 3);
        $this->actingAs($staff)->getJson('/api/admin/event-sales/summary')->assertJsonPath('data.orders', 1)->assertJsonPath('data.cash', $sale->json('data.total'));
    }

    public function test_only_known_payment_methods_and_every_method_settles_at_the_stand_today(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $c = $this->publishedCompetition(['event_sales_enabled' => true]);
        $this->actingAs($staff)->postJson('/api/admin/event-sales', [
            'competitionId' => $c->id, 'qty' => 1, 'name' => 'Pat Jones', 'email' => 'pat@example.com', 'dateOfBirth' => '1990-01-01', 'paymentMethod' => 'bitcoin',
        ])->assertStatus(422)->assertJsonValidationErrors('paymentMethod');

        foreach (EventPaymentMethod::cases() as $m) {
            $this->assertTrue($m->settlesAtStand(), "{$m->value} must settle at the stand until site card payment is built");
        }
    }

    public function test_customers_cant_use_the_stand(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->getJson('/api/admin/event-sales/competitions')->assertForbidden();
    }
}
