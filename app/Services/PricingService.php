<?php

namespace App\Services;

use App\Models\Competition;

/**
 * Authoritative ticket pricing. The Astro frontend mirrors this in src/lib/pricing.ts
 * for display only — the server always recalculates.
 *
 * Bundles are applied greedily, biggest first. E.g. £14.95 each, 3 for £40, 10 for £125:
 *   12 tickets = 10 (£125) + 1×£14.95 + 1×£14.95 = £154.90
 *   13 tickets = 10 (£125) + 3 (£40) = £165.00
 */
class PricingService
{
    /** @param array<int, array{qty:int, price_pence:int}> $bundles */
    public function price(int $qty, int $unitPence, array $bundles = []): int
    {
        if ($qty <= 0) {
            return 0;
        }

        $useful = array_values(array_filter(
            $bundles,
            fn ($b) => ($b['qty'] ?? 0) > 1 && ($b['price_pence'] ?? 0) < $b['qty'] * $unitPence
        ));
        usort($useful, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        $remaining = $qty;
        $total = 0;
        foreach ($useful as $bundle) {
            $n = intdiv($remaining, $bundle['qty']);
            $total += $n * $bundle['price_pence'];
            $remaining -= $n * $bundle['qty'];
        }

        return $total + $remaining * $unitPence;
    }

    public function forCompetition(Competition $competition, int $qty): int
    {
        return $this->price($qty, $competition->ticket_price_pence, $competition->bundles ?? []);
    }

    public function saving(Competition $competition, int $qty): int
    {
        return $qty * $competition->ticket_price_pence - $this->forCompetition($competition, $qty);
    }
}
