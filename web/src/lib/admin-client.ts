/** Shared admin behaviours (see AdminLayout). */
    // Shared admin behaviours:
    //  <form data-admin-form="/api/admin/…" data-method="PUT">  → JSON submit (checkboxes = booleans, data-type="number" = numbers,
    //     data-type="uk-datetime" on a datetime-local input = UK local time sent as UTC ISO)
    //  <button data-admin-action="/api/admin/…" data-confirm="…" data-body='{"x":1}'>  → POST
    //  data-reload on either reloads the page after success.
    let timer: number | undefined;
export function say(msg: string, err = false) {
      const toast = document.querySelector<HTMLElement>('.adm-toast')!;
      toast.textContent = msg; toast.classList.toggle('err', err); toast.hidden = false;
      clearTimeout(timer); timer = window.setTimeout(() => (toast.hidden = true), 3500);
    }

export async function send(url: string, method: string, body?: BodyInit, isJson = true) {
      const res = await fetch(url, { method, body, headers: isJson && body ? { 'Content-Type': 'application/json' } : undefined });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        const first = data.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : null;
        throw new Error(first || data.message || 'Something went wrong.');
      }
      return data;
    }

/**
 * A failed admin form or action also leaves its message on screen, next to what was pressed, until the
 * next attempt (the toast alone disappears after a few seconds and is easy to miss).
 */
export function showError(anchor: HTMLElement, msg: string | null) {
  const host = anchor.closest<HTMLElement>('[data-error-host]') ?? (anchor.tagName === 'FORM' ? anchor : anchor.parentElement!);
  let box = host.querySelector<HTMLElement>(':scope > [data-admin-error]');
  if (!msg) { if (box) box.hidden = true; return; }
  if (!box) {
    box = document.createElement('p');
    box.className = 'alert alert--error adm-error';
    box.setAttribute('role', 'alert');
    box.dataset.adminError = '';
    host.prepend(box);
  }
  box.textContent = msg;
  box.hidden = false;
}

/** A datetime-local value ("2026-12-22T17:59") read as UK local time → UTC ISO string. */
export function ukLocalToIso(v: string): string | null {
  if (!v) return null;
  const guess = new Date(v + 'Z');
  const uk = new Date(guess.toLocaleString('en-US', { timeZone: 'Europe/London' }));
  const utc = new Date(guess.toLocaleString('en-US', { timeZone: 'UTC' }));
  return new Date(guess.getTime() - (uk.getTime() - utc.getTime())).toISOString();
}

export function initAdmin() {
    document.querySelectorAll<HTMLFormElement>('form[data-admin-form]').forEach((form) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (form.dataset.confirm && !confirm(form.dataset.confirm)) return;
        const hasFile = !!form.querySelector('input[type=file]');
        let body: BodyInit;
        if (hasFile) body = new FormData(form);
        else {
          const out: Record<string, unknown> = {};
          for (const el of Array.from(form.elements) as HTMLInputElement[]) {
            if (!el.name || el.disabled) continue;
            if (el.type === 'checkbox') out[el.name] = el.checked;
            else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
            else if (el.dataset.type === 'number') out[el.name] = el.value === '' ? null : Number(el.value);
            else if (el.dataset.type === 'uk-datetime') out[el.name] = ukLocalToIso(el.value);
            else if (el.dataset.type === 'lines') out[el.name] = el.value.split('\n').map((s) => s.trim()).filter(Boolean);
            else out[el.name] = el.value === '' ? null : el.value;
          }
          body = JSON.stringify(out);
        }
        const btn = form.querySelector<HTMLButtonElement>('button[type=submit]');
        if (btn) btn.disabled = true;
        showError(form, null);
        try {
          const data = await send(form.dataset.adminForm!, form.dataset.method || 'POST', body, !hasFile);
          say(data.message || 'Saved.');
          form.dispatchEvent(new CustomEvent('admin:saved', { detail: data }));
          if (form.hasAttribute('data-reload') && !data.preview) setTimeout(() => location.reload(), 700);
          if (form.hasAttribute('data-reset')) form.reset();
        } catch (err) { say((err as Error).message, true); showError(form, (err as Error).message); }
        finally { if (btn) btn.disabled = false; }
      });
    });

    document.querySelectorAll<HTMLButtonElement>('[data-admin-action]').forEach((b) => b.addEventListener('click', async () => {
      if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
      b.disabled = true;
      showError(b, null);
      try {
        const data = await send(b.dataset.adminAction!, b.dataset.method || 'POST', b.dataset.body || '{}');
        say(data.message || 'Done.');
        b.dispatchEvent(new CustomEvent('admin:done', { detail: data, bubbles: true }));
        if (b.hasAttribute('data-reload') && !data.preview) setTimeout(() => location.reload(), 900);
      } catch (err) { say((err as Error).message, true); showError(b, (err as Error).message); }
      finally { b.disabled = false; }
    }));
  }
