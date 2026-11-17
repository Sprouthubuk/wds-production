<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Jobs\SendLaunchAlert;
use App\Mail\LaunchAlertConfirmation;
use App\Models\Competition;
use App\Models\LaunchAlertSend;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Launch alerts: "Notify me when it's live" (one competition) and "Get first access" (site-wide).
 *
 * When a competition goes live, everyone on its list plus the site-wide list gets ONE email: deduplicated by
 * normalised email, every send logged in launch_alert_sends (its unique dedupe_key makes a second launch email
 * for the same competition impossible, even if going live were run twice), and spaced out for Resend's rate
 * limit. Self-excluded, suspended and closed accounts are never emailed. Every email has a one-click unsubscribe.
 */
class LaunchAlerts
{
    /** Resend's default limit is 2 requests a second per team: stay at or under it (LAUNCH_ALERTS_PER_SECOND). */
    public static function perSecond(): int
    {
        return max(1, (int) config('wds.launch_alerts_per_second', 2));
    }

    /**
     * Sign up (or sign up again after unsubscribing). Returns the row and whether it's new, which is when
     * the confirmation email goes.
     *
     * @return array{subscriber: NewsletterSubscriber, created: bool}
     */
    public function subscribe(string $email, ?Competition $competition, string $source, ?User $user = null): array
    {
        $email = TicketAllocator::normaliseEmail($email);
        if ($competition && ! in_array($competition->status, ['draft', 'coming_soon'], true)) {
            throw new EntryException($competition->status === 'live'
                ? "{$competition->title} is open now: you can enter straight away."
                : "{$competition->title} has closed.");
        }
        $key = NewsletterSubscriber::listKey($competition?->id, $email);

        try {
            [$row, $created] = $this->upsert($email, $competition, $source, $user, $key);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The same sign-up sent twice at once: the other request created it.
            [$row, $created] = [NewsletterSubscriber::where('list_key', $key)->firstOrFail(), false];
        }

        if ($created) {
            Mail::to($email)->queue(new LaunchAlertConfirmation($row->fresh(['competition'])));
        }

        return ['subscriber' => $row, 'created' => $created];
    }

    /** @return array{0: NewsletterSubscriber, 1: bool} */
    private function upsert(string $email, ?Competition $competition, string $source, ?User $user, string $key): array
    {
        return DB::transaction(function () use ($email, $competition, $source, $user, $key) {
            $row = NewsletterSubscriber::where('list_key', $key)->lockForUpdate()->first();
            if ($row && ! $row->unsubscribed_at) {
                return [$row, false];
            }
            $fresh = [
                'email' => $email, 'competition_id' => $competition?->id, 'user_id' => $user->id ?? User::where('email', $email)->value('id'),
                'consent_at' => now(), 'consent_text' => NewsletterSubscriber::CONSENT_TEXT, 'source' => $source,
                'unsubscribe_token' => $row->unsubscribe_token ?? NewsletterSubscriber::newToken(), 'list_key' => $key, 'unsubscribed_at' => null,
            ];
            if ($row) {
                $row->update($fresh);

                return [$row, true];
            }

            return [NewsletterSubscriber::create($fresh), true];
        });
    }

    /** One-click unsubscribe: stops every launch alert list for that email. Returns how many lists it left. */
    public function unsubscribe(string $token): ?int
    {
        $row = NewsletterSubscriber::where('unsubscribe_token', $token)->first();
        if (! $row) {
            return null;
        }

        return NewsletterSubscriber::where('email', $row->email)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
    }

    /**
     * The people a competition's launch email goes to: its own list plus the site-wide list, one row per
     * normalised email (their own competition sign-up wins over the site-wide one), minus excluded accounts.
     *
     * @return Collection<string, NewsletterSubscriber> keyed by email
     */
    public function audience(Competition $competition): Collection
    {
        $rows = NewsletterSubscriber::subscribed()
            ->where(fn ($q) => $q->where('competition_id', $competition->id)->orWhereNull('competition_id'))
            ->orderByRaw('case when competition_id is null then 1 else 0 end')->orderBy('id')
            ->get()
            ->toBase() // a plain collection: Eloquent's except() works on model keys, not these email keys
            ->unique(fn (NewsletterSubscriber $s) => TicketAllocator::normaliseEmail($s->email))
            ->keyBy(fn (NewsletterSubscriber $s) => TicketAllocator::normaliseEmail($s->email));

        return $rows->except($this->excludedEmails($rows->keys()));
    }

    /** Never email anyone self-excluded, suspended or closed (the same rule as the marketing export). */
    private function excludedEmails(Collection $emails): array
    {
        $out = [];
        foreach ($emails->chunk(500) as $chunk) {
            array_push($out, ...User::whereIn('email', $chunk->all())
                ->where(fn ($q) => $q->where('self_excluded_until', '>', now())->orWhereNotNull('suspended_at')->orWhereNotNull('closed_at'))
                ->pluck('email')->map(fn ($e) => TicketAllocator::normaliseEmail($e))->all());
        }

        return $out;
    }

    /**
     * Queue the launch email for a competition that has just gone live. Safe to run again: anyone already
     * logged for this competition is skipped. Returns how many were queued this time.
     */
    public function queueLaunch(Competition $competition): int
    {
        if ($competition->status !== 'live') {
            throw new EntryException("{$competition->title} isn't live, so no launch email goes out.");
        }
        $queued = 0;
        $perSecond = self::perSecond();
        foreach ($this->audience($competition) as $email => $subscriber) {
            // The unique dedupe_key is the guarantee: a second insert for the same competition and email is ignored.
            $inserted = LaunchAlertSend::insertOrIgnore([
                'competition_id' => $competition->id, 'email' => $email, 'newsletter_subscriber_id' => $subscriber->id,
                'kind' => 'launch', 'dedupe_key' => LaunchAlertSend::dedupeKey($competition->id, $email), 'status' => 'queued',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $inserted) {
                continue;
            }
            $send = LaunchAlertSend::where('dedupe_key', LaunchAlertSend::dedupeKey($competition->id, $email))->first();
            // Spaced out: $perSecond emails a second, so a big list never trips Resend's rate limit.
            SendLaunchAlert::dispatch($send->id)->delay(now()->addSeconds(intdiv($queued, $perSecond)));
            $queued++;
        }
        Log::info("Launch alerts queued for {$competition->title}", ['competition' => $competition->id, 'queued' => $queued]);

        return $queued;
    }

    /** Admin's "Send test launch email": the real email for this competition, to one address, logged as a test. */
    public function sendTest(Competition $competition, string $email, User $by): LaunchAlertSend
    {
        $send = LaunchAlertSend::create([
            'competition_id' => $competition->id, 'email' => TicketAllocator::normaliseEmail($email),
            'kind' => 'test', 'status' => 'queued', 'sent_by' => $by->id,
        ]);
        SendLaunchAlert::dispatch($send->id);

        return $send;
    }
}
