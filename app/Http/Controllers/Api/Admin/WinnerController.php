<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Winner;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WinnerController extends Controller
{
    public function index()
    {
        return Winner::with('competition:id,title', 'user:id,email,phone')->latest()->get();
    }

    public function store(Request $request)
    {
        $winner = Winner::create($this->validated($request));

        return response()->json($winner, 201);
    }

    public function update(Request $request, Winner $winner)
    {
        $winner->update($this->validated($request, true));

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
