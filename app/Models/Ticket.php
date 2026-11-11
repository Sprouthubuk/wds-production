<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = ['competition_id', 'number', 'channel', 'order_line_id', 'postal_entry_id', 'user_id', 'entrant_id', 'email', 'name'];

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function orderLine(): BelongsTo { return $this->belongsTo(OrderLine::class); }
    public function postalEntry(): BelongsTo { return $this->belongsTo(PostalEntry::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Zero-padded display number, e.g. 0042 for a 750-ticket competition. */
    public function display(int $total): string
    {
        return str_pad((string) $this->number, max(4, strlen((string) $total)), '0', STR_PAD_LEFT);
    }
}
