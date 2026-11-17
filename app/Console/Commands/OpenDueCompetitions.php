<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Services\CompetitionLauncher;
use Illuminate\Console\Command;

/**
 * Every minute (routes/console.php): coming-soon competitions whose opening time has come go live, and their
 * launch email is queued. Needs the Forge scheduler (schedule:run every minute) and the queue worker.
 */
class OpenDueCompetitions extends Command
{
    protected $signature = 'competitions:open-due';

    protected $description = 'Open coming-soon competitions whose opening time has passed, and queue their launch emails';

    public function handle(CompetitionLauncher $launcher): int
    {
        $due = Competition::query()->whereIn('status', ['coming_soon', 'scheduled'])->whereNotNull('verity_ref')
            ->whereNotNull('opens_at')->where('opens_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderBy('opens_at')->get();

        foreach ($due as $c) {
            // One that can't open mustn't stop the rest; it's tried again next minute and the error is logged.
            try {
                $r = $launcher->open($c, null);
                $this->info("Opened {$c->title}; launch email queued for {$r['queued']}.");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$c->title}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
