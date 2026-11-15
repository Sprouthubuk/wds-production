<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Models\HarmFlag;
use App\Services\ResponsiblePlay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Responsible-play review queue (HarmMonitor raises the flags). Staff add notes and act:
 * contact (record that we reached out), lower limit (applies immediately), block (suspend the account),
 * or close the flag once reviewed.
 */
class HarmFlagController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');
        $flags = HarmFlag::with('user:id,name,email,limit_monthly_pence,suspended_at,self_excluded_until,closed_at')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status === 'actioned' ? '!=' : '=', 'open'))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $flags->map(fn (HarmFlag $f) => [
            'id' => $f->id,
            'email' => $f->email,
            'name' => $f->user?->name,
            'userId' => $f->user_id,
            'reason' => HarmFlag::REASONS[$f->reason] ?? $f->reason,
            'summary' => $f->summary,
            'status' => $f->status,
            'notes' => $f->notes,
            'monthlyLimit' => Money::pounds($f->user?->limit_monthly_pence),
            'accountState' => $f->user?->closed_at ? 'closed' : ($f->user?->suspended_at ? 'suspended' : ($f->user?->self_excluded_until?->isFuture() ? 'self-excluded' : null)),
            'recent' => DB::table('responsible_play_events')->where('email', $f->email)->latest('created_at')->limit(5)
                ->get(['type', 'created_at'])->map(fn ($e) => ['type' => $e->type, 'at' => $e->created_at]),
            'createdAt' => $f->created_at->toIso8601String(),
            'actionedAt' => $f->actioned_at?->toIso8601String(),
        ])]);
    }

    public function act(Request $request, HarmFlag $flag, ResponsiblePlay $rp)
    {
        $d = $request->validate([
            'action' => ['required', Rule::in(['note', 'contact', 'lower_limit', 'block', 'close'])],
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => in_array($request->input('action'), ['note', 'contact', 'close'], true))],
            'monthlyLimit' => ['nullable', 'numeric', 'min:0', 'max:10000', Rule::requiredIf(fn () => $request->input('action') === 'lower_limit')],
        ]);
        $user = $flag->user;
        $staff = $request->user();
        $did = match ($d['action']) {
            'note' => 'Note',
            'contact' => 'Contacted the customer',
            'lower_limit' => 'Lowered the monthly limit to £'.number_format((float) $d['monthlyLimit'], 2),
            'block' => 'Blocked (account suspended)',
            'close' => 'Reviewed and closed',
        };
        if (in_array($d['action'], ['lower_limit', 'block'], true) && ! $user) {
            abort(422, 'This flag is for a guest email with no account, so there is no limit or account to change.');
        }
        if ($d['action'] === 'lower_limit') {
            $new = Money::pence($d['monthlyLimit']);
            if ($user->limit_monthly_pence !== null && $new > $user->limit_monthly_pence) {
                abort(422, 'That is higher than their current monthly limit. Staff can only lower a limit.');
            }
            $rp->setLimits($user, ['monthly' => $new]); // a decrease: applies immediately
        }
        if ($d['action'] === 'block') {
            $user->update(['suspended_at' => now(), 'marketing_opt_in' => false]);
            $user->tokens()->delete();
        }

        $line = now()->timezone('Europe/London')->format('j M Y H:i').' · '.$staff->name.' · '.$did.(! empty($d['note']) ? ': '.$d['note'] : '');
        $flag->update([
            'notes' => trim(($flag->notes ? $flag->notes."\n" : '').$line),
            'status' => match ($d['action']) {
                'note' => $flag->status,
                'contact' => 'contacted',
                'lower_limit' => 'limit_lowered',
                'block' => 'blocked',
                'close' => 'closed',
            },
            'actioned_by' => $d['action'] === 'note' ? $flag->actioned_by : $staff->id,
            'actioned_at' => $d['action'] === 'note' ? $flag->actioned_at : now(),
        ]);

        return response()->json(['message' => $did.'.']);
    }
}
