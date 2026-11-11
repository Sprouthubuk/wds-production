<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

class EntrantTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
    }

    private function competition(int $maxPerPerson = 10): Competition
    {
        return $this->publishedCompetition([
            'slug' => 'test', 'title' => 'Test car', 'category' => 'cars',
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => $maxPerPerson,
            'closes_at' => now()->addWeeks(2), 'draw_at' => now()->addWeeks(2)->addHour(), 'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ]);
    }

    private function buyOnline(Competition $c, int $qty, string $email)
    {
        $r = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '07000000000', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
            'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
        if ($r->isOk()) {
            $order = Order::where('reference', $r->json('reference'))->firstOrFail();
            app(CheckoutService::class)->handlePaymentResult(new PaymentResult($order->reference, 'paid'));
        }

        return $r;
    }

    private function buyAtEvent(Competition $c, int $qty, string $email): void
    {
        app(CheckoutService::class)->start([
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => null, 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]],
        ], null, 'event', User::factory()->create(['role' => 'staff']), 'cash');
    }

    private function enterByPost(Competition $c, string $email)
    {
        return app(PostalEntryService::class)->log($c, [
            'name' => 'Alex Smith', 'address' => '1 High St', 'date_of_birth' => '1990-05-05',
            'email' => $email, 'received_on' => now()->toDateString(),
        ], User::factory()->create(['role' => 'staff']));
    }

    public function test_one_entrant_per_normalised_email_across_all_channels(): void
    {
        Mail::fake();
        $c = $this->competition();

        $this->buyOnline($c, 2, 'Alex@Example.com')->assertOk();
        $this->enterByPost($c, '  alex@example.COM ');
        $this->buyAtEvent($c, 1, 'ALEX@example.com');

        $this->assertSame(1, Entrant::count());
        $entrant = Entrant::first();
        $this->assertSame('alex@example.com', $entrant->email);
        $this->assertSame('entrant:'.$entrant->id, $entrant->ownerRef());

        $tickets = Ticket::orderBy('number')->get();
        $this->assertSame(['online', 'online', 'postal', 'event'], $tickets->pluck('channel')->all());
        $this->assertSame([$entrant->id], $tickets->pluck('entrant_id')->unique()->values()->all());
        $this->assertSame(2, Order::where('entrant_id', $entrant->id)->count());
        $this->assertSame(1, $entrant->postalEntries()->count());
    }

    public function test_per_person_cap_counts_per_entrant_across_channels(): void
    {
        Mail::fake();
        $c = $this->competition(maxPerPerson: 3);

        $this->buyOnline($c, 2, 'alex@example.com')->assertOk();
        $this->assertSame('allocated', $this->enterByPost($c, 'ALEX@example.com')->status);

        // 3 of 3 used: postal and online are both refused for the same person, whatever the email's case.
        $refused = $this->enterByPost($c, 'Alex@Example.com');
        $this->assertSame('rejected', $refused->status);
        $this->assertSame('Maximum entries per person reached', $refused->rejection_reason);
        $this->buyOnline($c, 1, 'alex@EXAMPLE.com')->assertStatus(422);

        // A different person is unaffected.
        $this->buyOnline($c, 3, 'sam@example.com')->assertOk();
        $this->assertSame(2, Entrant::count());
    }

    public function test_entrant_for_email_reuses_the_existing_row(): void
    {
        $a = Entrant::forEmail('Jo@Example.com');
        $b = Entrant::forEmail(' jo@example.com ');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Entrant::count());
        $this->assertNull(Entrant::findByEmail('nobody@example.com'));
    }
}
