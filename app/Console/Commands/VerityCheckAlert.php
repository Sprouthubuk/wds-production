<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Sprouthub\Verity\Services\Alerts;
use Sprouthub\Verity\Support\SecurityChecks;

/**
 * Runs verity:check and emails VERITY_ALERT_EMAILS when any row is FAIL. Verity runs verity:check
 * hourly (Verity::schedule) and records it on the audit chain, but doesn't alert on it itself; its
 * own alerts cover the chain, anchors, witnesses, stuck draws, redraws and invariants. This uses
 * Verity's alert mechanism (Alerts::track), so it's one email when a FAIL appears, a reminder every
 * 24 hours while it lasts, and an all-clear when it's fixed. Warnings don't alert.
 *
 * Always exits 0: it reports, it never blocks anything.
 */
class VerityCheckAlert extends Command
{
    protected $signature = 'wds:verity-check-alert';

    protected $description = 'Run verity:check and email the Verity alert recipients on any FAIL row';

    public function handle(): int
    {
        // The same rows verity:check prints (the hourly verity:check records them on the audit chain).
        try {
            $rows = array_merge(\App\Support\VerityKeyChecks::run(), SecurityChecks::run());
        } catch (\Throwable $e) {
            report($e);
            Alerts::track('wds-verity-check', ['verity:check' => mb_substr($e->getMessage(), 0, 300)], 'verity:check could not run', 'All clear: verity:check runs again');
            $this->error('verity:check could not run: '.$e->getMessage());

            return self::SUCCESS;
        }

        $failing = [];
        foreach ($rows as $row) {
            if (! $row['ok'] && $row['level'] === 'FAIL') {
                $failing[$row['label']] = (string) ($row['note'] ?? '');
            }
        }
        $kind = Alerts::track('wds-verity-check', $failing, 'verity:check has FAIL rows', 'All clear: verity:check passes again');

        $this->line($failing
            ? count($failing).' FAIL row(s): '.implode('; ', array_keys($failing)).($kind ? " (alert: {$kind})" : ' (already alerted)')
            : 'verity:check: no FAIL rows.'.($kind === 'all_clear' ? ' All-clear sent.' : ''));

        return self::SUCCESS;
    }
}
