<?php

namespace App\Services\Draws;

final class DrawPreparation
{
    /** @param array<string,mixed> $data engine-private state stored in draws.proof until the draw runs */
    public function __construct(
        public readonly ?string $commitment,
        public readonly array $data = [],
    ) {}
}
