<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer flagged for responsible-play review (HarmMonitor). Staff add notes and act on it. */
class HarmFlag extends Model
{
    public const REASONS = [
        'repeated_limit_hits' => 'Repeatedly hitting a spend limit',
        'payment_method_changes' => 'Frequent payment method changes',
        'break_tools' => 'Used a break tool',
    ];

    public const STATUSES = ['open', 'contacted', 'limit_lowered', 'blocked', 'closed'];

    protected $fillable = ['user_id', 'email', 'reason', 'summary', 'status', 'notes', 'actioned_by', 'actioned_at'];

    protected function casts(): array
    {
        return ['actioned_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
