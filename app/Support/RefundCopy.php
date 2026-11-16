<?php

namespace App\Support;

/**
 * What customers are told about refunds, in one place so the emails and the order page can't drift from each other
 * or from the Refund policy page.
 */
final class RefundCopy
{
    public const TIMESCALE = 'It can take up to 10 working days to appear on your statement, depending on your bank.';

    public static function withTimescale(string $message): string
    {
        return rtrim($message).' '.self::TIMESCALE;
    }
}
