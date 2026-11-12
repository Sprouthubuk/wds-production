<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** max_per_person is optional: null = no per-person cap (only the tickets left limit an order). */
class NoPerPersonCapTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
    }

    public function test_with_no_cap_one_person_can_buy_up_to_what_is_left(): void
    {
        $c = $this->publishedCompetition(['total_tickets' => 120, 'max_per_person' => null, 'ticket_price_pence' => 100, 'bundles' => []]);
        $this->assertNull(VerityCompetition::where('ref', $c->verity_ref)->value('max_per_person'));
        $this->getJson("/api/competitions/{$c->slug}")->assertOk()->assertJsonPath('data.maxPerPerson', null);

        $ref = $this->checkout($c, 100)->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));
        $this->assertSame(100, Ticket::where('email', 'a@example.com')->count());

        // Still limited by what's left.
        $this->checkout($c, 21)->assertStatus(422)->assertJsonFragment(['message' => "Only 20 tickets left for {$c->title}."]);
    }

    public function test_a_cap_still_applies_when_set(): void
    {
        $c = $this->publishedCompetition(['total_tickets' => 120, 'max_per_person' => 5, 'ticket_price_pence' => 100, 'bundles' => []]);
        $this->checkout($c, 6)->assertStatus(422);
    }

    public function test_admin_can_leave_the_maximum_empty_or_clear_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($admin)->postJson('/api/admin/competitions', [
            'title' => 'No cap', 'category' => 'parts', 'status' => 'draft', 'ticketPrice' => 5, 'totalTickets' => 495,
        ])->assertCreated()->assertJsonPath('data.maxPerPerson', null)->json('data.id');

        $this->actingAs($admin)->putJson("/api/admin/competitions/{$id}", ['maxPerPerson' => 10])->assertOk()->assertJsonPath('data.maxPerPerson', 10);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$id}", ['maxPerPerson' => null])->assertOk()->assertJsonPath('data.maxPerPerson', null);
        $this->assertNull(Competition::findOrFail($id)->max_per_person);
    }

    private function checkout(Competition $c, int $qty)
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '07000000000', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
            'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
    }
}
