<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Winner;
use App\Services\DrawService;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Http\Request;

/**
 * Draw status for the admin. The draw itself is Verity's: entries close at the closing time, a future
 * drand round is committed, and verity:process-due draws once it's out. Admins see what happened and
 * publish the result (the reveal); no action here can change a result.
 */
class DrawController extends Controller
{
    /** Every published competition with its draw state, committed round and audit trail. */
    public function index(DrawService $draws, VerityDrawEngine $verity)
    {
        return Competition::whereNotNull('verity_ref')->orderByDesc('closes_at')->get()->map(function (Competition $c) use ($draws, $verity) {
            $draw = $draws->sync($c);
            $vc = $verity->verityCompetition($c);
            $round = $vc->beacon_round ?? $vc->beacon_planned_round;

            return [
                'competitionId' => $c->id,
                'competition' => $c->title,
                'verityRef' => $c->verity_ref,
                'status' => $vc->status,                                   // live | closed | drawn | void
                'closesAt' => $c->closes_at?->toIso8601String(),
                'ticketsSold' => (int) $c->tickets_allocated,
                'totalTickets' => (int) $c->total_tickets,
                'round' => $round ? (int) $round : null,
                'roundCommitted' => $vc->beacon_round !== null,           // false = planned at publish, fixed at close
                'roundAt' => VerityDrawEngine::roundTime($round ? (int) $round : null)?->toIso8601String(),
                'roundLate' => (bool) $vc->beacon_late,
                'commitment' => $vc->commitment,
                'method' => $c->engineDrawMethod(),        // verity | external
                'externalKind' => $c->externalKind(),       // rng | physical (numbered tokens) | null
                'drawTool' => $vc->externalTool(),
                'seal' => \Sprouthub\Verity\Services\DrawService::closeRecord($vc)?->payload['external_seal'] ?? null,
                'drawNow' => (bool) (\Sprouthub\Verity\Services\DrawService::closeRecord($vc)?->payload['draw_now'] ?? false),
                'draw' => $draw ? [
                    'id' => $draw->id,
                    'status' => $draw->status,                             // prepared | drawn | published
                    'entries' => $draw->entries_count,
                    'entriesHash' => $draw->entries_hash,
                    'livestreamUrl' => $draw->livestream_url,
                    'winningTicket' => $draw->winningTicket?->display($c->total_tickets),
                    'winnerName' => $draw->winningTicket?->name,
                    'drawnAt' => $draw->drawn_at?->toIso8601String(),
                    'publishedAt' => $draw->published_at?->toIso8601String(),
                    'certificateUrl' => $draw->status === 'published' ? route('draws.certificate', $draw) : null,
                    'verifyUrl' => $draw->status === 'published' ? config('raffle.draws.verify_page').'/'.$draw->id : null,
                    // Original winner and every redraw's, forfeited ones included (never deleted).
                    'winners' => Winner::with('ticket')->where('draw_id', $draw->id)->get()
                        ->sortBy(fn (Winner $w) => [(int) $w->redraw_number, $w->id])->values()
                        ->map(fn (Winner $w) => [
                            'id' => $w->id,
                            'label' => $w->redraw_number ? "Redraw {$w->redraw_number}" : 'Original draw',
                            'ticket' => $w->ticket?->display($c->total_tickets),
                            'name' => $w->ticket?->name,
                            'forfeited' => $w->isForfeited(),
                            'reason' => $w->isForfeited() ? Winner::forfeitLabel($w->forfeit_category) : null,
                            'note' => $w->forfeit_note,
                            'canForfeit' => $vc->status === 'drawn' && $w->verity_winner_id && ! $w->isForfeited()
                                && ! \Sprouthub\Verity\Services\Redraws::pending($vc),
                        ]),
                    'redrawPending' => ($p = \Sprouthub\Verity\Services\Redraws::pending($vc)) ? [
                        'number' => (int) $p->number,
                        'round' => (int) $p->beacon_round,
                        'roundAt' => VerityDrawEngine::roundTime((int) $p->beacon_round)?->toIso8601String(),
                    ] : null,
                ] : null,
                // Closes, extensions, voids, redraws and forfeits as recorded on Verity's public audit chain.
                'trail' => collect($verity->auditTrail($c))
                    ->whereIn('type', ['competition.published', 'competition.extended', 'competition.closed_early', 'competition.closed', 'close.witnessed',
                        'competition.void', 'draw.completed', 'draw.winner_forfeited', 'draw.forfeited', 'draw.redraw_scheduled', 'redraw.witnessed', 'draw.redrawn', 'order.withdrawn'])
                    ->map(fn ($e) => ['seq' => $e['seq'], 'type' => $e['type'], 'at' => $e['occurred_at'] ?? null, 'hash' => $e['hash']])
                    ->values(),
            ];
        })->values();
    }

