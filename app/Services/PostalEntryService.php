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
        // A draft can't take entries of any kind (nothing is logged, Verity is never called).
        if (! $competition->isPublished() || $competition->status === 'draft') {
            throw new EntryException("{$competition->title} isn't open for entries yet.");
        }
        $entry = DB::transaction(function () use ($competition, $data, $staff) {
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
                'status' => $reason ? 'rejected' : 'allocated',
                'rejection_reason' => $reason,
                'logged_by' => $staff->id,
            ]);

            if (! $reason) {
                $this->allocator->allocate($competition, 1, [
                    'channel' => 'postal',
                    'email' => $entry->email,
                    'name' => $entry->name,
                    'user_id' => $entry->user_id,
                    'postal_entry_id' => $entry->id,
                ]);
            }

            return $entry->load('ticket', 'competition');
        });

        // After the commit: a rolled-back allocation never sends an email.
        Mail::to($entry->email)->queue(new PostalEntryLogged($entry));

        return $entry;
    }

    private function rejectionReason(Competition $competition, array $data, Carbon $received): ?string
    {
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
        if ($competition->max_per_person !== null && $this->allocator->heldBy($competition, $data['email']) >= $competition->max_per_person) {
            return 'Maximum entries per person reached';
        }
        if ($competition->available() < 1) {
            return 'Competition sold out';
        }

        return null;
    }
}
