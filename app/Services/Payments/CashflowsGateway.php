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
 *  - Card type (credit card cap): PaymentResult::$cardType must be 'credit', 'debit', 'prepaid' or 'unknown'
 *    (see cardType() below). Without it the £250/month credit card cap can't be enforced.
 *  - Card reference (harm monitoring): PaymentResult::$cardRef = their card token / fingerprint, so frequent
 *    payment-method changes can be flagged. TODO(cashflows): map it; never store the card number.
 *  - refund(): full refund of a paid order, used when a credit card payment would breach the cap.
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

    /**
     * TODO(cashflows): map Cashflows' card-type field from the payment/webhook payload to our card type.
     * Use their funding-type field (credit / debit / prepaid), not the scheme (Visa / Mastercard). Anything
     * missing or unrecognised must come back as 'unknown', never as 'debit'.
     */
    public static function cardType(array $payment): string
    {
        return 'unknown'; // TODO(cashflows): e.g. CreditCardCap::normalise($payment['<their card type field>'] ?? null) ?? 'unknown'
    }

    public function refund(Order $order, string $reason): bool
    {
        // TODO(cashflows): call their refund endpoint for $order->provider_reference, full amount. Until then
        // a refused payment shows in Admin as "refund needed" and staff refund it by hand.
        return false;
    }
}
