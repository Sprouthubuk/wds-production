<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catches payments whose notification never arrived (or arrived while Cashflows couldn't be queried): asks
 * Cashflows about each Cashflows order still waiting, and applies the answer exactly as a notification would
 * (CheckoutService::handlePaymentResult: idempotent, same late-payment and credit-cap refund rules).
 *
 *  - pending orders older than 3 minutes (younger ones are usually still on the payment page);
 *  - orders cancelled or failed in the last hour without payment (the reservation ended, or a declined attempt):
 *    only a Paid answer moves those, as with a late notification;
 *  - a pending order past its expiry (reserved_until = the job's expirationDateTimeUtc) with no payment taken is
 *    cancelled as expired, which releases its tickets.
 *
 * Each order's actions go in its payment history: "Reconcile re-query: …".
 */
class CashflowsReconciler
{
    public const PENDING_AFTER_MINUTES = 3;
    public const LATE_PAYMENT_WINDOW_MINUTES = 60;
    private const WHY = 'Reconcile re-query';

    public function __construct(private CashflowsGateway $gateway, private CheckoutService $checkout) {}

    /** @return array<string, int> what happened, counted */
    public function run(): array
    {
        $counts = ['checked' => 0, 'paid' => 0, 'refunded' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0, 'unchanged' => 0, 'errors' => 0];
        $orders = Order::where('payment_provider', 'cashflows')->whereNotNull('payment_job_reference')->whereNull('paid_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(self::PENDING_AFTER_MINUTES)))
                ->orWhere(fn ($q) => $q->whereIn('status', ['cancelled', 'failed'])->where('updated_at', '>=', now()->subMinutes(self::LATE_PAYMENT_WINDOW_MINUTES))))
            ->orderBy('id')->limit(500)->get();

        foreach ($orders as $order) {
            $counts['checked']++;
            $counts[$this->reconcile($order)]++;
        }

        return $counts;
    }

    /** @return string one of the keys counted by run() */
    public function reconcile(Order $order): string
    {
        // The same lock as the notification: the two never act on one order at once.
        $lock = Cache::lock("cashflows-notify:{$order->id}", 60);
        if (! $lock->get()) {
            return 'unchanged';
        }
        try {
            $order->refresh();
            if ($order->paid_at || ! in_array($order->status, ['pending', 'cancelled', 'failed'], true)) {
                return 'unchanged';
            }
            $before = $order->status;
            $result = $this->query($order);

            if ($result && $result->status !== PaymentResult::PENDING) {
                $after = $this->checkout->handlePaymentResult($result) ?? $order->fresh();
                $outcome = match (true) {
                    $after->status === 'paid' => 'paid',
                    $after->status === 'refunded', $after->paid_at !== null => 'refunded', // refused (cap, too late): refunded or refund needed
                    $after->status === $before => 'unchanged',
                    $after->status === 'failed' => 'failed',
                    $after->status === 'cancelled' && strcasecmp((string) $result->gatewayStatus, 'Expired') === 0 => 'expired',
                    $after->status === 'cancelled' => 'cancelled',
                    default => 'unchanged',
                };
                if ($outcome !== 'unchanged') {
                    PaymentLog::record($after, 'reconcile', $result->gatewayStatus, self::WHY.": Cashflows says {$result->gatewayStatus}; order {$before} → {$after->status}");
                }

                return $outcome;
            }

            // No payment taken (still pending at Cashflows, or no payment found) and the payment page has expired:
            // release the tickets.
            if ($order->status === 'pending' && $order->reserved_until && $order->reserved_until->isPast()) {
                $this->checkout->handlePaymentResult(new PaymentResult($order->reference, PaymentResult::CANCELLED, gatewayStatus: 'Expired'));
                PaymentLog::record($order->fresh(), 'reconcile', 'Expired', self::WHY.': '.($result ? 'still '.($result->gatewayStatus ?? 'pending') : 'no payment found').' after the payment page expired; order cancelled, tickets released');

                return 'expired';
            }

            return 'unchanged';
        } catch (Throwable $e) {
            Log::warning('Cashflows reconcile: status query failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
            PaymentLog::record($order, 'error', null, self::WHY.': failed ('.mb_substr($e->getMessage(), 0, 200).'); will try again');

            return 'errors';
        } finally {
            $lock->release();
        }
    }

    /**
     * The payment that decides the order: any Paid payment in the job wins (a declined attempt, then a good one);
     * otherwise the most recent attempt. Falls back to the stored payment reference if the job can't be listed.
     */
    private function query(Order $order): ?PaymentResult
    {
        $refs = $this->gateway->jobPaymentReferences($order, $order->payment_job_reference, self::WHY) ?? [];
        if ($order->payment_reference && ! in_array($order->payment_reference, $refs, true)) {
            array_unshift($refs, $order->payment_reference);
        }
        $latest = null;
        foreach ($refs as $ref) {
            $result = $this->gateway->fetchPayment($order, $order->payment_job_reference, $ref, self::WHY);
            if ($result?->status === PaymentResult::PAID) {
                return $result;
            }
            $latest = $result ?? $latest;
        }

        return $latest;
    }
}
