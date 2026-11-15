<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentEvent;

/** The order's payment history (Admin → order → Payment): every gateway call and notification, redacted. */
class PaymentLog
{
    private const MAX_PAYLOAD_BYTES = 60000;

    public static function record(Order $order, string $kind, ?string $status, ?string $summary, mixed $payload = null): PaymentEvent
    {
        $payload = $payload === null ? null : PaymentRedactor::redact($payload);
        if ($payload !== null && strlen((string) json_encode($payload)) > self::MAX_PAYLOAD_BYTES) {
            $payload = ['truncated' => true, 'preview' => substr((string) json_encode($payload), 0, self::MAX_PAYLOAD_BYTES)];
        }

        return PaymentEvent::create([
            'order_id' => $order->id,
            'kind' => $kind,
            'status' => $status === null ? null : substr($status, 0, 40),
            'summary' => $summary === null ? null : mb_substr($summary, 0, 500),
            'payload' => is_array($payload) ? $payload : ($payload === null ? null : ['value' => $payload]),
        ]);
    }
}
