<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Draws\VerityDrawEngine;
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
        return (new OrderResource($order))->additional(['customer' => [
            'name' => $order->name, 'email' => $order->email, 'phone' => $order->phone, 'userId' => $order->user_id,
            'provider' => $order->payment_provider, 'providerReference' => $order->provider_reference,
        ]]);
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
