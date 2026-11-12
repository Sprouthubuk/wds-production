<?php

namespace App\Services\Payments;

final class PaymentResult
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $orderReference,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?int $amountPence = null,
        public readonly ?string $method = null,
        // credit, debit, prepaid or unknown, as the provider confirms it (CreditCardCap::normalise); null = not a card.
        public readonly ?string $cardType = null,
    ) {}
}
