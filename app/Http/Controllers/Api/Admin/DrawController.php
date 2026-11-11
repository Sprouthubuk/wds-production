<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Draw;
use App\Services\DrawService;
use Illuminate\Http\Request;

class DrawController extends Controller
{
    public function index()
    {
        return Draw::with('competition:id,title,total_tickets', 'winningTicket')->latest()->get()->map(fn ($d) => [
            'id' => $d->id, 'competition' => $d->competition->title, 'engine' => $d->engine, 'status' => $d->status,
            'entries' => $d->entries_count, 'entriesHash' => $d->entries_hash, 'commitment' => $d->commitment,
            'winningTicket' => $d->winningTicket?->display($d->competition->total_tickets),
            'winnerName' => $d->winningTicket?->name, 'drawnAt' => $d->drawn_at?->toIso8601String(),
        ]);
    }

    public function prepare(Request $request, Competition $competition, DrawService $draws)
    {
        $draw = $draws->prepare($competition, $request->user());

        return response()->json(['message' => "Entries locked: {$draw->entries_count} tickets.", 'id' => $draw->id, 'commitment' => $draw->commitment, 'entriesHash' => $draw->entries_hash]);
    }

    /** Export the locked entry list (ticket number, first name + initial, channel) for the live draw. */
    public function entries(Draw $draw)
    {
        $c = $draw->competition;
        $rows = $c->tickets()->orderBy('number')->get(['number', 'name', 'channel']);

        return response()->streamDownload(function () use ($rows, $c) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ticket', 'name', 'channel']);
            foreach ($rows as $t) {
                fputcsv($out, [$t->display($c->total_tickets), DrawService::displayName($t->name), $t->channel]);
            }
            fclose($out);
        }, "entries-{$c->slug}.csv", ['Content-Type' => 'text/csv']);
    }

    public function run(Request $request, Draw $draw, DrawService $draws)
    {
        $draw = $draws->run($draw, $request->user());
        $t = $draw->winningTicket;

        return response()->json([
            'message' => 'Winning ticket '.$t->display($draw->competition->total_tickets),
            'winningTicket' => $t->display($draw->competition->total_tickets),
            'winner' => ['name' => $t->name, 'email' => $t->email, 'channel' => $t->channel],
        ]);
    }

    public function publish(Draw $draw, DrawService $draws)
    {
        $draws->publish($draw);

        return response()->json(['message' => 'Result published on the Live draws page.']);
    }

    public function void(Draw $draw)
    {
        abort_unless($draw->status === 'prepared', 422, 'Only a prepared draw can be voided.');
        $draw->update(['status' => 'void']);

        return response()->json(['message' => 'Draw voided. You can prepare it again.']);
    }
}
