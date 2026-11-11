<?php

namespace App\Providers;

use App\Services\Draws\CommitRevealDrawEngine;
use App\Services\Draws\DrawEngine;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\ServiceProvider;

/** Swap payment provider and draw engine from .env — no code changes elsewhere. */
class RaffleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => match (config('raffle.payments.driver')) {
            'cashflows' => new CashflowsGateway(config('raffle.payments.cashflows')),
            default => new FakeGateway(),
        });

        $this->app->bind(DrawEngine::class, fn () => match (config('raffle.draws.engine')) {
            'verity' => new VerityDrawEngine(config('raffle.draws.verity')),
            default => new CommitRevealDrawEngine(),
        });
    }
}
