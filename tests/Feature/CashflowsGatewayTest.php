<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Models\Competition;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PaymentEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payments\BinLookup;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Facades\Verity;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Cashflows Hosted Checkout Pages against a fake Cashflows (Http::fake): the request format and Hash, the status
 * mapping, notifications, refunds, card type detection and redaction. The cases follow the integration test
 * matrix in docs/cashflows-testing.md (test cards; the cardholder name sets the outcome).
 */
class CashflowsGatewayTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private const KEY = 'test-api-key-123';          // a made-up key: never a real one
    private const TOKEN = 'notify-token-for-tests';

    /** @var list<Request> */
    private array $sent = [];
    /** What GET …/payments/{ref} answers, per payment reference: [httpStatus, json]. */
    private array $payments = [];
    /** What GET …/payment-jobs/{job} answers, per job reference: [httpStatus, json]. */
    private array $jobs = [];
    /** What POST …/refunds answers: [httpStatus, json]. */
    private array $refundReply = [201, ['data' => ['reference' => 'RF1', 'status' => 'Completed']]];
    private array $createReply = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'cashflows', 'raffle.payments.cashflows' => [
            'configuration_id' => 'CONFIG-1', 'api_key' => self::KEY, 'base_url' => 'https://gateway-int.cashflows.com',
            'webhook_token' => self::TOKEN, 'webhook_ips' => '', 'unknown_card_as_credit' => true, 'send_expiry' => true,
            'log_first_payments' => 20, 'timeout' => 5,
        ], 'raffle.frontend_url' => 'https://staging.example.test']);
        $this->createReply = [201, [
            'data' => ['reference' => 'JOB1', 'order' => ['orderNumber' => 'x', 'payments' => [['reference' => 'PAY1', 'status' => 'Pending']]]],
            'links' => ['action' => ['url' => 'https://gateway-int.cashflows.com/payment?ref=JOB1', 'type' => 'text/html']],
        ]];
        Http::fake(function (Request $request) {
            $this->sent[] = $request;
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/api/gateway/payment-jobs') {
                return Http::response($this->createReply[1], $this->createReply[0]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/refunds')) {
                return Http::response($this->refundReply[1], $this->refundReply[0]);
            }
            if ($request->method() === 'GET' && preg_match('#/payment-jobs/([^/]+)/payments/([^/]+)$#', $path, $m)) {
                [$status, $json] = $this->payments[$m[2]] ?? [404, ['errors' => ['not found']]];

                return Http::response($json, $status);
            }
            if ($request->method() === 'GET' && preg_match('#/payment-jobs/([^/]+)$#', $path, $m)) {
                [$status, $json] = $this->jobs[$m[1]] ?? [404, ['errors' => ['not found']]];

                return Http::response($json, $status);
            }

            return Http::response(['errors' => ['unexpected']], 400);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function comp(array $attrs = []): Competition
    {
        return $this->publishedCompetition($attrs + ['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00',
            'total_tickets' => 1000, 'max_per_person' => null, 'ticket_price_pence' => 1000, 'bundles' => []]);
    }

    private function checkout(Competition $c, int $qty, string $email = 'luke@example.com', string $name = 'Luke Skywalker'): Order
    {
        $res = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => $name, 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk();
        $this->assertSame('https://gateway-int.cashflows.com/payment?ref=JOB1', $res->json('redirectUrl'));

        return Order::where('reference', $res->json('reference'))->firstOrFail();
    }

    /** A payment as Cashflows returns it; $details goes in attributes.cashFlowsAcquiringDetails (a JSON string). */
    private function payment(string $status, string $amount, ?array $details = null, string $ref = 'PAY1'): void
    {
        $data = ['reference' => $ref, 'status' => $status, 'amountToCollect' => $amount, 'refunds' => []];
        if ($details !== null) {
            $data['attributes'] = ['cashFlowsAcquiringDetails' => json_encode($details)];
        }
        $this->payments[$ref] = [200, ['data' => $data]];
    }

    private function debit(): array
    {
        return ['TruncatedCardNumber' => '840068******0009', 'FundingType' => 'Debit', 'Message' => 'Authorised', 'Status' => 'Successful', 'AuthCode' => '123456'];
    }

    private function credit(): array
    {
        return ['TruncatedCardNumber' => '400000******0002', 'FundingType' => 'Credit', 'Message' => 'Authorised', 'Status' => 'Successful'];
    }

    /** The order page, as the customer's browser polls it (each poll re-queries Cashflows). */
    private function poll(Order $order): \Illuminate\Testing\TestResponse
    {
        $this->travel(5)->seconds();

        return $this->getJson("/api/orders/{$order->reference}?key={$order->access_key}")->assertOk();
    }

    private function notify(array $body, ?string $token = self::TOKEN): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments/cashflows/notify'.($token === null ? '' : '?token='.$token), $body);
    }

    private function sentTo(string $method, string $pathEnd): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => $r->method() === $method && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $pathEnd)));
    }

    // ------------------------------------------------------------------ the Hash and the payment job

    public function test_the_hash_is_lowercase_hex_sha512_of_the_key_and_the_exact_body(): void
    {
        // Fixed vectors, computed independently (Python hashlib.sha512).
        $this->assertSame('cf7cacf0b2477638b4bced52ca09543b442875a3fc9bbda079b3561ccb8d03a6048021274ea72378a83e4271300e7390a4c5f348a5265582f864b1eefa4ef163',
            CashflowsGateway::hash(self::KEY));
        $this->assertSame('dff7d278193532124446616fd9965eab2602acccb6e88c1dd01625ab26af277fe4e4a4ac2b0cabe4d2fa4168ede4e40701052d697cf510fb412380fb17a178e1',
            CashflowsGateway::hash(self::KEY, '{"amountToCollect":"10.00","currency":"GBP"}'));
        $this->assertSame('{"amountToCollect":"10.00","currency":"GBP"}', CashflowsGateway::encode(['amountToCollect' => '10.00', 'currency' => 'GBP']));
        $this->assertSame('f27977c6d29ec9770dc254d8f3578e3a0d4fd3ddbc0e1186a0406ec2dff848aef4f11487da9128a4067c3230f4d5376c92d40fad1a0833cd52844dfb6d95a92c',
            CashflowsGateway::hash(self::KEY, CashflowsGateway::encode(['a' => '£/'])));
    }

    public function test_checkout_creates_a_payment_job_and_sends_the_customer_to_the_payment_page(): void
    {
        $order = $this->checkout($this->comp(), 3, 'luke@example.com', 'Luke Skywalker');

        [$create] = $this->sentTo('POST', '/api/gateway/payment-jobs');
        $body = $create->body();
        // The exact string sent is the one hashed; compact JSON, no pretty-printing.
        $this->assertSame([hash('sha512', self::KEY.$body)], $create->header('Hash'));
        $this->assertSame(['CONFIG-1'], $create->header('ConfigurationId'));
        $this->assertStringNotContainsString("\n", $body);
        $sent = json_decode($body, true);
        $this->assertSame('30.00', $sent['amountToCollect']);
        $this->assertSame(['GBP', 'en_GB'], [$sent['currency'], $sent['locale']]);
        $this->assertSame($order->reference, $sent['order']['orderNumber']);
        $this->assertSame(['firstName' => 'Luke', 'lastName' => 'Skywalker'], $sent['order']['billingAddress']);
        $this->assertSame('luke@example.com', $sent['order']['billingIdentity']['emailAddress']);
        $orderUrl = "https://staging.example.test/order/{$order->reference}?key={$order->access_key}";
        $this->assertSame("{$orderUrl}&result=success", $sent['parameters']['returnUrlSuccess']);
        $this->assertSame("{$orderUrl}&result=failed", $sent['parameters']['returnUrlFailed']);
        $this->assertSame("{$orderUrl}&result=cancelled", $sent['parameters']['returnUrlCancelled']);
        // The payment page expires with the ticket reservation (RAFFLE_RESERVATION_MINUTES).
        $this->assertSame('2026-12-10T12:15:00Z', $sent['expirationDateTimeUtc']);

        $this->assertSame(['cashflows', 'JOB1', 'PAY1', 'pending'], [$order->payment_provider, $order->payment_job_reference, $order->payment_reference, $order->status]);
        $this->assertSame('created', PaymentEvent::where('order_id', $order->id)->value('kind'));
    }

    public function test_a_get_is_hashed_from_the_api_key_alone(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $this->payment('Pending', '10.00');
        $this->poll($order);

        [$get] = $this->sentTo('GET', '/payments/PAY1');
        $this->assertSame([hash('sha512', self::KEY)], $get->header('Hash'));
        $this->assertSame('', $get->body());
    }

    public function test_if_the_payment_page_cannot_be_created_the_reservation_is_released(): void
    {
        $this->createReply = [400, ['errors' => [['code' => 'InvalidRequest']]]];
        $c = $this->comp();
        $this->postJson('/api/checkout', [
            'customer' => ['email' => 'a@example.com', 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 2]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertStatus(422)->assertJsonPath('message', "We couldn't open the payment page just now. No money has been taken. Please try again in a minute.");
        $this->assertSame(['cancelled', null], [Order::value('status'), Order::value('reserved_until')]);
        $this->assertSame(0, $c->fresh()->reservedPending());
    }

    // ------------------------------------------------------------------ the test matrix

    public function test_1_visa_debit_paid_tickets_allocated_card_type_debit(): void
    {
        $order = $this->checkout($this->comp(), 3);
        $this->payment('Pending', '30.00');
        $this->poll($order)->assertJsonPath('data.status', 'pending');   // "Confirming your payment…"
        $this->payment('Paid', '30.00', $this->debit());
        $this->poll($order)->assertJsonPath('data.status', 'paid');

        $order->refresh();
        $this->assertSame(['debit', 'gateway'], [$order->card_type, $order->card_type_source]);
        $this->assertSame(3, Ticket::count());
        $this->assertStringStartsWith('cf:', $order->card_ref);
        Mail::assertQueued(TicketsIssued::class, 1);
    }

    public function test_2_visa_credit_paid_and_counts_towards_the_250_cap(): void
    {
        $order = $this->checkout($this->comp(), 5);
        $this->payment('Paid', '50.00', $this->credit());
        $this->poll($order)->assertJsonPath('data.status', 'paid');
        $this->assertSame(['credit', 'gateway'], [$order->fresh()->card_type, $order->fresh()->card_type_source]);
        $this->assertSame(5000, app(\App\Services\CreditCardCap::class)->creditSpentThisMonth(new Order(['email' => 'luke@example.com'])));
    }

    public function test_3_a_credit_card_payment_over_250_in_the_month_is_refunded_automatically(): void
    {
        $c = $this->comp();
        $first = $this->checkout($c, 20);                        // £200 on credit
        $this->payment('Paid', '200.00', $this->credit());
        $this->poll($first)->assertJsonPath('data.status', 'paid');

        $this->createReply[1]['data']['reference'] = 'JOB2';
        $this->createReply[1]['data']['order']['payments'][0]['reference'] = 'PAY2';
        $second = $this->checkout($c, 6);                       // £60 more: over £250
        $this->payment('Paid', '60.00', $this->credit(), 'PAY2');
        $res = $this->poll($second)->assertJsonPath('data.status', 'refunded');
        $this->assertStringContainsString('£250 a month', $res->json('data.message'));
        $this->assertSame([], $res->json('data.lines.0.ticketNumbers'));

        [$refund] = $this->sentTo('POST', '/api/gateway/payment-jobs/JOB2/payments/PAY2/refunds');
        $this->assertSame(['amountToRefund' => '60.00', 'refundNumber' => "{$second->reference}-R1"], json_decode($refund->body(), true));
        $this->assertSame([hash('sha512', self::KEY.$refund->body())], $refund->header('Hash'));
        $this->assertSame(['completed', true], [OrderRefund::value('status'), OrderRefund::value('automatic')]);
        $this->assertSame(6000, $second->fresh()->refunded_pence);
        $this->assertSame(20, Ticket::count());
    }

    public function test_4_a_3ds_challenge_stays_pending_then_completes(): void
    {
        $order = $this->checkout($this->comp(), 1, 'han@example.com', 'Han Solo');
        $this->payment('Pending', '10.00');
        $this->poll($order)->assertJsonPath('data.status', 'pending');
        $this->poll($order)->assertJsonPath('data.status', 'pending');
        $this->payment('Paid', '10.00', $this->debit());
        $this->poll($order)->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Ticket::count());
    }

    public function test_5_and_6_declined_or_technical_failure_releases_the_reservation_with_a_clear_message(): void
    {
        foreach (['Ben Kenobi' => 'PAYB', 'Darth Vader' => 'PAYD'] as $name => $ref) {
            $this->createReply[1]['data']['order']['payments'][0]['reference'] = $ref;
            $order = $this->checkout($c ??= $this->comp(), 4, strtolower(str_replace(' ', '.', $name)).'@example.com', $name);
            $this->assertSame(4, $c->fresh()->reservedPending());
            $this->payment('Failed', '40.00', ['Status' => 'Declined'], $ref);
            $res = $this->poll($order)->assertJsonPath('data.status', 'failed');
            $this->assertStringContainsString("Your payment didn't go through", $res->json('data.message'));
            $this->assertNull($order->fresh()->reserved_until);
            $this->assertSame(0, $c->fresh()->reservedPending());
        }
        $this->assertSame(0, Ticket::count());
    }

    public function test_6_cashflows_unreachable_leaves_the_order_pending_and_the_page_working(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $this->payments['PAY1'] = [500, ['errors' => ['boom']]];
        $this->poll($order)->assertJsonPath('data.status', 'pending');
        $this->assertSame('error', PaymentEvent::where('order_id', $order->id)->latest('id')->value('kind'));
    }

    public function test_7_cancelling_on_the_payment_page_releases_the_reservation(): void
    {
        $c = $this->comp();
        $order = $this->checkout($c, 2);
        $this->payment('Cancelled', '20.00');
        // The cancelled return URL proves nothing; the page asks Cashflows.
        $this->travel(5)->seconds();
        $res = $this->getJson("/api/orders/{$order->reference}?paymentjobref=JOB1&paymentref=PAY1&ordernumber={$order->reference}&status=cancelled")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertStringContainsString('You cancelled the payment', $res->json('data.message'));
        $this->assertSame(0, $c->fresh()->reservedPending());

        // Expired payment page: same, with its own message.
        $this->createReply[1]['data']['order']['payments'][0]['reference'] = 'PAYX';
        $expired = $this->checkout($c, 1, 'x@example.com');
        $this->payment('Expired', '10.00', null, 'PAYX');
        $this->assertStringContainsString('expired', $this->poll($expired)->json('data.message'));
    }

    public function test_the_payment_job_reference_only_opens_its_own_order(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $this->getJson("/api/orders/{$order->reference}?paymentjobref=JOB2")->assertNotFound();
        $this->getJson("/api/orders/{$order->reference}")->assertNotFound();
    }

    public function test_8_a_payment_after_the_reservation_expired_is_honoured_while_open_and_refunded_once_closed(): void
    {
        $c = $this->comp();
        $order = $this->checkout($c, 2);
        $this->travel(20)->minutes();
        Artisan::call('orders:expire');
        $this->assertSame('cancelled', $order->fresh()->status);

        // Still open, tickets still there: the late payment is honoured.
        $this->payment('Paid', '20.00', $this->debit());
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'])->assertOk();
        $this->assertSame('paid', $order->fresh()->status);

        // The competition has closed: the money goes back automatically, no tickets.
        $this->createReply[1]['data']['reference'] = 'JOB2';
        $this->createReply[1]['data']['order']['payments'][0]['reference'] = 'PAY2';
        $late = $this->checkout($c, 1, 'late@example.com');
        $this->travel(20)->minutes();
        Artisan::call('orders:expire');
        Verity::close($c->verity_ref, 'Closed early for the late payment test');
        $this->payment('Paid', '10.00', $this->debit(), 'PAY2');
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB2', 'paymentReference' => 'PAY2'])->assertOk();

        $late->refresh();
        $this->assertSame(['refunded', 1000], [$late->status, $late->refunded_pence]);
        $this->assertStringContainsString("We've refunded your payment in full", $late->status_note);
        $this->assertCount(1, $this->sentTo('POST', '/payments/PAY2/refunds'));
        $this->assertSame(0, Ticket::whereIn('order_line_id', $late->lines()->pluck('id'))->count());
    }

    public function test_a_good_payment_after_a_declined_attempt_in_the_same_job_is_honoured(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $this->payment('Failed', '10.00');
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'])->assertOk();
        $this->assertSame('failed', $order->fresh()->status);

        $this->payment('Paid', '10.00', $this->debit(), 'PAY1B');
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1B'])->assertOk();
        $this->assertSame(['paid', 'PAY1B'], [$order->fresh()->status, $order->fresh()->payment_reference]);
        $this->assertSame(1, Ticket::count());
    }

    // ------------------------------------------------------------------ notifications

    public function test_9_the_same_notification_twice_is_processed_once_and_always_re_queried(): void
    {
        $order = $this->checkout($this->comp(), 2);
        $this->payment('Paid', '20.00', $this->debit());
        $body = ['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'];

        $this->notify($body)->assertOk()->assertExactJson(['paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1']);
        $this->notify($body)->assertOk()->assertExactJson(['paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1']);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, Ticket::count());
        Mail::assertQueued(TicketsIssued::class, 1);
        $this->assertCount(2, $this->sentTo('GET', '/payments/PAY1'));   // never trusts the body
        $this->assertSame(2, PaymentEvent::where('kind', 'notification')->count());
    }

    public function test_a_notification_needs_the_token_and_never_trusts_its_body(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $body = ['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'];
        $this->notify($body, null)->assertForbidden();
        $this->notify($body, 'wrong')->assertForbidden();
        config(['raffle.payments.cashflows.webhook_token' => '']);
        $this->notify($body, '')->assertForbidden();                 // no token configured: refuse everything
        config(['raffle.payments.cashflows.webhook_token' => self::TOKEN]);

        // Says nothing about the outcome; Cashflows says it's still pending, so nothing happens.
        $this->payment('Pending', '10.00');
        $this->notify($body)->assertOk();
        $this->assertSame('pending', $order->fresh()->status);

        // Optional IP allowlist.
        config(['raffle.payments.cashflows.webhook_ips' => '54.75.5.171, 54.73.83.234']);
        $this->notify($body)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '54.75.5.171'])->notify($body)->assertOk();

        // Unknown payment job: acknowledged (so it isn't retried for a month), nothing done.
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'NOPE', 'paymentReference' => 'X'])
            ->assertOk()->assertExactJson(['paymentJobReference' => 'NOPE', 'paymentReference' => 'X']);
    }

    public function test_if_cashflows_cannot_be_queried_the_notification_asks_to_be_retried(): void
    {
        $order = $this->checkout($this->comp(), 1);
        $this->payments['PAY1'] = [503, []];
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'])->assertStatus(503);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ------------------------------------------------------------------ refunds from Admin

    public function test_10_admin_partial_then_full_refund_shows_in_the_payment_panel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->checkout($this->comp(), 3);
        $this->payment('Paid', '30.00', $this->debit());
        $this->poll($order);

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5, 'reason' => 'Goodwill'])
            ->assertOk()->assertJsonPath('message', "Refunded £5.00 ({$order->reference}-R1). £25.00 is still paid; the tickets stay in the draw.");
        $this->assertSame(['paid', 500], [$order->fresh()->status, $order->fresh()->refunded_pence]);

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 30, 'reason' => 'Too much'])
            ->assertStatus(422)->assertJsonPath('message', 'You can refund at most £25.00 on this order (£30.00 paid, £5.00 already refunded).');
        $this->assertCount(1, $this->sentTo('POST', '/refunds'));      // refused before reaching Cashflows

        $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['reason' => 'Customer asked', 'void_tickets' => true])
            ->assertOk()->assertJsonPath('message', "Refunded £25.00 ({$order->reference}-R2). The order is refunded and its tickets are out of the draw.");
        $this->assertSame(['refunded', 3000], [$order->fresh()->status, $order->fresh()->refunded_pence]);
        $this->assertSame(0, Ticket::whereNull('withdrawn_at')->count());
        $this->assertSame(['25.00', "{$order->reference}-R2"], [json_decode($this->sentTo('POST', '/refunds')[1]->body(), true)['amountToRefund'], json_decode($this->sentTo('POST', '/refunds')[1]->body(), true)['refundNumber']]);

        $panel = $this->actingAs($admin)->getJson("/api/admin/orders/{$order->id}")->assertOk()->json('payment');
        $this->assertSame(['JOB1', 'PAY1', 'debit', 'gateway'], [$panel['paymentJobReference'], $panel['paymentReference'], $panel['cardType'], $panel['cardTypeSource']]);
        $this->assertEquals([30, 30, 0], [$panel['total'], $panel['refunded'], $panel['refundable']]);
        $this->assertSame([['R1', 5, 'completed'], ['R2', 25, 'completed']], array_map(fn ($r) => [substr($r['number'], -2), (int) $r['amount'], $r['status']], $panel['refunds']));
        $this->assertSame(['created', 'status', 'refund', 'refund'], array_column($panel['history'], 'kind'));
    }

    public function test_cashflows_refund_errors_become_clear_admin_messages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->checkout($this->comp(), 1);
        $this->payment('Paid', '10.00', $this->debit());
        $this->poll($order);

        foreach ([
            'RefundAmountExceedsOriginalAmount' => 'more than is left to refund',
            'PaymentAlreadyFullyRefunded' => 'already been refunded in full',
            'InvalidPaymentForRefund' => "can't refund this payment",
        ] as $code => $words) {
            $this->refundReply = [400, ['errors' => [['code' => $code, 'message' => 'x']]]];
            $msg = $this->actingAs($admin)->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 1, 'reason' => 'Test'])->assertStatus(422)->json('message');
            $this->assertStringContainsString($words, $msg);
        }
        $this->assertSame(['paid', 0], [$order->fresh()->status, $order->fresh()->refunded_pence]);
        $this->assertSame(['failed', 'InvalidPaymentForRefund'], [OrderRefund::latest('id')->value('status'), OrderRefund::latest('id')->value('error_code')]);
    }

    // ------------------------------------------------------------------ card type

    public function test_an_unknown_card_counts_as_credit_until_detection_is_confirmed(): void
    {
        $c = $this->comp();
        $order = $this->checkout($c, 26);                        // £260, card type not reported
        $this->payment('Paid', '260.00', ['TruncatedCardNumber' => '510000******0001', 'Message' => 'Authorised']);
        $res = $this->poll($order)->assertJsonPath('data.status', 'refunded');
        $this->assertStringContainsString("We couldn't confirm that your card is a debit card", $res->json('data.message'));
        $this->assertSame(['unknown', null], [$order->fresh()->card_type, $order->fresh()->card_type_source]);

        config(['raffle.payments.cashflows.unknown_card_as_credit' => false]);
        $this->createReply[1]['data']['order']['payments'][0]['reference'] = 'PAYU';
        $ok = $this->checkout($c, 26, 'other@example.com');
        $this->payment('Paid', '260.00', ['TruncatedCardNumber' => '510000******0001'], 'PAYU');
        $this->poll($ok)->assertJsonPath('data.status', 'paid');
    }

    public function test_the_bin_lookup_is_the_fallback_for_the_card_type(): void
    {
        $this->app->instance(BinLookup::class, new class implements BinLookup {
            public function lookup(string $bin): ?string { return $bin === '840068' ? 'debit' : null; }
        });
        $order = $this->checkout($this->comp(), 30);
        $this->payment('Paid', '300.00', ['TruncatedCardNumber' => '840068******0009']);
        $this->poll($order)->assertJsonPath('data.status', 'paid');
        $this->assertSame(['debit', 'bin'], [$order->fresh()->card_type, $order->fresh()->card_type_source]);
    }

    public function test_card_type_is_found_wherever_the_gateway_puts_it(): void
    {
        $g = app(PaymentGateway::class);
        $this->assertInstanceOf(CashflowsGateway::class, $g);
        $this->assertSame('credit', $g->cardDetails(['paymentMethods' => [['card' => ['fundingType' => 'CREDIT']]]])['type']);
        $this->assertSame('prepaid', $g->cardDetails(['attributes' => ['cashFlowsAcquiringDetails' => '{"CardCategory":"Prepaid Debit"}']])['type']);
        $this->assertSame('unknown', $g->cardDetails(['attributes' => ['cashFlowsAcquiringDetails' => '{"CardType":"Visa"}']])['type']);
        $this->assertSame('840068', $g->cardDetails(['attributes' => ['cashFlowsAcquiringDetails' => '{"TruncatedCardNumber":"840068******0009"}']])['bin']);
    }

    // ------------------------------------------------------------------ redaction

    public function test_nothing_stored_or_logged_has_a_card_number_or_a_key(): void
    {
        $this->assertSame('400000******0002', PaymentRedactor::maskCardNumber('4000000000000002'));
        $redacted = PaymentRedactor::redact([
            'reference' => '210121017238589452',
            'card' => ['cardNumber' => '4000 0000 0000 0002', 'cvv' => '123', 'cardExpiryMonth' => '12'],
            'hash' => 'abc', 'apiKey' => self::KEY,
            'attributes' => ['cashFlowsAcquiringDetails' => '{"Pan":"8400682600000009","TruncatedCardNumber":"840068******0009"}'],
        ]);
        $json = json_encode($redacted);
        $this->assertStringContainsString('210121017238589452', $json);      // references are kept
        foreach (['4000000000000002', '4000 0000 0000 0002', '8400682600000009', self::KEY, '"123"', '"abc"'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }

        $order = $this->checkout($this->comp(), 1);
        $this->payment('Paid', '10.00', $this->debit() + ['CardNumber' => '8400682600000009']);
        $this->poll($order);
        $stored = PaymentEvent::pluck('payload')->toJson();
        $this->assertStringNotContainsString('8400682600000009', $stored);
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertStringContainsString('840068******0009', $stored);
    }

    public function test_tokens_and_view_keys_in_urls_are_redacted_when_stored_and_when_shown(): void
    {
        $redacted = json_encode(PaymentRedactor::redact(['data' => ['attributes' => [
            'webhookUrl' => 'https://wds-staging-api.on-forge.com/api/payments/cashflows/notify?token=SECRET-TOKEN',
            'returnUrlSuccess' => 'https://wds-staging.on-forge.com/order/WDS-1?key=VIEW-KEY-1&result=success',
            'returnUrlFailed' => 'https://x.test/?a=1&KEY=VIEW-KEY-2#top',
            'encoded' => 'https%3A%2F%2Fx.test%2Forder%3Fkey%3DVIEW-KEY-3%26result%3Dsuccess',
        ]], 'monkey' => 'keyring=fine', 'paymentJobReference' => '210121017238589452']), JSON_UNESCAPED_SLASHES);
        foreach (['SECRET-TOKEN', 'VIEW-KEY-1', 'VIEW-KEY-2', 'VIEW-KEY-3'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted, $secret);
        }
        $this->assertStringContainsString('notify?token=[redacted]', $redacted);
        $this->assertStringContainsString('?key=[redacted]&result=success', $redacted);
        $this->assertStringContainsString('%3Fkey%3D%5Bredacted%5D%26result%3Dsuccess', $redacted);
        $this->assertStringContainsString('keyring=fine', $redacted);
        $this->assertStringContainsString('210121017238589452', $redacted);

        // Stored: the payment job request carries the order view key in every return URL.
        $order = $this->checkout($this->comp(), 1);
        $stored = PaymentEvent::where('order_id', $order->id)->pluck('payload')->toJson(JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($order->access_key, $stored);
        $this->assertStringContainsString('key=[redacted]&result=success', $stored);

        // Shown: a row stored before this rule existed is masked in Admin → order → Payment.
        PaymentEvent::create(['order_id' => $order->id, 'kind' => 'status', 'status' => 'Paid', 'summary' => 'old row', 'payload' => [
            'data' => ['attributes' => ['webhookUrl' => 'https://api.test/notify?token=OLD-SECRET', 'returnUrlSuccess' => "https://x.test/order/{$order->reference}?key={$order->access_key}"]],
        ]]);
        $admin = User::factory()->create(['role' => 'admin']);
        $shown = json_encode($this->actingAs($admin)->getJson("/api/admin/orders/{$order->id}")->assertOk()->json('payment.history'), JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('OLD-SECRET', $shown);
        $this->assertStringNotContainsString($order->access_key, $shown);
        $this->assertStringContainsString('token=[redacted]', $shown);
    }

    // ------------------------------------------------------------------ payments:reconcile

    private function reconcile(): void
    {
        $this->assertSame(0, Artisan::call('payments:reconcile'));
    }

    private function summaries(Order $order): array
    {
        return PaymentEvent::where('order_id', $order->id)->orderBy('id')->pluck('summary')->all();
    }

    private function nextJob(string $job, string $payment): void
    {
        $this->createReply[1]['data']['reference'] = $job;
        $this->createReply[1]['data']['order']['payments'][0]['reference'] = $payment;
    }

    public function test_reconcile_catches_a_payment_whose_notification_never_came_once_and_only_once(): void
    {
        $order = $this->checkout($this->comp(), 2);
        $this->payment('Paid', '20.00', $this->debit());

        $this->travel(2)->minutes();
        $this->reconcile();                                              // under 3 minutes old: left alone
        $this->assertCount(0, $this->sentTo('GET', '/payments/PAY1'));
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->reconcile();
        $this->assertSame(['paid', 'debit'], [$order->fresh()->status, $order->fresh()->card_type]);
        $this->assertSame(2, Ticket::count());
        $this->assertContains('Reconcile re-query: Cashflows says Paid; order pending → paid', $this->summaries($order));
        $this->assertContains('Reconcile re-query: Paid · card debit (gateway)', $this->summaries($order));

        // Then the notification arrives after all, and reconcile runs again: nothing happens twice.
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB1', 'paymentReference' => 'PAY1'])->assertOk();
        $this->travel(5)->minutes();
        $this->reconcile();
        $this->assertSame(2, Ticket::count());
        Mail::assertQueued(TicketsIssued::class, 1);
        $this->assertCount(2, $this->sentTo('GET', '/payments/PAY1'));   // reconcile once, the notification once
    }

    public function test_reconcile_applies_failed_and_cancelled_and_follows_the_paid_payment_in_the_job(): void
    {
        $c = $this->comp();
        $failed = $this->checkout($c, 1);
        $this->payment('Failed', '10.00');
        $this->travel(4)->minutes();
        $this->reconcile();
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertNull($failed->fresh()->reserved_until);
        $this->assertContains('Reconcile re-query: Cashflows says Failed; order pending → failed', $this->summaries($failed));

        $this->nextJob('JOB2', 'PAY2');
        $cancelled = $this->checkout($c, 1, 'leia@example.com');
        $this->payment('Cancelled', '10.00', null, 'PAY2');
        $this->travel(4)->minutes();
        $this->reconcile();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertStringContainsString('You cancelled the payment', $cancelled->fresh()->status_note);

        // A declined attempt, then a good one in the same payment job: the job lists both, and the paid one wins.
        $this->nextJob('JOB3', 'PAY3');
        $retried = $this->checkout($c, 1, 'han@example.com');
        $this->jobs['JOB3'] = [200, ['data' => ['reference' => 'JOB3', 'order' => ['payments' => [['reference' => 'PAY3'], ['reference' => 'PAY4']]]]]];
        $this->payment('Failed', '10.00', null, 'PAY3');
        $this->payment('Paid', '10.00', $this->debit(), 'PAY4');
        $this->travel(4)->minutes();
        $this->reconcile();
        $this->assertSame(['paid', 'PAY4'], [$retried->fresh()->status, $retried->fresh()->payment_reference]);
        $this->assertSame('failed', $failed->fresh()->status);           // only Paid moves a failed order
    }

    public function test_reconcile_expires_an_unpaid_order_past_the_payment_page_expiry_and_releases_its_tickets(): void
    {
        $c = $this->comp(['total_tickets' => 3]);
        $order = $this->checkout($c, 3);
        $this->payment('Pending', '30.00');
        $this->travel(5)->minutes();
        $this->reconcile();                                              // still on the payment page
        $this->assertSame('pending', $order->fresh()->status);

        $this->travel(11)->minutes();                                    // past reserved_until (15 minutes)
        $this->reconcile();
        $order->refresh();
        $this->assertSame(['cancelled', null], [$order->status, $order->reserved_until]);
        $this->assertStringContainsString('The payment page expired', $order->status_note);
        $this->assertContains('Reconcile re-query: still Pending after the payment page expired; order cancelled, tickets released', $this->summaries($order));

        // The tickets are free again.
        $this->nextJob('JOB2', 'PAY2');
        $this->checkout($c, 3, 'leia@example.com');
    }

    public function test_reconcile_refunds_a_late_payment_once_the_competition_has_closed_and_survives_cashflows_being_down(): void
    {
        $c = $this->comp();
        $this->nextJob('JOB9', 'PAY9');                                  // someone else's entry, so it can close
        $this->checkout($c, 1, 'leia@example.com');
        $this->payment('Paid', '10.00', $this->debit(), 'PAY9');
        $this->notify(['notifyType' => 'PaymentStatusChange', 'paymentJobReference' => 'JOB9', 'paymentReference' => 'PAY9'])->assertOk();
        $this->nextJob('JOB1', 'PAY1');
        $order = $this->checkout($c, 1);
        $this->payments['PAY1'] = [503, []];
        $this->travel(4)->minutes();
        $this->reconcile();                                              // Cashflows down: nothing changes, try again later
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertContains('Reconcile re-query: failed (Cashflows status query failed (HTTP 503).); will try again', $this->summaries($order));

        $this->travel(16)->minutes();
        Artisan::call('orders:expire');
        $this->assertSame('cancelled', $order->fresh()->status);
        Verity::close($c->verity_ref, 'Closed early for the reconcile test');
        $this->payment('Paid', '10.00', $this->debit());                // the money did arrive; the notification didn't
        $this->reconcile();
        $order->refresh();
        $this->assertSame(['refunded', 1000], [$order->status, $order->refunded_pence]);
        $this->assertStringContainsString("We've refunded your payment in full", $order->status_note);
        $this->travel(5)->minutes();
        $this->reconcile();
        $this->assertCount(1, $this->sentTo('POST', '/payments/PAY1/refunds'));
        $this->assertSame(0, Ticket::whereIn('order_line_id', $order->lines()->pluck('id'))->count());
    }

    public function test_reconcile_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'payments:reconcile'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
