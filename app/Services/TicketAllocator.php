<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Entrant;
use App\Models\Ticket;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Facades\DB;
use Sprouthub\Verity\Models\Entry;

/**
 * Ticket allocation, numbered by the Verity draw engine. Numbers run 1, 2, 3 … in the order entries
 * are confirmed, across every channel (online, postal, event): the competition is published on Verity
 * with sequential allocation, so the numbers customers see are Verity's and match the certificate.
 *
 * WDS checks first (open, capacity with reservations; caps and responsible play are checked by the
 * callers), then Verity allocates and WDS writes the numbers into its own tickets table as a mirror.
 * Everything runs in the caller's transaction: if Verity refuses, nothing is written anywhere.
 *
 * Locks: WDS's competition row, then Verity's own (its competition row and its audit-chain lock),
 * always in that order.
 */
class TicketAllocator
{
    public function __construct(private VerityDrawEngine $verity) {}

    /**
     * @param  array{channel:string, email:string, name:string, user_id?:int|null, order_line_id?:int|null, postal_entry_id?:int|null, ordered_at?:mixed, total_pence?:int|null}  $owner
     * @param  bool  $ignorePendingReservations  true when confirming an order that already holds a reservation
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    public function allocate(Competition $competition, int $qty, array $owner, bool $ignorePendingReservations = false)
    {
        if ($qty < 1) {
            throw new EntryException('Quantity must be at least 1.');
        }
        if ($owner['channel'] === 'postal' && $qty !== 1) {
            throw new EntryException('A postal entry is one ticket.');
        }

        return DB::transaction(function () use ($competition, $qty, $owner, $ignorePendingReservations) {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPublished()) {
                throw new EntryException("{$locked->title} isn't open yet: it hasn't been published.");
            }

            $email = self::normaliseEmail($owner['email']);
            $entrant = Entrant::forEmail($email);

            // A retried fulfilment of the same order line / postal entry: hand back what it already got.
            if ($hostRef = VerityDrawEngine::hostRef($owner)) {
                $existing = Ticket::where('competition_id', $locked->id)
                    ->where($owner['channel'] === 'postal' ? 'postal_entry_id' : 'order_line_id', $owner['postal_entry_id'] ?? $owner['order_line_id'])
                    ->orderBy('number')->get();
                if ($existing->isNotEmpty()) {
                    return $existing;
                }
            }

            $free = $locked->total_tickets - $locked->tickets_allocated;
            if (! $ignorePendingReservations) {
                $free -= $locked->reservedPending();
            }
            if ($qty > $free) {
                throw new EntryException($free > 0
                    ? "Only {$free} tickets left for {$locked->title}."
                    : "{$locked->title} is sold out.");
            }

            $group = $this->verity->addEntries($locked, $entrant, $qty, $owner);
            $entries = Entry::query()->where('entry_group_id', $group->id)->orderBy('number')->get(['id', 'number']);
            if ($entries->count() !== $qty) {
                throw new EntryException('The draw engine returned a different number of entries than requested.');
            }

            $now = now();
            Ticket::insert($entries->map(fn (Entry $e) => [
                'competition_id' => $locked->id,
                'number' => (int) $e->number,
                'verity_entry_id' => $e->id,
                'channel' => $owner['channel'],
                'order_line_id' => $owner['order_line_id'] ?? null,
                'postal_entry_id' => $owner['postal_entry_id'] ?? null,
                'user_id' => $owner['user_id'] ?? null,
                'entrant_id' => $entrant->id,
                'email' => $email,
                'name' => $owner['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            // Never goes down (as Verity's tickets_sold): a withdrawn number is never given out again.
            $locked->increment('tickets_allocated', $qty);

            return Ticket::whereIn('verity_entry_id', $entries->pluck('id'))->orderBy('number')->get();
        });
    }

    /**
     * Tickets this entrant (one per normalised email) holds across every channel, plus any they're
     * holding in pending orders. The per-person maximum is checked against this (Verity checks the
     * same maximum again per owner reference).
     */
    public function heldBy(Competition $competition, string $email): int
    {
        $entrant = Entrant::findByEmail($email);
        if (! $entrant) {
            return 0;
        }
        $allocated = Ticket::active()->where('competition_id', $competition->id)->where('entrant_id', $entrant->id)->count();
        $pending = (int) DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.competition_id', $competition->id)
            ->where('orders.entrant_id', $entrant->id)
            ->where('orders.status', 'pending')
            ->where('orders.reserved_until', '>', now())
            ->sum('order_lines.qty');

        return $allocated + $pending;
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
