<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionResource;
use App\Http\Resources\SettingsPayload;
use App\Http\Resources\WinnerResource;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Winner;
use App\Services\Draws\VerityDrawEngine;

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
            Winner::with('ticket', 'competition', 'draw')->where('show_on_site', true)->latest('drawn_at')->get()
        );
    }

    public function draws()
    {
        $upcoming = Competition::whereIn('status', ['live', 'closed'])->orderBy('draw_at')->get()->map(fn ($c) => [
            'id' => null,
            'competition' => $c->title,
            'drawAt' => $c->draw_at->toIso8601String(),
            'winningTicket' => null,
            'winner' => null,
            'certificateUrl' => null,
            'verifyUrl' => null,
        ]);
        $past = Draw::with('competition', 'winningTicket')->where('status', 'published')->latest('drawn_at')->get()->map(fn ($d) => [
            'id' => $d->id,
            'competition' => $d->competition->title,
            'drawAt' => $d->drawn_at?->toIso8601String() ?? '',
            'winningTicket' => $d->winningTicket?->display($d->competition->total_tickets),
            'winner' => Winner::where('draw_id', $d->id)->where('ticket_id', $d->winning_ticket_id)->value('display_name'),
            'certificateUrl' => route('draws.certificate', $d),
            'verifyUrl' => config('raffle.draws.verify_page').'/'.$d->id,
            'drandRound' => $d->beacon_round ? (int) $d->beacon_round : null,
            // No current winner: the winner was forfeited and a redraw is waiting for its drand round, or
            // no redraw was possible and the prize went unawarded.
            'note' => $d->winning_ticket_id ? null : (self::redrawPending($d) ? 'Redraw pending' : 'Prize unawarded'),
            'redraws' => (int) Winner::where('draw_id', $d->id)->whereNotNull('redraw_number')->count(),
        ]);

        return response()->json(['data' => $upcoming->concat($past)->values()]);
    }

    /**
     * The draw certificate: Verity's full public verification record (the drop-in verifier and the
     * PHP/Python verifiers re-run every check from it) plus a summary of the facts people look for.
     * Nothing is revealed before WDS publishes the result: until then this is a 404.
     */
    public function certificate(Draw $draw, VerityDrawEngine $verity)
    {
        abort_unless($draw->status === 'published' && $draw->verity_ref, 404);

        return response()->json([
            'data' => $verity->verification($draw),
            'certificate' => [
                'competition' => $draw->competition->title,
                'winningTicket' => $draw->winningTicket?->display($draw->competition->total_tickets),
                'drawnAt' => $draw->drawn_at?->toIso8601String(),
                'publishedAt' => $draw->published_at?->toIso8601String(),
            ] + $verity->certificate($draw),
        ]);
    }

    private static function redrawPending(Draw $draw): bool
    {
        $vcId = \Sprouthub\Verity\Models\Competition::where('ref', $draw->verity_ref)->value('id');

        return $vcId && \Sprouthub\Verity\Models\Redraw::where('competition_id', $vcId)->where('status', 'scheduled')->exists();
    }

    public function settings()
    {
        return response()->json(['data' => SettingsPayload::get()]);
    }
}
