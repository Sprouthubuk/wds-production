<?php

use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;
use Sprouthub\Verity\Facades\Verity;

// Release capacity held by abandoned checkouts.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();

// The Verity draw engine: verity:process-due every minute (close at the closing time, draw once the
// committed drand round is out, run scheduled redraws, alert on a stuck draw), plus the hourly chain
// check, anchors (local hourly, emailed daily), verity:check and the daily invariants.
Verity::schedule(app(Scheduler::class));

// verity:check has no alert of its own: email VERITY_ALERT_EMAILS once a day if any row is FAIL
// (one email per new problem, a reminder every 24 hours while it lasts, an all-clear when fixed).
Schedule::command('wds:verity-check-alert')->dailyAt('07:00')->timezone('Europe/London');
