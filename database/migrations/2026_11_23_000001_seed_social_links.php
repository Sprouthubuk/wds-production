<?php

use App\Http\Resources\SettingsPayload;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Social links in the footer and on the Contact page now come from Admin → Settings → Social links
 * (client, Nov 2026): Instagram @wds_originals, YouTube @wds_originals, Instagram @wds_competitions, and
 * Facebook (hidden until a URL is set). This fills only settings that are empty; anything an admin has
 * set is left alone, except that the old, wrong YouTube link (youtube.com/@wdsoriginals) is corrected.
 * No schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $site = Setting::get('site'); // the saved site settings, or null when Settings was never saved
        $site = is_array($site) ? $site : [];
        foreach (SettingsPayload::SOCIAL_DEFAULTS as $key => $value) {
            if (trim((string) ($site[$key] ?? '')) === '') {
                $site[$key] = $value;
            }
        }
        // The old YouTube link was wrong (youtube.com/@wdsoriginals): correct it if it was saved.
        if (in_array(rtrim((string) ($site['youtubeUrl'] ?? ''), '/'), ['https://youtube.com/@wdsoriginals', 'https://www.youtube.com/@wdsoriginals'], true)) {
            $site['youtubeUrl'] = SettingsPayload::SOCIAL_DEFAULTS['socialYoutubeUrl'];
        }
        Setting::put('site', $site);
    }

    public function down(): void
    {
        // Nothing to undo: these are ordinary settings an admin can change.
    }
};
