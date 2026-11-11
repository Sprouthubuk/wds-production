<?php

namespace Tests\Feature;

use App\Providers\RaffleServiceProvider;
use RuntimeException;
use Sprouthub\Verity\Engine\VerityException;
use Sprouthub\Verity\Services\Beacon;
use Tests\TestCase;

/**
 * Production draws only on drand (public, verifiable randomness). The commit-reveal engine is gone;
 * the local beacon is for development and tests and is refused in production twice: by WDS when a
 * web request boots, and by Verity itself at close, draw and redraw.
 */
class BeaconEnvironmentTest extends TestCase
{
    public function test_web_boot_refuses_a_local_beacon_in_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VERITY_BEACON must be "drand" in production');

        RaffleServiceProvider::assertBeaconAllowed('production', 'local');
    }

    public function test_drand_is_allowed_in_production(): void
    {
        RaffleServiceProvider::assertBeaconAllowed('production', 'drand');

        $this->addToAssertionCount(1);
    }

    public function test_local_beacon_is_allowed_outside_production(): void
    {
        RaffleServiceProvider::assertBeaconAllowed('local', 'local');
        RaffleServiceProvider::assertBeaconAllowed('testing', 'local');

        $this->addToAssertionCount(1);
    }

    public function test_verity_itself_refuses_the_local_beacon_in_production(): void
    {
        $this->app['env'] = 'production';
        config()->set('verity.beacon', 'local');

        $this->expectException(VerityException::class);

        Beacon::assertAllowed();
    }
}
