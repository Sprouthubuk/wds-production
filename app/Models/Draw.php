<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draw extends Model
{
    protected $fillable = ['competition_id', 'engine', 'status', 'entries_count', 'entries_hash', 'commitment', 'proof', 'winning_number', 'winning_ticket_id', 'certificate_path', 'run_by', 'drawn_at', 'published_at', 'verity_ref', 'beacon_round', 'beacon_round_at', 'method', 'livestream_url'];

    protected function casts(): array
    {
        return ['drawn_at' => 'datetime', 'published_at' => 'datetime', 'beacon_round_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function winningTicket(): BelongsTo { return $this->belongsTo(Ticket::class, 'winning_ticket_id'); }
}
