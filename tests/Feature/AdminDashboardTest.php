<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\HarmFlag;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** The Admin dashboard: KPIs net of refunds (UK days), 30-day sales, live competitions and what needs attention. */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        config(['raffle.payments.driver' => 'fake']);
        Carbon::setTestNow('2026-12-07 09:00:00');
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ad Min']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(Competition $c, int $qty, string $email, bool $pay = true): Order
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Pat P', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        if ($pay) {
            $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
        }

        return Order::where('reference', $ref)->firstOrFail();
    }

    public function test_kpis_chart_live_competitions_and_needs_attention(): void
    {
        // Car closes within 48 hours of "today" (10 Dec); its postal deadline (8 Dec) has passed.
        $car = $this->publishedCompetition(['title' => 'Car', 'closes_at' => '2026-12-11 08:00:00', 'draw_at' => '2026-12-11 09:00:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => [], 'images' => [['src' => '/images/car.jpg', 'alt' => 'Car']]]);
        $bike = $this->publishedCompetition(['title' => 'Bike', 'slug' => 'bike', 'closes_at' => '2026-12-20 19:00:00', 'draw_at' => '2026-12-20 20:00:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);

        $this->order($car, 2, 'old@example.com');                     // 7 Dec: £20 (this week and month, not today)
        Carbon::setTestNow('2026-12-10 10:00:00');
        $partly = $this->order($car, 3, 'a@example.com');              // today: £30, then £5 refunded → £25 kept
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$partly->id}/refund", ['amount' => 5, 'reason' => 'Goodwill'])->assertOk();
        $refunded = $this->order($bike, 1, 'b@example.com');           // today: £10, refunded in full (tickets left in) → £0
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$refunded->id}/refund", ['reason' => 'Asked'])->assertOk();
        $pending = $this->order($bike, 1, 'c@example.com', pay: false);
        $needsRefund = $this->order($bike, 1, 'd@example.com');
        $needsRefund->update(['status' => 'cancelled']);              // money taken, no tickets: refund needed
        HarmFlag::create(['email' => 'e@example.com', 'reason' => 'spend', 'summary' => 'Spent a lot quickly', 'status' => 'open']);
        User::factory()->create(['role' => 'customer']);
        Carbon::setTestNow('2026-12-10 10:30:00');                     // the pending payment is now 30 minutes old

        $d = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk()->json('data');

        $k = $d['kpis'];
        $this->assertEquals([25, 45, 45], [$k['revenueToday'], $k['revenue7Days'], $k['revenueMonth']], 'net of refunds');
        $this->assertSame(1, $k['ordersToday'], 'only orders still paid');
        $this->assertSame(3, $k['ticketsToday'], "the paid order's tickets, not the refunded or refund-needed ones");
        $this->assertEquals(22.5, $k['averageOrderMonth']);
        $this->assertSame(1, $k['newCustomersToday']);

        $this->assertCount(30, $d['daily']);
        $this->assertSame('2026-12-10', end($d['daily'])['day']);
        $this->assertEquals(45, array_sum(array_column($d['daily'], 'revenue')));

        $live = collect($d['live'])->keyBy('title');
        $this->assertSame(['/images/car.jpg', 5, 100, 'Verity (drand)'], [$live['Car']['image'], $live['Car']['sold'], $live['Car']['total'], $live['Car']['drawMethod']]);

        $att = collect($d['attention'])->keyBy('key');
        $this->assertSame("/admin/orders/{$needsRefund->id}", $att['refund-needed']['items'][0]['href']);
        $this->assertSame("/admin/orders/{$pending->id}", $att['stale-pending']['items'][0]['href']);
        $this->assertSame("/admin/orders/{$refunded->id}", $att['refunded-in-draw']['items'][0]['href']);
        $this->assertSame("/admin/competitions/{$car->id}", $att['closing-soon']['items'][0]['href']);
        $this->assertSame(1, $att['harm-flags']['count']);
        $this->assertFalse($att->has('awaiting-draw'));

        $this->assertCount(5, $d['recentOrders']);
        $this->assertTrue(collect($d['recentOrders'])->firstWhere('reference', $needsRefund->reference)['refundNeeded']);
    }

    public function test_postal_deadline_and_draws_awaiting_a_result(): void
    {
        Carbon::setTestNow('2026-12-10 10:00:00');
        $postal = $this->publishedCompetition(['title' => 'Postal soon', 'closes_at' => '2026-12-14 08:00:00', 'draw_at' => '2026-12-14 09:00:00', 'postal_entry_enabled' => true]);   // postal closes 12 Dec 08:00
        $late = $this->publishedCompetition(['title' => 'Overdue', 'slug' => 'overdue', 'closes_at' => '2026-12-11 08:00:00', 'draw_at' => '2026-12-11 09:00:00']);
        Carbon::setTestNow('2026-12-11 10:00:00');
        $late->update(['status' => 'closed']);

        $att = collect($this->actingAs($this->admin)->getJson('/api/admin/dashboard')->json('data.attention'))->keyBy('key');
        $this->assertSame(['Postal soon'], array_column($att['postal-deadline']['items'], 'label'));
        $this->assertSame(['Overdue'], array_column($att['awaiting-draw']['items'], 'label'));
    }

    public function test_dashboard_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
