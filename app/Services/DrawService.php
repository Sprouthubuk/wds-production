<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Ticket;
use App\Models\Winner;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Facades\DB;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Sprouthub\Verity\Models\Redraw;
use Sprouthub\Verity\Models\Winner as VerityWinner;

/**
 * Mirrors Verity's draw onto WDS's competitions, draws and winners. Verity closes the entries at the
 * closing time, commits to a future drand round and draws once it's out (verity:process-due); this
 * class only copies the outcome across, so the admin, the Live draws page and the winner's claim work
 * from WDS's own tables. It never decides anything: the numbers and the winner are read from Verity.
 *
 * sync() is idempotent and is called from Verity's events (after commit) and after every
 * verity:process-due run, so a missed event is caught up a minute later.
 *
 * The one WDS step left is publish(): the reveal. Until then the certificate is a 404 and nothing
 * public shows the result (Verity's own public routes stay off, VERITY_ROUTES=false).
 */
class DrawService
{
    public function __construct(private VerityDrawEngine $verity) {}

    /** Copy Verity's state for one competition (by its Verity ref) onto WDS's tables. */
    public function syncRef(string $verityRef): ?Draw
    {
        $competition = Competition::where('verity_ref', $verityRef)->first();

        return $competition ? $this->sync($competition) : null;
    }

    public function sync(Competition $competition): ?Draw
    {
        if (! $competition->isPublished()) {
            return null;
        }

        return DB::transaction(function () use ($competition) {
            $competition = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
            $vc = VerityCompetition::query()->where('ref', $competition->verity_ref)->firstOrFail();

            $status = match ($vc->status) {
                'closed', 'void' => 'closed',
                'drawn' => 'drawn',
                default => $competition->status,
            };
            $competition->update(['verity_status' => $vc->status, 'status' => $status]);

            if (! in_array($vc->status, ['closed', 'drawn'], true)) {
                return $competition->draws()->latest('id')->first();
            }

            // Closed: entries frozen and hashed, a future drand round committed. Nothing that decides the
            // winner exists yet (the seed is sealed in Verity until the draw, the round isn't out).
            $draw = Draw::firstOrCreate(
                ['competition_id' => $competition->id, 'verity_ref' => $vc->ref],
                [
                    'engine' => 'verity',
                    'status' => 'prepared',
                    'entries_count' => (int) $vc->entries_count,
                    'entries_hash' => (string) $vc->entries_hash,
                    'commitment' => $vc->commitment,
                    'beacon_round' => $vc->beacon_round,
                    'beacon_round_at' => VerityDrawEngine::roundTime($vc->beacon_round ? (int) $vc->beacon_round : null),
                ],
            );

            if ($vc->status === 'drawn') {
                $this->mirrorWinners($competition, $vc, $draw);
            }

            return $draw->fresh();
        });
    }

    /**
     * Every main winner Verity has recorded for this competition, original and redraws, is mirrored as a
     * WDS winner row and never deleted: a forfeited one stays, marked forfeited; a redraw's winner gets
     * redraw_number 1, 2 …. The draw's winning ticket is the current active winner (none while a redraw
     * is waiting for its drand round, or when the prize went unawarded).
     */
    private function mirrorWinners(Competition $competition, VerityCompetition $vc, Draw $draw): void
    {
        $main = VerityWinner::query()->with('entry')->where('competition_id', $vc->id)->where('type', 'main')->orderBy('id')->get();
        $redrawNumbers = Redraw::query()->where('competition_id', $vc->id)->pluck('number', 'id');

        foreach ($main as $vw) {
            $ticket = Ticket::where('verity_entry_id', $vw->entry_id)->first()
                ?? Ticket::where('competition_id', $competition->id)->where('number', $vw->entry->number)->firstOrFail();
            $row = Winner::where('verity_winner_id', $vw->id)->first()
                ?? Winner::where('draw_id', $draw->id)->where('ticket_id', $ticket->id)->first();
            $link = [
                'verity_winner_id' => $vw->id,
                'redraw_number' => $vw->redraw_id ? (int) ($redrawNumbers[$vw->redraw_id] ?? 0) ?: null : null,
            ];
            if (! $row) {
                $row = Winner::create($link + [
                    'competition_id' => $competition->id,
                    'draw_id' => $draw->id,
                    'ticket_id' => $ticket->id,
                    'user_id' => $ticket->user_id,
                    'display_name' => self::displayName($ticket->name),
                    'prize' => $vw->prize_name,
                    'drawn_at' => $vw->redraw_id ? $vw->created_at : $vc->drawn_at,
                ]);
            } else {
                $row->update($link);
            }
            // Forfeited in Verity some other way (not through WDS's forfeit): still shown as forfeited.
            if ($vw->status === 'forfeited' && ! $row->isForfeited()) {
                $row->update(['forfeited_at' => now(), 'show_on_site' => false]);
            }
        }

        $current = $main->where('status', 'active')->sortByDesc('id')->first();
        $winningTicket = $current ? Ticket::where('verity_entry_id', $current->entry_id)->first() : null;
        $draw->update([
            'status' => $draw->status === 'published' ? 'published' : 'drawn',
            'winning_number' => $current ? (int) $current->entry->number : null,
            'winning_ticket_id' => $winningTicket?->id,
            'drawn_at' => $vc->drawn_at,
            'proof' => json_encode([
                'spec' => $vc->spec,
                'beacon_round' => (int) $vc->beacon_round,
                'beacon_randomness' => $vc->beacon_randomness,
                'beacon_signature' => $vc->beacon_signature,
                'draw_seed' => $vc->draw_seed,
                'revealed_seed' => $vc->revealed_seed,
            ]),
        ]);
    }

    /** The reveal: puts the result and the certificate on the Live draws page. */
    public function publish(Draw $draw): Draw
    {
        $draw = $this->sync($draw->competition) ?? $draw;
        if ($draw->status !== 'drawn') {
            throw new EntryException($draw->status === 'published' ? 'This result is already published.' : 'The draw hasn\'t run yet: Verity draws once the committed drand round is out.');
        }
        $draw->update(['status' => 'published', 'published_at' => now()]);

        return $draw;
    }

    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($parts);
        $last = array_pop($parts);

        return $last ? "{$first} ".mb_substr($last, 0, 1).'.' : $first;
    }
}
