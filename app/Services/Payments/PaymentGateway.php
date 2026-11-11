<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Hosted checkout. The customer never enters card details on our site:
 * we create a payment session, redirect to the provider, and the provider tells us
 * the outcome via a signed webhook. Tickets are only allocated on that webhook.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Create a hosted payment for the order and return the URL to redirect the customer to. */
    public function createHostedPayment(Order $order, string $returnUrl): HostedPayment;

    /** Verify and parse a webhook. Must throw if the signature is invalid. */
    public function parseWebhook(Request $request): PaymentResult;

    /** Ask the provider for the latest status (used when the customer lands back before the webhook). */
    public function fetchStatus(Order $order): ?PaymentResult;
}
