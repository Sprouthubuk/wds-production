<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Sprouthub\Verity\Engine\Drand;
use Sprouthub\Verity\Services\Beacon;
use Sprouthub\Verity\Services\CloseWitnesses;
use Tests\TestCase;

/**
 * Opt-in: talks to the real drand relays and the real timestamp authority. Off by default (CI and
 * local runs stay offline); run with VERITY_LIVE_TEST=1 php artisan test --filter=VerityLiveTest.
 * Sends nothing but a random hash to the TSA.
 */
class VerityLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! filter_var(getenv('VERITY_LIVE_TEST') ?: false, FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Live drand / TSA test: set VERITY_LIVE_TEST=1 to run it.');
        }
        Http::allowStrayRequests();
    }

    public function test_a_real_drand_round_is_fetched_and_its_bls_signature_verifies(): void
    {
        config(['verity.beacon' => 'drand', 'verity.drand.clock_check_at_close' => true]);
        $latest = app(Beacon::class)->latestPublished();
        $this->assertNotNull($latest, 'No relay answered with a verified latest round');

        $round = app(Beacon::class)->fetchVerified((int) $latest['round'] - 1);
        $this->assertTrue(Drand::verifyRound($round['round'], $round['randomness'], $round['signature'])['valid']);
    }

    public function test_the_timestamp_authority_grants_a_token_for_an_imprint(): void
    {
        $url = config('verity.tsa_url') ?: 'http://timestamp.digicert.com';
        $imprint = hash('sha256', random_bytes(32));
        $res = Http::timeout(15)->withBody(CloseWitnesses::request($imprint), 'application/timestamp-query')->post($url);

        $this->assertTrue($res->successful(), "TSA answered {$res->status()}");
        $this->assertNotNull(CloseWitnesses::grantedTime($res->body(), $imprint), 'TSA reply not granted or not for this imprint');
    }
}
