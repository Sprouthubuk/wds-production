<?php

namespace App\Services\Draws;

use App\Models\Competition;
use RuntimeException;

/**
 * Adapter for Liam's Verity draw engine — PLACEHOLDER.
 *
 * Set DRAW_ENGINE=verity once this class is wired up. Map Verity's API onto the DrawEngine
 * contract:
 *   prepare() → register the draw with Verity, sending competition id/title, entries count and
 *               entries hash (never personal data). Return Verity's commitment / draw id.
 *   draw()    → trigger the draw, receive the winning ticket number + Verity's proof/certificate.
 *   verificationUrl() → Verity's public verification page for the draw, shown on /live-draws.
 *
 * Config: config('raffle.draws.verity.base_url'), config('raffle.draws.verity.api_key').
 */
class VerityDrawEngine implements DrawEngine
{
    public function __construct(private array $config) {}

    public function name(): string { return 'verity'; }

    public function prepare(Competition $competition, array $ticketNumbers, string $entriesHash): DrawPreparation
    {
        throw new RuntimeException('Verity adapter not connected yet — see App\Services\Draws\VerityDrawEngine.');
    }

    public function draw(Competition $competition, array $ticketNumbers, string $entriesHash, DrawPreparation $preparation): DrawOutcome
    {
        throw new RuntimeException('Verity adapter not connected yet.');
    }

    public function verificationUrl(DrawOutcome $outcome): ?string
    {
        return $outcome->externalId && ! empty($this->config['base_url'])
            ? rtrim($this->config['base_url'], '/').'/verify/'.$outcome->externalId
            : null;
    }
}
