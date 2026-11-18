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
        // One line per row: "WDS Competitions – Free Entry, Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY, United Kingdom".
        'postalEntryAddress' => ['WDS Competitions – Free Entry', 'Knoll House', 'Knoll Road', 'Camberley', 'Surrey', 'GU15 3SY', 'United Kingdom'],
        'drawsInstagram' => 'wds_competitions',
        'socials' => [
            ['label' => 'Instagram @wds_competitions', 'url' => 'https://www.instagram.com/wds_competitions/'],
        ],
        'youtubeUrl' => 'https://youtube.com/@wdsoriginals',
        // Optional YouTube channel for live draws. Instagram stays the primary channel; with both set
        // the copy says "Drawn live on our socials" (web/src/lib/channels.ts, drawnLiveOn() below).
        'drawsYoutubeUrl' => null,
        // Optional site-wide cap on what any one customer can spend in a calendar month (all payment
        // methods, all competitions). OFF by default: only switch it on if the client decides to.
        'siteSpendCapEnabled' => false,
        'siteSpendCapMonthly' => null, // pounds
        // The scrolling banner under the header on the home page (Admin → Settings → Scrolling banner
        // messages), in order. Empty = no banner. A data migration seeds BANNER_DEFAULTS once.
        'bannerMessages' => [],
    ];

    /** Seeded once into the saved settings (2026_11_22_000001 migration) when no messages are set. */
    public const BANNER_DEFAULTS = [
        'Ticket bundles available',
        'Low ticket numbers, better odds',
        'Numbers emailed instantly',
        'Drawn live on Instagram @wds_competitions',
    ];

    public static function get(): array
    {
        // The contact address comes from config('wds.contact_email') only, whatever an older saved setting says.
        $saved = Setting::get('site', []) ?? [];
        unset($saved['announcementBar']); // replaced by bannerMessages (Nov 2026)

        return ['supportEmail' => (string) config('wds.contact_email'), 'footer' => self::footer()]
            + array_merge(self::DEFAULTS, $saved);
    }

    /** The site footer's config (config/wds.php), not editable in Admin. */
    public static function footer(): array
    {
        return [
            'instagram' => config('wds.instagram'),
            'youtube' => config('wds.youtube'),
            'icoNumber' => config('wds.ico_number') ?: null,
            'poweredBy' => (bool) config('wds.powered_by'),
        ];
    }

    /** Where draws are streamed, to finish "Drawn live on …": "our socials" or "Instagram @wds_competitions". */
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
