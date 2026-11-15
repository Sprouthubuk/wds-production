<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\Winner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the winning ticket's holder when the result is published (Admin → Draws → Publish
 * result), the moment the winner is announced publicly. Next steps: ID check, then handover.
 */
class WinnerNotified extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Winner $winner) {}

    /** "£5,000" (or "£1,234.50"); null when the competition has no cash alternative. */
    public static function cashAlternative(?int $pence): ?string
    {
        if (! $pence) {
            return null;
        }

        return '£'.($pence % 100 === 0 ? number_format(intdiv($pence, 100)) : number_format($pence / 100, 2));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've won: {$this->winner->competition->title}");
    }

    public function content(): Content
    {
        $this->winner->loadMissing('competition', 'ticket');
        $front = rtrim(config('raffle.frontend_url'), '/');

        return new Content(
            view: 'mail.winner',
            text: 'mail.winner-text',
            with: [
                'winner' => $this->winner,
                'first' => strtok((string) $this->winner->ticket?->name, ' ') ?: 'there',
                'ticket' => $this->winner->ticket?->display($this->winner->competition->total_tickets),
                'accountUrl' => $front.'/account',
                'verifyUrl' => $this->winner->draw_id ? rtrim((string) config('raffle.draws.verify_page'), '/').'/'.$this->winner->draw_id : $front.'/live-draws',
                'cashAlternative' => self::cashAlternative($this->winner->competition->cash_alternative_pence),
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
