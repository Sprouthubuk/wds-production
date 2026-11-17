<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One launch email, queued or sent (see the migration). dedupe_key makes a second launch email impossible. */
class LaunchAlertSend extends Model
{
    protected $fillable = ['competition_id', 'email', 'newsletter_subscriber_id', 'kind', 'dedupe_key', 'status', 'error', 'sent_at', 'sent_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }

    public static function dedupeKey(int $competitionId, string $email): string
    {
        return "launch:{$competitionId}:{$email}";
    }
}
