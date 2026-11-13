<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A refund (full or partial) sent to the payment gateway. */
class OrderRefund extends Model
{
    protected $fillable = [
        'order_id', 'refund_number', 'amount_pence', 'reason', 'status', 'provider_reference',
        'error_code', 'error_message', 'automatic', 'staff_user_id',
    ];

    protected function casts(): array
    {
        return ['automatic' => 'boolean'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'staff_user_id'); }
}
