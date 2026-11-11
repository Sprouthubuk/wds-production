<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\Money;
use App\Models\Competition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Competitions are edited in plain English from Admin → Competitions, so Jason can change copy,
 * photos, prices and dates himself without touching code.
 */
class CompetitionController extends Controller
{
    public function index()
    {
        return CompetitionResource::collection(Competition::orderByDesc('draw_at')->get());
    }

    public function show(Competition $competition)
    {
        return new CompetitionResource($competition);
    }

    public function store(Request $request)
    {
        $competition = Competition::create($this->validated($request));

        return (new CompetitionResource($competition))->response()->setStatusCode(201);
    }

    public function update(Request $request, Competition $competition)
    {
        $data = $this->validated($request, $competition);
        if ($competition->tickets_allocated > 0) {
            // Once tickets are sold, protect the fundamentals.
            if (isset($data['total_tickets']) && $data['total_tickets'] < $competition->tickets_allocated) {
                abort(422, 'Total tickets can\'t be lower than tickets already sold.');
            }
            unset($data['ticket_price_pence']);
        }
        $competition->update($data);

        return new CompetitionResource($competition);
    }

    public function uploadImage(Request $request)
    {
        $request->validate(['image' => ['required', 'image', 'max:8192']]);
        $path = $request->file('image')->store('competitions', 'public');

        return response()->json(['src' => asset('storage/'.$path)]);
    }

    private function validated(Request $request, ?Competition $existing = null): array
    {
        $d = $request->validate([
            'title' => [$existing ? 'sometimes' : 'required', 'string', 'max:160'],
            'slug' => ['nullable', 'alpha_dash', 'max:160', Rule::unique('competitions', 'slug')->ignore($existing?->id)],
            'strapline' => ['nullable', 'string', 'max:300'],
            'category' => [$existing ? 'sometimes' : 'required', Rule::in(['cars', 'parts', 'cash', 'merch'])],
            'badge' => ['nullable', 'string', 'max:40'],
            'status' => [$existing ? 'sometimes' : 'required', Rule::in(['draft', 'scheduled', 'live', 'closed', 'drawn'])],
            'images' => ['array'], 'images.*.src' => ['required', 'string'], 'images.*.alt' => ['nullable', 'string', 'max:200'],
            'description' => ['array'], 'description.*' => ['string', 'max:3000'],
            'specs' => ['array'], 'specs.*.label' => ['required', 'string', 'max:60'], 'specs.*.value' => ['required', 'string', 'max:120'],
            'ticketPrice' => [$existing ? 'sometimes' : 'required', 'numeric', 'min:0.1', 'max:1000'],
            'bundles' => ['array'], 'bundles.*.qty' => ['required', 'integer', 'min:2'], 'bundles.*.price' => ['required', 'numeric', 'min:0.1'],
            'totalTickets' => [$existing ? 'sometimes' : 'required', 'integer', 'min:1', 'max:1000000'],
            'maxPerPerson' => [$existing ? 'sometimes' : 'required', 'integer', 'min:1'],
            'cashAlternative' => ['nullable', 'numeric', 'min:0'],
            'buildVideoUrl' => ['nullable', 'url'],
            'opensAt' => ['nullable', 'date'], 'closesAt' => ['nullable', 'date'],
            'drawAt' => [$existing ? 'sometimes' : 'required', 'date'],
            'postalEntryEnabled' => ['boolean'], 'eventSalesEnabled' => ['boolean'], 'sortOrder' => ['integer'],
        ]);

        $map = [
            'title' => 'title', 'strapline' => 'strapline', 'category' => 'category', 'badge' => 'badge', 'status' => 'status',
            'images' => 'images', 'description' => 'description', 'specs' => 'specs', 'totalTickets' => 'total_tickets',
            'maxPerPerson' => 'max_per_person', 'buildVideoUrl' => 'build_video_url', 'opensAt' => 'opens_at',
            'closesAt' => 'closes_at', 'drawAt' => 'draw_at', 'postalEntryEnabled' => 'postal_entry_enabled',
            'eventSalesEnabled' => 'event_sales_enabled', 'sortOrder' => 'sort_order',
        ];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $d)) $out[$col] = $d[$in];
        }
        if (array_key_exists('ticketPrice', $d)) $out['ticket_price_pence'] = Money::pence($d['ticketPrice']);
        if (array_key_exists('cashAlternative', $d)) $out['cash_alternative_pence'] = Money::pence($d['cashAlternative']);
        if (array_key_exists('bundles', $d)) {
            $out['bundles'] = collect($d['bundles'])->map(fn ($b) => ['qty' => (int) $b['qty'], 'price_pence' => Money::pence($b['price'])])->sortBy('qty')->values()->all();
        }
        if (! $existing || array_key_exists('slug', $d)) {
            $out['slug'] = $d['slug'] ?? Str::slug($d['title'] ?? $existing?->title);
        }

        return $out;
    }
}
