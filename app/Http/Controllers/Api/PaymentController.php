<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function webhook(Request $request, PaymentGateway $gateway, CheckoutService $checkout)
    {
        $result = $gateway->parseWebhook($request); // throws on bad signature
        $checkout->handlePaymentResult($result);

        return response()->json(['ok' => true]);
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
