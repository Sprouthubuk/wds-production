<?php

use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The free postal entry address now starts with "WDS Originals Ltd" (client, Nov 2026). A saved copy of the
 * previous default is replaced with the new one; an address an admin has changed to anything else is left alone,
 * and nothing is written when the address was never saved (the new default applies). No schema change.
 */
return new class extends Migration
{
    private const OLD_DEFAULT = ['WDS Competitions – Free Entry', 'Knoll House', 'Knoll Road', 'Camberley', 'Surrey', 'GU15 3SY', 'United Kingdom'];

    public function up(): void
    {
        $site = Setting::get('site');
        if (! is_array($site) || ! isset($site['postalEntryAddress']) || ! is_array($site['postalEntryAddress'])) {
            return;
        }
        $saved = array_values(array_map(fn ($l) => trim((string) $l), $site['postalEntryAddress']));
        if ($saved === self::OLD_DEFAULT) {
            $site['postalEntryAddress'] = SettingsPayload::DEFAULTS['postalEntryAddress'];
            Setting::put('site', $site);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the address is an ordinary setting an admin can change.
    }
};
