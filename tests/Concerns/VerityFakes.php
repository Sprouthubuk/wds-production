<?php

namespace Tests\Concerns;

use App\Models\Competition;
use App\Services\Draws\VerityDrawEngine;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Verity in tests: drand and the timestamp authority are faked at the HTTP layer (Http::fake), but
 * everything else is the real engine, BLS check included. The drand round is a real quicknet round,
 * so its signature verifies against the real public key (taken from the package's own verifier tests).
 */
trait VerityFakes
{
    /** Real drand quicknet round 33529032, published 2026-10-30T20:01:00Z. */
    protected const ROUND = 33529032;

    protected const RANDOMNESS = 'bf7edd2353bbca0cac49d30892db36abaa74d3dfd520ab62f6e4a8288d51f329';

    protected const SIGNATURE = '862631debfc2c6a799721fb44622a74ca9bceae6a20665acafb1ca77ee613334a93f81e7ebd2ef162483166a31892d96';

    /** A second real quicknet round, 33732989, published 2026-11-06T21:58:51Z: used by redraws (R2). */
    protected const ROUND_2 = 33732989;

    protected const RANDOMNESS_2 = 'd9d733c333a9a87fd2903f85d75f614f6630bf7c3d19ad0c7afc08868cbf1223';

    protected const SIGNATURE_2 = 'a16cbca54ebc06743e1009f8d3dab313eb386a9ea1dae4641a109187ff0a6adb9d130acc1122fb0985a7f48556bef929';

    /** A forfeit scheduled at this moment commits its redraw to ROUND_2 (60 s later). */
    protected const FORFEIT_AT = '2026-11-06 21:57:51';

    /** Closing here commits to ROUND (the first round at least 60 s after the close). */
    protected const CLOSES_AT = '2026-10-30 20:00:00';

    protected function fakeVerity(bool $tsa = true): void
    {
        Mail::fake();
        config([
            'verity.beacon' => 'drand',
            'verity.drand.relays' => ['https://relay.test'],
            // The clock check reads drand's live "latest" round; tests move the clock instead (allowed outside production).
            'verity.drand.clock_check_at_close' => false,
            'verity.tsa_url' => $tsa ? 'https://tsa.test/tsr' : '',
            'verity.alert_recipients' => ['ops@example.test'],
            'verity.anchor_recipients' => [],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'relay.test/*/public/'.self::ROUND => Http::response(['round' => self::ROUND, 'randomness' => self::RANDOMNESS, 'signature' => self::SIGNATURE]),
            'relay.test/*/public/'.self::ROUND_2 => Http::response(['round' => self::ROUND_2, 'randomness' => self::RANDOMNESS_2, 'signature' => self::SIGNATURE_2]),
            'relay.test/*' => Http::response(['error' => 'not this round'], 404),
            'tsa.test/*' => fn (Request $r) => Http::response(self::tsaReply($r->body()), 200, ['Content-Type' => 'application/timestamp-reply']),
        ]);
    }

    /** A WDS competition, published on Verity (sequential numbering, terms locked). */
    protected function publishedCompetition(array $overrides = []): Competition
    {
        $c = Competition::create(array_merge([
            'slug' => 'test-'.substr(md5((string) microtime(true)), 0, 6), 'title' => 'Test car', 'category' => 'cars', 'status' => 'draft',
            'ticket_price_pence' => 1495, 'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
            'total_tickets' => 50, 'max_per_person' => 20,
            'closes_at' => now()->addWeeks(2), 'draw_at' => now()->addWeeks(2)->addHour(),
            'postal_entry_enabled' => true, 'event_sales_enabled' => true,
        ], $overrides));

        return app(VerityDrawEngine::class)->publish($c);
    }

    /**
     * A minimal granted RFC 3161 TimeStampResp for the request's imprint, genTime = now. Enough for
     * Verity's parser and verifiers, which check the status, the imprint and the time (the TSA's
     * signature is checked with `openssl ts -verify`, outside the app).
     */
    protected static function tsaReply(string $request): string
    {
        $at = strpos($request, "\x04\x20");
        $imprint = substr($request, $at + 2, 32);
        $time = now()->utc()->format('YmdHis').'Z';
        $body = "\x30\x03\x02\x01\x00"."\x04\x20".$imprint."\x18".chr(strlen($time)).$time;

        return "\x30".chr(strlen($body)).$body;
    }
}
