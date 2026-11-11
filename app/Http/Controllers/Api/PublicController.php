<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\SettingsPayload;
use App\Http\Resources\WinnerResource;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Winner;

class PublicController extends Controller
{
    public function competitions()
    {
        $items = Competition::visible()->orderBy('sort_order')->orderBy('draw_at')->get();

        return CompetitionResource::collection($items);
    }

    public function competition(string $slug)
    {
        return new CompetitionResource(Competition::visible()->where('slug', $slug)->firstOrFail());
    }

    public function winners()
    {
        return WinnerResource::collection(
            Winner::with('ticket', 'competition')->where('show_on_site', true)->latest('drawn_at')->get()
        );
    }

    public function draws()
    {
        $upcoming = Competition::whereIn('status', ['live', 'closed'])->orderBy('draw_at')->get()->map(fn ($c) => [
            'competition' => $c->title,
            'drawAt' => $c->draw_at->toIso8601String(),
            'winningTicket' => null,
            'winner' => null,
            'certificateUrl' => null,
        ]);
        $past = Draw::with('competition', 'winningTicket')->where('status', 'published')->latest('drawn_at')->get()->map(fn ($d) => [
            'competition' => $d->competition->title,
            'drawAt' => $d->drawn_at?->toIso8601String() ?? '',
            'winningTicket' => $d->winningTicket?->display($d->competition->total_tickets),
            'winner' => Winner::where('draw_id', $d->id)->value('display_name'),
            'certificateUrl' => route('draws.certificate', $d),
        ]);

        return response()->json(['data' => $upcoming->concat($past)->values()]);
    }

    /** Public proof for a draw: entries hash, commitment, revealed seed / Verity proof. */
    public function certificate(Draw $draw)
    {
        abort_unless($draw->status === 'published', 404);

        return response()->json(['data' => [
            'competition' => $draw->competition->title,
            'engine' => $draw->engine,
            'entries' => $draw->entries_count,
            'entriesHash' => $draw->entries_hash,
            'commitment' => $draw->commitment,
            'winningTicket' => $draw->winningTicket?->display($draw->competition->total_tickets),
            'drawnAt' => $draw->drawn_at?->toIso8601String(),
            'proof' => json_decode($draw->proof ?? '{}', true),
        ]]);
    }

    public function settings()
    {
        return response()->json(['data' => SettingsPayload::get()]);
    }
}
