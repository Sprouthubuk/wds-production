<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Models\Competition;
use App\Models\IdVerification;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $paid = Order::where('status', 'paid');
        $today = (clone $paid)->where('paid_at', '>=', now()->startOfDay());
        $last7 = Order::where('status', 'paid')->where('paid_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(paid_at) as day, SUM(total_pence) as pence, COUNT(*) as orders')
            ->groupBy('day')->orderBy('day')->get();

        return response()->json(['data' => [
            'revenueToday' => Money::pounds((int) $today->sum('total_pence')),
            'ordersToday' => $today->count(),
            'revenueTotal' => Money::pounds((int) $paid->sum('total_pence')),
            'customers' => User::where('role', 'customer')->count(),
            'postalThisWeek' => PostalEntry::where('created_at', '>=', now()->startOfWeek())->count(),
            'idToReview' => IdVerification::where('status', 'to_review')->count(),
            'refundsNeeded' => Order::where('status', 'cancelled')->whereNotNull('paid_at')->count(),
            'harmFlagsOpen' => \App\Models\HarmFlag::where('status', 'open')->count(),
            'winnersToContact' => \App\Models\Winner::whereNull('contacted_at')->whereNull('forfeited_at')->whereNotNull('drawn_at')
                ->where('drawn_at', '<=', now()->subHours(\App\Models\Winner::CONTACT_WITHIN_HOURS))->count(),
            'daily' => $last7->map(fn ($r) => ['day' => $r->day, 'revenue' => Money::pounds((int) $r->pence), 'orders' => (int) $r->orders]),
            'live' => Competition::where('status', 'live')->orderBy('draw_at')->get()->map(fn ($c) => [
                'id' => $c->id, 'title' => $c->title, 'sold' => $c->tickets_allocated, 'total' => $c->total_tickets,
                'drawAt' => $c->draw_at?->toIso8601String(),
                'revenue' => Money::pounds((int) DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')
                    ->where('orders.status', 'paid')->where('order_lines.competition_id', $c->id)->sum('order_lines.line_total_pence')),
            ]),
            'recentOrders' => Order::with('lines.competition')->latest()->limit(8)->get()->map(fn ($o) => [
                'reference' => $o->reference, 'name' => $o->name, 'tickets' => $o->lines->sum('qty'),
                'total' => Money::pounds($o->total_pence), 'status' => $o->status, 'channel' => $o->channel,
                'createdAt' => $o->created_at->toIso8601String(),
            ]),
        ]]);
    }
}
