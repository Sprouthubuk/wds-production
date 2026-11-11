<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountCode extends Model
{
    protected $fillable = ['code', 'type', 'value', 'competition_id', 'usage_limit', 'times_used', 'expires_at', 'active'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'active' => 'boolean'];
    }

    public function isUsable(): bool
    {
        return $this->active
            && (! $this->expires_at || $this->expires_at->isFuture())
            && (! $this->usage_limit || $this->times_used < $this->usage_limit);
    }
}
