<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We've got your message": sent to whoever used the contact form. It deliberately repeats nothing they typed, so
 * the form can't be used to send someone else an email with chosen content.
 */
class ContactAcknowledgement extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const LINES = [
        "Thanks for getting in touch. We've got your message and will reply to this email address, usually within one working day.",
        "If it's about an order, keep your order number handy.",
    ];

    public function envelope(): Envelope
    {
        return new Envelope(subject: "We've got your message");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notice',
            text: 'mail.notice-text',
            with: ['headline' => "We've got your message.", 'paragraphs' => self::LINES, 'settings' => SettingsPayload::get()],
        );
    }
}