    /** The frozen entry list (ticket number, first name + initial, channel), for showing on the live. */
    public function entries(Draw $draw)
    {
        $c = $draw->competition;
        $rows = $c->tickets()->active()->orderBy('number')->get(['number', 'name', 'channel']);

        return response()->streamDownload(function () use ($rows, $c) {
            $out = fopen('php://output', 'w');
            // Ticket, first name + initial, source: what an external draw picks from (sealed at close).
            fputcsv($out, ['ticket', 'name', 'source']);
            foreach ($rows as $t) {
                fputcsv($out, [$t->display($c->total_tickets), DrawService::displayName($t->name), $t->channel]);
            }
            fclose($out);
        }, "entries-{$c->slug}-".substr((string) $draw->entries_hash, 0, 12).'.csv', ['Content-Type' => 'text/csv', 'X-Entries-Hash' => (string) $draw->entries_hash]);
    }

    /**
     * The chip sheet for a closed competition: every valid ticket number on the list Verity sealed at the
     * close (allocated and not withdrawn: withdrawn entries left the list), the count and the sealed
     * fingerprint. For making and checking the numbered tokens of a physical draw. ?format=csv to download.
     */
    public function chipSheet(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $sheet = $verity->chipSheet($competition);
        if ($request->query('format') !== 'csv') {
            return response()->json(['data' => $sheet]);
        }

        return response()->streamDownload(function () use ($sheet) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['competition', $sheet['competition']]);
            fputcsv($out, ['reference', $sheet['reference']]);
            fputcsv($out, ['valid tickets', $sheet['count']]);
            fputcsv($out, ['sealed fingerprint (SHA-256)', $sheet['entriesHash']]);
            fputcsv($out, ['sealed at', $sheet['sealedAt']]);
            fputcsv($out, []);
            fputcsv($out, ['ticket']);
            foreach ($sheet['tickets'] as $t) {
                fputcsv($out, [$t]);
            }
            fclose($out);
        }, "chip-sheet-{$competition->slug}-".substr((string) $sheet['entriesHash'], 0, 12).'.csv', ['Content-Type' => 'text/csv', 'X-Entries-Hash' => (string) $sheet['entriesHash']]);
    }

    /** The reveal: puts the result and its certificate on the Live draws page. */
    public function publish(Draw $draw, DrawService $draws)
    {
        $draws->publish($draw);

        return response()->json(['message' => 'Result published on the Live draws page, with its certificate.']);
    }

    /** Method A, pressed on the livestream: close now; the winner follows about a minute later. */
    public function closeNow(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $c = $verity->closeAndDrawNow($competition, $request->user());

        return response()->json(['message' => 'Entries closed. The draw runs on the next drand round, in about a minute.', 'status' => $c->status]);
    }

    /** Method B, pressed on the livestream: close entries and seal the list for the external draw. */
    public function closeEntries(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $c = $verity->closeEntries($competition, $request->user());

        return response()->json(['message' => 'Entries closed and sealed. Download the entry list and draw.', 'status' => $c->status]);
    }

    /** Method B: name or change the random number tool, until entries close (then it's sealed with the list). */
    public function drawTool(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80', 'not_regex:/[|\x00-\x1f]/'],
            'url' => ['required', 'url:https', 'max:300', 'not_regex:/[\s|]/'],
        ]);
        $verity->setDrawTool($competition, $d['name'], $d['url'], $request->user());

        return response()->json(['message' => 'Random number tool saved. It\'s sealed with the entry list when entries close.']);
    }

    /** Method B: the winning ticket number drawn on the livestream, and the livestream link (both required). */
    public function externalResult(Request $request, Competition $competition, VerityDrawEngine $verity)
    {
        $notListed = 'Ticket '.\App\Models\Ticket::format((int) $request->input('ticket'), (int) $competition->total_tickets).' is not on the sealed entry list.';
        $d = $request->validate([
            'ticket' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) $competition->total_tickets)],
            'livestreamUrl' => ['required', 'url:https', 'max:300'],
        ], [
            'ticket.min' => $notListed,
            'ticket.max' => $notListed,
            'ticket.required' => 'Enter the winning ticket number.',
            'ticket.integer' => 'Enter the winning ticket number (digits only).',
            'livestreamUrl.required' => 'Add the livestream link (https://…).',
            'livestreamUrl.url' => 'The livestream link must start with https://.',
        ]);
        $verity->recordExternalResult($competition, (int) $d['ticket'], $d['livestreamUrl'], $request->user());

        return response()->json(['message' => 'Result recorded on the audit chain. Publish it when you reveal it.']);
    }

    /**
     * The live reveal screen (item 9): what the stream shows right now. Polled every couple of seconds.
     * Admin only; the winner's first name + initial is only here once Verity has drawn (or the external
     * result is recorded), and the public verify link works once the result is published.
     */
    public function reveal(Competition $competition, DrawService $draws, VerityDrawEngine $verity)
    {
        abort_unless($competition->isPublished(), 404);
        $draw = $draws->sync($competition);
        $vc = $verity->verityCompetition($competition);
        $drawn = $vc->status === 'drawn' && $draw?->winning_ticket_id;
        $winner = $drawn ? Winner::where('draw_id', $draw->id)->where('ticket_id', $draw->winning_ticket_id)->whereNull('forfeited_at')->latest('id')->first() : null;
        $pending = \Sprouthub\Verity\Services\Redraws::pending($vc);

        return response()->json(['data' => [
            'competition' => ['id' => $competition->id, 'title' => $competition->title, 'image' => $competition->images[0]['src'] ?? null, 'drawAt' => $competition->draw_at?->toIso8601String()],
            'method' => $competition->engineDrawMethod(),
            'externalKind' => $competition->externalKind(),
            'status' => $vc->status,                                    // live | closed | drawn | void
            'entries' => $draw?->entries_count ?? (int) $competition->tickets_allocated,
            'entriesHash' => $draw?->entries_hash,
            'round' => $vc->beacon_round ? (int) $vc->beacon_round : null,
            'roundAt' => VerityDrawEngine::roundTime($vc->beacon_round ? (int) $vc->beacon_round : null)?->toIso8601String(),
            'redrawRound' => $pending?->beacon_round ? (int) $pending->beacon_round : null,
            'redrawRoundAt' => VerityDrawEngine::roundTime($pending?->beacon_round ? (int) $pending->beacon_round : null)?->toIso8601String(),
            // A redraw after a forfeit (protocol R2): the forfeited ticket and the redraw's number, whatever the method.
            'redrawPending' => $pending ? ['number' => (int) $pending->number, 'forfeitedTicket' => \App\Models\Ticket::pad((int) $pending->forfeited_ticket, (int) $competition->total_tickets)] : null,
            'ticket' => $drawn ? $draw->winningTicket?->display($competition->total_tickets) : null,
            'winner' => $winner?->display_name,
            'redraw' => $winner?->redraw_number,
            'drawId' => $draw?->id,
            'published' => $draw?->status === 'published',
            'verifyUrl' => $draw ? config('raffle.draws.verify_page').'/'.$draw->id : null,
            'serverTime' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }
}
