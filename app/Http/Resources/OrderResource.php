<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Order confirmation page. @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing('lines.competition', 'lines.tickets');

        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'message' => $this->status_note,   // e.g. why a payment was refunded
            'total' => Money::pounds($this->total_pence),
            'subtotal' => Money::pounds($this->subtotal_pence),
            'bundleSaving' => Money::pounds($this->bundle_saving_pence),
            'discount' => Money::pounds($this->discount_pence),
            'creditUsed' => Money::pounds($this->credit_used_pence),
            'createdAt' => $this->created_at->toIso8601String(),
            'lines' => $this->lines->map(fn ($l) => [
                'competition' => $l->competition->title,
                'slug' => $l->competition->slug,
                'qty' => $l->qty,
                'drawAt' => $l->competition->draw_at?->toIso8601String(),
                'ticketNumbers' => $l->tickets->sortBy('number')->map(fn ($t) => $t->display($l->competition->total_tickets))->values(),
            ]),
        ];
    }
}
