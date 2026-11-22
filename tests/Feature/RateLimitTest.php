<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payments\CashflowsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rate limits are per visitor (the IP the website passes on, believed only from this server) or per account, each
 * with its own counter: one person's requests never use up another's, or their own sign-in attempts.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '203.0.113.9';

    /** A request arriving from $from (the connection), saying it's for $for. */
    private function send(string $uri, array $body, ?string $for, string $from = '127.0.0.1', array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->json('POST', $uri, $body, $headers + ($for === null ? [] : ['X-Forwarded-For' => $for]));
    }

    private function signIn(?string $for, string $from = '127.0.0.1', array $headers = []): TestResponse
    {
        return $this->send('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'], $for, $from, $headers);
    }

    private function quote(?string $for, array $headers = []): TestResponse
    {
        return $this->send('/api/basket/quote', ['items' => [['competitionId' => 1, 'qty' => 1]]], $for, '127.0.0.1', $headers);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('web')->plainTextToken];
    }

    public function test_two_visitors_through_the_website_have_separate_counters(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn('198.51.100.1')->assertStatus(422);
        }
        $this->signIn('198.51.100.1')->assertStatus(429);
        $this->signIn('198.51.100.2')->assertStatus(422);
        $this->signIn('2001:db8::7')->assertStatus(422);
        $this->signIn('198.51.100.1', '::1')->assertStatus(429); // the same visitor, over IPv6 loopback
    }

    public function test_a_forwarded_header_from_anywhere_else_is_ignored(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn("198.51.100.{$i}", self::PUBLIC_IP)->assertStatus(422); // a different "visitor" every time
        }
        $this->signIn('198.51.100.99', self::PUBLIC_IP)->assertStatus(429);
        // Nor does it use up the counter of the visitor it names.
        $this->signIn('198.51.100.1')->assertStatus(422);
        // Nor from another private address, nor by any other header.
        foreach (['10.0.0.5', '192.168.0.2', '172.17.0.1'] as $private) {
            for ($i = 0; $i < 10; $i++) {
                $this->signIn("198.51.100.{$i}", $private);
            }
            $this->signIn('198.51.100.98', $private)->assertStatus(429);
        }
        $this->signIn(null, self::PUBLIC_IP, ['X-Real-IP' => '198.51.100.97', 'Forwarded' => 'for=198.51.100.97', 'Client-IP' => '198.51.100.97'])->assertStatus(429);
    }

    public function test_the_on_forge_host_does_not_bring_back_trusting_everyone(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn("198.51.100.{$i}", self::PUBLIC_IP, ['Host' => 'wds.on-forge.com']);
        }
        $this->json('POST', 'https://wds.on-forge.com/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'], ['X-Forwarded-For' => '198.51.100.50'])
            ->assertStatus(429);
    }

    public function test_only_the_client_ip_is_taken_from_the_website_not_the_host_or_scheme(): void
    {
        Router::get('/api/_test/request', fn () => ['ip' => request()->ip(), 'url' => request()->url()]);
        $headers = ['X-Forwarded-For' => '198.51.100.1', 'X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '444'];
        $this->getJson('http://api.example.test/api/_test/request', $headers)->assertExactJson(['ip' => '198.51.100.1', 'url' => 'http://api.example.test/api/_test/request']);

        // Several addresses: only the last one before a trusted address counts, never one the visitor put in front.
        $this->getJson('http://api.example.test/api/_test/request', ['X-Forwarded-For' => '192.0.2.66, 198.51.100.1'])->assertJsonPath('ip', '198.51.100.1');
        $this->getJson('http://api.example.test/api/_test/request', ['X-Forwarded-For' => 'not-an-ip'])->assertJsonPath('ip', '127.0.0.1');
    }

    /** on-forge.com goes through Cloudflare: the scheme (only) is kept, so signed links still check out there. */
    public function test_signed_links_still_work_on_the_on_forge_name(): void
    {
        $user = User::factory()->unverified()->create();
        URL::useOrigin('https://wds.on-forge.com');
        $link = URL::temporarySignedRoute('auth.verify-email', now()->addHour(), ['user' => $user->id, 'hash' => sha1($user->email)]);
        $this->assertStringStartsWith('https://wds.on-forge.com/', $link);
        $http = str_replace('https://', 'http://', $link); // Cloudflare to the server over http

        $this->withServerVariables(['REMOTE_ADDR' => self::PUBLIC_IP]);
        $this->get($http)->assertForbidden();
        $this->assertNotSame(403, $this->get($http, ['X-Forwarded-Proto' => 'https'])->getStatusCode());
    }

    public function test_basket_checks_and_forms_do_not_use_up_sign_in_attempts(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->quote('198.51.100.1')->assertOk();
        }
        $this->quote('198.51.100.1')->assertStatus(429);
        $this->quote('198.51.100.2')->assertOk()->assertHeader('X-RateLimit-Remaining', 59); // forge/README.md's check
        for ($i = 0; $i < 10; $i++) {
            $this->send('/api/newsletter', [], '198.51.100.1')->assertStatus(422);
        }
        $this->send('/api/newsletter', [], '198.51.100.1')->assertStatus(429);

        for ($i = 0; $i < 10; $i++) {
            $this->signIn('198.51.100.1')->assertStatus(422);
        }
        $this->signIn('198.51.100.1')->assertStatus(429);
    }

    public function test_checkout_allows_sixty_a_minute_per_person_on_its_own_counter(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->send('/api/checkout', [], '198.51.100.1')->assertStatus(422);
        }
        $this->send('/api/checkout', [], '198.51.100.1')->assertStatus(429);
        $this->send('/api/checkout', [], '198.51.100.2')->assertStatus(422);
        $this->quote('198.51.100.1')->assertOk();
    }

    public function test_signed_in_requests_are_limited_per_account_whatever_the_ip(): void
    {
        $ann = $this->bearer(User::factory()->create());
        $ben = $this->bearer(User::factory()->create());
        for ($i = 0; $i < 60; $i++) {
            $this->quote('198.51.100.'.($i % 5), $ann)->assertOk();
        }
        $this->quote('198.51.100.77', $ann)->assertStatus(429); // a new IP doesn't help
        $this->quote('198.51.100.1', $ben)->assertOk();          // someone else on the same IP
        $this->quote('198.51.100.1')->assertOk();                // and a guest on it
        $this->quote('198.51.100.1', ['Authorization' => 'Bearer 999|made-up'])->assertOk(); // an invalid token: a guest

        // A made-up token doesn't give a new counter: it's the guest's one.
        for ($i = 0; $i < 58; $i++) {
            $this->quote('198.51.100.1', ['Authorization' => "Bearer {$i}|made-up"])->assertOk();
        }
        $this->quote('198.51.100.1', ['Authorization' => 'Bearer 1000|made-up'])->assertStatus(429);
    }

    public function test_being_signed_in_does_not_get_round_the_sign_in_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->signIn('198.51.100.1', '127.0.0.1', $this->bearer(User::factory()->create()))->assertStatus(422);
        }
        $this->signIn('198.51.100.1', '127.0.0.1', $this->bearer(User::factory()->create()))->assertStatus(429);
    }

    /** An inline throttle:10,1 shares one counter with every other inline one: only named limits. */
    public function test_every_rate_limited_route_has_a_named_limit(): void
    {
        $limited = 0;
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $limited++;
                    $this->assertDoesNotMatchRegularExpression('/^throttle:\d/', $middleware, $route->uri());
                    $this->assertNotNull(\Illuminate\Support\Facades\RateLimiter::limiter(substr($middleware, 9)), "{$route->uri()}: {$middleware}");
                }
            }
        }
        $this->assertGreaterThanOrEqual(15, $limited);
    }
}
