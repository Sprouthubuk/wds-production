<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Mail\TicketsIssued;
use App\Models\Competition;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentResult;
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

        $order = DB::transaction(function () use ($items, $customer, $email, $user, $input, $channel, $staff, $paymentMethod) {
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
                if ($held + $qty > $c->max_per_person) {
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

            if ($user && $channel === 'online') {
                $this->responsiblePlay->assertCanSpend($user, $total + $credit);
            }

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

        // Event sales (cash / card reader) and fully-covered orders complete immediately.
        if ($channel === 'event' || $order->total_pence === 0) {
            $this->markPaid($order, $channel === 'event' ? ($paymentMethod ?? 'cash') : 'credit', null);

            return ['order' => $order->fresh(), 'redirectUrl' => $this->orderUrl($order)];
        }

        $hosted = $this->gateway->createHostedPayment($order, $this->orderUrl($order));
        $order->update(['payment_provider' => $this->gateway->name(), 'provider_reference' => $hosted->providerReference]);

        return ['order' => $order, 'redirectUrl' => $hosted->redirectUrl];
    }

    public function handlePaymentResult(PaymentResult $result): ?Order
    {
        $order = Order::where('reference', $result->orderReference)->first();
        if (! $order) {
            Log::warning('Payment webhook for unknown order', ['reference' => $result->orderReference]);

            return null;
        }
        // Idempotent: paid/failed/refunded orders are final. A 'cancelled' order is one whose
        // reservation expired while the customer was still paying — if the money arrives we still
        // try to honour it, and flag a refund if the tickets have gone in the meantime.
        if (! in_array($order->status, ['pending', 'cancelled'], true)) {
            return $order;
        }
        if ($order->status === 'cancelled' && $result->status !== PaymentResult::PAID) {
            return $order;
        }
        if ($result->status === PaymentResult::PAID) {
            if ($result->amountPence !== null && $result->amountPence !== $order->total_pence) {
                Log::error('Payment amount mismatch', ['order' => $order->reference, 'paid' => $result->amountPence, 'expected' => $order->total_pence]);
                $order->update(['status' => 'failed']);

                return $order;
            }
            try {
                $this->markPaid($order, $result->method ?? 'card', $result->providerReference);
            } catch (EntryException $e) {
                Log::critical('Paid order could not be fulfilled — REFUND REQUIRED', ['order' => $order->reference, 'reason' => $e->getMessage()]);
                // paid_at + cancelled = money taken, no tickets → shows in Admin as "refund needed".
                $order->update(['status' => 'cancelled', 'paid_at' => now(), 'payment_method' => $result->method ?? 'card', 'reserved_until' => null, 'provider_reference' => $result->providerReference ?? $order->provider_reference]);
            }
        } elseif ($result->status === PaymentResult::FAILED) {
            $order->update(['status' => 'failed', 'reserved_until' => null]);
        }

        return $order->fresh();
    }

    /** Allocate sequential tickets for every line, take credit, bump code usage, email the customer. */
    public function markPaid(Order $order, string $method, ?string $providerReference): void
    {
        DB::transaction(function () use ($order, $method, $providerReference) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['pending', 'cancelled'], true)) {
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
                'provider_reference' => $providerReference ?? $order->provider_reference,
                'paid_at' => now(),
            ]);
        });

        $order->refresh();
        if ($order->status === 'paid') {
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
