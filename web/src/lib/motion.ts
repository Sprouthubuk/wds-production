/**
 * Site-wide motion. Everything here is progressive enhancement:
 * - content is fully visible without JS (the `.js` class gates the hidden start states)
 * - `prefers-reduced-motion` turns it all off (see motion.css)
 */
const reduce = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Fade/slide elements in as they enter the viewport. Siblings stagger via --i. */
function reveals() {
  // Any page section without explicit hooks gets a gentle reveal on its blocks.
  document.querySelectorAll<HTMLElement>('main .section > .container > *, main .section > .container > .grid > *').forEach((el) => {
    if (el.closest('[data-reveal], [data-reveal-group]') || el.hasAttribute('data-reveal-group')) return;
    if (el.parentElement?.classList.contains('grid')) {
      el.style.setProperty('--i', String(Math.min(Array.from(el.parentElement.children).indexOf(el), 8)));
    }
    el.setAttribute('data-reveal', '');
  });
  const els = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal], [data-reveal-group] > *, .progress, [data-count-to]'));
  document.querySelectorAll<HTMLElement>('[data-reveal-group]').forEach((g) => {
    Array.from(g.children).forEach((c, i) => (c as HTMLElement).style.setProperty('--i', String(Math.min(i, 8))));
  });
  if (reduce() || !('IntersectionObserver' in window)) {
    els.forEach((el) => { el.classList.add('is-in'); if (el.dataset.countTo) el.textContent = el.dataset.countTo; });
    return;
  }
  const io = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (!e.isIntersecting) continue;
      const el = e.target as HTMLElement;
      el.classList.add('is-in');
      if (el.dataset.countTo) countUp(el);
      io.unobserve(el);
    }
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
  els.forEach((el) => {
    // Anything already on screen at load animates straight away.
    io.observe(el);
  });
}

/** Animate a number from 0 to data-count-to (keeps any suffix such as "%"). */
function countUp(el: HTMLElement) {
  const raw = el.dataset.countTo || '0';
  const target = parseFloat(raw.replace(/[^\d.]/g, '')) || 0;
  const suffix = raw.replace(/[\d.,]/g, '');
  const grouped = raw.includes(',');
  const dur = 1300;
  const start = performance.now();
  const fmt = (n: number) => (grouped ? Math.round(n).toLocaleString('en-GB') : String(Math.round(n))) + suffix;
  const step = (t: number) => {
    const p = Math.min(1, (t - start) / dur);
    const eased = 1 - Math.pow(1 - p, 4);
    el.textContent = fmt(target * eased);
    if (p < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

/** Sticky header: solid on scroll; on phones it tucks away while scrolling down. */
function header() {
  const h = document.querySelector<HTMLElement>('.site-header');
  if (!h) return;
  let last = window.scrollY;
  let ticking = false;
  const update = () => {
    const y = window.scrollY;
    h.classList.toggle('is-scrolled', y > 8);
    const menuOpen = document.documentElement.classList.contains('menu-open');
    if (!menuOpen && window.innerWidth < 1000) h.classList.toggle('is-hidden', y > last && y > 240);
    else h.classList.remove('is-hidden');
    last = y;
    ticking = false;
  };
  window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
  update();
}

/**
 * Sticky "Enter" bar: hidden while an entry panel (or the hero) is on screen,
 * slides up once the visitor has scrolled past it, and gets out of the way at the footer.
 */
function stickyBar() {
  const bar = document.querySelector<HTMLElement>('.sticky-bar');
  if (!bar) return;
  const watch = Array.from(document.querySelectorAll<HTMLElement>('[data-entry-panel], .hero, .site-footer'));
  if (!watch.length || !('IntersectionObserver' in window)) { bar.classList.add('is-shown'); return; }
  const visible = new Set<Element>();
  let scrolledPast = false;
  const io = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (e.isIntersecting) visible.add(e.target); else visible.delete(e.target);
      if (!e.isIntersecting && e.boundingClientRect.top < 0) scrolledPast = true;
    }
    bar.classList.toggle('is-shown', visible.size === 0 && scrolledPast);
  }, { threshold: 0 });
  watch.forEach((w) => io.observe(w));
}

/** Images fade in once decoded instead of popping. */
function images() {
  document.querySelectorAll<HTMLImageElement>('img[data-fade]').forEach((img) => {
    const done = () => img.classList.add('is-loaded');
    if (img.complete && img.naturalWidth) done();
    else { img.addEventListener('load', done, { once: true }); img.addEventListener('error', done, { once: true }); }
  });
}

/** Smooth open/close for <details> where the browser can't animate it natively. */
function details() {
  if (CSS.supports('interpolate-size', 'allow-keywords') || reduce()) return;
  document.querySelectorAll<HTMLDetailsElement>('.faq details').forEach((d) => {
    const summary = d.querySelector('summary');
    const body = d.querySelector<HTMLElement>('summary ~ *');
    if (!summary || !body) return;
    summary.addEventListener('click', (e) => {
      e.preventDefault();
      if (d.open) {
        const a = body.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'translateY(-6px)' }], { duration: 180, easing: 'ease-in' });
        a.onfinish = () => { d.open = false; };
      } else {
        d.open = true;
        body.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 320, easing: 'cubic-bezier(.16,1,.3,1)' });
      }
    });
  });
}

/** Little "tick" when a price/total changes. */
export function pop(el: Element | null) {
  if (!el || reduce()) return;
  el.classList.remove('pop');
  void (el as HTMLElement).offsetWidth;
  el.classList.add('pop');
}

export function initMotion() {
  document.documentElement.classList.add('js');
  reveals();
  header();
  stickyBar();
  images();
  details();
}
