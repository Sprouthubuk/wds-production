<?php

namespace App\Services\Payments;

/** What the gateway said to a refund request. $message is written for admins. */
final class RefundResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $reference = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
    ) {}

    public static function failed(?string $code, string $message): self
    {
        return new self(false, null, $code, $message);
    }
}
