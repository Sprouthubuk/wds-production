<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Hosted checkout. The customer never enters card details on our site: we create a payment session, redirect
 * to the provider, and only the provider's own answer (a status query, prompted by a notification or by the
 * order page polling) moves the order on. Tickets are only allocated once the provider says Paid.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Create a hosted payment for the order and return the URL to redirect the customer to. */
    public function createHostedPayment(Order $order, string $returnUrl): HostedPayment;

    /** Verify and parse a webhook (FakeGateway). Must throw if it can't be trusted. */
    public function parseWebhook(Request $request): PaymentResult;

    /** Ask the provider for the latest status (the order page polls this while the order is pending). */
    public function fetchStatus(Order $order): ?PaymentResult;

    /** Refund part or all of a paid order. Never throws for a refusal: the result says why. */
    public function refund(Order $order, int $amountPence, string $refundNumber, string $reason): RefundResult;
}
