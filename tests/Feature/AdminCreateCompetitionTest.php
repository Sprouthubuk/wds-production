<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admin → New competition: what the form sends (no status) creates a draft. */
class AdminCreateCompetitionTest extends TestCase
{
    use RefreshDatabase;

    /** The body the admin form posts for a new competition: it never sends a status. */
    private function formBody(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Engine table', 'slug' => 'engine-table', 'strapline' => '', 'description' => ['A table made from an engine.'],
            'category' => 'merch', 'badge' => null, 'buildVideoUrl' => null, 'cashAlternative' => null, 'images' => [], 'specs' => [], 'bundles' => [],
            'totalTickets' => 500, 'maxPerPerson' => null, 'opensAt' => null, 'closesAt' => '2026-12-24T19:00:00.000Z', 'postalClosesAt' => null,
            'drawAt' => null, 'postalEntryEnabled' => true, 'eventSalesEnabled' => false, 'drawMethod' => 'physical', 'ticketPrice' => 2.5,
        ], $overrides);
    }

    public function test_a_new_competition_saves_as_a_draft_without_a_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $id = $this->actingAs($admin)->postJson('/api/admin/competitions', $this->formBody())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $c = Competition::findOrFail($id);
        $this->assertSame('draft', $c->status);
        $this->assertNull($c->max_per_person);
        $this->assertFalse($c->isPublished());

        // A status other than draft can't be set on create: a competition goes live only through Publish.
        $this->actingAs($admin)->postJson('/api/admin/competitions', $this->formBody(['slug' => 'x2', 'status' => 'live']))
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAs($admin)->postJson('/api/admin/competitions', $this->formBody(['slug' => 'x3', 'status' => 'draft']))->assertCreated();

        // No maximum per person unless one is entered (the form starts empty; omitted = no limit too).
        $body = $this->formBody(['slug' => 'x5']);
        unset($body['maxPerPerson']);
        $id5 = $this->actingAs($admin)->postJson('/api/admin/competitions', $body)->assertCreated()->assertJsonPath('data.maxPerPerson', null)->json('data.id');
        $this->assertNull(Competition::findOrFail($id5)->max_per_person);

        // Errors come back as a message the admin form shows in its error banner.
        $this->actingAs($admin)->postJson('/api/admin/competitions', $this->formBody(['slug' => 'x4', 'title' => '']))
            ->assertStatus(422)->assertJsonPath('errors.title.0', 'The title field is required.');
    }
}
