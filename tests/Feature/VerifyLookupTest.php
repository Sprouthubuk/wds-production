<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Draw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The /verify index lookup: a draw reference or certificate ID, as people type or paste it. */
class VerifyLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['verity.ref_prefix' => 'WDS-']);
    }

    /** @return array<string, array{0: string|null, 1: array<string, int|string>|null}> */
    public static function inputs(): array
    {
        return [
            'exact reference' => ['WDS-7KQ2MX', ['ref' => 'WDS-7KQ2MX']],
            'lower case' => ['wds-7kq2mx', ['ref' => 'WDS-7KQ2MX']],
            'spaces around and inside' => ['  wds - 7kq 2mx ', ['ref' => 'WDS-7KQ2MX']],
            'no dash' => ['wds7kq2mx', ['ref' => 'WDS-7KQ2MX']],
            'no prefix' => ['7kq2mx', ['ref' => 'WDS-7KQ2MX']],
            'tab and non-breaking space' => ["WDS-\t7KQ\u{00A0}2MX", ['ref' => 'WDS-7KQ2MX']],
            'certificate ID' => ['12', ['id' => 12]],
            'certificate ID with hash' => [' #12 ', ['id' => 12]],
            'pasted verify link' => ['https://wds-competitions.co.uk/verify/12', ['id' => 12]],
            'pasted certificate link' => ['https://api.example.com/api/draws/12/certificate', ['id' => 12]],
            'pasted link with a reference' => ['https://wds-competitions.co.uk/verify/wds-7kq2mx', ['ref' => 'WDS-7KQ2MX']],
            'empty' => ['   ', null],
            'null' => [null, null],
            'punctuation' => ['WDS-7KQ2MX!; DROP', null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_normalises_what_people_type_or_paste(?string $input, ?array $expected): void
    {
        $this->assertSame($expected, Draw::normaliseLookup($input));
    }

    public function test_finds_a_published_draw_by_reference_or_certificate_id(): void
    {
        $draw = $this->draw('WDS-7KQ2MX', 'published');

        foreach (['WDS-7KQ2MX', ' wds 7kq2mx ', (string) $draw->id, "#{$draw->id}"] as $q) {
            $this->getJson('/api/draws/lookup?q='.rawurlencode($q))->assertOk()
                ->assertJsonPath('data.id', $draw->id)->assertJsonPath('data.reference', 'WDS-7KQ2MX');
        }
    }

    public function test_unknown_or_unpublished_is_a_plain_not_found(): void
    {
        $unpublished = $this->draw('WDS-NOTYET', 'drawn');   // drawn but not published: nothing revealed early

        foreach (['WDS-ZZZZZZ', 'WDS-NOTYET', (string) $unpublished->id, '999999', 'nonsense!!'] as $q) {
            $this->getJson('/api/draws/lookup?q='.rawurlencode($q))->assertNotFound()
                ->assertJsonPath('message', 'We couldn\'t find a published draw with that reference.');
        }
        $this->getJson('/api/draws/lookup')->assertNotFound();
    }

    public function test_the_public_draw_list_carries_the_reference_and_method(): void
    {
        $this->draw('WDS-7KQ2MX', 'published', 'external');
        $row = collect($this->getJson('/api/draws')->assertOk()->json('data'))->firstWhere('reference', 'WDS-7KQ2MX');
        $this->assertSame('external', $row['method']);
    }

    private function draw(string $ref, string $status, string $method = 'verity'): Draw
    {
        $c = Competition::create([
            'slug' => strtolower($ref), 'title' => "Car {$ref}", 'category' => 'cars', 'status' => 'drawn', 'ticket_price_pence' => 500, 'bundles' => [],
            'total_tickets' => 50, 'max_per_person' => 10, 'closes_at' => now()->subDay(), 'draw_at' => now()->subDay(),
            'postal_closes_at' => now()->subDays(3), 'postal_entry_enabled' => true, 'verity_ref' => $ref,
        ]);

        return Draw::create([
            'competition_id' => $c->id, 'engine' => 'verity', 'status' => $status, 'entries_count' => 0, 'entries_hash' => hash('sha256', $ref), 'verity_ref' => $ref,
            'method' => $method, 'drawn_at' => now()->subDay(), 'published_at' => $status === 'published' ? now() : null,
        ]);
    }
}
