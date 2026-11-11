<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;

class ResponsiblePlay
{
    public function assertCanSpend(User $user, int $amountPence): void
    {
        $this->applyDueIncreases($user);

        if ($user->suspended_at) {
            throw new EntryException('Your account is suspended. Please contact us.');
        }
        if ($user->self_excluded_until?->isFuture()) {
            throw new EntryException('Your account is self-excluded until '.$user->self_excluded_until->format('j M Y').'.');
        }
        if ($user->break_until?->isFuture()) {
            throw new EntryException('You are on a break until '.$user->break_until->format('j M Y, H:i').'.');
        }

        $now = CarbonImmutable::now();
        foreach ([
            'daily' => [$user->limit_daily_pence, $now->startOfDay()],
            'weekly' => [$user->limit_weekly_pence, $now->startOfWeek()],
            'monthly' => [$user->limit_monthly_pence, $now->startOfMonth()],
        ] as $label => [$limit, $from]) {
            if ($limit === null) {
                continue;
            }
            $spent = $this->spentSince($user, $from);
            if ($spent + $amountPence > $limit) {
                $left = max(0, $limit - $spent);
                throw new EntryException(sprintf(
                    'This would take you over your %s spending limit. You have £%s left.',
                    $label, number_format($left / 100, 2)
                ));
            }
        }
    }

    public function spentSince(User $user, \DateTimeInterface $from): int
    {
        return (int) Order::where('user_id', $user->id)
            ->where(fn ($q) => $q->where('status', 'paid')
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('reserved_until', '>', now())))
            ->where('created_at', '>=', $from)
            ->sum('total_pence');
    }

    /**
     * Lower limits apply immediately; raising or removing a limit waits 24 hours.
     * @param array{daily?:int|null, weekly?:int|null, monthly?:int|null} $limits pence, null = no limit
     * @return array<string,string> messages per period
     */
    public function setLimits(User $user, array $limits): array
    {
        $this->applyDueIncreases($user);
        $pending = $user->pending_limit_increases ?? [];
        $messages = [];
        $effective = now()->addHours(config('raffle.limit_increase_delay_hours'))->toIso8601String();

        foreach (['daily', 'weekly', 'monthly'] as $period) {
            if (! array_key_exists($period, $limits)) {
                continue;
            }
            $column = "limit_{$period}_pence";
            $new = $limits[$period];
            $current = $user->{$column};

            $isDecrease = $new !== null && ($current === null || $new <= $current);
            if ($isDecrease) {
                $user->{$column} = $new;
                unset($pending[$period]);
                $messages[$period] = 'Applied now.';
            } else {
                $pending[$period] = ['value' => $new, 'effective_at' => $effective];
                $messages[$period] = 'Takes effect in 24 hours.';
            }
        }

        $user->pending_limit_increases = $pending ?: null;
        $user->save();

        return $messages;
    }

    public function applyDueIncreases(User $user): void
    {
        $pending = $user->pending_limit_increases ?? [];
        $changed = false;
        foreach ($pending as $period => $change) {
            if (now()->greaterThanOrEqualTo($change['effective_at'])) {
                $user->{"limit_{$period}_pence"} = $change['value'];
                unset($pending[$period]);
                $changed = true;
            }
        }
        if ($changed) {
            $user->pending_limit_increases = $pending ?: null;
            $user->save();
        }
    }

    public static function isAdult(\DateTimeInterface|string|null $dob): bool
    {
        if (! $dob) {
            return false;
        }
        $dob = CarbonImmutable::parse($dob);

        return $dob->addYears(config('raffle.minimum_age'))->lessThanOrEqualTo(now());
    }
}
