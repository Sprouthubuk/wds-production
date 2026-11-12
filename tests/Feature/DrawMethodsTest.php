<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\Order;
use App\Models\User;
use App\Models\Winner;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Sprouthub\Verity\Engine\Drand;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** DRAFT (item 8): the two draw methods, against the verity-engine draw-methods branch. */
class DrawMethodsTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buy(Competition $c, int $qty, string $email): void
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));
    }

    public function test_method_a_close_and_draw_now_draws_on_the_next_round(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC(Drand::roundTime(self::ROUND) - 86400));
        $c = $this->publishedCompetition(['closes_at' => now()->addDays(3), 'draw_at' => now()->addDays(3)->addHour(), 'postal_closes_at' => now()->addDays(2)]);
        $this->assertSame('verity', $c->draw_method);
        $this->buy($c, 3, 'a@example.com');
        $this->buy($c, 2, 'b@example.com');

        // Pressed on the livestream; the next round is ROUND (about a minute later in real life).
        Carbon::setTestNow(Carbon::createFromTimestampUTC(Drand::roundTime(self::ROUND) - 10));
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-entries")->assertStatus(422); // wrong button
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-now")->assertOk();
        $this->assertSame('closed', $c->fresh()->status);
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertSame(self::ROUND, (int) $draw->beacon_round);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-now")->assertStatus(422); // once

        // Entries are refused straight away.
        $this->postJson('/api/checkout', [
            'customer' => ['email' => 'late@example.com', 'name' => 'Late Buyer', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertStatus(422);

        Carbon::setTestNow(Carbon::createFromTimestampUTC(Drand::roundTime(self::ROUND) + 5));
        $this->artisan('verity:process-due')->assertSuccessful();
        $draw->refresh();
        $this->assertSame('drawn', $draw->status);
        $this->assertNotNull($draw->winning_ticket_id);

        $this->actingAs($this->admin)->postJson("/api/admin/draws/{$draw->id}/publish")->assertOk();
        $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk()
            ->assertJsonPath('certificate.method', 'verity')->assertJsonPath('certificate.drawNow', true)
            ->assertJsonPath('certificate.drandRound', self::ROUND);
    }

    public function test_method_b_external_draw_seals_then_records_the_ticket_and_livestream(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $c = Competition::create([
            'slug' => 'stream-car', 'title' => 'Stream car', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 1000, 'bundles' => [],
            'total_tickets' => 50, 'max_per_person' => 20, 'closes_at' => '2026-10-30 20:00:00', 'draw_at' => '2026-10-30 20:30:00',
            'postal_closes_at' => '2026-10-28 20:00:00', 'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ]);
        // Chosen before publish, fixed after.
        $this->actingAs($this->admin)->putJson("/api/admin/competitions/{$c->id}", ['drawMethod' => 'external'])->assertOk()->assertJsonPath('data.drawMethod', 'external');
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/publish")->assertOk();
        $this->actingAs($this->admin)->putJson("/api/admin/competitions/{$c->id}", ['drawMethod' => 'verity'])->assertStatus(422);
        $this->assertSame('external', app(\App\Services\Draws\VerityDrawEngine::class)->verityCompetition($c->fresh())->draw_method);

        $this->buy($c, 3, 'a@example.com');
        $this->buy($c, 2, 'b@example.com');

        Carbon::setTestNow('2026-10-30 19:30:00');
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-now")->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-entries")->assertOk();
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $this->assertSame('external', $draw->method);
        $this->assertNull($draw->beacon_round);
        $this->assertSame(5, (int) $draw->entries_count);

        // The entry list to draw from: ticket, first name + initial, source; named with the sealed fingerprint.
        $csv = $this->actingAs($this->admin)->get("/api/admin/draws/{$draw->id}/entries.csv")->assertOk();
        $this->assertSame($draw->entries_hash, $csv->headers->get('X-Entries-Hash'));
        $lines = array_values(array_filter(explode("\n", $csv->streamedContent())));
        $this->assertSame('ticket,name,source', $lines[0]);
        $this->assertCount(6, $lines);
        $this->assertStringContainsString('Alex S', $lines[1]);

        // Verity never draws it.
        Carbon::setTestNow('2026-10-30 21:00:00');
        $this->artisan('verity:process-due')->assertSuccessful();
        $this->assertSame('prepared', $draw->fresh()->status);

        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 49, 'livestreamUrl' => 'https://youtube.com/live/x'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 4, 'livestreamUrl' => 'http://youtube.com/live/x'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 4, 'livestreamUrl' => 'https://www.youtube.com/live/abc123'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 2, 'livestreamUrl' => 'https://www.youtube.com/live/abc123'])->assertStatus(422);

        $draw->refresh();
        $this->assertSame('drawn', $draw->status);
        $this->assertSame(4, (int) $draw->winning_number);
        $this->assertSame('https://www.youtube.com/live/abc123', $draw->livestream_url);
        $this->assertSame(1, Winner::where('draw_id', $draw->id)->count());

        $this->actingAs($this->admin)->postJson("/api/admin/draws/{$draw->id}/publish")->assertOk();
        $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk()
            ->assertJsonPath('certificate.method', 'external')
            ->assertJsonPath('certificate.livestreamUrl', 'https://www.youtube.com/live/abc123')
            ->assertJsonPath('certificate.entriesHash', $draw->entries_hash)
            ->assertJsonPath('certificate.drandRound', null);
    }

    public function test_only_admins_close_or_record(): void
    {
        $c = $this->publishedCompetition();
        $staff = User::factory()->create(['role' => 'staff']);
        foreach (['close-now', 'close-entries', 'external-result'] as $action) {
            $this->actingAs($staff)->postJson("/api/admin/competitions/{$c->id}/{$action}")->assertForbidden();
        }
        $this->assertSame(0, Order::count());
    }
}
