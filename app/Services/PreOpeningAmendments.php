<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\CompetitionEvent;
use App\Models\OrderLine;
use App\Models\PostalEntry;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Mail\PlainNotice;
use Sprouthub\Verity\Models\AuditEvent;
use Sprouthub\Verity\Models\EntryGroup;
use Sprouthub\Verity\Services\AuditLog;
use Sprouthub\Verity\Support\ChainRollback;

/**
 * The one change to a published competition's terms besides an emergency postponement: setting or lowering
 * the maximum per person, while it is coming soon and nobody has entered (competitions:amend-cap).
 *
 * Nothing else can change this way, and it can only get tighter. The change is recorded openly: an event on
 * Verity's audit chain (competition.amended), an email to the anchor recipients, the competition's history in
 * Admin, and the draw's /verify page.
 *
 * Verity has no way to change a published competition, so its own row keeps the maximum it was published with.
 * WDS enforces the amended one wherever entries are made, TicketAllocator (every entry's last step) included.
 * The maximum isn't in Verity's seed commitment, its competition.published event or its public record.
 */
class PreOpeningAmendments
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 300;

    public function __construct(private VerityDrawEngine $verity) {}

    /**
     * Everything that stops this amendment, in words (empty = allowed).
     *
     * @return list<string>
     */
    public function problems(Competition $c, int $max): array
    {
        $out = [];

        // 1. Not open yet.
        if ($c->status !== 'coming_soon' || ! $c->isPublished()) {
            $out[] = "It's {$c->status}, not coming soon: terms can only be amended before opening.";
        } elseif (! $c->opens_at?->isFuture()) {
            $out[] = 'Its opening time has passed.';
        } elseif (($vs = $this->verity->verityCompetition($c)->status) !== 'draft') {
            $out[] = "The draw engine has it as {$vs}: it has already opened.";
        }

        // 2. Nobody has entered. Any order counts, whatever its status: a late payment can revive an expired one.
        foreach ($this->entries($c) as $what => $n) {
            if ($n > 0) {
                $out[] = "It already has {$what}: {$n}.";
            }
        }

        // 3. Tighter only.
        $now = $c->max_per_person === null ? null : (int) $c->max_per_person;
        if ($max < 1) {
            $out[] = 'The maximum must be at least 1.';
        } elseif ($now !== null && $max === $now) {
            $out[] = "The maximum per person is already {$now}.";
        } elseif ($now !== null && $max > $now) {
            $out[] = "The maximum per person is {$now}: {$max} would loosen it.";
        } elseif ($now === null && $max >= (int) $c->total_tickets) {
            $out[] = "It has {$c->total_tickets} tickets: a maximum of {$max} wouldn't tighten anything.";
        }

        return $out;
    }

    /**
     * What stops any amendment being recorded and witnessed right now (empty = ready).
     *
     * @return list<string>
     */
    public function systemProblems(): array
    {
        $out = [];
        $chain = AuditLog::verifyChain();
        if (! $chain['valid']) {
            $out[] = "The audit chain is broken at #{$chain['broken_at']}: {$chain['reason']}";
        } elseif ($why = ChainRollback::problem()) {
            $out[] = $why;
        }
        if (! config('verity.anchor_recipients')) {
            $out[] = 'VERITY_ANCHOR_EMAILS is not set, so the amendment could not be sent to the witnesses.';
        }

        return $out;
    }

    /** @return array<string, int> */
    public function entries(Competition $c): array
    {
        $vc = $c->isPublished() ? $this->verity->verityCompetition($c) : null;

        return [
            'tickets' => max(Ticket::where('competition_id', $c->id)->count(), (int) $c->tickets_allocated),
            'orders (paid, pending or ended)' => OrderLine::where('competition_id', $c->id)->count(),
            'postal entries (held or entered)' => PostalEntry::where('competition_id', $c->id)->where('status', '!=', 'rejected')->count(),
            'entries on the draw engine' => $vc ? max(EntryGroup::query()->where('competition_id', $vc->id)->count(), (int) $vc->tickets_sold) : 0,
        ];
    }

    /** Set or lower the maximum per person, with its audit event and Admin's history, all in one transaction. */
    public function apply(Competition $competition, int $max, string $reason, User $by): AuditEvent
    {
        $reason = self::cleanReason($reason);
        if ($problems = $this->systemProblems()) {
            throw new EntryException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($competition, $max, $reason, $by) {
            // The lock every entry route and the opening take first: nothing can enter or open meanwhile.
            $c = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if ($problems = $this->problems($c, $max)) {
                throw new EntryException(implode(' ', $problems));
            }
            $from = $c->max_per_person === null ? null : (int) $c->max_per_person;
            $event = $this->verity->recordCapAmended($c, $from, $max, $reason, $by);
            $c->update(['max_per_person' => $max]);
            CompetitionEvent::record($c, $by, 'terms_amended',
                self::sentence($max).' ('.self::was($from).") by {$by->email}. Audit record #{$event->seq}. Reason: {$reason}",
                ['field' => 'max_per_person', 'from' => $from, 'to' => $max, 'reason' => $reason, 'audit_seq' => (int) $event->seq, 'audit_hash' => $event->hash]);

            return $event;
        });
    }

    /** The witness email, sent straight away as Verity's own close witnesses are. False if it couldn't be sent. */
    public function emailWitnesses(AuditEvent $event, Competition $c): bool
    {
        try {
            Mail::to((array) config('verity.anchor_recipients'))->send(new PlainNotice("Verity audit record {$event->seq}", self::witnessText($event, $c)));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public static function witnessText(AuditEvent $event, Competition $c): string
    {
        $p = (array) $event->payload;

        return "Verity: terms amended before opening for {$c->title} ({$c->verity_ref})\n\n"
            .self::sentence((int) $p['to']).' ('.self::was($p['from']).")\n"
            ."Reason: {$p['reason']}\nBy: {$p['by']}\nAt: {$event->occurred_at}\nEntries at the time: 0\nOpens at: {$p['opens_at']}\n"
            ."Audit record: seq {$event->seq}, hash {$event->hash}\n\n"
            ."Keep this email. It shows the terms were tightened before anyone could enter.\n";
    }

    /** As shown in Admin's history and on the public /verify page. */
    public static function sentence(int $max): string
    {
        return "Terms amended before opening: max per person set to {$max}";
    }

    public static function was(?int $from): string
    {
        return $from === null ? 'was no limit' : "was {$from}";
    }

    public static function cleanReason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            throw new EntryException('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters (--reason). It is public and permanent.');
        }

        return $reason;
    }
}
