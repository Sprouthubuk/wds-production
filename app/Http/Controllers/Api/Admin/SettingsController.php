<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return response()->json(['data' => SettingsPayload::get()]);
    }

    public function update(Request $request)
    {
        $d = $request->validate([
            'tradingName' => ['sometimes', 'string', 'max:80'],
            'legalName' => ['sometimes', 'string', 'max:120'],
            'companyNumber' => ['sometimes', 'string', 'max:20'],
            'registeredOffice' => ['sometimes', 'string', 'max:255'],
            'supportEmail' => ['sometimes', 'email'],
            'postalEntryAddress' => ['sometimes', 'array', 'max:8'], 'postalEntryAddress.*' => ['string', 'max:120'],
            'drawsInstagram' => ['sometimes', 'string', 'max:60'],
            'socials' => ['sometimes', 'array'], 'socials.*.label' => ['required', 'string'], 'socials.*.url' => ['required', 'url'],
            'youtubeUrl' => ['sometimes', 'nullable', 'url'],
            'drawsYoutubeUrl' => ['sometimes', 'nullable', 'url:https'],
        ]);
        Setting::put('site', array_merge(Setting::get('site', []) ?? [], $d));

        return response()->json(['message' => 'Settings saved.', 'data' => SettingsPayload::get()]);
    }
}
