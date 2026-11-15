<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\HarmFlag;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** The Responsible Play Policy promises (docs/code-compliance.md). */
class ResponsiblePlayComplianceTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comp(): Competition
    {
        return $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 1000, 'max_per_person' => null, 'ticket_price_pence' => 1000, 'bundles' => []]);
    }

    private function customer(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['role' => 'customer', 'email' => 'player@example.com', 'date_of_birth' => '1990-05-05']);
    }

    private function checkout(Competition $c, int $qty, ?User $as = null, string $email = 'player@example.com')
    {
        $req = $as ? $this->actingAs($as) : $this;

        return $req->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Pat Player', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
    }

    public function test_limits_allow_zero_apply_decreases_now_and_increases_after_72_hours(): void
    {
        $u = $this->customer(['limit_monthly_pence' => 5000]);
        $c = $this->comp();
        $this->actingAs($u)->postJson('/api/me/limits', ['monthly' => 0])->assertOk()->assertJsonPath('message', 'Limits saved and applied.');
        $this->assertSame(0, $u->fresh()->limit_monthly_pence);
        $this->checkout($c, 1, $u->fresh())->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'spend limit is £0'));

        $this->actingAs($u)->postJson('/api/me/limits', ['monthly' => 100])->assertOk()
            ->assertJsonPath('message', 'Saved. Lower limits apply now; increases take effect in 72 hours.');
        Carbon::setTestNow(now()->addHours(71));
        $this->checkout($c, 1, $u->fresh())->assertStatus(422);
        Carbon::setTestNow(now()->addHours(2));
        $this->checkout($c, 1, $u->fresh())->assertOk();
    }

    public function test_a_monthly_limit_can_be_set_at_registration(): void
    {
        $this->postJson('/api/auth/register', [
            'firstName' => 'Robin', 'lastName' => 'Reg', 'email' => 'robin@example.com', 'phone' => '07000', 'dateOfBirth' => '1990-01-01',
            'password' => 'a-long-password-1', 'ageConfirmed' => true, 'monthlyLimit' => 0,
        ])->assertSuccessful();
        $this->assertSame(0, User::where('email', 'robin@example.com')->value('limit_monthly_pence'));
    }

    public function test_self_exclusion_cannot_be_shortened_and_stops_entry_and_all_marketing(): void
    {
        $u = $this->customer(['marketing_opt_in' => true]);
        NewsletterSubscriber::create(['email' => $u->email]);
        $this->actingAs($u)->postJson('/api/me/self-exclude', ['months' => 12])->assertOk();
        $until = $u->fresh()->self_excluded_until;
        $this->actingAs($u)->postJson('/api/me/self-exclude', ['months' => 6])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, "can't be shortened"));
        $this->assertTrue($u->fresh()->self_excluded_until->equalTo($until));

        $this->actingAs($u)->putJson('/api/me', ['marketingOptIn' => true])->assertStatus(422);
        $this->assertNotNull(NewsletterSubscriber::where('email', $u->email)->value('unsubscribed_at'));
        $this->assertFalse(app(ReportService::class)->marketingCustomers(now()->subYear(), now()->addDay())->pluck('email')->contains($u->email));

        // Signed in or as a guest with the same email: no entry.
        $c = $this->comp();
        $this->checkout($c, 1, $u->fresh())->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->checkout($c, 1)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'self-excluded'));
        $this->assertSame('break_tools', HarmFlag::sole()->reason);
    }

    public function test_permanent_closure_is_self_service_and_blocks_sign_in_entry_and_marketing(): void
    {
        $u = $this->customer(['marketing_opt_in' => true]);
        $this->actingAs($u)->postJson('/api/me/close', [])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/me/close', ['confirm' => true])->assertOk();
        $u->refresh();
        $this->assertNotNull($u->closed_at);
        $this->assertFalse($u->marketing_opt_in);
        $this->assertSame(0, $u->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn ($m) => str_contains($m, 'closed'));
        $this->checkout($this->comp(), 1)->assertStatus(422)->assertJsonPath('message', 'This account is closed.');
    }

    public function test_harm_monitoring_flags_limit_hits_card_changes_and_breaks_for_an_admin_queue(): void
    {
        $c = $this->comp();
        $u = $this->customer(['limit_monthly_pence' => 1000]);
        foreach (range(1, 3) as $i) {
            $this->checkout($c, 2, $u)->assertStatus(422);
        }
        $flag = HarmFlag::where('reason', 'repeated_limit_hits')->sole();
        $this->assertSame('Hit a spend limit 3 times in 30 days.', $flag->summary);

        // Three different cards in 30 days (as a guest).
        $this->app['auth']->forgetGuards();
        foreach (['card-a', 'card-b', 'card-c'] as $ref) {
            $order = $this->checkout($c, 1, null, 'guest@example.com')->assertOk()->json('reference');
            app(CheckoutService::class)->handlePaymentResult(new PaymentResult($order, PaymentResult::PAID, 'p', null, 'card', 'debit', $ref));
        }
        $this->assertSame('Used 3 different cards in 30 days.', HarmFlag::where('reason', 'payment_method_changes')->sole()->summary);

        // A break is flagged too; the queue lets an admin note, lower a limit and block.
        $this->actingAs($u)->postJson('/api/me/break', ['days' => 7])->assertOk();
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        $this->actingAs($admin)->getJson('/api/admin/harm-flags')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($admin)->postJson("/api/admin/harm-flags/{$flag->id}", ['action' => 'lower_limit', 'monthlyLimit' => 20])->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/admin/harm-flags/{$flag->id}", ['action' => 'lower_limit', 'monthlyLimit' => 5])->assertOk();
        $this->assertSame(500, $u->fresh()->limit_monthly_pence);
        $this->actingAs($admin)->postJson("/api/admin/harm-flags/{$flag->id}", ['action' => 'contact', 'note' => 'Phoned, all fine'])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/harm-flags/{$flag->id}", ['action' => 'block'])->assertOk();
        $flag->refresh();
        $this->assertSame('blocked', $flag->status);
        $this->assertStringContainsString('Phoned, all fine', $flag->notes);
        $this->assertNotNull($u->fresh()->suspended_at);
    }

    public function test_admins_are_reminded_about_winners_not_contacted_within_48_hours(): void
    {
        User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com']);
        $c = $this->comp();
        $w = Winner::create(['competition_id' => $c->id, 'display_name' => 'Sam W', 'prize' => 'Car', 'drawn_at' => now()->subHours(47)]);
        $this->artisan('wds:winner-contact-reminders')->assertSuccessful();
        Mail::assertNothingOutgoing();

        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('wds:winner-contact-reminders')->assertSuccessful();
        $this->artisan('wds:winner-contact-reminders')->assertSuccessful(); // once only
        Mail::assertSent(\App\Mail\WinnerContactOverdue::class, 1);
        $this->assertTrue($w->fresh()->contactOverdue());
        $this->assertSame('2026-12-22', $w->fresh()->claimBy()->toDateString()); // drawn 8 Dec + 14 days

        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->postJson("/api/admin/winners/{$w->id}/contacted")->assertOk();
        $this->assertFalse($w->fresh()->contactOverdue());
    }
}
