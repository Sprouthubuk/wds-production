<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\Money;
use App\Models\Competition;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Competitions are edited in plain English from Admin → Competitions, so Jason can change copy,
 * photos, prices and dates himself without touching code. Publishing puts a competition on the
 * Verity draw engine and is one way: after that the draw terms are locked.
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
        // New competitions start as drafts: they go live only through publish().
        $competition = Competition::create(['status' => 'draft'] + $this->validated($request));

        return (new CompetitionResource($competition))->response()->setStatusCode(201);
    }

    public function update(Request $request, Competition $competition)
    {
        $data = $this->validated($request, $competition);
        if ($competition->isPublished()) {
            // Published = locked in on Verity: the terms that are part of the draw can't change. The
            // closing time only moves later, with a public reason (extend()). Status follows Verity.
            foreach (self::LOCKED_AFTER_PUBLISH as $col => $label) {
                if (array_key_exists($col, $data) && ! self::same($competition->{$col}, $data[$col])) {
                    abort(422, "{$label} can't change after publishing: the draw is locked in.".($col === 'closes_at' ? ' Use "Extend closing time" to move it later.' : ''));
                }
                unset($data[$col]);
            }
            unset($data['status']);
        } elseif (isset($data['status']) && $data['status'] !== 'draft') {
            abort(422, 'Use Publish to put a competition live: publishing locks the draw in.');
        }
        $competition->update($data);

        return new CompetitionResource($competition);
    }

    /** One way: publishes on Verity, fixes the terms and the drand round. */
    public function publish(Competition $competition, VerityDrawEngine $verity)
    {
        $competition = $verity->publish($competition);

        return (new CompetitionResource($competition))->additional(['message' => "Published as {$competition->verity_ref}. The draw is locked in: it runs on the drand round after closing."]);
    }

    public function extend(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $d = $request->validate(['closesAt' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'min:10', 'max:300']]);
        // A datetime-local value from the admin form is UK time; a full ISO string keeps its own offset.
        $competition = $verity->extend($competition, Carbon::parse($d['closesAt'], 'Europe/London'), $d['reason']);

        return (new CompetitionResource($competition))->additional(['message' => 'Closing time extended. The reason is on the public audit record.']);
    }

    /** Columns that are part of the published draw terms. */
    private const LOCKED_AFTER_PUBLISH = [
        'draw_method' => 'The draw method',
        'total_tickets' => 'The number of tickets', 'ticket_price_pence' => 'The ticket price', 'max_per_person' => 'The maximum per person',
        'closes_at' => 'The closing time', 'opens_at' => 'The opening time', 'title' => 'The title',
    ];

    private static function same(mixed $current, mixed $new): bool
    {
        if ($current instanceof \DateTimeInterface || $new instanceof \DateTimeInterface) {
            return $current && $new && Carbon::parse($current)->equalTo(Carbon::parse($new));
        }

        return (string) $current === (string) $new;
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
            // Empty = 48 hours before closesAt (Competition::postalClosesAt()).
            'postalClosesAt' => ['nullable', 'date', ...($request->filled('closesAt') ? ['before_or_equal:closesAt'] : [])],
            'drawAt' => [$existing ? 'sometimes' : 'required', 'date'],
            'postalEntryEnabled' => ['boolean'], 'eventSalesEnabled' => ['boolean'], 'sortOrder' => ['integer'],
            'drawMethod' => ['sometimes', Rule::in([Competition::DRAW_VERITY, Competition::DRAW_EXTERNAL])],
        ]);

        $map = [
            'title' => 'title', 'strapline' => 'strapline', 'category' => 'category', 'badge' => 'badge', 'status' => 'status',
            'images' => 'images', 'description' => 'description', 'specs' => 'specs', 'totalTickets' => 'total_tickets',
            'maxPerPerson' => 'max_per_person', 'buildVideoUrl' => 'build_video_url', 'opensAt' => 'opens_at',
            'closesAt' => 'closes_at', 'postalClosesAt' => 'postal_closes_at', 'drawAt' => 'draw_at', 'postalEntryEnabled' => 'postal_entry_enabled',
            'eventSalesEnabled' => 'event_sales_enabled', 'sortOrder' => 'sort_order', 'drawMethod' => 'draw_method',
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
