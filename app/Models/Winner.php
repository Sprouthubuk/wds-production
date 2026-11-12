<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Winner extends Model
{
    protected $fillable = ['competition_id', 'draw_id', 'ticket_id', 'user_id', 'display_name', 'prize', 'image', 'verification', 'handover', 'show_on_site', 'drawn_at',
        'verity_winner_id', 'redraw_number', 'forfeited_at', 'forfeit_category', 'forfeit_note', 'forfeited_by', 'contacted_at', 'contact_reminder_sent_at'];

    protected function casts(): array
    {
        return ['show_on_site' => 'boolean', 'drawn_at' => 'datetime', 'forfeited_at' => 'datetime', 'contacted_at' => 'datetime', 'contact_reminder_sent_at' => 'datetime'];
    }

    public const CONTACT_WITHIN_HOURS = 48;

    /** The winner has this long from the draw to claim (terms: 14 days). */
    public function claimBy(): ?\Illuminate\Support\Carbon
    {
        return $this->drawn_at?->copy()->addDays((int) config('raffle.draws.claim_days'));
    }

    /** Not contacted within 48 hours of the draw (and still a live claim). */
    public function contactOverdue(): bool
    {
        return ! $this->contacted_at && ! $this->isForfeited() && $this->drawn_at
            && $this->drawn_at->lte(now()->subHours(self::CONTACT_WITHIN_HOURS));
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
