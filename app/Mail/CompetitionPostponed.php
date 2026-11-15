<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\Competition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to everyone with an entry when a competition is postponed (emergency only): the new times and the reason. */
class CompetitionPostponed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Competition $competition) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Postponed: {$this->competition->title}");
    }

    public function content(): Content
    {
        $c = $this->competition;
        $uk = fn ($t) => $t?->copy()->timezone('Europe/London')->format('l j F Y, g:ia');

        return new Content(
            view: 'mail.competition-postponed',
            text: 'mail.competition-postponed-text',
            with: [
                'competition' => $c,
                'from' => $uk($c->postponed_from),
                'closes' => $uk($c->closes_at),
                'draw' => $uk($c->draw_at),
                'reason' => $c->postponement_reason,
                'competitionUrl' => rtrim(config('raffle.frontend_url'), '/').'/competitions/'.$c->slug,
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
