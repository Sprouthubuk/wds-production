<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Exceptions\LimitReachedException;
use App\Mail\TicketsIssued;
use App\Models\Competition;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Basket → pending order → hosted checkout → webhook → tickets.
 *
 * Capacity is held by the pending order (reserved_until) so a customer on the payment page can't
 * lose their place, and tickets are numbered sequentially only once payment is confirmed.
 */
class CheckoutService
{
    public function __construct(
        private PricingService $pricing,
        private TicketAllocator $allocator,
        private ResponsiblePlay $responsiblePlay,
        private PaymentGateway $gateway,
        private CreditCardCap $creditCap,
        private HarmMonitor $harm,
        private RefundService $refunds,
    ) {}

    /**
     * @param  array{customer: array{email:string,name:string,phone?:string|null,dateOfBirth:string}, items: list<array{competitionId:int, qty:int}>, discountCode?:string|null, useCredit?:bool, marketingOptIn?:bool}  $input
     * @return array{order: Order, redirectUrl: string}
     */
    public function start(array $input, ?User $user, string $channel = 'online', ?User $staff = null, ?string $paymentMethod = null): array
    {
        $customer = $input['customer'];
        if (! ResponsiblePlay::isAdult($customer['dateOfBirth'] ?? null)) {
            throw new EntryException('You must be 18 or over to enter.');
        }

        $items = collect($input['items'])
            ->groupBy('competitionId')
            ->map(fn ($g, $id) => ['competitionId' => (int) $id, 'qty' => (int) $g->sum('qty')])
            ->filter(fn ($i) => $i['qty'] > 0)
            ->values();
        if ($items->isEmpty()) {
            throw new EntryException('Your basket is empty.');
        }

        $email = TicketAllocator::normaliseEmail($customer['email']);
        // A guest checking out with an account's email gets that account's checks: a self-excluded, closed,
        // suspended or paused customer can't get round them by not signing in, and their limits still apply.
        $account = $user ?? User::where('email', $email)->first();

        try {
            $order = DB::transaction(function () use ($items, $customer, $email, $user, $account, $input, $channel, $staff, $paymentMethod) {
                $competitions = Competition::whereIn('id', $items->pluck('competitionId'))->lockForUpdate()->get()->keyBy('id');

                $subtotal = 0;
                $total = 0;
                $lines = [];
                foreach ($items as $item) {
                    $c = $competitions->get($item['competitionId']) ?? throw new EntryException('A competition in your basket no longer exists.');
                    // A draft (even one seen through a preview link) is never enterable, and never reaches Verity.
                    if (! $c->isPublished() || $c->status === 'draft') {
                        throw new EntryException("{$c->title} isn't open for entries yet.");
                    }
                    if (! $c->isOpen()) {
                        throw new EntryException("{$c->title} is closed for entries.");
                    }
                    if ($c->isFree()) {
                        throw new EntryException("{$c->title} is free to enter: use Enter free on its page.");
                    }
                    if ($channel === 'event' && ! $c->event_sales_enabled) {
                        throw new EntryException("{$c->title} isn't available for event sales.");
                    }
                    $qty = $item['qty'];
                    $available = $c->available();
                    if ($qty > $available) {
                        throw new EntryException($available > 0 ? "Only {$available} tickets left for {$c->title}." : "{$c->title} is sold out.");
                    }
                    $held = $this->allocator->heldBy($c, $email);
                    if ($c->max_per_person !== null && $held + $qty > $c->max_per_person) {
                        $left = max(0, $c->max_per_person - $held);
                        throw new EntryException("The maximum is {$c->max_per_person} tickets per person for {$c->title}. You can add {$left} more.");
                    }
                    $lineTotal = $this->pricing->forCompetition($c, $qty);
                    $subtotal += $qty * $c->ticket_price_pence;
                    $total += $lineTotal;
                    $lines[] = ['competition_id' => $c->id, 'qty' => $qty, 'unit_price_pence' => $c->ticket_price_pence, 'line_total_pence' => $lineTotal];
                }
                $bundleSaving = $subtotal - $total;

                // Discount code
                $discount = 0;
                $code = null;
                if (! empty($input['discountCode'])) {
                    $code = DiscountCode::where('code', strtoupper(trim($input['discountCode'])))->lockForUpdate()->first();
                    if (! $code || ! $code->isUsable()) {
                        throw new EntryException('That discount code isn\'t valid.');
                    }
                    $base = $code->competition_id
                        ? collect($lines)->where('competition_id', $code->competition_id)->sum('line_total_pence')
                        : $total;
                    if ($base === 0) {
                        throw new EntryException('That discount code doesn\'t apply to your basket.');
                    }
                    $discount = $code->type === 'percent' ? intdiv($base * min(100, $code->value), 100) : min($base, $code->value);
                    $total -= $discount;
                }

                // Site credit (online, signed-in customers)
                $credit = 0;
                if ($user && ! empty($input['useCredit'])) {
                    $credit = min($total, $user->creditBalancePence());
                    $total -= $credit;
                }

                if ($account) {
                    $this->responsiblePlay->assertCanSpend($account, $total + $credit);
                }
                $this->responsiblePlay->assertWithinSiteCap($account, $email, $total);

                $order = Order::create([
                    'reference' => self::newReference(),
                    'access_key' => Str::random(40),
                    'user_id' => $user?->id,
                    'entrant_id' => Entrant::forEmail($email)->id,
                    'email' => $email,
                    'name' => trim($customer['name']),
                    'phone' => $customer['phone'] ?? null,
                    'date_of_birth' => $customer['dateOfBirth'],
                    'channel' => $channel,
                    'status' => 'pending',
                    'subtotal_pence' => $subtotal,
                    'bundle_saving_pence' => $bundleSaving,
                    'discount_pence' => $discount,
                    'credit_used_pence' => $credit,
                    'total_pence' => $total,
                    'discount_code_id' => $code?->id,
                    'payment_method' => $paymentMethod,
                    'staff_user_id' => $staff?->id,
                    'marketing_opt_in' => (bool) ($input['marketingOptIn'] ?? false),
                    'reserved_until' => now()->addMinutes(config('raffle.reservation_minutes')),
                ]);
                $order->lines()->createMany($lines);

                return $order;
            });
        } catch (LimitReachedException $e) {
            // Recorded after the rollback, for harm monitoring.
            $this->harm->record($account, $email, 'limit_hit', ['period' => $e->period]);
            throw $e;
        }

        // Event sales (cash / card reader) and fully-covered orders complete immediately.
        if ($channel === 'event' || $order->total_pence === 0) {
            $this->markPaid($order, $channel === 'event' ? ($paymentMethod ?? 'cash') : 'credit', null);

            return ['order' => $order->fresh(), 'redirectUrl' => $this->orderUrl($order)];
        }

        $order->update(['payment_provider' => $this->gateway->name()]);
        try {
            $hosted = $this->gateway->createHostedPayment($order, $this->orderUrl($order));
        } catch (\Throwable $e) {
            // Nothing was taken: release the reservation at once so the tickets go back on sale.
            Log::error('Could not start the hosted payment', ['order' => $order->reference, 'error' => $e->getMessage()]);
            $order->update(['status' => 'cancelled', 'reserved_until' => null]);
            throw new EntryException("We couldn't open the payment page just now. No money has been taken. Please try again in a minute.");
        }
        $order->update([
            'provider_reference' => $hosted->providerReference,
            'payment_job_reference' => $this->gateway->name() === 'cashflows' ? $hosted->providerReference : null,
            'payment_reference' => $hosted->paymentReference,
        ]);

        return ['order' => $order, 'redirectUrl' => $hosted->redirectUrl];
    }

