<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A free postal entry, logged by staff from an envelope. status: allocated (has a ticket), rejected (with a
 * reason), or held (logged while the competition was coming soon; numbered when it opens).
 *
 * @property int $id
 * @property int $competition_id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon $date_of_birth
 * @property \Illuminate\Support\Carbon $received_on
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property string $status
 * @property string|null $rejection_reason
 * @property-read Competition $competition
 * @property-read Ticket|null $ticket
 */
class PostalEntry extends Model
{
    protected $fillable = ['competition_id', 'user_id', 'entrant_id', 'name', 'address', 'date_of_birth', 'email', 'phone', 'received_on', 'received_at', 'status', 'rejection_reason', 'logged_by'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'received_on' => 'date', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    /** @return HasOne<Ticket, $this> */
    public function ticket(): HasOne { return $this->hasOne(Ticket::class); }

    /** Rejections because of the linked account's status start with this. Admin sees them; the entrant doesn't. */
    public const ACCOUNT_REASON_PREFIX = 'Account ';

    /** What the entrant's email says instead of an account-status reason: it must not reveal the account's status. */
    public const NEUTRAL_ACCOUNT_REASON = "We couldn't accept this postal entry because of the status of the account linked to this email address. If you think this is a mistake, contact us.";

    public function isAccountRejection(): bool
    {
        return $this->status === 'rejected' && str_starts_with((string) $this->rejection_reason, self::ACCOUNT_REASON_PREFIX);
    }

    /** When the envelope was received; rows from before received_at existed count from the start of that day. */
    public function receivedMoment(): \Illuminate\Support\Carbon
    {
        return $this->received_at ?? $this->received_on->copy()->startOfDay();
    }
}
