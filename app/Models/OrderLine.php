<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    protected $fillable = ['order_id', 'competition_id', 'qty', 'unit_price_pence', 'line_total_pence'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
}