    public function handlePaymentResult(PaymentResult $result): ?Order
    {
        $order = Order::where('reference', $result->orderReference)->first();
        if (! $order) {
            Log::warning('Payment webhook for unknown order', ['reference' => $result->orderReference]);

            return null;
        }
        // Idempotent: paid and refunded orders are final, and anything but Paid only moves a pending order.
        // A cancelled or failed order is one whose reservation ended (expired, cancelled, a declined attempt)
        // while the customer could still pay: if the money arrives we still try to honour it, and refund it
        // automatically if the tickets have gone or the competition has closed in the meantime.
        if (! in_array($order->status, ['pending', 'cancelled', 'failed'], true)) {
            return $order;
        }
        if ($order->status !== 'pending' && $result->status !== PaymentResult::PAID) {
            return $order;
        }
        if ($result->status === PaymentResult::PAID) {
            // A payment job can hold more than one payment (a declined attempt, then a good one): refunds and
            // status checks follow the one that was paid.
            if ($result->paymentReference && $result->paymentReference !== $order->payment_reference) {
                $order->update(['payment_reference' => $result->paymentReference]);
            }
            if ($result->amountPence !== null && $result->amountPence !== $order->total_pence) {
                Log::error('Payment amount mismatch', ['order' => $order->reference, 'paid' => $result->amountPence, 'expected' => $order->total_pence]);
                $order->update(['status' => 'failed']);

                return $order;
            }
            $cardType = CreditCardCap::normalise($result->cardType);
            // Credit card cap: checked once the provider confirms the card type, before any tickets are allocated.
            // One customer at a time, so two credit card payments landing together can't both slip under it,
            // and the same payment reported twice at once (a notification and the order page) is handled once.
            $lock = Cache::lock('credit-card-cap:'.strtolower($order->email), 30);
            try {
                $lock->block(10);
                $order->refresh();
                if (! in_array($order->status, ['pending', 'cancelled', 'failed'], true) || ($order->paid_at && $order->status !== 'pending')) {
                    return $order;
                }
                if ($refusal = $this->creditCap->refusal($order, $cardType)) {
                    $this->refuseAndRefund($order, $result, $cardType, $refusal);

                    return $order->fresh();
                }
                $this->markPaid($order, $result->method ?? 'card', $result->providerReference, $cardType, $result->cardRef);
                if ($result->cardTypeSource !== null) {
                    $order->update(['card_type_source' => $result->cardTypeSource]);
                }
            } catch (EntryException $e) {
                // Too late (the competition closed, or its tickets went while the reservation was over): the money
                // goes straight back, and the order page says why.
                Log::warning('Paid order could not be fulfilled: refunding', ['order' => $order->reference, 'reason' => $e->getMessage()]);
                $this->refuseAndRefund($order, $result, $cardType, "Your payment arrived after your tickets' reservation had ended and they could no longer be issued ({$e->getMessage()}). We've refunded your payment in full and no tickets were issued.");
            } finally {
                $lock->release();
            }
        } elseif ($result->status === PaymentResult::FAILED) {
            $order->update(['status' => 'failed', 'reserved_until' => null,
                'status_note' => "Your payment didn't go through: it was declined, or there was a problem at the payment provider. No money was taken and no tickets were issued. Your basket is still saved, so you can try again."]);
        } elseif ($result->status === PaymentResult::CANCELLED) {
            $expired = strcasecmp((string) $result->gatewayStatus, 'Expired') === 0;
            $order->update(['status' => 'cancelled', 'reserved_until' => null,
                'status_note' => $expired
                    ? 'The payment page expired before payment was made, so your tickets were released. No money was taken. Your basket is still saved.'
                    : 'You cancelled the payment, so your tickets were released. No money was taken. Your basket is still saved.']);
        }

        return $order->fresh();
    }

