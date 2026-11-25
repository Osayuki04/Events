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

// The pages of this site that app.js may go to, and the sections it may jump to on a page
const PAGES = ['index.php', 'events.php', 'event.php', 'event_form.php', 'people.php', 'person_form.php',
  'jobs.php', 'job_form.php', 'login.php', 'register.php'];
const PAGE_SECTIONS = ['people', 'jobs'];
const ID_PARAMS = ['id', 'event_id'];

/*
 * Go to a page the server told us about (after saving, a picked suggestion, or "log in again").
 * The address is never used as it is: it is rebuilt from the fixed lists above plus whole numbers,
 * e.g. "event.php?id=5#people". So it can only ever be one of our own pages (no open redirect,
 * no javascript: links). Anything else in the address is dropped.
 */
function goTo(address) {
  const parts = /^([a-z_]+\.php)(\?[^#]*)?(#[a-z]+)?$/.exec(String(address ?? ''));
  const page = parts ? PAGES.find((p) => p === parts[1]) : undefined;
  if (!page) return;

  let target = page;
  const query = new URLSearchParams(parts[2] || '');
  const key = ID_PARAMS.find((k) => query.has(k));
  const id = key ? Number(query.get(key)) : NaN;
  if (key && Number.isInteger(id) && id > 0) target += `?${key}=${id}`;

  const section = PAGE_SECTIONS.find((name) => `#${name}` === parts[3]);
  if (section) target += `#${section}`;

  window.location.assign(target);
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

/*
 * Make an element without writing HTML: el('button', {type: 'button', class: '...'}, ['Close']).
 * Text children become text nodes (never parsed as HTML), so app.js has no innerHTML anywhere.
 */
function el(tag, attributes = {}, children = []) {
  const element = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
  element.append(...children);
  return element;
}

// Show a message at the top of the page, e.g. after an Ajax save
function showMessage(text) {
  const area = document.querySelector('[data-flash-area]');
  if (!area || !text) return;
  const box = el('div', { class: 'mb-6 flex items-center gap-3 rounded-xl border border-zinc-200 px-4 py-3', role: 'status', 'data-message': '' }, [
    el('i', { class: 'bi bi-check-square-fill text-brand' }),
    el('span', { class: 'flex-1' }, [String(text)]),
    el('button', { type: 'button', class: 'text-zinc-400 hover:text-zinc-900', 'data-dismiss': '', 'aria-label': 'Close' }, [
      el('i', { class: 'bi bi-x-lg' }),
    ]),
  ]);
  area.replaceChildren(box);
}

/* ------------------------------------------------------------------ 2. "Are you sure?" box */

(() => {
  const forms = document.querySelectorAll('form[data-confirm]');
  if (!forms.length) return;

  const dialog = el('dialog', {
    class: 'm-auto w-[min(420px,calc(100%-32px))] rounded-2xl border border-zinc-200 bg-white p-6 text-zinc-900 backdrop:bg-black/40',
    'aria-labelledby': 'confirm-title',
  }, [
    el('h2', { id: 'confirm-title', class: 'text-lg font-semibold' }),
    el('p', { class: 'mt-2 text-sm text-zinc-500', 'data-detail': '' }),
    el('div', { class: 'mt-6 flex justify-end gap-2' }, [
      el('button', { type: 'button', class: 'rounded-xl border border-zinc-200 px-4 py-2 font-medium hover:bg-zinc-50', 'data-cancel': '' }, ['Keep it']),
      el('button', { type: 'button', class: 'rounded-xl bg-brand px-4 py-2 font-medium text-white hover:bg-brand-dark', 'data-ok': '' }, ['Delete']),
    ]),
  ]);
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

// The only pages the live search may ask (it is never sent anywhere else)
const SEARCH_PAGES = ['events.php', 'people.php', 'jobs.php'];

/*
 * How the live search avoids DOM XSS completely:
 *   - the page already has every card, drawn by Twig (which escapes every value), each with data-id="5"
 *   - the server answers a search with only the ids of the cards that match, e.g. {"ids": [5, 2, 9]}
 *   - app.js turns each id into a whole number and shows, hides and orders the existing cards
 * No HTML comes back from the server and nothing is ever parsed as HTML, so nothing can be injected.
 */
(() => {
  const form = document.querySelector('form[data-live-search]');
  const results = document.querySelector('[data-results]');
  const count = document.querySelector('[data-count]');
  if (!form || !results) return;

  const page = SEARCH_PAGES.find((p) => p === form.getAttribute('action'));
  const grid = results.querySelector('[data-cards]');
  const empty = results.querySelector('[data-empty]');
  if (!page || !grid) return;

  // Show the cards whose ids came back, in that order; hide the rest.
  // The ids are only used as numbers to look up a position: the elements that get shown and
  // moved are always the page's own cards, never anything made from the server's answer.
  function showCards(ids) {
    const position = new Map();
    (Array.isArray(ids) ? ids : []).forEach((id, i) => {
      const n = Number(id);
      if (Number.isInteger(n) && !position.has(n)) position.set(n, i);
    });
    const cards = [...grid.querySelectorAll(':scope > [data-id]')];
    const placeOf = (card) => position.get(Number(card.dataset.id));
    cards.forEach((card) => { card.hidden = placeOf(card) === undefined; });
    const shown = cards.filter((card) => !card.hidden).sort((a, b) => placeOf(a) - placeOf(b));
    shown.forEach((card) => grid.appendChild(card)); // moves it to the end, so the shown cards end up in search order
    if (empty) empty.hidden = shown.length > 0;
  }

  /*
   * Stat cards: <div data-status-filter data-field="category">, each card has data-status="value".
   * Clicking a card picks the option with that value in the form's dropdown and searches again
   * (the option is picked by its position: no text is copied into the form).
   * The card matching the dropdown gets aria-current="true", which turns it red (Tailwind aria-[current=true]).
   */
  const filter = document.querySelector('[data-status-filter]');
  const field = filter ? form.elements[filter.dataset.field || 'status'] : null;
  const canFilter = filter && field instanceof HTMLSelectElement;

  function highlightCard() {
    if (!canFilter) return;
    filter.querySelectorAll('[data-status]').forEach((card) => {
      if (card.dataset.status === field.value) card.setAttribute('aria-current', 'true');
      else card.removeAttribute('aria-current');
    });
  }

  if (canFilter) {
    filter.addEventListener('click', (e) => {
      const card = e.target.closest('[data-status]');
      if (!card) return;
      e.preventDefault();
      const index = [...field.options].findIndex((option) => option.value === card.dataset.status);
      field.selectedIndex = Math.max(index, 0); // not one of the options: back to the first ("All")
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
    params.set('ajax', '1');

    controller?.abort();
    controller = new AbortController();
    results.classList.add('opacity-50');
    try {
      const res = await fetch(`${page}?${params}`, { headers: AJAX_HEADERS, signal: controller.signal, credentials: 'same-origin' });
      if (res.status === 401) { goTo('login.php'); return; } // logged out (e.g. idle too long)
      if (!res.ok || !(res.headers.get('Content-Type') || '').includes('application/json')) throw new Error(res.status);
      const data = await res.json();
      showCards(data.ids);
      if (count) count.textContent = String(data.label ?? '');
      // Update the numbers on the stat cards (e.g. after "Mark as followed up"): whole numbers only
      if (data.stats && typeof data.stats === 'object') {
        filter?.querySelectorAll('[data-stat-value]').forEach((number) => {
          const total = Number(data.stats[number.dataset.statValue]);
          if (Number.isInteger(total)) number.textContent = String(total);
        });
      }
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

/* After a quick action on a card saved (Ajax): show the card's new state.
   Both states are already drawn by Twig; only which one is visible changes. */
function updateCard(form, data) {
  const card = form.closest('[data-id]');
  if (!card) return;
  // People: "Follow up" <-> "Done"
  if (data.follow_up === 0 || data.follow_up === 1) {
    card.querySelectorAll('[data-follow]').forEach((el) => { el.hidden = Number(el.dataset.follow) !== data.follow_up; });
  }
  // Jobs: the status label follows the dropdown (labels are in the same order as the options)
  const select = form.querySelector('select[data-autosubmit]');
  if (select) card.querySelectorAll('[data-status-label]').forEach((el, i) => { el.hidden = i !== select.selectedIndex; });
}

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
      updateCard(form, data);
      if (refreshResults) refreshResults(); else window.location.reload();
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
