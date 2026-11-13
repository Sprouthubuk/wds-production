<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call to or from the payment gateway for an order (redacted): the order's payment history. */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'kind', 'status', 'summary', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
