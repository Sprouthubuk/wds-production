<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\CreditTransaction;
use App\Models\DiscountCode;
use App\Models\Draw;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PostalEntry;
use App\Models\Ticket;
use App\Models\Winner;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Before launch: delete every test order, ticket, postal entry, draw and draw winner so the
 * competitions can be re-created on Verity from a clean start.
 *
 * Refuses if any order was paid through anything other than the fake gateway. That includes
 * event sales (cash / card reader) and orders covered by site credit, which have no gateway:
 * real money may have changed hands, so a person has to look at them first.
 *
 * Keeps users, competitions, promotions, settings and winners added by hand (no draw or ticket).
 *
 * Also refuses once anything is on the Verity draw engine: its records can't be deleted, so the
 * reset has to happen before the first publish (or on a fresh database).
 */
class ResetTestData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'wds:reset-test-data {--force : Run without asking in production}';

    protected $description = 'Delete all test orders, tickets, postal entries and draws before launch (refuses if any real payment exists)';

    public function handle(): int
    {
        // Verity's records can't be deleted (append-only audit chain, frozen entries: database triggers),
        // and the live database must start with none (forge/README.md, Verity go-live). So this only runs
        // before anything has been published on Verity.
        if ($engine = $this->verityRows()) {
            $this->error('Refusing: the Verity draw engine already holds data ('.implode(', ', $engine).').');
            $this->line('Its records are permanent by design. Start from an empty database instead (forge/README.md, section 4). Nothing was deleted.');

            return self::FAILURE;
        }

        $real = Order::query()
            ->where(fn ($q) => $q->whereNotNull('paid_at')->orWhereIn('status', ['paid', 'refunded']))
            ->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'fake'))
            ->selectRaw('payment_provider, payment_method, count(*) as n')
            ->groupBy('payment_provider', 'payment_method')
            ->get();

        if ($real->isNotEmpty()) {
            $this->error('Refusing: these paid orders did not go through the fake gateway, so real money may be involved.');
            $this->table(['payment_provider', 'payment_method', 'orders'], $real->map(fn ($r) => [
                $r->payment_provider ?? '(none)', $r->payment_method ?? '(none)', $r->n,
            ])->all());
            $this->line('Check them in Admin → Orders. Nothing was deleted.');

            return self::FAILURE;
        }

        $counts = [
            'orders' => Order::count(),
            'order lines' => OrderLine::count(),
            'tickets' => Ticket::count(),
            'postal entries' => PostalEntry::count(),
            'draws' => Draw::count(),
            'draw winners' => $this->drawWinners()->count(),
            'order credit transactions' => CreditTransaction::whereNotNull('order_id')->count(),
            'entrants' => Entrant::count(),
        ];
        $this->table(['will delete', 'rows'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        if (! $this->confirmToProceed('This deletes all of the above.')) {
            return self::FAILURE;
        }

        DB::transaction(function () {
            $this->drawWinners()->delete();
            Draw::query()->delete();
            Ticket::query()->delete();
            PostalEntry::query()->delete();
            CreditTransaction::whereNotNull('order_id')->delete();
            OrderLine::query()->delete();
            Order::query()->delete();
            Entrant::query()->delete();
            Competition::query()->update(['tickets_allocated' => 0]);
            DiscountCode::query()->update(['times_used' => 0]);
        });

        $this->info('Test data cleared. Competitions, users, promotions, settings and hand-added winners are untouched.');

        return self::SUCCESS;
    }

    /** @return list<string> non-empty Verity tables, e.g. "verity_competitions: 1" */
    private function verityRows(): array
    {
        $out = [];
        // Not verity_audit_events: the hourly verity:check records check.run there with no competition.
        foreach (['verity_competitions', 'verity_entry_groups'] as $t) {
            if (Schema::hasTable($t) && ($n = DB::table($t)->count()) > 0) {
                $out[] = "{$t}: {$n}";
            }
        }

        return $out;
    }

    private function drawWinners()
    {
        return Winner::query()->where(fn ($q) => $q->whereNotNull('draw_id')->orWhereNotNull('ticket_id'));
    }
}
