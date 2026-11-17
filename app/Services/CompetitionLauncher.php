<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionEvent;
use App\Models\User;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Support\Facades\Log;

/**
 * Coming soon → live, one way for both routes: competitions:open-due at opens_at (every minute), and Admin's
 * "Open now" (with a confirmation). Verity's draft goes live, Admin's history records who and when, and the
 * launch email is queued for the competition's list plus the site-wide list.
 */
class CompetitionLauncher
{
    public function __construct(private VerityDrawEngine $verity, private LaunchAlerts $alerts, private PostalEntryService $postal) {}

    /** @return array{competition: Competition, queued: int} */
    public function open(Competition $competition, ?User $by): array
    {
        $early = $competition->opens_at?->isFuture() ?? false;
        $was = $competition->opens_at?->copy();
        $competition = $this->verity->goLive($competition);

        CompetitionEvent::record($competition, $by, $by ? 'opened_now' : 'opened',
            $by
                ? "Opened for entries now by {$by->email}".($early && $was ? ' (it was due to open '.Competition::ukDeadline($was).', UK time)' : '').'.'
                : 'Opened for entries at its opening time.',
            ['opens_at' => $competition->opens_at?->toIso8601String(), 'was_due' => $was?->toIso8601String()]);

        // Envelopes logged while it was coming soon get their ticket numbers now, as online entry opens.
        $this->addHeldPostalEntries($competition);

        // The launch email goes even if something about it fails later: opening is what matters here.
        try {
            $queued = $this->alerts->queueLaunch($competition);
        } catch (\Throwable $e) {
            Log::error('Launch alerts could not be queued', ['competition' => $competition->id, 'error' => $e->getMessage()]);
            $queued = 0;
        }
        CompetitionEvent::record($competition, null, 'launch_alerts', "Launch email queued for {$queued} ".\Illuminate\Support\Str::plural('person', $queued).'.', ['queued' => $queued]);

        return ['competition' => $competition, 'queued' => $queued];
    }

    /** Held postal entries into the draw, noted on the competition's history. Also competitions:open-due's retry. */
    public function addHeldPostalEntries(Competition $competition): void
    {
        try {
            $p = $this->postal->allocateHeld($competition);
        } catch (\Throwable $e) {
            Log::error('Held postal entries could not be added', ['competition' => $competition->id, 'error' => $e->getMessage()]);

            return;
        }
        if ($p['allocated'] + $p['rejected'] + $p['failed'] === 0) {
            return;
        }
        CompetitionEvent::record($competition, null, 'postal_held_added',
            "{$p['allocated']} postal ".\Illuminate\Support\Str::plural('entry', $p['allocated']).' received before opening added to the draw'
            .($p['rejected'] ? ", {$p['rejected']} rejected" : '')
            .($p['failed'] ? ", {$p['failed']} still held (retried every minute)" : '').'.', $p);
    }
}
