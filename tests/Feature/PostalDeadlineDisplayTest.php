<?php

namespace Tests\Feature;

use App\Mail\PostalEntryLogged;
use App\Models\Competition;
use App\Models\User;
use App\Services\PostalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Closing 24 December 2026 at 7:00pm UK time with no postal override: the postal deadline is 48 hours
 * earlier, "Tuesday 22 December 2026, 7:00pm", on the API and in the emails (web/tests/postal-deadline.test.mjs
 * checks the admin hint, the competition page and the Free Postal Entry page with the same data). A summer close
 * (BST) checks it isn't a UTC/UK mix-up.
 */
class PostalDeadlineDisplayTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        $this->tz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_christmas_eve_postal_deadline_is_tuesday_22_december_7pm_everywhere(): void
    {
        // The API stores and sends UTC (config/app.php pins 'UTC' at boot, whatever the server's timezone) and
        // formats in Europe/London; the website formats in Europe/London whatever its own TZ (the web test runs it in New York).
        $this->assertSame('UTC', config('app.timezone'));
        Carbon::setTestNow('2026-12-01 12:00:00');
        $admin = User::factory()->create(['role' => 'admin']);

        // Created through the admin form: "24/12/2026 19:00" UK time is sent as 2026-12-24T19:00:00.000Z (GMT).
        $id = $this->actingAs($admin)->postJson('/api/admin/competitions', [
            'title' => 'Coilovers', 'category' => 'parts', 'ticketPrice' => 2, 'totalTickets' => 1000,
            'closesAt' => '2026-12-24T19:00:00.000Z', 'drawAt' => '2026-12-24T19:30:00.000Z', 'postalClosesAt' => null, 'postalEntryEnabled' => true,
            'images' => [['src' => '/images/p.jpg', 'alt' => 'P']],
        ])->assertCreated()->json('data.id');
        $c = Competition::findOrFail($id);
        $this->assertNull($c->postal_closes_at);

        $expected = 'Tuesday 22 December 2026, 7:00pm';
        $this->assertSame('2026-12-22T19:00:00+00:00', $this->actingAs($admin)->getJson("/api/admin/competitions/{$id}")->json('data.postalClosesAt'));
        $this->assertSame($expected, Competition::ukDeadline($c->postalClosesAt()));
        $this->assertSame("Postal entries must be received at least 48 hours before close: by {$expected} (UK time).", $c->postalDeadlineSentence());

        // Published: the public API gives the same moment to the competition page and the Free Postal Entry page.
        $this->actingAs($admin)->postJson("/api/admin/competitions/{$id}/publish")->assertOk();
        $this->app['auth']->forgetGuards();
        $public = $this->getJson("/api/competitions/{$c->fresh()->slug}")->assertOk()->json('data');
        $this->assertSame('2026-12-22T19:00:00+00:00', $public['postalClosesAt']);
        $this->assertNull($public['postalClosesAtCustom']);
        $listed = collect($this->getJson('/api/competitions')->json('data'))->firstWhere('id', $id);
        $this->assertSame('2026-12-22T19:00:00+00:00', $listed['postalClosesAt']);

        // The postal email (HTML and text).
        $entry = app(PostalEntryService::class)->log($c->fresh(), [
            'name' => 'Alex Smith', 'address' => '1 High St', 'date_of_birth' => '1990-05-05', 'email' => 'alex@example.com', 'received_on' => '2026-12-10 09:00:00',
        ], User::factory()->create(['role' => 'staff']));
        $mail = new PostalEntryLogged($entry);
        $this->assertStringContainsString($expected, $mail->render());
        $this->assertStringContainsString($expected, view('mail.postal-entry-logged-text', $mail->content()->with)->render());
    }

    public function test_a_summer_close_is_shown_in_uk_summer_time(): void
    {
        // 1 July 2026 7:00pm BST = 18:00 UTC; the deadline is 29 June, 7:00pm UK time (not 6:00pm).
        $c = new Competition(['closes_at' => '2026-07-01 18:00:00']);
        $this->assertSame('Monday 29 June 2026, 7:00pm', Competition::ukDeadline($c->postalClosesAt()));
    }
}
