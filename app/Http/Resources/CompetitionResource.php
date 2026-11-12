<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Competition */
class CompetitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'strapline' => $this->strapline,
            'category' => $this->category,
            'badge' => $this->badge,
            'status' => $this->status,
            'images' => $this->images ?? [],
            'description' => $this->description ?? [],
            'specs' => $this->specs ?? [],
            'ticketPrice' => Money::pounds($this->ticket_price_pence),
            'totalTickets' => $this->total_tickets,
            'ticketsSold' => $this->tickets_allocated,
            'maxPerPerson' => $this->max_per_person,
            'bundles' => collect($this->bundles ?? [])->map(fn ($b) => ['qty' => (int) $b['qty'], 'price' => Money::pounds((int) $b['price_pence'])])->values(),
            'opensAt' => $this->opens_at?->toIso8601String(),
            'closesAt' => $this->closes_at?->toIso8601String(),
            'postalClosesAt' => $this->hasPostalRoute() ? $this->postalClosesAt()?->toIso8601String() : null,   // effective deadline; null = no postal route
            'postalClosesAtCustom' => $this->postal_closes_at?->toIso8601String(), // null = the default
            'drawAt' => $this->draw_at?->toIso8601String(),   // empty only on a draft whose dates aren't confirmed
            // Postponed (emergency only): shown as a notice on the competition page.
            'postponement' => $this->postponed_at ? [
                'at' => $this->postponed_at->toIso8601String(),
                'from' => $this->postponed_from?->toIso8601String(),
                'reason' => $this->postponement_reason,
            ] : null,
            'cashAlternative' => Money::pounds($this->cash_alternative_pence),
            'buildVideoUrl' => $this->build_video_url,
            'postalEntryEnabled' => $this->hasPostalRoute(), // never on a free competition
            'eventSalesEnabled' => (bool) $this->event_sales_enabled,
            'drawMethod' => $this->draw_method ?? 'verity',
            'drawTool' => $this->when($request->is('api/admin/*'), fn () => $this->resource->drawTool()),
            // Published on the Verity draw engine: the draw is locked in (null = still a draft).
            'verityRef' => $this->verity_ref,
            'publishedAt' => $this->published_at?->toIso8601String(),
            // A draft seen through a preview link: shown as live, but entries are refused server-side.
            'preview' => (bool) ($this->resource->previewing ?? false),
        ];
    }
}
