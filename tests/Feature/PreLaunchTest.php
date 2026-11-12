<?php

namespace Tests\Feature;

use App\Http\Resources\CompetitionTeaser;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function draft(array $overrides = []): Competition
    {
        return Competition::create(array_merge([
            'slug' => 'bmw-e46', 'title' => 'BMW E46 330Ci Clubsport', 'category' => 'cars', 'status' => 'draft',
            'images' => [['src' => '/images/e46.jpg', 'alt' => 'The car'], ['src' => '/images/2.jpg', 'alt' => '']],
            'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000]], 'total_tickets' => 750,
            'max_per_person' => 50, 'closes_at' => now()->addMonth(), 'draw_at' => now()->addMonth()->addHours(2),
            'postal_entry_enabled' => true, 'cash_alternative_pence' => 2000000,
        ], $overrides));
    }

    public function test_a_draft_is_not_listed_or_shown_as_a_competition(): void
    {
        $this->draft();

        $this->getJson('/api/competitions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/competitions/bmw-e46')->assertNotFound();
    }

    public function test_the_featured_draft_is_teased_with_title_image_and_draw_date_only(): void
    {
        $this->draft(['slug' => 'later', 'title' => 'Later', 'draw_at' => now()->addMonths(3), 'closes_at' => now()->addMonths(3)]);
        $c = $this->draft(['badge' => 'Main event']);

        $json = $this->getJson('/api/upcoming')->assertOk()->json('data');

        $this->assertSame(CompetitionTeaser::FIELDS, array_keys($json));
        $this->assertSame('bmw-e46', $json['slug']);
        $this->assertSame('BMW E46 330Ci Clubsport', $json['title']);
        $this->assertSame(['src' => '/images/e46.jpg', 'alt' => 'The car'], $json['image']);
        $this->assertSame($c->draw_at->toIso8601String(), $json['drawAt']);
        $this->assertNoDraftNumbers($this->getJson('/api/upcoming')->getContent());
    }

    public function test_a_draft_slug_gets_the_teaser_and_anything_else_a_404(): void
    {
        $this->draft();

        $body = $this->getJson('/api/upcoming/bmw-e46')->assertOk()->assertJsonPath('data.title', 'BMW E46 330Ci Clubsport')->getContent();
        $this->assertNoDraftNumbers($body);
        $this->getJson('/api/upcoming/nope')->assertNotFound();
    }

    public function test_live_and_past_competitions_are_not_teased(): void
    {
        $this->draft(['status' => 'live']);
        $this->draft(['slug' => 'old', 'draw_at' => now()->subDay(), 'closes_at' => now()->subDays(2)]);

        $this->getJson('/api/upcoming')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/upcoming/bmw-e46')->assertNotFound();
        $this->getJson('/api/upcoming/old')->assertNotFound();
    }

    private function assertNoDraftNumbers(string $body): void
    {
        foreach (['1495', '14.95', '750', '4000', 'ticketPrice', 'totalTickets', 'bundles', 'maxPerPerson', 'cashAlternative', '"id"'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
