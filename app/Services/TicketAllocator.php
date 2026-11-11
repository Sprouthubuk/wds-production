<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Sequential ticket allocation. Numbers run 1..total_tickets in the order entries are confirmed,
 * across every channel (online, postal, event) — no lucky dips, no picking.
 *
 * The competition row is locked FOR UPDATE so two payments confirming at the same moment can
 * never receive the same number; the unique(competition_id, number) index is the final backstop.
 */
class TicketAllocator
{
    /**
     * @param  array{channel:string, email:string, name:string, user_id?:int|null, order_line_id?:int|null, postal_entry_id?:int|null}  $owner
     * @param  bool  $ignorePendingReservations  true when confirming an order that already holds a reservation
     * @return \Illuminate\Support\Collection<int, Ticket>
     */
    public function allocate(Competition $competition, int $qty, array $owner, bool $ignorePendingReservations = false)
    {
        if ($qty < 1) {
            throw new EntryException('Quantity must be at least 1.');
        }

        return DB::transaction(function () use ($competition, $qty, $owner, $ignorePendingReservations) {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->id)->lockForUpdate()->firstOrFail();

            $free = $locked->total_tickets - $locked->tickets_allocated;
            if (! $ignorePendingReservations) {
                $free -= $locked->reservedPending();
            }
            if ($qty > $free) {
                throw new EntryException($free > 0
                    ? "Only {$free} tickets left for {$locked->title}."
                    : "{$locked->title} is sold out.");
            }

            $email = self::normaliseEmail($owner['email']);
            $start = $locked->tickets_allocated + 1;
            $now = now();
            $rows = [];
            for ($n = $start; $n < $start + $qty; $n++) {
                $rows[] = [
                    'competition_id' => $locked->id,
                    'number' => $n,
                    'channel' => $owner['channel'],
                    'order_line_id' => $owner['order_line_id'] ?? null,
                    'postal_entry_id' => $owner['postal_entry_id'] ?? null,
                    'user_id' => $owner['user_id'] ?? null,
                    'email' => $email,
                    'name' => $owner['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Ticket::insert($rows);
            $locked->increment('tickets_allocated', $qty);

            return Ticket::where('competition_id', $locked->id)
                ->whereBetween('number', [$start, $start + $qty - 1])
                ->orderBy('number')
                ->get();
        });
    }

    /** Tickets this person already holds (all channels) plus any they're holding in pending orders. */
    public function heldBy(Competition $competition, string $email): int
    {
        $email = self::normaliseEmail($email);
        $allocated = Ticket::where('competition_id', $competition->id)->where('email', $email)->count();
        $pending = (int) DB::table('order_lines')
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.competition_id', $competition->id)
            ->where('orders.email', $email)
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
