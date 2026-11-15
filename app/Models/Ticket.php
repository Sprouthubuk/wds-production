<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = ['competition_id', 'number', 'channel', 'order_line_id', 'postal_entry_id', 'user_id', 'entrant_id', 'email', 'name', 'verity_entry_id', 'withdrawn_at'];

    protected function casts(): array
    {
        return ['withdrawn_at' => 'datetime'];
    }

    /** Tickets still in the draw (not withdrawn by a refund while the competition was open). */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereNull('withdrawn_at');
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function orderLine(): BelongsTo { return $this->belongsTo(OrderLine::class); }
    public function postalEntry(): BelongsTo { return $this->belongsTo(PostalEntry::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Zero-padded display number, e.g. 0042 for a 750-ticket competition. */
    public function display(int $total): string
    {
        return self::pad($this->number, $total);
    }

    /** A ticket number as everyone sees it: at least 4 digits ("0007"; wider only past 9,999 tickets). */
    public static function pad(int $number, int $total): string
    {
        return str_pad((string) $number, max(4, strlen((string) $total)), '0', STR_PAD_LEFT);
    }

    /** With the hash, as shown in messages: "#0007". */
    public static function format(int $number, int $total): string
    {
        return '#'.self::pad($number, $total);
    }
}
