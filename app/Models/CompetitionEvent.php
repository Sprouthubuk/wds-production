<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin's history for a competition: published, opened now (with confirmation), opened at its time.
 *
 * @property int $id
 * @property int $competition_id
 * @property int|null $user_id
 * @property string $action
 * @property string $summary
 * @property array<string, mixed>|null $details
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $user
 */
class CompetitionEvent extends Model
{
    protected $fillable = ['competition_id', 'user_id', 'action', 'summary', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function record(Competition $competition, ?User $by, string $action, string $summary, array $details = []): self
    {
        return static::create([
            'competition_id' => $competition->id, 'user_id' => $by?->id,
            'action' => $action, 'summary' => $summary, 'details' => $details ?: null,
        ]);
    }
}
