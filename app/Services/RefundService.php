<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\RefundResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refunds through the payment gateway, full or partial: automatic ones (credit card cap, a payment that arrived
 * too late or for tickets that had gone) and the admin "Refund" button. One refund at a time per order, never more
 * than is left, and each refund is recorded with its own refund number ("WDS-ABC123-R1") whatever the outcome.
 */
class RefundService
{
    public function __construct(private PaymentGateway $gateway) {}

    /** @throws EntryException when the amount isn't refundable (nothing is sent to the gateway) */
    public function refund(Order $order, int $amountPence, string $reason, ?User $staff = null, bool $automatic = false): OrderRefund
    {
        $lock = Cache::lock("order-refund:{$order->id}", 60);
        $lock->block(15);
        try {
            $order->refresh();
            $left = $order->refundablePence();
            if ($left === 0) {
                throw new EntryException($order->refunded_pence > 0 ? 'This order has already been refunded in full.' : "This order has no card payment to refund through {$this->gatewayName($order)}.");
            }
            if ($amountPence < 1) {
                throw new EntryException('Enter an amount to refund.');
            }
            if ($amountPence > $left) {
                throw new EntryException(sprintf('You can refund at most £%s on this order (£%s paid, £%s already refunded).',
                    number_format($left / 100, 2), number_format($order->total_pence / 100, 2), number_format($order->refunded_pence / 100, 2)));
            }

            $refund = OrderRefund::create([
                'order_id' => $order->id,
                'refund_number' => $order->reference.'-R'.($order->refunds()->count() + 1),
                'amount_pence' => $amountPence,
                'reason' => mb_substr($reason, 0, 500),
                'status' => 'pending',
                'automatic' => $automatic,
                'staff_user_id' => $staff?->id,
            ]);
            try {
                $result = $this->gateway->refund($order, $amountPence, $refund->refund_number, $reason);
            } catch (Throwable $e) {
                Log::error('Refund failed', ['order' => $order->reference, 'refund' => $refund->refund_number, 'error' => $e->getMessage()]);
                $result = RefundResult::failed(null, "The payment provider couldn't be reached, so nothing was refunded. Try again in a few minutes.");
            }

            if ($result->ok) {
                $refund->update(['status' => 'completed', 'provider_reference' => $result->reference]);
                $order->increment('refunded_pence', $amountPence);
            } else {
                $refund->update(['status' => 'failed', 'error_code' => $result->errorCode, 'error_message' => $result->message]);
                Log::warning('Refund refused', ['order' => $order->reference, 'refund' => $refund->refund_number, 'code' => $result->errorCode]);
            }

            return $refund->fresh();
        } finally {
            $lock->release();
        }
    }

    private function gatewayName(Order $order): string
    {
        return $order->payment_provider === 'cashflows' ? 'Cashflows' : 'the payment provider';
    }
}
