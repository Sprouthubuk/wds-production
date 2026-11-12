<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Local/testing gateway. "Hosted page" is a signed URL on this API that marks the order paid
 * and bounces back to the frontend. Never enable in production.
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string { return 'fake'; }

    public function createHostedPayment(Order $order, string $returnUrl): HostedPayment
    {
        $ref = 'fake_'.Str::random(16);
        $url = URL::temporarySignedRoute('payments.fake', now()->addMinutes(30), [
            'order' => $order->reference,
            'provider_reference' => $ref,
            'return' => $returnUrl,
        ]);

        return new HostedPayment($url, $ref);
    }

    public function parseWebhook(Request $request): PaymentResult
    {
        return new PaymentResult(
            (string) $request->input('order'),
            (string) $request->input('status', PaymentResult::PAID),
            (string) $request->input('provider_reference'),
            null,
            'card',
            // Testing: choose the card type the "provider" reports (credit / debit / prepaid / unknown).
            (string) $request->input('card_type', 'debit'),
            $request->input('card_ref'),
        );
    }

    public function refund(Order $order, string $reason): bool
    {
        return true;
    }

    public function fetchStatus(Order $order): ?PaymentResult
    {
        return null;
    }
}
