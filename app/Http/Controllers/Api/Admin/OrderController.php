<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\EntryException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\CreditCardCap;
use App\Services\Draws\VerityDrawEngine;
use App\Services\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $q = Order::with('lines.competition')->latest();
        if ($s = $request->query('q')) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%$s%")->orWhere('email', 'like', "%$s%")->orWhere('name', 'like', "%$s%"));
        }
        if ($status = $request->query('status')) $q->where('status', $status);
        if ($channel = $request->query('channel')) $q->where('channel', $channel);
        if ($cid = $request->query('competition')) $q->whereHas('lines', fn ($l) => $l->where('competition_id', $cid));

        return $q->paginate(50)->through(fn ($o) => [
            'id' => $o->id, 'reference' => $o->reference, 'name' => $o->name, 'email' => $o->email,
            'competition' => $o->lines->map(fn ($l) => $l->competition->title)->unique()->implode(', '),
            'tickets' => $o->lines->sum('qty'), 'total' => Money::pounds($o->total_pence),
            'status' => $o->status, 'channel' => $o->channel, 'paymentMethod' => $o->payment_method,
            'createdAt' => $o->created_at->toIso8601String(),
        ]);
    }

    public function show(Order $order)
    {
        $order->load(['paymentEvents' => fn ($q) => $q->orderBy('id'), 'refunds' => fn ($q) => $q->orderBy('id'), 'refunds.staff']);
        $countsAsCredit = $order->paid_at && CreditCardCap::countsAsCredit($order->card_type, $order->payment_provider);

        return (new OrderResource($order))->additional([
            'customer' => [
                'name' => $order->name, 'email' => $order->email, 'phone' => $order->phone, 'userId' => $order->user_id,
                'provider' => $order->payment_provider, 'providerReference' => $order->provider_reference,
            ],
            // Admin -> order -> Payment panel.
            'payment' => [
                'id' => $order->id,
                'status' => $order->status,
                'provider' => $order->payment_provider,
                'method' => $order->payment_method,
                'channel' => $order->channel,
                'paymentJobReference' => $order->payment_job_reference,
                'paymentReference' => $order->payment_reference,
                'paidAt' => $order->paid_at?->toIso8601String(),
                'reservedUntil' => $order->reserved_until?->toIso8601String(),
                'cardType' => $order->card_type,
                'cardTypeSource' => $order->card_type_source,
                'countsAsCredit' => (bool) $countsAsCredit,
                'total' => Money::pounds($order->total_pence),
                'refunded' => Money::pounds((int) $order->refunded_pence),
                'refundable' => Money::pounds($order->refundablePence()),
                'refundNeeded' => $order->status === 'cancelled' && $order->paid_at !== null,
                'history' => $order->paymentEvents->map(fn ($e) => [
                    'at' => $e->created_at?->toIso8601String(), 'kind' => $e->kind, 'status' => $e->status,
                    'summary' => $e->summary, 'payload' => $e->payload,
                ])->values(),
                'refunds' => $order->refunds->map(fn ($r) => [
                    'number' => $r->refund_number, 'amount' => Money::pounds($r->amount_pence), 'status' => $r->status,
                    'reason' => $r->reason, 'automatic' => $r->automatic, 'by' => $r->staff?->name,
                    'reference' => $r->provider_reference, 'errorCode' => $r->error_code, 'error' => $r->error_message,
                    'at' => $r->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Refund through the payment gateway, full or partial. A full refund marks the order refunded (and, with
     * void_tickets, takes its tickets out of the draw while the competition is still open, as markRefunded does).
     * A partial refund keeps the order paid and its tickets in the draw.
     */
    public function refund(Request $request, Order $order, RefundService $refunds, VerityDrawEngine $verity)
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'void_tickets' => ['boolean'],
        ]);
        $left = $order->refundablePence();
        $amount = isset($data['amount']) ? (int) round($data['amount'] * 100) : $left;
        $full = $amount >= $left && $left > 0;
        $void = $full && $request->boolean('void_tickets') && $order->status === 'paid';
        if ($void) {
            $this->assertCanVoid($order, $verity);
        }

        try {
            $refund = $refunds->refund($order, $amount, $data['reason'], $request->user());
        } catch (EntryException $e) {
            abort(422, $e->getMessage());
        }
        if ($refund->status !== 'completed') {
            abort(422, $refund->error_message ?? 'The refund was refused. Nothing was refunded.');
        }

        $order->refresh();
        if ($order->refundablePence() === 0) {
            DB::transaction(function () use ($order, $void, $verity) {
                $order->update(['status' => 'refunded']);
                if ($void) {
                    $this->voidTickets($order, $verity);
                }
            });
        }
        $amountText = '£'.number_format($amount / 100, 2);

        return response()->json(['message' => match (true) {
            $order->refundablePence() > 0 => "Refunded {$amountText} ({$refund->refund_number}). £".number_format($order->refundablePence() / 100, 2).' is still paid; the tickets stay in the draw.',
            $void => "Refunded {$amountText} ({$refund->refund_number}). The order is refunded and its tickets are out of the draw.",
            default => "Refunded {$amountText} ({$refund->refund_number}). The order is refunded.".($order->tickets()->whereNull('tickets.withdrawn_at')->exists() ? ' Its tickets stay in the draw.' : ''),
        }]);
    }

    private function assertCanVoid(Order $order, VerityDrawEngine $verity): void
    {
        foreach ($order->lines()->with('competition')->get() as $line) {
            $c = $line->competition;
            if ($c->isPublished() && ! $verity->isOpen($c)) {
                abort(422, "{$c->title} has closed, so its entries are frozen and these tickets stay in the draw. You can still refund the money: untick \"Take the tickets out of the draw\".");
            }
        }
    }

    private function voidTickets(Order $order, VerityDrawEngine $verity): void
    {
        foreach ($order->lines()->with('competition')->get() as $line) {
            if ($line->competition->isPublished()) {
                $verity->withdraw(VerityDrawEngine::hostRef(['order_line_id' => $line->id]), "Refunded (order {$order->reference})");
            }
            Ticket::where('order_line_id', $line->id)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
        }
    }

    /**
     * Marks an order refunded (the money goes back in the payment provider's dashboard).
     *
     * With void_tickets, the order's tickets also come out of the draw, which Verity allows only while
     * the competition is still open (withdrawEntries). Once it has closed the entry list is frozen: the
     * refund is money only and the tickets stay in the draw. Withdrawn numbers are never reused.
     */
    public function markRefunded(Request $request, Order $order, VerityDrawEngine $verity)
    {
        $request->validate(['void_tickets' => ['boolean']]);
        $lines = $order->lines()->with('competition')->get();

        if ($request->boolean('void_tickets')) {
            foreach ($lines as $line) {
                $c = $line->competition;
                if ($c->isPublished() && ! $verity->isOpen($c)) {
                    abort(422, "{$c->title} has closed, so its entries are frozen and these tickets stay in the draw. You can still refund the money: mark the order refunded without removing the tickets.");
                }
            }
        }

        DB::transaction(function () use ($order, $lines, $request, $verity) {
            $order->update(['status' => 'refunded']);
            if (! $request->boolean('void_tickets')) {
                return;
            }
            foreach ($lines as $line) {
                if ($line->competition->isPublished()) {
                    $verity->withdraw(VerityDrawEngine::hostRef(['order_line_id' => $line->id]), "Refunded (order {$order->reference})");
                }
                Ticket::where('order_line_id', $line->id)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
            }
        });

        return response()->json(['message' => $request->boolean('void_tickets')
            ? 'Order marked as refunded and its tickets taken out of the draw.'
            : 'Order marked as refunded. The tickets stay in the draw.']);
    }
}
