<?php

namespace App\Providers;

use App\Services\DrawService;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Sprouthub\Verity\Events\AlertRaised;
use Sprouthub\Verity\Events\CompetitionClosed;
use Sprouthub\Verity\Events\CompetitionDrawn;
use Sprouthub\Verity\Events\PrizeForfeited;
use Sprouthub\Verity\Events\ProcessedDue;
use Sprouthub\Verity\Events\RedrawScheduled;
use Sprouthub\Verity\Events\WinnerDrawn;
use Sprouthub\Verity\Models\Competition as VerityCompetition;

/**
 * Payment provider from .env, and the wiring to the Verity draw engine: its events (all dispatched
 * after commit) are mirrored onto WDS's competitions, draws and winners by DrawService::sync().
 */
class RaffleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => match (config('raffle.payments.driver')) {
            'cashflows' => new CashflowsGateway(config('raffle.payments.cashflows'), $this->app->make(\App\Services\Payments\BinLookup::class)),
            default => new FakeGateway(),
        });
        // No BIN service yet: swap in a real one here when chosen (see docs/cashflows-testing.md).
        $this->app->bindIf(\App\Services\Payments\BinLookup::class, \App\Services\Payments\NullBinLookup::class);
    }

    public function boot(): void
    {
        // Production only ever draws on public, verifiable randomness. Verity itself refuses the local
        // beacon at close and draw in production; web requests also refuse to boot, so a wrong setting
        // shows at once. Console commands still boot, so an operator can fix .env and run config:cache.
        if (! $this->app->runningInConsole()) {
            self::assertBeaconAllowed($this->app->environment(), config('verity.beacon'));
        }

        $sync = fn (VerityCompetition $c) => app(DrawService::class)->syncRef($c->ref);
        Event::listen(CompetitionClosed::class, fn (CompetitionClosed $e) => $sync($e->competition));
        Event::listen(CompetitionDrawn::class, fn (CompetitionDrawn $e) => $sync($e->competition));
        Event::listen(WinnerDrawn::class, fn (WinnerDrawn $e) => $sync($e->winner->competition));
        Event::listen(RedrawScheduled::class, fn (RedrawScheduled $e) => $sync($e->redraw->competition));
        Event::listen(PrizeForfeited::class, fn (PrizeForfeited $e) => $sync($e->winner->competition));
        // Catch-up after every verity:process-due run (also mirrors a competition voided for no entries).
        Event::listen(ProcessedDue::class, function () {
            $open = \App\Models\Competition::whereNotNull('verity_ref')
                ->where(fn ($q) => $q->whereNull('verity_status')->orWhereNotIn('verity_status', ['drawn', 'void']))->get();
            foreach ($open as $c) {
                app(DrawService::class)->sync($c);
            }
        });
        // Verity emails VERITY_ALERT_EMAILS itself; the log line makes it visible in Forge's logs too.
        Event::listen(AlertRaised::class, fn (AlertRaised $e) => Log::critical("Verity alert {$e->alert} ({$e->kind}): {$e->subject}", ['failing' => array_keys($e->failing)]));
    }

    public static function assertBeaconAllowed(string $environment, ?string $beacon): void
    {
        if ($environment === 'production' && $beacon !== 'drand') {
            throw new RuntimeException("VERITY_BEACON must be \"drand\" in production (it is \"{$beacon}\"). Local randomness is for development and tests only.");
        }
    }
}
