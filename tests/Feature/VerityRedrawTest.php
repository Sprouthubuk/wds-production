<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\Order;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\DrawService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Sprouthub\Verity\Engine\VerityException;
use Sprouthub\Verity\Facades\Verity;
use Sprouthub\Verity\Models\AuditEvent;
use Sprouthub\Verity\Models\Redraw;
use Sprouthub\Verity\Models\Winner as VerityWinner;
use Sprouthub\Verity\Verifier\RecordVerifier;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Forfeit and redraw, through Verity (protocol R2): the forfeit is on the audit chain, the redraw is
 * committed to a future drand round at the forfeit and drawn from it, BLS-verified, like the original.
 */
class VerityRedrawTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->fakeVerity();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buy(Competition $c, int $qty, string $email, string $name): void
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => $name, 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));
    }

    /** A competition with entries from three people, closed and drawn on ROUND; published (revealed) if asked. */
    private function drawn(bool $publish = true): Draw
    {
        $c = $this->publishedCompetition(['closes_at' => self::CLOSES_AT, 'draw_at' => self::CLOSES_AT]);
        $this->buy($c, 3, 'alex@example.com', 'Alex Smith');
        $this->buy($c, 2, 'sam@example.com', 'Sam Jones');
        $this->buy($c, 2, 'jo@example.com', 'Jo Brown');
        Carbon::setTestNow('2026-10-30 20:00:05');
        $this->artisan('verity:process-due')->assertSuccessful();
        Carbon::setTestNow('2026-10-30 20:01:05');
        $this->artisan('verity:process-due')->assertSuccessful();
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertSame('drawn', $draw->status);

        return $publish ? app(DrawService::class)->publish($draw) : $draw;
    }

    private function forfeit(Winner $w, array $body = [])
    {
        return $this->actingAs($this->admin)->postJson("/api/admin/winners/{$w->id}/forfeit", $body + [
            'reason' => 'failed_id', 'note' => 'Passport photo did not match (internal note)', 'redraw' => true, 'confirm' => true,
        ]);
    }

    public function test_forfeit_then_redraw_is_on_the_chain_and_passes_verity_s_verifier(): void
    {
        $draw = $this->drawn();
        $original = Winner::where('draw_id', $draw->id)->firstOrFail();
        $originalTicket = $draw->winningTicket->number;

        Carbon::setTestNow(self::FORFEIT_AT);
        $this->forfeit($original)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Redraw 1') && str_contains($m, number_format(self::ROUND_2)));

        $original->refresh();
        $this->assertTrue($original->isForfeited());
        $this->assertSame('failed_id', $original->forfeit_category);
        $this->assertFalse($original->show_on_site);
        // Only the category is public; the note never reaches Verity's audit chain.
        $forfeitEvent = AuditEvent::where('type', 'draw.winner_forfeited')->firstOrFail();
        $this->assertSame('Failed ID check', $forfeitEvent->payload['reason']);
        $this->assertSame('admin:'.$this->admin->id, $forfeitEvent->payload['by']);
        $this->assertSame(0, AuditEvent::all()->filter(fn ($e) => str_contains(json_encode($e->payload), 'Passport'))->count());

        // While the redraw waits for its round: no current winner, the redraw is public with its round.
        $this->assertNull($draw->fresh()->winning_ticket_id);
        $cert = $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk()->json('certificate');
        $this->assertSame(self::ROUND_2, $cert['redrawsPending'][0]['drandRound']);
        $this->assertSame('Original draw', $cert['winners'][0]['label']);
        $this->assertSame('forfeited', $cert['winners'][0]['status']);
        $this->assertSame('Failed ID check', $cert['winners'][0]['reason']);
        $this->assertNull($cert['winners'][0]['name'], 'A forfeited winner is shown by ticket only, not by name');
        $this->assertSame('Redraw pending', collect($this->getJson('/api/draws')->json('data'))->firstWhere('id', $draw->id)['note']);

        // The redraw runs from ROUND_2 once it's out, like the original draw.
        Carbon::setTestNow('2026-11-06 21:59:00');
        $this->artisan('verity:process-due')->assertSuccessful();

        $redraw = Redraw::firstOrFail();
        $this->assertSame('done', $redraw->status);
        $this->assertSame(self::ROUND_2, (int) $redraw->beacon_round);
        $this->assertSame(self::SIGNATURE_2, $redraw->beacon_signature);
        $replacement = Winner::where('draw_id', $draw->id)->where('redraw_number', 1)->firstOrFail();
        $this->assertFalse($replacement->isForfeited());
        $this->assertNotSame($originalTicket, $replacement->ticket->number);
        $this->assertSame($replacement->ticket_id, $draw->fresh()->winning_ticket_id);
        $this->assertSame(2, Winner::where('draw_id', $draw->id)->count(), 'The forfeited winner is kept');

        $res = $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk();
        $this->assertSame(['Original draw', 'Redraw 1'], array_column($res->json('certificate.winners'), 'label'));
        $this->assertSame(['forfeited', 'winner'], array_column($res->json('certificate.winners'), 'status'));
        $this->assertSame([], $res->json('certificate.redrawsPending'));

        $rows = RecordVerifier::verify($res->json('data'));
        $this->assertFalse(RecordVerifier::failed($rows), json_encode(array_values(array_filter($rows, fn ($r) => $r[0] === 'FAIL'))));
        $passed = array_column(array_filter($rows, fn ($r) => $r[0] === 'PASS'), 1);
        $this->assertNotEmpty(array_filter($passed, fn ($check) => stripos($check, 'redraw') !== false), 'The verifier checked the redraw: '.implode(' | ', $passed));
        $this->assertContains('drand BLS signature', $passed);
        fwrite(STDERR, "
[redraw] verifier PASS: ".implode(' | ', array_values(array_filter($passed, fn ($c) => stripos($c, 'redraw') !== false)))."
");
    }

    public function test_a_forfeited_winner_s_statuses_lock(): void
    {
        $winner = Winner::where('draw_id', $this->drawn()->id)->firstOrFail();
        $winner->update(['verification' => 'to_review', 'handover' => 'contacted']);
        Carbon::setTestNow(self::FORFEIT_AT);
        $this->forfeit($winner, ['reason' => 'no_response', 'redraw' => false])->assertOk();

        $this->actingAs($this->admin)->putJson("/api/admin/winners/{$winner->id}", ['verification' => 'approved'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/admin/winners/{$winner->id}", ['handover' => 'delivered'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/admin/winners/{$winner->id}", ['showOnSite' => true])->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/api/admin/winners/{$winner->id}", ['displayName' => 'Alex S.'])->assertOk();
        $this->assertSame(['to_review', 'contacted'], [$winner->fresh()->verification, $winner->fresh()->handover]);
        $this->assertSame('No response within 14 days', AuditEvent::where('type', 'draw.forfeited')->firstOrFail()->payload['reason']);
    }

    public function test_there_is_no_redraw_without_a_forfeit(): void
    {
        $draw = $this->drawn();
        $winner = Winner::where('draw_id', $draw->id)->firstOrFail();

        // No route redraws on its own, and nothing is scheduled until a forfeit.
        $this->actingAs($this->admin)->postJson("/api/admin/winners/{$winner->id}/redraw")->assertNotFound();
        $this->actingAs($this->admin)->postJson("/api/admin/draws/{$draw->id}/redraw")->assertNotFound();
        $this->assertSame(0, Redraw::count());

        // A forfeit with no redraw leaves the prize unawarded; it can't be redrawn afterwards.
        Carbon::setTestNow(self::FORFEIT_AT);
        $this->forfeit($winner, ['redraw' => false])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'unawarded'));
        $this->forfeit($winner->fresh())->assertStatus(422);
        $this->expectException(VerityException::class);
        Verity::redraw(VerityWinner::findOrFail($winner->verity_winner_id), 'Trying to redraw after a forfeit');
    }

    public function test_the_forfeit_needs_a_confirmation_and_a_known_reason(): void
    {
        $winner = Winner::where('draw_id', $this->drawn()->id)->firstOrFail();
        Carbon::setTestNow(self::FORFEIT_AT);

        $this->forfeit($winner, ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->forfeit($winner, ['reason' => 'did not like them'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertFalse($winner->fresh()->isForfeited());
        $this->assertSame(0, AuditEvent::where('type', 'like', 'draw.%forfeit%')->count());

        // A hand-added past winner (not drawn by Verity) can't be forfeited here.
        $past = Winner::create(['display_name' => 'Mark R.', 'prize' => 'Alpina D3']);
        $this->forfeit($past)->assertStatus(422);
    }

    public function test_verity_allows_a_forfeit_before_the_result_is_published_but_not_before_the_draw(): void
    {
        // Drawn but not yet revealed by WDS: Verity allows the forfeit (it knows nothing of WDS's reveal).
        $draw = $this->drawn(publish: false);
        $this->assertSame('drawn', $draw->status);
        $winner = Winner::where('draw_id', $draw->id)->firstOrFail();
        Carbon::setTestNow(self::FORFEIT_AT);
        $this->forfeit($winner)->assertOk();
        $this->assertTrue(AuditEvent::where('type', 'draw.winner_forfeited')->exists());
        $this->getJson("/api/draws/{$draw->id}/certificate")->assertNotFound(); // still nothing public until published

        // Before the draw there is no Verity winner, and Verity refuses a forfeit of a non-drawn competition.
        $c = $this->publishedCompetition(['slug' => 'later', 'closes_at' => '2026-11-20 20:00:00', 'draw_at' => '2026-11-20 20:30:00']);
        $this->assertSame(0, Winner::where('competition_id', $c->id)->count());
        // Verity's own rule (Redraws::blocker): only a winner of a competition that has been drawn. Checked on an
        // in-memory copy of a winner whose competition is still "closed" (nothing is written to verity_* tables).
        $vw = VerityWinner::findOrFail(Winner::where('draw_id', $draw->id)->value('verity_winner_id') ?? $winner->verity_winner_id);
        $vw->setRelation('competition', (clone $vw->competition)->forceFill(['status' => 'closed']));
        $this->assertSame('Only drawn end-draw winners can be forfeited', \Sprouthub\Verity\Services\Redraws::blocker($vw));
    }
}
