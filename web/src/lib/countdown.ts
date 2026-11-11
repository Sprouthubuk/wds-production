/**
 * Live countdowns. Mark up any element with data-countdown="<ISO date>" and
 * children carrying data-unit="d|h|m|s"; or data-countdown-text for a one-line
 * "43d 11h 36m 57s" string.
 */
export function startCountdowns(): void {
  const els = Array.from(document.querySelectorAll<HTMLElement>('[data-countdown]'));
  if (!els.length) return;
  const pad = (n: number) => String(n).padStart(2, '0');
  const tick = () => {
    const now = Date.now();
    for (const el of els) {
      const target = Date.parse(el.dataset.countdown || '');
      if (Number.isNaN(target)) continue;
      let diff = Math.max(0, target - now);
      const d = Math.floor(diff / 86_400_000); diff -= d * 86_400_000;
      const h = Math.floor(diff / 3_600_000); diff -= h * 3_600_000;
      const m = Math.floor(diff / 60_000); diff -= m * 60_000;
      const s = Math.floor(diff / 1000);
      const parts: Record<string, string> = { d: pad(d), h: pad(h), m: pad(m), s: pad(s) };
      el.querySelectorAll<HTMLElement>('[data-unit]').forEach((u) => {
        u.textContent = parts[u.dataset.unit || ''] ?? '';
      });
      if ('countdownText' in el.dataset) el.textContent = `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    }
  };
  tick();
  setInterval(tick, 1000);
}

/** Server-side first render so the countdown isn't blank before JS runs. */
export function countdownParts(iso: string, now = Date.now()) {
  const pad = (n: number) => String(n).padStart(2, '0');
  let diff = Math.max(0, Date.parse(iso) - now);
  const d = Math.floor(diff / 86_400_000); diff -= d * 86_400_000;
  const h = Math.floor(diff / 3_600_000); diff -= h * 3_600_000;
  const m = Math.floor(diff / 60_000); diff -= m * 60_000;
  const s = Math.floor(diff / 1000);
  return { d: pad(d), h: pad(h), m: pad(m), s: pad(s), text: `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s` };
}
