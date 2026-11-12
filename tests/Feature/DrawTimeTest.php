<?php

namespace Tests\Feature;

use App\Services\Draws\VerityDrawEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sprouthub\Verity\Engine\Drand;
use Sprouthub\Verity\Models\AuditEvent;
use Sprouthub\Verity\Models\Competition as VerityCompetition;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Entries close at closes_at (sealed then); the drand round is the one at the live draw time draw_at
 * (engine spec §4.2.1), so the winner can't be known before the stream. Close 19:00, draw 19:30.
 */
class DrawTimeTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
    }

    public function test_publishing_commits_to_the_round_at_the_live_draw_time(): void
    {
        $closes = now()->addDays(3)->setTime(19, 0);
        $draw = $closes->copy()->addMinutes(30);
        $c = $this->publishedCompetition(['closes_at' => $closes, 'draw_at' => $draw]);

        $vc = VerityCompetition::where('ref', $c->verity_ref)->firstOrFail();
        $this->assertSame($draw->getTimestamp(), $vc->draw_at->getTimestamp());
        $this->assertSame(Drand::roundAt($draw->getTimestamp()), (int) $vc->beacon_planned_round);
        // Not the old rule (a minute after the close).
        $this->assertGreaterThan(Drand::roundAt($closes->getTimestamp() + 60) + 500, (int) $vc->beacon_planned_round);
        $published = AuditEvent::where('competition_id', $vc->id)->where('type', 'competition.published')->firstOrFail();
        $this->assertSame($draw->copy()->utc()->format('Y-m-d\TH:i:s\Z'), $published->payload['draw_at']);
    }

    public function test_an_extension_moves_the_live_draw_with_the_close(): void
    {
        $closes = now()->addDays(3)->setTime(19, 0);
        $c = $this->publishedCompetition(['closes_at' => $closes, 'draw_at' => $closes->copy()->addMinutes(30)]);

        $c = app(VerityDrawEngine::class)->extend($c, $closes->copy()->addDay(), 'Postal delays over Christmas week');
        $this->assertSame($closes->copy()->addDay()->getTimestamp(), $c->closes_at->getTimestamp());
        $this->assertSame($closes->copy()->addDay()->addMinutes(30)->getTimestamp(), $c->fresh()->draw_at->getTimestamp());
    }
}
