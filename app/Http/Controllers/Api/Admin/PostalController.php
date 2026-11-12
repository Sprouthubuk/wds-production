<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Http\Resources\CompetitionResource;
use App\Models\PostalEntry;
use App\Services\Draws\VerityDrawEngine;
use App\Services\PostalEntryService;
use Illuminate\Http\Request;

class PostalController extends Controller
{
    public function index(Request $request)
    {
        $q = PostalEntry::with('competition:id,title,total_tickets', 'ticket')->latest();
        if ($cid = $request->query('competition')) $q->where('competition_id', $cid);

        return $q->paginate(50)->through(fn ($e) => [
            'id' => $e->id, 'competition' => $e->competition->title, 'name' => $e->name, 'email' => $e->email,
            'receivedOn' => $e->received_on->toDateString(), 'receivedAt' => $e->receivedMoment()->toIso8601String(), 'status' => $e->status, 'reason' => $e->rejection_reason,
            'ticket' => $e->ticket?->display($e->competition->total_tickets),
        ]);
    }

    /**
     * What the postal logging form offers: every published competition still taking entries that has the
     * free postal route (paid, postal entry on), whether or not it's sold at events.
     */
    public function competitions(VerityDrawEngine $verity)
    {
        $list = Competition::query()->whereNotNull('verity_ref')->where('postal_entry_enabled', true)->where('ticket_price_pence', '>', 0)
            ->orderBy('closes_at')->get()
            ->filter(fn (Competition $c) => $c->hasPostalRoute() && $verity->isOpen($c))->values();

        return CompetitionResource::collection($list)->response()->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, PostalEntryService $service)
    {
        $d = $request->validate([
            'competitionId' => ['required', 'exists:competitions,id'],
            'name' => ['required', 'string', 'max:160'],
            'address' => ['required', 'string', 'max:255'],
            'dateOfBirth' => ['required', 'date'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            // When the envelope arrived: date and time (the 48-hour rule is exact). Not in the future.
            'receivedOn' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
        ]);
        $entry = $service->log(Competition::findOrFail($d['competitionId']), [
            'name' => $d['name'], 'address' => $d['address'], 'date_of_birth' => $d['dateOfBirth'],
            'email' => $d['email'], 'phone' => $d['phone'] ?? null, 'received_on' => $d['receivedOn'],
        ], $request->user());

        return response()->json([
            'status' => $entry->status,
            'message' => $entry->status === 'allocated'
                ? 'Entry logged — ticket #'.$entry->ticket->display($entry->competition->total_tickets).' allocated.'
                : 'Entry rejected: '.$entry->rejection_reason.'.',
        ], 201);
    }
}
