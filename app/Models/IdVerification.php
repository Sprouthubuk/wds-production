<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdVerification extends Model
{
    protected $fillable = ['user_id', 'document_path', 'selfie_path', 'status', 'notes', 'reviewed_by'];

    /** The files are deleted 30 days after the winner's prize is handed over (wds:delete-winner-ids). */
    protected function casts(): array
    {
        return ['files_deleted_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
