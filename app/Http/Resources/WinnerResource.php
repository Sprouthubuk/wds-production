<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Winner */
class WinnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->display_name,
            'prize' => $this->prize,
            'image' => $this->image,
            'drawnAt' => $this->drawn_at?->toIso8601String(),
            'ticketNumber' => $this->ticket ? $this->ticket->display($this->competition?->total_tickets ?? 0) : null,
        ];
    }
}
