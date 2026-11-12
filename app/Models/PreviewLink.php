<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A private preview link for a draft competition (see the migration). Only a draft can be
 * previewed: once a competition is published the link stops working.
 */
class PreviewLink extends Model
{
    public const DAYS = 14;

    protected $fillable = ['competition_id', 'token_hash', 'token_encrypted', 'expires_at', 'revoked_at', 'created_by'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    /** A new link for the competition; any earlier link for it is revoked. */
    public static function issue(Competition $competition, ?User $by): self
    {
        static::where('competition_id', $competition->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(48);

        return static::create([
            'competition_id' => $competition->id,
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => Crypt::encryptString($token),
            'expires_at' => now()->addDays(self::DAYS),
            'created_by' => $by?->id,
        ]);
    }

    /** The current (unrevoked, unexpired) link for a competition, if any. */
    public static function current(Competition $competition): ?self
    {
        return static::where('competition_id', $competition->id)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    /** The valid link for a token: not revoked, not expired, and its competition still a draft. */
    public static function findValid(?string $token): ?self
    {
        if (! $token || strlen($token) > 128) {
            return null;
        }
        $link = static::with('competition')->where('token_hash', hash('sha256', $token))->first();

        return $link && $link->isValid() ? $link : null;
    }

    public function isValid(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture()
            && $this->competition && ! $this->competition->isPublished() && $this->competition->status === 'draft';
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return rtrim(config('raffle.frontend_url'), '/').'/preview/'.$this->token();
    }
}
