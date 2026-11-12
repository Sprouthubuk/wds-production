<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\ReportController;
use App\Models\Competition;
use App\Models\DiscountCode;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;
    private User $staff;
    private Competition $c;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->fakeVerity();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'staff', 'name' => 'Sam Staff']);
        $this->c = $this->publishedCompetition(['title' => 'Test car', 'ticket_price_pence' => 1000, 'bundles' => [], 'total_tickets' => 500, 'max_per_person' => 100, 'postal_entry_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function online(string $email, int $qty, bool $optIn = false, ?string $code = null): void
    {
        $ref = $this->postJson('/api/checkout', array_filter([
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $this->c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
            'marketingOptIn' => $optIn, 'discountCode' => $code,
        ], fn ($v) => $v !== null))->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));
    }

    private function event(string $method, int $qty): void
    {
        $this->actingAs($this->staff)->postJson('/api/admin/event-sales', [
            'competitionId' => $this->c->id, 'qty' => $qty, 'name' => 'Pat Jones', 'email' => "pat-{$method}{$qty}@example.com",
            'dateOfBirth' => '1990-01-01', 'paymentMethod' => $method,
        ])->assertOk();
    }

    private function csv(string $type, string $query = '?from=2026-11-01&to=2026-11-30'): array
    {
        $res = $this->actingAs($this->admin)->get("/api/admin/reports/{$type}.csv{$query}")->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString("wds-{$type}-", $res->headers->get('Content-Disposition'));
        $body = ltrim($res->streamedContent(), "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_values(array_filter(explode("\n", $body))));
    }

    public function test_sales_by_competition_counts_each_channel_and_revenue(): void
    {
        $this->online('a@example.com', 3);
        $this->online('b@example.com', 2);
        $this->event('cash', 4);
        $this->event('card_reader', 1);
        app(PostalEntryService::class)->log($this->c, ['name' => 'Kim Post', 'address' => '1 St', 'date_of_birth' => '1990-01-01', 'email' => 'kim@example.com', 'received_on' => '2026-11-10 09:00'], $this->staff);

        $rows = $this->csv('sales');
        $this->assertSame(['Competition', 'Status', 'Online tickets', 'Online revenue (GBP)', 'Event tickets', 'Event revenue (GBP)', 'Postal entries', 'Total tickets', 'Total revenue (GBP)'], $rows[0]);
        $this->assertSame(['Test car', 'live', '5', '50.00', '5', '50.00', '1', '11', '100.00'], $rows[1]);

        $this->actingAs($this->admin)->getJson('/api/admin/reports/summary?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonPath('data.totals.tickets', 11)->assertJsonPath('data.totals.revenue', 100)
            ->assertJsonPath('data.sales.0.onlineTickets', 5);
    }

    public function test_the_date_range_is_whole_uk_days(): void
    {
        $this->online('a@example.com', 2);                  // 10 Nov
        Carbon::setTestNow('2026-11-20 23:30:00');           // 20 Nov in the UK (GMT)
        $this->online('b@example.com', 1);

        $this->assertSame('2', $this->csv('sales', '?from=2026-11-01&to=2026-11-19')[1][2]);
        $this->assertSame('1', $this->csv('sales', '?from=2026-11-20&to=2026-11-20')[1][2]);
        $this->assertCount(1, $this->csv('sales', '?from=2026-12-01&to=2026-12-31')); // header only
    }

    public function test_event_takings_by_staff_and_payment_method(): void
    {
        $this->event('cash', 2);
        $this->event('cash', 3);
        $this->event('card_reader', 1);

        $rows = $this->csv('event-takings');
        $this->assertSame(['Staff', 'Payment method', 'Orders', 'Tickets', 'Total (GBP)'], $rows[0]);
        $this->assertContains(['Sam Staff', 'Card reader', '1', '1', '10.00'], $rows);
        $this->assertContains(['Sam Staff', 'Cash', '2', '5', '50.00'], $rows);
    }

    public function test_postal_entries_accepted_and_rejected_with_reasons(): void
    {
        $svc = app(PostalEntryService::class);
        $base = ['address' => '1 St', 'date_of_birth' => '1990-01-01', 'received_on' => '2026-11-10 09:00'];
        $svc->log($this->c, $base + ['name' => 'Kim Post', 'email' => 'kim@example.com'], $this->staff);
        $svc->log($this->c, ['date_of_birth' => '2015-01-01'] + $base + ['name' => 'Young One', 'email' => 'young@example.com'], $this->staff);

        $rows = $this->csv('postal');
        $this->assertSame(['Received (UK)', 'Competition', 'Name', 'Email', 'Status', 'Ticket', 'Rejection reason'], $rows[0]);
        $this->assertSame('accepted', $rows[1][4]);
        $this->assertNotSame('', $rows[1][5]);
        $this->assertSame(['rejected', '', 'Entrant under 18'], array_slice($rows[2], 4));

        $this->actingAs($this->admin)->getJson('/api/admin/reports/summary?from=2026-11-01&to=2026-11-30')
            ->assertJsonPath('data.totals.postalAccepted', 1)->assertJsonPath('data.totals.postalRejected', 1)
            ->assertJsonPath('data.postalReasons.0.reason', 'Entrant under 18');
    }

    public function test_customer_export_only_includes_people_who_opted_in(): void
    {
        User::factory()->create(['email' => 'yes@example.com', 'first_name' => 'Yes', 'marketing_opt_in' => true, 'role' => 'customer']);
        User::factory()->create(['email' => 'no@example.com', 'marketing_opt_in' => false, 'role' => 'customer']);
        User::factory()->create(['email' => 'excluded@example.com', 'marketing_opt_in' => true, 'role' => 'customer', 'self_excluded_until' => now()->addYear()]);
        $this->online('guest-yes@example.com', 1, optIn: true);
        $this->online('guest-no@example.com', 1, optIn: false);
        NewsletterSubscriber::create(['email' => 'news@example.com']);
        NewsletterSubscriber::create(['email' => 'gone@example.com', 'unsubscribed_at' => now()]);
        NewsletterSubscriber::create(['email' => 'no@example.com']); // an account that said no wins

        $emails = array_column(array_slice($this->csv('customers'), 1), 0);
        sort($emails);
        $this->assertSame(['guest-yes@example.com', 'news@example.com', 'yes@example.com'], $emails);
    }

    public function test_discount_code_usage(): void
    {
        DiscountCode::create(['code' => 'LAUNCH10', 'type' => 'percent', 'value' => 10, 'active' => true]);
        DiscountCode::create(['code' => 'UNUSED', 'type' => 'amount', 'value' => 500, 'active' => true]);
        $this->online('a@example.com', 2, code: 'LAUNCH10');
        $this->online('b@example.com', 1, code: 'LAUNCH10');

        $rows = $this->csv('discounts');
        $this->assertCount(2, $rows);
        $this->assertSame(['LAUNCH10', '10%', '2', '3.00', '27.00', '2', 'yes'], $rows[1]);
    }

    public function test_cells_cant_become_spreadsheet_formulas_and_access_is_admin_only(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", ReportController::cell('=HYPERLINK("x")'));
        $this->assertSame("'+44", ReportController::cell('+44'));
        $this->assertSame("'@SUM(A1)", ReportController::cell('@SUM(A1)'));
        $this->assertSame('-12.50', ReportController::cell('-12.50'));
        $this->assertSame('Alex', ReportController::cell('Alex'));

        $this->actingAs($this->staff)->get('/api/admin/reports/sales.csv')->assertForbidden();
        $this->actingAs($this->admin)->get('/api/admin/reports/everything.csv')->assertNotFound();
    }
}
