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
        'build_video_url', 'opens_at', 'closes_at', 'draw_at', 'postal_entry_enabled', 'event_sales_enabled', 'sort_order',
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
            'draw_at' => 'datetime',
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

    public function available(): int
    {
        return max(0, $this->total_tickets - $this->tickets_allocated - $this->reservedPending());
    }
}
