<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Http\Resources\Money;
use App\Models\CreditTransaction;
use App\Models\IdVerification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $q = User::query()->withCount('tickets')->withSum(['orders as spent_pence' => fn ($o) => $o->where('status', 'paid')], 'total_pence')->latest();
        if ($s = $request->query('q')) {
            $q->where(fn ($w) => $w->where('email', 'like', "%$s%")->orWhere('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"));
        }

        return $q->paginate(50)->through(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->role,
            'tickets' => $u->tickets_count, 'spent' => Money::pounds((int) $u->spent_pence),
            'credit' => Money::pounds($u->creditBalancePence()),
            'flags' => array_values(array_filter([
                $u->self_excluded_until?->isFuture() ? 'self-excluded' : null,
                $u->break_until?->isFuture() ? 'on break' : null,
                $u->suspended_at ? 'suspended' : null,
            ])),
            'joinedAt' => $u->created_at->toIso8601String(),
        ]);
    }

    public function show(User $user)
    {
        return (new MeResource($user))->additional(['credit' => $user->creditTransactions()->latest()->get()]);
    }

    public function addCredit(Request $request, User $user)
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'not_in:0', 'between:-1000,1000'], 'reason' => ['required', 'string', 'max:160']]);
        CreditTransaction::create(['user_id' => $user->id, 'amount_pence' => Money::pence($d['amount']), 'reason' => $d['reason'], 'added_by' => $request->user()->id]);

        return response()->json(['message' => 'Credit updated.', 'balance' => Money::pounds($user->creditBalancePence())]);
    }

    public function setSuspended(Request $request, User $user)
    {
        $request->validate(['suspended' => ['required', 'boolean']]);
        $user->update(['suspended_at' => $request->boolean('suspended') ? now() : null]);
        if ($request->boolean('suspended')) $user->tokens()->delete();

        return response()->json(['message' => $request->boolean('suspended') ? 'Account suspended.' : 'Account reinstated.']);
    }

    public function setRole(Request $request, User $user)
    {
        $d = $request->validate(['role' => ['required', Rule::in(['customer', 'staff', 'admin'])]]);
        $user->update(['role' => $d['role']]);

        return response()->json(['message' => 'Role updated.']);
    }

    public function verifications()
    {
        return IdVerification::with('user:id,name,email')->where('status', 'to_review')->oldest()->get();
    }

    public function verificationFile(IdVerification $verification, string $which)
    {
        abort_unless(in_array($which, ['document', 'selfie'], true), 404);

        return Storage::disk('local')->response($verification->{$which.'_path'});
    }

    public function reviewVerification(Request $request, IdVerification $verification)
    {
        $d = $request->validate(['status' => ['required', Rule::in(['approved', 'denied'])], 'notes' => ['nullable', 'string', 'max:255']]);
        $verification->update($d + ['reviewed_by' => $request->user()->id]);
        \App\Models\Winner::where('user_id', $verification->user_id)->where('verification', '!=', 'approved')
            ->update(['verification' => $d['status']]);

        return response()->json(['message' => 'Verification '.$d['status'].'.']);
    }
}
