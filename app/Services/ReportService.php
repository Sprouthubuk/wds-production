<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\DiscountCode;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Reports. Every report covers a date range of whole UK days (from 00:00 on `from` to the
 * end of `to`, Europe/London). Money counts PAID orders by the time they were paid; refunded,
 * failed, cancelled and pending orders are left out. Postal entries count by the time received.
 * Amounts are pence; the controller formats them.
 */
class ReportService
{
    /** @return array{0: Carbon, 1: Carbon} UTC bounds for whole UK days. */
    public static function range(?string $from, ?string $to): array
    {
        $tz = 'Europe/London';
        $end = $to ? Carbon::parse($to, $tz)->endOfDay() : Carbon::now($tz)->endOfDay();
        $start = $from ? Carbon::parse($from, $tz)->startOfDay() : $end->copy()->subDays(29)->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start->utc(), $end->utc()];
    }

    /** Sales by competition: tickets and revenue per channel (online, event, postal). */
    public function salesByCompetition(Carbon $from, Carbon $to): Collection
    {
        $lines = DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.status', 'paid')->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('order_lines.competition_id', 'orders.channel')
            ->select('order_lines.competition_id', 'orders.channel', DB::raw('SUM(order_lines.qty) as tickets'), DB::raw('SUM(order_lines.line_total_pence) as revenue'), DB::raw('COUNT(DISTINCT orders.id) as orders'))
            ->get();
        $postal = PostalEntry::query()->where('status', 'allocated')
            ->whereBetween(DB::raw('COALESCE(received_at, received_on)'), [$from, $to])
            ->groupBy('competition_id')->select('competition_id', DB::raw('COUNT(*) as n'))->pluck('n', 'competition_id');

        $ids = $lines->pluck('competition_id')->merge($postal->keys())->unique();

        return Competition::whereIn('id', $ids)->orderBy('draw_at')->get()->map(function (Competition $c) use ($lines, $postal) {
            $row = fn (string $channel, string $field) => (int) ($lines->first(fn ($l) => $l->competition_id === $c->id && $l->channel === $channel)?->{$field} ?? 0);

            return [
                'competition' => $c->title,
                'status' => $c->status,
                'onlineTickets' => $row('online', 'tickets'),
                'onlineRevenue' => $row('online', 'revenue'),
                'eventTickets' => $row('event', 'tickets'),
                'eventRevenue' => $row('event', 'revenue'),
                'postalEntries' => (int) ($postal[$c->id] ?? 0),
                'totalTickets' => $row('online', 'tickets') + $row('event', 'tickets') + (int) ($postal[$c->id] ?? 0),
                'totalRevenue' => $row('online', 'revenue') + $row('event', 'revenue'),
            ];
        })->values();
    }

    /** Event takings by staff member and payment method. */
    public function eventTakings(Carbon $from, Carbon $to): Collection
    {
        $rows = Order::query()->where('channel', 'event')->where('status', 'paid')->whereBetween('paid_at', [$from, $to])
            ->groupBy('staff_user_id', 'payment_method')
            ->select('staff_user_id', 'payment_method', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total_pence) as total'))
            ->get();
        $tickets = DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('orders.channel', 'event')->where('orders.status', 'paid')->whereBetween('orders.paid_at', [$from, $to])
            ->groupBy('orders.staff_user_id', 'orders.payment_method')
            ->select('orders.staff_user_id', 'orders.payment_method', DB::raw('SUM(order_lines.qty) as tickets'))->get();
        $staff = User::whereIn('id', $rows->pluck('staff_user_id')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'staff' => $staff[$r->staff_user_id] ?? 'Unknown',
            'paymentMethod' => self::methodLabel($r->payment_method),
            'orders' => (int) $r->orders,
            'tickets' => (int) ($tickets->first(fn ($t) => $t->staff_user_id === $r->staff_user_id && $t->payment_method === $r->payment_method)?->tickets ?? 0),
            'total' => (int) $r->total,
        ])->sortBy([['staff', 'asc'], ['paymentMethod', 'asc']])->values();
    }

    /** Postal entries received in the range, accepted and rejected, with the reason. */
    public function postalEntries(Carbon $from, Carbon $to): Collection
    {
        return PostalEntry::with('competition:id,title,total_tickets', 'ticket')
            ->whereBetween(DB::raw('COALESCE(received_at, received_on)'), [$from, $to])
            ->orderBy('id')->get()
            ->map(fn (PostalEntry $e) => [
                'receivedAt' => $e->receivedMoment(),
                'competition' => $e->competition->title,
                'name' => $e->name,
                'email' => $e->email,
                'status' => $e->status === 'allocated' ? 'accepted' : 'rejected',
                'ticket' => $e->ticket?->display($e->competition->total_tickets),
                'reason' => $e->rejection_reason,
            ]);
    }

    /**
     * Customers who said yes to marketing: accounts with marketing_opt_in, guests whose latest
     * order opted in, and newsletter sign-ups that haven't unsubscribed. Nobody else is exported.
     * The range filters on when they joined / signed up.
     */
    public function marketingCustomers(Carbon $from, Carbon $to): Collection
    {
        $out = collect();
        User::where('role', 'customer')->where('marketing_opt_in', true)->whereNull('suspended_at')
            ->whereBetween('created_at', [$from, $to])->orderBy('id')->get()
            ->each(function (User $u) use ($out) {
                $out[$u->email] = ['email' => $u->email, 'firstName' => $u->first_name, 'lastName' => $u->last_name, 'source' => 'Account', 'since' => $u->created_at];
            });

        $latest = Order::query()->whereNull('user_id')->whereIn('status', ['paid'])->whereBetween('created_at', [$from, $to])
            ->orderBy('id')->get(['email', 'name', 'marketing_opt_in', 'created_at'])->keyBy('email');
        foreach ($latest as $email => $o) {
            if ($o->marketing_opt_in && ! isset($out[$email])) {
                [$first, $last] = array_pad(explode(' ', trim($o->name), 2), 2, '');
                $out[$email] = ['email' => $email, 'firstName' => $first, 'lastName' => $last, 'source' => 'Guest order', 'since' => $o->created_at];
            }
        }

        NewsletterSubscriber::whereNull('unsubscribed_at')->whereBetween('created_at', [$from, $to])->orderBy('id')->get()
            ->each(function ($s) use ($out) {
                if (! isset($out[$s->email])) {
                    $out[$s->email] = ['email' => $s->email, 'firstName' => '', 'lastName' => '', 'source' => 'Newsletter', 'since' => $s->created_at];
                }
            });

        // Never market to anyone self-excluded or suspended, and an account's own "no" beats an older
        // guest-order or newsletter "yes" for the same email.
        $excluded = User::whereIn('email', $out->keys())
            ->where(fn ($q) => $q->where('self_excluded_until', '>', now())->orWhereNotNull('suspended_at')->orWhereNotNull('closed_at')->orWhere('marketing_opt_in', false))
            ->pluck('email')->flip();

        return $out->reject(fn ($r, $email) => isset($excluded[$email]))->values();
    }

    /** Discount code usage: paid orders that used each code in the range. */
    public function discountUsage(Carbon $from, Carbon $to): Collection
    {
        $rows = Order::query()->whereNotNull('discount_code_id')->where('status', 'paid')->whereBetween('paid_at', [$from, $to])
            ->groupBy('discount_code_id')
            ->select('discount_code_id', DB::raw('COUNT(*) as orders'), DB::raw('SUM(discount_pence) as discount'), DB::raw('SUM(total_pence) as revenue'))
            ->get()->keyBy('discount_code_id');

        return DiscountCode::orderBy('code')->get()
            ->filter(fn (DiscountCode $d) => isset($rows[$d->id]))
            ->map(fn (DiscountCode $d) => [
                'code' => $d->code,
                'type' => $d->type === 'percent' ? "{$d->value}%" : '£'.number_format($d->value / 100, 2),
                'orders' => (int) $rows[$d->id]->orders,
                'discount' => (int) $rows[$d->id]->discount,
                'revenue' => (int) $rows[$d->id]->revenue,
                'usedAllTime' => (int) $d->times_used,
                'active' => (bool) $d->active,
            ])->values();
    }

    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'card_reader' => 'Card reader',
            'card' => 'Card (online)',
            null, '' => 'Unknown',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }
}
