<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Winner;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WinnerController extends Controller
{
    public function index()
    {
        return Winner::with('competition:id,title', 'user:id,email,phone', 'draw:id,status')->latest()->get()->map(fn (Winner $w) => $w->toArray() + [
            'forfeit_label' => Winner::forfeitLabel($w->forfeit_category),
            'can_forfeit' => $w->verity_winner_id !== null && ! $w->isForfeited(),
        ]);
    }

    /**
     * Forfeit a drawn winner through Verity: permanent and on the public audit chain. With redraw (the
     * default) the replacement is committed to a future drand round now and drawn automatically once
     * it's out (protocol R2); without, the prize is unawarded.
     */
    public function forfeit(Request $request, Winner $winner, VerityDrawEngine $verity)
    {
        $d = $request->validate([
            'reason' => ['required', Rule::in(array_keys(config('raffle.draws.forfeit_reasons')))],
            'note' => ['nullable', 'string', 'max:500'],
            'redraw' => ['required', 'boolean'],
            // The admin has read that this is permanent and public.
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that the forfeit is permanent and public.']);

        $redraw = $verity->forfeit($winner, $d['reason'], $d['note'] ?? null, (bool) $d['redraw'], $request->user());

        return response()->json(['message' => match (true) {
            $redraw !== null => "Forfeited. Redraw {$redraw->number} is committed to drand round ".number_format((int) $redraw->beacon_round)
                .' and runs automatically once that round is out (about a minute).',
            (bool) $d['redraw'] => 'Forfeited. No redraw was possible (the redraw limit is reached, or every entry has won), so the prize is unawarded.',
            default => 'Forfeited. The prize is unawarded (no redraw).',
        }]);
    }

    public function store(Request $request)
    {
        $winner = Winner::create($this->validated($request));

        return response()->json($winner, 201);
    }

    public function update(Request $request, Winner $winner)
    {
        $data = $this->validated($request, true);
        if ($winner->isForfeited()) {
            // A forfeited winner's claim is closed: its ID check and handover stay as they were.
            foreach (['verification' => 'ID check', 'handover' => 'handover'] as $col => $label) {
                if (array_key_exists($col, $data) && $data[$col] !== $winner->{$col}) {
                    abort(422, "This winner was forfeited, so the {$label} status is locked.");
                }
                unset($data[$col]);
            }
            if (! empty($data['show_on_site'])) {
                abort(422, 'A forfeited winner cannot be shown on the site as a winner.');
            }
        }
        $winner->update($data);

        return response()->json($winner);
    }

    public function uploadImage(Request $request, Winner $winner)
    {
        $request->validate(['image' => ['required', 'image', 'max:8192']]);
        $winner->update(['image' => asset('storage/'.$request->file('image')->store('winners', 'public'))]);

        return response()->json($winner);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $d = $request->validate([
            'displayName' => [$s, 'string', 'max:80'],
            'prize' => [$s, 'string', 'max:160'],
            'image' => ['nullable', 'string'],
            'verification' => ['sometimes', Rule::in(['awaiting_id', 'to_review', 'approved', 'denied'])],
            'handover' => ['sometimes', Rule::in(['not_started', 'contacted', 'booked', 'delivered'])],
            'showOnSite' => ['sometimes', 'boolean'],
            'drawnAt' => ['nullable', 'date'],
        ]);
        $map = ['displayName' => 'display_name', 'prize' => 'prize', 'image' => 'image', 'verification' => 'verification', 'handover' => 'handover', 'showOnSite' => 'show_on_site', 'drawnAt' => 'drawn_at'];

        return collect($map)->filter(fn ($col, $in) => array_key_exists($in, $d))->mapWithKeys(fn ($col, $in) => [$col => $d[$in]])->all();
    }
}
