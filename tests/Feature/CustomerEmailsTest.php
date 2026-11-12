<?php

namespace Tests\Feature;

use App\Mail\AccountPasswordLink;
use App\Mail\MailTest;
use App\Mail\TicketsIssued;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/** Every customer email: branded HTML plus a plain-text part, the company footer, and links that open the website. */
class CustomerEmailsTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        config(['raffle.frontend_url' => 'https://wdscompetitions.co.uk']);
    }

    private function assertBranded(Mailable $mail): array
    {
        $html = $mail->render();
        $content = $mail->content();
        $this->assertNotNull($content->text, get_class($mail).' has no plain-text part');
        $text = view($content->text, $content->with)->render();

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('WDS Originals Limited trading as WDS Competitions', $body);
            $this->assertStringContainsString('company no. 13330886', $body);
            $this->assertStringContainsString('Knoll House', $body);
            $this->assertStringContainsString('competitions@wdsoriginals.com', $body);
            $this->assertStringContainsString('https://wdscompetitions.co.uk/responsible-play', $body);
        }
        $this->assertStringNotContainsString('<td', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        preg_match_all('/href="([^"]+)"/', $html, $m);
        foreach ($m[1] as $href) {
            $this->assertMatchesRegularExpression('#^(https://|mailto:)#', $href, "Link isn't absolute: {$href}");
        }

        return [$html, $text];
    }

    public function test_order_confirmation_has_ticket_numbers_text_part_and_footer(): void
    {
        Mail::fake();
        $c = $this->publishedCompetition(['title' => 'Test car', 'total_tickets' => 100]);
        $ref = $this->postJson('/api/checkout', [
            'customer' => ['email' => 'alex@example.com', 'name' => 'Alex Smith', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 3]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        app(CheckoutService::class)->handlePaymentResult(new PaymentResult($ref, 'paid'));

        $sent = null;
        Mail::assertQueued(TicketsIssued::class, function ($m) use (&$sent) { $sent = $m; return true; });
        [$html, $text] = $this->assertBranded($sent);
        $this->assertStringContainsString('#0001', $html);
        $this->assertStringContainsString('Your numbers: #0001, #0002, #0003', $text);
        $this->assertStringContainsString('https://wdscompetitions.co.uk/order/'.$ref.'?key=', $text);
    }

    public function test_forgotten_password_sends_a_branded_link_to_the_website(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com', 'first_name' => 'Sam']);
        $user->createToken('web'); // has signed in before: "reset", not "set"
        Notification::fake();

        $a = $this->postJson('/api/auth/forgot-password', ['email' => 'SAM@example.com'])->assertOk()->json('message');
        $b = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame($a, $b); // the same answer either way

        $sent = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user, &$sent) { $sent = $n->toMail($user); return true; });
        Notification::assertCount(1);
        $this->assertInstanceOf(AccountPasswordLink::class, $sent);
        $this->assertTrue($sent->hasTo('sam@example.com'));
        $this->assertFalse($sent->newAccount);
        $this->assertStringStartsWith('https://wdscompetitions.co.uk/reset-password?token=', $sent->url);
        $this->assertStringContainsString('email=sam%40example.com', $sent->url);
        [$html] = $this->assertBranded($sent);
        $this->assertStringContainsString('Reset your password, Sam.', $html);
    }

    public function test_the_link_resets_the_password_once(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.com']);
        Notification::fake();
        $this->postJson('/api/auth/forgot-password', ['email' => 'sam@example.com'])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });

        $body = ['token' => $token, 'email' => 'sam@example.com', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'];
        $this->postJson('/api/auth/reset-password', $body)->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'sam@example.com', 'password' => 'a-new-password-1'])->assertOk();
        $this->postJson('/api/auth/reset-password', $body)->assertStatus(422);   // used up
    }

    public function test_account_made_at_checkout_gets_set_your_password(): void
    {
        Mail::fake();
        Notification::fake();
        $c = $this->publishedCompetition();
        $this->postJson('/api/checkout', [
            'customer' => ['email' => 'new@example.com', 'name' => 'Jo Bloggs', 'phone' => '0', 'dateOfBirth' => '1990-05-05'],
            'items' => [['competitionId' => $c->id, 'qty' => 1]], 'ageConfirmed' => true, 'termsAccepted' => true, 'createAccount' => true,
        ])->assertOk();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $mail = $n->toMail($user);

            return $mail->newAccount && $mail->hasTo('new@example.com') && str_contains($mail->render(), 'Set your password, Jo.');
        });
    }

    public function test_mail_test_command_sends_a_branded_email(): void
    {
        Mail::fake();
        $this->artisan('wds:mail-test', ['email' => 'ops@example.com'])->assertSuccessful();
        Mail::assertSent(MailTest::class, fn ($m) => $m->hasTo('ops@example.com'));
        $this->assertBranded(new MailTest('array'));

        $this->artisan('wds:mail-test', ['email' => 'not-an-email'])->assertFailed();
        config(['mail.default' => 'resend', 'services.resend.key' => null]);
        $this->artisan('wds:mail-test', ['email' => 'ops@example.com'])->assertFailed();
    }

    public function test_resend_is_configured(): void
    {
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertArrayHasKey('key', config('services.resend'));
        $this->assertTrue(class_exists(\Resend::class));
        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('MAIL_MAILER=resend', $env);
        $this->assertStringContainsString('RESEND_API_KEY=', $env);
    }
}
