<?php

namespace App\Services\Payments;

final class HostedPayment
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $providerReference,
    ) {}
}
