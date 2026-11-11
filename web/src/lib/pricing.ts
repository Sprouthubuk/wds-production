import type { Bundle } from './types';

/**
 * Cheapest price for `qty` tickets using fixed-price bundles, biggest first.
 * Mirrors App\Services\PricingService on the API, which is the source of truth.
 */
export function priceFor(qty: number, ticketPrice: number, bundles: Bundle[]): number {
  const packs = [...bundles].filter((b) => b.qty > 1).sort((a, b) => b.qty - a.qty);
  let remaining = Math.max(0, Math.floor(qty));
  let total = 0;
  for (const pack of packs) {
    const n = Math.floor(remaining / pack.qty);
    total += n * pack.price;
    remaining -= n * pack.qty;
  }
  return round2(total + remaining * ticketPrice);
}

export function savingFor(qty: number, ticketPrice: number, bundles: Bundle[]): number {
  return round2(qty * ticketPrice - priceFor(qty, ticketPrice, bundles));
}

export function money(value: number): string {
  if (!Number.isFinite(value)) return '£—';
  return Math.abs(value - Math.round(value)) < 0.001 ? `£${Math.round(value)}` : `£${value.toFixed(2)}`;
}

export function odds(totalTickets: number, qty = 1): string {
  if (!totalTickets) return '1 in —';
  return `1 in ${Math.max(1, Math.round(totalTickets / Math.max(1, qty))).toLocaleString('en-GB')}`;
}

export function percentSold(sold: number, total: number): number {
  return total > 0 ? Math.min(100, Math.round((sold / total) * 100)) : 0;
}

export function statusLabel(sold: number, total: number): string {
  const left = Math.max(0, total - sold);
  const pct = percentSold(sold, total);
  if (total && left === 0) return 'Sold out';
  if (total && left / total <= 0.1) return `Only ${left.toLocaleString('en-GB')} left`;
  if (pct >= 50) return 'Selling fast';
  if (pct >= 10) return 'Live now';
  return 'Just launched';
}

function round2(v: number): number {
  return Math.round(v * 100) / 100;
}
