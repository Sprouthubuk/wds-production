<?php

namespace Tests\Feature;

use App\Exceptions\LimitReachedException;
use App\Models\Entrant;
use App\Models\Order;
use App\Models\User;
use App\Services\ResponsiblePlay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Daily, weekly and monthly spend limits run on UK days, not UTC ones (which are an hour out in summer). */
class SpendLimitUkDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(User $user, string $at, int $pence): void
    {
        Carbon::setTestNow($at);
        Order::create([
            'reference' => 'WDS-'.strtoupper(substr(md5($at), 0, 6)), 'access_key' => str_repeat('k', 40), 'email' => $user->email, 'name' => 'Test',
            'user_id' => $user->id, 'date_of_birth' => '1990-05-05', 'channel' => 'online', 'status' => 'paid', 'paid_at' => $at,
            'subtotal_pence' => $pence, 'bundle_saving_pence' => 0, 'discount_pence' => 0, 'credit_used_pence' => 0, 'total_pence' => $pence,
            'payment_provider' => 'fake', 'entrant_id' => Entrant::forEmail($user->email)->id,
        ]);
    }

    public function test_the_daily_limit_covers_the_whole_uk_day_in_summer(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'limit_daily_pence' => 1000]);
        // 00:15 BST on 16 July (23:15 UTC on the 15th): £10 spent, the whole daily limit.
        $this->paid($user, '2026-07-15 23:15:00', 1000);

        // 01:30 BST, the same UK day (a new UTC day): nothing left.
        Carbon::setTestNow('2026-07-16 00:30:00');
        try {
            app(ResponsiblePlay::class)->assertCanSpend($user, 500);
            $this->fail('A second day\'s spend was allowed within one UK day.');
        } catch (LimitReachedException $e) {
            $this->assertStringContainsString('daily', $e->getMessage());
        }

        // 23:30 BST on 15 July spending, then 00:30 BST on the 16th (still the 15th in UTC): a new UK day.
        $other = User::factory()->create(['email' => 'jo@example.com', 'limit_daily_pence' => 1000]);
        $this->paid($other, '2026-07-15 22:30:00', 1000);
        Carbon::setTestNow('2026-07-15 23:30:00');
        app(ResponsiblePlay::class)->assertCanSpend($other, 1000);
        $this->addToAssertionCount(1);
    }
}
