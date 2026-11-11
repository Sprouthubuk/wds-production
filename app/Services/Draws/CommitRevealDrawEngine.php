<?php

namespace App\Services\Draws;

use App\Models\Competition;

/**
 * Interim, built-in engine used until Verity is connected.
 *
 * prepare(): generate a 256-bit secret seed; publish commitment = sha256(seed).
 * draw():    winner index = HMAC-SHA256(seed, entriesHash) as a big integer mod N (rejection-sampled
 *            to avoid modulo bias), then reveal the seed. Anyone can recompute it.
 */
class CommitRevealDrawEngine implements DrawEngine
{
    public function name(): string { return 'commit-reveal'; }

    public function prepare(Competition $competition, array $ticketNumbers, string $entriesHash): DrawPreparation
    {
        $seed = bin2hex(random_bytes(32));

        return new DrawPreparation(hash('sha256', $seed), ['seed' => $seed]);
    }

    public function draw(Competition $competition, array $ticketNumbers, string $entriesHash, DrawPreparation $preparation): DrawOutcome
    {
        $seed = $preparation->data['seed'] ?? throw new \RuntimeException('Missing seed.');
        $n = count($ticketNumbers);
        if ($n === 0) {
            throw new \RuntimeException('No entries to draw from.');
        }

        $index = self::pickIndex($seed, $entriesHash, $n);

        return new DrawOutcome($ticketNumbers[$index], [
            'method' => 'HMAC-SHA256(seed, entriesHash || ":" || counter) → first 6 bytes as integer, rejection-sampled, mod N',
            'seed' => $seed,
            'commitment' => hash('sha256', $seed),
            'entriesHash' => $entriesHash,
            'entries' => $n,
            'index' => $index,
        ]);
    }

    public function verificationUrl(DrawOutcome $outcome): ?string { return null; }

    public static function pickIndex(string $seed, string $entriesHash, int $n): int
    {
        $space = 2 ** 48;
        $limit = $space - ($space % $n);
        for ($counter = 0; ; $counter++) {
            $h = hash_hmac('sha256', $entriesHash.':'.$counter, $seed);
            $value = hexdec(substr($h, 0, 12));
            if ($value < $limit) {
                return (int) ($value % $n);
            }
        }
    }
}
