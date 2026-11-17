<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admin's history for a competition: published, opened now (with confirmation), opened at its time. */
class CompetitionEvent extends Model
{
    protected $fillable = ['competition_id', 'user_id', 'action', 'summary', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function record(Competition $competition, ?User $by, string $action, string $summary, array $details = []): self
    {
        return static::create([
            'competition_id' => $competition->id, 'user_id' => $by?->id,
            'action' => $action, 'summary' => $summary, 'details' => $details ?: null,
        ]);
    }
}
