<?php

namespace App\Services\Payments;

final class HostedPayment
{
    public function __construct(
        public readonly string $redirectUrl,
        // The provider's reference for the payment session (Cashflows: the payment job reference).
        public readonly string $providerReference,
        // Cashflows: the payment reference inside the job (status and refunds need both).
        public readonly ?string $paymentReference = null,
    ) {}
}
