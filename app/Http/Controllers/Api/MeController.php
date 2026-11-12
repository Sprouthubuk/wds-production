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
        if (array_key_exists('marketingOptIn', $data)) $user->marketing_opt_in = $data['marketingOptIn'];
        $user->name = trim($user->first_name.' '.$user->last_name);
        $user->save();

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
            'daily' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'weekly' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'monthly' => ['nullable', 'numeric', 'min:1', 'max:10000'],
        ]);
        $pence = [];
        foreach (['daily', 'weekly', 'monthly'] as $p) {
            if ($request->exists($p)) {
                $pence[$p] = Money::pence($data[$p] ?? null);
            }
        }
        $result = $rp->setLimits($request->user(), $pence);
        $delayed = in_array('Takes effect in 24 hours.', $result, true);

        return response()->json(['message' => $delayed
            ? 'Saved. Lower limits apply now; increases take effect in 24 hours.'
            : 'Limits saved and applied.']);
    }

    public function takeBreak(Request $request)
    {
        $data = $request->validate(['days' => ['required', Rule::in([1, 7, 30, '1', '7', '30'])]]);
        $user = $request->user();
        $until = now()->addDays((int) $data['days']);
        if (! $user->break_until || $user->break_until->lt($until)) {
            $user->update(['break_until' => $until]);
        }

        return response()->json(['message' => 'Break started. You can enter again from '.$user->break_until->format('j M Y, H:i').'.']);
    }

    public function selfExclude(Request $request)
    {
        $data = $request->validate(['months' => ['required', Rule::in([6, 12, 60, '6', '12', '60'])]]);
        $user = $request->user();
        $until = now()->addMonths((int) $data['months']);
        $user->update(['self_excluded_until' => $until, 'marketing_opt_in' => false]);
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return response()->json(['message' => 'You are self-excluded until '.$until->format('j M Y').'. We\'ve stopped all marketing emails.']);
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
