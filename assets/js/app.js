/*
 * Yukylo — all the JavaScript for the site (plain JavaScript, no libraries).
 *
 *   1. Phone menu and closing messages
 *   2. "Are you sure?" box for delete buttons
 *   3. Live search (Ajax) + stat cards that filter
 *   4. Forms sent with Ajax: errors show under each field, no page reload
 *   5. Autocomplete (Ajax): suggestions while typing, and filling in other fields
 *
 * Every Ajax request sends the header "X-Requested-With: fetch" so the PHP page knows to answer with JSON.
 */
'use strict';

const AJAX_HEADERS = { 'X-Requested-With': 'fetch' };

// Go to a page the server told us about (after saving, or a picked suggestion).
// Only a page of this site like "event.php?id=5" is allowed, never another site or a javascript: link.
function goTo(address) {
  try {
    const url = new URL(String(address), location.href);
    const page = url.pathname.split('/').pop();
    if (url.origin === location.origin && /^[a-z_]+\.php$/.test(page)) {
      location.assign(url.pathname + url.search + url.hash);
    }
  } catch {
    // not a valid address: stay on this page
  }
}

/* ------------------------------------------------------------------ 1. Menu + messages */

document.querySelectorAll('[data-menu-toggle]').forEach((button) => {
  const menu = document.getElementById(button.getAttribute('aria-controls'));
  button.addEventListener('click', () => {
    const open = menu.classList.toggle('hidden') === false;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
});

// The × on a message box closes it (works for messages added later too)
document.addEventListener('click', (e) => {
  const close = e.target.closest('[data-dismiss]');
  if (close) close.closest('[data-message]').remove();
});

// Show a message at the top of the page, e.g. after an Ajax save
function showMessage(text) {
  const area = document.querySelector('[data-flash-area]');
  if (!area || !text) return;
  const box = document.createElement('div');
  box.className = 'mb-6 flex items-center gap-3 rounded-xl border border-zinc-200 px-4 py-3';
  box.setAttribute('role', 'status');
  box.dataset.message = '';
  box.innerHTML = '<i class="bi bi-check-square-fill text-brand"></i><span class="flex-1"></span>'
    + '<button type="button" class="text-zinc-400 hover:text-zinc-900" data-dismiss aria-label="Close"><i class="bi bi-x-lg"></i></button>';
  box.querySelector('span').textContent = text; // textContent: never parsed as HTML
  area.replaceChildren(box);
}

/* ------------------------------------------------------------------ 2. "Are you sure?" box */

(() => {
  const forms = document.querySelectorAll('form[data-confirm]');
  if (!forms.length) return;

  const dialog = document.createElement('dialog');
  dialog.className = 'm-auto w-[min(420px,calc(100%-32px))] rounded-2xl border border-zinc-200 bg-white p-6 text-zinc-900 backdrop:bg-black/40';
  dialog.setAttribute('aria-labelledby', 'confirm-title');
  dialog.innerHTML = `
    <h2 id="confirm-title" class="text-lg font-semibold"></h2>
    <p class="mt-2 text-sm text-zinc-500" data-detail></p>
    <div class="mt-6 flex justify-end gap-2">
      <button type="button" class="rounded-xl border border-zinc-200 px-4 py-2 font-medium hover:bg-zinc-50" data-cancel>Keep it</button>
      <button type="button" class="rounded-xl bg-brand px-4 py-2 font-medium text-white hover:bg-brand-dark" data-ok>Delete</button>
    </div>`;
  document.body.append(dialog);

  const title = dialog.querySelector('#confirm-title');
  const detail = dialog.querySelector('[data-detail]');
  const ok = dialog.querySelector('[data-ok]');
  let pending = null;

  dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
  ok.addEventListener('click', () => {
    pending.dataset.confirmed = '1';
    dialog.close();
    pending.requestSubmit(); // goes on to the Ajax form code below
  });

  forms.forEach((form) => form.addEventListener('submit', (e) => {
    if (form.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    pending = form;
    title.textContent = form.dataset.confirm;
    detail.textContent = form.dataset.confirmDetail || 'This can’t be undone.';
    ok.textContent = form.dataset.confirmAction || 'Delete';
    dialog.showModal();
  }));
})();

/* ------------------------------------------------------------------ 3. Live search + stat cards */

let refreshResults = null; // set below on pages with a live search, used after Ajax saves

// The only pages the live search may ask for results (anything else is ignored)
const SEARCH_PAGES = ['events.php', 'people.php', 'jobs.php'];

/*
 * Turn the result cards HTML from the server into safe DOM nodes (defence in depth for DOM XSS).
 * Twig has already escaped every value, but this never trusts it: the HTML is parsed in an
 * inert document (nothing runs there), then anything that could run code is removed before
 * the nodes go into the page:
 *   - script, iframe, object, embed, style, link, meta, base, form elements that aren't ours
 *   - every on...="" attribute (onclick, onerror...)
 *   - href / src / action / formaction that isn't a normal link on this site (javascript:, data:...)
 */
const BLOCKED_TAGS = 'script, iframe, frame, object, embed, style, link, meta, base, template, noscript';
const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'xlink:href'];

// Normal web, email and phone links only: blocks javascript:, data:, vbscript: and the like
const SAFE_PROTOCOLS = ['http:', 'https:', 'mailto:', 'tel:'];

function isSafeUrl(value) {
  try {
    return SAFE_PROTOCOLS.includes(new URL(value, location.href).protocol);
  } catch {
    return false;
  }
}

function safeFragment(html) {
  const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
  doc.querySelectorAll(BLOCKED_TAGS).forEach((el) => el.remove());
  doc.body.querySelectorAll('*').forEach((el) => {
    for (const attr of [...el.attributes]) {
      const name = attr.name.toLowerCase();
      if (name.startsWith('on') || name === 'srcdoc' || name === 'style' && /expression|url\s*\(/i.test(attr.value)) {
        el.removeAttribute(attr.name);
      } else if (URL_ATTRIBUTES.includes(name) && !isSafeUrl(attr.value)) {
        el.removeAttribute(attr.name);
      }
    }
  });
  const fragment = document.createDocumentFragment();
  fragment.append(...doc.body.childNodes);
  return fragment;
}

(() => {
  const form = document.querySelector('form[data-live-search]');
  const results = document.querySelector('[data-results]');
  const count = document.querySelector('[data-count]');
  if (!form || !results) return;

  /*
   * Stat cards: <div data-status-filter data-field="category">, each card has data-status="value".
   * Clicking a card puts its value in that form field and searches again.
   * The card matching the field gets aria-current="true", which turns it red (Tailwind aria-[current=true]).
   */
  const filter = document.querySelector('[data-status-filter]');
  const field = filter ? form.elements[filter.dataset.field || 'status'] : null;

  // A filter value is only accepted if it's short plain text (letters, numbers, spaces, - _ .)
  // and, for a dropdown, one of its own options. Anything else clears the filter.
  function allowedValue(value) {
    const text = String(value ?? '');
    if (!/^[\w .-]{0,60}$/.test(text)) return '';
    if (field instanceof HTMLSelectElement && ![...field.options].some((option) => option.value === text)) return '';
    return text;
  }

  // The address of the results page, only if it is one of ours on this site (never another site)
  function searchUrl(params) {
    const url = new URL(form.getAttribute('action') || '', location.href);
    const page = url.pathname.split('/').pop();
    if (url.origin !== location.origin || !SEARCH_PAGES.includes(page)) return null;
    url.search = params.toString();
    return url;
  }

  function highlightCard() {
    if (!filter || !field) return;
    filter.querySelectorAll('[data-status]').forEach((card) => {
      if (card.dataset.status === field.value) card.setAttribute('aria-current', 'true');
      else card.removeAttribute('aria-current');
    });
  }

  if (filter && field) {
    filter.addEventListener('click', (e) => {
      const card = e.target.closest('[data-status]');
      if (!card) return;
      e.preventDefault();
      field.value = allowedValue(card.dataset.status);
      run();
    });
  }

  let timer;
  let controller;

  async function run() {
    highlightCard();
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(form)) {
      if (value !== '') params.append(key, value);
    }
    // Keep the search in the address bar (so refresh / back keep it). Same page, only the ?query changes.
    const pageUrl = searchUrl(params);
    if (!pageUrl) return;
    if (pageUrl.pathname === location.pathname) history.replaceState(null, '', pageUrl.pathname + pageUrl.search);

    params.set('ajax', '1');
    const ajaxUrl = searchUrl(params);

    controller?.abort();
    controller = new AbortController();
    results.classList.add('opacity-50');
    try {
      const res = await fetch(ajaxUrl, { headers: AJAX_HEADERS, signal: controller.signal, credentials: 'same-origin' });
      if (res.status === 401) { goTo('login.php'); return; } // logged out (e.g. idle too long)
      if (!res.ok || !(res.headers.get('Content-Type') || '').includes('application/json')) throw new Error(res.status);
      const data = await res.json();
      // Built by Twig (every value escaped), and cleaned again here before it goes into the page
      results.replaceChildren(safeFragment(data.html));
      if (count) count.textContent = String(data.label ?? '');
      // Update the numbers on the stat cards (e.g. after "Mark as followed up")
      if (data.stats) {
        for (const [key, total] of Object.entries(data.stats)) {
          const number = document.querySelector(`[data-stat-value="${CSS.escape(key)}"]`);
          if (number) number.textContent = total;
        }
      }
      hideSaveButtons();
    } catch (err) {
      if (err.name !== 'AbortError' && count) count.textContent = 'Search is unavailable right now. Press Search to try again.';
    } finally {
      results.classList.remove('opacity-50');
    }
  }
  refreshResults = run;

  form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  form.addEventListener('change', (e) => { if (e.target.type !== 'search') run(); });
  form.addEventListener('input', (e) => {
    if (e.target.type !== 'search') return;
    clearTimeout(timer);
    timer = setTimeout(run, 300);
  });
})();

/* ------------------------------------------------------------------ 4. Forms sent with Ajax */

/*
 * A <form data-ajax> is posted in the background. The PHP page answers with JSON:
 *   {ok: false, errors: {field: "message"}}   -> show each message under its field
 *   {ok: true, redirect: "event.php?id=5"}     -> go there (the page shows "Event saved.")
 *   {ok: true, refresh: true, message: "..."}  -> show the message and refresh the list
 * Without JavaScript the same forms still work as normal forms.
 */

// A <select data-autosubmit> saves its form as soon as it changes (job status on the Jobs page)
function hideSaveButtons() {
  document.querySelectorAll('[data-autosubmit-button]').forEach((button) => button.classList.add('hidden'));
}
hideSaveButtons();
document.addEventListener('change', (e) => {
  if (e.target.matches('select[data-autosubmit]')) e.target.form.requestSubmit();
});

function showErrors(form, errors) {
  // Clear old messages and red borders
  form.querySelectorAll('[data-error-for]').forEach((p) => { p.textContent = ''; p.classList.add('hidden'); });
  form.querySelectorAll('.border-brand[name]').forEach((input) => input.classList.replace('border-brand', 'border-zinc-200'));

  let first = null;
  for (const [name, message] of Object.entries(errors)) {
    const p = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
    if (p) { p.textContent = message; p.classList.remove('hidden'); }
    const input = form.elements[name];
    if (input && input.classList) {
      input.classList.replace('border-zinc-200', 'border-brand');
      input.setAttribute('aria-invalid', 'true');
      first = first || input;
    }
  }
  first?.focus();
}

document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (!form.matches('form[data-ajax]')) return;
  e.preventDefault();

  const button = form.querySelector('[type=submit]');
  if (button) button.disabled = true;
  try {
    const res = await fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: AJAX_HEADERS });
    const data = await res.json();
    if (data.redirect) {
      goTo(data.redirect);
      return;
    }
    if (data.errors) showErrors(form, data.errors);
    if (data.error) showMessage(data.error);
    // A captcha works only once, so after a failed try show a new picture
    if (!data.ok) form.querySelectorAll('[data-captcha]').forEach(newCaptcha);
    if (data.refresh) {
      showMessage(data.message);
      if (refreshResults) refreshResults(); else location.reload();
    }
  } catch (err) {
    showMessage('Saving failed. Check your connection and try again.');
  } finally {
    if (button) button.disabled = false;
  }
});

