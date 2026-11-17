<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/** "You're on the list": sent once when someone signs up for a launch alert (or signs up again). */
class LaunchAlertConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public NewsletterSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('wds.noreply_address'), config('mail.from.name')),
            subject: "You're on the list",
        );
    }

    public function headers(): Headers
    {
        $urls = LaunchAlert::unsubscribeUrls($this->subscriber->unsubscribe_token);

        return new Headers(text: ['List-Unsubscribe' => "<{$urls['oneClick']}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function content(): Content
    {
        $c = $this->subscriber->competition;
        $paragraphs = [
            $c
                ? "We'll email you once when {$c->title} opens for entries".($c->opens_at ? ' on '.$c->opensLabel().' (UK time)' : '').'.'
                : "We'll email you when WDS Competitions launch and each competition opens for entries.",
            'You signed up with this email address: "'.NewsletterSubscriber::CONSENT_TEXT.'"',
            'Free postal entry is available on every paid competition.',
        ];

        return new Content(
            view: 'mail.launch-alert-confirmation',
            text: 'mail.launch-alert-confirmation-text',
            with: [
                'headline' => "You're on the list.",
                'paragraphs' => $paragraphs,
                'unsubscribeUrl' => LaunchAlert::unsubscribeUrls($this->subscriber->unsubscribe_token)['page'],
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
