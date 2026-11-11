<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Http\Resources\OrderResource;
use App\Models\Order;
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
     * Marks an order refunded (the refund itself is issued in the payment provider's dashboard).
     * Tickets stay allocated but are flagged by the order status and excluded from the draw list
     * only if `void_tickets` is true — normally refunds happen before the draw is prepared.
     */
    public function markRefunded(Request $request, Order $order)
    {
        $request->validate(['void_tickets' => ['boolean']]);
        $order->update(['status' => 'refunded']);
        if ($request->boolean('void_tickets')) {
            \App\Models\Ticket::whereIn('order_line_id', $order->lines()->pluck('id'))->delete();
        }

        return response()->json(['message' => 'Order marked as refunded.']);
    }
}
