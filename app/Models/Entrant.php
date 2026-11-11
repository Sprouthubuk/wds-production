<?php

namespace App\Models;

use App\Services\TicketAllocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person entering competitions, identified by their normalised email, across every channel
 * (online, postal, event). The per-person maximum counts per entrant. The draw engine only ever
 * sees ownerRef() ("entrant:42"), never the email.
 */
class Entrant extends Model
{
    protected $fillable = ['email'];

    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
    public function orders(): HasMany { return $this->hasMany(Order::class); }
    public function postalEntries(): HasMany { return $this->hasMany(PostalEntry::class); }

    public function ownerRef(): string
    {
        return 'entrant:'.$this->id;
    }

    public static function findByEmail(string $email): ?self
    {
        return static::where('email', TicketAllocator::normaliseEmail($email))->first();
    }

    /**
     * The entrant for this email, created if new. Safe when two requests create the same entrant at
     * the same moment: the insert is ignored on the unique email, and the locking read then returns
     * the committed row (also under MySQL's repeatable-read snapshot).
     */
    public static function forEmail(string $email): self
    {
        $email = TicketAllocator::normaliseEmail($email);
        if ($existing = static::where('email', $email)->first()) {
            return $existing;
        }
        static::query()->insertOrIgnore(['email' => $email, 'created_at' => now(), 'updated_at' => now()]);

        return static::where('email', $email)->lockForUpdate()->firstOrFail();
    }
}
