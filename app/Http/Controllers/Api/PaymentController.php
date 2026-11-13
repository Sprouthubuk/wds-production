<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentController extends Controller
{
    public function webhook(Request $request, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $result = $gateway->parseWebhook($request); // throws on bad signature
        $checkout->handlePaymentResult($result);

        return response()->json(['ok' => true]);
    }

    /**
     * Cashflows notification (notifyType PaymentStatusChange). Cashflows doesn't sign these, so:
     *  - the URL must carry CASHFLOWS_WEBHOOK_TOKEN (?token=…, compared in constant time), and optionally come
     *    from an allowed IP (CASHFLOWS_WEBHOOK_IPS);
     *  - the body is never trusted: the payment's status is always fetched from Cashflows before acting;
     *  - it's idempotent (Cashflows retries for up to a month until it gets a 200): the order only moves once;
     *  - the reply is 200 with both references. If Cashflows can't be queried, the reply is 503 so it retries.
     */
    public function cashflows(Request $request, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $config = config('raffle.payments.cashflows');
        $token = (string) ($config['webhook_token'] ?? '');
        if ($token === '' || ! hash_equals($token, (string) $request->query('token'))) {
            Log::warning('Cashflows notification refused: bad token', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $allowed = array_filter(array_map('trim', explode(',', (string) ($config['webhook_ips'] ?? ''))));
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            Log::warning('Cashflows notification refused: IP not allowed', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! $gateway instanceof CashflowsGateway) {
            return response()->json(['message' => 'Cashflows is not the payment driver.'], 404);
        }

        $job = (string) $request->input('paymentJobReference', '');
        $payment = (string) $request->input('paymentReference', '');
        $reply = ['paymentJobReference' => $job, 'paymentReference' => $payment];
        $type = (string) $request->input('notifyType', '');

        $order = $job === '' ? null : Order::where('payment_job_reference', $job)->first();
        if (! $order || $payment === '') {
            // Not one of ours (or not about a payment): acknowledge, so it isn't retried for a month.
            Log::warning('Cashflows notification for an unknown payment job', $reply + ['notifyType' => $type]);

            return response()->json($reply);
        }
        PaymentLog::record($order, 'notification', null, "Notification: {$type}", $request->all());
        if ($type !== '' && $type !== 'PaymentStatusChange') {
            return response()->json($reply);
        }

        // One notification for an order at a time (retries can overlap); the order itself only ever moves once.
        $lock = Cache::lock("cashflows-notify:{$order->id}", 60);
        try {
            $lock->block(20);
            $result = $gateway->fetchPayment($order, $job, $payment, 'Notification re-query');
            if ($result && $result->status !== PaymentResult::PENDING) {
                $checkout->handlePaymentResult($result);
            }
        } catch (Throwable $e) {
            Log::error('Cashflows notification: status query failed', ['order' => $order->reference, 'error' => $e->getMessage()]);

            return response()->json($reply + ['message' => 'Status check failed; please retry.'], 503);
        } finally {
            $lock->release();
        }

        return response()->json($reply);
    }

    /** FakeGateway's stand-in "hosted payment page" (local only, signed URL). */
    public function fake(Request $request, CheckoutService $checkout)
    {
        abort_unless(config('raffle.payments.driver') === 'fake' && ! app()->isProduction(), 404);
        // ?fail=1 and ?card_type=credit|debit|prepaid|unknown can be added to the signed link when testing.
        abort_unless($request->hasValidSignatureWhileIgnoring(['fail', 'card_type', 'card_ref']), 403);
        $checkout->handlePaymentResult(new PaymentResult(
            (string) $request->query('order'),
            $request->query('fail') ? PaymentResult::FAILED : PaymentResult::PAID,
            (string) $request->query('provider_reference'),
            null,
            'card',
            (string) $request->query('card_type', 'debit'),
            $request->query('card_ref'),
        ));

        return redirect()->away((string) $request->query('return'));
    }
}
