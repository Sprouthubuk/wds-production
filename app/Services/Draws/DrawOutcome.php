<?php

namespace App\Services\Draws;

final class DrawOutcome
{
    /** @param array<string,mixed> $proof */
    public function __construct(
        public readonly int $winningNumber,
        public readonly array $proof,
        public readonly ?string $externalId = null,
    ) {}
}
