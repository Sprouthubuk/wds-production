<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Sprouthub\Verity\Mail\PlainNotice;
use Tests\TestCase;

class VerityCheckAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['verity.alert_recipients' => ['ops@example.test'], 'cache.default' => 'array']);
    }

    public function test_a_fail_row_emails_the_alert_recipients_once_and_never_fails_the_command(): void
    {
        config(['verity.seed_key' => null]); // FAIL row: seeds not sealed with their own key

        $this->artisan('wds:verity-check-alert')->expectsOutputToContain('FAIL row(s)')->assertSuccessful();
        Mail::assertSent(PlainNotice::class, fn ($m) => $m->hasTo('ops@example.test'));
        $this->assertSame(1, Mail::sent(PlainNotice::class)->count());

        // Still failing an hour later: no second email (the reminder comes after 24 hours).
        $this->artisan('wds:verity-check-alert')->expectsOutputToContain('already alerted')->assertSuccessful();
        $this->assertSame(1, Mail::sent(PlainNotice::class)->count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('wds:verity-check-alert')->assertSuccessful();
    }
}
