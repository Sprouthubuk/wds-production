<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\EntryException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Http\Resources\OrderResource;
use App\Mail\OrderRefunded;
use App\Mail\OrderTicketsWithdrawn;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\CreditCardCap;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\PaymentLog;
use App\Services\Payments\PaymentRedactor;
use App\Services\RefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $inDraw = $this->ticketsInDraw($order);

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
                // Refunded in full but its tickets are still in the draw: Admin warns, and offers to take them out while open.
                'ticketsInDraw' => $inDraw,
                'refundedButInDraw' => $order->status === 'refunded' && $inDraw > 0,
                'canWithdrawTickets' => $order->status === 'refunded' && $inDraw > 0 && $this->closedCompetition($order) === null,
                // Ticket numbers taken out of the draw (shown struck through on the order page).
                'withdrawnTickets' => $order->tickets()->with('competition')->whereNotNull('tickets.withdrawn_at')->orderBy('tickets.number')->get()
                    ->map(fn (Ticket $t) => $t->display($t->competition->total_tickets))->values(),
                'history' => $order->paymentEvents->map(fn ($e) => [
                    'at' => $e->created_at?->toIso8601String(), 'kind' => $e->kind, 'status' => $e->status,
                    // Redacted again on the way out: rows stored before a redaction rule existed are masked too.
                    'summary' => $e->summary, 'payload' => $e->payload === null ? null : PaymentRedactor::redact($e->payload),
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
        if ($request->boolean('void_tickets') && ! $full && $left > 0) {
            // Never drop the box silently: a partial refund keeps the tickets in the draw.
            abort(422, 'Tickets can only be taken out of the draw with a full refund of everything left (£'.number_format($left / 100, 2).'). Untick "Take the tickets out of the draw" to make a partial refund.');
        }
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
            DB::transaction(function () use ($order, $void, $verity, $request) {
                $order->update(['status' => 'refunded']);
                if ($void) {
                    $this->voidTickets($order, $verity, $request->user()?->name);
                }
            });
        }
        // The customer hears about it (after the commit). Once the whole payment is back, the email says so in full.
        $full = $order->refundablePence() === 0;
        Mail::to($order->email)->queue(new OrderRefunded($order, $full ? (int) $order->total_pence : $amount, $full, $void));
        $amountText = '£'.number_format($amount / 100, 2);

        return response()->json(['message' => match (true) {
            $order->refundablePence() > 0 => "Refunded {$amountText} ({$refund->refund_number}). £".number_format($order->refundablePence() / 100, 2).' is still paid; the tickets stay in the draw.',
            $void => "Refunded {$amountText} ({$refund->refund_number}). The order is refunded and its tickets are out of the draw.",
            default => "Refunded {$amountText} ({$refund->refund_number}). The order is refunded.".($order->tickets()->whereNull('tickets.withdrawn_at')->exists() ? ' Its tickets stay in the draw.' : ''),
        }]);
    }

    private function assertCanVoid(Order $order, VerityDrawEngine $verity): void
    {
        if ($title = $this->closedCompetition($order, $verity)) {
            abort(422, "{$title} has closed, so its entries are frozen and these tickets stay in the draw. You can still refund the money: untick \"Take the tickets out of the draw\".");
        }
    }

    /** The first of the order's competitions whose entries are frozen (closed, sealed or drawn), if any. */
    private function closedCompetition(Order $order, ?VerityDrawEngine $verity = null): ?string
    {
        $verity ??= app(VerityDrawEngine::class);
        foreach ($order->lines()->with('competition')->get() as $line) {
            $c = $line->competition;
            if ($c->isPublished() && ! $verity->isOpen($c)) {
                return $c->title;
            }
        }

        return null;
    }

    private function ticketsInDraw(Order $order): int
    {
        return $order->tickets()->whereNull('tickets.withdrawn_at')->count();
    }

    /** Takes the order's tickets out of the draw (Verity withdraws its entries) and records it in the order's history. */
    private function voidTickets(Order $order, VerityDrawEngine $verity, ?string $by): void
    {
        $count = $this->ticketsInDraw($order);
        foreach ($order->lines()->with('competition')->get() as $line) {
            if ($line->competition->isPublished()) {
                $verity->withdraw(VerityDrawEngine::hostRef(['order_line_id' => $line->id]), "Refunded (order {$order->reference})");
            }
            Ticket::where('order_line_id', $line->id)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
        }
        if ($count > 0) {
            PaymentLog::record($order, 'tickets', 'Withdrawn', "{$count} ".($count === 1 ? 'ticket' : 'tickets').' taken out of the draw'.($by ? " by {$by}" : ''));
        }
    }

    /**
     * For an order refunded in full whose tickets are still in the draw (the box wasn't ticked, or the money went back
     * another way): takes them out while every competition is still open. Never after close or seal. The customer
     * was last told their tickets stay in the draw, so they get a short note that they've now been taken out.
     */
    public function withdrawTickets(Request $request, Order $order, VerityDrawEngine $verity)
    {
        if ($order->status !== 'refunded') {
            abort(422, 'Only an order refunded in full can have its tickets taken out of the draw here.');
        }
        if ($this->ticketsInDraw($order) === 0) {
            abort(422, "This order's tickets are already out of the draw.");
        }
        $this->assertCanVoid($order, $verity);

        DB::transaction(fn () => $this->voidTickets($order, $verity, $request->user()?->name));
        Mail::to($order->email)->queue(new OrderTicketsWithdrawn($order));

        return response()->json(['message' => "The tickets on {$order->reference} are out of the draw. The customer has been told."]);
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
        $alreadyRefunded = $order->status === 'refunded';

        if ($request->boolean('void_tickets')) {
            foreach ($lines as $line) {
                $c = $line->competition;
                if ($c->isPublished() && ! $verity->isOpen($c)) {
                    abort(422, "{$c->title} has closed, so its entries are frozen and these tickets stay in the draw. You can still refund the money: mark the order refunded without removing the tickets.");
                }
            }
        }

        DB::transaction(function () use ($order, $request, $verity) {
            $order->update(['status' => 'refunded']);
            if ($request->boolean('void_tickets')) {
                $this->voidTickets($order, $verity, $request->user()?->name);
            }
        });
        // Marking an order refunded a second time (to take its tickets out later) doesn't email the customer again.
        if (! $alreadyRefunded) {
            Mail::to($order->email)->queue(new OrderRefunded($order->fresh(), (int) $order->total_pence, true, $request->boolean('void_tickets')));
        }

        return response()->json(['message' => $request->boolean('void_tickets')
            ? 'Order marked as refunded and its tickets taken out of the draw.'
            : 'Order marked as refunded. The tickets stay in the draw.']);
    }
}
