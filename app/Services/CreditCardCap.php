<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * DCMS Voluntary Code: credit card spending is capped at £250 per calendar month per customer (UK time),
 * across all competitions, and credit cards are never accepted on instant-win competitions.
 *
 * The card type is only known once the payment provider confirms the payment, so this runs on the
 * payment result, before any tickets are allocated: a payment that would breach it is refunded in full.
 */
class CreditCardCap
{
    public const TYPES = ['credit', 'debit', 'prepaid', 'unknown'];

    public static function normalise(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $type = strtolower(trim($type));

        return in_array($type, self::TYPES, true) ? $type : 'unknown';
    }

    /**
     * Whether a payment counts as a credit card one. Cashflows: until its card type detection is confirmed on real
     * payments, a card of unknown type counts too (CASHFLOWS_UNKNOWN_CARD_AS_CREDIT, on by default).
     */
    public static function countsAsCredit(?string $cardType, ?string $provider): bool
    {
        $type = self::normalise($cardType);

        return $type === 'credit'
            || ($provider === 'cashflows' && in_array($type, [null, 'unknown'], true) && self::unknownCountsAsCredit());
    }

    public static function unknownCountsAsCredit(): bool
    {
        return (bool) config('raffle.payments.cashflows.unknown_card_as_credit', true);
    }

    public function monthlyCapPence(): int
    {
        return (int) config('raffle.credit_card_monthly_cap_pence', 25000);
    }

    /** Why this payment can't be accepted, in words for the customer; null = fine. */
    public function refusal(Order $order, ?string $cardType): ?string
    {
        if (! self::countsAsCredit($cardType, $order->payment_provider)) {
            return null;
        }
        // A card we couldn't identify is treated as a credit card (see countsAsCredit), and the customer is told so.
        $unknown = self::normalise($cardType) !== 'credit'
            ? "We couldn't confirm that your card is a debit card, so the payment counts as a credit card payment. "
            : '';
        $order->loadMissing('lines.competition');
        if ($order->lines->contains(fn ($l) => $l->competition->instant_win)) {
            return $unknown."Credit cards can't be used on instant-win competitions. We've refunded your payment in full and no tickets were issued. Please pay by debit card instead.";
        }
        $spent = $this->creditSpentThisMonth($order);
        $cap = $this->monthlyCapPence();
        if ($spent + $order->total_pence > $cap) {
            $left = max(0, $cap - $spent);

            return $unknown.sprintf(
                "Credit card spending is limited to £%s a month across all competitions, and this payment would take you over it (you have £%s left on a credit card this month). We've refunded your payment in full and no tickets were issued. You can pay by debit card instead.",
                number_format($cap / 100, 0), number_format($left / 100, 2),
            );
        }

        return null;
    }

    /** Paid credit card orders this calendar month (UK) by the same customer: their account or their email. */
    public function creditSpentThisMonth(Order $order): int
    {
        $from = CarbonImmutable::now('Europe/London')->startOfMonth()->utc();

        return (int) Order::query()
            ->whereKeyNot($order->id)
            ->where('status', 'paid')
            ->where(fn ($q) => $q->where('card_type', 'credit')
                ->when(self::unknownCountsAsCredit(), fn ($q) => $q->orWhere(fn ($q) => $q->where('payment_provider', 'cashflows')->where('card_type', 'unknown'))))
            ->where('paid_at', '>=', $from)
            ->where(fn ($q) => $q->where('email', $order->email)
                ->when($order->user_id, fn ($q) => $q->orWhere('user_id', $order->user_id)))
            ->sum('total_pence');
    }
}
