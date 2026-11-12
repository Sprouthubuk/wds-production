<?php

namespace Tests\Feature;

use App\Http\Resources\SettingsPayload;
use App\Mail\PostalEntryLogged;
use App\Models\Competition;
use App\Models\Setting;
use App\Models\User;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

class PostalCloseTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 12:00:00');
        $this->fakeVerity();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function competition(array $overrides = []): Competition
    {
        return $this->publishedCompetition(array_merge([
            'slug' => 'test', 'title' => 'Test car', 'category' => 'cars',
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 10,
            'closes_at' => '2026-12-24 18:00:00', 'draw_at' => '2026-12-24 20:00:00', 'postal_entry_enabled' => true,
        ], $overrides));
    }

    private function postalEntry(Competition $c, string $receivedOn, string $email = 'alex@example.com')
    {
        return app(PostalEntryService::class)->log($c, [
            'name' => 'Alex Smith', 'address' => '1 High St', 'date_of_birth' => '1990-05-05',
            'email' => $email, 'received_on' => $receivedOn,
        ], User::factory()->create(['role' => 'staff']));
    }

    public function test_postal_close_defaults_to_48_hours_before_closing(): void
    {
        $c = $this->competition();

        $this->assertNull($c->postal_closes_at);
        $this->assertSame('2026-12-22 18:00:00', $c->postalClosesAt()->toDateTimeString());
        $this->assertSame(
            'Postal entries must be received at least 48 hours before close: by 6:00pm on Tuesday 22 December 2026 (UK time).',
            $c->postalDeadlineSentence(),
        );
    }

    public function test_an_explicit_postal_close_wins(): void
    {
        $c = $this->competition(['postal_closes_at' => '2026-12-20 09:00:00']);

        $this->assertSame('2026-12-20 09:00:00', $c->postalClosesAt()->toDateTimeString());
        $this->assertSame('Postal entries must be received by 9:00am on Sunday 20 December 2026 (UK time).', $c->postalDeadlineSentence());
    }

    public function test_received_47h59m_before_close_is_rejected(): void
    {
        Mail::fake();
        $c = $this->competition(); // closes 2026-12-24 18:00 UTC

        $late = $this->postalEntry($c, '2026-12-22 18:01:00');

        $this->assertSame('rejected', $late->status);
        $this->assertSame('Received less than 48 hours before close', $late->rejection_reason);
        $this->assertNull($late->ticket);
        $this->assertSame('2026-12-22 18:01:00', $late->received_at->toDateTimeString());
        Mail::assertQueued(PostalEntryLogged::class, fn ($m) => $m->entry->is($late) && $m->hasTo('alex@example.com'));
    }

    public function test_received_48h01m_before_close_is_accepted(): void
    {
        Mail::fake();
        $c = $this->competition();

        $entry = $this->postalEntry($c, '2026-12-22 17:59:00');

        $this->assertSame('allocated', $entry->status);
        $this->assertNull($entry->rejection_reason);
        $this->assertNotNull($entry->ticket);
        Mail::assertQueued(PostalEntryLogged::class, fn ($m) => $m->entry->is($entry));
    }

    public function test_exactly_48_hours_before_close_is_accepted(): void
    {
        $this->assertSame('allocated', $this->postalEntry($this->competition(), '2026-12-22 18:00:00')->status);
    }

    public function test_after_an_admin_postal_close_the_reason_says_so(): void
    {
        $c = $this->competition(['postal_closes_at' => '2026-12-20 09:00:00']);

        $late = $this->postalEntry($c, '2026-12-20 09:01:00');

        $this->assertSame('rejected', $late->status);
        $this->assertSame('Received after the postal closing date', $late->rejection_reason);
    }

    public function test_the_postal_emails_show_the_ticket_or_reason_and_the_deadline(): void
    {
        $c = $this->competition();
        $in = $this->postalEntry($c, '2026-12-22 09:00:00', 'a@example.com');
        $out = $this->postalEntry($c, '2026-12-23 09:00:00', 'b@example.com');

        $html = (new PostalEntryLogged($in))->render();
        $this->assertStringContainsString('#'.$in->ticket->display(100), $html);
        $this->assertStringContainsString('at least 48 hours before close: by 6:00pm on Tuesday 22 December 2026', $html);
        $this->assertStringContainsString('Company no. 13330886', $html);

        $rejected = new PostalEntryLogged($out);
        $this->assertStringContainsString('Received less than 48 hours before close', $rejected->render());
        $content = $rejected->content();
        $text = view($content->text, $content->with)->render();
        $this->assertStringContainsString('Reason: Received less than 48 hours before close.', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_staff_log_the_time_an_envelope_arrived(): void
    {
        Carbon::setTestNow('2026-12-22 18:30:00');
        $c = $this->competition();
        $staff = User::factory()->create(['role' => 'staff']);
        $entry = ['competitionId' => $c->id, 'name' => 'Sam Lee', 'address' => '2 High St', 'dateOfBirth' => '1990-01-01', 'email' => 'sam@example.com'];

        $this->actingAs($staff)->postJson('/api/admin/postal', $entry + ['receivedOn' => '2026-12-22T17:59:00Z'])
            ->assertCreated()->assertJsonPath('status', 'allocated');
        $this->actingAs($staff)->postJson('/api/admin/postal', ['email' => 'kim@example.com'] + $entry + ['receivedOn' => '2026-12-22T18:01:00Z'])
            ->assertCreated()->assertJsonPath('status', 'rejected')
            ->assertJsonPath('message', 'Entry rejected: Received less than 48 hours before close.');
        $this->actingAs($staff)->postJson('/api/admin/postal', ['email' => 'jo@example.com'] + $entry + ['receivedOn' => '2026-12-23T12:00:00Z'])
            ->assertStatus(422); // in the future
    }

    public function test_the_free_entry_address_defaults_to_knoll_house(): void
    {
        $this->assertSame(
            'WDS Competitions – Free Entry, Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY, United Kingdom',
            implode(', ', SettingsPayload::get()['postalEntryAddress']),
        );
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.postalEntryAddress.1', 'Knoll House');
    }

    public function test_the_seeder_replaces_a_placeholder_address_but_keeps_a_real_one(): void
    {
        Setting::put('site', ['postalEntryAddress' => ['WDS Competitions — Free Entry', '[POSTAL ADDRESS LINE 1]']]);
        $this->seed();
        $this->assertSame('Knoll House', Setting::get('site')['postalEntryAddress'][1]);

        Setting::put('site', ['postalEntryAddress' => ['PO Box 1', 'Town']]);
        $this->seed();
        $this->assertSame(['PO Box 1', 'Town'], Setting::get('site')['postalEntryAddress']);
    }

    public function test_the_api_shows_the_postal_close(): void
    {
        $c = $this->competition();

        $this->getJson("/api/competitions/{$c->slug}")
            ->assertOk()
            ->assertJsonPath('data.postalClosesAt', Carbon::parse('2026-12-22 18:00:00')->toIso8601String());
    }

    public function test_admin_can_set_a_postal_close_no_later_than_the_close(): void
    {
        $c = $this->competition();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson("/api/admin/competitions/{$c->id}", ['closesAt' => '2026-12-24 18:00:00', 'postalClosesAt' => '2026-12-25 09:00:00'])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$c->id}", ['closesAt' => '2026-12-24 18:00:00', 'postalClosesAt' => '2026-12-19 09:00:00'])
            ->assertOk()
            ->assertJsonPath('data.postalClosesAt', Carbon::parse('2026-12-19 09:00:00')->toIso8601String());
    }
}