    /**
     * The payment can't be accepted (credit card cap, or it came too late): refund it in full through the gateway,
     * allocate nothing, explain on the order page. If the refund fails the order shows in Admin as "refund needed".
     */
    private function refuseAndRefund(Order $order, PaymentResult $result, ?string $cardType, string $message): void
    {
        $order->update([
            'paid_at' => now(), 'payment_method' => $result->method ?? 'card', 'card_type' => $cardType, 'reserved_until' => null,
            'card_type_source' => $result->cardTypeSource, 'card_ref' => $result->cardRef,
            'provider_reference' => $result->providerReference ?? $order->provider_reference, 'status_note' => $message,
            // cancelled + paid_at = money taken, no tickets ("refund needed") until the refund goes through.
            'status' => 'cancelled',
        ]);
        $refunded = false;
        try {
            $refunded = $this->refunds->refund($order, (int) $order->total_pence, $message, null, true)->status === 'completed';
        } catch (\Throwable $e) {
            Log::error('Automatic refund failed', ['order' => $order->reference, 'error' => $e->getMessage()]);
        }
        if ($refunded) {
            $order->update(['status' => 'refunded']);
            Log::notice('Payment refused and refunded', ['order' => $order->reference]);
        } else {
            Log::critical('Payment refused, automatic refund failed: REFUND REQUIRED', ['order' => $order->reference]);
        }
    }

    /** Allocate sequential tickets for every line, take credit, bump code usage, email the customer. */
    public function markPaid(Order $order, string $method, ?string $providerReference, ?string $cardType = null, ?string $cardRef = null): void
    {
        DB::transaction(function () use ($order, $method, $providerReference, $cardType, $cardRef) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['pending', 'cancelled', 'failed'], true)) {
                return;
            }
            // The order's own reservation must not count against itself while we allocate.
            $order->update(['reserved_until' => null]);
            foreach ($order->lines()->with('competition')->get() as $line) {
                $this->allocator->allocate($line->competition, $line->qty, [
                    'channel' => $order->channel === 'event' ? 'event' : 'online',
                    'email' => $order->email,
                    'name' => $order->name,
                    'user_id' => $order->user_id,
                    'order_line_id' => $line->id,
                    // Committed before the close: Verity still enters it if the webhook lands between the
                    // closing time and the close itself (verity:process-due, within a minute).
                    'ordered_at' => $order->created_at,
                    'total_pence' => (int) $line->line_total_pence,
                ]);
            }
            if ($order->credit_used_pence > 0 && $order->user_id) {
                CreditTransaction::create([
                    'user_id' => $order->user_id,
                    'amount_pence' => -$order->credit_used_pence,
                    'reason' => "Used on order {$order->reference}",
                    'order_id' => $order->id,
                ]);
            }
            if ($order->discount_code_id) {
                DiscountCode::whereKey($order->discount_code_id)->increment('times_used');
            }
            $order->update([
                'status' => 'paid',
                'payment_method' => $method,
                'card_type' => $cardType,
                'card_ref' => $cardRef,
                'provider_reference' => $providerReference ?? $order->provider_reference,
                'paid_at' => now(),
                'status_note' => null,
            ]);
        });

        $order->refresh();
        if ($order->status === 'paid') {
            $this->harm->cardUsed($order);
            Mail::to($order->email)->queue(new TicketsIssued($order));
        }
    }

    public function orderUrl(Order $order): string
    {
        return rtrim(config('raffle.frontend_url'), '/')."/order/{$order->reference}?key={$order->access_key}";
    }

    public static function newReference(): string
    {
        do {
            $ref = 'WDS-'.strtoupper(Str::random(6));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }
}
