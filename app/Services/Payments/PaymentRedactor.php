<?php

namespace App\Services\Payments;

/**
 * Makes gateway requests and responses safe to keep and log: no full card numbers, no security codes, no keys
 * or hashes. Card numbers are cut to the first 6 and last 4 digits (as the gateway's TruncatedCardNumber already
 * is). Cashflows nests its acquirer details as a JSON string (cashFlowsAcquiringDetails): that is redacted too.
 * References (long digit strings such as payment job references) are kept, so only card-number fields are masked.
 */
class PaymentRedactor
{
    private const SECRET_KEYS = '/^(api_?key|apikey|hash|signature|secret|password|token|cvv|cvc|cv2|cvv2|security_?code|card_?security_?code|pin|configuration_?id)$/i';

    private const CARD_KEYS = '/(card_?number|^pan$|primary_?account_?number|account_?number)/i';

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::redact($v, is_string($k) ? $k : $key);
            }

            return $out;
        }
        if ($key !== null && preg_match(self::SECRET_KEYS, $key)) {
            return $value === null || $value === '' ? $value : '[redacted]';
        }
        if (! is_string($value)) {
            return $value;
        }
        // A JSON document inside a string (cashFlowsAcquiringDetails): redact what's inside, keep it a string.
        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(self::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($key !== null && preg_match(self::CARD_KEYS, $key)) {
            return self::maskCardNumber($value);
        }

        return $value;
    }

    /** "4000000000000002" → "400000******0002"; an already-masked number is left as it is. */
    public static function maskCardNumber(string $value): string
    {
        return preg_replace_callback('/\d[\d ]{11,22}\d/', function ($m) {
            $digits = preg_replace('/\D/', '', $m[0]);
            if (strlen($digits) < 12 || strlen($digits) > 19) {
                return $m[0];
            }

            return substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4);
        }, $value) ?? '[redacted]';
    }
}
