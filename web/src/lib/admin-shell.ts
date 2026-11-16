/** The Admin shell: the phone drawer, the collapsible sidebar and the account menu (AdminLayout). */
/** Filtering or paging a list reloads the page: show skeleton rows in its tables until the next page arrives. */
function initSkeletons() {
  const busy = (on: boolean) => document.querySelectorAll<HTMLElement>('.panel').forEach((p) => {
    if (p.querySelector('.tbl')) on ? p.setAttribute('aria-busy', 'true') : p.removeAttribute('aria-busy');
  });
  document.querySelectorAll<HTMLFormElement>('form.filters').forEach((f) => {
    if ((f.getAttribute('method') ?? 'get').toLowerCase() === 'get' && !f.hasAttribute('data-admin-form')) f.addEventListener('submit', () => busy(true));
  });
  document.querySelectorAll<HTMLAnchorElement>('[data-pager] a').forEach((a) => a.addEventListener('click', (e) => {
    if (!e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) busy(true);
  }));
  window.addEventListener('pageshow', () => busy(false));   // back/forward cache
}

export function initShell() {
  initSkeletons();
  const side = document.querySelector<HTMLElement>('[data-adm-side]');
  const openBtn = document.querySelector<HTMLButtonElement>('[data-adm-open]');
  const scrim = document.querySelector<HTMLElement>('[data-adm-scrim]');
  const closeBtn = document.querySelector<HTMLButtonElement>('[data-adm-close]');
  const collapse = document.querySelector<HTMLButtonElement>('[data-adm-collapse]');
  const user = document.querySelector<HTMLDetailsElement>('[data-adm-user]');
  const phone = window.matchMedia('(max-width: 900px)');

  const setDrawer = (open: boolean, focus = true) => {
    if (!side || !openBtn || !scrim) return;
    side.classList.toggle('open', open);
    scrim.hidden = !open;
    openBtn.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
    if (!focus) return;
    if (open) side.querySelector<HTMLElement>('.adm-nav.on, .adm-nav')?.focus();
    else openBtn.focus();
  };
  openBtn?.addEventListener('click', () => setDrawer(true));
  closeBtn?.addEventListener('click', () => setDrawer(false));
  scrim?.addEventListener('click', () => setDrawer(false));
  phone.addEventListener('change', () => setDrawer(false, false));

  // Keep keyboard focus inside the open drawer.
  side?.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !side.classList.contains('open')) return;
    const items = Array.from(side.querySelectorAll<HTMLElement>('a[href], button:not([disabled])')).filter((el) => el.offsetParent !== null);
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
  });

  const collapsed = () => document.documentElement.classList.contains('adm-collapsed');
  collapse?.setAttribute('aria-pressed', String(collapsed()));
  collapse?.addEventListener('click', () => {
    const next = !collapsed();
    document.documentElement.classList.toggle('adm-collapsed', next);
    collapse.setAttribute('aria-pressed', String(next));
    collapse.querySelector('.adm-nav-text')!.textContent = next ? 'Expand menu' : 'Collapse menu';
    collapse.title = next ? 'Expand menu' : 'Collapse menu';
    try { localStorage.setItem('wds-adm-collapsed', next ? '1' : '0'); } catch { /* private mode: not remembered */ }
  });
  if (collapsed() && collapse) { collapse.title = 'Expand menu'; collapse.querySelector('.adm-nav-text')!.textContent = 'Expand menu'; }

  // The account menu closes on Escape or a click elsewhere.
  document.addEventListener('click', (e) => { if (user?.open && !user.contains(e.target as Node)) user.open = false; });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (user?.open) { user.open = false; user.querySelector('summary')?.focus(); }
    if (side?.classList.contains('open')) setDrawer(false);
  });
}
