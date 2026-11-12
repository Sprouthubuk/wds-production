<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draw extends Model
{
    /**
     * What a visitor typed into the /verify lookup, tidied: a certificate ID ("12", "#12") or a draw reference
     * ("WDS-AB12CD", " wds - ab12cd ", "wdsab12cd", "ab12cd"), or a pasted /verify/… or certificate link.
     *
     * @return array{id:int}|array{ref:string}|null
     */
    public static function normaliseLookup(?string $input): ?array
    {
        $q = trim((string) $input);
        if ($q === '') {
            return null;
        }
        // A pasted link: keep its last meaningful path segment (/verify/12, /api/draws/12/certificate).
        if (preg_match('~^https?://~i', $q) || str_contains($q, '/')) {
            $parts = array_values(array_filter(explode('/', (string) parse_url($q, PHP_URL_PATH) ?: $q), fn ($p) => $p !== '' && strtolower($p) !== 'certificate'));
            $q = (string) end($parts);
        }
        $q = strtoupper((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $q));
        $q = ltrim($q, '#');
        if ($q === '') {
            return null;
        }
        if (preg_match('/^\d{1,9}$/', $q)) {
            return ['id' => (int) $q];
        }
        if (! preg_match('/^[A-Z0-9-]{3,64}$/', $q)) {
            return null;
        }
        $prefix = strtoupper((string) config('verity.ref_prefix', 'VTY-'));   // e.g. "WDS-"
        $bare = rtrim($prefix, '-');
        if ($bare !== '' && ! str_starts_with($q, $prefix)) {
            $q = str_starts_with($q, $bare) ? $prefix.ltrim(substr($q, strlen($bare)), '-') : $prefix.ltrim($q, '-');
        }

        return ['ref' => $q];
    }

    /** The published draw a lookup points at, or null (unknown, or not published yet: nothing is revealed early). */
    public static function findPublishedByLookup(?string $input): ?self
    {
        $key = self::normaliseLookup($input);
        if (! $key) {
            return null;
        }
        $q = self::query()->where('status', 'published')->whereNotNull('verity_ref');

        return isset($key['id']) ? $q->whereKey($key['id'])->first() : $q->where('verity_ref', $key['ref'])->latest('id')->first();
    }

    protected $fillable = ['competition_id', 'engine', 'status', 'entries_count', 'entries_hash', 'commitment', 'proof', 'winning_number', 'winning_ticket_id', 'certificate_path', 'run_by', 'drawn_at', 'published_at', 'verity_ref', 'beacon_round', 'beacon_round_at', 'method', 'livestream_url'];

    protected function casts(): array
    {
        return ['drawn_at' => 'datetime', 'published_at' => 'datetime', 'beacon_round_at' => 'datetime'];
    }

    public function competition(): BelongsTo { return $this->belongsTo(Competition::class); }
    public function winningTicket(): BelongsTo { return $this->belongsTo(Ticket::class, 'winning_ticket_id'); }
}
