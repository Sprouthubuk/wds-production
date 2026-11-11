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
        'postalEntryAddress' => ['WDS Competitions — Free Entry', '[POSTAL ADDRESS LINE 1]', '[TOWN] [POSTCODE]'],
        'drawsInstagram' => 'wds_originals',
        'socials' => [
            ['label' => 'Instagram @wds_originals', 'url' => 'https://instagram.com/wds_originals'],
            ['label' => 'Instagram @wds_competitions', 'url' => 'https://instagram.com/wds_competitions'],
        ],
        'youtubeUrl' => 'https://youtube.com/@wdsoriginals',
    ];

    public static function get(): array
    {
        return array_merge(self::DEFAULTS, Setting::get('site', []) ?? []);
    }
}
