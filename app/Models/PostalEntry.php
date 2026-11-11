<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PostalEntry extends Model
{
    protected $fillable = ['competition_id', 'user_id', 'entrant_id', 'name', 'address', 'date_of_birth', 'email', 'phone', 'received_on', 'status', 'rejection_reason', 'logged_by'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'received_on' => 'date'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function ticket(): HasOne { return $this->hasOne(Ticket::class); }
}
