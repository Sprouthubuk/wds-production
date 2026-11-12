<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** `php artisan wds:mail-test {email}`: proves the mailer, the from address and the branding work. */
class MailTest extends Mailable
{
    public function __construct(public string $mailerName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WDS Competitions: test email');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test', text: 'mail.test-text', with: [
            'mailer' => $this->mailerName,
            'sentAt' => now()->timezone('Europe/London')->format('j F Y, H:i:s'),
            'settings' => SettingsPayload::get(),
        ]);
    }
}
