<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\HarmFlag;
use App\Models\IdVerification;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Admin dashboard (read-only). Days are UK days. Revenue is net of refunds: what was kept from orders paid in
 * the period (a paid order's total less any partial refund; a refunded order counts nothing).
 */
class DashboardController extends Controller
{
    private const TZ = 'Europe/London';
    private const LIST_LIMIT = 5;

    public function __invoke()
    {
        $now = CarbonImmutable::now(self::TZ);
        $today = $now->startOfDay()->utc();
        $week = $now->subDays(6)->startOfDay()->utc();
        $month = $now->startOfMonth()->utc();

        $monthNet = $this->netPence($month);
        $monthOrders = Order::where('status', 'paid')->where('paid_at', '>=', $month)->where('total_pence', '>', 0)->count();

        return response()->json(['data' => [
            'kpis' => [
                'revenueToday' => Money::pounds($this->netPence($today)),
                'ordersToday' => Order::where('status', 'paid')->where('paid_at', '>=', $today)->count(),
                'revenue7Days' => Money::pounds($this->netPence($week)),
                'revenueMonth' => Money::pounds($monthNet),
                // Tickets on paid orders, issued today and still in the draw.
                'ticketsToday' => Ticket::whereNull('withdrawn_at')->where('created_at', '>=', $today)
                    ->whereHas('orderLine.order', fn ($o) => $o->where('status', 'paid'))->count(),
                'newCustomers7Days' => User::where('role', 'customer')->where('created_at', '>=', $week)->count(),
                'newCustomersToday' => User::where('role', 'customer')->where('created_at', '>=', $today)->count(),
                'averageOrderMonth' => $monthOrders ? Money::pounds(intdiv($monthNet, $monthOrders)) : null,
            ],
            'daily' => $this->daily($now),
            'live' => $this->live(),
            'attention' => $this->attention(),
            'recentOrders' => Order::with('lines')->latest()->limit(10)->get()->map(fn (Order $o) => [
                'id' => $o->id, 'reference' => $o->reference, 'name' => $o->name, 'tickets' => (int) $o->lines->sum('qty'),
                'total' => Money::pounds($o->total_pence), 'status' => $o->status, 'channel' => $o->channel,
                'refundNeeded' => $o->status === 'cancelled' && $o->paid_at !== null,
                'createdAt' => $o->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    private function netPence(CarbonImmutable $from): int
    {
        return (int) Order::where('status', 'paid')->where('paid_at', '>=', $from)
            ->sum(DB::raw('total_pence - COALESCE(refunded_pence, 0)'));
    }

    /** @return list<array{day: string, revenue: float, orders: int}> the last 30 UK days, oldest first, zeros included */
    private function daily(CarbonImmutable $now): array
    {
        $from = $now->subDays(29)->startOfDay();
        $days = [];
        for ($d = $from; $d <= $now; $d = $d->addDay()) {
            $days[$d->toDateString()] = ['pence' => 0, 'orders' => 0];
        }
        Order::where('status', 'paid')->where('paid_at', '>=', $from->utc())->get(['paid_at', 'total_pence', 'refunded_pence'])
            ->each(function (Order $o) use (&$days) {
                $key = $o->paid_at->copy()->timezone(self::TZ)->toDateString();
                if (isset($days[$key])) {
                    $days[$key]['pence'] += (int) $o->total_pence - (int) $o->refunded_pence;
                    $days[$key]['orders']++;
                }
            });

        return array_map(fn ($day, $v) => ['day' => $day, 'revenue' => Money::pounds($v['pence']), 'orders' => $v['orders']], array_keys($days), $days);
    }

    private function live(): array
    {
        return Competition::where('status', 'live')->orderBy('closes_at')->get()->map(fn (Competition $c) => [
            'id' => $c->id, 'title' => $c->title, 'image' => $c->images[0]['src'] ?? null,
            'sold' => (int) $c->tickets_allocated, 'total' => (int) $c->total_tickets,
            'revenue' => Money::pounds((int) DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->where('orders.status', 'paid')->where('order_lines.competition_id', $c->id)->sum('order_lines.line_total_pence')),
            'closesAt' => $c->closes_at?->toIso8601String(),
            'drawAt' => $c->draw_at?->toIso8601String(),
            'drawMethod' => match (true) {
                $c->drawsPhysically() => 'Physical draw',
                $c->drawsExternally() => 'External RNG',
                default => 'Verity (drand)',
            },
        ])->all();
    }

    /** What needs doing, each item linking straight to it. Each list is capped; `count` is the full number. */
    private function attention(): array
    {
        $soon = now()->addHours(48);
        $money = fn (Order $o) => '£'.number_format($o->total_pence / 100, 2);

        $refundNeeded = Order::where('status', 'cancelled')->whereNotNull('paid_at');
        $stalePending = Order::where('status', 'pending')->where('created_at', '<=', now()->subMinutes(15));
        $refundedInDraw = Order::where('status', 'refunded')->whereHas('tickets', fn ($t) => $t->whereNull('tickets.withdrawn_at'));
        $closing = Competition::where('status', 'live')->whereBetween('closes_at', [now(), $soon]);
        $postal = Competition::where('status', 'live')->where('postal_entry_enabled', true)->whereNotNull('closes_at')->get()
            ->filter(fn (Competition $c) => ($d = $c->postalClosesAt()) && $d->isFuture() && $d->lte($soon))->values();
        $published = Draw::where('status', 'published')->pluck('competition_id');
        $awaitingDraw = Competition::whereIn('status', ['live', 'closed'])->whereNotNull('draw_at')->where('draw_at', '<=', now())->whereNotIn('id', $published);
        $harm = HarmFlag::where('status', 'open');
        $winners = Winner::whereNull('contacted_at')->whereNull('forfeited_at')->whereNotNull('drawn_at')->where('drawn_at', '<=', now()->subHours(Winner::CONTACT_WITHIN_HOURS));

        $group = fn (string $key, string $title, $query, callable $item, string $all) => [
            'key' => $key, 'title' => $title, 'count' => (clone $query)->count(), 'href' => $all,
            'items' => (clone $query)->limit(self::LIST_LIMIT)->get()->map($item)->all(),
        ];

        return array_values(array_filter([
            $group('refund-needed', 'Refund needed', $refundNeeded->latest('paid_at'), fn (Order $o) => ['label' => $o->reference, 'detail' => "Paid {$money($o)}, no tickets: refund it", 'href' => "/admin/orders/{$o->id}", 'at' => $o->paid_at?->toIso8601String()], '/admin/orders?status=cancelled'),
            $group('stale-pending', 'Payments pending over 15 minutes', $stalePending->oldest(), fn (Order $o) => ['label' => $o->reference, 'detail' => "{$money($o)} · started ".$o->created_at?->diffForHumans(), 'href' => "/admin/orders/{$o->id}", 'at' => $o->created_at?->toIso8601String()], '/admin/orders?status=pending'),
            $group('refunded-in-draw', 'Refunded, tickets still in the draw', $refundedInDraw->latest(), fn (Order $o) => ['label' => $o->reference, 'detail' => 'Refunded in full; take the tickets out', 'href' => "/admin/orders/{$o->id}", 'at' => null], '/admin/orders?status=refunded'),
            [
                'key' => 'postal-deadline', 'title' => 'Postal deadline within 48 hours', 'count' => $postal->count(), 'href' => '/admin/postal',
                'items' => $postal->take(self::LIST_LIMIT)->map(fn (Competition $c) => ['label' => $c->title, 'detail' => 'Log received postal entries before the deadline', 'href' => '/admin/postal', 'at' => $c->postalClosesAt()?->toIso8601String()])->all(),
            ],
            $group('closing-soon', 'Closing within 48 hours', $closing->orderBy('closes_at'), fn (Competition $c) => ['label' => $c->title, 'detail' => "{$c->tickets_allocated} / {$c->total_tickets} sold", 'href' => "/admin/competitions/{$c->id}", 'at' => $c->closes_at?->toIso8601String()], '/admin/competitions'),
            $group('awaiting-draw', 'Draws awaiting a result', $awaitingDraw->orderBy('draw_at'), fn (Competition $c) => ['label' => $c->title, 'detail' => 'Draw time has passed; no published result yet', 'href' => '/admin/draws', 'at' => $c->draw_at?->toIso8601String()], '/admin/draws'),
            $group('harm-flags', 'Responsible play alerts', $harm->latest(), fn (HarmFlag $f) => ['label' => $f->email ?? 'Alert', 'detail' => (string) ($f->summary ?? $f->reason), 'href' => '/admin/responsible-play', 'at' => $f->created_at?->toIso8601String()], '/admin/responsible-play'),
            $group('winners', 'Winners to contact (over 48 hours)', $winners->oldest('drawn_at'), fn (Winner $w) => ['label' => (string) $w->display_name, 'detail' => (string) $w->prize, 'href' => '/admin/winners', 'at' => $w->drawn_at?->toIso8601String()], '/admin/winners'),
            [
                'key' => 'id-review', 'title' => 'ID checks to review', 'count' => IdVerification::where('status', 'to_review')->count(), 'href' => '/admin/customers#verifications',
                'items' => [],
            ],
        ], fn ($g) => $g['count'] > 0));
    }
}
