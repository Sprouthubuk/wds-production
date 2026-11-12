<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Models\Competition;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Optional site-wide monthly spend cap: off by default, set in Admin → Settings. */
class SiteSpendCapTest extends TestCase
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

    private function buy(Competition $c, int $qty, string $email = 'cap@example.com')
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Casey Jones', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ]);
    }

    public function test_the_site_wide_cap_is_off_by_default_and_applies_per_customer_per_month_when_switched_on(): void
    {
        $this->assertFalse(SettingsPayload::get()['siteSpendCapEnabled']);
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 1000, 'max_per_person' => null, 'ticket_price_pence' => 1000, 'bundles' => []]);
        $ref = $this->buy($c, 60)->assertOk()->json('reference'); // £600 with the cap off
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, PaymentResult::PAID, 'p', null, 'card', 'debit'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['siteSpendCapEnabled' => true, 'siteSpendCapMonthly' => null])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/settings', ['siteSpendCapEnabled' => true, 'siteSpendCapMonthly' => 650])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->buy($c, 6)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'you have £50.00 left'));
        $this->buy($c, 5)->assertOk();                         // exactly up to the cap (reserved while paying)
        $this->buy($c, 1)->assertStatus(422);
        $this->buy($c, 6, 'someone-else@example.com')->assertOk();
        Carbon::setTestNow('2027-01-01 00:30:00');             // a new calendar month (UK)
        $c->update(['closes_at' => '2027-01-24 19:00:00', 'draw_at' => '2027-01-24 19:30:00']);
        $this->buy($c, 6)->assertOk();
    }
}
