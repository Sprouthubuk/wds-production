<?php

namespace App\Services\Draws;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Entrant;
use App\Models\User;
use App\Models\Winner;
use App\Services\DrawService;
use Illuminate\Support\Facades\DB;
use Sprouthub\Verity\Engine\Drand;
use Sprouthub\Verity\Engine\VerityException;
use Sprouthub\Verity\Facades\Verity;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Sprouthub\Verity\Models\EntryGroup;
use Sprouthub\Verity\Models\Redraw;
use Sprouthub\Verity\Models\Winner as VerityWinner;

/**
 * WDS's one door into the Verity draw engine (sprouthub/verity-engine). Verity owns the numbering,
 * the close, the draw, redraws and the proof; WDS decides who may enter (caps, responsible play,
 * reservations, pricing, payment) and mirrors what Verity decided (DrawService::sync).
 *
 * Nothing here can choose a ticket number or a winner: Verity's API has no way to.
 */
class VerityDrawEngine
{
    /**
     * Verity's entry sources: online and event sales are both paid; postal entries are free, equal chances.
     * An online entry to a free competition (£0) is recorded as "free".
     */
    public const SOURCE_FOR_CHANNEL = ['online' => 'paid', 'event' => 'paid', 'postal' => 'postal'];

    /**
     * Publish = lock the draw in. Verity fixes the terms (closing time, ticket count, price, per-person
     * maximum), commits to the seed and plans the drand round. One way: there is no unpublish.
     */
    public function publish(Competition $competition): Competition
    {
        if ($competition->isPublished()) {
            throw new EntryException("{$competition->title} is already published (draw {$competition->verity_ref}).");
        }
        if (! $competition->closes_at) {
            throw new EntryException('Set the closing time before publishing: the draw round is fixed from it.');
        }
        if ($competition->closes_at->isPast()) {
            throw new EntryException('The closing time has passed.');
        }
        if (! $competition->draw_at) {
            throw new EntryException('Set the live draw time before publishing.');
        }
        if ($competition->draw_at->lt($competition->closes_at)) {
            throw new EntryException('The live draw can\'t be before entries close.');
        }
        if ($competition->drawsExternally() && ! $competition->drawTool()) {
            throw new EntryException('Name the random number tool for the external draw (and its https link) before publishing.');
        }

        return DB::transaction(function () use ($competition) {
            $locked = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPublished()) {
                throw new EntryException("{$locked->title} is already published (draw {$locked->verity_ref}).");
            }
            try {
                $vc = Verity::publish([
                    'title' => $locked->title,
                    'max_tickets' => (int) $locked->total_tickets,
                    'ticket_price_pence' => (int) $locked->ticket_price_pence,
                    'max_per_person' => $locked->max_per_person === null ? null : (int) $locked->max_per_person, // null = no cap
                    'closes_at' => $locked->closes_at,
                    'opens_at' => $locked->opens_at,
                    'ticket_allocation' => 'sequential', // 1, 2, 3 … across online, postal and event (no instant wins)
                    'draw_method' => $locked->draw_method ?? Competition::DRAW_VERITY, // fixed from here on
                ] + ($locked->drawsExternally() ? ['external_tool' => $locked->drawTool()] : []) + [
                    'meta' => ['wds_competition_id' => $locked->id, 'wds_slug' => $locked->slug],
                ], endPrizes: [[
                    'name' => $locked->title,
                    'value_pence' => (int) ($locked->cash_alternative_pence ?? config('raffle.draws.default_prize_value_pence')),
                ]]);
            } catch (VerityException $e) {
                throw new EntryException($e->getMessage());
            }
            $locked->update([
                'verity_ref' => $vc->ref,
                'verity_status' => $vc->status,
                'published_at' => now(),
                'status' => $locked->opens_at?->isFuture() ? 'scheduled' : 'live',
            ]);

            return $locked;
        });
    }

    /** Move the closing time later (the only change Verity allows after publish), with a public reason. */
    public function extend(Competition $competition, \DateTimeInterface $until, string $reason): Competition
    {
        try {
            $vc = Verity::extend($this->verityCompetition($competition), $until, $reason);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
        $competition->update(['closes_at' => $vc->closes_at]);

        return $competition;
    }

    /**
     * Add entries for one WDS order line / postal entry. Runs inside the caller's transaction, so a
     * refusal from Verity rolls the whole WDS fulfilment back.
     *
     * @param  array{channel:string, order_line_id?:int|null, postal_entry_id?:int|null, ordered_at?:mixed, total_pence?:int|null}  $owner
     */
    public function addEntries(Competition $competition, Entrant $entrant, int $qty, array $owner): EntryGroup
    {
        $source = self::SOURCE_FOR_CHANNEL[$owner['channel']] ?? throw new EntryException("Unknown channel {$owner['channel']}.");
        if ($source === 'paid' && $competition->isFree()) {
            $source = 'free';
        }
        $options = array_filter([
            'source' => $source,
            'host_ref' => self::hostRef($owner),
            'ordered_at' => $owner['ordered_at'] ?? null,
            'total_pence' => $owner['total_pence'] ?? null,
        ], fn ($v) => $v !== null);
        try {
            return $source === 'postal'
                ? Verity::addPostalEntry($competition->verity_ref, $entrant->ownerRef(), $options)
                : Verity::addEntries($competition->verity_ref, $entrant->ownerRef(), $qty, $options);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
    }

    /** "order_line:12" / "postal:7": Verity's idempotency key, so a retried webhook allocates nothing twice. */
    public static function hostRef(array $owner): ?string
    {
        return match (true) {
            ! empty($owner['order_line_id']) => 'order_line:'.$owner['order_line_id'],
            ! empty($owner['postal_entry_id']) => 'postal:'.$owner['postal_entry_id'],
            default => null,
        };
    }

    /** Take an order line's entries out of the draw (a refund). Only while the competition is open. */
    public function withdraw(string $hostRef, string $reason): void
    {
        $group = EntryGroup::query()->where('host_ref', $hostRef)->first();
        if (! $group) {
            return; // nothing was ever entered for it
        }
        try {
            Verity::withdrawEntries($group, $reason);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
    }

    /**
     * Forfeit a drawn winner, through Verity, so it's on the public audit chain:
     *  - $redraw = true: Verity::redraw() forfeits the winner AND commits the replacement to a drand round
     *    still in the future (protocol R2), in one step. verity:process-due draws it once the round is out.
     *    There is no separate "redraw later": fixing the round at the forfeit is what stops anyone
     *    choosing when to redraw. Returns the scheduled redraw, or null if no redraw was possible
     *    (VERITY_MAX_REDRAWS reached, or every entry has won), in which case the prize is unawarded.
     *  - $redraw = false: Verity::forfeit(), the prize is unawarded (draw.forfeited), no redraw ever.
     *
     * Only the reason's label (a category, no personal details) goes to Verity, because the audit chain
     * is public and permanent. The note stays in WDS. The WDS winner row is kept, marked forfeited.
     */
    public function forfeit(Winner $winner, string $category, ?string $note, bool $redraw, User $admin): ?Redraw
    {
        if ($winner->isForfeited()) {
            throw new EntryException('This winner has already been forfeited.');
        }
        $reason = Winner::forfeitLabel($category) ?? throw new EntryException('Choose a reason for the forfeit.');
        $vw = $winner->verity_winner_id ? VerityWinner::query()->find($winner->verity_winner_id) : null;
        if (! $vw) {
            throw new EntryException('Only a winner drawn by the draw engine can be forfeited here.');
        }

        return DB::transaction(function () use ($winner, $category, $note, $redraw, $admin, $reason, $vw) {
            try {
                $scheduled = null;
                if ($redraw) {
                    $scheduled = Verity::redraw($vw, $reason, by: 'admin:'.$admin->id);
                } else {
                    Verity::forfeit($vw, $reason, by: 'admin:'.$admin->id);
                }
            } catch (VerityException $e) {
                throw new EntryException($e->getMessage());
            }
            $winner->update([
                'forfeited_at' => now(),
                'forfeit_category' => $category,
                'forfeit_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                'forfeited_by' => $admin->id,
                'show_on_site' => false,
            ]);

            return $scheduled;
        });
    }

    /** Verity still takes entries (and withdrawals) for this competition. */
    /**
     * Method A, on the livestream: close entries now and commit to the next drand round (about a minute
     * away). verity:process-due draws it once the round is out; the closing time stays as the backstop.
     */
    public function closeAndDrawNow(Competition $competition, User $admin): Competition
    {
        if ($competition->drawsExternally()) {
            throw new EntryException('This competition is drawn externally: use "Close entries".');
        }

        return $this->closeLive($competition, fn ($vc, $reason) => Verity::closeAndDrawNow($vc, $reason));
    }

    /** Method B: change the random number tool, until entries close (on Verity's audit trail each time). */
    public function setDrawTool(Competition $competition, string $name, string $url, User $admin): Competition
    {
        if (! $competition->drawsExternally()) {
            throw new EntryException('Only an externally drawn competition has a random number tool.');
        }
        try {
            $vc = Verity::setExternalTool($this->verityCompetition($competition), $name, $url, 'admin:'.$admin->id);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
        $competition->update(['draw_tool_name' => $vc->external_tool_name, 'draw_tool_url' => $vc->external_tool_url]);

        return $competition;
    }

    /** Method B, on the livestream: close entries and seal the list with the random number tool (no drand round). */
    public function closeEntries(Competition $competition, User $admin): Competition
    {
        if (! $competition->drawsExternally()) {
            throw new EntryException('This competition is drawn by Verity: use "Close & draw now".');
        }

        return $this->closeLive($competition, fn ($vc, $reason) => Verity::close($vc, $reason));
    }

    /** Method B: the winning ticket drawn on the livestream, recorded on Verity's audit chain with the link. */
    public function recordExternalResult(Competition $competition, int $number, string $livestreamUrl, User $admin): Competition
    {
        if (! $competition->drawsExternally()) {
            throw new EntryException('Only an externally drawn competition takes a result.');
        }
        try {
            Verity::recordExternalResult($this->verityCompetition($competition), $number, $livestreamUrl, 'admin:'.$admin->id);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
        app(DrawService::class)->sync($competition);

        return $competition->fresh();
    }

    private function closeLive(Competition $competition, callable $close): Competition
    {
        $vc = $this->verityCompetition($competition);
        if ($vc->status !== 'live') {
            throw new EntryException("{$competition->title} isn't taking entries (it's {$vc->status}).");
        }
        // Closing before the advertised time needs a public reason on Verity's record.
        $reason = 'Entries closed live on the draw stream at '.now()->timezone('Europe/London')->format('g:ia \o\n j F Y').' (UK time).';
        try {
            $close($vc, $reason);
        } catch (VerityException $e) {
            throw new EntryException($e->getMessage());
        }
        app(DrawService::class)->sync($competition);

        return $competition->fresh();
    }

    public function isOpen(Competition $competition): bool
    {
        return $competition->isPublished() && in_array($this->verityCompetition($competition)->status, ['live', 'paused', 'draft'], true);
    }

    public function verityCompetition(Competition $competition): VerityCompetition
    {
        if (! $competition->isPublished()) {
            throw new EntryException("{$competition->title} isn't published on the draw engine yet.");
        }

        return VerityCompetition::query()->where('ref', $competition->verity_ref)->firstOrFail();
    }

    /** The public verification record (spec §7): everything anyone needs to re-run the draw. */
    public function verification(Draw $draw): array
    {
        return Verity::verification($draw->verity_ref);
    }

    /**
     * The certificate summary: the facts people look for, all taken from the verification record and
     * the audit chain (nothing WDS computed itself).
     */
    public function certificate(Draw $draw): array
    {
        $record = $this->verification($draw);
        $v = $record['verity'] ?? [];
        $completed = collect($record['audit'] ?? [])->firstWhere('type', 'draw.completed');
        $tsa = collect($record['close_witnesses'] ?? [])->first(fn ($w) => $w['kind'] === 'rfc3161' && $w['ok']);

        return [
            'reference' => $record['competition']['ref'] ?? $draw->verity_ref,
            'algorithm' => $v['spec'] ?? null,
            'ticketAllocation' => $v['ticket_allocation'] ?? null,
            'commitment' => $v['commitment'] ?? null,
            'revealedSeed' => $v['revealed_seed'] ?? null,
            'entriesCount' => $v['entries_count'] ?? null,
            'entriesHash' => $v['entries_hash'] ?? null,
            'drandChainHash' => Drand::CHAIN_HASH,
            'drandRound' => $v['beacon_round'] ?? null,
            'drandRoundAt' => isset($v['beacon_round']) ? gmdate('Y-m-d\TH:i:s\Z', Drand::roundTime((int) $v['beacon_round'])) : null,
            'drandRandomness' => $v['beacon_randomness'] ?? null,
            'drandSignature' => $v['beacon_signature'] ?? null,
            'drawSeed' => $v['draw_seed'] ?? null,
            'winningTickets' => $record['winning_tickets'] ?? [],
            'auditEvent' => $completed ? ['seq' => $completed['seq'] ?? null, 'hash' => $completed['hash'] ?? null] : null,
            'closeTimestamp' => $tsa ? ['authority' => config('verity.tsa_url'), 'witnessedAt' => $tsa['witnessed_at'], 'token' => $tsa['token']] : null,
            'engine' => $record['engine'] ?? null,
            'winners' => self::publicWinners($draw),
            // Redraws committed to a drand round that isn't out yet (protocol R2).
            'redrawsPending' => collect($record['redraws'] ?? [])->where('status', 'scheduled')
                ->map(fn ($r) => ['redraw' => $r['redraw'], 'forfeitedTicket' => $r['forfeited_ticket'], 'drandRound' => $r['beacon_round'] ?? null, 'drandRoundAt' => $r['round_time'] ?? null])
                ->values()->all(),
            'howToVerify' => config('raffle.draws.verify_page').'/'.$draw->id,
            // How it was drawn: "verity" (drand) or "external" (on the livestream, from the sealed list).
            'method' => $v['draw_method'] ?? 'verity',
            'drawNow' => (bool) ($v['draw_now'] ?? false),
            'livestreamUrl' => $v['external']['livestream_url'] ?? null,
            'drawTool' => $v['external']['tool'] ?? null,     // the random number tool, sealed with the entry list
            'seal' => $v['external']['seal'] ?? null,
            'statement' => ($v['draw_method'] ?? 'verity') === 'external' ? Competition::EXTERNAL_STATEMENT : null,
            'recordedAt' => $v['external']['recorded_at'] ?? null,
        ];
    }

    /**
     * Every winner of a draw, as shown publicly: the original draw, then Redraw 1, 2 …. A forfeited
     * winner is never removed. It's listed as forfeited with its reason category, by ticket number
     * only (no name: a forfeit reason next to a person's name, published for good, isn't needed to
     * verify anything).
     *
     * @return list<array{label:string, ticket:?string, name:?string, status:string, reason:?string}>
     */
    public static function publicWinners(Draw $draw): array
    {
        $total = (int) $draw->competition->total_tickets;

        return Winner::with('ticket')->where('draw_id', $draw->id)->get()
            ->sortBy(fn (Winner $w) => [(int) $w->redraw_number, $w->id])->values()
            ->map(fn (Winner $w) => [
                'label' => $w->redraw_number ? "Redraw {$w->redraw_number}" : 'Original draw',
                'ticket' => $w->ticket?->display($total),
                'name' => $w->isForfeited() ? null : $w->display_name,
                'status' => $w->isForfeited() ? 'forfeited' : 'winner',
                'reason' => $w->isForfeited() ? (Winner::forfeitLabel($w->forfeit_category) ?? 'Forfeited under the terms') : null,
            ])->all();
    }

    /**
     * Public audit events for one competition (withheld fields nulled), for the admin trail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function auditTrail(Competition $competition): array
    {
        return $competition->isPublished() ? Verity::auditEvents($competition->verity_ref) : [];
    }

    /** When a drand round is published (UTC). */
    public static function roundTime(?int $round): ?\Illuminate\Support\Carbon
    {
        return $round ? \Illuminate\Support\Carbon::createFromTimestampUTC(Drand::roundTime($round)) : null;
    }
}
