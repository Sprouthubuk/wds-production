<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your email": sent when an account is made, when its email changes, and on request from the
 * account page. Free competitions need a verified email (one entry per verified account). Setting a
 * password from an emailed link also verifies it.
 */
class VerifyEmail extends Mailable
{
    use Queueable;

    public const HOURS = 48;

    public function __construct(public User $user) {}

    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('auth.verify-email', now()->addHours(self::HOURS), [
            'user' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email: your WDS Competitions account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.verify-email',
            text: 'mail.verify-email-text',
            with: [
                'name' => $this->user->first_name ?: (strtok((string) $this->user->name, ' ') ?: 'there'),
                'url' => self::url($this->user),
                'hours' => self::HOURS,
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
