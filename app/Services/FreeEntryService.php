<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A free competition (ticket price £0): one online entry per signed-in account with a verified email,
 * plus the usual free postal route. No basket and no payment gateway: a £0 order is created and
 * fulfilled straight away through the normal path (CheckoutService::markPaid), so the entrant gets the
 * next sequential ticket number, the tickets email, and an entry on Verity (source "free") for the
 * normal draw.
 */
class FreeEntryService
{
    public function __construct(
        private CheckoutService $checkout,
        private TicketAllocator $allocator,
        private ResponsiblePlay $responsiblePlay,
    ) {}

    public function enter(User $user, Competition $competition, bool $marketingOptIn = false): Order
    {
        if (! $competition->isFree()) {
            throw new EntryException("{$competition->title} isn't a free competition.");
        }
        if (! $user->hasVerifiedEmail()) {
            throw new EntryException('Verify your email address to enter free competitions. Use the link we emailed you, or ask for a new one in your account.');
        }
        if (! ResponsiblePlay::isAdult($user->date_of_birth?->toDateString())) {
            throw new EntryException('You must be 18 or over to enter.');
        }
        // Suspended, self-excluded or on a break: the same account checks as a paid entry (nothing to spend).
        $this->responsiblePlay->assertCanSpend($user, 0);

        $email = TicketAllocator::normaliseEmail($user->email);
        $order = DB::transaction(function () use ($user, $competition, $email, $marketingOptIn) {
            $c = Competition::whereKey($competition->id)->lockForUpdate()->firstOrFail();
            if (! $c->isPublished() || $c->status === 'draft') {
                throw new EntryException("{$c->title} isn't open for entries yet.");
            }
            if (! $c->isOpen()) {
                throw new EntryException("{$c->title} is closed for entries.");
            }
            // One online entry per account: an earlier entry (or one being fulfilled right now) counts.
            // The competition row lock above serialises two clicks of the button.
            if ($this->alreadyEntered($user, $c, $email)) {
                throw new EntryException("You've already entered {$c->title}. Good luck!");
            }
            if ($c->available() < 1) {
                throw new EntryException("{$c->title} is full.");
            }
            if ($this->allocator->heldBy($c, $email) + 1 > $c->max_per_person) {
                throw new EntryException("You already have the maximum number of entries for {$c->title}.");
            }

            $order = Order::create([
                'reference' => CheckoutService::newReference(),
                'access_key' => Str::random(40),
                'user_id' => $user->id,
                'entrant_id' => Entrant::forEmail($email)->id,
                'email' => $email,
                'name' => trim((string) $user->name),
                'phone' => $user->phone,
                'date_of_birth' => $user->date_of_birth?->toDateString(),
                'channel' => 'online',
                'status' => 'pending',
                'subtotal_pence' => 0, 'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => 0,
                'payment_method' => 'free',
                'marketing_opt_in' => $marketingOptIn,
                'reserved_until' => now()->addMinutes(5),
            ]);
            $order->lines()->create(['competition_id' => $c->id, 'qty' => 1, 'unit_price_pence' => 0, 'line_total_pence' => 0]);

            return $order;
        });

        $this->checkout->markPaid($order, 'free', null);
        if ($marketingOptIn && ! $user->marketing_opt_in) {
            $user->forceFill(['marketing_opt_in' => true])->save();
        }

        return $order->fresh();
    }

    public function alreadyEntered(User $user, Competition $c, string $email): bool
    {
        $ticket = Ticket::active()->where('competition_id', $c->id)->where('channel', 'online')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $email))->exists();

        return $ticket || Order::where('user_id', $user->id)->whereIn('status', ['pending', 'paid'])
            ->whereHas('lines', fn ($q) => $q->where('competition_id', $c->id))->exists();
    }
}
