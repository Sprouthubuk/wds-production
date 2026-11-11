import type { BasketLine } from './types';

const KEY = 'wds_basket_v1';

export function readBasket(): BasketLine[] {
  try {
    const raw = localStorage.getItem(KEY);
    const lines = raw ? (JSON.parse(raw) as BasketLine[]) : [];
    return Array.isArray(lines) ? lines.filter((l) => l && l.qty > 0) : [];
  } catch {
    return [];
  }
}

export function writeBasket(lines: BasketLine[]): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(lines));
  } catch {
    /* storage unavailable (private mode) — basket lives for this page only */
  }
  window.dispatchEvent(new CustomEvent('basket:change'));
}

/** Add tickets for a competition. Quantity is capped at the per-person maximum. */
export function addToBasket(line: BasketLine): void {
  const lines = readBasket();
  const existing = lines.find((l) => l.competitionId === line.competitionId);
  if (existing) {
    existing.qty = Math.min(line.maxPerPerson, existing.qty + line.qty);
  } else {
    lines.push({ ...line, qty: Math.min(line.maxPerPerson, line.qty) });
  }
  writeBasket(lines);
}

export function setQty(competitionId: number, qty: number): void {
  const lines = readBasket()
    .map((l) => (l.competitionId === competitionId ? { ...l, qty: Math.min(l.maxPerPerson, Math.max(0, qty)) } : l))
    .filter((l) => l.qty > 0);
  writeBasket(lines);
}

export function clearBasket(): void {
  writeBasket([]);
}

export function basketCount(): number {
  return readBasket().reduce((n, l) => n + l.qty, 0);
}
