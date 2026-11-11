<?php

use Illuminate\Support\Facades\Schedule;

// Release capacity held by abandoned checkouts.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
