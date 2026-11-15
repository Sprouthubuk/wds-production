<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'first_name', 'last_name', 'email', 'password', 'phone', 'date_of_birth', 'address',
        'marketing_opt_in', 'role', 'limit_daily_pence', 'limit_weekly_pence', 'limit_monthly_pence',
        'pending_limit_increases', 'break_until', 'self_excluded_until', 'suspended_at', 'closed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'marketing_opt_in' => 'boolean',
            'pending_limit_increases' => 'array',
            'break_until' => 'datetime',
            'self_excluded_until' => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
    public function creditTransactions(): HasMany { return $this->hasMany(CreditTransaction::class); }
    public function verifications(): HasMany { return $this->hasMany(IdVerification::class); }

    /** Self-excluded or closed: no entries and no marketing of any kind. */
    public function isExcludedFromMarketing(): bool
    {
        return $this->closed_at !== null || $this->suspended_at !== null || ($this->self_excluded_until && $this->self_excluded_until->isFuture());
    }

    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isStaff(): bool { return in_array($this->role, ['staff', 'admin'], true); }

    public function creditBalancePence(): int
    {
        return (int) $this->creditTransactions()->sum('amount_pence');
    }

    /** True if the customer may not enter right now (break, self-exclusion, suspension or closed account). */
    public function isBlockedFromPlay(): bool
    {
        return $this->closed_at !== null
            || ($this->break_until && $this->break_until->isFuture())
            || ($this->self_excluded_until && $this->self_excluded_until->isFuture())
            || $this->suspended_at !== null;
    }
}
