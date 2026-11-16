<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\VerityFakes;
use Tests\TestCase;

/**
 * Every API route, walked from the route table so a new one can't be added unprotected:
 *  - /api/admin/* : a guest gets 401, a customer never gets in, staff only reach the staff routes;
 *  - customer routes (auth:sanctum) : a guest gets 401;
 *  - one customer can't see another's order, entries or tickets.
 */
class AuthorizationSweepTest extends TestCase
{
    use RefreshDatabase, VerityFakes;

    /** Staff (not only admins) may use these: postal entries and the event stand. */
    private const STAFF_PREFIXES = ['api/admin/postal', 'api/admin/event-sales', 'api/admin/environment'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeVerity();
        Mail::fake();
    }

    /** @return list<array{0: string, 1: string}> [method, uri with parameters filled in] */
    private function routes(callable $filter): array
    {
        $out = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! $filter($route)) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, '/'.$uri];
            }
        }
        $this->assertNotEmpty($out);

        return $out;
    }

    private function codeFor(string $method, string $uri): int
    {
        return $this->json($method, $uri)->getStatusCode();
    }

    public function test_admin_routes_refuse_guests_and_customers_and_staff_only_reach_staff_routes(): void
    {
        $admin = $this->routes(fn (Route $r) => str_starts_with($r->uri(), 'api/admin'));
        $customer = User::factory()->create(['role' => 'customer']);
        $staff = User::factory()->create(['role' => 'staff']);

        foreach ($admin as [$method, $uri]) {
            $this->app['auth']->forgetGuards();
            $this->assertSame(401, $this->codeFor($method, $uri), "guest {$method} {$uri}");
        }
        foreach ($admin as [$method, $uri]) {
            $this->actingAs($customer);
            $this->assertContains($this->codeFor($method, $uri), [403, 404], "customer {$method} {$uri}");
        }
        foreach ($admin as [$method, $uri]) {
            $isStaffRoute = (bool) array_filter(self::STAFF_PREFIXES, fn ($p) => str_starts_with(ltrim($uri, '/'), $p));
            if ($isStaffRoute) {
                continue;
            }
            $this->actingAs($staff);
            $this->assertContains($this->codeFor($method, $uri), [403, 404], "staff {$method} {$uri}");
        }
    }

    public function test_customer_routes_refuse_guests(): void
    {
        $routes = $this->routes(fn (Route $r) => in_array('auth:sanctum', $r->gatherMiddleware(), true) && ! str_starts_with($r->uri(), 'api/admin'));
        foreach ($routes as [$method, $uri]) {
            $this->app['auth']->forgetGuards();
            $this->assertSame(401, $this->codeFor($method, $uri), "guest {$method} {$uri}");
        }
    }

    public function test_one_customer_cannot_see_another_customers_order_or_tickets(): void
    {
        Carbon::setTestNow('2026-12-10 12:00:00');
        config(['raffle.payments.driver' => 'fake']);
        $c = $this->publishedCompetition(['closes_at' => '2026-12-24 19:00:00', 'draw_at' => '2026-12-24 19:30:00', 'total_tickets' => 100, 'ticket_price_pence' => 100, 'bundles' => []]);
        $alice = User::factory()->create(['email' => 'alice@example.com', 'date_of_birth' => '1990-01-01']);
        $bob = User::factory()->create(['email' => 'bob@example.com', 'date_of_birth' => '1990-01-01']);

        $ref = $this->actingAs($alice)->postJson('/api/checkout', [
            'customer' => ['email' => 'alice@example.com', 'name' => 'Alice A', 'phone' => '0', 'dateOfBirth' => '1990-01-01'],
            'items' => [['competitionId' => $c->id, 'qty' => 2]], 'ageConfirmed' => true, 'termsAccepted' => true,
        ])->assertOk()->json('reference');
        $order = Order::where('reference', $ref)->firstOrFail();
        $this->postJson('/api/payments/webhook', ['order' => $ref, 'status' => 'paid', 'provider_reference' => 'x'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($bob);
        $this->getJson("/api/orders/{$ref}")->assertNotFound();
        $this->getJson("/api/orders/{$ref}?key=wrong")->assertNotFound();
        $this->getJson("/api/orders/{$ref}?paymentjobref=")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/me/orders')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/me/entries')->assertOk()->json('data'));

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/orders/{$ref}?key={$order->access_key}")->assertOk();   // the link in the email still works
        $this->actingAs($alice);
        $this->getJson("/api/orders/{$ref}")->assertOk();
        Carbon::setTestNow();
    }
}
