<?php

namespace App\Models;

use App\Services\TicketAllocator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A launch alert sign-up ("Notify me when it's live" / "Get first access"). One row per email per list:
 * competition_id null = the site-wide list, otherwise that competition's list. Unsubscribing stops every
 * list for that email (LaunchAlerts::unsubscribe).
 */
class NewsletterSubscriber extends Model
{
    /** The consent line shown beside every new sign-up form, stored with the sign-up. */
    public const CONSENT_TEXT = 'Email me when WDS Competitions launch. Unsubscribe anytime.';

    /** Where a sign-up came from (Admin's list shows it). */
    public const SOURCES = ['first_access', 'home', 'competition', 'card', 'account'];

    protected $fillable = ['email', 'competition_id', 'user_id', 'consent_at', 'consent_text', 'source', 'unsubscribe_token', 'list_key', 'unsubscribed_at'];

    protected $hidden = ['unsubscribe_token'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    public function scopeSubscribed(Builder $q): Builder
    {
        return $q->whereNull('unsubscribed_at');
    }

    public static function listKey(?int $competitionId, string $email): string
    {
        return ($competitionId ?? 0).':'.TicketAllocator::normaliseEmail($email);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }
}
