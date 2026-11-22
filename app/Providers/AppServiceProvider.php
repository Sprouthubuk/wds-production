<?php

namespace App\Providers;

use App\Mail\AccountPasswordLink;
use App\Support\ApiRateLimits;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ApiRateLimits::register();

        // Password links open the website (this app is API-only, so there's no password.reset route here),
        // and use the branded email. A customer who has never signed in (account made at checkout) gets
        // "Set your password"; everyone else "Reset your password".
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('raffle.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = rtrim((string) config('raffle.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset());

            return (new AccountPasswordLink($user, $url, newAccount: ! $user->tokens()->exists()))->to($user->email);
        });

        // Replies to any email go to the contact address, whatever the sending address (MAIL_FROM_ADDRESS) is.
        // An email that sets its own Reply-To (a contact form enquiry: the customer) replaces this.
        // (Config, not Mail::alwaysReplyTo, so every mailer gets it, however late it's resolved.)
        config(['mail.reply_to' => ['address' => (string) config('wds.contact_email'), 'name' => (string) config('mail.from.name')]]);
    }
}
