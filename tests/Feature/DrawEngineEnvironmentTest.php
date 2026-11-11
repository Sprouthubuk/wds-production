<?php

namespace Tests\Feature;

use App\Providers\RaffleServiceProvider;
use App\Services\Draws\CommitRevealDrawEngine;
use App\Services\Draws\DrawEngine;
use RuntimeException;
use Tests\TestCase;

class DrawEngineEnvironmentTest extends TestCase
{
    public function test_commit_reveal_is_refused_in_production(): void
    {
        $this->app['env'] = 'production';
        config()->set('raffle.draws.engine', 'commit-reveal');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DRAW_ENGINE must be "verity" in production');

        $this->app->make(DrawEngine::class);
    }

    public function test_unset_engine_is_refused_in_production(): void
    {
        $this->expectException(RuntimeException::class);

        RaffleServiceProvider::assertDrawEngineAllowed('production', null);
    }

    public function test_verity_is_allowed_in_production(): void
    {
        RaffleServiceProvider::assertDrawEngineAllowed('production', 'verity');

        $this->addToAssertionCount(1);
    }

    public function test_commit_reveal_still_works_outside_production(): void
    {
        config()->set('raffle.draws.engine', 'commit-reveal');

        $this->assertInstanceOf(CommitRevealDrawEngine::class, $this->app->make(DrawEngine::class));
    }
}
