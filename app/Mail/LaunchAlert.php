<?php

namespace App\Mail;

use App\Http\Resources\Money;
use App\Http\Resources\SettingsPayload;
use App\Models\Competition;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * "It's live": the one launch email to a competition's list plus the site-wide list (SendLaunchAlert). Sent
 * from the job (not queued again): the job logs the outcome. One-click unsubscribe (RFC 8058) in the headers
 * and a link in the body.
 */
class LaunchAlert extends Mailable
{
    public function __construct(public Competition $competition, public ?string $unsubscribeToken, public bool $test = false) {}

    public static function unsubscribeUrls(?string $token): array
    {
        return [
            // The page (a button, so link scanners can't unsubscribe anyone) and the one-click POST endpoint.
            'page' => rtrim(config('raffle.frontend_url'), '/').'/unsubscribe/'.($token ?? 'test'),
            'oneClick' => rtrim(config('app.url'), '/').'/api/launch-alerts/unsubscribe/'.($token ?? 'test'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('wds.noreply_address'), config('mail.from.name')),
            subject: ($this->test ? '[TEST] ' : '')."It's live: {$this->competition->title}",
        );
    }

    public function headers(): Headers
    {
        $urls = self::unsubscribeUrls($this->unsubscribeToken);

        return new Headers(text: [
            'List-Unsubscribe' => "<{$urls['oneClick']}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $c = $this->competition;
        $uk = fn ($t) => $t?->copy()->timezone('Europe/London')->format('l j F Y, g:ia');

        return new Content(
            view: 'mail.launch-alert',
            text: 'mail.launch-alert-text',
            with: [
                'competition' => $c,
                'test' => $this->test,
                'price' => $c->isFree() ? 'Free to enter' : '£'.number_format(Money::pounds($c->ticket_price_pence), 2).' a ticket',
                'odds' => '1 in '.number_format((int) $c->total_tickets),
                'closes' => $uk($c->closes_at),
                'draw' => $uk($c->draw_at),
                'postal' => $c->hasPostalRoute() ? $c->postalDeadlineSentence() : null,
                'competitionUrl' => rtrim(config('raffle.frontend_url'), '/').'/competitions/'.$c->slug,
                'unsubscribeUrl' => self::unsubscribeUrls($this->unsubscribeToken)['page'],
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
