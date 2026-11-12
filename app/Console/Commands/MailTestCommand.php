<?php

namespace App\Console\Commands;

use App\Mail\MailTest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** Sends one branded test email straight away (not queued) and says which mailer and sender it used. */
class MailTestCommand extends Command
{
    protected $signature = 'wds:mail-test {email : Where to send the test email}';

    protected $description = 'Send a test email through the configured mailer (e.g. Resend) to check delivery';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("That isn't a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        if ($mailer === 'resend' && ! config('services.resend.key')) {
            $this->error('MAIL_MAILER is resend but RESEND_API_KEY is empty. Set it in Forge (Site → Environment), then run php artisan config:cache.');

            return self::FAILURE;
        }

        $this->line("Mailer: {$mailer}   From: ".config('mail.from.name').' <'.config('mail.from.address').'>');
        try {
            Mail::to($email)->send(new MailTest($mailer));
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Sent a test email to {$email}. Check it arrived (and not in spam).");
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("The {$mailer} mailer doesn't deliver anything: set MAIL_MAILER=resend for real email.");
        }

        return self::SUCCESS;
    }
}
