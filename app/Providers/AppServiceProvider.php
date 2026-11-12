<?php

namespace App\Providers;

use App\Mail\AccountPasswordLink;
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
    }
}
