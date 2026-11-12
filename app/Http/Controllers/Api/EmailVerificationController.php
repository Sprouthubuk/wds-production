<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    /** The link in the "Confirm your email" email (signed, expires). Lands on the account page. */
    public function verify(Request $request, User $user, string $hash)
    {
        $account = rtrim((string) config('raffle.frontend_url'), '/').'/account';
        // An old link for an email the account no longer uses does nothing.
        if (! hash_equals(sha1($user->email), $hash)) {
            return redirect($account.'?email=link-invalid');
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect($account.'?email=verified');
    }

    /** "Send the link again" on the account page. */
    public function resend(Request $request)
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email is already confirmed.']);
        }
        Mail::to($user->email)->queue(new VerifyEmail($user));

        return response()->json(['message' => "We've sent a new link to {$user->email}. It works for ".VerifyEmail::HOURS.' hours.']);
    }
}
