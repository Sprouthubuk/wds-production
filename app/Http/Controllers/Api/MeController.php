<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Resources\Money;
use App\Models\Competition;
use App\Models\IdVerification;
use App\Models\Ticket;
use App\Services\ResponsiblePlay;
use App\Services\TicketAllocator;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\User;
use App\Services\HarmMonitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request)
    {
        app(ResponsiblePlay::class)->applyDueIncreases($request->user());

        return new MeResource($request->user());
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'firstName' => ['sometimes', 'required', 'string', 'max:80'],
            'lastName' => ['sometimes', 'required', 'string', 'max:80'],
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'marketingOptIn' => ['boolean'],
        ]);
        $user->fill(array_filter([
            'first_name' => $data['firstName'] ?? null,
            'last_name' => $data['lastName'] ?? null,
            'email' => isset($data['email']) ? TicketAllocator::normaliseEmail($data['email']) : null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('phone', $data)) $user->phone = $data['phone'];
        if (array_key_exists('address', $data)) $user->address = $data['address'];
        if (array_key_exists('marketingOptIn', $data)) {
            if ($data['marketingOptIn'] && $user->isExcludedFromMarketing()) {
                abort(422, "Marketing can't be switched on while your account is self-excluded or closed.");
            }
            $user->marketing_opt_in = $data['marketingOptIn'];
        }
        $user->name = trim($user->first_name.' '.$user->last_name);
        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;   // the new address has to be confirmed
        }
        $user->save();
        if ($emailChanged) {
            \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\VerifyEmail($user));
        }

        return response()->json(['message' => 'Details saved.', 'data' => new MeResource($user)]);
    }

    /** Tickets grouped by competition, matching the account page's Entry shape. */
    public function entries(Request $request)
    {
        $user = $request->user();
        $tickets = Ticket::active()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))
            ->orderBy('number')->get()->groupBy('competition_id');
        $competitions = Competition::whereIn('id', $tickets->keys())->get()->keyBy('id');
        $wonTicketIds = \App\Models\Winner::whereIn('ticket_id', $tickets->flatten()->pluck('id'))->pluck('ticket_id')->all();

        $data = $tickets->map(function ($group, $cid) use ($competitions, $wonTicketIds) {
            $c = $competitions[$cid];

            return [
                'competition' => $c->title,
                'slug' => $c->slug,
                'image' => $c->images[0]['src'] ?? null,
                'drawAt' => $c->draw_at?->toIso8601String(),
                'totalTickets' => $c->total_tickets,
                'status' => $c->status,
                'online' => $group->where('channel', '!=', 'postal')->map(fn ($t) => $t->display($c->total_tickets))->values(),
                'postal' => $group->where('channel', 'postal')->map(fn ($t) => $t->display($c->total_tickets))->values(),
                'won' => $group->pluck('id')->intersect($wonTicketIds)->isNotEmpty(),
            ];
        })->sortByDesc('drawAt')->values();

        return response()->json(['data' => $data]);
    }

    public function orders(Request $request)
    {
        $orders = $request->user()->orders()->with('lines.competition')->latest()->limit(100)->get();

        return response()->json(['data' => $orders->map(fn ($o) => [
            'reference' => $o->reference,
            'createdAt' => $o->created_at->toIso8601String(),
            'competition' => $o->lines->map(fn ($l) => $l->competition->title)->unique()->implode(', '),
            'tickets' => $o->lines->sum('qty'),
            'total' => Money::pounds($o->total_pence),
            'status' => $o->status,
            'key' => $o->access_key,
        ])]);
    }

    public function limits(Request $request, ResponsiblePlay $rp)
    {
        $data = $request->validate([
            // £0 is a valid limit: no paid entries at all.
            'daily' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'weekly' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'monthly' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);
        $pence = [];
        foreach (['daily', 'weekly', 'monthly'] as $p) {
            if ($request->exists($p)) {
                $pence[$p] = Money::pence($data[$p] ?? null);
            }
        }
        $result = $rp->setLimits($request->user(), $pence);
        $delayed = in_array('Delayed.', $result, true);
        $hours = config('raffle.limit_increase_delay_hours');

        return response()->json(['message' => $delayed
            ? "Saved. Lower limits apply now; increases take effect in {$hours} hours."
            : 'Limits saved and applied.']);
    }

    public function takeBreak(Request $request, HarmMonitor $harm)
    {
        $data = $request->validate(['days' => ['required', Rule::in([1, 7, 30, '1', '7', '30'])]]);
        $user = $request->user();
        $until = now()->addDays((int) $data['days']);
        if (! $user->break_until || $user->break_until->lt($until)) {
            $user->update(['break_until' => $until]);
        }
        $harm->record($user, $user->email, 'break', ['days' => (int) $data['days']]);

        return response()->json(['message' => 'Break started. You can enter again from '.$user->break_until->format('j M Y, H:i').'.']);
    }

    /** 6 months or more. It can be lengthened but never shortened; it stops entries and every kind of marketing. */
    public function selfExclude(Request $request, HarmMonitor $harm)
    {
        $data = $request->validate(['months' => ['required', Rule::in([6, 12, 60, '6', '12', '60'])]]);
        $user = $request->user();
        $until = now()->addMonths((int) $data['months']);
        if ($user->self_excluded_until?->gt($until)) {
            $until = $user->self_excluded_until; // already longer: keep it
        }
        $user->update(['self_excluded_until' => $until, 'marketing_opt_in' => false]);
        $this->stopMarketing($user);
        $current = $request->user()->currentAccessToken();
        $user->tokens()->when($current instanceof \Laravel\Sanctum\PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->id))->delete();
        $harm->record($user, $user->email, 'self_exclusion', ['until' => $until->toDateString()]);

        return response()->json(['message' => 'You are self-excluded until '.$until->format('j M Y').'. It can\'t be shortened. We\'ve stopped all marketing emails.']);
    }

    /** Permanent closure, self-service: no entries, no marketing, signed out everywhere. Can't be reopened online. */
    public function close(Request $request, HarmMonitor $harm)
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm you want to close your account permanently.']);
        $user = $request->user();
        $user->update(['closed_at' => now(), 'marketing_opt_in' => false]);
        $this->stopMarketing($user);
        $harm->record($user, $user->email, 'closure');
        $user->tokens()->delete();

        return response()->json(['message' => 'Your account is closed. You won\'t be able to sign in or enter, and we\'ve stopped all marketing emails. Tickets you already hold stay in their draws.']);
    }

    private function stopMarketing(User $user): void
    {
        NewsletterSubscriber::where('email', $user->email)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        Order::where('email', $user->email)->where('marketing_opt_in', true)->update(['marketing_opt_in' => false]);
    }

    public function verification(Request $request)
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,heic,pdf', 'max:10240'],
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png,heic', 'max:10240'],
        ]);
        $user = $request->user();
        IdVerification::create([
            'user_id' => $user->id,
            'document_path' => $request->file('document')->store("verifications/{$user->id}", 'local'),
            'selfie_path' => $request->file('selfie')->store("verifications/{$user->id}", 'local'),
            'status' => 'to_review',
        ]);

        return response()->json(['message' => 'Thanks — we\'ll review your ID and be in touch.']);
    }
}
