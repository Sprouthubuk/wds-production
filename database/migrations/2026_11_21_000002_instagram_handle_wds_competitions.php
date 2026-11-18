<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The live-draw Instagram account is now @wds_competitions (client, Nov 2026). Saving Admin → Settings stores
 * the whole form, so a saved "site" setting may still hold the old @wds_originals handle and would override the
 * new default. This changes only those exact old values (the handle and the matching social link); anything else
 * an admin set is left alone. No schema change, nothing removed.
 */
return new class extends Migration
{
    private const OLD = 'wds_originals';

    private const NEW = 'wds_competitions';

    private const NEW_URL = 'https://www.instagram.com/wds_competitions/';

    public function up(): void
    {
        $site = Setting::get('site'); // the saved site settings (array), or null when Settings was never saved
        if (! is_array($site)) {
            return;
        }
        $changed = false;
        if (ltrim(trim((string) ($site['drawsInstagram'] ?? '')), '@') === self::OLD) {
            $site['drawsInstagram'] = self::NEW;
            $changed = true;
        }
        foreach ((array) ($site['socials'] ?? []) as $i => $s) {
            if (is_array($s) && str_contains(strtolower((string) ($s['url'] ?? '')), 'instagram.com/'.self::OLD)) {
                $site['socials'][$i] = ['label' => 'Instagram @'.self::NEW, 'url' => self::NEW_URL];
                $changed = true;
            }
        }
        if ($changed) {
            Setting::put('site', $site);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the old handle is no longer the client's live-draw account.
    }
};
