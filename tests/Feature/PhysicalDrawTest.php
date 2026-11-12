<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Draws\VerityDrawEngine;
use App\Services\Payments\PaymentResult;
use App\Support\ClientDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Sprouthub\Verity\Services\DrawService as VerityDraws;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Physical draw (numbered tokens): Verity seals the entry list at the close; the winner is drawn by hand on
 * the livestream with an independent witness and recorded. No random number tool link to give.
 */
class PhysicalDrawTest extends TestCase
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

    public function test_a_physical_draw_needs_no_link_seals_the_list_and_prints_the_chip_sheet(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $c = Competition::create([
            'slug' => 'token-car', 'title' => 'Token car', 'images' => [['src' => '/images/prize.jpg', 'alt' => 'The prize']], 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 1000, 'bundles' => [],
            'total_tickets' => 150, 'max_per_person' => null, 'closes_at' => '2026-10-30 20:00:00', 'draw_at' => '2026-10-30 20:30:00',
            'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ]);
        $this->actingAs($this->admin)->putJson("/api/admin/competitions/{$c->id}", ['drawMethod' => 'physical'])->assertOk()
            ->assertJsonPath('data.drawMethod', 'physical')->assertJsonPath('data.drawTool', Competition::PHYSICAL_TOOL);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/publish")->assertOk();

        $vc = app(VerityDrawEngine::class)->verityCompetition($c->fresh());
        $this->assertSame('external', $vc->draw_method);
        $this->assertSame(Competition::PHYSICAL_TOOL, $vc->externalTool());
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/draw-tool", ['name' => 'Coin', 'url' => 'https://example.com/coin'])->assertStatus(422);

        $a = $this->buy($c, 3, 'a@example.com');   // 1-3
        $b = $this->buy($c, 2, 'b@example.com');   // 4-5, refunded while open: withdrawn
        $this->buy($c, 2, 'c@example.com');        // 6-7
        $this->actingAs($this->admin)->postJson("/api/admin/orders/{$b->id}/refunded", ['void_tickets' => true])->assertOk();

        // Not before the close: the list can still change.
        $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/chip-sheet")->assertStatus(422);

        Carbon::setTestNow('2026-10-30 19:30:00');
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/close-entries")->assertOk();
        $draw = Draw::where('competition_id', $c->id)->firstOrFail();
        $vc->refresh();

        $sheet = $this->actingAs($this->admin)->getJson("/api/admin/competitions/{$c->id}/chip-sheet")->assertOk()->json('data');
        $this->assertSame(['001', '002', '003', '006', '007'], $sheet['tickets']);   // sold only, the withdrawn ones gone
        $this->assertSame(5, $sheet['count']);
        $this->assertSame((int) $draw->entries_count, $sheet['count']);
        $this->assertSame($vc->entries_hash, $sheet['entriesHash']);
        $this->assertSame($draw->entries_hash, $sheet['entriesHash']);
        $this->assertSame(VerityDraws::externalSeal($vc->ref, $vc->entries_hash, Competition::PHYSICAL_TOOL['name'], Competition::PHYSICAL_TOOL['url']),
            VerityDraws::closeRecord($vc)->payload['external_seal']);

        $csv = $this->actingAs($this->admin)->get("/api/admin/competitions/{$c->id}/chip-sheet?format=csv")->assertOk();
        $this->assertSame($vc->entries_hash, $csv->headers->get('X-Entries-Hash'));
        $lines = array_values(array_filter(explode("\n", trim($csv->streamedContent()))));
        $this->assertSame('"valid tickets",5', $lines[2]);
        $this->assertSame(['ticket', '001', '002', '003', '006', '007'], array_slice($lines, -6));

        $row = collect($this->actingAs($this->admin)->getJson('/api/admin/draws')->json())->firstWhere('competitionId', $c->id);
        $this->assertSame(['external', 'physical'], [$row['method'], $row['externalKind']]);

        // Drawn by hand: a ticket that isn't on the sealed list is refused.
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 4, 'livestreamUrl' => 'https://www.youtube.com/live/abc'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/admin/competitions/{$c->id}/external-result", ['ticket' => 6, 'livestreamUrl' => 'https://www.youtube.com/live/abc'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/draws/{$draw->id}/publish")->assertOk();

        $this->getJson("/api/draws/{$draw->id}/certificate")->assertOk()
            ->assertJsonPath('certificate.method', 'external')
            ->assertJsonPath('certificate.externalKind', 'physical')
            ->assertJsonPath('certificate.statement', 'Drawn by hand from numbered tokens on a live stream, supervised by an independent witness. Verity verified the sealed entry list and that the winning ticket was on it.');
        $listed = collect($this->getJson('/api/draws')->json('data'))->firstWhere('id', $draw->id);
        $this->assertSame('physical', $listed['externalKind']);
        $this->assertNotNull($a);
    }

    public function test_the_three_december_drafts_are_physical_draws(): void
    {
        $this->artisan('wds:client-drafts --apply')->assertSuccessful();
        foreach ([ClientDrafts::COILOVERS, ClientDrafts::ENGINE_TABLE] as $slug) {
            $this->assertSame('physical', Competition::where('slug', $slug)->value('draw_method'));
        }
        $this->assertSame(Competition::DRAW_PHYSICAL, ClientDrafts::confirmed()[ClientDrafts::MAIN_EVENT]['draw_method']);
    }

    public function test_only_staff_see_the_chip_sheet(): void
    {
        $c = $this->publishedCompetition();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->getJson("/api/admin/competitions/{$c->id}/chip-sheet")->assertForbidden();
    }

    private function buy(Competition $c, int $qty, string $email): Order
    {
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => $email, 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => $qty]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        return Order::where('reference', $ref)->firstOrFail();
    }
}
