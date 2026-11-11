<?php

namespace App\Services\Draws;

use App\Models\Competition;

/**
 * Plug-in point for the draw engine (Verity).
 *
 * Lifecycle, run from Admin → Draws:
 *  1. prepare(): we lock the competition, export the full, ordered ticket list and its SHA-256 hash.
 *     The engine returns a commitment that can be published (e.g. on the Instagram live) BEFORE drawing.
 *  2. draw(): the engine picks a winning ticket NUMBER from 1..entries_count-ish and returns a proof
 *     that anyone can use to verify the result against the published commitment and entries hash.
 *
 * Engines only ever see ticket numbers + the hash — never names, emails or addresses.
 */
interface DrawEngine
{
    public function name(): string;

    /**
     * @param  list<int>  $ticketNumbers  every valid ticket number, ascending
     */
    public function prepare(Competition $competition, array $ticketNumbers, string $entriesHash): DrawPreparation;

    /**
     * @param  list<int>  $ticketNumbers
     */
    public function draw(Competition $competition, array $ticketNumbers, string $entriesHash, DrawPreparation $preparation): DrawOutcome;

    /** Optional public verification URL for a completed draw. */
    public function verificationUrl(DrawOutcome $outcome): ?string;
}
