<?php

namespace App\Http\Resources;

/** The API stores pence; the frontend works in pounds. */
final class Money
{
    public static function pounds(?int $pence): ?float
    {
        return $pence === null ? null : round($pence / 100, 2);
    }

    public static function pence(int|float|string|null $pounds): ?int
    {
        return $pounds === null || $pounds === '' ? null : (int) round(((float) $pounds) * 100);
    }
}
