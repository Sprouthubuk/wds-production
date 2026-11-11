<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';
    protected $description = 'Cancel pending online orders whose ticket reservation has expired';

    public function handle(): int
    {
        $n = Order::where('status', 'pending')->where('channel', 'online')
            ->where('reserved_until', '<', now())
            ->update(['status' => 'cancelled', 'reserved_until' => null]);
        $this->info("Expired {$n} orders.");

        return self::SUCCESS;
    }
}
