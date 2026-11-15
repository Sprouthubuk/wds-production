<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** DRAFT (item 9): the state behind the live reveal screen. */
class RevealScreenTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_reveal_state_follows_the_draw_and_never_shows_the_winner_early(): void
    {
        $this->fakeVerity();
        $admin = User::factory()->create(['role' => 'admin']);
        Carbon::setTestNow('2026-10-20 12:00:00');
        $c = $this->publishedCompetition(['draw_method' => 'external', 'draw_tool_name' => 'random.org', 'draw_tool_url' => 'https://www.random.org/integers/', 'closes_at' => '2026-10-30 20:00:00', 'draw_at' => '2026-10-30 20:30:00', 'postal_closes_at' => '2026-10-28 20:00:00']);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        $url = "/api/admin/competitions/{$c->id}/reveal";
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.status', 'live')->assertJsonPath('data.method', 'external')
            ->assertJsonPath('data.entries', 3)->assertJsonPath('data.ticket', null)->assertJsonPath('data.winner', null);

        Carbon::setTestNow('2026-10-30 19:30:00');
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/close-entries")->assertOk();
        $sealed = $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.ticket', null)->json('data');
        $this->assertNotEmpty($sealed['entriesHash']);
        $this->assertNull($sealed['round']);

        $this->actingAs($admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 2, 'livestreamUrl' => 'https://www.youtube.com/live/abc'])->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.status', 'drawn')->assertJsonPath('data.ticket', '0002')
            ->assertJsonPath('data.winner', 'Alex S.')->assertJsonPath('data.published', false);

        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->getJson($url)->assertForbidden();
        $draft = Competition::create(['slug' => 'd', 'title' => 'D', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 100, 'bundles' => [], 'total_tickets' => 10, 'max_per_person' => 5, 'closes_at' => now()->addDay(), 'draw_at' => now()->addDay()]);
        $this->actingAs($admin)->getJson("/api/admin/competitions/{$draft->id}/reveal")->assertNotFound();
    }
}
