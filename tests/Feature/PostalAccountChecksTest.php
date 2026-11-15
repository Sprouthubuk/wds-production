<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Free online entry refuses a closed, suspended, self-excluded or paused account (assertCanSpend). Postal entry, the
 * other free route, must too: otherwise self-exclusion can be got round with a stamp.
 */
class PostalAccountChecksTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
        Carbon::setTestNow('2026-12-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_postal_entries_from_blocked_accounts_are_rejected_and_others_are_allocated(): void
    {
        $c = $this->publishedCompetition([
            'ticket_price_pence' => 1495, 'bundles' => [], 'total_tickets' => 100, 'max_per_person' => 10,
            'closes_at' => '2026-12-24 18:00:00', 'draw_at' => '2026-12-24 20:00:00', 'postal_entry_enabled' => true,
        ]);
        $staff = User::factory()->create(['role' => 'staff']);
        $cases = [
            'closed@example.com' => [['closed_at' => now()->subDay()], 'account is closed'],
            'suspended@example.com' => [['suspended_at' => now()->subDay()], 'account is suspended'],
            'excluded@example.com' => [['self_excluded_until' => now()->addMonths(6)], 'self-excluded until'],
            'paused@example.com' => [['break_until' => now()->addDays(3)], 'on a break until'],
        ];
        foreach ($cases as $email => [$attrs, $reason]) {
            User::factory()->create(['email' => $email] + $attrs);
            $this->actingAs($staff)->postJson('/api/admin/postal', [
                'competitionId' => $c->id, 'name' => 'Sam Lee', 'address' => '2 High St', 'dateOfBirth' => '1990-01-01',
                'email' => strtoupper($email), 'receivedOn' => '2026-12-10T09:00:00Z',
            ])->assertCreated()->assertJsonPath('status', 'rejected')
                ->assertJsonPath('message', fn ($m) => str_contains($m, $reason));
        }
        $this->assertSame(0, Ticket::count());

        // An ended break or exclusion, and someone with no account, are fine.
        User::factory()->create(['email' => 'back@example.com', 'break_until' => now()->subDay(), 'self_excluded_until' => now()->subDay()]);
        foreach (['back@example.com', 'guest@example.com'] as $email) {
            $this->actingAs($staff)->postJson('/api/admin/postal', [
                'competitionId' => $c->id, 'name' => 'Sam Lee', 'address' => '2 High St', 'dateOfBirth' => '1990-01-01',
                'email' => $email, 'receivedOn' => '2026-12-10T09:00:00Z',
            ])->assertCreated()->assertJsonPath('status', 'allocated');
        }
        $this->assertSame(2, Ticket::count());
    }
}
