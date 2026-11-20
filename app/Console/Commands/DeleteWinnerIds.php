<?php

namespace App\Console\Commands;

use App\Models\IdVerification;
use App\Models\Winner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Privacy policy: "Winner ID documents are deleted within 30 days of the prize being handed over."
 * Hourly: for each customer whose prizes have all been handed over (Admin → Winners → handover "Delivered"),
 * the last one at least 30 days ago, deletes their uploaded ID files (document and selfie) from the private disk
 * and clears the paths. The verification record (status, reviewer, dates) is kept. A customer with a prize still
 * to hand over keeps their ID until that one is delivered too. Forfeited wins don't count either way.
 */
class DeleteWinnerIds extends Command
{
    public const AFTER_DAYS = 30;

    protected $signature = 'wds:delete-winner-ids';

    protected $description = 'Delete winner ID files 30 days after the prize was handed over';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::AFTER_DAYS);
        $deleted = 0;
        $userIds = IdVerification::whereNull('files_deleted_at')->distinct()->pluck('user_id');
        foreach ($userIds as $userId) {
            $wins = Winner::where('user_id', $userId)->whereNull('forfeited_at')->get(['handover', 'handed_over_at']);
            $allHandedOver = $wins->isNotEmpty() && $wins->every(fn (Winner $w) => $w->handover === 'delivered' && $w->handed_over_at);
            if (! $allHandedOver || $wins->max('handed_over_at')->gt($cutoff)) {
                continue;
            }
            IdVerification::where('user_id', $userId)->whereNull('files_deleted_at')->get()->each(function (IdVerification $v) use (&$deleted) {
                Storage::disk('local')->delete(array_filter([$v->document_path, $v->selfie_path]));
                $v->forceFill(['document_path' => '', 'selfie_path' => '', 'files_deleted_at' => now()])->save();
                $deleted++;
            });
        }
        $this->line("Deleted ID files for {$deleted} ".($deleted === 1 ? 'verification' : 'verifications').'.');

        return self::SUCCESS;
    }
}
