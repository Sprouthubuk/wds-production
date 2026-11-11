<?php

namespace App\Services;

use App\Exceptions\EntryException;
use App\Models\Competition;
use App\Models\Draw;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Winner;
use App\Services\Draws\DrawEngine;
use App\Services\Draws\DrawOutcome;
use App\Services\Draws\DrawPreparation;
use Illuminate\Support\Facades\DB;

class DrawService
{
    public function __construct(private DrawEngine $engine) {}

    /** Close the competition, lock the entry list and get a commitment from the engine. */
    public function prepare(Competition $competition, User $admin): Draw
    {
        if ($competition->draws()->whereIn('status', ['prepared', 'drawn', 'published'])->exists()) {
            throw new EntryException('This competition already has a draw.');
        }

        return DB::transaction(function () use ($competition, $admin) {
            $competition->update(['status' => 'closed']);
            $numbers = $this->ticketNumbers($competition);
            if (! $numbers) {
                throw new EntryException('There are no tickets to draw.');
            }
            $hash = self::entriesHash($numbers);
            $prep = $this->engine->prepare($competition, $numbers, $hash);

            return Draw::create([
                'competition_id' => $competition->id,
                'engine' => $this->engine->name(),
                'status' => 'prepared',
                'entries_count' => count($numbers),
                'entries_hash' => $hash,
                'commitment' => $prep->commitment,
                'proof' => json_encode(['preparation' => $prep->data]),
                'run_by' => $admin->id,
            ]);
        });
    }

    /** Run the draw (live on Instagram) and create the winner record. */
    public function run(Draw $draw, User $admin): Draw
    {
        if ($draw->status !== 'prepared') {
            throw new EntryException('This draw has already been run.');
        }
        $competition = $draw->competition;
        $numbers = $this->ticketNumbers($competition);
        $hash = self::entriesHash($numbers);
        if ($hash !== $draw->entries_hash) {
            throw new EntryException('The entry list changed after the draw was prepared. Void and prepare again.');
        }

        $stored = json_decode($draw->proof ?? '{}', true);
        $prep = new DrawPreparation($draw->commitment, $stored['preparation'] ?? []);
        $outcome = $this->engine->draw($competition, $numbers, $hash, $prep);

        return DB::transaction(function () use ($draw, $competition, $outcome, $admin) {
            $ticket = Ticket::where('competition_id', $competition->id)->where('number', $outcome->winningNumber)->firstOrFail();

            $draw->update([
                'status' => 'drawn',
                'proof' => json_encode($this->publicProof($outcome)),
                'winning_number' => $outcome->winningNumber,
                'winning_ticket_id' => $ticket->id,
                'run_by' => $admin->id,
                'drawn_at' => now(),
            ]);
            $competition->update(['status' => 'drawn']);

            Winner::create([
                'competition_id' => $competition->id,
                'draw_id' => $draw->id,
                'ticket_id' => $ticket->id,
                'user_id' => $ticket->user_id,
                'display_name' => self::displayName($ticket->name),
                'prize' => $competition->title,
                'drawn_at' => now(),
            ]);

            return $draw->fresh();
        });
    }

    public function publish(Draw $draw): Draw
    {
        $draw->update(['status' => 'published', 'published_at' => now()]);

        return $draw;
    }

    /** @return list<int> */
    public function ticketNumbers(Competition $competition): array
    {
        return Ticket::where('competition_id', $competition->id)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
    }

    /** @param list<int> $numbers */
    public static function entriesHash(array $numbers): string
    {
        return hash('sha256', implode(',', $numbers));
    }

    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($parts);
        $last = array_pop($parts);

        return $last ? "{$first} ".mb_substr($last, 0, 1).'.' : $first;
    }

    private function publicProof(DrawOutcome $outcome): array
    {
        return array_merge($outcome->proof, array_filter([
            'externalId' => $outcome->externalId,
            'verificationUrl' => $this->engine->verificationUrl($outcome),
        ]));
    }
}
