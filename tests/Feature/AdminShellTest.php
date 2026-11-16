<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** The Admin shell's read-only endpoints: the environment badge and the top bar's quick search. */
class AdminShellTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_environment_is_production_only_for_the_real_production_address(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        config(['wds.production_app_urls' => ['https://api.wdscompetitions.co.uk']]);

        foreach (['https://api.wdscompetitions.co.uk' => true, 'https://API.wdscompetitions.co.uk/' => true,
            'https://staging-api.wdscompetitions.co.uk' => false, 'http://127.0.0.1:8000' => false] as $url => $production) {
            config(['app.url' => $url]);
            $this->actingAs($staff)->getJson('/api/admin/environment')->assertOk()
                ->assertExactJson(['data' => ['production' => $production, 'label' => $production ? 'Production' : 'Staging']]);
        }
    }

    public function test_environment_needs_staff(): void
    {
        $this->getJson('/api/admin/environment')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/admin/environment')->assertForbidden();
    }

    public function test_quick_search_finds_orders_customers_and_tickets(): void
    {
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'finder@example.com', 'name' => 'Fin Der', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 2]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->json('reference');
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();
        $order = Order::where('reference', $ref)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com', 'name' => 'Boss']);
        User::factory()->create(['role' => 'customer', 'email' => 'finder@example.com', 'name' => 'Fin Der']);

        $byRef = $this->actingAs($admin)->getJson('/api/admin/search?q='.strtolower(substr($ref, 4)))->assertOk()->json('data');
        $this->assertSame([$order->id], array_column($byRef['orders'], 'id'));

        $byEmail = $this->actingAs($admin)->getJson('/api/admin/search?q=finder@')->json('data');
        $this->assertSame([$ref], array_column($byEmail['orders'], 'reference'));
        $this->assertSame(['finder@example.com'], array_column($byEmail['customers'], 'email'));

        $tickets = $this->actingAs($admin)->getJson('/api/admin/search?q=%23002')->json('data.tickets');
        $this->assertSame([['0002', $ref, false]], array_map(fn ($t) => [$t['number'], $t['orderReference'], $t['withdrawn']], $tickets));

        $this->actingAs($admin)->getJson('/api/admin/search?q=x')->assertJsonPath('data.orders', [])->assertJsonPath('data.tickets', []);
        $this->actingAs($admin)->getJson('/api/admin/search?q=100%25')->assertOk()->assertJsonPath('data.orders', []);   // % is literal
    }

    public function test_quick_search_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))->getJson('/api/admin/search?q=WDS')->assertForbidden();
    }
}
