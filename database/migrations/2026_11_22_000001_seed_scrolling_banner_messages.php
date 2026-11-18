<?php

use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The home page's scrolling banner is back, with its messages set in Admin → Settings → Scrolling banner
 * messages (client, Nov 2026). It replaces the short-lived "Top announcement bar" setting.
 *
 * Seeds the default messages only when none are set. If an admin had already typed an announcement, that
 * text becomes the only message instead. Messages an admin has already saved are never changed.
 * No schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $site = Setting::get('site'); // the saved site settings, or null when Settings was never saved
        $site = is_array($site) ? $site : [];
        $current = array_filter(array_map(fn ($m) => trim((string) $m), (array) ($site['bannerMessages'] ?? [])), fn ($m) => $m !== '');
        $legacy = trim((string) ($site['announcementBar'] ?? ''));
        unset($site['announcementBar']);

        if ($current === []) {
            $site['bannerMessages'] = $legacy !== '' ? [$legacy] : SettingsPayload::BANNER_DEFAULTS;
        }
        Setting::put('site', $site);
    }

    public function down(): void
    {
        // Nothing to undo: the messages are ordinary settings an admin can clear.
    }
};
