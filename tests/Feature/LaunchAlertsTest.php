<?php

namespace Tests\Feature;

use App\Jobs\SendLaunchAlert;
use App\Mail\LaunchAlert;
use App\Mail\LaunchAlertConfirmation;
use App\Models\Competition;
use App\Models\LaunchAlertSend;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\CompetitionLauncher;
use App\Services\Draws\VerityDrawEngine;
use App\Services\LaunchAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Launch alerts: sign-up (email only or one click), confirmation, one deduplicated launch email, unsubscribe, Admin. */
class LaunchAlertsTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comingSoon(string $slug = 'soon'): Competition
    {
        return app(VerityDrawEngine::class)->publish(Competition::create([
            'slug' => $slug, 'title' => ucfirst($slug).' prize', 'category' => 'cars', 'status' => 'draft',
            'images' => [['src' => '/images/e46-clubsport-34.jpg', 'alt' => 'Car']],
            'ticket_price_pence' => 500, 'bundles' => [], 'total_tickets' => 495, 'max_per_person' => null,
            'opens_at' => '2026-12-01 09:00:00', 'closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00',
            'draw_method' => Competition::DRAW_PHYSICAL, 'postal_entry_enabled' => true,
        ]));
    }

    public function test_email_only_sign_up_stores_consent_and_sends_one_confirmation(): void
    {
        $c = $this->comingSoon();

        $this->postJson('/api/newsletter', ['email' => ' Fan@Example.com ', 'source' => 'home'])->assertOk()->assertJsonPath('created', true);
        $this->postJson('/api/newsletter', ['email' => 'fan@example.com'])->assertOk()->assertJsonPath('created', false);
        $this->postJson('/api/newsletter', ['email' => 'fan@example.com', 'competitionId' => $c->id, 'source' => 'competition'])->assertOk()
            ->assertJsonPath('message', "You're on the list. We'll email you once when Soon prize opens for entries.");

        $rows = NewsletterSubscriber::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([null, $c->id], $rows->pluck('competition_id')->all());
        $this->assertSame('fan@example.com', $rows[0]->email);
        $this->assertSame(NewsletterSubscriber::CONSENT_TEXT, $rows[0]->consent_text);
        $this->assertNotNull($rows[0]->consent_at);
        $this->assertSame(['home', 'competition'], $rows->pluck('source')->all());
        Mail::assertQueued(LaunchAlertConfirmation::class, 2);
        Mail::assertQueued(LaunchAlertConfirmation::class, fn ($m) => $m->hasFrom('noreply@wdscompetitions.co.uk') && $m->hasTo('fan@example.com'));

        $this->postJson('/api/newsletter', [])->assertStatus(422);
    }

    public function test_a_signed_in_customer_signs_up_with_one_click(): void
    {
        $c = $this->comingSoon();
        $user = User::factory()->create(['role' => 'customer', 'email' => 'member@example.com']);
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/newsletter', ['competitionId' => $c->id])->assertOk();
        $row = NewsletterSubscriber::sole();
        $this->assertSame('member@example.com', $row->email);
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_the_launch_email_goes_once_per_person_to_the_competition_and_site_wide_lists(): void
    {
        Queue::fake();
        $c = $this->comingSoon();
        $other = $this->comingSoon('other');
        $alerts = app(LaunchAlerts::class);
        $alerts->subscribe('both@example.com', null, 'home');
        $alerts->subscribe('BOTH@example.com', $c, 'competition');       // on both lists: one email
        $alerts->subscribe('site@example.com', null, 'home');
        $alerts->subscribe('mine@example.com', $c, 'competition');
        $alerts->subscribe('elsewhere@example.com', $other, 'competition'); // another competition's list only
        $alerts->subscribe('gone@example.com', null, 'home');
        $alerts->unsubscribe(NewsletterSubscriber::where('email', 'gone@example.com')->value('unsubscribe_token'));
        User::factory()->create(['email' => 'excluded@example.com', 'self_excluded_until' => now()->addYear()]);
        $alerts->subscribe('excluded@example.com', null, 'home');
        // An existing "Get first access" sign-up from before this change counts as site-wide.
        DB::table('newsletter_subscribers')->insert(['email' => 'early@example.com', 'consent_at' => now()->subWeek(), 'source' => 'first_access',
            'unsubscribe_token' => 'early-token', 'list_key' => '0:early@example.com', 'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()]);

        Carbon::setTestNow('2026-12-01 09:00:00');
        $r = app(CompetitionLauncher::class)->open($c, null);

        $this->assertEqualsCanonicalizing(['both@example.com', 'site@example.com', 'mine@example.com', 'early@example.com'],
            LaunchAlertSend::where('competition_id', $c->id)->pluck('email')->all());
        $this->assertSame(4, $r['queued']);
        Queue::assertPushed(SendLaunchAlert::class, 4);
        // Rate-limited: 2 a second, so the 3rd and 4th are delayed by a second.
        Queue::assertPushed(SendLaunchAlert::class, fn ($job) => $job->delay && Carbon::instance($job->delay)->gt(now()));

        // Never twice: queueing again (a retried open) adds nothing.
        $this->assertSame(0, $alerts->queueLaunch($c->fresh()));
        $this->assertSame(4, LaunchAlertSend::where('competition_id', $c->id)->count());
    }

    public function test_the_job_sends_with_one_click_unsubscribe_and_logs_it(): void
    {
        $c = $this->comingSoon();
        $sub = app(LaunchAlerts::class)->subscribe('fan@example.com', $c, 'competition')['subscriber'];
        Carbon::setTestNow('2026-12-01 09:00:00');
        Queue::fake();
        app(CompetitionLauncher::class)->open($c, null);
        $send = LaunchAlertSend::sole();

        (new SendLaunchAlert($send->id))->handle();
        (new SendLaunchAlert($send->id))->handle(); // a retry sends nothing more

        Mail::assertSent(LaunchAlert::class, 1);
        Mail::assertSent(LaunchAlert::class, function (LaunchAlert $m) use ($sub) {
            $headers = $m->headers()->text;
            $html = $m->render();

            return $m->hasTo('fan@example.com') && $m->hasFrom('noreply@wdscompetitions.co.uk')
                && str_contains($headers['List-Unsubscribe'], "/api/launch-alerts/unsubscribe/{$sub->unsubscribe_token}")
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
                && str_contains($html, '1 in 495') && str_contains($html, "/unsubscribe/{$sub->unsubscribe_token}");
        });
        $this->assertSame('sent', $send->fresh()->status);
        $this->assertNotNull($send->fresh()->sent_at);
    }

    public function test_unsubscribe_is_one_click_and_stops_every_list(): void
    {
        $c = $this->comingSoon();
        $alerts = app(LaunchAlerts::class);
        $token = $alerts->subscribe('fan@example.com', null, 'home')['subscriber']->unsubscribe_token;
        $alerts->subscribe('fan@example.com', $c, 'competition');

        $this->postJson('/api/launch-alerts/unsubscribe/nope')->assertNotFound();
        $this->postJson("/api/launch-alerts/unsubscribe/{$token}")->assertOk();
        $this->assertSame(0, NewsletterSubscriber::subscribed()->count());

        // Queued before unsubscribing: skipped at send time.
        Queue::fake();
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->assertSame(0, app(CompetitionLauncher::class)->open($c, null)['queued']);
    }

    public function test_admin_sees_counts_exports_csv_and_sends_a_test(): void
    {
        $c = $this->comingSoon();
        $alerts = app(LaunchAlerts::class);
        $alerts->subscribe('a@example.com', null, 'home');
        $alerts->subscribe('b@example.com', $c, 'competition');
        $alerts->subscribe('a@example.com', $c, 'card');

        $this->getJson('/api/admin/launch-alerts')->assertUnauthorized();
        $this->actingAs($this->admin)->getJson('/api/admin/launch-alerts')->assertOk()
            ->assertJsonPath('counts.subscribed', 3)->assertJsonPath('counts.people', 2)->assertJsonPath('counts.siteWide', 1)
            ->assertJsonPath('counts.competitions.0.launchAudience', 2)->assertJsonPath('meta.total', 3);

        $csv = $this->actingAs($this->admin)->get('/api/admin/launch-alerts/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Email,List,Source', $csv);
        $this->assertStringContainsString('b@example.com,"Soon prize",competition', $csv);

        Queue::fake();
        $this->actingAs($this->admin)->postJson('/api/admin/launch-alerts/test', ['competitionId' => $c->id])->assertOk();
        $send = LaunchAlertSend::where('kind', 'test')->sole();
        $this->assertSame($this->admin->email, $send->email);
        (new SendLaunchAlert($send->id))->handle();
        Mail::assertSent(LaunchAlert::class, fn ($m) => $m->test && str_starts_with($m->envelope()->subject, '[TEST]'));
        $this->assertSame(3, NewsletterSubscriber::subscribed()->count(), 'a test sends to nobody on the list');
    }
}
