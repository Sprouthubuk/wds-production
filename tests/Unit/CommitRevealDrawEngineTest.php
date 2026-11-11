<?php

namespace Tests\Unit;

use App\Services\Draws\CommitRevealDrawEngine;
use PHPUnit\Framework\TestCase;

class CommitRevealDrawEngineTest extends TestCase
{
    public function test_pick_is_deterministic_and_in_range(): void
    {
        $seed = str_repeat('ab', 32);
        $hash = hash('sha256', '1,2,3');
        $a = CommitRevealDrawEngine::pickIndex($seed, $hash, 750);
        $this->assertSame($a, CommitRevealDrawEngine::pickIndex($seed, $hash, 750));
        $this->assertGreaterThanOrEqual(0, $a);
        $this->assertLessThan(750, $a);
    }

    public function test_distribution_is_roughly_uniform(): void
    {
        $counts = array_fill(0, 5, 0);
        for ($i = 0; $i < 5000; $i++) {
            $counts[CommitRevealDrawEngine::pickIndex(bin2hex(random_bytes(32)), 'h', 5)]++;
        }
        foreach ($counts as $c) {
            $this->assertGreaterThan(850, $c);
            $this->assertLessThan(1150, $c);
        }
    }
}
