<?php

/*
 * One racer for TicketAllocationRaceTest. Run as its own PHP process:
 *
 *   php tests/Concurrency/allocate-worker.php <competitionId> <workerId> <iterations> <startAtUnixMicro>
 *
 * Boots the app against the database in the environment (DB_CONNECTION / DB_DATABASE ...), waits
 * for the shared start time so every racer begins together, then allocates 1-3 tickets per
 * iteration (postal: 1) across the online, postal and event channels, through Verity; every fifth one under a shared email. Prints one JSON line with what it got.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $competitionId, $workerId, $iterations, $startAt] = $argv;

$allocator = $app->make(App\Services\TicketAllocator::class);
$competition = App\Models\Competition::findOrFail((int) $competitionId);
$channels = ['online', 'postal', 'event'];

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$result = ['worker' => (int) $workerId, 'late_ms' => (int) round((microtime(true) - (float) $startAt) * 1000), 'ok' => 0, 'tickets' => 0, 'numbers' => [], 'errors' => []];
for ($i = 0; $i < (int) $iterations; $i++) {
    $channel = $channels[$i % 3];
    $qty = $channel === 'postal' ? 1 : 1 + ($i % 3); // a postal entry is one ticket
    try {
        $tickets = $allocator->allocate($competition, $qty, [
            'channel' => $channel,
            // Every fifth purchase is by one shared person, so both racers create/find the same entrant.
            'email' => $i % 5 === 4 ? 'shared@race.test' : "racer{$workerId}@race.test",
            'name' => "Racer {$workerId}",
        ]);
        $result['ok']++;
        $result['tickets'] += $tickets->count();
        array_push($result['numbers'], ...$tickets->pluck('number')->map(fn ($n) => (int) $n)->all());
    } catch (Throwable $e) {
        $result['errors'][] = get_class($e).': '.$e->getMessage();
    }
    // A short random gap between purchases, as in real traffic. Without it SQLite's busy handler
    // lets one writer take every turn (correct, but it doesn't interleave the racers).
    usleep(random_int(0, 3000));
}

echo json_encode($result), PHP_EOL;
