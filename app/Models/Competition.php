<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competition extends Model
{
    protected $fillable = [
        'slug', 'title', 'strapline', 'category', 'badge', 'status', 'images', 'description', 'specs',
        'ticket_price_pence', 'bundles', 'total_tickets', 'max_per_person', 'cash_alternative_pence',
        'build_video_url', 'opens_at', 'closes_at', 'postal_closes_at', 'draw_at', 'postal_entry_enabled', 'event_sales_enabled', 'sort_order',
        'verity_ref', 'verity_status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'description' => 'array',
            'specs' => 'array',
            'bundles' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'postal_closes_at' => 'datetime',
            'draw_at' => 'datetime',
            'published_at' => 'datetime',
            'postal_entry_enabled' => 'boolean',
            'event_sales_enabled' => 'boolean',
        ];
    }

    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
    public function orderLines(): HasMany { return $this->hasMany(OrderLine::class); }
    public function postalEntries(): HasMany { return $this->hasMany(PostalEntry::class); }
    public function draws(): HasMany { return $this->hasMany(Draw::class); }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', ['live', 'closed', 'drawn']);
    }

    /** Open for entries right now. */
    public function isOpen(): bool
    {
        return $this->status === 'live'
            && (! $this->opens_at || $this->opens_at->isPast())
            && (! $this->closes_at || $this->closes_at->isFuture())
            && $this->draw_at->isFuture();
    }

    /** Tickets held by pending (unpaid, unexpired) online orders. */
    public function reservedPending(): int
    {
        return (int) OrderLine::query()
            ->where('competition_id', $this->id)
            ->whereHas('order', fn ($q) => $q->where('status', 'pending')->where('reserved_until', '>', now()))
            ->sum('qty');
    }

    /** Published on the Verity draw engine: the draw is locked in (terms fixed, drand round committed). */
    public function isPublished(): bool
    {
        return $this->verity_ref !== null;
    }

    /** Postal entries must be received at least this many hours before closes_at, unless an admin set postal_closes_at. */
    public const POSTAL_CLOSE_HOURS_BEFORE = 48;

    /** The rejection reason for an envelope received after the default postal deadline. */
    public const POSTAL_LATE_REASON = 'Received less than 48 hours before close';

    /** Postal entries must be received by this moment: postal_closes_at, or 48 hours before closes_at. */
    public function postalClosesAt(): ?\Illuminate\Support\Carbon
    {
        return $this->postal_closes_at ?? $this->closes_at?->copy()->subHours(self::POSTAL_CLOSE_HOURS_BEFORE);
    }

    /**
     * The postal deadline in words, with the exact UK date and time, for emails (the website builds
     * the same sentence in web/src/lib/postal.ts):
     * "Postal entries must be received at least 48 hours before close: by 6:00pm on Tuesday 22 December 2026 (UK time)."
     */
    public function postalDeadlineSentence(): ?string
    {
        $by = $this->postalClosesAt();
        if (! $by) {
            return null;
        }
        $when = $by->copy()->timezone('Europe/London')->format('g:ia').' on '.$by->copy()->timezone('Europe/London')->format('l j F Y');

        return $this->postal_closes_at
            ? "Postal entries must be received by {$when} (UK time)."
            : 'Postal entries must be received at least '.self::POSTAL_CLOSE_HOURS_BEFORE." hours before close: by {$when} (UK time).";
    }

    public function available(): int
    {
        return max(0, $this->total_tickets - $this->tickets_allocated - $this->reservedPending());
    }
}
