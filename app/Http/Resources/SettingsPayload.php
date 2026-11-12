<?php

namespace App\Http\Resources;

use App\Models\Setting;

final class SettingsPayload
{
    public const DEFAULTS = [
        'tradingName' => 'WDS Competitions',
        'legalName' => 'WDS Originals Limited',
        'companyNumber' => '13330886',
        'registeredOffice' => 'Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY',
        'supportEmail' => 'competitions@wdsoriginals.com',
        // One line per row: "WDS Competitions – Free Entry, Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY, United Kingdom".
        'postalEntryAddress' => ['WDS Competitions – Free Entry', 'Knoll House', 'Knoll Road', 'Camberley', 'Surrey', 'GU15 3SY', 'United Kingdom'],
        'drawsInstagram' => 'wds_originals',
        'socials' => [
            ['label' => 'Instagram @wds_originals', 'url' => 'https://instagram.com/wds_originals'],
            ['label' => 'Instagram @wds_competitions', 'url' => 'https://instagram.com/wds_competitions'],
        ],
        'youtubeUrl' => 'https://youtube.com/@wdsoriginals',
        // Optional YouTube channel for live draws. Instagram stays the primary channel; with both set
        // the copy says "Drawn live on our socials" (web/src/lib/channels.ts, drawnLiveOn() below).
        'drawsYoutubeUrl' => null,
    ];

    public static function get(): array
    {
        return array_merge(self::DEFAULTS, Setting::get('site', []) ?? []);
    }

    /** Where draws are streamed, to finish "Drawn live on …": "our socials" or "Instagram @wds_originals". */
    public static function drawnLiveOn(?array $settings = null): string
    {
        $s = $settings ?? self::get();
        $instagram = ltrim(trim((string) ($s['drawsInstagram'] ?? '')), '@');
        $youtube = $s['drawsYoutubeUrl'] ?? null;

        return match (true) {
            $instagram !== '' && $youtube => 'our socials',
            $instagram !== '' => "Instagram @{$instagram}",
            (bool) $youtube => 'YouTube',
            default => 'our socials',
        };
    }
}
