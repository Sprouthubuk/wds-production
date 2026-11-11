<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Winner extends Model
{
    protected $fillable = ['competition_id', 'draw_id', 'ticket_id', 'user_id', 'display_name', 'prize', 'image', 'verification', 'handover', 'show_on_site', 'drawn_at'];

    protected function casts(): array
    {
        return ['show_on_site' => 'boolean', 'drawn_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function draw(): BelongsTo { return $this->belongsTo(Draw::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
