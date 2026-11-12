<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Support\ClientDrafts as Drafts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Puts the client's confirmed competition details into the database, safely and repeatably:
 *  - creates each new draft whose slug doesn't exist yet (an existing one is left exactly as it is);
 *  - applies the main event's confirmed details only while it is still an unpublished draft.
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

        $main = Competition::where('slug', Drafts::MAIN_EVENT)->first();
        if (! $main) {
            $this->warn('  '.Drafts::MAIN_EVENT.': not found, skipped.');
        } elseif ($main->isPublished() || $main->status !== 'draft') {
            $this->warn("  {$main->title}: already published or live, not changed (its terms are locked).");
        } else {
            $changes = $this->diff($main, Drafts::mainEvent());
            if (! $changes) {
                $this->line("  {$main->title}: already up to date.");
            } else {
                foreach ($changes as $col => [$from, $to]) {
                    $this->line("  {$main->title}: {$col} {$from} → {$to}");
                }
                if ($apply) {
                    $main->update(Drafts::mainEvent());
                }
            }
        }

        foreach (Drafts::newDrafts() as $slug => $attributes) {
            if (Competition::where('slug', $slug)->exists()) {
                $this->line("  {$attributes['title']}: exists, left as it is.");

                continue;
            }
            $this->line("  {$attributes['title']}: create as a draft ({$slug}).");
            if ($apply) {
                Competition::create(['slug' => $slug] + $attributes);
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
