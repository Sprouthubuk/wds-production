<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdVerification extends Model
{
    protected $fillable = ['user_id', 'document_path', 'selfie_path', 'status', 'notes', 'reviewed_by'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
