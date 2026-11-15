<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use App\Services\Payments\CashflowsGateway;
use App\Services\Payments\CashflowsReconciler;
use App\Services\Payments\PaymentGateway;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';
    protected $description = 'Re-query Cashflows for orders still waiting on a payment, in case a notification was missed';

    public function handle(PaymentGateway $gateway, CheckoutService $checkout): int
    {
        if (! $gateway instanceof CashflowsGateway) {
            $this->info('Cashflows is not the payment driver: nothing to reconcile.');

            return self::SUCCESS;
        }
        $counts = (new CashflowsReconciler($gateway, $checkout))->run();
        $this->info(collect($counts)->map(fn ($n, $k) => "{$k} {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
