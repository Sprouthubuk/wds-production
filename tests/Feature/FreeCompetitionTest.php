<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Mail\VerifyEmail;
use App\Models\Competition;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Sprouthub\Verity\Models\EntryGroup;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Free competitions (£0): one online entry per verified account, no basket or payment, a normal Verity draw. */
class FreeCompetitionTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
    }

    public function test_a_verified_customer_enters_once_and_gets_the_next_ticket_number(): void
    {
        $c = $this->freeCompetition();
        $sam = $this->customer();

        $res = $this->enter($c, $sam, ['marketingOptIn' => true])->assertCreated()->assertJsonStructure(['reference', 'redirectUrl']);
        $order = Order::where('reference', $res->json('reference'))->firstOrFail();
        $this->assertSame(['paid', 0, 'free'], [$order->status, $order->total_pence, $order->payment_method]);
        $this->assertSame([1], Ticket::where('competition_id', $c->id)->where('user_id', $sam->id)->pluck('number')->all());
        $this->assertTrue($sam->fresh()->marketing_opt_in);
        Mail::assertQueued(TicketsIssued::class);
        // Verity records it as a free entry in the normal draw.
        $this->assertSame('free', EntryGroup::query()->latest('id')->value('source'));

        // Second click: refused, still one ticket.
        $this->enter($c, $sam)->assertStatus(422)->assertJsonPath('message', "You've already entered {$c->title}. Good luck!");
        $this->assertSame(1, Ticket::where('competition_id', $c->id)->count());

        // The next person gets the next number.
        $this->enter($c, $this->customer('kim@example.com'))->assertCreated();
        $this->assertSame([1, 2], Ticket::where('competition_id', $c->id)->orderBy('number')->pluck('number')->all());
    }

    public function test_marketing_opt_in_is_off_unless_ticked(): void
    {
        $sam = $this->customer();
        $this->enter($this->freeCompetition(), $sam)->assertCreated();
        $this->assertFalse((bool) $sam->fresh()->marketing_opt_in);
    }

    public function test_the_usual_checks_apply(): void
    {
        $c = $this->freeCompetition();

        $this->postJson("/api/competitions/{$c->id}/free-entry", ['ageConfirmed' => true, 'termsAccepted' => true])->assertUnauthorized();
        $this->enter($c, $this->customer(), ['ageConfirmed' => false])->assertStatus(422);
        $this->enter($c, $this->customer('t@example.com'), ['termsAccepted' => false])->assertStatus(422);

        $unverified = $this->customer('new@example.com', ['email_verified_at' => null]);
        $this->enter($c, $unverified)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Verify your email'));

        $this->enter($c, $this->customer('young@example.com', ['date_of_birth' => now()->subYears(17)->toDateString()]))
            ->assertStatus(422)->assertJsonPath('message', 'You must be 18 or over to enter.');
        $this->enter($c, $this->customer('break@example.com', ['self_excluded_until' => now()->addMonths(6)]))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'self-excluded'));

        $this->assertSame(0, Ticket::where('competition_id', $c->id)->count());
    }

    public function test_free_competitions_never_go_through_the_basket_or_the_event_till(): void
    {
        $c = $this->freeCompetition();
        $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '07000000000', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertStatus(422)->assertJsonPath('message', "{$c->title} is free to enter: use Enter free on its page.");

        $staff = User::factory()->create(['role' => 'staff']);
        $ids = collect($this->actingAs($staff)->getJson('/api/admin/event-sales/competitions')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($c->id, $ids);
    }

    public function test_a_paid_competition_cannot_be_entered_free(): void
    {
        $paid = $this->publishedCompetition();
        $this->enter($paid, $this->customer())->assertStatus(422);
    }

    public function test_admin_can_make_a_free_competition_but_not_with_bundles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['title' => 'Free table', 'category' => 'merch', 'status' => 'draft', 'totalTickets' => 1000, 'maxPerPerson' => 1];
        $this->actingAs($admin)->postJson('/api/admin/competitions', $base + ['ticketPrice' => 0])->assertCreated()->assertJsonPath('data.ticketPrice', 0);
        $this->actingAs($admin)->postJson('/api/admin/competitions', $base + ['slug' => 'free-2', 'ticketPrice' => 0, 'bundles' => [['qty' => 3, 'price' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('bundles');
        $this->actingAs($admin)->postJson('/api/admin/competitions', $base + ['slug' => 'free-3', 'ticketPrice' => 0.05])->assertStatus(422);
    }

    public function test_email_verification(): void
    {
        Mail::fake();
        $this->postJson('/api/auth/register', [
            'firstName' => 'Sam', 'lastName' => 'Taylor', 'email' => 'Sam@Example.com', 'phone' => '07000000000', 'dateOfBirth' => '1990-01-01',
            'password' => 'a-long-password-1', 'ageConfirmed' => true,
        ])->assertCreated();
        $sam = User::where('email', 'sam@example.com')->firstOrFail();
        $this->assertFalse($sam->hasVerifiedEmail());
        Mail::assertQueued(VerifyEmail::class, fn ($m) => $m->hasTo('sam@example.com'));

        // A tampered or unsigned link does nothing.
        $this->get('/api/auth/verify-email/'.$sam->id.'/'.sha1('sam@example.com'))->assertForbidden();
        $this->assertFalse($sam->fresh()->hasVerifiedEmail());

        $this->get(VerifyEmail::url($sam))->assertRedirectContains('/account?email=verified');
        $this->assertTrue($sam->fresh()->hasVerifiedEmail());

        // Changing the email means confirming the new one.
        $this->actingAs($sam->fresh())->putJson('/api/me', ['email' => 'sam.t@example.com'])->assertOk()->assertJsonPath('data.emailVerified', false);
        $this->get(URL::temporarySignedRoute('auth.verify-email', now()->addHour(), ['user' => $sam->id, 'hash' => sha1('sam@example.com')]))
            ->assertRedirectContains('email=link-invalid');
        $this->assertFalse($sam->fresh()->hasVerifiedEmail());
    }

    private function enter(Competition $c, User $user, array $overrides = [])
    {
        return $this->actingAs($user)->postJson("/api/competitions/{$c->id}/free-entry", $overrides + ['ageConfirmed' => true, 'termsAccepted' => true]);
    }

    private function freeCompetition(): Competition
    {
        return $this->publishedCompetition(['title' => 'Bedside engine table', 'category' => 'merch', 'ticket_price_pence' => 0, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 1, 'event_sales_enabled' => false]);
    }

    private function customer(string $email = 'sam@example.com', array $overrides = []): User
    {
        return User::factory()->create($overrides + ['email' => $email, 'role' => 'customer', 'date_of_birth' => '1990-01-01', 'email_verified_at' => now(), 'marketing_opt_in' => false]);
    }
}
