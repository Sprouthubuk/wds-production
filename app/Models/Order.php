<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Order extends Model
{
    protected $fillable = [
        'reference', 'access_key', 'user_id', 'entrant_id', 'email', 'name', 'phone', 'date_of_birth', 'channel', 'status',
        'subtotal_pence', 'bundle_saving_pence', 'discount_pence', 'credit_used_pence', 'total_pence',
        'discount_code_id', 'payment_method', 'payment_provider', 'provider_reference', 'staff_user_id', 'card_type', 'status_note', 'card_ref',
        'marketing_opt_in', 'reserved_until', 'paid_at',
        'payment_job_reference', 'payment_reference', 'card_type_source', 'refunded_pence',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'marketing_opt_in' => 'boolean',
            'reserved_until' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function lines(): HasMany { return $this->hasMany(OrderLine::class); }
    public function tickets(): HasManyThrough { return $this->hasManyThrough(Ticket::class, OrderLine::class); }
    public function discountCode(): BelongsTo { return $this->belongsTo(DiscountCode::class); }
    public function paymentEvents(): HasMany { return $this->hasMany(PaymentEvent::class); }
    public function refunds(): HasMany { return $this->hasMany(OrderRefund::class); }

    /** What can still be refunded through the gateway: paid by card and not yet refunded. */
    public function refundablePence(): int
    {
        return $this->paid_at && $this->payment_provider && $this->payment_provider !== 'credit'
            ? max(0, (int) $this->total_pence - (int) $this->refunded_pence)
            : 0;
    }
}
