<?php

namespace Tests\Feature;

use App\Mail\WinnerNotified;
use App\Models\Competition;
use App\Models\Winner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The optional cash alternative: set in pounds in Admin, shown on the site and in the winner email. */
class CashAlternativeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sets_it_in_pounds_and_the_api_returns_pounds(): void
    {
        $c = $this->competition(null);
        $this->assertNull($this->getJson("/api/competitions/{$c->slug}")->json('data.cashAlternative'));

        $c->update(['cash_alternative_pence' => 500000]);
        $this->assertEquals(5000, $this->getJson("/api/competitions/{$c->slug}")->json('data.cashAlternative'));
    }

    public function test_the_winner_email_offers_the_cash_alternative(): void
    {
        $mail = new WinnerNotified($this->winner($this->competition(500000)));
        $mail->assertSeeInHtml('(or £5,000 cash alternative)', false);
        $mail->assertSeeInText('(or £5,000 cash alternative)');
    }

    public function test_no_cash_alternative_no_mention(): void
    {
        $mail = new WinnerNotified($this->winner($this->competition(null)));
        $mail->assertDontSeeInHtml('cash alternative');
        $mail->assertDontSeeInText('cash alternative');
    }

    public function test_amounts_are_formatted_like_the_site(): void
    {
        $this->assertSame('£5,000', WinnerNotified::cashAlternative(500000));
        $this->assertSame('£500', WinnerNotified::cashAlternative(50000));
        $this->assertSame('£1,234.50', WinnerNotified::cashAlternative(123450));
        $this->assertNull(WinnerNotified::cashAlternative(null));
        $this->assertNull(WinnerNotified::cashAlternative(0));
    }

    private function competition(?int $cashPence): Competition
    {
        return Competition::create([
            'slug' => 'bmw-'.uniqid(), 'title' => 'BMW E46 330Ci Clubsport', 'category' => 'cars', 'status' => 'live', 'ticket_price_pence' => 1495,
            'bundles' => [], 'total_tickets' => 750, 'max_per_person' => 50, 'cash_alternative_pence' => $cashPence,
            'closes_at' => now()->addDays(5), 'draw_at' => now()->addDays(5)->addMinutes(30),
        ]);
    }

    private function winner(Competition $c): Winner
    {
        return Winner::create(['competition_id' => $c->id, 'display_name' => 'Sam T', 'prize' => $c->title]);
    }
}