/* Captcha (login and create account): load a new picture and empty the box */
function newCaptcha(img) {
  img.src = `captcha.php?${Date.now()}`;
  const input = img.closest('form')?.elements.captcha;
  if (input) input.value = '';
}
document.addEventListener('click', (e) => {
  const button = e.target.closest('[data-captcha-refresh]');
  if (!button) return;
  const img = button.closest('form').querySelector('[data-captcha]');
  newCaptcha(img);
  img.closest('form').elements.captcha?.focus();
});

/* ------------------------------------------------------------------ 5. Autocomplete */

/*
 * <input data-suggest="company"> asks suggest.php?field=company&q=... while you type and shows a list.
 * Arrow keys + Enter pick a suggestion, Escape closes. A suggestion can also:
 *   - fill other fields (picking a past event fills its type, venue and city)
 *   - open a page (the person is already in your diary)
 */
document.querySelectorAll('input[data-suggest]').forEach((input) => {
  const wrap = input.closest('[data-suggest-wrap]') || input.parentElement;
  wrap.classList.add('relative');

  const list = document.createElement('div');
  list.id = `${input.name}-suggestions`;
  list.className = 'absolute left-0 right-0 z-20 hidden max-h-72 overflow-auto rounded-xl border border-zinc-200 bg-white p-1';
  list.setAttribute('role', 'listbox');
  wrap.append(list);

  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', list.id);
  input.setAttribute('aria-expanded', 'false');

  let items = [];
  let active = -1;
  let timer;
  let controller;

  function close() {
    list.classList.add('hidden');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    active = -1;
  }

  function show(data) {
    items = data.items;
    list.replaceChildren();
    if (!items.length) { close(); return; }

    if (data.title) {
      const heading = document.createElement('p');
      heading.className = 'px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-brand';
      heading.textContent = data.title;
      list.append(heading);
    }
    items.forEach((item, i) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.id = `${list.id}-${i}`;
      option.className = 'block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-50 aria-selected:bg-zinc-100';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      const main = document.createElement('span');
      main.className = 'block font-medium';
      main.textContent = item.value;
      option.append(main);
      if (item.detail) {
        const small = document.createElement('span');
        small.className = 'block text-xs text-zinc-500';
        small.textContent = item.detail;
        option.append(small);
      }
      option.addEventListener('mousedown', (e) => e.preventDefault()); // keep focus in the input
      option.addEventListener('click', () => choose(i));
      list.append(option);
    });

    // Put the list right under the input box (not under its label or help text)
    list.style.top = `${input.offsetTop + input.offsetHeight + 4}px`;
    list.classList.remove('hidden');
    input.setAttribute('aria-expanded', 'true');
  }

  function choose(i) {
    const item = items[i];
    if (!item) return;
    if (item.url) { goTo(item.url); return; }
    input.value = item.value;
    // Form filling assistance: fill in the other fields this suggestion knows about (only if they're empty)
    if (item.fill && input.form) {
      for (const [name, value] of Object.entries(item.fill)) {
        const other = input.form.elements[name];
        if (other && !other.value && value) other.value = value;
      }
    }
    close();
    input.dispatchEvent(new Event('input', { bubbles: true })); // search boxes search again
  }

  function move(step) {
    const options = list.querySelectorAll('[role=option]');
    if (!options.length) return;
    options[active]?.setAttribute('aria-selected', 'false');
    active = (active + step + options.length) % options.length;
    options[active].setAttribute('aria-selected', 'true');
    options[active].scrollIntoView({ block: 'nearest' });
    input.setAttribute('aria-activedescendant', options[active].id);
  }

  input.addEventListener('input', (e) => {
    if (!e.isTrusted) return; // ignore the event we fire ourselves after choosing
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { close(); return; }
    timer = setTimeout(async () => {
      controller?.abort();
      controller = new AbortController();
      try {
        const res = await fetch(`suggest.php?field=${encodeURIComponent(input.dataset.suggest)}&q=${encodeURIComponent(q)}`,
          { headers: AJAX_HEADERS, signal: controller.signal });
        show(await res.json());
      } catch (err) {
        if (err.name !== 'AbortError') close();
      }
    }, 200);
  });

  input.addEventListener('keydown', (e) => {
    if (list.classList.contains('hidden')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
    else if (e.key === 'Escape') close();
  });
  input.addEventListener('blur', () => setTimeout(close, 150));
});
