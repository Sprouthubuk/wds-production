<?php

namespace App\Console\Commands;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\User;
use App\Services\PreOpeningAmendments;
use Illuminate\Console\Command;

/**
 * Sets or lowers the maximum per person of a published competition that hasn't opened and has no entries.
 * A dry run unless --apply. See App\Services\PreOpeningAmendments for the rules and how it's recorded.
 */
class AmendCap extends Command
{
    protected $signature = 'competitions:amend-cap
        {slug : The competition}
        {max : The new maximum per person (it can only be set or lowered)}
        {--reason= : Why. Public and permanent: it goes on the draw\'s audit record}
        {--by= : The email of the admin making the change}
        {--apply : Write the change (without it, only show what would happen)}';

    protected $description = 'Set or lower a coming-soon competition\'s maximum per person, on the public audit record (dry run unless --apply)';

    public function handle(PreOpeningAmendments $amendments): int
    {
        $apply = (bool) $this->option('apply');
        $c = Competition::where('slug', (string) $this->argument('slug'))->first();
        if (! $c) {
            return $this->refuse(["No competition has the slug {$this->argument('slug')}."]);
        }
        if (! preg_match('/^\d{1,7}$/', (string) $this->argument('max'))) {
            return $this->refuse(['The maximum must be a whole number.']);
        }
        $max = (int) $this->argument('max');
        $by = User::where('email', mb_strtolower(trim((string) $this->option('by'))))->first();

        $problems = [...$amendments->problems($c, $max), ...$amendments->systemProblems()];
        $reason = '';
        try {
            $reason = PreOpeningAmendments::cleanReason((string) $this->option('reason'));
        } catch (EntryException $e) {
            $problems[] = $e->getMessage();
        }
        if (! $by || ! $by->isAdmin() || $by->suspended_at || $by->closed_at) {
            $problems[] = 'Say who is making the change: --by= an active admin account\'s email.';
        }

        $this->line($apply ? 'Applying:' : 'Dry run (nothing is written; add --apply to write):');
        $this->line("  Competition: {$c->title} (".($c->verity_ref ?? 'not published')."), {$c->status}, opens ".($c->opensLabel() ?? 'no time set').' (UK time)');
        foreach ($amendments->entries($c) as $what => $n) {
            $this->line("  {$what}: {$n}");
        }
        $this->line('  Max per person: '.($c->max_per_person ?? 'no limit')." → {$max}");
        $this->line("  Reason (public): {$reason}");
        if ($problems || ! $by) {
            return $this->refuse($problems);
        }
        $this->line("  By: {$by->email} (on the public audit record as admin:{$by->id})");
        $this->line('  Witness email recipients: '.count((array) config('verity.anchor_recipients')));

        if (! $apply) {
            $this->info('Allowed. Nothing written.');

            return self::SUCCESS;
        }

        try {
            $event = $amendments->apply($c, $max, $reason, $by);
        } catch (EntryException $e) {
            return $this->refuse([$e->getMessage()]);
        }
        $c->refresh();
        $this->info("Done: max per person is now {$c->max_per_person}. Audit record #{$event->seq}, hash {$event->hash}");

        // Saved and on the chain: now the people outside the server get it, as they get closes and anchors.
        $mailed = $amendments->emailWitnesses($event, $c);
        if ($mailed) {
            $this->info('Witness email sent.');
        } else {
            $this->error('The change is saved, but the witness email could not be sent. Forward this to the anchor recipients:');
            $this->line(PreOpeningAmendments::witnessText($event, $c));
        }
        $anchored = $this->call('verity:anchor') === self::SUCCESS;
        if (! $anchored) {
            $this->error('The change is saved, but the anchor failed. Run verity:anchor again.');
        }

        return $mailed && $anchored ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<string>  $problems */
    private function refuse(array $problems): int
    {
        foreach ($problems as $p) {
            $this->error("  Refused: {$p}");
        }
        $this->line('Nothing written.');

        return self::FAILURE;
    }
}
