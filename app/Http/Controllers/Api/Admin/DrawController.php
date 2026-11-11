<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\DrawService;
use App\Services\Draws\VerityDrawEngine;

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
                'draw' => $draw ? [
                    'id' => $draw->id,
                    'status' => $draw->status,                             // prepared | drawn | published
                    'entries' => $draw->entries_count,
                    'entriesHash' => $draw->entries_hash,
                    'winningTicket' => $draw->winningTicket?->display($c->total_tickets),
                    'winnerName' => $draw->winningTicket?->name,
                    'drawnAt' => $draw->drawn_at?->toIso8601String(),
                    'publishedAt' => $draw->published_at?->toIso8601String(),
                    'certificateUrl' => $draw->status === 'published' ? route('draws.certificate', $draw) : null,
                    'verifyUrl' => $draw->status === 'published' ? config('raffle.draws.verify_page').'/'.$draw->id : null,
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
            fputcsv($out, ['ticket', 'name', 'channel']);
            foreach ($rows as $t) {
                fputcsv($out, [$t->display($c->total_tickets), DrawService::displayName($t->name), $t->channel]);
            }
            fclose($out);
        }, "entries-{$c->slug}.csv", ['Content-Type' => 'text/csv']);
    }

    /** The reveal: puts the result and its certificate on the Live draws page. */
    public function publish(Draw $draw, DrawService $draws)
    {
        $draws->publish($draw);

        return response()->json(['message' => 'Result published on the Live draws page, with its certificate.']);
    }
}
