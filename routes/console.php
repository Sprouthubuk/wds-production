<?php

use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;
use Sprouthub\Verity\Facades\Verity;

// Coming soon → live at each competition's opening time (and queue its launch email). Needs the Forge
// scheduler AND the queue worker on production (docs/production-readiness.md).
Schedule::command('competitions:open-due')->everyMinute()->withoutOverlapping();
// Release capacity held by abandoned checkouts.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
// Cashflows: re-query orders still waiting on a payment, in case a notification was missed (CashflowsReconciler).
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();

// The Verity draw engine: verity:process-due every minute (close at the closing time, draw once the
// committed drand round is out, run scheduled redraws, alert on a stuck draw), plus the hourly chain
// check, anchors (local hourly, emailed daily), verity:check and the daily invariants.
Verity::schedule(app(Scheduler::class));

// The hourly verity:check's output normally goes to /dev/null, so a failing run on a server only shows as
// "failed with exit code …" in the scheduler log. Keep what it printed (the failing row, or the exception), the
// same way the deploy keeps storage/logs/verity-check-deploy.log. Output only: the command itself is unchanged.
foreach (app(Scheduler::class)->events() as $event) {
    if (preg_match('/\bverity:check\b/', (string) $event->command)) {
        $event->appendOutputTo(storage_path('logs/verity-check-schedule.log'));
    }
}

// verity:check has no alert of its own: email VERITY_ALERT_EMAILS once a day if any row is FAIL
// (one email per new problem, a reminder every 24 hours while it lasts, an all-clear when fixed).
Schedule::command('wds:verity-check-alert')->dailyAt('07:00')->timezone('Europe/London');
// Responsible Play Policy: remind admins about winners not contacted within 48 hours of the draw.
Schedule::command('wds:winner-contact-reminders')->hourly()->withoutOverlapping();
