<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Mail\PostalEntryLogged;
use App\Models\Competition;
use App\Models\Entrant;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Free postal entries are logged by staff from the envelopes received. Each valid entry gets ONE
 * ticket number in the same sequence as paid entries, and counts towards the per-person maximum
 * (per entrant: the same person online, by post and at events). Envelopes must be RECEIVED at
 * least 48 hours before closes_at (or by the admin's postal_closes_at override,
 * Competition::postalClosesAt()); later ones are rejected. Either way the entrant gets an email.
 *
 * Coming soon: an envelope that arrives before the competition opens is logged as "held" (checked, no
 * ticket yet, no Verity call). When the competition opens (CompetitionLauncher::open, the same step that
 * opens online entry) every held entry is checked again and gets its ticket number, earliest received first.
 */
class PostalEntryService
{
    public function __construct(private TicketAllocator $allocator) {}

    /**
     * received_on: when the envelope arrived, a date-time ("2026-12-22 17:59") or a date alone (the
     * start of that day, the entrant-favourable reading).
     *
     * @param array{name:string,address:string,date_of_birth:string,email:string,phone?:string|null,received_on:string} $data
     */
    public function log(Competition $competition, array $data, User $staff): PostalEntry
    {
        // An unpublished draft takes nothing (nothing is logged, Verity is never called).
        if ($competition->status === 'draft' || ! $competition->isPublished()) {
            throw new EntryException($competition->notOpenYetReason() ?? "{$competition->title} isn't open for entries yet.");
        }
        $entry = DB::transaction(function () use ($competition, $data, $staff) {
            // Locked, so an envelope logged while the competition opens is either held before it opens (and the
            // opening allocates it) or allocated straight away after: never left held.
            $competition = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
            $hold = $competition->notOpenYetReason() !== null;
            $receivedAt = Carbon::parse($data['received_on']);
            $reason = $this->rejectionReason($competition, $data, $receivedAt);
            $entry = PostalEntry::create([
                'competition_id' => $competition->id,
                'user_id' => User::where('email', TicketAllocator::normaliseEmail($data['email']))->value('id'),
                'entrant_id' => Entrant::forEmail($data['email'])->id,
                'name' => $data['name'],
                'address' => $data['address'],
                'date_of_birth' => $data['date_of_birth'],
                'email' => TicketAllocator::normaliseEmail($data['email']),
                'phone' => $data['phone'] ?? null,
                'received_on' => $receivedAt->toDateString(),
                'received_at' => $receivedAt,
                'status' => $reason ? 'rejected' : ($hold ? 'held' : 'allocated'),
                'rejection_reason' => $reason,
                'logged_by' => $staff->id,
            ]);

            if (! $reason && ! $hold) {
                $this->allocateOne($competition, $entry);
            }

            return $entry->load('ticket', 'competition');
        });

        // After the commit: a rolled-back allocation never sends an email. A held entry gets "received, counted
        // when entries open"; its ticket number follows when the competition opens.
        Mail::to($entry->email)->queue(new PostalEntryLogged($entry));

        return $entry;
    }

    /**
     * The competition has opened: every held envelope is checked again (account status, max per person, sold
     * out, the deadline) and gets its ticket number, earliest received first, each in its own transaction.
     * One that can't be entered is rejected with the reason; an unexpected error leaves it held for the next
     * run (competitions:open-due retries open competitions that still have held entries).
     *
     * @return array{allocated: int, rejected: int, failed: int}
     */
    public function allocateHeld(Competition $competition): array
    {
        $counts = ['allocated' => 0, 'rejected' => 0, 'failed' => 0];
        $ids = PostalEntry::where('competition_id', $competition->id)->where('status', 'held')
            ->orderBy('received_at')->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            try {
                $entry = DB::transaction(function () use ($competition, $id) {
                    $locked = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
                    $entry = PostalEntry::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $entry || $entry->status !== 'held' || $locked->notOpenYetReason() !== null) {
                        return null;
                    }
                    $reason = $this->rejectionReason($locked, ['date_of_birth' => $entry->date_of_birth->toDateString(), 'email' => $entry->email], $entry->receivedMoment());
                    if ($reason) {
                        $entry->update(['status' => 'rejected', 'rejection_reason' => $reason]);
                    } else {
                        $this->allocateOne($locked, $entry);
                        $entry->update(['status' => 'allocated']);
                    }

                    return $entry->load('ticket', 'competition');
                });
            } catch (EntryException $e) {
                // Refused by the allocator or Verity (e.g. closed in the meantime): rejected, with the reason.
                PostalEntry::whereKey($id)->where('status', 'held')->update(['status' => 'rejected', 'rejection_reason' => mb_substr($e->getMessage(), 0, 250)]);
                $entry = PostalEntry::with('ticket', 'competition')->find($id);
            } catch (\Throwable $e) {
                report($e);
                $counts['failed']++;

                continue;
            }
            if (! $entry) {
                continue;
            }
            $counts[$entry->status === 'allocated' ? 'allocated' : 'rejected']++;
            Mail::to($entry->email)->queue(new PostalEntryLogged($entry));
        }

        return $counts;
    }

    private function allocateOne(Competition $competition, PostalEntry $entry): void
    {
        $this->allocator->allocate($competition, 1, [
            'channel' => 'postal',
            'email' => $entry->email,
            'name' => $entry->name,
            'user_id' => $entry->user_id,
            'postal_entry_id' => $entry->id,
        ]);
    }

    private function rejectionReason(Competition $competition, array $data, Carbon $received): ?string
    {
        if ($competition->isFree()) {
            return 'Free competitions have no postal entry';
        }
        if (! $competition->postal_entry_enabled) {
            return 'Postal entry not enabled for this competition';
        }
        if (($postalClose = $competition->postalClosesAt()) && $received->gt($postalClose)) {
            // The 48-hour rule, or the admin's own postal deadline when one was set.
            return $competition->postal_closes_at
                ? 'Received after the postal closing date'
                : Competition::POSTAL_LATE_REASON;
        }
        if (! ResponsiblePlay::isAdult($data['date_of_birth'])) {
            return 'Entrant under 18';
        }
        // As with free online entry: an account that is closed, suspended, self-excluded or on a break can't enter
        // by post either (matched on the email, as guest checkout is). Spend limits don't apply: postal entry is free.
        // These reasons are for Admin only: the entrant's email gives a neutral one (PostalEntry::isAccountRejection).
        $account = User::where('email', TicketAllocator::normaliseEmail($data['email']))->first();
        if ($account?->closed_at) {
            return PostalEntry::ACCOUNT_REASON_PREFIX.'closed';
        }
        if ($account?->suspended_at) {
            return PostalEntry::ACCOUNT_REASON_PREFIX.'suspended';
        }
        if ($account?->self_excluded_until?->isFuture()) {
            return PostalEntry::ACCOUNT_REASON_PREFIX.'self-excluded until '.$account->self_excluded_until->format('j M Y');
        }
        if ($account?->break_until?->isFuture()) {
            return PostalEntry::ACCOUNT_REASON_PREFIX.'on a break until '.$account->break_until->format('j M Y, H:i');
        }
        if ($competition->max_per_person !== null && $this->allocator->heldBy($competition, $data['email']) >= $competition->max_per_person) {
            return 'Maximum entries per person reached';
        }
        if ($competition->available() < 1) {
            return 'Competition sold out';
        }

        return null;
    }
}
