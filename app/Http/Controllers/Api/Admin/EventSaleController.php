<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\Money;
use App\Http\Resources\OrderResource;
use App\Models\Competition;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff-only point of sale for car shows and events. Same sequential numbering as online. */
class EventSaleController extends Controller
{
    public function competitions()
    {
        return CompetitionResource::collection(Competition::where('status', 'live')->where('event_sales_enabled', true)->orderBy('draw_at')->get());
    }

    public function store(Request $request, CheckoutService $checkout)
    {
        $d = $request->validate([
            'competitionId' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:500'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'dateOfBirth' => ['required', 'date'],
            'paymentMethod' => ['required', Rule::in(['cash', 'card_reader'])],
            'marketingOptIn' => ['boolean'],
        ]);
        $result = $checkout->start([
            'customer' => ['email' => $d['email'], 'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'dateOfBirth' => $d['dateOfBirth']],
            'items' => [['competitionId' => $d['competitionId'], 'qty' => $d['qty']]],
            'marketingOptIn' => $d['marketingOptIn'] ?? false,
        ], null, 'event', $request->user(), $d['paymentMethod']);

        return new OrderResource($result['order']);
    }

    /** Today's takings for the current staff member, for cashing up. */
    public function summary(Request $request)
    {
        $orders = Order::where('channel', 'event')->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('staff_user_id', $request->user()->id))->get();

        return response()->json(['data' => [
            'orders' => $orders->count(),
            'cash' => Money::pounds((int) $orders->where('payment_method', 'cash')->sum('total_pence')),
            'card' => Money::pounds((int) $orders->where('payment_method', 'card_reader')->sum('total_pence')),
        ]]);
    }
}
