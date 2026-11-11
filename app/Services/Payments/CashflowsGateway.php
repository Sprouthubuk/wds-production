<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cashflows hosted payment page — SKELETON.
 *
 * Deliberately not implemented against guessed endpoints. When the merchant account is live,
 * fill these three methods in from Cashflows' integration guide (hosted payment page,
 * payment status, and webhook/notification signature verification), using the credentials
 * in config('raffle.payments.cashflows').
 *
 * Contract the rest of the app relies on:
 *  - createHostedPayment(): amount = $order->total_pence, currency GBP, our reference = $order->reference,
 *    return URL = $returnUrl. Return the hosted page URL + the provider's payment reference.
 *  - parseWebhook(): verify the signature FIRST (throw on mismatch), then map the provider status
 *    to PaymentResult::PAID / FAILED / PENDING and include the amount so we can check it matches.
 *  - fetchStatus(): optional poll used if the customer returns before the webhook arrives.
 */
class CashflowsGateway implements PaymentGateway
{
    public function __construct(private array $config) {}

    public function name(): string { return 'cashflows'; }

    public function createHostedPayment(Order $order, string $returnUrl): HostedPayment
    {
        throw new RuntimeException('Cashflows gateway not configured yet — see App\Services\Payments\CashflowsGateway.');
    }

    public function parseWebhook(Request $request): PaymentResult
    {
        throw new RuntimeException('Cashflows webhook verification not implemented yet.');
    }

    public function fetchStatus(Order $order): ?PaymentResult
    {
        return null;
    }
}
