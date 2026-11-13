<?php

namespace App\Services\Payments;

final class PaymentResult
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    /** Cancelled on the payment page, or the payment page expired: nothing taken, the reservation is released. */
    public const CANCELLED = 'cancelled';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $orderReference,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?int $amountPence = null,
        public readonly ?string $method = null,
        // credit, debit, prepaid or unknown, as the provider confirms it (CreditCardCap::normalise); null = not a card.
        public readonly ?string $cardType = null,
        // The provider's token/fingerprint for the card (never the card number): spots frequent card changes.
        public readonly ?string $cardRef = null,
        // Where the card type came from: gateway (the payment response) | bin (BIN lookup) | null (not known).
        public readonly ?string $cardTypeSource = null,
        // The gateway's own status word (Paid, Failed, Cancelled, Expired, …), for messages and history.
        public readonly ?string $gatewayStatus = null,
        // The gateway's payment reference this result is about (a payment job can hold more than one payment).
        public readonly ?string $paymentReference = null,
    ) {}
}
