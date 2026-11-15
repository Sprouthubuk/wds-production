<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Support\ClientDrafts as Drafts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Puts the client's confirmed competition details into the database, safely and repeatably:
 *  - creates each new draft whose slug doesn't exist yet;
 *  - applies the confirmed details (dates, prices, caps) to each one only while it is an unpublished draft,
 *    leaving everything else edited in Admin (title, copy, photos) as it is.
 * Never publishes anything. Shows what it would change unless --apply is given.
 */
class ClientDrafts extends Command
{
    protected $signature = 'wds:client-drafts {--apply : Write the changes (without it, only show them)}';

    protected $description = 'Create the client\'s confirmed draft competitions and apply the main event\'s details (dry run unless --apply)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? 'Applying:' : 'Dry run (nothing is written; add --apply to write):');

        $new = Drafts::newDrafts();
        foreach (Drafts::confirmed() as $slug => $confirmed) {
            $c = Competition::where('slug', $slug)->first();
            if (! $c) {
                if (! isset($new[$slug])) {
                    $this->warn("  {$slug}: not found, skipped.");

                    continue;
                }
                $this->line("  {$new[$slug]['title']}: create as a draft ({$slug}).");
                if ($apply) {
                    Competition::create(['slug' => $slug] + $confirmed + $new[$slug]);
                }

                continue;
            }
            if ($c->isPublished() || $c->status !== 'draft') {
                $this->warn("  {$c->title}: already published or live, not changed (its terms are locked).");

                continue;
            }
            $changes = $this->diff($c, $confirmed);
            if (! $changes) {
                $this->line("  {$c->title}: already up to date.");

                continue;
            }
            foreach ($changes as $col => [$from, $to]) {
                $this->line("  {$c->title}: {$col} {$from} → {$to}");
            }
            if ($apply) {
                $c->update($confirmed);
            }
        }

        $this->info($apply ? 'Done. Nothing was published.' : 'Nothing written.');

        return self::SUCCESS;
    }

    /** @return array<string, array{0:string, 1:string}> column => [current, confirmed], only where they differ */
    private function diff(Competition $c, array $wanted): array
    {
        $out = [];
        foreach ($wanted as $col => $value) {
            $current = $c->getAttribute($col);
            $a = $this->show($current);
            $b = $this->show(in_array($col, ['closes_at', 'postal_closes_at', 'draw_at'], true) && $value !== null ? Carbon::parse($value, 'UTC') : $value);
            if ($a !== $b) {
                $out[$col] = [$a, $b];
            }
        }

        return $out;
    }

    private function show(mixed $v): string
    {
        return match (true) {
            $v === null => '(empty)',
            $v instanceof \DateTimeInterface => Carbon::instance($v)->utc()->format('Y-m-d H:i').' UTC',
            is_array($v) => json_encode($v),
            default => (string) $v,
        };
    }
}
