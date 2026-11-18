<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Order;
use App\Models\PostalEntry;
use App\Models\User;
use App\Support\ClientDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Paid competitions ask a multiple-choice skill question at checkout, alongside the free postal route:
 *  - the answer is checked on the server when the order is created (never trusted from the browser);
 *  - a wrong or missing answer creates nothing and starts no payment, with a friendly message, and can be retried;
 *  - the answer given is stored on the order;
 *  - free competitions and postal entries are unaffected;
 *  - the correct answer is never sent to the public site, and the question is locked once published.
 */
class SkillQuestionTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
    }

    private function paid(array $overrides = []): Competition
    {
        return $this->publishedCompetition($overrides + ['skill_question' => self::skillQ(), 'max_per_person' => null, 'bundles' => []]);
    }

    private function checkout(array $items, ?array $answers)
    {
        return $this->postJson('/api/checkout', [
            'customer' => ['email' => 'sam@example.com', 'name' => 'Sam Lee', 'phone' => '07700900123', 'dateOfBirth' => '1990-05-05'],
            'items' => $items, 'ageConfirmed' => true, 'termsAccepted' => true,
        ] + ($answers === null ? [] : ['skillAnswers' => $answers]));
    }

    public function test_a_wrong_or_missing_answer_is_rejected_on_the_server_and_nothing_is_created(): void
    {
        $c = $this->paid();
        $item = [['competitionId' => $c->id, 'qty' => 2]];

        foreach ([[$c->id => 0], [$c->id => 2], [$c->id => null], [], null] as $answers) {
            $this->checkout($item, $answers)->assertStatus(422)
                ->assertJsonPath('code', 'skill_wrong')->assertJsonPath('competitionId', $c->id)
                ->assertJsonPath('message', Competition::SKILL_WRONG);
        }
        // Not a number, or out of range: refused by validation, still nothing created.
        $this->checkout($item, [$c->id => 'Bayerische Motoren Werke'])->assertStatus(422);
        $this->checkout($item, [$c->id => 7])->assertStatus(422);

        $this->assertSame(0, Order::count(), 'no order, so no payment page and no reservation');
        $this->assertSame(0, $c->fresh()->tickets()->count());
    }

    public function test_the_correct_answer_passes_and_is_stored_on_the_order(): void
    {
        $c = $this->paid();
        // Wrong first, then try again with the right one.
        $this->checkout([['competitionId' => $c->id, 'qty' => 2]], [$c->id => 0])->assertStatus(422);
        $ref = $this->checkout([['competitionId' => $c->id, 'qty' => 2]], [$c->id => 1])->assertOk()->json('reference');

        $line = Order::where('reference', $ref)->firstOrFail()->lines()->sole();
        $this->assertSame('Bayerische Motoren Werke', $line->skill_answer);
    }

    public function test_each_competition_in_the_basket_is_checked_and_the_message_names_the_wrong_one(): void
    {
        $bmw = $this->paid(['title' => 'BMW E46']);
        $coil = $this->paid(['title' => 'Coilovers', 'skill_question' => ['question' => 'What do coilovers replace?', 'options' => ['The brakes', 'The exhaust', 'The suspension', 'The gearbox'], 'correct' => 2]]);
        $items = [['competitionId' => $bmw->id, 'qty' => 1], ['competitionId' => $coil->id, 'qty' => 1]];

        $this->checkout($items, [$bmw->id => 1, $coil->id => 0])->assertStatus(422)
            ->assertJsonPath('competitionId', $coil->id)->assertJsonPath('message', 'Coilovers: '.Competition::SKILL_WRONG);
        $this->assertSame(0, Order::count());

        $ref = $this->checkout($items, [(string) $bmw->id => '1', (string) $coil->id => 2])->assertOk()->json('reference');
        $this->assertEqualsCanonicalizing(['Bayerische Motoren Werke', 'The suspension'], Order::where('reference', $ref)->firstOrFail()->lines()->pluck('skill_answer')->all());
    }

    public function test_a_free_competition_has_no_skill_question(): void
    {
        // Even if one were saved on it, a free competition never asks it.
        $free = $this->publishedCompetition(['title' => 'Engine table', 'category' => 'merch', 'ticket_price_pence' => 0, 'bundles' => [], 'total_tickets' => 100,
            'max_per_person' => 1, 'event_sales_enabled' => false, 'postal_entry_enabled' => false, 'skill_question' => self::skillQ()]);
        $this->getJson("/api/competitions/{$free->slug}")->assertOk()->assertJsonPath('data.skillQuestion', null);

        $user = User::factory()->create(['role' => 'customer', 'email_verified_at' => now(), 'date_of_birth' => '1990-01-01']);
        $this->actingAs($user)->postJson("/api/competitions/{$free->id}/free-entry", ['ageConfirmed' => true, 'termsAccepted' => true])->assertCreated();
        $this->assertSame(1, $free->tickets()->count());
    }

    public function test_postal_entries_are_unaffected(): void
    {
        $c = $this->paid();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/admin/postal', [
            'competitionId' => $c->id, 'name' => 'Pat Post', 'address' => '1 High St, Camberley', 'dateOfBirth' => '1990-01-01',
            'email' => 'pat@example.com', 'receivedOn' => now()->subHour()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('status', 'allocated');
        $this->assertSame('allocated', PostalEntry::sole()->status);
    }

    public function test_the_public_site_gets_the_question_and_answers_but_never_which_is_correct(): void
    {
        $c = $this->paid();
        $public = $this->getJson("/api/competitions/{$c->slug}")->assertOk()->json('data.skillQuestion');
        $this->assertSame(['question' => 'What does BMW stand for?', 'options' => ['British Motor Works', 'Bayerische Motoren Werke', 'Berlin Motor Wagons']], $public);
        $this->assertStringNotContainsString('correct', $this->getJson('/api/competitions')->getContent());
        // The basket gets it fresh from the API, to ask before payment.
        $quote = $this->postJson('/api/basket/quote', ['items' => [['competitionId' => $c->id, 'qty' => 1]]])->assertOk();
        $this->assertSame($public, $quote->json('data.0.skillQuestion'));
        $this->assertStringNotContainsString('correct', $quote->getContent());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson("/api/admin/competitions/{$c->id}")->assertJsonPath('data.skillQuestion.correct', 1);
    }

    public function test_admin_edits_it_on_a_draft_and_it_is_locked_once_published(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = Competition::create(['slug' => 'd', 'title' => 'Draft', 'category' => 'cars', 'status' => 'draft', 'ticket_price_pence' => 500, 'bundles' => [],
            'total_tickets' => 100, 'closes_at' => now()->addWeeks(2), 'draw_at' => now()->addWeeks(2)->addHour(), 'images' => [['src' => '/images/p.jpg', 'alt' => 'P']]]);
        $put = fn (array $q) => $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['skillQuestion' => $q]);

        $put(['question' => 'Q?', 'options' => ['A', 'B'], 'correct' => 0])->assertStatus(422)->assertJsonValidationErrors('skillQuestion.options');
        $put(['question' => 'Q?', 'options' => ['A', 'B', 'C', 'D', 'E'], 'correct' => 0])->assertStatus(422);
        $put(['question' => 'Q?', 'options' => ['A', 'B', 'C'], 'correct' => 3])->assertStatus(422)->assertJsonValidationErrors('skillQuestion.correct');
        $put(['question' => 'Q?', 'options' => ['A', 'a', 'C'], 'correct' => 0])->assertStatus(422);
        $put(['question' => ' What does BMW stand for? ', 'options' => [' British Motor Works', 'Bayerische Motoren Werke', 'Berlin Motor Wagons'], 'correct' => 1])->assertOk();
        $this->assertSame(self::skillQ(), $draft->fresh()->skill_question, 'trimmed and stored');

        $this->actingAs($admin)->postJson("/api/admin/competitions/{$draft->id}/publish", ['openNow' => true])->assertOk();
        // Saving the same question again is fine (Admin sends the whole form); changing it isn't.
        $put(self::skillQ())->assertOk();
        $put(['question' => 'Easier?', 'options' => ['Yes', 'No', 'Maybe'], 'correct' => 0])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, "The skill question can't change after publishing"));
        $this->actingAs($admin)->putJson("/api/admin/competitions/{$draft->id}", ['skillQuestion' => null])->assertStatus(422);
        $this->assertSame(self::skillQ(), $draft->fresh()->skill_question);
    }

    public function test_client_drafts_seed_a_question_for_the_bmw_and_the_coilovers_but_never_overwrite_one_edited_in_admin(): void
    {
        Competition::create(['slug' => ClientDrafts::MAIN_EVENT, 'title' => 'BMW E46 330Ci Clubsport', 'category' => 'cars', 'status' => 'draft', 'images' => [],
            'ticket_price_pence' => 1000, 'total_tickets' => 1000, 'skill_question' => null]);
        $this->artisan('wds:client-drafts --apply')->assertSuccessful();

        $bmw = Competition::where('slug', ClientDrafts::MAIN_EVENT)->firstOrFail();
        $coil = Competition::where('slug', ClientDrafts::COILOVERS)->firstOrFail();
        $table = Competition::where('slug', ClientDrafts::ENGINE_TABLE)->firstOrFail();
        $this->assertSame('What does BMW stand for?', $bmw->skillQuestion()['question']);
        $this->assertSame('Bayerische Motoren Werke', $bmw->skillQuestion()['options'][$bmw->skillQuestion()['correct']]);
        $this->assertSame('What part of a car do coilovers replace?', $coil->skillQuestion()['question']);
        $this->assertSame('The suspension (springs and dampers)', $coil->skillQuestion()['options'][$coil->skillQuestion()['correct']]);
        $this->assertNull($table->skill_question, 'free: no question');

        // Edited in Admin (e.g. the client's approved wording): a later --apply keeps it.
        $edited = ['question' => 'Which German city is BMW based in?', 'options' => ['Munich', 'Berlin', 'Hamburg'], 'correct' => 0];
        $bmw->update(['skill_question' => $edited]);
        $this->artisan('wds:client-drafts --apply')->assertSuccessful();
        $this->assertSame($edited, $bmw->fresh()->skill_question);
    }
}
