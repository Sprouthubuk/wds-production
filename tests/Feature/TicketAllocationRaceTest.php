<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real race: two separate PHP processes allocate tickets on the same competition, released at the
 * same moment, against one shared database (a SQLite file locally; MySQL 8.4 in CI when
 * DB_RACE_DATABASE is set). In-memory SQLite can't be shared between processes, so this test
 * builds its own database instead of using RefreshDatabase.
 *
 * Proves: no duplicate numbers, numbers 1..N with no gaps, tickets_allocated matches the rows,
 * and each racer's rows match exactly what it was told it got (nothing half-written).
 */
class TicketAllocationRaceTest extends TestCase
{
    private const WORKERS = 2;

    private const ITERATIONS = 40;

    private string $driver;

    private string $database;

    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = config('database.default');
        if ($this->driver === 'sqlite') {
            $this->sqliteFile = storage_path('framework/testing/race-'.bin2hex(random_bytes(4)).'.sqlite');
            @mkdir(dirname($this->sqliteFile), 0777, true);
            touch($this->sqliteFile);
            $this->database = $this->sqliteFile;
        } elseif (in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $this->database = (string) env('DB_RACE_DATABASE');
            if ($this->database === '') {
                $this->markTestSkipped('Set DB_RACE_DATABASE to an empty MySQL database to run the race test.');
            }
        } else {
            $this->markTestSkipped("No race test for {$this->driver}.");
        }

        config()->set('database.connections.race', array_merge(
            config("database.connections.{$this->driver}"),
            ['database' => $this->database],
        ));
        Artisan::call('migrate:fresh', ['--database' => 'race', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('race');
        if ($this->sqliteFile) {
            foreach ([$this->sqliteFile, $this->sqliteFile.'-journal', $this->sqliteFile.'-wal', $this->sqliteFile.'-shm'] as $f) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    public function test_two_processes_never_get_the_same_number(): void
    {
        $race = DB::connection('race');
        $competitionId = $race->table('competitions')->insertGetId([
            'slug' => 'race', 'title' => 'Race car', 'category' => 'cars', 'status' => 'live',
            'ticket_price_pence' => 1495, 'bundles' => json_encode([]), 'total_tickets' => 10_000, 'max_per_person' => 10_000,
            'draw_at' => now()->addWeek(), 'postal_entry_enabled' => true, 'event_sales_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $startAt = microtime(true) + 6;
        $processes = [];
        for ($w = 1; $w <= self::WORKERS; $w++) {
            $p = new Process(
                [PHP_BINARY, base_path('tests/Concurrency/allocate-worker.php'), (string) $competitionId, (string) $w, (string) self::ITERATIONS, sprintf('%.6F', $startAt)],
                base_path(),
                $this->workerEnv(),
                null,
                120,
            );
            $p->start();
            $processes[$w] = $p;
        }

        $results = [];
        foreach ($processes as $w => $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), "Racer {$w} crashed:\n".$p->getErrorOutput().$p->getOutput());
            $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
            $results[$w] = json_decode((string) end($lines), true);
            $this->assertIsArray($results[$w], "Racer {$w} printed no result:\n".$p->getOutput());
        }

        // Every attempt succeeded: no "database is locked", no deadlock surfaced to the caller.
        foreach ($results as $w => $r) {
            $this->assertSame([], $r['errors'], "Racer {$w} had errors");
            $this->assertSame(self::ITERATIONS, $r['ok']);
        }

        $numbers = $race->table('tickets')->where('competition_id', $competitionId)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
        $expected = array_sum(array_column($results, 'tickets'));

        $this->assertCount($expected, $numbers, 'Rows in tickets match what the racers were told they got');
        $this->assertSame($numbers, array_values(array_unique($numbers)), 'No duplicate numbers');
        $this->assertSame(range(1, $expected), $numbers, 'Numbers run 1..N with no gaps');
        $this->assertSame($expected, (int) $race->table('competitions')->where('id', $competitionId)->value('tickets_allocated'), 'tickets_allocated matches the rows');

        foreach ($results as $w => $r) {
            $mine = $race->table('tickets')->where('competition_id', $competitionId)->where('email', "racer{$w}@race.test")
                ->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
            $reported = $r['numbers'];
            sort($reported);
            $this->assertSame($reported, $mine, "Racer {$w}'s rows are exactly the numbers it was given");
        }
        $this->assertSame(0, $race->table('tickets')->where('competition_id', $competitionId)->where(fn ($q) => $q->whereNull('email')->orWhereNull('name'))->count());

        // Evidence that the two really overlapped: the owner changes many times along the number line.
        $owners = $race->table('tickets')->where('competition_id', $competitionId)->orderBy('number')->pluck('email')->all();
        $switches = 0;
        for ($i = 1; $i < count($owners); $i++) {
            $switches += $owners[$i] !== $owners[$i - 1] ? 1 : 0;
        }
        fwrite(STDERR, sprintf("\n[race:%s] %d tickets, %d owner switches along the number line, start lateness %s ms\n",
            $this->driver, $expected, $switches, implode('/', array_column($results, 'late_ms'))));
        $this->assertGreaterThanOrEqual(2, $switches, 'The racers ran one after the other, not at the same time; the race was not exercised');
    }

    /** The child processes get this test's shared database, not the parent's in-memory one. */
    private function workerEnv(): array
    {
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $this->driver,
            'DB_DATABASE' => $this->database,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];
        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (($v = env($key)) !== null) {
                $env[$key] = (string) $v;
            }
        }

        return $env;
    }
}
