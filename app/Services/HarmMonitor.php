<?php

namespace App\Services;

use App\Models\HarmFlag;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Responsible Play Policy: harm monitoring. Records the signals and raises a flag for admin review when
 *  - a customer hits a spend limit 3 or more times in 30 days;
 *  - 3 or more different cards are used on one customer's orders in 30 days;
 *  - a customer uses a break tool (break, self-exclusion or closing their account).
 * One open flag per customer and reason; later signals are added to it rather than opening a new one.
 */
class HarmMonitor
{
    public const WINDOW_DAYS = 30;
    public const LIMIT_HITS = 3;
    public const CARDS = 3;

    public function record(?User $user, string $email, string $type, array $details = []): void
    {
        DB::table('responsible_play_events')->insert([
            'user_id' => $user?->id, 'email' => strtolower($email), 'type' => $type,
            'details' => $details ? json_encode($details) : null, 'created_at' => now(),
        ]);

        match ($type) {
            'limit_hit' => $this->countAndFlag($user, $email, 'limit_hit', self::LIMIT_HITS, 'repeated_limit_hits',
                fn ($n) => "Hit a spend limit {$n} times in ".self::WINDOW_DAYS.' days.'),
            'break', 'self_exclusion', 'closure' => $this->flag($user, $email, 'break_tools', match ($type) {
                'break' => 'Took a break'.(isset($details['days']) ? " ({$details['days']} days)" : '').'.',
                'self_exclusion' => 'Self-excluded'.(isset($details['until']) ? " until {$details['until']}" : '').'.',
                'closure' => 'Closed their account permanently.',
            }),
            default => null,
        };
    }

    /** After a card payment: flag when one customer has used several different cards recently. */
    public function cardUsed(Order $order): void
    {
        if (! $order->card_ref) {
            return;
        }
        $refs = Order::query()->whereNotNull('card_ref')->where('paid_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($q) => $q->where('email', $order->email)->when($order->user_id, fn ($q) => $q->orWhere('user_id', $order->user_id)))
            ->distinct()->pluck('card_ref');
        if ($refs->count() >= self::CARDS) {
            $user = $order->user_id ? User::find($order->user_id) : User::where('email', $order->email)->first();
            $this->flag($user, $order->email, 'payment_method_changes', "Used {$refs->count()} different cards in ".self::WINDOW_DAYS.' days.');
        }
    }

    private function countAndFlag(?User $user, string $email, string $type, int $threshold, string $reason, callable $summary): void
    {
        $n = DB::table('responsible_play_events')->where('type', $type)->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where(fn ($q) => $q->where('email', strtolower($email))->when($user, fn ($q) => $q->orWhere('user_id', $user->id)))
            ->count();
        if ($n >= $threshold) {
            $this->flag($user, $email, $reason, $summary($n));
        }
    }

    private function flag(?User $user, string $email, string $reason, string $summary): void
    {
        $open = HarmFlag::where('reason', $reason)->where('status', 'open')
            ->where(fn ($q) => $q->where('email', strtolower($email))->when($user, fn ($q) => $q->orWhere('user_id', $user->id)))
            ->first();
        if ($open) {
            $open->update(['summary' => $summary]);

            return;
        }
        HarmFlag::create(['user_id' => $user?->id, 'email' => strtolower($email), 'reason' => $reason, 'summary' => $summary, 'status' => 'open']);
    }
}
