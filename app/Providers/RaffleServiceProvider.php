<?php

namespace App\Providers;

use App\Services\Draws\CommitRevealDrawEngine;
use App\Services\Draws\DrawEngine;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Swap payment provider and draw engine from .env — no code changes elsewhere. */
class RaffleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => match (config('raffle.payments.driver')) {
            'cashflows' => new CashflowsGateway(config('raffle.payments.cashflows')),
            default => new FakeGateway(),
        });

        $this->app->bind(DrawEngine::class, function () {
            self::assertDrawEngineAllowed($this->app->environment(), config('raffle.draws.engine'));

            return match (config('raffle.draws.engine')) {
                'verity' => new VerityDrawEngine(config('raffle.draws.verity')),
                default => new CommitRevealDrawEngine(),
            };
        });
    }

    /**
     * Production only ever draws with Verity: the commit-reveal engine keeps its secret seed in the
     * database between prepare and draw, so whoever can read the database can predict the winner.
     *
     * Web requests refuse to boot with any other engine. Console commands still boot, so an operator
     * can fix .env and run config:cache, but resolving the draw engine still throws, so no draw can run.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            self::assertDrawEngineAllowed($this->app->environment(), config('raffle.draws.engine'));
        }
    }

    public static function assertDrawEngineAllowed(string $environment, ?string $engine): void
    {
        if ($environment === 'production' && $engine !== 'verity') {
            throw new RuntimeException("DRAW_ENGINE must be \"verity\" in production (it is \"{$engine}\"). The commit-reveal engine is for local development and tests only.");
        }
    }
}
