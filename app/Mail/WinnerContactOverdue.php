<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Admin reminder: winners not contacted within 48 hours of their draw (wds:winner-contact-reminders). */
class WinnerContactOverdue extends Mailable
{
    use Queueable;

    public function __construct(public string $body, public int $count) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Winner contact overdue ({$this->count})");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6">'.nl2br(e($this->body)).'</div>');
    }
}
