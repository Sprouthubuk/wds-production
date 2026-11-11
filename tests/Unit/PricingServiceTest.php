<?php

namespace Tests\Unit;

use App\Services\PricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    private array $bundles = [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]];

    #[DataProvider('cases')]
    public function test_prices_match_the_frontend(int $qty, int $expected): void
    {
        $this->assertSame($expected, (new PricingService)->price($qty, 1495, $this->bundles));
    }

    public static function cases(): array
    {
        return [
            'one' => [1, 1495],
            'two' => [2, 2990],
            'three bundle' => [3, 4000],
            'four' => [4, 5495],
            'ten bundle' => [10, 12500],
            'twelve' => [12, 15490],
            'thirteen' => [13, 16500],
            'fifty' => [50, 62500],
            'zero' => [0, 0],
        ];
    }

    public function test_ignores_bundles_that_cost_more_than_singles(): void
    {
        $this->assertSame(300, (new PricingService)->price(3, 100, [['qty' => 3, 'price_pence' => 500]]));
    }
}
