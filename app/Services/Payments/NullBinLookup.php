<?php

namespace App\Services\Payments;

/** No BIN service yet: every lookup is "don't know" (CASHFLOWS_UNKNOWN_CARD_AS_CREDIT decides what that means). */
class NullBinLookup implements BinLookup
{
    public function lookup(string $bin): ?string
    {
        return null;
    }
}
