<?php

namespace App\Jobs;

use App\Mail\LaunchAlert;
use App\Models\LaunchAlertSend;
use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/** Sends one logged launch email (LaunchAlerts::queueLaunch / sendTest) and records the outcome on its row. */
class SendLaunchAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> seconds between attempts (a Resend hiccup or a 429) */
    public array $backoff = [30, 120];

    public function __construct(public int $sendId) {}

    public function handle(): void
    {
        $send = LaunchAlertSend::with('competition')->find($this->sendId);
        // Sent already (a retried job), or nothing to send.
        if (! $send || ! in_array($send->status, ['queued', 'failed'], true) || ! $send->competition) {
            return;
        }
        $subscriber = $send->newsletter_subscriber_id ? NewsletterSubscriber::find($send->newsletter_subscriber_id) : null;
        if ($send->kind === 'launch' && (! $subscriber || $subscriber->unsubscribed_at)) {
            // Unsubscribed between queueing and sending.
            $send->update(['status' => 'skipped', 'error' => 'Unsubscribed before sending']);

            return;
        }
        $token = $subscriber?->unsubscribe_token
            ?? NewsletterSubscriber::where('email', $send->email)->whereNull('unsubscribed_at')->value('unsubscribe_token');

        try {
            Mail::to($send->email)->send(new LaunchAlert($send->competition, $token, $send->kind === 'test'));
        } catch (\Throwable $e) {
            $send->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e; // retried ($tries); the row says failed until one attempt works
        }
        $send->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }
}
