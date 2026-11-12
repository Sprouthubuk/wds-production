<?php

namespace App\Console\Commands;

use App\Mail\WinnerContactOverdue;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Responsible Play Policy: every winner is contacted within 48 hours of the draw and has 14 days to claim.
 * Hourly: emails the admins once about each winner not marked "contacted" 48 hours after the draw.
 * The admin dashboard and Winners page show the same thing until they're marked contacted.
 */
class WinnerContactReminders extends Command
{
    protected $signature = 'wds:winner-contact-reminders';

    protected $description = 'Email admins about winners not contacted within 48 hours of the draw';

    public function handle(): int
    {
        $due = Winner::with('competition:id,title')->whereNull('contacted_at')->whereNull('forfeited_at')->whereNull('contact_reminder_sent_at')
            ->whereNotNull('drawn_at')->where('drawn_at', '<=', now()->subHours(Winner::CONTACT_WITHIN_HOURS))->get();
        $admins = User::where('role', 'admin')->whereNull('suspended_at')->pluck('email');
        if ($due->isEmpty() || $admins->isEmpty()) {
            $this->line($due->isEmpty() ? 'No winners overdue for contact.' : 'Winners overdue but no admin to email.');

            return self::SUCCESS;
        }

        $url = rtrim(config('raffle.frontend_url'), '/').'/admin/winners';
        $lines = $due->map(fn (Winner $w) => sprintf('- %s: %s (drawn %s; must claim by %s)',
            $w->competition?->title ?? $w->prize, $w->display_name,
            $w->drawn_at->timezone('Europe/London')->format('j M Y H:i'),
            $w->claimBy()->timezone('Europe/London')->format('j M Y H:i')))->implode("\n");
        $body = "These winners haven't been marked as contacted within 48 hours of their draw:\n\n{$lines}\n\n"
            ."Contact them today, then press \"Mark contacted\" on {$url}.";
        foreach ($admins as $email) {
            Mail::to($email)->send(new WinnerContactOverdue($body, $due->count()));
        }
        Winner::whereKey($due->modelKeys())->update(['contact_reminder_sent_at' => now()]);
        $this->line("Reminded {$admins->count()} admin(s) about {$due->count()} winner(s).");

        return self::SUCCESS;
    }
}
