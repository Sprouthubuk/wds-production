<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Entrant;
use App\Models\PostalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Free postal entries are logged by staff from the envelopes received. Each valid entry gets ONE
 * ticket number in the same sequence as paid entries, and counts towards the per-person maximum
 * (per entrant: the same person online, by post and at events). Envelopes received after the
 * competition's postal closing date (Competition::postalClosesAt()) are rejected.
 */
class PostalEntryService
{
    public function __construct(private TicketAllocator $allocator) {}

    /** @param array{name:string,address:string,date_of_birth:string,email:string,phone?:string|null,received_on:string} $data */
    public function log(Competition $competition, array $data, User $staff): PostalEntry
    {
        return DB::transaction(function () use ($competition, $data, $staff) {
            $reason = $this->rejectionReason($competition, $data);
            $entry = PostalEntry::create([
                'competition_id' => $competition->id,
                'user_id' => User::where('email', TicketAllocator::normaliseEmail($data['email']))->value('id'),
                'entrant_id' => Entrant::forEmail($data['email'])->id,
                'name' => $data['name'],
                'address' => $data['address'],
                'date_of_birth' => $data['date_of_birth'],
                'email' => TicketAllocator::normaliseEmail($data['email']),
                'phone' => $data['phone'] ?? null,
                'received_on' => $data['received_on'],
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

            return $entry->load('ticket');
        });
    }

    private function rejectionReason(Competition $competition, array $data): ?string
    {
        if (! $competition->postal_entry_enabled) {
            return 'Postal entry not enabled for this competition';
        }
        $received = now()->parse($data['received_on'])->startOfDay();
        if (($postalClose = $competition->postalClosesAt()) && $received->gt($postalClose)) {
            return 'Received after the postal closing date';
        }
        if ($competition->closes_at && $received->gt($competition->closes_at)) {
            return 'Received after the closing date';
        }
        if (! ResponsiblePlay::isAdult($data['date_of_birth'])) {
            return 'Entrant under 18';
        }
        if ($this->allocator->heldBy($competition, $data['email']) >= $competition->max_per_person) {
            return 'Maximum entries per person reached';
        }
        if ($competition->available() < 1) {
            return 'Competition sold out';
        }

        return null;
    }
}
