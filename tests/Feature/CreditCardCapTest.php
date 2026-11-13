<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\CheckoutService;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
use App\Services\Payments\RefundResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** DCMS Voluntary Code: £250 per calendar month on credit cards per customer; no credit cards on instant wins. */
class CreditCardCapTest extends TestCase
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

    private function comp(array $attrs = []): Competition
    {
        return $this->publishedCompetition($attrs + ['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 1000, 'max_per_person' => null, 'ticket_price_pence' => 1000, 'bundles' => []]);
    }

    private function order(Competition $c, int $qty, string $email = 'cap@example.com'): string
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Casey Jones', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
    }

    private function pay(string $ref, ?string $cardType): Order
    {
        return app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, PaymentResult::PAID, 'p_'.$ref, null, 'card', $cardType));
    }

    public function test_credit_cards_are_capped_at_250_a_month_across_competitions_and_refunded_over_it(): void
    {
        $a = $this->comp();
        $b = $this->comp(['slug' => 'second-car', 'title' => 'Second car']);

        $this->assertSame('paid', $this->pay($this->order($a, 20), 'credit')->status);   // £200 on credit
        $this->assertSame('credit', Order::where('status', 'paid')->value('card_type'));

        // £60 more on credit, in a different competition: over £250, so refunded with nothing allocated.
        $refused = $this->pay($this->order($b, 6), 'credit');
        $this->assertSame(['refunded', 'credit'], [$refused->status, $refused->card_type]);
        $this->assertSame(0, Ticket::where('order_line_id', $refused->lines()->value('id'))->count());
        $this->assertStringContainsString('£250 a month', $refused->status_note);
        $this->assertStringContainsString('you have £50.00 left', $refused->status_note);
        $this->getJson("/api/orders/{$refused->reference}?key={$refused->access_key}")
            ->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.message', $refused->status_note);

        // Exactly up to the cap is fine, and debit cards don't count towards it.
        $this->assertSame('paid', $this->pay($this->order($b, 5), 'credit')->status);    // £250 total
        $this->assertSame('paid', $this->pay($this->order($b, 30), 'debit')->status);
        $this->assertSame('paid', $this->pay($this->order($b, 10), 'prepaid')->status);
        $this->assertSame('paid', $this->pay($this->order($b, 10), null)->status);       // unknown/not reported
        $this->assertSame('refunded', $this->pay($this->order($b, 1), 'credit')->status);

        // A different customer has their own cap; a new calendar month (UK) starts again.
        $this->assertSame('paid', $this->pay($this->order($b, 25, 'other@example.com'), 'credit')->status);
        Carbon::setTestNow('2027-01-01 00:30:00');
        $c = $this->comp(['slug' => 'january', 'title' => 'January', 'closes_at' => '2027-01-24 19:00:00', 'draw_at' => '2027-01-24 19:30:00']);
        $this->assertSame('paid', $this->pay($this->order($c, 25), 'credit')->status);
    }

    public function test_credit_cards_are_refused_on_instant_win_competitions(): void
    {
        $c = $this->comp(['instant_win' => true]);
        $refused = $this->pay($this->order($c, 1), 'credit');
        $this->assertSame('refunded', $refused->status);
        $this->assertStringContainsString("can't be used on instant-win", $refused->status_note);
        $this->assertSame('paid', $this->pay($this->order($c, 1), 'debit')->status);
    }

    public function test_when_the_refund_cant_be_automatic_it_shows_as_refund_needed(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class extends FakeGateway {
            public function refund(Order $order, int $amountPence, string $refundNumber, string $reason): RefundResult { return RefundResult::failed(null, 'no'); }
        });
        $c = $this->comp();
        $refused = $this->pay($this->order($c, 26), 'credit');
        $this->assertSame('cancelled', $refused->status);
        $this->assertNotNull($refused->paid_at);
        $this->assertSame(0, Ticket::count());
    }

    public function test_the_fake_payment_page_lets_you_choose_the_card_type(): void
    {
        config(['raffle.payments.driver' => 'fake']);
        $c = $this->comp();
        $ref = $this->order($c, 26);
        $url = URL::temporarySignedRoute('payments.fake', now()->addMinutes(30), ['order' => $ref, 'provider_reference' => 'x', 'return' => 'http://localhost/back']);
        $this->get($url.'&card_type=credit')->assertRedirect('http://localhost/back');
        $this->assertSame(['refunded', 'credit'], [Order::where('reference', $ref)->value('status'), Order::where('reference', $ref)->value('card_type')]);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }
}
