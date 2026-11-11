<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Winner extends Model
{
    protected $fillable = ['competition_id', 'draw_id', 'ticket_id', 'user_id', 'display_name', 'prize', 'image', 'verification', 'handover', 'show_on_site', 'drawn_at',
        'verity_winner_id', 'redraw_number', 'forfeited_at', 'forfeit_category', 'forfeit_note', 'forfeited_by'];

    protected function casts(): array
    {
        return ['show_on_site' => 'boolean', 'drawn_at' => 'datetime', 'forfeited_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function draw(): BelongsTo { return $this->belongsTo(Draw::class); }

    public function isForfeited(): bool
    {
        return $this->forfeited_at !== null;
    }

    /** The public label of a forfeit reason category (as recorded on Verity's audit chain). */
    public static function forfeitLabel(?string $category): ?string
    {
        $label = $category ? (config('raffle.draws.forfeit_reasons')[$category] ?? null) : null;

        return $label ? str_replace(':days', (string) config('raffle.draws.claim_days'), $label) : null;
    }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
