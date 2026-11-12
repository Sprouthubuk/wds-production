<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\PostalEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when staff log a free postal entry: the ticket number, or why it couldn't be accepted. */
class PostalEntryLogged extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PostalEntry $entry) {}

    public function envelope(): Envelope
    {
        $title = $this->entry->competition->title;

        return new Envelope(subject: $this->entry->status === 'allocated'
            ? "Your free postal entry is in: {$title}"
            : "We couldn't accept your postal entry: {$title}");
    }

    public function content(): Content
    {
        $this->entry->loadMissing('competition', 'ticket');
        $competition = $this->entry->competition;

        return new Content(
            view: 'mail.postal-entry-logged',
            text: 'mail.postal-entry-logged-text',
            with: [
                'entry' => $this->entry,
                'competition' => $competition,
                'ticket' => $this->entry->ticket?->display($competition->total_tickets),
                'deadline' => $competition->postalDeadlineSentence(),
                'competitionUrl' => rtrim(config('raffle.frontend_url'), '/').'/competitions/'.$competition->slug,
                'postalUrl' => rtrim(config('raffle.frontend_url'), '/').'/free-postal-entry',
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
