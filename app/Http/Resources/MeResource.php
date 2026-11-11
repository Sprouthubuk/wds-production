<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'address' => $this->address,
            'marketingOptIn' => (bool) $this->marketing_opt_in,
            'siteCredit' => Money::pounds($this->creditBalancePence()),
            'limits' => [
                'daily' => Money::pounds($this->limit_daily_pence),
                'weekly' => Money::pounds($this->limit_weekly_pence),
                'monthly' => Money::pounds($this->limit_monthly_pence),
            ],
            'pendingLimitIncreases' => collect($this->pending_limit_increases ?? [])
                ->map(fn ($c) => ['value' => Money::pounds($c['value']), 'effectiveAt' => $c['effective_at']]),
            'breakUntil' => $this->break_until?->isFuture() ? $this->break_until->toIso8601String() : null,
            'selfExcludedUntil' => $this->self_excluded_until?->isFuture() ? $this->self_excluded_until->toIso8601String() : null,
            'isAdmin' => $this->isAdmin(),
            'isStaff' => $this->isStaff(),
            'verification' => $this->verifications()->latest()->value('status'),
        ];
    }
}
