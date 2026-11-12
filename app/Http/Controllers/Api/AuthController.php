<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ResponsiblePlay;
use App\Services\TicketAllocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:80'],
            'lastName' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:30'],
            'dateOfBirth' => ['required', 'date'],
            'password' => ['required', Password::min(10)],
            'ageConfirmed' => ['accepted'],
            'marketingOptIn' => ['boolean'],
        ]);
        $email = TicketAllocator::normaliseEmail($data['email']);
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists. Try signing in.']);
        }
        if (! ResponsiblePlay::isAdult($data['dateOfBirth'])) {
            throw ValidationException::withMessages(['dateOfBirth' => 'You must be 18 or over to create an account.']);
        }

        $user = User::create([
            'name' => trim($data['firstName'].' '.$data['lastName']),
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'email' => $email,
            'phone' => $data['phone'],
            'date_of_birth' => $data['dateOfBirth'],
            'password' => $data['password'],
            'marketing_opt_in' => (bool) ($data['marketingOptIn'] ?? false),
            'role' => 'customer',
        ]);

        \Illuminate\Support\Facades\Mail::to($email)->queue(new \App\Mail\VerifyEmail($user));

        // Link any guest orders/tickets bought with this email before the account existed.
        \App\Models\Order::whereNull('user_id')->where('email', $email)->update(['user_id' => $user->id]);
        \App\Models\Ticket::whereNull('user_id')->where('email', $email)->update(['user_id' => $user->id]);

        return response()->json(['token' => $user->createToken('web')->plainTextToken], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', TicketAllocator::normaliseEmail($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those details don\'t match an account.']);
        }

        return response()->json([
            'token' => $user->createToken($request->boolean('admin') ? 'admin' : 'web')->plainTextToken,
            'isAdmin' => $user->isAdmin(),
            'isStaff' => $user->isStaff(),
        ]);
    }

    /** "Forgotten your password?": always the same answer, so it can't be used to find accounts. */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        PasswordBroker::sendResetLink(['email' => TicketAllocator::normaliseEmail($data['email'])]);

        return response()->json(['message' => "If there's an account for that email, we've sent a link to set a new password. It works for "
            .config('auth.passwords.users.expire', 60).' minutes.']);
    }

    /** The website's /reset-password page posts here with the token from the email. */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $status = PasswordBroker::reset(
            ['email' => TicketAllocator::normaliseEmail($data['email']), 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                // The link came to this inbox, so the email address is confirmed too.
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }
                $user->tokens()->delete(); // sign out everywhere
            },
        );
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This link has expired or has already been used. Ask for a new one.']);
        }

        return response()->json(['message' => 'Password saved. You can sign in now.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
