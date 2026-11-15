<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;

/**
 * Format checks for the Verity secrets that Verity's own verity:check only checks for presence.
 *
 * VERITY_SEED_KEY seals every competition's server seed (Sprouthub\Verity\Casts\SealedSeed). It must be
 * "base64:" followed by the base64 of a random key of exactly the length the cipher needs (32 bytes for
 * AES-256-CBC, the app cipher). Without the prefix the base64 text itself is used as the key, which is the
 * wrong length: publishing then crashes with "Unsupported cipher or incorrect key length".
 *
 * VERITY_OWNER_PEPPER is an HMAC key (Sprouthub\Verity\Support\OwnerHash): any non-empty string works, so
 * there is no format to check, only that it's set (Verity warns when it isn't).
 *
 * Key values are never printed or logged: only what's wrong with them.
 */
final class VerityKeyChecks
{
    /** @return array<int, array{key:string, label:string, ok:bool, level:string, note:string}> */
    public static function run(): array
    {
        $problem = self::seedKeyProblem();

        return [[
            'key' => 'seed_key_format',
            'label' => 'VERITY_SEED_KEY is a valid key for '.self::cipher().' (decodes, right length, encrypts and decrypts)',
            'ok' => $problem === null,
            'level' => 'FAIL',
            'note' => (string) $problem,
        ]];
    }

    /** What's wrong with VERITY_SEED_KEY, or null when it's fine (or not set: Verity's own seed_key row covers that). */
    public static function seedKeyProblem(): ?string
    {
        $key = (string) config('verity.seed_key');
        if ($key === '') {
            return null;
        }
        $cipher = self::cipher();
        $need = self::keyLength($cipher);
        $make = 'Make one with php artisan verity:seal-seeds --new-key (it prints the whole line, prefix included).';

        if (! str_starts_with($key, 'base64:')) {
            $looksBase64 = base64_decode($key, true) !== false && strlen((string) base64_decode($key, true)) === $need;

            return $looksBase64
                ? "It's missing the \"base64:\" prefix: it must be base64: followed by the key ({$need} bytes for {$cipher}). Add the prefix to the same value; don't make a new key."
                : "It must be base64: followed by the base64 of a {$need}-byte key for {$cipher}. {$make}";
        }
        $raw = base64_decode(substr($key, 7), true);
        if ($raw === false) {
            return "The part after base64: isn't valid base64. {$make}";
        }
        if (strlen($raw) !== $need) {
            return 'It decodes to '.strlen($raw)." bytes; {$cipher} needs {$need}. {$make}";
        }
        try {
            $enc = new Encrypter($raw, $cipher);
            $probe = bin2hex(random_bytes(16));
            if ($enc->decryptString($enc->encryptString($probe)) !== $probe) {
                return 'An encrypt/decrypt round-trip with it gave a different value.';
            }
        } catch (\Throwable $e) {
            return 'An encrypt/decrypt round-trip with it failed: '.$e->getMessage();
        }

        return null;
    }

    private static function cipher(): string
    {
        return (string) config('app.cipher', 'AES-256-CBC');
    }

    private static function keyLength(string $cipher): int
    {
        return in_array(strtolower($cipher), ['aes-128-cbc', 'aes-128-gcm'], true) ? 16 : 32;
    }
}
