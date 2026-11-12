<?php

namespace App\Mail;

use App\Http\Resources\SettingsPayload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The account password link. Laravel's password broker sends it through ResetPassword::toMailUsing
 * (AppServiceProvider), for "Forgotten your password?" and for the account made at checkout
 * ("set your password"). The link opens the website's /reset-password page.
 */
class AccountPasswordLink extends Mailable
{
    use Queueable;

    public function __construct(public User $user, public string $url, public bool $newAccount) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->newAccount
            ? 'Set your password: your WDS Competitions account'
            : 'Reset your WDS Competitions password');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-password',
            text: 'mail.account-password-text',
            with: [
                'name' => $this->user->first_name ?: (strtok((string) $this->user->name, ' ') ?: 'there'),
                'url' => $this->url,
                'newAccount' => $this->newAccount,
                'minutes' => (int) config('auth.passwords.users.expire', 60),
                'settings' => SettingsPayload::get(),
            ],
        );
    }
}
