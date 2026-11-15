<?php

namespace App\Services\Payments;

/**
 * Card type from the first 6 digits (BIN) of the card number, for when the gateway doesn't say whether a card
 * is credit or debit. Returns credit / debit / prepaid, or null when it doesn't know.
 */
interface BinLookup
{
    public function lookup(string $bin): ?string;
}
